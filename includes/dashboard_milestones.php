<?php
/** Named dashboard funds are informational configuration, never income. */

/** Missing or nontransactional storage must not break the existing dashboard. */
function dashboard_milestones_available()
{
    $engine = q_val("SELECT ENGINE FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dashboard_milestones'");
    if (strtoupper((string)$engine) !== 'INNODB') { return false; }
    $columns = (int)q_val("SELECT COUNT(*) FROM information_schema.COLUMNS
                           WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='dashboard_milestones'
                           AND COLUMN_NAME IN ('id','name','target_points','amount','status','sort_order','created_at','updated_at')");
    return $columns === 8;
}

function dashboard_milestones_list($activeOnly = false)
{
    if (!dashboard_milestones_available()) { return []; }
    return q_all("SELECT id,name,target_points,amount,status,sort_order,created_at,updated_at
                  FROM dashboard_milestones" . ($activeOnly ? " WHERE status='active'" : '') .
                 " ORDER BY sort_order, id");
}

/** Strictly validate values before they reach the installed column ranges. */
function dashboard_milestone_validate(array $input)
{
    $errors = [];
    $name = isset($input['name']) && is_scalar($input['name']) ? trim((string)$input['name']) : '';
    $nameLength = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
    if ($nameLength < 2 || $nameLength > 80 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
        $errors[] = 'Enter a milestone name between 2 and 80 characters.';
    }
    $normalized = strtolower((string)preg_replace('/\s+/', ' ', $name));
    if (in_array($normalized, ['car fund', 'tour vacation', 'tour/vacation', 'tour / vacation'], true)) {
        $errors[] = 'Car Fund and Tour/Vacation already have their own plan settings.';
    }
    $points = isset($input['target_points']) && is_scalar($input['target_points'])
        ? trim((string)$input['target_points']) : '';
    if (!ctype_digit($points) || strlen($points) > 10 || (float)$points < 1 || (float)$points > 4294967295) {
        $errors[] = 'Target points must be a whole number from 1 to 4294967295.';
    }
    $amount = isset($input['amount']) && is_scalar($input['amount']) ? trim((string)$input['amount']) : '';
    if ($amount === '' || !is_numeric($amount) || !is_finite((float)$amount)
        || (float)$amount < 0 || (float)$amount > 9999999999.99) {
        $errors[] = 'Amount must be between 0 and 9999999999.99.';
    }
    $sort = isset($input['sort_order']) && is_scalar($input['sort_order']) ? trim((string)$input['sort_order']) : '0';
    if ($sort === '') { $sort = '0'; }
    if (!ctype_digit($sort) || strlen($sort) > 10 || (float)$sort > 2147483647) {
        $errors[] = 'Sort order must be a whole number from 0 to 2147483647.';
    }
    $status = isset($input['status']) && is_scalar($input['status']) ? (string)$input['status'] : 'active';
    if (!in_array($status, ['active', 'inactive'], true)) { $errors[] = 'Choose a valid status.'; }
    if ($errors) { return [null, $errors]; }
    return [[
        'name' => $name, 'target_points' => (int)$points, 'amount' => number_format((float)$amount, 2, '.', ''),
        'status' => $status, 'sort_order' => (int)$sort,
    ], []];
}

/** Save configuration only. No rank, commission, wallet or payout is touched. */
function dashboard_milestone_save($id, array $input)
{
    if (!dashboard_milestones_available()) {
        throw new RuntimeException('Milestone storage is missing or not InnoDB. Apply DATABASE_CHANGES_1.8.54.sql.');
    }
    [$values, $errors] = dashboard_milestone_validate($input);
    if ($errors) { return [false, implode(' ', $errors), 0]; }
    $id = (int)$id;
    return db_tx(function () use ($id, $values) {
        if ($id && !q_row('SELECT id FROM dashboard_milestones WHERE id=? FOR UPDATE', [$id])) {
            return [false, 'That milestone no longer exists.', 0];
        }
        if ((int)q_val('SELECT COUNT(*) FROM dashboard_milestones WHERE name=? AND id<>?', [$values['name'], $id])) {
            return [false, 'A milestone with that name already exists.', 0];
        }
        if ($id) {
            q('UPDATE dashboard_milestones SET name=?,target_points=?,amount=?,status=?,sort_order=?,updated_at=? WHERE id=?',
                [$values['name'], $values['target_points'], $values['amount'], $values['status'], $values['sort_order'], now(), $id]);
        } else {
            q('INSERT INTO dashboard_milestones (name,target_points,amount,status,sort_order,created_at,updated_at) VALUES (?,?,?,?,?,?,?)',
                [$values['name'], $values['target_points'], $values['amount'], $values['status'], $values['sort_order'], now(), now()]);
            $id = (int)db()->lastInsertId();
        }
        return [true, 'Milestone saved. It is informational only.', $id];
    });
}

function dashboard_milestone_delete($id)
{
    if (!dashboard_milestones_available()) {
        throw new RuntimeException('Milestone storage is missing or not InnoDB. Apply DATABASE_CHANGES_1.8.54.sql.');
    }
    return db_tx(function () use ($id) {
        $row = q_row('SELECT id FROM dashboard_milestones WHERE id=? FOR UPDATE', [(int)$id]);
        if (!$row) { return [false, 'That milestone no longer exists.']; }
        q('DELETE FROM dashboard_milestones WHERE id=?', [(int)$id]);
        return [true, 'Milestone deleted. No wallet or payout was changed.'];
    });
}
