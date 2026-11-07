<?php
$pageTitle = 'Pengaturan & Panduan Cron - TraffMont';
require_once __DIR__ . '/includes/header.php';

$message = '';
$messageType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_settings') {
        $appName = trim($_POST['app_name'] ?? 'TraffMont');
        $timezone = trim($_POST['timezone'] ?? 'Asia/Jakarta');
        $retentionDays = (int)($_POST['retention_days'] ?? 60);
        $publicEnabled = !empty($_POST['public_status_enabled']) ? '1' : '0';
        $publicTitle = trim($_POST['public_status_title'] ?? 'Network & Service Status');
        $publicAnnouncement = trim($_POST['public_announcement'] ?? '');
        $publicShowTraffic = !empty($_POST['public_show_traffic']) ? '1' : '0';

        set_setting('app_name', $appName);
        set_setting('timezone', $timezone);
        set_setting('retention_days', (string)$retentionDays);
        set_setting('public_status_enabled', $publicEnabled);
        set_setting('public_status_title', $publicTitle);
        set_setting('public_announcement', $publicAnnouncement);
        set_setting('public_show_traffic', $publicShowTraffic);

        $message = 'Pengaturan aplikasi dan Status Page berhasil disimpan.';
    } elseif ($action === 'change_password') {
        $oldPass = $_POST['old_password'] ?? '';
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (strlen($newPass) < 6) {
            $message = 'Password baru minimal 6 karakter.';
            $messageType = 'danger';
        } elseif ($newPass !== $confirmPass) {
            $message = 'Konfirmasi password baru tidak cocok.';
            $messageType = 'danger';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$_SESSION['traffmont_user_id']]);
            $user = $stmt->fetch();

            if ($user && password_verify($oldPass, $user['password_hash'])) {
                $hash = password_hash($newPass, PASSWORD_DEFAULT);
                $stmtUp = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmtUp->execute([$hash, $user['id']]);
                $message = 'Password admin berhasil diperbarui.';
            } else {
                $message = 'Password lama salah.';
                $messageType = 'danger';
            }
        }
    } elseif ($action === 'clean_logs') {
        $retention = (int)get_setting('retention_days', 60);
        $cutoff = date('Y-m-d 00:00:00', strtotime("-{$retention} days"));
        $stmtDel = $pdo->prepare("DELETE FROM monitoring_logs WHERE checked_at < ?");
        $stmtDel->execute([$cutoff]);
        $deleted = $stmtDel->rowCount();
        $message = "Pembersihan selesai. Sebanyak {$deleted} baris log lama berhasil dihapus.";
    } elseif ($action === 'regen_token') {
        $newToken = bin2hex(random_bytes(16));
        set_setting('cron_token', $newToken);
        $message = 'Cron Security Token baru berhasil dibuat. Pastikan memperbarui URL di cPanel Cron Jobs!';
    }
}

$cronToken = get_setting('cron_token', defined('CRON_TOKEN') ? CRON_TOKEN : '');
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
$baseUrl = $protocol . $host . rtrim(dirname($_SERVER['REQUEST_URI']), '/\\');
$cronUrl = "{$baseUrl}/cron.php?token=" . urlencode($cronToken);

