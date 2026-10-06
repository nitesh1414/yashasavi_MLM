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

/** Format money in INR (Indian lakh / crore digit grouping). */
function money($n, $symbol = '₹')
{
    $num = (float)$n;
    $neg = $num < 0;
    $num = abs($num);
    $s = number_format($num, 2, '.', '');
    $parts = explode('.', $s);
    $int = $parts[0];
    if (strlen($int) > 3) {
        $last3 = substr($int, -3);
        $rest = substr($int, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $int = $rest . ',' . $last3;
    }
    return ($neg ? '-' : '') . $symbol . $int . '.' . $parts[1];
}

/** Format BV / PV points (Indian grouping). */
function bv($n)
{
    $num = (float)$n;
    $s = number_format($num, 0, '.', '');
    if (strlen($s) > 3) {
        $last3 = substr($s, -3);
        $rest = substr($s, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $s = $rest . ',' . $last3;
    }
    return $s . ' BV';
}

/* ------------------------------------------------------------------ */
/*  URLs                                                               */
/* ------------------------------------------------------------------ */

/** Base URL of the app (no trailing slash). */
function base_url()
{
    if (defined('APP_URL') && APP_URL !== '') {
        $u = rtrim(APP_URL, '/');
        if (preg_match('~^https?://~i', $u)) {
            return $u;
        }
        /* scheme-less APP_URL (e.g. "localhost/yashasavi_MLM") — use the
           current request's scheme so links stay absolute */
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $u;
    }
    // auto-detect
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $dir    = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])) : '/';
    $dir    = rtrim(str_replace(['/admin', '/superadmin', '/user', '/install'], '', $dir), '/');
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

/**
 * Compute the absolute URL a redirect should go to.
 *
 *  - full URLs (http://…) are returned unchanged;
 *  - paths starting with '/' are resolved from the APP ROOT (base_url);
 *  - bare relative paths are resolved against the CURRENT SCRIPT's
 *    directory — built from the request origin + script path, never from
 *    base_url() (which already contains the app folder: prefixing it here
 *    too duplicated the folder in sub-directory installs, e.g.
 *    /live/yashasavi_MLM/live/yashasavi_MLM/superadmin/…).
 */
function redirect_target($path)
{
    if (preg_match('~^https?://~i', $path)) {
        return $path;                                 // full URL, as-is
    }
    if (isset($path[0]) && $path[0] === '/') {
        return base_url() . $path;                    // absolute from app root
    }
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $origin = $scheme . '://' . $host;

    $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    if ($dir === '/' || $dir === '.') {
        $dir = '';
    }
    $target = $origin . $dir . '/' . $path;

    /* normalise any ../ segments so the Location header is clean */
    $schemeSlashes = substr($target, 0, strpos($target, '://') + 3);
    $rest = substr($target, strlen($schemeSlashes));
    while (preg_match('~/[^/]+/\.\.(/|$)~', $rest)) {
        $rest = preg_replace('~/[^/]+/\.\.(/|$)~', '$1', $rest, 1);
    }
    return $schemeSlashes . $rest;
}

