/*
 * Destructive, concurrent integration tests; Node.js + PHP CLI + PDO MySQL.
 * ONLY a disposable DB named yash_test_* may be used:
 *   YASH_TEST_DB=yash_test_weekly YASH_TEST_ALLOW_RESET=1 node tests/weekly_payouts_concurrency.mjs
 * PHP_BIN may be set to a PHP executable. Every reset drops and reseeds tables.
 */
import { spawn } from 'node:child_process';
import { access, mkdtemp, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';

const name = process.env.YASH_TEST_DB || '';
if (!/^yash_test_[a-z0-9_]+$/.test(name) || process.env.YASH_TEST_ALLOW_RESET !== '1') {
  throw new Error('Refusing to run: select a disposable yash_test_* DB and set YASH_TEST_ALLOW_RESET=1.');
}
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = process.env.PHP_BIN || 'php';
const quote = value => JSON.stringify(value);
const bootstrap = `define('YASH_CLI',true); define('DB_NAME',${quote(name)}); require ${quote(root + '/includes/init.php')}; `;
function run(code) {
  return new Promise((resolve, reject) => {
    let out = '', err = '';
    const child = spawn(php, ['-r', bootstrap + code]);
    child.stdout.on('data', chunk => out += chunk);
    child.stderr.on('data', chunk => err += chunk);
    child.on('error', reject);
    child.on('close', status => status === 0 ? resolve(out.trim()) : reject(new Error(err || out || `PHP exit ${status}`)));
  });
}
async function json(code) { return JSON.parse(await run(`echo json_encode(${code});`)); }
let checks = 0;
function check(condition, message) {
  if (!condition) { throw new Error('FAIL: ' + message); }
  checks++; console.log('PASS: ' + message);
}
async function reset() {
  await run(`require ${quote(root + '/install/seed.php')}; seed_database(true);
    q("UPDATE users SET kyc_status='verified',bank_name='Race Bank',bank_account_no='123456',bank_ifsc='TEST0001' WHERE id=1");
    $GLOBALS['__commission_clock']=date('Y-m-d H:i:s', strtotime('-21 days'));
    db_tx(function(){income_rules_ready(true); add_commission(1,null,'binary',1000,0,null,'Old test income');});
    unset($GLOBALS['__commission_clock']);`);
}
async function state() {
  return json(`q_row("SELECT wallet_balance,total_earned,total_withdrawn,kyc_status,bank_account_no,
    (SELECT COUNT(*) FROM payout_statements) statements,
    (SELECT COUNT(*) FROM payouts) payouts,
    (SELECT COUNT(*) FROM payouts WHERE status='paid') paid,
    (SELECT COUNT(*) FROM payouts WHERE status='rejected') rejected,
    (SELECT COALESCE(SUM(amount),0) FROM weekly_payout_reservations WHERE status='reserved') reserved,
    (SELECT COUNT(*) FROM weekly_payout_reservations WHERE status='released') released,
    (SELECT COUNT(*) FROM wallet_transactions WHERE ref_type='payout' AND type='debit') payout_debits,
    (SELECT COUNT(*) FROM wallet_transactions WHERE ref_type='refund') refunds,
    (SELECT COUNT(*) FROM income_recalc_runs) recalc_runs
    FROM users WHERE id=1")`);
}

// Hold the first writer's transaction open while the second writer tries to
// act. This verifies both lock orderings rather than relying on timing alone.
async function concurrentWithHeldTransaction(firstCode, secondExpression) {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'yash-weekly-race-'));
  const ready = path.join(dir, 'ready');
  const started = path.join(dir, 'started');
  const release = path.join(dir, 'release');
  const first = run(`db_tx(function() {
    ${firstCode}
    if (file_put_contents(${quote(ready)}, 'ready') === false) { throw new RuntimeException('Cannot signal lock'); }
    $deadline = microtime(true) + 20;
    while (!is_file(${quote(release)})) {
      if (microtime(true) > $deadline) { throw new RuntimeException('Lock test timed out'); }
      clearstatcache(true, ${quote(release)}); usleep(20000);
    }
  });`);
  let second;
  try {
    const waitForMarker = async (file, message) => {
      for (let i = 0; i < 400; i++) {
        try { await access(file); return; } catch { await delay(25); }
      }
      throw new Error(message);
    };
    await Promise.race([waitForMarker(ready, 'First transaction never acquired its lock'),
      first.then(() => { throw new Error('First transaction exited early'); })]);
    let completed = false;
    second = run(`if (file_put_contents(${quote(started)}, 'started') === false) { throw new RuntimeException('Cannot signal contender'); }
      echo json_encode(${secondExpression});`).then(JSON.parse);
    second.then(() => { completed = true; }, () => { completed = true; });
    await Promise.race([waitForMarker(started, 'Contending writer did not start'), second.then(() => {}, error => { throw error; })]);
    await delay(1500);
    const wasBlocked = !completed;
    await writeFile(release, 'release');
    const [, result] = await Promise.all([first, second]);
    return { wasBlocked, result };
  } finally {
    await writeFile(release, 'release').catch(() => {});
    await first.catch(() => {});
    if (second) { await second.catch(() => {}); }
    await rm(dir, { recursive: true, force: true });
  }
}

