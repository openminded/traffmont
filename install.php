<?php
/**
 * TraffMont Web Installer
 * Designed for cPanel shared hosting (MySQL) or Local Testing (MySQL / SQLite).
 */
session_start();

$configFile = __DIR__ . '/config.php';
$alreadyInstalled = file_exists($configFile);

$errors = [];
$success = false;
$cronCommandCurl = '';
$cronCommandPhp = '';
$cronToken = '';

// Check prerequisites
$phpVersionOk = version_compare(PHP_VERSION, '7.4.0', '>=');
$pdoMysqlOk = extension_loaded('pdo_mysql');
$pdoSqliteOk = extension_loaded('pdo_sqlite');
$curlOk = extension_loaded('curl');
$socketsOk = function_exists('fsockopen') || extension_loaded('sockets');
$writableDir = is_writable(__DIR__);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install') {
    $dbDriver = $_POST['db_driver'] ?? 'mysql';
    $dbHost = trim($_POST['db_host'] ?? 'localhost');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = $_POST['db_pass'] ?? '';
    $adminUser = trim($_POST['admin_user'] ?? 'admin');
    $adminPass = $_POST['admin_pass'] ?? '';
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $timezone = trim($_POST['timezone'] ?? 'Asia/Jakarta');

    if ($dbDriver === 'mysql') {
        if (empty($dbName)) $errors[] = "Database name is required.";
        if (empty($dbUser)) $errors[] = "Database user is required.";
    }
    if (empty($adminUser)) $errors[] = "Admin username is required.";
    if (strlen($adminPass) < 6) $errors[] = "Admin password must be at least 6 characters.";

    if (empty($errors)) {
        try {
            if ($dbDriver === 'sqlite') {
                $dbFile = __DIR__ . '/database.sqlite';
                $pdo = new PDO("sqlite:" . $dbFile, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);
                $pdo->exec("PRAGMA foreign_keys = ON;");

                $schemaFile = __DIR__ . '/schema_sqlite.sql';
                if (!file_exists($schemaFile)) {
                    throw new Exception("schema_sqlite.sql file not found!");
                }
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);

                $cronToken = bin2hex(random_bytes(16));
                $passHash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?)");
                $stmt->execute([$adminUser, $passHash, $adminEmail]);

                $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('cron_token', ?), ('timezone', ?) ON CONFLICT(key_name) DO UPDATE SET key_value = excluded.key_value");
                $stmt->execute([$cronToken, $timezone]);

                $configContent = "<?php\n"
                    . "/**\n * TraffMont Configuration (SQLite Local)\n */\n\n"
                    . "define('DB_DRIVER', 'sqlite');\n"
                    . "define('DB_PATH', __DIR__ . '/database.sqlite');\n\n"
                    . "define('APP_TIMEZONE', " . var_export($timezone, true) . ");\n"
                    . "define('CRON_TOKEN', " . var_export($cronToken, true) . ");\n";
            } else {
                $dsn = "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]);

                $schemaFile = __DIR__ . '/schema.sql';
                if (!file_exists($schemaFile)) {
                    throw new Exception("schema.sql file not found!");
                }
                $sql = file_get_contents($schemaFile);
                $pdo->exec($sql);

                $cronToken = bin2hex(random_bytes(16));
                $passHash = password_hash($adminPass, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, email) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), email = VALUES(email)");
                $stmt->execute([$adminUser, $passHash, $adminEmail]);

                $stmt = $pdo->prepare("INSERT INTO settings (key_name, key_value) VALUES ('cron_token', ?), ('timezone', ?) ON DUPLICATE KEY UPDATE key_value = VALUES(key_value)");
                $stmt->execute([$cronToken, $timezone]);

                $configContent = "<?php\n"
                    . "/**\n * TraffMont Configuration (MySQL cPanel/XAMPP)\n */\n\n"
                    . "define('DB_DRIVER', 'mysql');\n"
                    . "define('DB_HOST', " . var_export($dbHost, true) . ");\n"
                    . "define('DB_NAME', " . var_export($dbName, true) . ");\n"
                    . "define('DB_USER', " . var_export($dbUser, true) . ");\n"
                    . "define('DB_PASS', " . var_export($dbPass, true) . ");\n\n"
                    . "define('APP_TIMEZONE', " . var_export($timezone, true) . ");\n"
                    . "define('CRON_TOKEN', " . var_export($cronToken, true) . ");\n";
            }

            if (file_put_contents($configFile, $configContent) === false) {
                throw new Exception("Could not write config.php. Please ensure directory is writable.");
            }

            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? "https://" : "http://";
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
            $uriDir = rtrim(dirname($_SERVER['REQUEST_URI'] ?? ''), '/\\');
            $cronUrl = "{$protocol}{$host}{$uriDir}/cron.php?token={$cronToken}";
            $cronCommandCurl = "* * * * * curl -s \"{$cronUrl}\" >/dev/null 2>&1";
            $cronCommandPhp = "* * * * * php " . __DIR__ . "/cron.php";

            $success = true;
        } catch (Exception $e) {
            $errors[] = "Installation failed: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TraffMont - Web Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #0f172a; color: #f8fafc; font-family: 'Segoe UI', system-ui, sans-serif; }
        .card { background-color: #1e293b; border: 1px solid #334155; border-radius: 12px; }
        .form-control, .form-select { background-color: #0f172a; border: 1px solid #334155; color: #f8fafc; }
        .form-control:focus, .form-select:focus { background-color: #0f172a; color: #fff; border-color: #38bdf8; box-shadow: 0 0 0 0.25rem rgba(56, 189, 248, 0.25); }
        .badge-check { font-size: 0.9rem; }
        .code-box { background: #090d16; border: 1px solid #334155; border-radius: 6px; padding: 12px; font-family: monospace; color: #38bdf8; word-break: break-all; }
        .driver-select-card { cursor: pointer; transition: all 0.2s; border: 2px solid #334155; }
        .driver-select-card:hover, .driver-select-card.active { border-color: #38bdf8; background-color: rgba(56, 189, 248, 0.1); }
    </style>
</head>
<body class="py-5">
<div class="container" style="max-width: 680px;">
    <div class="text-center mb-4">
        <h2 class="fw-bold text-info"><i class="bi bi-activity"></i> TraffMont</h2>
        <p class="text-secondary">MikroTik Interface Traffic, Uptime & SLA Monitor (cPanel & Local Testing)</p>
    </div>

    <?php if ($success): ?>
        <div class="card p-4 shadow-lg mb-4 text-center border-success">
            <div class="text-success mb-3"><i class="bi bi-check-circle-fill display-3"></i></div>
            <h3 class="fw-bold text-white">Instalasi Berhasil!</h3>
            <p class="text-secondary">TraffMont telah siap digunakan.</p>
            
            <div class="text-start mt-3">
                <h6 class="fw-bold text-warning"><i class="bi bi-clock-history"></i> Setup Cron Job Pengecekan 1 Menit:</h6>
                <p class="small text-muted mb-2">Jika di cPanel: Buka menu <strong>Cron Jobs</strong> &gt; pilih <strong>Once Per Minute (* * * * *)</strong> &gt; masukkan perintah berikut:</p>
                <div class="code-box mb-3"><?= htmlspecialchars($cronCommandCurl) ?></div>
                <p class="small text-muted mb-1">Jika di Local (Windows/Mac/Linux): Jalankan di terminal/CMD atau buka file <code>local-cron.bat</code>:</p>
                <div class="code-box mb-3"><?= htmlspecialchars($cronCommandPhp) ?></div>
            </div>

            <div class="mt-4">
                <a href="login.php" class="btn btn-primary btn-lg w-100 fw-bold"><i class="bi bi-box-arrow-in-right"></i> Masuk ke Dashboard Sekarang</a>
            </div>
        </div>
    <?php else: ?>

        <?php if ($alreadyInstalled): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle-fill"></i> <strong>TraffMont sudah terinstall.</strong> File <code>config.php</code> sudah ada.
                <div class="mt-2"><a href="index.php" class="btn btn-sm btn-outline-light">Ke Dashboard</a></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-danger">
                <strong><i class="bi bi-x-circle"></i> Terjadi Kesalahan:</strong>
                <ul class="mb-0 mt-2">
                    <?php foreach ($errors as $err): ?>
                        <li><?= htmlspecialchars($err) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Environment Checks -->
        <div class="card p-3 mb-4">
            <h6 class="fw-bold text-secondary text-uppercase mb-2" style="font-size: 0.8rem;">Pemeriksaan Kebutuhan Server</h6>
            <div class="d-flex flex-wrap gap-2">
                <span class="badge <?= $phpVersionOk ? 'bg-success' : 'bg-danger' ?> badge-check">
                    <i class="bi bi-<?= $phpVersionOk ? 'check' : 'x' ?>"></i> PHP <?= PHP_VERSION ?>
                </span>
                <span class="badge <?= $pdoMysqlOk ? 'bg-success' : 'bg-secondary' ?> badge-check">
                    <i class="bi bi-<?= $pdoMysqlOk ? 'check' : 'dash' ?>"></i> PDO MySQL (cPanel)
                </span>
                <span class="badge <?= $pdoSqliteOk ? 'bg-success' : 'bg-secondary' ?> badge-check">
                    <i class="bi bi-<?= $pdoSqliteOk ? 'check' : 'dash' ?>"></i> PDO SQLite (Local)
                </span>
                <span class="badge <?= $curlOk ? 'bg-success' : 'bg-danger' ?> badge-check">
                    <i class="bi bi-<?= $curlOk ? 'check' : 'x' ?>"></i> cURL
                </span>
                <span class="badge <?= $writableDir ? 'bg-success' : 'bg-danger' ?> badge-check">
                    <i class="bi bi-<?= $writableDir ? 'check' : 'x' ?>"></i> Writable
                </span>
            </div>
        </div>

        <div class="card p-4 shadow-lg">
            <h5 class="fw-bold text-white mb-3"><i class="bi bi-sliders"></i> Pilih Tipe Database &amp; Konfigurasi</h5>
            <form method="POST" action="">
                <input type="hidden" name="action" value="install">

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <div class="card p-3 driver-select-card active" id="cardMysql" onclick="selectDriver('mysql')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="db_driver" id="radioMysql" value="mysql" checked>
                                <label class="form-check-label fw-bold text-white" for="radioMysql">
                                    <i class="bi bi-database text-info me-1"></i> MySQL / MariaDB
                                </label>
                            </div>
                            <div class="small text-secondary mt-1">Untuk hosting cPanel, XAMPP, atau Laragon.</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card p-3 driver-select-card" id="cardSqlite" onclick="selectDriver('sqlite')">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="db_driver" id="radioSqlite" value="sqlite">
                                <label class="form-check-label fw-bold text-white" for="radioSqlite">
                                    <i class="bi bi-filetype-sql text-warning me-1"></i> SQLite (Local Cepat)
                                </label>
                            </div>
                            <div class="small text-secondary mt-1">1-Klik tanpa perlu server MySQL, langsung jalan!</div>
                        </div>
                    </div>
                </div>

                <!-- MySQL Details (hidden if SQLite chosen) -->
                <div id="mysqlFields">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-secondary small">Database Host</label>
                            <input type="text" name="db_host" class="form-control" value="localhost">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small">Database Name</label>
                            <input type="text" name="db_name" class="form-control" placeholder="traffmont_db">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label text-secondary small">Database User</label>
                            <input type="text" name="db_user" class="form-control" placeholder="root / dbuser">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label text-secondary small">Database Password</label>
                            <input type="password" name="db_pass" class="form-control" placeholder="Kosongkan jika root tanpa password">
                        </div>
                    </div>
                </div>

                <div id="sqliteFields" class="d-none">
                    <div class="alert alert-info py-2 small mb-3">
                        <i class="bi bi-info-circle me-1"></i> File database <code>database.sqlite</code> akan otomatis dibuat di folder ini tanpa setup database server sama sekali.
                    </div>
                </div>

                <hr class="border-secondary my-4">

                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label text-secondary small">Admin Username</label>
                        <input type="text" name="admin_user" class="form-control" value="admin" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary small">Admin Password</label>
                        <input type="password" name="admin_pass" class="form-control" placeholder="Minimal 6 karakter" required>
                    </div>
                </div>

                <div class="row g-3 mb-4">
                    <div class="col-md-6">
                        <label class="form-label text-secondary small">Admin Email (Opsional)</label>
                        <input type="email" name="admin_email" class="form-control" placeholder="admin@domain.com">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-secondary small">Timezone</label>
                        <select name="timezone" class="form-select">
                            <option value="Asia/Jakarta" selected>Asia/Jakarta (WIB)</option>
                            <option value="Asia/Makassar">Asia/Makassar (WITA)</option>
                            <option value="Asia/Jayapura">Asia/Jayapura (WIT)</option>
                            <option value="UTC">UTC</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary w-100 py-2 fw-bold" <?= $alreadyInstalled ? 'disabled' : '' ?>>
                    <i class="bi bi-hdd-network"></i> Mulai Instalasi TraffMont
                </button>
            </form>
        </div>
    <?php endif; ?>
</div>

<script>
function selectDriver(driver) {
    document.getElementById('radioMysql').checked = (driver === 'mysql');
    document.getElementById('radioSqlite').checked = (driver === 'sqlite');

    if (driver === 'sqlite') {
        document.getElementById('cardSqlite').classList.add('active');
        document.getElementById('cardMysql').classList.remove('active');
        document.getElementById('mysqlFields').classList.add('d-none');
        document.getElementById('sqliteFields').classList.remove('d-none');
    } else {
        document.getElementById('cardMysql').classList.add('active');
        document.getElementById('cardSqlite').classList.remove('active');
        document.getElementById('mysqlFields').classList.remove('d-none');
        document.getElementById('sqliteFields').classList.add('d-none');
    }
}
</script>
</body>
</html>
