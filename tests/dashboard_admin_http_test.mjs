/*
 * HTTP integration/security tests for the dashboard configuration.
 * DESTRUCTIVE: use a disposable, separately seeded site and yash_test_* DB only.
 * Requires Node.js 20+, mysql2, and the demo member YSH100002/YSH100002.
 * Example (also set DB_HOST/DB_USER/DB_PASS if needed):
 * YASH_TEST_DB=yash_test_dashboard YASH_TEST_ALLOW_WRITES=1 \
 * YASH_TEST_BASE_URL=http://127.0.0.1:8089 YASH_TEST_SITE_ROOT=/path/to/test/site \
 * node tests/dashboard_admin_http_test.mjs
 * Superadmin credentials default to the fresh seed (superadmin/Super@123).
 */
import { readFile, readdir } from 'node:fs/promises';
import path from 'node:path';
import { randomBytes } from 'node:crypto';
import mysql from 'mysql2/promise';

const dbName = process.env.YASH_TEST_DB || '';
const base = process.env.YASH_TEST_BASE_URL || '';
const root = process.env.YASH_TEST_SITE_ROOT || '';
const parsed = base ? new URL(base) : null;
if (!/^yash_test_[a-z0-9_]+$/.test(dbName) || process.env.YASH_TEST_ALLOW_WRITES !== '1' || !root
    || !parsed || !['127.0.0.1', 'localhost', '[::1]'].includes(parsed.hostname)) {
  throw new Error('Refusing to run without a disposable yash_test_* DB, loopback test URL, test site root and YASH_TEST_ALLOW_WRITES=1.');
}
const db = await mysql.createConnection({ host: process.env.DB_HOST || '127.0.0.1', user: process.env.DB_USER || 'root',
  password: process.env.DB_PASS || '', database: dbName });
let checks = 0;
function check(ok, message) { if (!ok) throw new Error('FAIL: ' + message); checks++; console.log('PASS: ' + message); }
async function one(sql, args = []) { return (await db.query(sql, args))[0][0]; }
async function rows(sql, args = []) { return (await db.query(sql, args))[0]; }
function client() {
  let cookie = '';
  return async (url, body) => {
    const headers = {};
    if (cookie) headers.cookie = cookie;
    if (body instanceof URLSearchParams) headers['content-type'] = 'application/x-www-form-urlencoded';
    const response = await fetch(base + url, { method: body ? 'POST' : 'GET', headers, body, redirect: 'manual' });
    for (const value of response.headers.getSetCookie()) cookie = value.split(';')[0];
    return { status: response.status, location: response.headers.get('location') || '', text: await response.text() };
  };
}
const token = text => text.match(/name="_token" value="([^"]+)"/)?.[1];
function post(values) { return new URLSearchParams(values); }
function rankForm(values, bytes, filename = 'image.png', type = 'image/png') {
  const form = new FormData();
  for (const [key, value] of Object.entries(values)) form.append(key, String(value));
  if (bytes) form.append('icon_image', new Blob([bytes], { type }), filename);
  return form;
}
async function iconFiles() { try { return (await readdir(path.join(root, 'uploads/rank-icons'))).sort(); } catch (e) { if (e.code === 'ENOENT') return []; throw e; } }
async function financialSnapshot() {
  return JSON.stringify([
    await rows('SELECT id,self_bv,left_bv,right_bv,matched_pairs,reward_points,rank_id,wallet_balance,total_earned,total_withdrawn FROM users ORDER BY id'),
    await rows('SELECT * FROM commissions ORDER BY id'), await rows('SELECT * FROM wallet_transactions ORDER BY id'),
    await rows('SELECT * FROM payouts ORDER BY id'), await rows('SELECT * FROM weekly_payout_reservations ORDER BY payout_id'),
    await rows('SELECT * FROM income_rank_events ORDER BY user_id,rank_id'),
    await rows('SELECT car_fund_points,car_fund_amount FROM plan_settings ORDER BY id'),
    await rows("SELECT skey,svalue FROM settings WHERE skey IN ('dashboard_tour_points','dashboard_tour_amount') ORDER BY skey"),
  ]);
}