try {
  await reset();
  let generated = await Promise.all(Array.from({ length: 4 }, () => json('generate_payout_report()')));
  let s = await state();
  check(generated.filter(x => x[2].outcome === 'created').length === 1 && +s.statements === 1 && +s.payouts === 1 && +s.reserved === 1000 && +s.wallet_balance === 1000,
    'simultaneous scheduler invocations create one report and one reservation without a wallet debit');
  const statement = generated.find(x => x[2].outcome === 'created')[2].statement_id;
  const paid = await Promise.all(Array.from({ length: 4 }, () => json(`process_weekly_payout_report(${statement},'paid',1)`)));
  s = await state();
  check(paid.filter(x => x[0]).length === 1 && +s.paid === 1 && +s.payout_debits === 1 && +s.wallet_balance === 0 && +s.total_withdrawn === 1000 && +s.total_earned === 1000,
    'simultaneous Paid actions settle and debit exactly once');

  await reset();
  const genVsSpend = await Promise.all([
    json('generate_payout_report()'), json("debit_wallet(1,800,'purchase',null,'Concurrent purchase')")
  ]);
  s = await state();
  check((+s.reserved === 1000 && +s.wallet_balance === 1000 && genVsSpend[1] === null)
      || (+s.reserved === 0 && +s.wallet_balance === 200 && +genVsSpend[1] === 200),
    'generation and a competing wallet purchase cannot both spend the same funds');

  await reset();
  const genVsRequest = await Promise.all([json('generate_payout_report()'), json('request_payout(1,600)')]);
  s = await state();
  check((+s.reserved === 1000 && +s.wallet_balance === 1000 && !genVsRequest[1][0])
      || (+s.reserved === 0 && +s.wallet_balance === 400 && genVsRequest[1][0]),
    'generation and a competing manual withdrawal cannot hold the same earnings twice');

  await reset();
  await run("db_tx(function(){income_rules_ready(true); credit_wallet(1,200,'admin',null,'Extra available funds');});");
  const ready = await json('generate_payout_report()');
  const id = ready[2].statement_id;
  const settlement = await Promise.all([
    json(`process_weekly_payout_report(${id},'paid',1)`),
    json("debit_wallet(1,200,'purchase',null,'Concurrent purchase A')"),
    json("debit_wallet(1,200,'purchase',null,'Concurrent purchase B')")
  ]);
  s = await state();
  check(settlement[0][0] && settlement.slice(1).filter(x => x !== null).length === 1
      && +s.wallet_balance === 0 && +s.payout_debits === 1 && +s.total_withdrawn === 1000,
    'Paid and concurrent purchases use the reservation and the one available amount at most once');

  await reset();
  const ready2 = await json('generate_payout_report()');
  const id2 = ready2[2].statement_id;
  await Promise.all([json(`process_weekly_payout_report(${id2},'paid',1)`), json(`process_weekly_payout_report(${id2},'in_process',1)`)]);
  s = await state();
  check(+s.paid === 1 && +s.payout_debits === 1 && +s.wallet_balance === 0,
    'a concurrent In Process transition cannot reverse or duplicate a paid batch');

  await reset();
  const hash = await run(`require ${quote(root + '/includes/income_recalculation.php')}; echo income_preview_reconciliation()['hash'];`);
  const recalc = await Promise.all([
    json('generate_payout_report()'),
    run(`require ${quote(root + '/includes/income_recalculation.php')}; try {echo json_encode(['ok'=>income_apply_reconciliation('${hash}',1)]);} catch(Throwable $e){echo json_encode(['error'=>$e->getMessage()]);}`)
  ]);
  s = await state();
  const recalcResult = JSON.parse(recalc[1]);
  check((+s.wallet_balance === 0 && +s.reserved === 0 && +s.recalc_runs === 1 && recalcResult.ok)
      || (+s.wallet_balance === 1000 && +s.reserved === 1000 && +s.recalc_runs === 0 && (recalcResult.error || '').includes('changed since')),
    'generation and reconciliation serialize or reject a stale preview without spending reserved funds');

  await reset();
  const manual = await json('request_payout(1,600)');
  await Promise.all([json('generate_payout_report()'), json(`process_payout(${manual[1]},'reject',1)`) ]);
  s = await state();
  check(+s.wallet_balance === 1000 && +s.refunds === 1 && (+s.reserved === 0 || +s.reserved === 1000),
    'legacy refund and report generation serialize without duplicate refunds or over-reservation');

  await reset();
  const ready3 = await json('generate_payout_report()');
  const id3 = ready3[2].statement_id;
  await Promise.all([json(`process_weekly_payout_report(${id3},'paid',1)`), json(`process_weekly_payout_report(${id3},'release',1)`)]);
  s = await state();
  check((+s.paid === 1 && +s.payout_debits === 1 && +s.wallet_balance === 0 && +s.released === 0)
      || (+s.rejected === 1 && +s.released === 1 && +s.payout_debits === 0 && +s.wallet_balance === 1000),
    'concurrent Paid and release produce either one payment or one unpaid release, never both');

  await reset();
  const kycReport = await json('generate_payout_report()');
  const kycRace = await concurrentWithHeldTransaction(
    `require ${quote(root + '/includes/kyc.php')}; kyc_replace_documents(1,'kyc/new.png',null);`,
    `process_weekly_payout_report(${kycReport[2].statement_id},'paid',1)`);
  s = await state();
  check(kycRace.wasBlocked && kycRace.result[0] && s.kyc_status === 'pending' && +s.released === 1
      && +s.paid === 0 && +s.payout_debits === 0 && +s.wallet_balance === 1000,
    'settlement waits for a concurrent KYC re-upload and releases the now-unverified payout unpaid');

  await reset();
  const bankReport = await json('generate_payout_report()');
  const bankRace = await concurrentWithHeldTransaction(
    `q("UPDATE users SET bank_account_no='999999' WHERE id=1");`,
    `process_weekly_payout_report(${bankReport[2].statement_id},'paid',1)`);
  s = await state();
  check(bankRace.wasBlocked && bankRace.result[0] && s.bank_account_no === '999999' && +s.released === 1
      && +s.paid === 0 && +s.payout_debits === 0 && +s.wallet_balance === 1000,
    'settlement waits for a concurrent bank edit and releases changed bank details unpaid');

  await reset();
  const paidFirstReport = await json('generate_payout_report()');
  const paidFirstRace = await concurrentWithHeldTransaction(
    `$settled=process_weekly_payout_report(${paidFirstReport[2].statement_id},'paid',1); if (!$settled[0]) { throw new RuntimeException($settled[1]); }`,
    `q("UPDATE users SET bank_account_no='999999' WHERE id=1")->rowCount()`);
  s = await state();
  const paidBank = await json(`q_val("SELECT bank_account_no FROM payouts WHERE status='paid'")`);
  check(paidFirstRace.wasBlocked && +paidFirstRace.result === 1 && paidBank === '123456' && s.bank_account_no === '999999'
      && +s.paid === 1 && +s.payout_debits === 1 && +s.wallet_balance === 0,
    'a bank edit waits for an already-settling report; the paid snapshot and one debit remain consistent');

  await reset();
  const displayReport = await json('generate_payout_report()');
  const displayRace = await concurrentWithHeldTransaction(
    `$settled=process_weekly_payout_report(${displayReport[2].statement_id},'paid',1); if (!$settled[0]) { throw new RuntimeException($settled[1]); }`,
    'weekly_wallet_snapshot(1)');
  s = await state();
  check(!displayRace.wasBlocked && +displayRace.result.wallet_balance === 1000 && +displayRace.result.reserved === 1000
      && +displayRace.result.available === 0 && +displayRace.result.total_withdrawn === 0
      && +s.wallet_balance === 0 && +s.reserved === 0 && +s.total_withdrawn === 1000,
    'a dashboard read during settlement sees one consistent wallet/reservation snapshot');

  await reset();
  const genKycRace = await concurrentWithHeldTransaction(
    `require ${quote(root + '/includes/kyc.php')}; kyc_replace_documents(1,'kyc/new.png',null);`,
    'generate_payout_report()');
  s = await state();
  check(genKycRace.wasBlocked && genKycRace.result[2].blocked === 1 && s.kyc_status === 'pending'
      && +s.payouts === 0 && +s.reserved === 0 && +s.wallet_balance === 1000,
    'generation waits for a KYC re-upload and lists the unverified member unpaid');

  await reset();
  const genBankRace = await concurrentWithHeldTransaction(
    `q("UPDATE users SET bank_account_no='999999' WHERE id=1");`,
    'generate_payout_report()');
  s = await state();
  const snapshotBank = await json(`q_val("SELECT bank_account_no FROM payouts LIMIT 1")`);
  check(genBankRace.wasBlocked && genBankRace.result[2].created === 1 && snapshotBank === '999999'
      && +s.reserved === 1000 && +s.wallet_balance === 1000,
    'generation waits for a bank edit and snapshots the committed bank details');

  await reset();
  await run(`q("UPDATE users SET kyc_status='pending' WHERE id=1");`);
  const snapshot = await run(`require ${quote(root + '/includes/kyc.php')}; echo kyc_review_snapshot(q_row('SELECT * FROM users WHERE id=1'));`);
  const staleReview = await concurrentWithHeldTransaction(
    `require ${quote(root + '/includes/kyc.php')}; kyc_replace_documents(1,'kyc/new.png',null);`,
    `(function(){require ${quote(root + '/includes/kyc.php')}; try {kyc_review_decide(1,'kyc_verify',${quote(snapshot)}); return ['ok'=>true];} catch (RuntimeException $e) {return ['error'=>$e->getMessage()];}})()`);
  s = await state();
  check(staleReview.wasBlocked && (staleReview.result.error || '').includes('changed since') && s.kyc_status === 'pending',
    'a concurrent upload invalidates an old admin verification instead of approving unseen documents');

  await reset();
  await run(`q("UPDATE users SET kyc_status='pending' WHERE id=1");`);
  const reviewFirstSnapshot = await run(`require ${quote(root + '/includes/kyc.php')}; echo kyc_review_snapshot(q_row('SELECT * FROM users WHERE id=1'));`);
  const reviewFirst = await concurrentWithHeldTransaction(
    `require ${quote(root + '/includes/kyc.php')}; kyc_review_decide(1,'kyc_verify',${quote(reviewFirstSnapshot)});`,
    `(function(){require ${quote(root + '/includes/kyc.php')}; return kyc_replace_documents(1,'kyc/new.png',null);})()`);
  s = await state();
  check(reviewFirst.wasBlocked && s.kyc_status === 'pending',
    'an upload following a concurrent admin verification returns KYC to pending');
  console.log(`OK: ${checks} concurrent scenarios`);
} catch (err) {
  console.error(err.stack || err);
  process.exitCode = 1;
}
