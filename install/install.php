<?php
/**
 * =====================================================================
 *  YASHASAVI MLM — INSTALLER
 * ---------------------------------------------------------------------
 *  Open this file in your browser (e.g. https://yoursite.com/install/),
 *  follow the steps, and the database + demo content will be created.
 *  A lock file (install/install.lock) prevents re-installation.
 * =====================================================================
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
date_default_timezone_set('Asia/Kolkata');

/* Load current config values (for pre-filling) WITHOUT registering the
 * file as "included" so init.php can freshly load it after rewrite. */
$CFG_CURRENT = [];
$cfgFile = dirname(__DIR__) . '/config.php';
if (is_file($cfgFile)) {
    $php = preg_replace('~^<\?php~i', '', (string)file_get_contents($cfgFile));
    $val = eval($php . PHP_EOL . 'return $CFG ?? [];');
    if (is_array($val)) {
        $CFG_CURRENT = $val;
    }
}
$CFG_DEFAULTS = [
    'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306', 'DB_NAME' => 'yashasavi_mlm',
    'DB_USER' => 'root', 'DB_PASS' => '', 'APP_URL' => '', 'APP_ENV' => 'production',
];
$CFG_CURRENT = array_merge($CFG_DEFAULTS, $CFG_CURRENT);

require_once __DIR__ . '/seed.php';

$lockFile = __DIR__ . '/install.lock';
$installed = is_file($lockFile);
$isCli = PHP_SAPI === 'cli' || defined('YASH_CLI');

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

function req_checks()
{
    return [
        'PHP version >= 7.4'        => version_compare(PHP_VERSION, '7.4.0', '>='),
        'PDO MySQL extension'       => extension_loaded('pdo_mysql'),
        'mbstring extension'        => extension_loaded('mbstring') ? true : 'optional (recommended)',
        'GD extension (images)'     => extension_loaded('gd') ? true : 'optional (recommended)',
        'sessions enabled'          => true,
        'uploads/ writable'         => is_writable(dirname(__DIR__) . '/uploads') || @mkdir(dirname(__DIR__) . '/uploads', 0755, true),
    ];
}

function connect_server($host, $port, $user, $pass)
{
    $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    return $pdo;
}

function write_config($vals)
{
    $template = '<?php
/**
 * =====================================================================
 *  YASHASAVI MLM  —  Application Configuration
 * ---------------------------------------------------------------------
 *  Edit the values below to match your server environment.
 *  The DATABASE and the APPLICATION URL are managed from this file.
 * =====================================================================
 */

$CFG = [

    \'APP_ENV\'   => \'%APP_ENV%\',
    \'APP_URL\'   => \'%APP_URL%\',

    \'DB_HOST\'    => \'%DB_HOST%\',
    \'DB_PORT\'    => \'%DB_PORT%\',
    \'DB_NAME\'    => \'%DB_NAME%\',
    \'DB_USER\'    => \'%DB_USER%\',
    \'DB_PASS\'    => \'%DB_PASS%\',
    \'DB_CHARSET\' => \'utf8mb4\',

    \'SESSION_NAME\' => \'YASHMLMSESS\',
    \'BCRYPT_COST\'  => 10,

    \'MAX_UPLOAD_MB\'     => 5,
    \'ALLOWED_IMG_EXT\'   => \'jpg,jpeg,png,webp,gif\',
    \'ALLOWED_DOC_EXT\'   => \'pdf\',

    \'APP_VERSION\'    => \'1.0.0\',
    \'APP_TIMEZONE\'   => \'Asia/Kolkata\',
    \'ITEMS_PER_PAGE\' => 12,
];
';
    $map = [
        '%APP_ENV%' => $vals['app_env'],
        '%APP_URL%' => $vals['app_url'],
        '%DB_HOST%' => $vals['db_host'],
        '%DB_PORT%' => $vals['db_port'],
        '%DB_NAME%' => $vals['db_name'],
        '%DB_USER%' => $vals['db_user'],
        '%DB_PASS%' => addslashes($vals['db_pass']),
    ];
    return file_put_contents(dirname(__DIR__) . '/config.php', strtr($template, $map)) !== false;
}

