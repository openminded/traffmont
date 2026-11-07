<?php
/**
 * MikroTik Script Generator
 * Generates ready-to-paste MikroTik CLI script for Push Agent Mode
 */
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Auth.php';

Auth::requireLogin();

$routerId = (int)($_GET['router_id'] ?? 0);
$ifaceName = trim($_GET['interface'] ?? 'ether1');

$stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ?");
$stmt->execute([$routerId]);
$router = $stmt->fetch();

if (!$router) {
    die("Router not found");
}

// Ensure router has a push token
if (empty($router['push_token'])) {
    $newToken = bin2hex(random_bytes(16));
    $stmtUp = $pdo->prepare("UPDATE routers SET push_token = ? WHERE id = ?");
    $stmtUp->execute([$newToken, $routerId]);
    $router['push_token'] = $newToken;
}

$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443) ? "https://" : "http://";
$host = $_SERVER['HTTP_HOST'] ?? 'yourdomain.com';
$baseUrl = $protocol . $host . rtrim(dirname(dirname($_SERVER['REQUEST_URI'])), '/\\');
$pushUrl = "{$baseUrl}/api/push.php";

header('Content-Type: text/plain; charset=utf-8');
?>
# ====================================================================
# TraffMont - MikroTik Push Agent Script
# Router: <?= htmlspecialchars($router['name']) ?> (ID: <?= $routerId ?>)
# Interface: <?= htmlspecialchars($ifaceName) ?>
# ====================================================================

# 1. Hapus script & scheduler lama jika ada
/system script remove [find name="traffmont_push_<?= $routerId ?>"]
/system scheduler remove [find name="traffmont_sched_<?= $routerId ?>"]

# 2. Buat Script Pengirim Metrik (Setiap 1 Menit)
/system script add name="traffmont_push_<?= $routerId ?>" policy=read,write,test source="
:local ifaceName \"<?= $ifaceName ?>\"
:local routerId \"<?= $routerId ?>\"
:local token \"<?= $router['push_token'] ?>\"
:local url \"<?= $pushUrl ?>\"

:local isRunning [/interface get [find name=\$ifaceName] running]
:local isDisabled [/interface get [find name=\$ifaceName] disabled]
:local rxBytes [/interface get [find name=\$ifaceName] rx-byte]
:local txBytes [/interface get [find name=\$ifaceName] tx-byte]

:local status \"UP\"
:if (\$isDisabled = true) do={ :set status \"DOWN\" }
:if (\$isRunning = false) do={ :set status \"DOWN\" }

:local fullUrl (\$url . \"?token=\" . \$token . \"&router_id=\" . \$routerId . \"&interface=\" . \$ifaceName . \"&status=\" . \$status . \"&rx=\" . \$rxBytes . \"&tx=\" . \$txBytes)

:do {
    /tool fetch url=\$fullUrl mode=http keep-result=no
} on-error={
    :log warning \"TraffMont: Gagal mengirim metrik ke server cPanel\"
}
"

# 3. Jadwalkan Script Berjalan Setiap 1 Menit
/system scheduler add name="traffmont_sched_<?= $routerId ?>" interval=1m on-event="traffmont_push_<?= $routerId ?>" start-time=startup

# Verifikasi:
/system script run traffmont_push_<?= $routerId ?>

