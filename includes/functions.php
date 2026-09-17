<?php
/**
 * Global helper functions: escaping, urls, settings, csrf, flash,
 * formatting, uploads, pagination.
 */

/* ------------------------------------------------------------------ */
/*  Output helpers                                                     */
/* ------------------------------------------------------------------ */

/** HTML-escape a value. */
function e($str)
{
    return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
}

/** Convert plain text to safe HTML paragraphs (for stored CMS text). */
function rich_text($html)
{
    return $html; // content is authored by trusted admins through the editor
}

/** Format money in INR. */
function money($n, $symbol = '₹')
{
    return $symbol . number_format((float)$n, 2);
}

/** Format BV / PV points. */
function bv($n)
{
    return number_format((float)$n, 2) . ' BV';
}

/* ------------------------------------------------------------------ */
/*  URLs                                                               */
/* ------------------------------------------------------------------ */

/** Base URL of the app (no trailing slash). */
function base_url()
{
    if (defined('APP_URL') && APP_URL !== '') {
        return rtrim(APP_URL, '/');
    }
    // auto-detect
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir    = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '/';
    $dir    = rtrim(str_replace(['/admin', '/superadmin', '/user'], '', $dir), '/');
    return $scheme . '://' . $host . $dir;
}

/** Build an absolute URL for an app path, e.g. url('user/tree.php'). */
function url($path = '')
{
    if ($path === '' || $path === '/') {
        return base_url() . '/index.php';
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return base_url() . '/' . ltrim($path, '/');
}

/** URL of an uploaded file. */
function upload_url($path)
{
    if ($path === null || $path === '') {
        return null;
    }
    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }
    return url('uploads/' . ltrim($path, '/'));
}

/** Placeholder image for products etc. */
function placeholder($label = 'No Image')
{
    return 'data:image/svg+xml;utf8,' . rawurlencode(
        '<svg xmlns="http://www.w3.org/2000/svg" width="400" height="400"><rect width="100%" height="100%" fill="#eef3ec"/>'
        . '<text x="50%" y="50%" font-family="Arial" font-size="22" fill="#8aa58a" text-anchor="middle">' . $label . '</text></svg>'
    );
}

/* ------------------------------------------------------------------ */
/*  Request helpers                                                    */
/* ------------------------------------------------------------------ */

function get_str($key, $default = '')
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

function post_str($key, $default = '')
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

function get_int($key, $default = 0)
{
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

function redirect($path)
{
    if (preg_match('~^https?://~i', $path)) {
        $target = $path;                              // full URL, as-is
    } elseif (isset($path[0]) && $path[0] === '/') {
        $target = base_url() . $path;                 // absolute from app root
    } else {                                          // relative to current script's directory
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        if ($dir === '/' || $dir === '.') {
            $dir = '';
        }
        $target = base_url() . $dir . '/' . $path;
    }
    header('Location: ' . $target);
    exit;
}

function client_ip()
{
    return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
}

function is_post()
{
    return ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
}

/* ------------------------------------------------------------------ */
/*  CSRF                                                               */
/* ------------------------------------------------------------------ */

function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf()
{
    $token = $_POST['_token'] ?? $_GET['_token'] ?? '';
    if (!hash_equals(csrf_token(), (string)$token)) {
        http_response_code(419);
        die('Invalid security token. Please go back and try again.');
    }
}

/* ------------------------------------------------------------------ */
/*  Flash messages                                                     */
/* ------------------------------------------------------------------ */

function flash($type, $message)
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $message];
}

function get_flashes()
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}

