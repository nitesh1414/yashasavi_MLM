<?php
/** Small public AJAX endpoints (sponsor lookup). */
require_once __DIR__ . '/includes/init.php';

header('Content-Type: application/json; charset=utf-8');

$action = get_str('action');

if ($action === 'sponsor') {
    $sid = strtoupper(get_str('sid'));
    $user = find_user($sid);
    if ($user && $user['status'] === 'active') {
        echo json_encode(['found' => true, 'name' => $user['full_name'] . ' (' . $user['username'] . ')']);
    } else {
        echo json_encode(['found' => false]);
    }
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'Unknown action']);
