<?php
/** Create one bulk-generated member atomically, including any deliberate
 * synthetic BV/activation. Each completed member is committed separately. */
class BulkSeedException extends RuntimeException {}

function bulk_create_seeded_member($rootId, $cursorId, $leg, $lineSponsor, array $details, $withJoiningBv, $activate)
{
    // This helper must own the transaction so a failed seed never leaves a
    // registered member behind. db_tx deliberately joins nested transactions.
    if (db()->inTransaction()) { throw new LogicException('Bulk creation requires its own transaction.'); }
    try {
        return db_tx(function () use ($rootId, $cursorId, $leg, $lineSponsor, $details, $withJoiningBv, $activate) {
            lock_network_mutation(); // also refreshes plan after any concurrent edit
            $leg = $leg === 'R' ? 'R' : 'L';
            $root = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$rootId]);
            if (!$root || $root['status'] !== 'active') { throw new BulkSeedException('The anchor member is no longer active.'); }

            // Use the previous member as a fast cursor, but revalidate it and
            // the intended leg while holding the network lock. If a move or
            // deletion invalidated it, resume from the current anchor instead.
            $cursor = q_row("SELECT * FROM users WHERE id = ? AND status <> 'deleted' FOR UPDATE", [(int)$cursorId]);
            if (!$cursor) { $cursor = $root; }
            if ((int)$cursor['id'] !== (int)$root['id']) {
                $head = user_children($root['id'])[$leg];
                if (!$head || strpos($cursor['path'], $head['path']) !== 0) { $cursor = $root; }
            }
            $pos = find_position($cursor['id'], $leg);
            if (!$pos) { throw new BulkSeedException('No free position is available on the selected leg.'); }
            $parent = q_row("SELECT * FROM users WHERE id = ? FOR UPDATE", [(int)$pos[0]]);
            if (!$parent || $parent['status'] !== 'active') {
                throw new BulkSeedException('The next placement member is blocked or unavailable.');
            }

            $amount = $withJoiningBv ? (float)get_plan()['activation_bv'] : 0.0;
            if ($withJoiningBv && (!is_finite($amount) || $amount <= 0)) {
                throw new BulkSeedException('The current joining BV must be greater than zero before synthetic BV can be used.');
            }
            $data = array_merge($details, [
                'sponsor' => $lineSponsor ? $parent['username'] : $root['username'],
                'leg' => $leg,
                'placement' => (int)$parent['id'],
                'placement_leg' => $leg,
                'password' => 'Temp-' . bin2hex(random_bytes(8)),
            ]);
            [$ok, $uid, $code] = register_distributor($data);
            if (!$ok) { throw new BulkSeedException($code); }
            // The account is not visible until registration, initial password
            // and the full business pipeline have all committed together.
            q("UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?",
              [password_hash($code, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]), $uid]);
            if ($activate || $withJoiningBv) {
                [$seeded, $message] = seed_member_business($uid, $amount, $activate);
                if (!$seeded) { throw new BulkSeedException($message); }
            }
            // Registration leaves KYC pending. Synthetic data must never mark
            // identity documents as verified automatically.
            return [true, $uid, $code, $amount];
        });
    } catch (BulkSeedException $e) {
        return [false, 0, $e->getMessage(), 0.0];
    } catch (Throwable $e) {
        error_log('Bulk member transaction failed: ' . $e->getMessage());
        return [false, 0, 'Unexpected error; this member was not saved. Check server logs.', 0.0];
    }
}