function render_flashes()
{
    $out = '';
    foreach (get_flashes() as $f) {
        $cls = $f['type'] === 'error' ? 'alert-danger' : ($f['type'] === 'warning' ? 'alert-warning' : 'alert-success');
        $out .= '<div class="alert ' . $cls . ' alert-dismissible">' . e($f['msg'])
              . '<button type="button" class="btn-close" onclick="this.parentElement.remove()">&times;</button></div>';
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/*  Settings (site configuration stored in DB)                          */
/* ------------------------------------------------------------------ */

function setting($key, $default = '')
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (q_all("SELECT skey, svalue FROM settings") as $r) {
                $cache[$r['skey']] = $r['svalue'];
            }
        } catch (Throwable $e) {
            // settings table may not exist yet (installer)
        }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function save_setting($key, $value)
{
    q("INSERT INTO settings (skey, svalue) VALUES (?, ?)
       ON DUPLICATE KEY UPDATE svalue = VALUES(svalue)", [$key, $value]);
}

/* ------------------------------------------------------------------ */
/*  Validation                                                         */
/* ------------------------------------------------------------------ */

function is_email($v)
{
    return filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
}

function is_mobile($v)
{
    return (bool)preg_match('/^[6-9][0-9]{9}$/', $v); // Indian mobile
}

function strong_password_error($v)
{
    if (strlen($v) < 8) {
        return 'Password must be at least 8 characters long.';
    }
    if (!preg_match('/[A-Za-z]/', $v) || !preg_match('/[0-9]/', $v)) {
        return 'Password must contain both letters and numbers.';
    }
    return '';
}

function slugify($text)
{
    $text = strtolower(trim((string)$text));
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-') ?: 'page';
}

/* ------------------------------------------------------------------ */
/*  Dates                                                              */
/* ------------------------------------------------------------------ */

function now()
{
    return date('Y-m-d H:i:s');
}

function dmy($dt, $withTime = false)
{
    if (!$dt || $dt === '0000-00-00 00:00:00') {
        return '-';
    }
    $ts = strtotime($dt);
    return $withTime ? date('d M Y, h:i A', $ts) : date('d M Y', $ts);
}

/* ------------------------------------------------------------------ */
/*  File uploads                                                       */
/* ------------------------------------------------------------------ */

/**
 * Handle a file upload from an HTML form.
 * $allowed = comma list of extensions (without dots), e.g. 'jpg,png'.
 * Returns relative path inside /uploads (e.g. "products/abc123.jpg")
 * or null when no file was uploaded. Dies with flash error on failure.
 */
function handle_upload($field, $subdir, $allowed = ALLOWED_IMG_EXT)
{
    if (empty($_FILES[$field]['name'])) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) {
        flash('error', 'Upload failed (error code ' . (int)$f['error'] . ').');
        return '';
    }
    if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) {
        flash('error', 'File is larger than ' . MAX_UPLOAD_MB . ' MB.');
        return '';
    }
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    $allowedList = array_map('trim', explode(',', $allowed));
    if (!in_array($ext, $allowedList, true)) {
        flash('error', 'File type not allowed. Allowed: ' . implode(', ', $allowedList));
        return '';
    }
    $dir = dirname(__DIR__) . '/uploads/' . trim($subdir, '/');
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $name = date('Ymd') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    $moved = is_uploaded_file($f['tmp_name'])
        ? move_uploaded_file($f['tmp_name'], $dest)
        : @rename($f['tmp_name'], $dest); // dev-server bridge mode
    if (!$moved) {
        flash('error', 'Could not save the uploaded file (check folder permissions).');
        return '';
    }
    return trim($subdir, '/') . '/' . $name;
}

/** Delete an uploaded file (relative path). */
function delete_upload($relPath)
{
    if (!$relPath) {
        return;
    }
    $abs = dirname(__DIR__) . '/uploads/' . ltrim($relPath, '/');
    if (is_file($abs)) {
        @unlink($abs);
    }
}

/* ------------------------------------------------------------------ */
/*  Pagination                                                         */
/* ------------------------------------------------------------------ */

/**
 * Returns [limit, offset] and echoes pagination links into $links (by ref).
 */
function paginate($totalRows, $perPage, &$linksHtml, $pageParam = 'page')
{
    $page = max(1, (int)get_int($pageParam, 1));
    $pages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $pages) {
        $page = $pages;
    }
    $offset = ($page - 1) * $perPage;

    $qs = $_GET;
    $links = '';
    if ($pages > 1) {
        $links .= '<nav class="pagination-nav"><ul class="pagination">';
        $make = function ($p, $label, $active = false, $disabled = false) use ($qs, $pageParam) {
            $qs[$pageParam] = $p;
            $href = '?' . http_build_query($qs);
            $cls = 'page-item' . ($active ? ' active' : '') . ($disabled ? ' disabled' : '');
            return '<li class="' . $cls . '"><a class="page-link" href="' . e($href) . '">' . $label . '</a></li>';
        };
        $links .= $make($page - 1, '&laquo;', false, $page <= 1);
        $start = max(1, $page - 2);
        $end   = min($pages, $page + 2);
        if ($start > 1) {
            $links .= $make(1, '1') . ($start > 2 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '');
        }
        for ($i = $start; $i <= $end; $i++) {
            $links .= $make($i, (string)$i, $i == $page);
        }
        if ($end < $pages) {
            $links .= ($end < $pages - 1 ? '<li class="page-item disabled"><span class="page-link">…</span></li>' : '') . $make($pages, (string)$pages);
        }
        $links .= $make($page + 1, '&raquo;', false, $page >= $pages);
        $links .= '</ul></nav>';
    }
    $linksHtml = $links;
    return [$perPage, $offset, $page, $pages];
}

/* ------------------------------------------------------------------ */
/*  Misc                                                               */
/* ------------------------------------------------------------------ */

function order_no()
{
    return 'ORD' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}

function payout_no()
{
    return 'PWT' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
}

function badge($text, $type = 'success')
{
    return '<span class="badge badge-' . e($type) . '">' . e($text) . '</span>';
}

function status_badge($status)
{
    $map = [
        'pending'   => 'warning',
        'approved'  => 'success',
        'paid'      => 'success',
        'rejected'  => 'danger',
        'cancelled' => 'secondary',
        'active'    => 'success',
        'blocked'   => 'danger',
        'verified'  => 'success',
        'new'       => 'info',
        'read'      => 'secondary',
        'replied'   => 'success',
        'credited'  => 'success',
        'reversed'  => 'danger',
    ];
    return badge(ucfirst($status), $map[$status] ?? 'secondary');
}

/** Mask an account number for display. */
function mask_acct($no)
{
    $no = (string)$no;
    if (strlen($no) <= 4) {
        return e($no);
    }
    return e(str_repeat('X', max(0, strlen($no) - 4)) . substr($no, -4));
}

/** Simple CSV export helper. */
function output_csv($filename, $header, $rows)
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=' . $filename);
    $out = fopen('php://output', 'w');
    fputcsv($out, $header);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    exit;
}
