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
    $loginUrl = url('login.php');

    ob_start();
    ?>
<style>
.regpop-overlay{position:fixed;inset:0;background:rgba(16,34,19,.66);backdrop-filter:blur(2px);-webkit-backdrop-filter:blur(2px);z-index:9999;display:flex;align-items:center;justify-content:center;padding:12px;animation:regpop-fade .18s ease-out}
@keyframes regpop-fade{from{opacity:0}to{opacity:1}}
@keyframes regpop-pop{from{opacity:0;transform:scale(.94) translateY(8px)}to{opacity:1;transform:none}}
.regpop{position:relative;background:#fff;border-radius:14px;width:min(410px,94vw);max-height:94vh;overflow-y:auto;padding:16px 12px 12px;text-align:center;box-shadow:0 18px 50px rgba(0,0,0,.32);animation:regpop-pop .22s ease-out;font-family:inherit}
.regpop-x{position:absolute;top:4px;right:8px;background:none;border:0;font-size:20px;line-height:1;color:#9aa79b;cursor:pointer;padding:4px}
.regpop-x:hover{color:#000}
.regpop h2{margin:0 0 3px;font-size:17px;color:#1b3a1f;font-weight:700;line-height:1.25}
.regpop-welcome{font-size:12px;color:#4a5a4d;margin:0 0 8px;line-height:1.5}
.regpop-box{background:#f4f8f3;border:1px solid #dfe9dc;border-radius:10px;padding:1px 10px;margin:0 0 7px;text-align:left}
.regpop-row{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:5px 0;border-bottom:1px dashed #dfe9dc;font-size:12px}
.regpop-row:last-child{border-bottom:0}
.regpop-row>span{color:#68786b;flex-shrink:0;font-size:11.5px}
.regpop-row>b{color:#000;font-weight:600;text-align:right;overflow-wrap:anywhere;line-height:1.35}
.regpop-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px;background:#fff;border:1px solid #c8dcc6;border-radius:7px;padding:2px 8px;cursor:pointer;user-select:all;white-space:nowrap}
.regpop-code:hover{border-color:#2e7d32;background:#f0f7ee}
.regpop-note{font-size:10px;color:#68786b;margin:0 0 6px;line-height:1.45;text-align:center}
.regpop-btns{display:flex;gap:8px;margin-top:6px}
.regpop-share{flex:1;display:block;background:#fff;border:1.5px solid #2e7d32;color:#2e7d32 !important;text-decoration:none;font-size:13px;font-weight:600;padding:10px 8px;border-radius:10px;cursor:pointer}
.regpop-share:hover{background:#f0f7ee}
.regpop-btn{flex:1.4;display:block;background:#2e7d32;color:#fff !important;text-decoration:none;font-size:13.5px;font-weight:600;padding:10px 12px;border-radius:10px}
.regpop-btn:hover{background:#256c29}
@media (max-width:400px){.regpop{padding:14px 8px 10px}.regpop-row{font-size:11.5px;gap:6px}.regpop-btns{flex-direction:column}}
</style>
<div class="regpop-overlay" id="regpop-overlay">
    <div class="regpop" role="dialog" aria-modal="true" aria-labelledby="regpop-title">
        <button type="button" class="regpop-x" id="regpop-close" aria-label="Close">&times;</button>
        <h2 id="regpop-title"><?= $selfRegister ? '🎉 Registration Successful!' : '✅ Member Registered!' ?></h2>
        <p class="regpop-welcome">
            <?php if ($selfRegister): ?>
                Welcome to <b><?= e($site) ?></b>, <b><?= e($member['full_name']) ?></b>!
            <?php else: ?>
                Share these login details with the member (shown only once).
            <?php endif; ?>
        </p>
        <div class="regpop-box">
            <div class="regpop-row"><span>Member Name</span><b><?= e($member['full_name']) ?></b></div>
            <div class="regpop-row"><span>User ID</span><b class="regpop-code" data-copy="<?= e($uid) ?>" title="Click to copy"><?= e($uid) ?> 📋</b></div>
            <div class="regpop-row"><span>Password</span><b class="regpop-code" data-copy="<?= e($uid) ?>" title="Click to copy"><?= e($uid) ?> 📋</b></div>
            <div class="regpop-row"><span>Sponsor</span><b><?= e($sponsorTxt) ?></b></div>
            <div class="regpop-row"><span>Joined</span><b><?= e($joined) ?></b></div>
        </div>
        <p class="regpop-note">🔐 Change this password after first login &nbsp;•&nbsp; 📄 PAN/Aadhaar optional — upload anytime (Upload KYC)</p>
        <div class="regpop-btns">
            <button type="button" class="regpop-share" id="regpop-share">📤 Share</button>
            <a class="regpop-btn" id="regpop-go" href="<?= e($target) ?>"><?= $selfRegister ? '🔐 Continue to Login' : '✅ Continue' ?></a>
        </div>
    </div>
</div>
<script>
(function () {
    var REG = <?= json_encode([
        'site'    => $site,
        'name'    => $member['full_name'],
        'uid'     => $uid,
        'sponsor' => $sponsorTxt,
        'joined'  => $joined,
        'login'   => $loginUrl,
    ]) ?>;
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

    /* ---- share the registration card as an IMAGE (WhatsApp etc.) ---- */
    function rr(x, px, py, w, h, r) {
        x.beginPath();
        x.moveTo(px + r, py);
        x.arcTo(px + w, py, px + w, py + h, r);
        x.arcTo(px + w, py + h, px, py + h, r);
        x.arcTo(px, py + h, px, py, r);
        x.arcTo(px, py, px + w, py, r);
        x.closePath();
    }
    function trunc(x, text, max) {
        if (x.measureText(text).width <= max) { return text; }
        while (text.length > 1 && x.measureText(text + '…').width > max) { text = text.slice(0, -1); }
        return text + '…';
    }
    function buildCard() {
        var W = 720, pad = 46, headerH = 92, titleH = 66, rowH = 50, noteH = 74, footH = 66;
        var rows = [
            ['Member Name', REG.name],
            ['User ID', REG.uid],
            ['Password', REG.uid],
            ['Sponsor', REG.sponsor],
            ['Joined', REG.joined]
        ];
        var H = headerH + titleH + rows.length * rowH + noteH + footH;
        var c = document.createElement('canvas');
        var S = 2; /* crisp on every messenger */
        c.width = W * S; c.height = H * S;
        var x = c.getContext('2d');
        x.scale(S, S);
        var F = "'Segoe UI',system-ui,Roboto,Arial,sans-serif";

        x.fillStyle = '#f5f7f4'; x.fillRect(0, 0, W, H);
        rr(x, 12, 12, W - 24, H - 24, 18); x.fillStyle = '#fff'; x.fill();
        x.strokeStyle = '#dfe9dc'; x.lineWidth = 2; x.stroke();

        /* header band */
        x.save();
        rr(x, 12, 12, W - 24, headerH, 18); x.clip();
        x.fillStyle = '#1b3a1f'; x.fillRect(12, 12, W - 24, headerH);
        x.fillStyle = '#c99a2e'; x.fillRect(12, 12 + headerH - 5, W - 24, 5);
        x.restore();
        x.fillStyle = '#fff'; x.font = 'bold 25px ' + F; x.textAlign = 'center';
        x.fillText(trunc(x, REG.site, W - 120), W / 2, 12 + headerH / 2 + 9);

        /* title */
        x.fillStyle = '#1b3a1f'; x.font = 'bold 29px ' + F; x.textAlign = 'center';
        x.fillText('Registration Successful', W / 2, headerH + titleH / 2 + 10);

        /* rows */
        var y = headerH + titleH;
        rows.forEach(function (r) {
            x.textAlign = 'left'; x.fillStyle = '#68786b'; x.font = '21px ' + F;
            x.fillText(r[0], pad, y + 19);
            x.textAlign = 'right'; x.fillStyle = '#000'; x.font = 'bold 21px ' + F;
            x.fillText(trunc(x, String(r[1]), W - pad * 2 - 170), W - pad, y + 19);
            x.strokeStyle = '#e8efe6'; x.lineWidth = 1;
            x.beginPath(); x.moveTo(pad, y + rowH - 9); x.lineTo(W - pad, y + rowH - 9); x.stroke();
            y += rowH;
        });

        /* note */
        x.textAlign = 'center'; x.fillStyle = '#4a5a4d'; x.font = '18px ' + F;
        x.fillText('Password = User ID. Change it after the first login.', W / 2, y + 24);
        x.fillText('PAN / Aadhaar can be uploaded later (Upload KYC).', W / 2, y + 50);

        /* footer */
        x.fillStyle = '#2e7d32'; x.font = 'bold 20px ' + F;
        x.fillText('Login: ' + trunc(x, REG.login, W - 160), W / 2, H - 26);
        return c;
    }
    function shareText() {
        return '🎉 Registration Successful!\n' + REG.site +
            '\n\nMember Name: ' + REG.name +
            '\nUser ID: ' + REG.uid +
            '\nPassword: ' + REG.uid +
            '\nSponsor: ' + REG.sponsor +
            '\nJoined: ' + REG.joined +
            '\n\nLogin: ' + REG.login +
            '\n(Please change the password after the first login.)';
    }
    var shareBtn = document.getElementById('regpop-share');
    shareBtn.addEventListener('click', function () {
        var c = null;
        try { c = buildCard(); } catch (err) { c = null; }
        var tryTextShare = function () {
            if (navigator.share) {
                navigator.share({ title: 'Registration Successful', text: shareText() }).catch(function () {});
            } else {
                window.open('https://wa.me/?text=' + encodeURIComponent(shareText()), '_blank');
            }
        };
        if (c && c.toBlob) {
            c.toBlob(function (blob) {
                if (!blob) { tryTextShare(); return; }
                var file = null;
                try { file = new File([blob], 'registration-' + REG.uid + '.png', { type: 'image/png' }); } catch (err) { file = null; }
                if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
                    navigator.share({ files: [file], title: 'Registration Successful' }).catch(function () {});
                } else {
                    /* no image sharing support: save the card + offer text share */
                    try {
                        var a = document.createElement('a');
                        a.href = c.toDataURL('image/png');
                        a.download = 'registration-' + REG.uid + '.png';
                        document.body.appendChild(a); a.click(); a.remove();
                    } catch (err) {}
                    tryTextShare();
                }
            }, 'image/png');
        } else {
            tryTextShare();
        }
    });
})();
</script>
    <?php
    return ob_get_clean();
}
