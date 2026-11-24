<?php
/** Integration tests. Run ONLY on a disposable MySQL DB:
 *   YASH_TEST_DB=yash_test_example php tests/income_tree_integration.php
 * The application's tables are dropped/reseeded repeatedly. Never point this at real data. */
$name = getenv('YASH_TEST_DB');
if (!$name || !preg_match('/^yash_test_[a-z0-9_]+$/', $name)) {
    fwrite(STDERR, "Set YASH_TEST_DB to a disposable database whose name starts with yash_test_.\n"); exit(2);
}
define('DB_NAME', $name);
define('YASH_CLI', true);
define('APP_ENV', 'development');
require __DIR__ . '/../includes/init.php';
require __DIR__ . '/../includes/member_deletion.php';
require __DIR__ . '/../includes/bulk_seeding.php';
require __DIR__ . '/../install/seed.php';

$passed = 0; $failed = 0;
function check($cond, $label) {
    global $passed, $failed;
    if ($cond) { $passed++; echo "PASS $label\n"; }
    else { $failed++; echo "FAIL $label\n"; }
}
function num($sql, $args = []) { return (float)q_val($sql, $args); }
function eqn($a, $b) { return abs((float)$a - (float)$b) < 0.001; }
function member($name, $sponsorId, $parentId, $leg) {
    $parent = $parentId ? q_row("SELECT path, depth FROM users WHERE id = ?", [$parentId]) : null;
    q("INSERT INTO users (username,password,full_name,mobile,sponsor_id,placement_id,leg,path,depth,status,created_at)
       VALUES (?, ?, ?, ?, ?, ?, ?, '/', ?, 'active', ?)", ['tmp' . bin2hex(random_bytes(5)), 'unused', $name, '9876543210', $sponsorId ?: null, $parentId ?: null, $leg, $parent ? (int)$parent['depth'] + 1 : 0, now()]);
    $id = (int)db()->lastInsertId();
    q("UPDATE users SET username=?,path=? WHERE id=?", ['T' . $id, ($parent ? $parent['path'] : '/') . $id . '/', $id]);
    q("INSERT INTO matching_state (user_id) VALUES (?)", [$id]);
    return $id;
}
function order_bv($uid, $bv) {
    static $n = 0; $n++;
    q("INSERT INTO orders (order_no,user_id,total_bv,total_dp,payment_mode,payment_status,status,created_at)
       VALUES (?, ?, ?, 0, 'bank_transfer', 'pending', 'pending', ?)", ['TEST' . $n . '-' . bin2hex(random_bytes(3)), $uid, $bv, now()]);
    $oid = (int)db()->lastInsertId();
    [$ok, $msg] = approve_order($oid, 1);
    if (!$ok) { throw new RuntimeException('Order failed: ' . $msg); }
    return $oid;
}
function earned($id, $type) { return num("SELECT COALESCE(SUM(amount),0) FROM commissions WHERE user_id=? AND type=? AND status='credited'", [$id, $type]); }

try {
    seed_database(true);
    $p = member('Placement Parent', 1, 1, 'L');
    $s = member('Other Sponsor', 1, 1, 'R');
    check(manually_activate_member($p)[0] && manually_activate_member($s)[0], 'manual green activation uses the income pipeline');
    $left = member('Left Direct', $p, $p, 'L');
    $right = member('Right Spillover', $s, $p, 'R');
    order_bv($left, 1500); order_bv($right, 1500);
    check(eqn(earned($p, 'sponsor'), 0) && eqn(earned($p, 'binary'), 0), 'partial, non-green members do not trigger bonuses');
    check(eqn(num("SELECT left_eligible_bv FROM matching_state WHERE user_id=?", [$p]), 1500), 'approved BV from a non-green member contributes immediately');
    order_bv($left, 1500);
    check(eqn(earned($p, 'sponsor'), 300) && eqn(earned($p, 'binary'), 0), 'green direct member pays its real sponsor ₹300');
    order_bv($right, 1500);
    check(eqn(earned($s, 'sponsor'), 300), '₹300 goes to actual sponsor outside the placement parent’s tree');
    check(eqn(earned($p, 'sponsor'), 300), 'placement parent does not receive another sponsor’s ₹300');
    check(eqn(earned($p, 'binary'), 450), 'placement parent gets ₹450 matching regardless of sponsor');
    check(eqn(num("SELECT left_eligible_bv FROM matching_state WHERE user_id=?", [$p]), 3000), 'BV is not double-counted at green activation');
    order_bv($left, 6000); order_bv($right, 6000);
    check(eqn(earned($p, 'binary'), 1350), 'two additional pairs pay another ₹900');
    check(eqn(earned($p, 'sponsor'), 300), 'repeat purchases do not repeat a green-ID sponsor bonus');
    $third = member('Another Direct', $p, $left, 'L'); order_bv($third, 3000);
    check(eqn(earned($p, 'sponsor'), 600), 'multiple directs in one leg each pay ₹300');
    $pendingSponsor = member('Inactive Sponsor', 1, $right, 'L');
    $pendingDirect = member('Pending Direct', $pendingSponsor, $right, 'R'); order_bv($pendingDirect, 3000);
    check(eqn(earned($pendingSponsor, 'sponsor'), 0) && num("SELECT COUNT(*) FROM sponsor_activation_bonuses WHERE member_id=? AND status='pending'", [$pendingDirect]) === 1.0, 'inactive sponsor bonus is held as one pending event');
    check(manually_activate_member($pendingSponsor)[0] && eqn(earned($pendingSponsor, 'sponsor'), 300), 'pending bonus is paid once when sponsor becomes green');
    check(!manually_activate_member($pendingSponsor)[0] && eqn(earned($pendingSponsor, 'sponsor'), 300), 'repeated activation does not double-pay');

    // Four individually non-green buyers still contribute their team BV.
    seed_database(true);
    $nl = member('Partial Left 1', 1, 1, 'L'); $nr = member('Partial Right 1', 1, 1, 'R');
    $nl2 = member('Partial Left 2', 1, $nl, 'L'); $nr2 = member('Partial Right 2', 1, $nr, 'R');
    foreach ([$nl, $nr, $nl2, $nr2] as $uid) { order_bv($uid, 1500); }
    check(eqn(earned(1, 'binary'), 450) && eqn(earned(1, 'sponsor'), 0), 'BV matches even when all contributing members are non-green and unsponsored for income');
    check(eqn(num("SELECT left_bv FROM users WHERE id=1"), 3000) && eqn(num("SELECT right_bv FROM users WHERE id=1"), 3000), 'matching uses the displayed team BV');

    // Pairs earned while the recipient is inactive retain their original
    // value even if the plan is changed before they become green.
    seed_database(true); get_plan(true);
    $wait = member('Waiting Recipient', 1, 1, 'L');
    $wl = member('Wait Left', $wait, $wait, 'L'); $wr = member('Wait Right', $wait, $wait, 'R');
    order_bv($wl, 3000); order_bv($wr, 3000);
    check(eqn(earned($wait, 'binary'), 0) && eqn(num('SELECT pending_amount FROM matching_state WHERE user_id=?', [$wait]), 450), 'inactive recipient holds a ₹450 matching pair');
    q("UPDATE plan_settings SET binary_value=600, points_per_unit=2 WHERE id=1"); get_plan(true);
    order_bv($wl, 3000); order_bv($wr, 3000);
    check(eqn(num('SELECT pending_amount FROM matching_state WHERE user_id=?', [$wait]), 1050), 'pending pairs retain their rates across plan changes');
    check((int)q_val('SELECT pending_points FROM matching_state WHERE user_id=?', [$wait]) === 3, 'held points retain their earning-time rates');
    check(manually_activate_member($wait)[0] && eqn(earned($wait, 'binary'), 1050), 'activation settles the exact previously earned pending amount');
    check((int)q_val('SELECT reward_points FROM users WHERE id=?', [$wait]) === 3, 'activation settles held points at their original rates');
    q("UPDATE plan_settings SET binary_value=450, points_per_unit=1 WHERE id=1"); get_plan(true);

    seed_database(true); get_plan(true);
    q("UPDATE plan_settings SET points_per_unit=0 WHERE id=1"); get_plan(true);
    $wait = member('Disabled Points Recipient', 1, 1, 'L');
    $wl = member('Disabled Points Left', $wait, $wait, 'L'); $wr = member('Disabled Points Right', $wait, $wait, 'R');
    order_bv($wl, 3000); order_bv($wr, 3000);
    q("UPDATE plan_settings SET points_per_unit=1 WHERE id=1"); get_plan(true);
    manually_activate_member($wait);
    check((int)q_val('SELECT reward_points FROM users WHERE id=?', [$wait]) === 0, 'enabling points does not retroactively reward zero-point periods');
    order_bv($wl, 3000); order_bv($wr, 3000);
    check((int)q_val('SELECT reward_points FROM users WHERE id=?', [$wait]) === 1, 'new matching BV earns points after re-enabling them');

    // A blocked green sponsor is credited when unblocked, without duplicates.
    seed_database(true); get_plan(true);
    $blocked = member('Blocked Sponsor', 1, 1, 'L'); manually_activate_member($blocked);
    set_member_account_status($blocked, 'blocked');
    $direct = member('Direct of Blocked', $blocked, $blocked, 'L'); order_bv($direct, 3000);
    check(eqn(earned($blocked, 'sponsor'), 0), 'blocked sponsor bonus waits while the account is blocked');
    check(set_member_account_status($blocked, 'active')[0] && eqn(earned($blocked, 'sponsor'), 300), 'unblocking settles the held green-ID sponsor bonus');
    set_member_account_status($blocked, 'active');
    check(eqn(earned($blocked, 'sponsor'), 300), 'unblocking again does not repeat the sponsor bonus');

    // Upgrade a legacy DB: all past matches are watermarked, not replayed.
    seed_database(true); get_plan(true);
    $l = member('Legacy Left', 1, 1, 'L'); $r = member('Legacy Right', 1, 1, 'R');
    q("UPDATE users SET is_active=1,self_bv=9000 WHERE id IN (?, ?)", [$l, $r]);
    q("UPDATE users SET left_bv=9000,right_bv=9000,matched_pairs=3,wallet_balance=450,total_earned=450 WHERE id=1");
    q("INSERT INTO commissions (user_id,type,amount,bv,level,note,status,created_at) VALUES (1,'binary',450,3000,1,'legacy','credited',?)", [now()]);
    q("DELETE FROM matching_state"); q("DELETE FROM settings WHERE skey='income_rules_v1_8_49'");
    check(initialize_income_policy_baseline(), 'legacy income policy baseline is created once');
    check(!initialize_income_policy_baseline(), 'baseline migration is idempotent');
    check(eqn(earned(1, 'binary'), 450) && eqn(num('SELECT wallet_balance FROM users WHERE id=1'), 450), 'upgrade preserves historical commissions and wallet balance');
    check(eqn(num('SELECT processed_bv FROM matching_state WHERE user_id=1'), 9000), 'existing qualified matches are watermarked, not retroactively paid');
    order_bv($l, 3000); order_bv($r, 3000);
    check(eqn(earned(1, 'binary'), 900), 'one new pair after upgrade pays once, not for old pairs');
    check(eqn(earned(1, 'sponsor'), 0), 'old green IDs do not create retroactive direct bonuses');

    // Branch promotion, exact preview, stale hash and preservation of history.
    seed_database(true);
    $p = member('Delete Target', 1, 1, 'L'); $s = member('Replacement Sponsor', 1, 1, 'R');
    manually_activate_member($p); manually_activate_member($s); order_bv($p, 3000);
    $a = member('Promoted Left', $p, $p, 'L'); $b = member('Grafted Right', $s, $p, 'R');
    $c = member('Inside Left Right', $p, $a, 'R'); $e = member('Inside Left Left', $s, $a, 'L');
    order_bv($a, 3000); order_bv($b, 3000); order_bv($c, 3000); order_bv($e, 6000);
    $beforeA = earned($a, 'binary'); $beforeP = earned($p, 'sponsor');
    q("INSERT INTO orders (order_no,user_id,total_bv,payment_mode,payment_status,status,created_at) VALUES ('PENDINGDEL',?,500,'bank_transfer','pending','pending',?)", [$p, now()]);
    $pendingId = (int)db()->lastInsertId();
    q("INSERT INTO orders (order_no,user_id,total_dp,total_bv,payment_mode,payment_status,status,created_at) VALUES ('WALLETDEL',?,200,100,'wallet','paid','pending',?)", [$p, now()]);
    $walletOrder = (int)db()->lastInsertId();
    debit_wallet($p, 200, 'purchase', $walletOrder, 'test wallet payment');
    q("INSERT INTO payouts (user_id,request_no,amount,tds_amount,admin_charge,net_amount,status,created_at) VALUES (?,'DELPAY',100,5,5,90,'pending',?)", [$p, now()]);
    $pendingPayout = (int)db()->lastInsertId();
    $cycleMember = member('Sponsor Descendant', $a, $e, 'L');
    $cyclePlan = build_member_deletion_plan($p, 'L', $cycleMember);
    check(!empty($cyclePlan['error']) && strpos($cyclePlan['error'], 'cycle') !== false, 'replacement sponsor cycles are rejected');
    $plan = build_member_deletion_plan($p, 'L', $s);
    check(empty($plan['error']) && $plan['promotedId'] === $a && $plan['otherId'] === $b && $plan['destination'] === $c, 'preview shows the selected promotion and same-side graft');
    check(count($plan['sponsorChanges']) === 2 && count($plan['orders']) >= 2 && count($plan['payouts']) === 1, 'preview includes every affected referral and financial record');
    [$tampered] = delete_member_with_plan($p, 'L', $s, str_repeat('a', 64), 1);
    check(!$tampered && q_val('SELECT status FROM users WHERE id=?', [$p]) === 'active', 'tampered preview fingerprint cannot delete a member');
    q("UPDATE users SET wallet_balance=wallet_balance+1 WHERE id=?", [$p]);
    [$ok, $msg] = delete_member_with_plan($p, 'L', $s, $plan['hash'], 1);
    check(!$ok && strpos($msg, 'changed') !== false && q_val('SELECT status FROM users WHERE id=?', [$p]) === 'active', 'stale preview is rejected without changing the tree');
    $plan = build_member_deletion_plan($p, 'L', $s);
    [$ok, $msg] = delete_member_with_plan($p, 'L', $s, $plan['hash'], 1);
    check($ok, 'confirmed deletion succeeds');
    $pa = q_row('SELECT * FROM users WHERE id=?', [$a]); $pb = q_row('SELECT * FROM users WHERE id=?', [$b]);
    check((int)$pa['placement_id'] === 1 && $pa['path'] === '/1/' . $a . '/' && (int)$pb['placement_id'] === $c && $pb['leg'] === 'R', 'both complete branches remain in the rebuilt tree');
    check((int)q_val('SELECT sponsor_id FROM users WHERE id=?', [$a]) === $s && (int)q_val('SELECT sponsor_id FROM users WHERE id=?', [$c]) === $s, 'direct referrals transfer to the chosen sponsor');
    check(q_val('SELECT status FROM users WHERE id=?', [$p]) === 'deleted' && eqn(earned($p, 'sponsor'), $beforeP), 'deleted member is archived and past commissions are retained');
    check((int)q_val('SELECT COUNT(*) FROM orders WHERE user_id=?', [$p]) >= 2 && (int)q_val('SELECT COUNT(*) FROM payouts WHERE user_id=?', [$p]) === 1, 'orders and payouts remain auditable');
    check((int)q_val('SELECT COUNT(*) FROM member_deletion_audit WHERE user_id=?', [$p]) === 1, 'deletion audit is recorded');
    check(!approve_order($pendingId, 1)[0] && !authorize_and_approve_order($pendingId, 1)[0], 'pending orders for deleted members cannot be approved or authorized');
    check(!set_member_account_status($p, 'active')[0], 'a deleted account cannot be resurrected by unblocking');
    $archivedBalance = num('SELECT wallet_balance FROM users WHERE id=?', [$p]);
    $archivedEarned = num('SELECT total_earned FROM users WHERE id=?', [$p]);
    check(reject_order($walletOrder, 1, 'removed')[0] && !reject_order($walletOrder, 1, 'duplicate')[0]
          && eqn(num('SELECT wallet_balance FROM users WHERE id=?', [$p]), $archivedBalance + 200), 'paid wallet order is refunded exactly once to the archived wallet');
    check(process_payout($pendingPayout, 'reject', 1)[0] && !process_payout($pendingPayout, 'reject', 1)[0]
          && eqn(num('SELECT wallet_balance FROM users WHERE id=?', [$p]), $archivedBalance + 300), 'pending payout refund is idempotent after deletion');
    check(eqn(num('SELECT total_earned FROM users WHERE id=?', [$p]), $archivedEarned) && !request_payout($p, 500)[0], 'refunds do not inflate income and archived members cannot request payout');
    check(eqn(earned($a, 'binary'), $beforeA), 'reparenting itself does not create or replay matching income');
    order_bv($b, 3000);
    check(eqn(earned($a, 'binary'), $beforeA), 'tree-created matched volume is not paid later by accident');
    order_bv($e, 3000);
    check(eqn(earned($a, 'binary'), $beforeA + 450), 'genuinely new matched volume after deletion pays once');
    $rootPlan = build_member_deletion_plan(1, 'L', $s);
    check(empty($rootPlan['error']), 'root deletion supports a direct referral as replacement sponsor');
    [$ok] = delete_member_with_plan(1, 'L', $s, $rootPlan['hash'], 1);
    check($ok && (int)q_val('SELECT COUNT(*) FROM users WHERE placement_id IS NULL AND status <> ?', ['deleted']) === 1, 'root removal promotes a new root without losing the tree');
    check(q_val('SELECT sponsor_id FROM users WHERE id=?', [$s]) === null, 'selected direct replacement sponsor becomes sponsor root if necessary');

    // Reverse promotion, one-child removal, and legacy BV consistency.
    seed_database(true); get_plan(true);
    $target = member('Right Promotion Target', 1, 1, 'L');
    $l = member('Left Graft', 1, $target, 'L'); $r = member('Right Promotion', 1, $target, 'R');
    $deep = member('Right Left Slot', 1, $r, 'L');
    $rp = build_member_deletion_plan($target, 'R', 0);
    check(empty($rp['error']) && $rp['destination'] === $deep && $rp['otherId'] === $l, 'right branch promotion grafts the left branch into the first left slot');
    check(delete_member_with_plan($target, 'R', 0, $rp['hash'], 1)[0]
          && (int)q_val('SELECT placement_id FROM users WHERE id=?', [$l]) === $deep, 'right-branch promotion preserves both subtrees');
    $one = member('One Child Target', 1, $l, 'R'); $child = member('Only Child', 1, $one, 'R');
    $single = build_member_deletion_plan($one, '', 0);
    check(empty($single['error']) && $single['promoteLeg'] === 'R' && delete_member_with_plan($one, 'R', 0, $single['hash'], 1)[0]
          && (int)q_val('SELECT placement_id FROM users WHERE id=?', [$child]) === $l, 'single child is promoted automatically');

    seed_database(true); get_plan(true);
    $target = member('Legacy Counter Target', 1, 1, 'L'); $survivor = member('Legacy Counter Survivor', 1, 1, 'R');
    q('UPDATE users SET self_bv=3000 WHERE id IN (?, ?)', [$target, $survivor]);
    $legacyPlan = build_member_deletion_plan($target, '', 0);
    check(empty($legacyPlan['error']) && in_array(1, $legacyPlan['legacyCounters'], true), 'preview identifies legacy leg BV inconsistencies');
    check(delete_member_with_plan($target, '', 0, $legacyPlan['hash'], 1)[0]
          && eqn(num('SELECT right_bv FROM users WHERE id=1'), 3000) && eqn(earned(1, 'binary'), 0), 'deletion repairs legacy team BV without paying old volume');

    // Pending direct bonuses owed to a deleted sponsor are cancelled;
    // a bonus earned by the deleted member for somebody else stays owed.
    seed_database(true); get_plan(true);
    $target = member('Inactive Sponsor To Delete', 1, 1, 'L');
    $direct = member('Green Direct', $target, $target, 'L'); order_bv($direct, 3000);
    $pendingPlan = build_member_deletion_plan($target, '', 1);
    check(count($pendingPlan['bonuses']) === 1 && delete_member_with_plan($target, 'L', 1, $pendingPlan['hash'], 1)[0]
          && q_val('SELECT status FROM sponsor_activation_bonuses WHERE member_id=?', [$direct]) === 'cancelled', 'previewed bonuses owed to deleted sponsor are cancelled');
    check((int)q_val('SELECT sponsor_id FROM users WHERE id=?', [$direct]) === 1 && eqn(earned(1, 'sponsor'), 0), 'replacement sponsor does not get retroactive direct income');
    seed_database(true); get_plan(true);
    $sponsor = member('Sponsor Still Inactive', 1, 1, 'L');
    $target = member('Green Direct To Delete', $sponsor, $sponsor, 'L'); order_bv($target, 3000);
    $pendingPlan = build_member_deletion_plan($target, '', 0);
    check(($pendingPlan['sourceBonus']['status'] ?? '') === 'pending' && delete_member_with_plan($target, '', 0, $pendingPlan['hash'], 1)[0]
          && manually_activate_member($sponsor)[0] && eqn(earned($sponsor, 'sponsor'), 300), 'pending bonus earned for a surviving sponsor remains payable');

    // A held first-pair sponsor bonus is tied to the sponsor at accrual,
    // not to somebody assigned later while the recipient is inactive.
    seed_database(true); get_plan(true);
    $wait = member('Pending First Pair', 1, 1, 'L'); $replacement = member('Replacement', 1, 1, 'R');
    manually_activate_member($replacement);
    $wl = member('Pending First Left', $wait, $wait, 'L'); $wr = member('Pending First Right', $wait, $wait, 'R');
    order_bv($wl, 3000); order_bv($wr, 3000);
    check((int)q_val('SELECT pending_first_sponsor_id FROM matching_state WHERE user_id=?', [$wait]) === 1,
          'held first pair snapshots its original placement sponsor');
    db_tx(function () use ($wait, $replacement) { lock_network_mutation(); q('UPDATE users SET sponsor_id=? WHERE id=?', [$replacement, $wait]); });
    check(manually_activate_member($wait)[0] && eqn(earned(1, 'sponsor_matching'), 225)
          && eqn(earned($replacement, 'sponsor_matching'), 0), 'sponsor reassignment cannot transfer a historical held first-pair bonus');

    seed_database(true); get_plan(true);
    $target = member('Original First-Pair Sponsor', 1, 1, 'L'); manually_activate_member($target);
    $wait = member('Waiting Direct', $target, $target, 'L');
    $wl = member('Waiting Left', $wait, $wait, 'L'); $wr = member('Waiting Right', $wait, $wait, 'R');
    order_bv($wl, 3000); order_bv($wr, 3000);
    $firstPlan = build_member_deletion_plan($target, '', 1);
    check(count($firstPlan['pendingFirstBonuses']) === 1 && $firstPlan['pendingFirstBonuses'][0]['user_id'] === $wait,
          'deletion preview identifies held first-pair bonuses tied to the removed sponsor');
    check(delete_member_with_plan($target, 'L', 1, $firstPlan['hash'], 1)[0]
          && manually_activate_member($wait)[0] && eqn(earned($wait, 'binary'), 450)
          && eqn(earned(1, 'sponsor_matching'), 0), 'deletion preserves the member matching but does not transfer the old sponsor bonus');

    // Moving a held pair into its sponsor's tree later cannot retroactively
    // qualify the sponsor for a bonus on pre-move volume.
    seed_database(true); get_plan(true);
    $outside = member('Waiting Outside Sponsor Tree', 1, 1, 'L');
    $outsideSponsor = member('Outside Sponsor', 1, 1, 'R'); manually_activate_member($outsideSponsor);
    q('UPDATE users SET sponsor_id=? WHERE id=?', [$outsideSponsor, $outside]);
    $ol = member('Outside Left', $outside, $outside, 'L'); $or = member('Outside Right', $outside, $outside, 'R');
    order_bv($ol, 3000); order_bv($or, 3000);
    check(move_user_position($outside, $outsideSponsor, 'L', 1)[0] && manually_activate_member($outside)[0]
          && eqn(earned($outside, 'binary'),450) && eqn(earned($outsideSponsor, 'sponsor_matching'),0),
          'placement move cannot retroactively qualify a held first-pair sponsor bonus');

    // Moving a live branch cannot create or replay historical matching.
    seed_database(true); get_plan(true);
    $l = member('Move Left', 1, 1, 'L'); $r = member('Move Right', 1, 1, 'R');
    order_bv($l, 3000); order_bv($r, 3000); $matchBefore = earned(1, 'binary');
    check(move_user_position($r, $l, 'R', 1)[0] && move_user_position($r, 1, 'R', 1)[0]
          && eqn(earned(1, 'binary'), $matchBefore), 'moving a branch out and back does not replay matching income');

    // Bulk generation commits each member and business effects together,
    // with safe defaults, correct sponsors and a fresh per-member plan.
    seed_database(true); get_plan(true);
    $bulkDetails = ['full_name' => 'Bulk Fixture', 'email' => '', 'mobile' => '9876543210',
        'dob' => '', 'marital_status' => '', 'nationality' => 'Indian', 'address' => '', 'city' => '', 'state' => '', 'pincode' => '',
        'nominee_name' => '', 'nominee_relation' => '', 'bank_holder' => '', 'bank_account_no' => '', 'bank_ifsc' => '', 'bank_name' => '', 'bank_branch' => '', 'aadhaar_no' => '', 'pan_no' => ''];
    [$ok, $ba, $bac] = bulk_create_seeded_member(1, 1, 'L', false, $bulkDetails, false, false);
    $bulkUser = q_row('SELECT * FROM users WHERE id=?', [$ba]);
    check($ok && $bulkUser['kyc_status'] === 'pending' && !(int)$bulkUser['is_active'] && eqn($bulkUser['self_bv'], 0)
          && password_verify($bac, $bulkUser['password']) && (int)$bulkUser['must_change_password'] === 1,
          'bulk member defaults to no BV, inactive earning ID, pending KYC and a change-required password');
    [$ok, $bb] = bulk_create_seeded_member(1, $ba, 'L', false, $bulkDetails, false, false);
    $bulkUser = q_row('SELECT * FROM users WHERE id=?', [$bb]);
    check($ok && (int)$bulkUser['placement_id'] === $ba && (int)$bulkUser['sponsor_id'] === 1,
          'bulk same-leg generation keeps the chosen sponsor across a straight line');
    [$ok1, $bc] = bulk_create_seeded_member(1, 1, 'R', true, $bulkDetails, false, false);
    [$ok2, $bd] = bulk_create_seeded_member(1, $bc, 'R', true, $bulkDetails, false, false);
    $bulkUser = q_row('SELECT * FROM users WHERE id=?', [$bd]);
    check($ok1 && $ok2 && (int)$bulkUser['placement_id'] === $bc && (int)$bulkUser['sponsor_id'] === $bc,
          'bulk straight-line generation sponsors each member by their direct placement parent');
    // An administrator can move the last created cursor between two batch
    // transactions. Re-evaluate the intended leg instead of following it.
    $moved = move_user_position($bb, $bc, 'L', 1)[0];
    [$ok, $be] = bulk_create_seeded_member(1, $bb, 'L', false, $bulkDetails, false, false);
    $bulkUser = q_row('SELECT * FROM users WHERE id=?', [$be]);
    check($moved && $ok && (int)$bulkUser['placement_id'] === $ba && (int)$bulkUser['sponsor_id'] === 1,
          'bulk generation revalidates a moved cursor and continues on the intended leg');
    q('UPDATE plan_settings SET activation_bv=4000 WHERE id=1'); // cached plan still says 3000
    [$ok, $bf] = bulk_create_seeded_member(1, $be, 'L', false, $bulkDetails, true, false);
    $bulkUser = q_row('SELECT * FROM users WHERE id=?', [$bf]);
    check($ok && eqn($bulkUser['self_bv'], 4000) && (int)$bulkUser['is_active'] === 1
          && $bulkUser['kyc_status'] === 'pending' && eqn(earned(1, 'sponsor'), 400),
          'synthetic BV uses the fresh plan, creates real income and never automatically verifies KYC');

    // Administrative synthetic BV follows the same prospective pipeline.
    seed_database(true); get_plan(true);
    $sl = member('Seed Left', 1, 1, 'L'); $sr = member('Seed Right', 1, 1, 'R');
    check(seed_member_business($sl, 3000, true)[0] && seed_member_business($sr, 3000, true)[0]
          && eqn(earned(1, 'sponsor'), 600) && eqn(earned(1, 'binary'), 450), 'synthetic bulk seed credits real activation and matching income');
    check(!seed_member_business($sl, 3000, true)[0] && eqn(earned(1, 'sponsor'), 600), 'repeating a synthetic seed cannot double-credit income');

    // A panel registration must not be authorized by an actor deleted while
    // its form was open, even when its selected sponsor survives the removal.
    seed_database(true); get_plan(true);
    $actor = member('Soon Removed Actor', 1, 1, 'L'); $sponsor = member('Surviving Sponsor', 1, $actor, 'L');
    $actorPlan = build_member_deletion_plan($actor, '', 0);
    delete_member_with_plan($actor, 'L', 0, $actorPlan['hash'], 1);
    $data = ['_member_actor_id' => $actor, 'sponsor' => 'T' . $sponsor, 'leg' => 'L', 'full_name' => 'Must Not Register', 'email' => '', 'mobile' => '9876543210', 'password' => 'Password123!',
        'dob' => '', 'marital_status' => '', 'nationality' => 'Indian', 'address' => '', 'city' => '', 'state' => '', 'pincode' => '',
        'nominee_name' => '', 'nominee_relation' => '', 'bank_holder' => '', 'bank_account_no' => '', 'bank_ifsc' => '', 'bank_name' => '', 'bank_branch' => '', 'aadhaar_no' => '', 'pan_no' => ''];
    check(!register_distributor($data)[0], 'removed member cannot authorize registration using a surviving downline sponsor');
    $data['_member_actor_id'] = 1;
    check(register_distributor($data)[0], 'live member can still register under their surviving downline');

    // The tree's green icon reflects actual earning activation even when
    // activation was manual and joining BV remains incomplete.
    seed_database(true); get_plan(true);
    $manual = member('Manually Green', 1, 1, 'L'); manually_activate_member($manual);
    require_once __DIR__ . '/../includes/tree_renderer.php';
    $treeHtml = render_binary_tree(q_row('SELECT * FROM users WHERE id=?', [$manual]), 1, 'tree.php');
    check(strpos($treeHtml, 't-pill on') !== false && strpos($treeHtml, 'Not completed (earning enabled)') !== false,
          'tree distinguishes manual green activation from incomplete joining BV');

    // A long-lived request cannot use a cached old plan after another
    // transaction has updated rates before its order acquires the lock.
    seed_database(true); get_plan(true);
    $direct = member('Cached Plan Direct', 1, 1, 'L');
    q("UPDATE plan_settings SET sponsor_percent=12 WHERE id=1");
    order_bv($direct, 3000);
    check(eqn(earned(1, 'sponsor'), 360), 'transaction lock refreshes stale cached plan rates before activation');

    // Weekly report creates one eligible payout, excludes archived members,
    // and repeated generation never debits the same commissions twice.
    seed_database(true); get_plan(true);
    q("UPDATE users SET bank_name='Test Bank',bank_account_no='12345678',bank_ifsc='TEST0001234',kyc_status='verified' WHERE id=1");
    $removed = member('Archived Report Candidate', 1, 1, 'L');
    q("UPDATE users SET bank_name='Test Bank',bank_account_no='12345678',bank_ifsc='TEST0001234',kyc_status='verified' WHERE id=?", [$removed]);
    add_commission(1, null, 'sponsor', 1000, 0, null, 'Report fixture');
    add_commission($removed, null, 'sponsor', 800, 0, null, 'Archived report fixture');
    q("UPDATE commissions SET created_at=?", [date('Y-m-d H:i:s', strtotime('-14 days'))]);
    $removePlan = build_member_deletion_plan($removed, '', 0);
    delete_member_with_plan($removed, '', 0, $removePlan['hash'], 1);
    [$reportOk, $ts, $report] = generate_payout_report();
    $payout = q_row("SELECT * FROM payouts WHERE user_id=1 AND status='pending'");
    check($reportOk && $report['created'] === 1 && $payout && eqn($payout['amount'],1000)
          && eqn($payout['tds_amount'],50) && eqn($payout['admin_charge'],50) && eqn($payout['net_amount'],900), 'weekly payout generation applies deductions and creates the eligible payout');
    check(eqn(num('SELECT wallet_balance FROM users WHERE id=1'),0)
          && eqn(num('SELECT wallet_balance FROM users WHERE id=?',[$removed]),800)
          && !q_val('SELECT COUNT(*) FROM payouts WHERE user_id=?',[$removed]), 'weekly report excludes archived members and preserves their wallets');
    [, , $repeatReport] = generate_payout_report();
    check($repeatReport['created'] === 0 && (int)q_val("SELECT COUNT(*) FROM payouts WHERE user_id=1") === 1,
          'repeated payout generation cannot debit or pay the same income twice');

    // Last-root deletion and creation of a new first root by a superadmin.
    seed_database(true); get_plan(true);
    $emptyPlan = build_member_deletion_plan(1, '', 0);
    check(empty($emptyPlan['error']) && delete_member_with_plan(1, '', 0, $emptyPlan['hash'], 1)[0], 'the last root can be removed safely');
    $_SESSION['superadmin_id'] = 1;
    $data = ['_first_root' => true, 'sponsor' => '', 'leg' => 'L', 'full_name' => 'New Root', 'email' => '', 'mobile' => '9876543210', 'password' => 'Password123!',
        'dob' => '', 'marital_status' => '', 'nationality' => 'Indian', 'address' => '', 'city' => '', 'state' => '', 'pincode' => '',
        'nominee_name' => '', 'nominee_relation' => '', 'bank_holder' => '', 'bank_account_no' => '', 'bank_ifsc' => '', 'bank_name' => '', 'bank_branch' => '', 'aadhaar_no' => '', 'pan_no' => ''];
    [$ok, $newRoot] = register_distributor($data);
    check($ok && q_val('SELECT path FROM users WHERE id=?', [$newRoot]) === '/' . $newRoot . '/', 'superadmin can create a new company root after the tree is emptied');
} catch (Throwable $e) { $failed++; echo 'ERROR ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n"; }
echo "\n$passed passed, $failed failed\n";
exit($failed ? 1 : 0);
