<?php
/**
 * Authentication & role guards.
 *
 * Three independent areas share one session but separate keys:
 *   - user       (distributor)          -> $_SESSION['user_id']
 *   - admin      (CMS website admin)    -> $_SESSION['admin_id']
 *   - superadmin (MLM super admin)      -> $_SESSION['superadmin_id']
 */

/* ------------------------------------------------------------------ */
/*  Users (distributors)                                               */
/* ------------------------------------------------------------------ */

/** Attempt login with username / member-id / email + password. */
function attempt_user_login($login, $password)
{
    $user = q_row(
        "SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1",
        [$login, $login]
    );
    if (!$user || !password_verify($password, $user['password'])) {
        return false;
    }
    if ($user['status'] === 'blocked') {
        return 'blocked';
    }
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int)$user['id'];
    q("UPDATE users SET last_login = ? WHERE id = ?", [now(), $user['id']]);
    return true;
}

function current_user()
{
    static $user = null;
    if ($user === null && !empty($_SESSION['user_id'])) {
        $user = q_row("SELECT * FROM users WHERE id = ?", [(int)$_SESSION['user_id']]);
        if (!$user) {
            unset($_SESSION['user_id']);
        }
    }
    return $user ?: null;
}

function user_logout()
{
    unset($_SESSION['user_id']);
    session_regenerate_id(true);
}

function require_user()
{
    $u = current_user();
    if (!$u || $u['status'] !== 'active') {
        unset($_SESSION['user_id']);
        flash('warning', $u ? 'Your account is not active. Please contact support.' : 'Please login to continue.');
        redirect('/login.php');
    }
    // New member IDs are initially also their passwords. Do not expose the
    // member panel until that publicly guessable password has been changed.
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $passwordPage = basename($script) === 'password.php' && basename(dirname($script)) === 'user';
    if (!empty($u['must_change_password']) && !$passwordPage) {
        flash('warning', 'Please change your first-time password before continuing.');
        redirect('/user/password.php');
    }
    return $u;
}

/* ------------------------------------------------------------------ */
/*  Admins (CMS) & Super Admins (MLM)                                  */
/* ------------------------------------------------------------------ */

/** Attempt staff login. $role = 'admin' or 'superadmin'. */
function attempt_admin_login($username, $password, $role)
{
    $admin = q_row(
        "SELECT * FROM admins WHERE username = ? AND role = ? LIMIT 1",
        [$username, $role]
    );
    if (!$admin || !password_verify($password, $admin['password'])) {
        return false;
    }
    if ($admin['status'] !== 'active') {
        return 'blocked';
    }
    session_regenerate_id(true);
    $_SESSION[$role . '_id'] = (int)$admin['id'];
    q("UPDATE admins SET last_login = ? WHERE id = ?", [now(), $admin['id']]);
    return true;
}

function current_admin($role = 'admin')
{
    static $cache = [];
    $key = $role;
    if (!array_key_exists($key, $cache) && !empty($_SESSION[$role . '_id'])) {
        $cache[$key] = q_row("SELECT * FROM admins WHERE id = ? AND role = ?", [(int)$_SESSION[$role . '_id'], $role]);
        if (!$cache[$key]) {
            unset($_SESSION[$role . '_id']);
        }
    }
    return $cache[$key] ?? null;
}

function admin_logout($role)
{
    unset($_SESSION[$role . '_id']);
    session_regenerate_id(true);
}

function require_admin()
{
    $a = current_admin('admin');
    if (!$a) {
        redirect('/admin/login.php');
    }
    return $a;
}

function require_superadmin()
{
    $a = current_admin('superadmin');
    if (!$a || $a['status'] !== 'active') {
        unset($_SESSION['superadmin_id']);
        redirect('/superadmin/login.php');
    }
    return $a;
}

/* ------------------------------------------------------------------ */
/*  Shared                                                             */
/* ------------------------------------------------------------------ */

/** Simple brute-force throttle per session: sleep grows with attempts. */
function login_throttle($key)
{
    $k = 'login_attempts_' . $key;
    $_SESSION[$k] = ($_SESSION[$k] ?? 0) + 1;
    if ($_SESSION[$k] > 5) {
        sleep(min(10, $_SESSION[$k] - 5));
        return $_SESSION[$k];
    }
    return 0;
}

function login_throttle_reset($key)
{
    unset($_SESSION['login_attempts_' . $key]);
}
