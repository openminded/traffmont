<?php
/**
 * TraffMont - Public Status Page
 * Accessible publicly by clients, management, and visitors without login.
 * Displays real-time interface health, 30-day uptime bars, SLA compliance, and incident history.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/SlaCalculator.php';

// Public Page Settings
$publicEnabled = get_setting('public_status_enabled', '1');
if ($publicEnabled === '0') {
    http_response_code(403);
    die("<div style='text-align:center; padding:50px; font-family:sans-serif;'><h2>Status Page Dinonaktifkan</h2><p>Halaman status publik saat ini dinonaktifkan oleh administrator.</p></div>");
}

$pageTitle = get_setting('public_status_title', 'Network & Service Status');
$announcement = get_setting('public_announcement', '');
$showTraffic = get_setting('public_show_traffic', '1');

// Fetch active routers & interfaces
$stmtRouters = $pdo->query("
    SELECT r.* 
    FROM routers r
    WHERE r.is_active = 1
    ORDER BY r.name ASC
");
$routers = $stmtRouters->fetchAll();

// Fetch open incidents
$stmtIncidents = $pdo->query("
    SELECT inc.*, i.interface_name, i.alias_name, r.name as router_name
    FROM incidents inc
    JOIN interfaces i ON inc.interface_id = i.id
    JOIN routers r ON inc.router_id = r.id
    WHERE inc.status = 'OPEN'
    ORDER BY inc.started_at DESC
");
$openIncidents = $stmtIncidents->fetchAll();

// Fetch resolved incidents in the last 7 days
$sevenDaysAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
$stmtPastInc = $pdo->prepare("
    SELECT inc.*, i.interface_name, i.alias_name, r.name as router_name
    FROM incidents inc
    JOIN interfaces i ON inc.interface_id = i.id
    JOIN routers r ON inc.router_id = r.id
    WHERE inc.status = 'RESOLVED' AND inc.started_at >= ?
    ORDER BY inc.started_at DESC
    LIMIT 10
");
$stmtPastInc->execute([$sevenDaysAgo]);
$pastIncidents = $stmtPastInc->fetchAll();

// Determine global status
$hasOutage = !empty($openIncidents);
$totalIfacesCount = 0;
$upIfacesCount = 0;

$routerData = [];
$now = time();
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

// 30 days history date list
$daysList = [];
for ($i = 29; $i >= 0; $i--) {
    $daysList[] = date('Y-m-d', strtotime("-{$i} days"));
}

foreach ($routers as $r) {
    $stmtIf = $pdo->prepare("SELECT * FROM interfaces WHERE router_id = ? AND is_active = 1 ORDER BY interface_name ASC");
    $stmtIf->execute([$r['id']]);
    $ifaces = $stmtIf->fetchAll();

    $ifaceData = [];
    foreach ($ifaces as $if) {
        $totalIfacesCount++;
        if ($if['current_status'] === 'UP') {
            $upIfacesCount++;
        }

        $targetSla = (float)($if['target_sla_percent'] !== null ? $if['target_sla_percent'] : $r['target_sla_percent']);
        $monthlyStats = SlaCalculator::getMonthlyStats($pdo, $if['id'], $currentYear, $currentMonth, $targetSla);

        // Fetch daily aggregates for the last 30 days for visual uptime bars
        $stmtBars = $pdo->prepare("
            SELECT date, uptime_percent, total_downtime_minutes
            FROM daily_aggregates
            WHERE interface_id = ? AND date >= ? AND date <= ?
        ");
        $stmtBars->execute([$if['id'], $daysList[0], end($daysList)]);
        $dailyMap = $stmtBars->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

        $bars = [];
        foreach ($daysList as $dayDate) {
            if (isset($dailyMap[$dayDate])) {
                $pct = (float)$dailyMap[$dayDate]['uptime_percent'];
                $dtMins = (int)$dailyMap[$dayDate]['total_downtime_minutes'];
            } else {
                $pct = 100.0;
                $dtMins = 0;
            }

            // Determine bar color type
            if ($pct >= $targetSla) {
                $type = 'up';
            } elseif ($pct >= 95.0) {
                $type = 'degraded';
            } else {
                $type = 'down';
            }

            $bars[] = [
                'date' => $dayDate,
                'uptime' => $pct,
                'downtime_minutes' => $dtMins,
                'type' => $type
            ];
        }

        $ifaceData[] = [
            'info' => $if,
            'target_sla' => $targetSla,
            'stats' => $monthlyStats,
            'bars' => $bars
        ];
    }

    $routerData[] = [
        'router' => $r,
        'interfaces' => $ifaceData
    ];
}

$overallUptimePct = ($totalIfacesCount > 0) ? round(($upIfacesCount / $totalIfacesCount) * 100, 1) : 100.0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> &mdash; Status Page</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body {
            background-color: #0b1120;
            color: #f1f5f9;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
        }
        .public-container {
            max-width: 980px;
            margin: 0 auto;
            padding: 40px 20px;
        }
        .status-header-card {
            border-radius: 14px;
            padding: 24px 28px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
            margin-bottom: 30px;
        }
        .status-good {
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.2), rgba(6, 95, 70, 0.3));
            border: 1px solid #10b981;
        }
        .status-bad {
            background: linear-gradient(135deg, rgba(239, 68, 68, 0.2), rgba(153, 27, 27, 0.3));
            border: 1px solid #ef4444;
        }
        .router-group-card {
            background-color: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            margin-bottom: 24px;
            overflow: hidden;
        }
        .router-group-header {
            background-color: #152033;
            padding: 16px 20px;
            border-bottom: 1px solid #334155;
        }
        .interface-row {
            padding: 16px 20px;
            border-bottom: 1px solid rgba(51, 65, 85, 0.6);
            transition: background-color 0.15s;
        }
        .interface-row:last-child {
            border-bottom: none;
        }
        .interface-row:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }
        /* Sleek 30-Day Uptime Bars */
        .bars-container {
            display: flex;
            gap: 3px;
            height: 34px;
            align-items: center;
            margin-top: 10px;
        }
        .day-bar {
            flex: 1;
            height: 28px;
            border-radius: 3px;
            position: relative;
            cursor: pointer;
            transition: all 0.15s ease;
        }
        .day-bar:hover {
            transform: scaleY(1.2);
            z-index: 5;
        }
        .bar-up { background-color: #10b981; }
        .bar-degraded { background-color: #f59e0b; }
        .bar-down { background-color: #ef4444; }

        .tooltip-custom {
            position: absolute;
            bottom: 35px;
            left: 50%;
            transform: translateX(-50%);
            background: #090d16;
            color: #fff;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            white-space: nowrap;
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.15s;
            border: 1px solid #334155;
            box-shadow: 0 4px 6px rgba(0,0,0,0.5);
        }
        .day-bar:hover .tooltip-custom {
            opacity: 1;
        }
        .badge-sla-met {
            background-color: rgba(16, 185, 129, 0.15);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.3);
        }
        .badge-sla-breached {
            background-color: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
        }
    </style>
</head>
<body>

<div class="public-container">
    <!-- Top Branding & Auto-Refresh Indicator -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
        <div class="d-flex align-items-center gap-2">
            <i class="bi bi-activity text-info fs-3"></i>
            <h4 class="fw-bold mb-0 text-white"><?= htmlspecialchars($pageTitle) ?></h4>
        </div>
        <div class="d-flex align-items-center gap-2 text-secondary small">
            <span class="pulse-dot pulse-up"></span>
            <span>Diperbarui otomatis &bull; <?= date('H:i:s') ?> WIB</span>
        </div>
    </div>

    <!-- Announcement Banner (if any) -->
    <?php if (!empty($announcement)): ?>
        <div class="alert alert-info border-info bg-opacity-10 d-flex align-items-center gap-3 mb-4">
            <i class="bi bi-info-circle-fill fs-4 text-info"></i>
            <div><?= nl2br(htmlspecialchars($announcement)) ?></div>
        </div>
    <?php endif; ?>

    <!-- Master Status Header Banner -->
    <?php if (!$hasOutage): ?>
        <div class="status-header-card status-good">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-check-circle-fill text-success fs-1"></i>
                <div>
                    <h4 class="fw-bold text-white mb-1">Semua Layanan &amp; Interface Beroperasi Normal</h4>
                    <p class="text-secondary mb-0 small">Seluruh interface jaringan dan server MikroTik berjalan lancar tanpa kendala.</p>
                </div>
            </div>
            <div class="text-end d-none d-md-block">
                <div class="badge bg-success bg-opacity-25 text-success border border-success px-3 py-2 fw-bold fs-6">
                    <?= $overallUptimePct ?>% Operational
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="status-header-card status-bad">
            <div class="d-flex align-items-center gap-3">
                <i class="bi bi-exclamation-triangle-fill text-danger fs-1"></i>
                <div>
                    <h4 class="fw-bold text-white mb-1">Sebagian Layanan Mengalami Gangguan</h4>
                    <p class="text-secondary mb-0 small">Terdeteksi <?= count($openIncidents) ?> interface sedang down atau mengalami timeout.</p>
                </div>
            </div>
            <div class="text-end d-none d-md-block">
                <div class="badge bg-danger bg-opacity-25 text-danger border border-danger px-3 py-2 fw-bold fs-6">
                    Gangguan Aktif
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Active Incident Details (If Any) -->
    <?php if (!empty($openIncidents)): ?>
        <div class="card border-danger shadow-sm mb-4">
            <div class="card-header bg-danger bg-opacity-10 text-danger fw-bold py-2">
                <i class="bi bi-exclamation-diamond me-1"></i> Insiden Gangguan Sedang Berlangsung
            </div>
            <div class="card-body p-3">
                <?php foreach ($openIncidents as $inc): ?>
                    <div class="border-bottom border-secondary pb-2 mb-2 last-border-0">
                        <div class="d-flex justify-content-between align-items-center">
                            <strong class="text-white"><?= htmlspecialchars($inc['router_name']) ?> &mdash; <?= htmlspecialchars($inc['interface_name']) ?> <?= $inc['alias_name'] ? "({$inc['alias_name']})" : '' ?></strong>
                            <span class="badge bg-danger">DOWN / TIMEOUT</span>
                        </div>
                        <div class="small text-secondary mt-1">
                            Mulai terputus sejak: <span class="text-white"><?= date('d M Y H:i:s', strtotime($inc['started_at'])) ?></span>
                            (Durasi: <strong class="text-danger"><?= SlaCalculator::formatDuration(round((time() - strtotime($inc['started_at'])) / 60)) ?></strong>)
                        </div>
                        <div class="small text-danger mt-1">
                            <em>Penyebab: <?= htmlspecialchars($inc['root_cause']) ?></em>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- Services & Interfaces Grouped by Router -->
    <h5 class="fw-bold text-white mb-3"><i class="bi bi-router text-info me-2"></i> Status Link Interface Jaringan</h5>

    <?php if (empty($routerData)): ?>
        <div class="card p-5 text-center text-secondary">
            Belum ada interface yang dipublikasikan.
        </div>
    <?php else: ?>
        <?php foreach ($routerData as $item): 
            $router = $item['router'];
            $ifaces = $item['interfaces'];
            if (empty($ifaces)) continue;
        ?>
        <div class="router-group-card">
            <div class="router-group-header d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-hdd-network text-info"></i>
                    <strong class="text-white"><?= htmlspecialchars($router['name']) ?></strong>
                </div>
                <div class="small text-secondary">
                    Target SLA: <span class="text-info fw-bold"><?= number_format($router['target_sla_percent'], 2) ?>%</span>
                </div>
            </div>

            <?php foreach ($ifaces as $ifItem): 
                $info = $ifItem['info'];
                $stats = $ifItem['stats'];
                $bars = $ifItem['bars'];
                $targetSla = $ifItem['target_sla'];
                $isUp = ($info['current_status'] === 'UP');
                $isSlaMet = ($stats['actual_uptime_percent'] >= $targetSla);
            ?>
            <div class="interface-row">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                    <div>
                        <span class="fw-bold text-white fs-6">
                            <?= htmlspecialchars($info['interface_name']) ?>
                            <?php if (!empty($info['alias_name'])): ?>
                                <span class="text-secondary fw-normal fs-6"> &mdash; <?= htmlspecialchars($info['alias_name']) ?></span>
                            <?php endif; ?>
                        </span>
                    </div>

                    <div class="d-flex align-items-center gap-2">
                        <?php if ($isUp): ?>
                            <span class="badge-up small d-inline-flex align-items-center">
                                <span class="pulse-dot pulse-up"></span> Operasional
                            </span>
                        <?php elseif ($info['current_status'] === 'TIMEOUT'): ?>
                            <span class="badge-timeout small d-inline-flex align-items-center">
                                <span class="pulse-dot pulse-timeout"></span> Gangguan (Timeout)
                            </span>
                        <?php else: ?>
                            <span class="badge-down small d-inline-flex align-items-center">
                                <span class="pulse-dot pulse-down"></span> Terputus (Down)
                            </span>
                        <?php endif; ?>

                        <span class="badge <?= $isSlaMet ? 'badge-sla-met' : 'badge-sla-breached' ?> small">
                            Real SLA: <strong><?= number_format($stats['actual_uptime_percent'], 2) ?>%</strong>
                        </span>
                    </div>
                </div>

                <!-- Traffic & Latency Info (if enabled) -->
                <?php if ($showTraffic === '1' && $isUp): ?>
                    <div class="small text-secondary mb-1">
                        <span class="text-success me-3"><i class="bi bi-arrow-down"></i> <?= SlaCalculator::formatBps($info['last_rx_bps']) ?></span>
                        <span class="text-primary me-3"><i class="bi bi-arrow-up"></i> <?= SlaCalculator::formatBps($info['last_tx_bps']) ?></span>
                        <span>Latency: <?= (int)$info['last_latency_ms'] ?> ms</span>
                    </div>
                <?php endif; ?>

                <!-- 30-Day Visual Uptime Bars -->
                <div class="bars-container" title="Riwayat Uptime 30 Hari Terakhir">
                    <?php foreach ($bars as $bar): ?>
                        <div class="day-bar bar-<?= $bar['type'] ?>">
                            <div class="tooltip-custom">
                                <?= date('d M Y', strtotime($bar['date'])) ?>: <?= number_format($bar['uptime'], 2) ?>%
                                <?php if ($bar['downtime_minutes'] > 0): ?>
                                    (<?= $bar['downtime_minutes'] ?>m down)
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <div class="d-flex justify-content-between text-secondary mt-1" style="font-size: 0.72rem;">
                    <span>30 hari lalu</span>
                    <span>Pencapaian SLA Bulan Ini: <strong><?= number_format($stats['actual_uptime_percent'], 3) ?>%</strong> (Downtime: <?= SlaCalculator::formatDuration($stats['downtime_minutes']) ?>)</span>
                    <span>Hari ini</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Past Incidents Transparency Section -->
    <div class="card bg-dark border-secondary mt-5 shadow-sm">
        <div class="card-header border-secondary py-3 d-flex justify-content-between align-items-center">
            <h6 class="fw-bold text-white mb-0"><i class="bi bi-clock-history text-secondary me-2"></i> Riwayat Insiden Terakhir (7 Hari)</h6>
            <span class="text-secondary small">Transparansi Layanan</span>
        </div>
        <div class="card-body p-3">
            <?php if (empty($pastIncidents)): ?>
                <div class="text-center py-3 text-secondary small">
                    <i class="bi bi-shield-check text-success fs-4 d-block mb-1"></i>
                    Tidak ada insiden gangguan dalam 7 hari terakhir.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 small">
                        <thead class="text-secondary text-uppercase" style="font-size: 0.75rem;">
                            <tr>
                                <th>Layanan / Interface</th>
                                <th>Mulai</th>
                                <th>Selesai</th>
                                <th>Durasi</th>
                                <th>Penyebab</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pastIncidents as $pInc): ?>
                                <tr>
                                    <td class="fw-bold text-white"><?= htmlspecialchars($pInc['router_name']) ?> &bull; <?= htmlspecialchars($pInc['interface_name']) ?></td>
                                    <td class="text-secondary"><?= date('d/m H:i', strtotime($pInc['started_at'])) ?></td>
                                    <td class="text-secondary"><?= $pInc['ended_at'] ? date('d/m H:i', strtotime($pInc['ended_at'])) : '-' ?></td>
                                    <td class="text-danger fw-bold"><?= SlaCalculator::formatDuration(round($pInc['duration_seconds'] / 60)) ?></td>
                                    <td class="text-secondary"><?= htmlspecialchars($pInc['root_cause']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Footer -->
    <div class="text-center text-secondary small mt-5 pt-3 border-top border-secondary">
        <div>Ditenagai oleh <strong>TraffMont</strong> &mdash; Sistem Monitoring Uptime &amp; SLA MikroTik.</div>
        <div class="mt-1" style="font-size: 0.75rem;">Halaman ini otomatis merefresh setiap 60 detik.</div>
    </div>
</div>

<!-- Auto-Refresh Script -->
<script>
    setTimeout(function() {
        window.location.reload();
    }, 60000);
</script>

</body>
</html>