function redirect($path)
{
    header('Location: ' . redirect_target($path));
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
/*  Shopping cart (session)                                            */
/* ------------------------------------------------------------------ */

/**
 * Remove products from the session cart that are no longer purchasable
 * (deleted or deactivated). Without this, a deactivated product kept the
 * cart badge showing a count while the cart page looked empty and
 * checkout failed with "no longer available".
 *
 * Returns the names of the removed products (for a flash notice).
 */
function cart_cleanup()
{
    $cart = $_SESSION['cart'] ?? [];
    if (!$cart) {
        return [];
    }
    $ids = [];
    foreach ($cart as $pid => $qty) {
        $ids[(int)$pid] = true;
    }
    $in = implode(',', array_map('intval', array_keys($ids)));
    $rows = q_all("SELECT id, name, status FROM products WHERE id IN ($in)");

    $byId = [];
    foreach ($rows as $r) {
        $byId[(int)$r['id']] = $r;
    }
    $removed = [];
    foreach ($cart as $pid => $qty) {
        $pid = (int)$pid;
        if (!isset($byId[$pid]) || $byId[$pid]['status'] !== 'active') {
            $removed[] = isset($byId[$pid]) ? $byId[$pid]['name'] : ('Product #' . $pid);
            unset($cart[$pid]);
        }
    }
    if ($removed) {
        $_SESSION['cart'] = $cart;
    }
    return $removed;
}

/** Number of items currently in the cart (validated against the catalogue). */
function cart_count()
{
    cart_cleanup();
    return array_sum($_SESSION['cart'] ?? []);
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

/* ------------------------------------------------------------------ */
/*  Registration success popup                                         */
/* ------------------------------------------------------------------ */

/**
 * Renders the "Registration Successful" popup with the member's login
 * details and a welcome message. Self-contained (inline CSS + JS) so it
 * works unchanged on the public site and inside the dashboards.
 *
 * Closing the popup (button, ✕, backdrop or Escape) redirects to
 * $targetUrl — the "respective flow" (login page for self-registration,
 * the panel success page when a member was added from a dashboard).
 *
 * @param array  $member       full users row of the new member
 * @param string $targetUrl    absolute URL to continue to on close
 * @param bool   $selfRegister true = member registered themselves (public form)
 * @return string HTML
 */
function registration_success_popup(array $member, $targetUrl, $selfRegister = true)
{
    $site = setting('site_name');
    $sponsorTxt = 'Company Root';
    if (!empty($member['sponsor_id'])) {
        $sp = q_row("SELECT username, full_name FROM users WHERE id = ?", [(int)$member['sponsor_id']]);
        if ($sp) {
            $sponsorTxt = $sp['username'] . ' — ' . $sp['full_name'];
        }
    }
    $uid = (string)$member['username'];
    $joined = !empty($member['created_at']) ? date('d M Y', strtotime($member['created_at'])) : date('d M Y');
    $target = (string)$targetUrl;

    ob_start();
    ?>
<style>
.regpop-overlay{position:fixed;inset:0;background:rgba(16,34,19,.66);backdrop-filter:blur(3px);-webkit-backdrop-filter:blur(3px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:16px;animation:regpop-fade .2s ease-out}
@keyframes regpop-fade{from{opacity:0}to{opacity:1}}
@keyframes regpop-pop{from{opacity:0;transform:scale(.92) translateY(14px)}to{opacity:1;transform:none}}
.regpop{position:relative;background:#fff;border-radius:18px;width:min(440px,94vw);max-height:90vh;overflow-y:auto;padding:28px 22px 22px;text-align:center;box-shadow:0 24px 70px rgba(0,0,0,.35);animation:regpop-pop .28s cubic-bezier(.2,.9,.3,1.2);font-family:inherit}
.regpop-x{position:absolute;top:8px;right:10px;background:none;border:0;font-size:26px;line-height:1;color:#9aa79b;cursor:pointer;padding:6px}
.regpop-x:hover{color:#000}
.regpop-emoji{font-size:46px;line-height:1}
.regpop h2{margin:10px 0 4px;font-size:22px;color:#1b3a1f;font-weight:700}
.regpop-welcome{font-size:13.5px;color:#4a5a4d;margin:0 0 14px;line-height:1.55}
.regpop-box{background:#f4f8f3;border:1px solid #dfe9dc;border-radius:12px;padding:4px 14px;margin:0 0 12px;text-align:left}
.regpop-row{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:10px 0;border-bottom:1px dashed #dfe8dc;font-size:13px}
.regpop-row:last-child{border-bottom:0}
.regpop-row>span{color:#68786b;flex-shrink:0}
.regpop-row>b{color:#000;font-weight:600;text-align:right;overflow-wrap:anywhere}
.regpop-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:13.5px;background:#fff;border:1px solid #c8dcc6;border-radius:8px;padding:3px 10px;cursor:pointer;user-select:all;white-space:nowrap}
.regpop-code:hover{border-color:#2e7d32;background:#f0f7ee}
.regpop-note{font-size:12.5px;background:#fdf3d7;border:1px solid #efe0a8;color:#000;border-radius:10px;padding:9px 12px;margin:0 0 8px;text-align:left;line-height:1.5}
.regpop-btn{display:block;background:#2e7d32;color:#fff !important;text-decoration:none;font-size:15px;font-weight:600;padding:13px 16px;border-radius:12px;margin-top:14px}
.regpop-btn:hover{background:#256c29}
@media (max-width:540px){.regpop{padding:22px 14px 16px}.regpop h2{font-size:19px}.regpop-row{font-size:12.5px;flex-direction:column;align-items:flex-start;gap:2px}.regpop-row>b{text-align:left}}
</style>
<div class="regpop-overlay" id="regpop-overlay">
    <div class="regpop" role="dialog" aria-modal="true" aria-labelledby="regpop-title">
        <button type="button" class="regpop-x" id="regpop-close" aria-label="Close">&times;</button>
        <div class="regpop-emoji"><?= $selfRegister ? '🎉' : '✅' ?></div>
        <h2 id="regpop-title"><?= $selfRegister ? 'Registration Successful!' : 'Member Registered!' ?></h2>
        <p class="regpop-welcome">
            <?php if ($selfRegister): ?>
                Welcome to <b><?= e($site) ?></b>, <?= e($member['full_name']) ?>! 🌿<br>
                Your distributor account has been created successfully.
            <?php else: ?>
                <b><?= e($member['full_name']) ?></b> has been added to the network.<br>
                Share these login details with the member — they are shown only once.
            <?php endif; ?>
        </p>
        <div class="regpop-box">
            <div class="regpop-row"><span>Member Name</span><b><?= e($member['full_name']) ?></b></div>
            <div class="regpop-row"><span>User ID</span><b class="regpop-code" data-copy="<?= e($uid) ?>" title="Click to copy"><?= e($uid) ?> 📋</b></div>
            <div class="regpop-row"><span>First-time Password</span><b class="regpop-code" data-copy="<?= e($uid) ?>" title="Click to copy"><?= e($uid) ?> 📋</b></div>
            <div class="regpop-row"><span>Sponsor</span><b><?= e($sponsorTxt) ?></b></div>
            <div class="regpop-row"><span>Joined On</span><b><?= e($joined) ?></b></div>
        </div>
        <p class="regpop-note">🔐 <b>First login:</b> the password is the User ID itself — it must be changed
            immediately after logging in for the first time.</p>
        <?php if ($selfRegister): ?>
            <p class="regpop-note">📄 You can upload your PAN &amp; Aadhaar card images anytime from your
                dashboard — <b>Account → Upload KYC</b>.</p>
        <?php endif; ?>
        <a class="regpop-btn" id="regpop-go" href="<?= e($target) ?>">
            <?= $selfRegister ? '🔐 Continue to Login' : '✅ Continue' ?>
        </a>
    </div>
</div>
<script>
(function () {
    var target = <?= json_encode($target) ?>;
    var overlay = document.getElementById('regpop-overlay');
    if (!overlay) { return; }
    function go() { window.location.href = target; }
    document.getElementById('regpop-close').addEventListener('click', go);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) { go(); } });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { go(); } });
    var btn = document.getElementById('regpop-go');
    if (btn) { btn.focus(); }
    overlay.querySelectorAll('.regpop-code').forEach(function (el) {
        el.addEventListener('click', function () {
            var t = el.getAttribute('data-copy') || '';
            var done = function () {
                var old = el.innerHTML;
                el.innerHTML = '✓ Copied';
                setTimeout(function () { el.innerHTML = old; }, 1200);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(t).then(done, done);
            } else {
                var i = document.createElement('textarea');
                i.value = t; document.body.appendChild(i); i.select();
                try { document.execCommand('copy'); } catch (err) {}
                document.body.removeChild(i); done();
            }
        });
    });
})();
</script>
    <?php
    return ob_get_clean();
}
