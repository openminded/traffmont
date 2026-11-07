<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/SlaCalculator.php';

Auth::requireLogin();
$currentPage = basename($_SERVER['PHP_SELF']);
$appName = get_setting('app_name', 'TraffMont');
$currentUsername = Auth::currentUser();

// Quick stats for top navbar
$stmtHeaderStats = $pdo->query("
    SELECT 
        COUNT(*) as total_ifaces,
        SUM(CASE WHEN current_status = 'UP' THEN 1 ELSE 0 END) as up_ifaces,
        SUM(CASE WHEN current_status IN ('DOWN', 'TIMEOUT') THEN 1 ELSE 0 END) as down_ifaces
    FROM interfaces 
    WHERE is_active = 1
");
$hStats = $stmtHeaderStats->fetch();
$totalIfaces = (int)($hStats['total_ifaces'] ?? 0);
$upIfaces = (int)($hStats['up_ifaces'] ?? 0);
$downIfaces = (int)($hStats['down_ifaces'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? $appName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark sticky-top py-2">
    <div class="container-fluid px-4">
        <a class="navbar-brand d-flex align-items-center gap-2" href="index.php">
            <i class="bi bi-activity text-info fs-4"></i>
            <span>Traff<span class="text-white">Mont</span></span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3">
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'index.php') ? 'active' : '' ?>" href="index.php">
                        <i class="bi bi-speedometer2 me-1"></i> Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'routers.php' || $currentPage === 'router_detail.php') ? 'active' : '' ?>" href="routers.php">
                        <i class="bi bi-router me-1"></i> MikroTik &amp; Interfaces
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'sla_report.php') ? 'active' : '' ?>" href="sla_report.php">
                        <i class="bi bi-file-earmark-bar-graph me-1"></i> Rekap SLA Bulanan
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'incidents.php') ? 'active' : '' ?>" href="incidents.php">
                        <i class="bi bi-exclamation-diamond me-1"></i> Gangguan / Incidents
                        <?php if ($downIfaces > 0): ?>
                            <span class="badge bg-danger ms-1"><?= $downIfaces ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'logs.php') ? 'active' : '' ?>" href="logs.php">
                        <i class="bi bi-clock-history me-1"></i> Log Detail
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?= ($currentPage === 'settings.php') ? 'active' : '' ?>" href="settings.php">
                        <i class="bi bi-gear me-1"></i> Pengaturan &amp; Cron
                    </a>
                </li>
            </ul>

            <div class="d-flex align-items-center gap-3">
                <a href="TraffMont-Monitor.apk" class="btn btn-sm btn-outline-success" title="Download Android App &amp; Floating Widget (APK)">
                    <i class="bi bi-android2 me-1"></i> Android APK
                </a>

                <a href="status.php" target="_blank" class="btn btn-sm btn-outline-info" title="Buka Halaman Status Publik untuk Klien / Umum">
                    <i class="bi bi-broadcast me-1"></i> Public Status
                </a>

                <div class="d-none d-md-flex align-items-center gap-2 border-end pe-3 border-secondary text-secondary small">
                    <?php if ($downIfaces > 0): ?>
                        <span class="badge bg-danger"><i class="bi bi-x-circle me-1"></i> <?= $downIfaces ?> Down</span>
                    <?php endif; ?>
                    <span class="badge bg-success"><i class="bi bi-check-circle me-1"></i> <?= $upIfaces ?> Up</span>
                    <span>Total: <?= $totalIfaces ?> Ether</span>
                </div>

                <div class="dropdown">
                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle text-light" type="button" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1 text-info"></i> <?= htmlspecialchars($currentUsername) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i> Pengaturan</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i> Keluar</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>
<div class="container-fluid px-4 py-4">