function copy_seed_assets()
{
    $src = __DIR__ . '/seed-assets';
    $dst = dirname(__DIR__) . '/uploads';
    if (!is_dir($src)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($src, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($it as $item) {
        $target = $dst . '/' . $it->getSubPathName();
        if ($item->isDir()) {
            @mkdir($target, 0755, true);
        } else {
            @mkdir(dirname($target), 0755, true);
            @copy($item->getPathname(), $target);
        }
    }
}

/* ------------------------------------------------------------------ */
/*  RUN INSTALL                                                        */
/* ------------------------------------------------------------------ */

function do_install($vals)
{
    // 1) connect to the server (without db) and create the database
    $server = connect_server($vals['db_host'], $vals['db_port'], $vals['db_user'], $vals['db_pass']);
    $server->exec("CREATE DATABASE IF NOT EXISTS `" . str_replace('`', '', $vals['db_name']) . "`
                   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

    // 2) remember credentials in config.php
    write_config($vals);

    // 3) define constants for this process, then boot the application core
    foreach (['DB_HOST' => 'db_host', 'DB_PORT' => 'db_port', 'DB_NAME' => 'db_name',
              'DB_USER' => 'db_user', 'DB_PASS' => 'db_pass'] as $k => $v) {
        if (!defined($k)) {
            define($k, $vals[$v]);
        }
    }
    require_once dirname(__DIR__) . '/includes/init.php';

    // 4) schema + seed
    seed_database(true);
    copy_seed_assets();

    // 5) lock
    @file_put_contents(__DIR__ . '/install.lock', 'installed ' . date('c'));
    return true;
}

/* ------------------------------------------------------------------ */
/*  CLI mode                                                           */
/* ------------------------------------------------------------------ */
if ($isCli) {
    if ($installed) {
        echo "Already installed (install/install.lock exists). Delete the lock to reinstall.\n";
        exit(0);
    }
    echo "Installing Yashasavi MLM (CLI)...\n";
    echo 'DB: ' . $CFG_CURRENT['DB_USER'] . '@' . $CFG_CURRENT['DB_HOST'] . ':' . $CFG_CURRENT['DB_PORT'] . '/' . $CFG_CURRENT['DB_NAME'] . "\n";
    do_install([
        'db_host' => $CFG_CURRENT['DB_HOST'], 'db_port' => $CFG_CURRENT['DB_PORT'],
        'db_name' => $CFG_CURRENT['DB_NAME'], 'db_user' => $CFG_CURRENT['DB_USER'],
        'db_pass' => $CFG_CURRENT['DB_PASS'], 'app_url' => $CFG_CURRENT['APP_URL'],
        'app_env' => $CFG_CURRENT['APP_ENV'],
    ]);
    echo "Done!\n";
    echo "Super Admin : superadmin / Super@123\n";
    echo "CMS Admin   : admin / Admin@123\n";
    echo "Root user   : YSH100001 / User@123\n";
    exit(0);
}

/* ------------------------------------------------------------------ */
/*  WEB mode                                                           */
/* ------------------------------------------------------------------ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install — Yashasavi MLM</title>
<style>
:root{--green:#2e7d32;--dark:#1b3a1f;--gold:#c99a2e;--bg:#f5f7f4;--danger:#c62828}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:var(--bg);color:#22301f;padding:40px 16px;line-height:1.6}
.wrap{max-width:720px;margin:0 auto}
.card{background:#fff;border-radius:14px;box-shadow:0 8px 30px rgba(27,58,31,.08);padding:32px;margin-bottom:20px}
h1{color:var(--dark);font-size:26px;margin-bottom:6px}
h2{color:var(--dark);font-size:18px;margin:18px 0 10px}
p.lead{color:#5a6b58;margin-bottom:18px}
label{display:block;font-weight:600;font-size:13px;margin:12px 0 4px;color:#33452f}
input[type=text],input[type=password]{width:100%;padding:10px 12px;border:1px solid #cfd8cf;border-radius:8px;font-size:14px}
input:focus{outline:2px solid var(--green);border-color:var(--green)}
button{margin-top:20px;background:var(--green);color:#fff;border:0;padding:12px 26px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer}
button:hover{background:var(--dark)}
table.req{width:100%;border-collapse:collapse;margin-top:8px}
table.req td{padding:7px 4px;border-bottom:1px solid #edf1ed;font-size:14px}
.ok{color:#2e7d32;font-weight:700}.warn{color:#c99a2e;font-weight:700}.bad{color:var(--danger);font-weight:700}
.creds{background:#f0f6ef;border:1px solid #cfe0cf;border-radius:10px;padding:16px 20px;margin-top:14px}
.creds code{background:#fff;border:1px solid #dbe5db;padding:2px 8px;border-radius:6px;font-size:13px}
.note{font-size:13px;color:#77787a;margin-top:10px}
.err{background:#fdecec;border:1px solid #f5c6c6;color:#8a1f1f;padding:12px 16px;border-radius:8px;margin-bottom:16px}
.grid{display:grid;grid-template-columns:1fr 1fr;gap:0 16px}
</style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <h1>🌱 Yashasavi MLM — Installer</h1>
        <p class="lead">This wizard creates the database tables, the default admin accounts and the demo website content.</p>

        <?php if ($installed): ?>
            <div class="creds" style="background:#fff8e6;border-color:#efd9a0">
                <strong>⚠ Already installed.</strong> The file <code>install/install.lock</code> exists.
                Delete it to run the installer again (this will <strong>erase all data</strong>).
            </div>
        <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <?php
            $vals = [
                'db_host' => trim($_POST['db_host'] ?? '127.0.0.1'),
                'db_port' => trim($_POST['db_port'] ?? '3306'),
                'db_name' => trim($_POST['db_name'] ?? 'yashasavi_mlm'),
                'db_user' => trim($_POST['db_user'] ?? 'root'),
                'db_pass' => (string)($_POST['db_pass'] ?? ''),
                'app_url' => rtrim(trim($_POST['app_url'] ?? ''), '/'),
                'app_env' => $CFG_CURRENT['APP_ENV'],
            ];
            $error = null;
            try {
                do_install($vals);
            } catch (Throwable $ex) {
                $error = $ex->getMessage();
            }
            ?>
            <?php if ($error): ?>
                <div class="err"><strong>Installation failed:</strong> <?= htmlspecialchars($error) ?></div>
                <p><a href="install.php">&larr; Go back and try again</a></p>
            <?php else: ?>
                <h2 style="color:#2e7d32">✅ Installation complete!</h2>
                <div class="creds">
                    <h2 style="margin-top:0">Default logins</h2>
                    <p>🌐 <strong>Website</strong> — <a href="../index.php">Open site</a></p>
                    <p>👤 <strong>Distributor</strong> — ID <code>YSH100001</code> / password <code>User@123</code></p>
                    <p>🛠 <strong>Website Admin (CMS)</strong> — <a href="../admin/login.php">admin panel</a> — <code>admin / Admin@123</code></p>
                    <p>👑 <strong>Super Admin (MLM)</strong> — <a href="../superadmin/login.php">super admin panel</a> — <code>superadmin / Super@123</code></p>
                </div>
                <p class="note">For security, delete the <code>/install</code> folder from your server now, and change all default passwords after your first login.</p>
            <?php endif; ?>
        <?php else: ?>
            <h2>1. Server requirements</h2>
            <table class="req">
                <?php foreach (req_checks() as $label => $ok):
                    $cls = $ok === true ? 'ok' : ($ok === 'optional (recommended)' || strpos((string)$ok, 'optional') === 0 ? 'warn' : 'bad');
                    $txt = $ok === true ? 'OK' : (is_string($ok) && $ok !== '' ? $ok : 'FAILED');
                ?>
                <tr><td><?= htmlspecialchars($label) ?></td><td class="<?= $cls ?>"><?= htmlspecialchars($txt) ?></td></tr>
                <?php endforeach; ?>
            </table>

            <form method="post">
                <h2>2. Database (MySQL / MariaDB)</h2>
                <div class="grid">
                    <div><label>DB Host</label><input type="text" name="db_host" value="<?= htmlspecialchars($CFG_CURRENT['DB_HOST']) ?>"></div>
                    <div><label>DB Port</label><input type="text" name="db_port" value="<?= htmlspecialchars($CFG_CURRENT['DB_PORT']) ?>"></div>
                </div>
                <label>DB Name</label>
                <input type="text" name="db_name" value="<?= htmlspecialchars($CFG_CURRENT['DB_NAME']) ?>">
                <div class="grid">
                    <div><label>DB User</label><input type="text" name="db_user" value="<?= htmlspecialchars($CFG_CURRENT['DB_USER']) ?>"></div>
                    <div><label>DB Password</label><input type="password" name="db_pass" value="<?= htmlspecialchars($CFG_CURRENT['DB_PASS']) ?>"></div>
                </div>
                <label>Application URL (optional — empty = auto-detect)</label>
                <input type="text" name="app_url" placeholder="https://www.yourdomain.com" value="<?= htmlspecialchars($CFG_CURRENT['APP_URL']) ?>">
                <p class="note">These values will be written to <code>config.php</code>. The database will be created if it does not exist. Existing tables &amp; data will be replaced.</p>
                <button type="submit">🚀 Install Now</button>
            </form>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