$cronCommandCurl = "* * * * * curl -s \"{$cronUrl}\" >/dev/null 2>&1";
$cronCommandWget = "* * * * * wget -q -O - \"{$cronUrl}\" >/dev/null 2>&1";
$cronCommandPhp = "* * * * * /usr/local/bin/php " . __DIR__ . "/cron.php >/dev/null 2>&1";
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-0"><i class="bi bi-gear text-info me-2"></i> Pengaturan &amp; Panduan cPanel</h3>
        <p class="text-secondary small mb-0">Konfigurasi sistem, keamanan, dan panduan lengkap setup cron job di cPanel shared hosting.</p>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- cPanel Cron Setup Guide -->
    <div class="col-lg-7" id="cron-guide">
        <div class="card shadow-sm mb-4 border-info">
            <div class="card-header bg-dark py-3">
                <h5 class="fw-bold text-info mb-0"><i class="bi bi-clock-history me-2"></i> Panduan Setup Cron Job di cPanel</h5>
            </div>
            <div class="card-body p-4">
                <p class="text-secondary small">
                    Karena Anda menggunakan shared hosting cPanel tanpa akses terminal/VPS, cron job dikonfigurasi melalui menu antarmuka web cPanel. Cron job inilah yang akan mengecek MikroTik Anda <strong>setiap 1 menit secara berkala</strong>.
                </p>

                <h6 class="fw-bold text-white mb-2">Langkah-langkah di cPanel:</h6>
                <ol class="text-secondary small ps-3 mb-4">
                    <li class="mb-2">Login ke akun <strong>cPanel</strong> hosting Anda.</li>
                    <li class="mb-2">Cari menu <strong>Advanced</strong> lalu klik ikon <strong>Cron Jobs</strong>.</li>
                    <li class="mb-2">Pada bagian <em>Common Settings</em>, pilih opsi <strong>Once Per Minute (* * * * *)</strong>.</li>
                    <li class="mb-2">Pada kolom <em>Command</em>, tempel (paste) perintah cURL di bawah ini:</li>
                </ol>

                <label class="form-label small text-info fw-bold">Opsi 1: Rekomendasi (cURL Web Cron)</label>
                <div class="code-box mb-3"><?= htmlspecialchars($cronCommandCurl) ?></div>

                <label class="form-label small text-info fw-bold">Opsi 2: wget</label>
                <div class="code-box mb-3"><?= htmlspecialchars($cronCommandWget) ?></div>

                <label class="form-label small text-info fw-bold">Opsi 3: PHP CLI Langsung (Jika path PHP tersedia)</label>
                <div class="code-box mb-4"><?= htmlspecialchars($cronCommandPhp) ?></div>

                <div class="d-flex align-items-center gap-2">
                    <a href="<?= htmlspecialchars($cronUrl) ?>" target="_blank" class="btn btn-sm btn-outline-info">
                        <i class="bi bi-box-arrow-up-right me-1"></i> Uji Coba Jalankan Cron Manual di Tab Baru
                    </a>
                    <form method="POST" action="" onsubmit="return confirm('Regenerasi token keamanan cron? Anda harus mengupdate URL di cPanel cron!');" class="d-inline">
                        <input type="hidden" name="action" value="regen_token">
                        <button type="submit" class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-shield-lock me-1"></i> Ganti Cron Token
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Push Agent Mode Guide -->
        <div class="card shadow-sm border-secondary">
            <div class="card-header bg-dark py-3">
                <h5 class="fw-bold text-white mb-0"><i class="bi bi-router me-2"></i> Info: MikroTik di Balik NAT / IP Dinamis (Push Mode)</h5>
            </div>
            <div class="card-body p-4 text-secondary small">
                <p>
                    Jika router MikroTik Anda berada di balik <strong>NAT / CGNAT</strong> atau <strong>IP Dinamis tanpa Public IP</strong>, hosting cPanel tidak bisa menembus masuk ke IP router.
                </p>
                <p>
                    Solusinya: Gunakan mode <strong>Push Agent Script</strong> saat mendaftarkan router di menu <em>MikroTik &amp; Interfaces</em>. TraffMont akan meng-generate script MikroTik siap pakai yang mengirim laporan interface ke cPanel Anda setiap menit menggunakan fitur bawaan <code>/tool fetch</code> di MikroTik!
                </p>
            </div>
        </div>
    </div>

    <!-- App Settings & Password -->
    <div class="col-lg-5">
        <!-- System Settings -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark py-3">
                <h5 class="fw-bold text-white mb-0"><i class="bi bi-sliders me-2"></i> Konfigurasi Aplikasi</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_settings">

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Nama Aplikasi / Judul</label>
                        <input type="text" name="app_name" class="form-control" value="<?= htmlspecialchars(get_setting('app_name', 'TraffMont')) ?>" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Timezone Server</label>
                        <select name="timezone" class="form-select">
                            <?php $currTz = get_setting('timezone', 'Asia/Jakarta'); ?>
                            <option value="Asia/Jakarta" <?= ($currTz === 'Asia/Jakarta') ? 'selected' : '' ?>>Asia/Jakarta (WIB)</option>
                            <option value="Asia/Makassar" <?= ($currTz === 'Asia/Makassar') ? 'selected' : '' ?>>Asia/Makassar (WITA)</option>
                            <option value="Asia/Jayapura" <?= ($currTz === 'Asia/Jayapura') ? 'selected' : '' ?>>Asia/Jayapura (WIT)</option>
                            <option value="UTC" <?= ($currTz === 'UTC') ? 'selected' : '' ?>>UTC</option>
                        </select>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small text-secondary">Retensi Log Detail (Hari)</label>
                        <div class="input-group">
                            <input type="number" name="retention_days" class="form-control" value="<?= (int)get_setting('retention_days', 60) ?>" min="14" max="365">
                            <span class="input-group-text bg-dark border-secondary text-secondary">Hari</span>
                        </div>
                        <div class="form-text text-secondary" style="font-size: 0.75rem;">
                            Log mentah 1-menit yang lebih tua dari hari ini akan otomatis dibersihkan agar database hosting cPanel tetap ringan dan cepat.
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary w-100 fw-bold">
                        <i class="bi bi-save me-1"></i> Simpan Pengaturan
                    </button>
                </form>
            </div>
        </div>

        <!-- Android App & Floating Widget Card -->
        <div class="card shadow-sm mb-4 border-success">
            <div class="card-header bg-dark py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold text-success mb-0"><i class="bi bi-android2 me-2"></i> Aplikasi Android (Home Screen &amp; Floating Widget)</h5>
                <span class="badge bg-success-subtle text-success border border-success">Versi APK v1.0</span>
            </div>
            <div class="card-body p-4">
                <p class="small text-secondary mb-3">
                    Pantau real-time traffic MikroTik (Download &amp; Upload Mbps, Latency, dan status UP/DOWN) langsung dari layar HP Android Anda tanpa perlu membuka browser.
                </p>

                <div class="row g-3 align-items-center mb-3">
                    <div class="col-md-7">
                        <ul class="small text-light mb-0 ps-3">
                            <li><strong class="text-success">Floating Widget Melayang:</strong> Gelembung/pill kecil yang melayang di atas layar HP (bisa digeser ke mana saja, tetap tampil saat buka game, YouTube, atau WhatsApp).</li>
                            <li><strong class="text-info">Home Screen Widget:</strong> Widget resmi Android yang bisa ditaruh di layar utama HP (Home Screen).</li>
                            <li><strong class="text-warning">Alert Bunyi Otomatis:</strong> Suara &amp; getar notifikasi langsung berbunyi jika interface MikroTik DOWN.</li>
                        </ul>
                    </div>
                    <div class="col-md-5 text-md-end">
                        <a href="TraffMont-Monitor.apk" download class="btn btn-success fw-bold px-4 py-2 w-100 mb-2">
                            <i class="bi bi-download me-1"></i> Download APK Android (5.7 MB)
                        </a>
                        <div class="text-muted small">File: <code>TraffMont-Monitor.apk</code></div>
                    </div>
                </div>

                <div class="bg-black p-3 rounded border border-secondary small">
                    <div class="text-secondary mb-1"><strong>Cara Pakai:</strong></div>
                    <ol class="mb-0 ps-3 text-secondary">
                        <li>Download dan install <code>TraffMont-Monitor.apk</code> di HP Android Anda.</li>
                        <li>Buka aplikasi, masukkan URL server ini (<code><?= $baseUrl ?></code>).</li>
                        <li>Klik <strong>"Hubungkan &amp; Ambil Interface"</strong>, lalu pilih interface yang ingin dipantau.</li>
                        <li>Nyalakan <strong>"Floating Widget"</strong> atau pasang <strong>"Home Screen Widget"</strong> di layar HP Anda.</li>
                    </ol>
                </div>
            </div>
        </div>

        <!-- Public Status Page Settings -->
        <div class="card shadow-sm mb-4 border-info">
            <div class="card-header bg-dark py-3 d-flex justify-content-between align-items-center">
                <h5 class="fw-bold text-info mb-0"><i class="bi bi-broadcast me-2"></i> Pengaturan Public Status Page</h5>
                <a href="status.php" target="_blank" class="btn btn-sm btn-outline-info">
                    <i class="bi bi-box-arrow-up-right me-1"></i> Buka Status Page
                </a>
            </div>
            <div class="card-body p-4">
                <p class="small text-secondary mb-3">Halaman ini dapat diakses oleh siapa saja (klien, publik, atau tim Anda) untuk melihat status uptime dan SLA tanpa perlu login.</p>

                <div class="mb-3">
                    <label class="form-label small text-secondary">Link Publik (Bagikan ke Orang Lain):</label>
                    <div class="input-group">
                        <input type="text" class="form-control form-control-sm text-info bg-black" id="publicStatusUrl" value="<?= $baseUrl ?>/status.php" readonly>
                        <button class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('publicStatusUrl').value); alert('Link Public Status Page berhasil disalin!');">
                            <i class="bi bi-clipboard me-1"></i> Salin Link
                        </button>
                    </div>
                </div>

                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="app_name" value="<?= htmlspecialchars(get_setting('app_name', 'TraffMont')) ?>">
                    <input type="hidden" name="timezone" value="<?= htmlspecialchars(get_setting('timezone', 'Asia/Jakarta')) ?>">
                    <input type="hidden" name="retention_days" value="<?= (int)get_setting('retention_days', 60) ?>">

                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" name="public_status_enabled" id="chkPublicEnabled" value="1" <?= (get_setting('public_status_enabled', '1') === '1') ? 'checked' : '' ?>>
                        <label class="form-check-label text-white small" for="chkPublicEnabled">Aktifkan Halaman Status Publik</label>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Judul Halaman Publik</label>
                        <input type="text" name="public_status_title" class="form-control form-control-sm" value="<?= htmlspecialchars(get_setting('public_status_title', 'Network & Service Status')) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Pengumuman / Catatan Pemeliharaan (Opsional)</label>
                        <textarea name="public_announcement" class="form-control form-control-sm" rows="2" placeholder="Contoh: Pemeliharaan jaringan terjadwal Minggu jam 02:00 WIB"><?= htmlspecialchars(get_setting('public_announcement', '')) ?></textarea>
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" name="public_show_traffic" id="chkShowTraffic" value="1" <?= (get_setting('public_show_traffic', '1') === '1') ? 'checked' : '' ?>>
                        <label class="form-check-label text-white small" for="chkShowTraffic">Tampilkan Angka Bandwidth (RX/TX Mbps) di Halaman Publik</label>
                    </div>

                    <button type="submit" class="btn btn-outline-info w-100 fw-bold btn-sm">
                        <i class="bi bi-save me-1"></i> Simpan Pengaturan Status Page
                    </button>
                </form>
            </div>
        </div>

        <!-- Change Password -->
        <div class="card shadow-sm mb-4">
            <div class="card-header bg-dark py-3">
                <h5 class="fw-bold text-white mb-0"><i class="bi bi-shield-lock me-2"></i> Ganti Password Admin</h5>
            </div>
            <div class="card-body p-4">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="change_password">

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Password Saat Ini</label>
                        <input type="password" name="old_password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small text-secondary">Password Baru</label>
                        <input type="password" name="new_password" class="form-control" placeholder="Minimal 6 karakter" required>
                    </div>

                    <div class="mb-4">
                        <label class="form-label small text-secondary">Konfirmasi Password Baru</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>

                    <button type="submit" class="btn btn-outline-warning w-100 fw-bold">
                        <i class="bi bi-key me-1"></i> Perbarui Password
                    </button>
                </form>
            </div>
        </div>

        <!-- Database Maintenance -->
        <div class="card shadow-sm border-danger">
            <div class="card-header bg-dark py-3">
                <h6 class="fw-bold text-danger mb-0"><i class="bi bi-trash me-2"></i> Pembersihan Database Manual</h6>
            </div>
            <div class="card-body p-3">
                <p class="small text-secondary mb-3">Hapus log mentah lama secara manual sesuai batas retensi hari saat ini.</p>
                <form method="POST" action="" onsubmit="return confirm('Jalankan pembersihan log lama sekarang?');">
                    <input type="hidden" name="action" value="clean_logs">
                    <button type="submit" class="btn btn-sm btn-outline-danger w-100">
                        <i class="bi bi-eraser me-1"></i> Bersihkan Log Sekarang
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