const marker = 'Test DB Marker ' + randomBytes(6).toString('hex');
const member = client(), admin = client(), anonymous = client();
try {
  // Prove the HTTP site really uses the explicitly selected test DB before
  // visiting an authenticated superadmin panel, which can generate payouts.
  await db.query("INSERT INTO dashboard_milestones (name,target_points,amount,status,sort_order,created_at,updated_at) VALUES (?,1,0,'active',0,NOW(),NOW())", [marker]);
  let page = await member('/login.php');
  check(!!token(page.text), 'member login has a CSRF token');
  await member('/login.php', post({ _token: token(page.text), login: process.env.YASH_TEST_MEMBER || 'YSH100002', password: process.env.YASH_TEST_MEMBER_PASSWORD || 'YSH100002' }));
  page = await member('/user/index.php');
  check(page.status === 200 && page.text.includes(marker), 'member page proves the HTTP site uses the selected disposable database');
  await db.query('DELETE FROM dashboard_milestones WHERE name=?', [marker]);

  let denied = await anonymous('/superadmin/milestones.php');
  check(denied.status === 302 && denied.location.includes('login.php'), 'anonymous users cannot open milestone administration');
  denied = await member('/superadmin/milestones.php', post({ action: 'save', id: '0', name: 'Unauthorized Fund', target_points: '1', amount: '1' }));
  check(denied.status === 302 && denied.location.includes('login.php')
    && !(await one("SELECT id FROM dashboard_milestones WHERE name='Unauthorized Fund'")), 'members cannot create funds through the superadmin endpoint');
  denied = await member('/superadmin/ranks.php', post({ action: 'delete', id: '1' }));
  check(denied.status === 302 && denied.location.includes('login.php'), 'members cannot alter ranks');

  page = await admin('/superadmin/login.php');
  await admin('/superadmin/login.php', post({ _token: token(page.text), username: process.env.YASH_TEST_ADMIN || 'superadmin', password: process.env.YASH_TEST_ADMIN_PASSWORD || 'Super@123' }));
  page = await admin('/superadmin/milestones.php');
  check(page.status === 200 && page.text.includes('Named Milestones') && !!token(page.text), 'superadmin can access milestone management');
  const csrf = token(page.text);
  const before = await financialSnapshot(); // automatic weekly visit, if due, has already completed

  let result = await admin('/superadmin/milestones.php', post({ _token: 'wrong', action: 'save', id: '0', name: 'CSRF Fund', target_points: '1', amount: '1', status: 'active', sort_order: '0' }));
  check([403, 419].includes(result.status) && !(await one("SELECT id FROM dashboard_milestones WHERE name='CSRF Fund'")), 'invalid CSRF cannot create a fund');
  for (const fund of [
    { name: 'Bike Fund', target_points: '1', amount: '25000', sort_order: '2' },
    { name: 'Home Fund', target_points: '100', amount: '800000', sort_order: '1' },
    { name: 'Study <img src=x onerror=alert(1)>', target_points: '4', amount: '123.45', sort_order: '3' },
  ]) {
    result = await admin('/superadmin/milestones.php', post({ _token: csrf, action: 'save', id: '0', status: 'active', ...fund }));
    check(result.status === 302 && !!(await one('SELECT id FROM dashboard_milestones WHERE name=?', [fund.name])), 'superadmin creates ' + fund.name);
  }
  result = await admin('/superadmin/milestones.php', post({ _token: csrf, action: 'save', id: '0', name: 'bike fund', target_points: '2', amount: '5', status: 'active', sort_order: '0' }));
  check(result.status === 200 && (await one("SELECT COUNT(*) n FROM dashboard_milestones WHERE name='Bike Fund'")).n === 1, 'duplicate fund names are rejected');
  result = await admin('/superadmin/milestones.php', post({ _token: csrf, action: 'save', id: '0', name: 'Bad Fund', target_points: '-1', amount: '5', status: 'active', sort_order: '0' }));
  check(result.status === 200 && !(await one("SELECT id FROM dashboard_milestones WHERE name='Bad Fund'")), 'invalid points are rejected server-side');
  page = await member('/user/index.php');
  const bike = await one("SELECT * FROM dashboard_milestones WHERE name='Bike Fund'");
  const home = await one("SELECT * FROM dashboard_milestones WHERE name='Home Fund'");
  check(page.text.includes('data-metric="fund-' + bike.id + '"') && page.text.includes('data-metric="fund-' + home.id + '"'), 'each active fund has its own member card');
  check(page.text.includes('₹25,000.00 · target 1 pts · reached, not paid') && page.text.includes('₹8,00,000.00 · target 100 pts'), 'each fund displays its own point target, amount and unpaid status');
  check(page.text.includes('Study &lt;img src=x onerror=alert(1)&gt;') && !page.text.includes('Study <img src=x'), 'fund names are HTML-escaped on the member page');
  await admin('/superadmin/milestones.php', post({ _token: csrf, action: 'save', id: String(bike.id), name: 'Bike Fund', target_points: '8', amount: '27000', status: 'inactive', sort_order: '2' }));
  page = await member('/user/index.php');
  check(!page.text.includes('data-metric="fund-' + bike.id + '"') && page.text.includes('data-metric="fund-' + home.id + '"'), 'inactive funds disappear without affecting other cards');
  await admin('/superadmin/milestones.php', post({ _token: csrf, action: 'delete', id: String(home.id) }));
  check(!(await one('SELECT id FROM dashboard_milestones WHERE id=?', [home.id])), 'superadmin can delete one fund');

  page = await admin('/superadmin/ranks.php'); const rankToken = token(page.text);
  const memberRank = await one('SELECT r.* FROM ranks r JOIN users u ON u.rank_id=r.id WHERE u.username=?', [process.env.YASH_TEST_MEMBER || 'YSH100002']);
  check(!!memberRank && page.text.includes('name="icon_image"'), 'rank form offers an image upload');
  const rankData = { _token: rankToken, action: 'save', id: memberRank.id, name: memberRank.name,
    min_points: memberRank.min_points, reward_amount: memberRank.reward_amount, sort_order: memberRank.sort_order };
  const png = await readFile(path.join(root, 'assets/img/favicon.png'));
  const baselineFiles = await iconFiles();
  result = await admin('/superadmin/ranks.php', rankForm({ ...rankData, _token: 'wrong' }, png));
  check([403, 419].includes(result.status) && JSON.stringify(await iconFiles()) === JSON.stringify(baselineFiles), 'CSRF blocks rank uploads before files are saved');
  result = await admin('/superadmin/ranks.php', rankForm(rankData, Buffer.from('<svg onload="alert(1)"></svg>'), 'fake.png'));
  check(result.status === 200 && result.text.includes('valid JPG, PNG, GIF or WebP') && JSON.stringify(await iconFiles()) === JSON.stringify(baselineFiles), 'spoofed image contents are rejected');
  result = await admin('/superadmin/ranks.php', rankForm(rankData, png, 'image.php', 'application/x-php'));
  check(result.status === 200 && result.text.includes('valid JPG, PNG, GIF or WebP') && JSON.stringify(await iconFiles()) === JSON.stringify(baselineFiles), 'executable extensions are rejected even with image content');
  const hugeDimensions = Buffer.from(png); hugeDimensions.writeUInt32BE(2049, 16);
  result = await admin('/superadmin/ranks.php', rankForm(rankData, hugeDimensions));
  check(result.status === 200 && result.text.includes('2048') && JSON.stringify(await iconFiles()) === JSON.stringify(baselineFiles), 'oversized image dimensions are rejected');
  const tooLarge = Buffer.alloc(6 * 1024 * 1024, 1); png.copy(tooLarge);
  result = await admin('/superadmin/ranks.php', rankForm(rankData, tooLarge));
  check(result.status === 200 && JSON.stringify(await iconFiles()) === JSON.stringify(baselineFiles), 'oversized image uploads cannot change the rank or create files');
  const polyglot = Buffer.concat([png, Buffer.from('<?php echo "RANK_ICON_PAYLOAD"; ?>')]);
  result = await admin('/superadmin/ranks.php', rankForm(rankData, polyglot, 'my-rank.png'));
  let rank = await one('SELECT * FROM ranks WHERE id=?', [memberRank.id]);
  check(result.status === 302 && /^rank-icons\/[a-f0-9]{32}\.png$/.test(rank.icon_image), 'uploading a rank image saves a random local PNG path');
  const safeBytes = await readFile(path.join(root, 'uploads', rank.icon_image));
  check(safeBytes.subarray(0, 8).equals(Buffer.from('89504e470d0a1a0a', 'hex')) && !safeBytes.includes(Buffer.from('RANK_ICON_PAYLOAD')), 'uploaded image is decoded and re-encoded without appended script content');
  const imageResponse = await fetch(base + '/uploads/' + rank.icon_image);
  check(imageResponse.status === 200, 'saved rank image is served successfully');
  page = await member('/user/index.php');
  const rankCard = page.text.match(/<article class="md-metric" data-metric="rank">([\s\S]*?)<\/article>/)?.[1] || '';
  check(rankCard.includes(rank.name) && rankCard.includes('/uploads/' + rank.icon_image)
    && page.text.indexOf('data-metric="rank"') < page.text.indexOf('data-metric="cash-reward"'), 'member rank card shows the saved current rank and uploaded image before Cash Reward');
  result = await admin('/superadmin/ranks.php', rankForm(rankData));
  check(result.status === 302 && (await one('SELECT icon_image FROM ranks WHERE id=?', [memberRank.id])).icon_image === rank.icon_image, 'editing a rank without a new file retains its image');
  const oldImage = rank.icon_image;
  result = await admin('/superadmin/ranks.php', rankForm(rankData, png));
  rank = await one('SELECT * FROM ranks WHERE id=?', [memberRank.id]);
  check(result.status === 302 && rank.icon_image !== oldImage && !(await iconFiles()).includes(path.basename(oldImage)), 'replacing an image removes the previous unreferenced file');
  const newPath = rank.icon_image;
  result = await admin('/superadmin/ranks.php', rankForm({ ...rankData, remove_icon: '1' }));
  rank = await one('SELECT * FROM ranks WHERE id=?', [memberRank.id]); page = await member('/user/index.php');
  check(result.status === 302 && rank.icon_image === null && !(await iconFiles()).includes(path.basename(newPath))
    && (page.text.match(/<article class="md-metric" data-metric="rank">([\s\S]*?)<\/article>/)?.[1] || '').includes('fa-award'), 'removing an image restores the default rank icon');
  result = await admin('/superadmin/ranks.php', rankForm({ ...rankData, id: 0, name: 'Test New Rank', min_points: 999999, reward_amount: 0 }, png));
  const created = await one("SELECT * FROM ranks WHERE name='Test New Rank'");
  check(result.status === 302 && created && /^rank-icons\/[a-f0-9]{32}\.png$/.test(created.icon_image), 'new ranks can be created with an uploaded image');
  const preDuplicate = await iconFiles();
  result = await admin('/superadmin/ranks.php', rankForm({ ...rankData, id: 0, name: 'Test New Rank' }, png));
  check(result.status === 200 && result.text.includes('Rank name already exists') && JSON.stringify(await iconFiles()) === JSON.stringify(preDuplicate), 'failed rank creation removes its temporary uploaded icon');
  result = await admin('/superadmin/ranks.php', post({ _token: rankToken, action: 'delete', id: String(memberRank.id) }));
  check(result.status === 302 && !!(await one('SELECT id FROM ranks WHERE id=?', [memberRank.id])), 'rank held by a member cannot be deleted');
  result = await admin('/superadmin/ranks.php', post({ _token: rankToken, action: 'delete', id: String(created.id) }));
  check(result.status === 302 && !(await one('SELECT id FROM ranks WHERE id=?', [created.id])) && !(await iconFiles()).includes(path.basename(created.icon_image)), 'deleting an unused rank also cleans up its icon');
  check(await financialSnapshot() === before, 'fund and rank image administration leaves earnings, points, wallets, payouts and legacy Car/Tour settings unchanged');
  console.log('OK: dashboard HTTP security (' + checks + ' checks)');
} finally {
  await db.query('DELETE FROM dashboard_milestones WHERE name=?', [marker]).catch(() => {});
  await db.end();
}
