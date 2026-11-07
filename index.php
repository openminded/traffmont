<?php
$pageTitle = 'Dashboard - TraffMont MikroTik Monitor';
require_once __DIR__ . '/includes/header.php';

// Overall monthly SLA calculation across all active interfaces
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$stmtInterfaces = $pdo->prepare("
    SELECT 
        i.*,
        r.name as router_name,
        r.host as router_host,
        r.api_type,
        r.target_sla_percent as router_sla
    FROM interfaces i
    JOIN routers r ON i.router_id = r.id
    WHERE i.is_active = 1 AND r.is_active = 1
    ORDER BY (i.current_status IN ('DOWN', 'TIMEOUT')) DESC, r.name ASC, i.interface_name ASC
");
$stmtInterfaces->execute();
$monitoredInterfaces = $stmtInterfaces->fetchAll();

// Calculate global month-to-date SLA
$stmtMonthLogs = $pdo->prepare("
    SELECT 
        COUNT(*) as total_checks,
        SUM(CASE WHEN status = 'UP' THEN 1 ELSE 0 END) as up_checks,
        SUM(CASE WHEN status IN ('DOWN', 'TIMEOUT') THEN 1 ELSE 0 END) as down_checks
    FROM monitoring_logs
    WHERE checked_at >= :start_month
");
$stmtMonthLogs->execute([':start_month' => date('Y-m-01 00:00:00')]);
$globalMonth = $stmtMonthLogs->fetch();
$globalTotalChecks = (int)($globalMonth['total_checks'] ?? 0);
$globalUpChecks = (int)($globalMonth['up_checks'] ?? 0);
$globalMonthUptime = ($globalTotalChecks > 0) ? round(($globalUpChecks / $globalTotalChecks) * 100, 2) : 100.00;

// Fetch active open incidents
$stmtActiveInc = $pdo->query("
    SELECT inc.*, i.interface_name, r.name as router_name
    FROM incidents inc
    JOIN interfaces i ON inc.interface_id = i.id
    JOIN routers r ON inc.router_id = r.id
    WHERE inc.status = 'OPEN'
    ORDER BY inc.started_at DESC
");
$activeIncidents = $stmtActiveInc->fetchAll();
?>

<!-- Page Header & Auto-Refresh Toggle -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-0">Dashboard Monitoring</h3>
        <p class="text-secondary small mb-0">Pemantauan realtime status link interface MikroTik, traffic, dan pencapaian SLA.</p>
    </div>
    <div class="d-flex align-items-center gap-3">
        <div class="form-check form-switch text-secondary small">
            <input class="form-check-input" type="checkbox" id="autoRefreshToggle" checked>
            <label class="form-check-label" for="autoRefreshToggle">Auto-Refresh (30s)</label>
        </div>
        <a href="cron.php?token=<?= urlencode(CRON_TOKEN) ?>" target="_blank" class="btn btn-sm btn-outline-info">
            <i class="bi bi-arrow-repeat me-1"></i> Trigger Cron Manual
        </a>
        <a href="routers.php?action=add" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i> Tambah MikroTik
        </a>
    </div>
</div>

<!-- Active Incidents Warning Banner -->
<?php if (!empty($activeIncidents)): ?>
    <div class="alert alert-danger border-danger shadow-sm mb-4">
        <div class="d-flex align-items-center justify-content-between">
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-exclamation-triangle-fill fs-4 text-danger"></i>
                <div>
                    <strong class="text-white">Peringatan: <?= count($activeIncidents) ?> Interface Sedang DOWN / TIMEOUT!</strong>
                    <div class="small text-danger-emphasis">
                        <?php foreach ($activeIncidents as $inc): ?>
                            <span class="me-3">&bull; <?= htmlspecialchars($inc['router_name']) ?> (<?= htmlspecialchars($inc['interface_name']) ?>): Sejak <?= date('H:i:s', strtotime($inc['started_at'])) ?> &mdash; <em><?= htmlspecialchars($inc['root_cause']) ?></em></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <a href="incidents.php" class="btn btn-sm btn-danger">Lihat Detail Gangguan</a>
        </div>
    </div>
<?php endif; ?>

<!-- KPI Overview Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-secondary small text-uppercase fw-semibold">Total Interface</span>
                    <h3 class="fw-bold text-white mt-1 mb-0"><?= count($monitoredInterfaces) ?></h3>
                    <small class="text-secondary">Terdaftar &amp; Aktif</small>
                </div>
                <div class="bg-primary bg-opacity-10 text-primary p-3 rounded-3 fs-3">
                    <i class="bi bi-hdd-network"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-secondary small text-uppercase fw-semibold">Status Normal (UP)</span>
                    <h3 class="fw-bold text-success mt-1 mb-0"><?= $upIfaces ?></h3>
                    <small class="text-secondary">Beroperasi lancar</small>
                </div>
                <div class="bg-success bg-opacity-10 text-success p-3 rounded-3 fs-3">
                    <i class="bi bi-check-circle"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-secondary small text-uppercase fw-semibold">Interface Down / Timeout</span>
                    <h3 class="fw-bold <?= $downIfaces > 0 ? 'text-danger' : 'text-secondary' ?> mt-1 mb-0"><?= $downIfaces ?></h3>
                    <small class="text-secondary"><?= $downIfaces > 0 ? 'Perlu tindakan!' : 'Tidak ada gangguan' ?></small>
                </div>
                <div class="<?= $downIfaces > 0 ? 'bg-danger text-danger' : 'bg-secondary text-secondary' ?> bg-opacity-10 p-3 rounded-3 fs-3">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card p-3">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <span class="text-secondary small text-uppercase fw-semibold">Rata-rata SLA Bulan Ini</span>
                    <h3 class="fw-bold <?= $globalMonthUptime >= 99.0 ? 'text-info' : 'text-warning' ?> mt-1 mb-0"><?= number_format($globalMonthUptime, 2) ?>%</h3>
                    <small class="text-secondary"><?= date('F Y') ?></small>
                </div>
                <div class="bg-info bg-opacity-10 text-info p-3 rounded-3 fs-3">
                    <i class="bi bi-shield-check"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Main Interfaces List -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <h5 class="fw-bold text-white mb-0"><i class="bi bi-ethernet text-info me-2"></i> Status Interface &amp; Traffic Terkini</h5>
        <span class="badge bg-dark border border-secondary text-secondary">Dicek berkala setiap 1 menit</span>
    </div>

    <?php if (empty($monitoredInterfaces)): ?>
        <div class="p-5 text-center text-secondary">
            <i class="bi bi-router display-3 text-secondary mb-3"></i>
            <h5>Belum ada MikroTik atau Interface yang dipantau</h5>
            <p class="small">Tambahkan IP MikroTik dan pilih interface (ethernet/sfp) yang ingin dipantau.</p>
            <a href="routers.php?action=add" class="btn btn-primary mt-2"><i class="bi bi-plus-lg me-1"></i> Tambah MikroTik Pertama</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="text-secondary small text-uppercase bg-dark bg-opacity-50">
                    <tr>
                        <th style="min-width: 200px;">MikroTik &amp; Interface</th>
                        <th>Status</th>
                        <th>Traffic Download (RX)</th>
                        <th>Traffic Upload (TX)</th>
                        <th>Latency</th>
                        <th>Target SLA</th>
                        <th style="min-width: 140px;">Uptime Bulan Ini</th>
                        <th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($monitoredInterfaces as $if): 
                        $status = $if['current_status'];
                        $targetSla = (float)($if['target_sla_percent'] !== null ? $if['target_sla_percent'] : $if['router_sla']);
                        
                        // Fetch monthly SLA for this interface
                        $mStats = SlaCalculator::getMonthlyStats($pdo, $if['id'], $currentYear, $currentMonth, $targetSla);
                        $uptimePct = $mStats['actual_uptime_percent'];
                        $isSlaMet = ($uptimePct >= $targetSla);
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-white">
                                <a href="router_detail.php?interface_id=<?= $if['id'] ?>" class="text-white text-decoration-none hover-info">
                                    <?= htmlspecialchars($if['interface_name']) ?>
                                    <?php if (!empty($if['alias_name'])): ?>
                                        <span class="text-info small fw-normal">(<?= htmlspecialchars($if['alias_name']) ?>)</span>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <div class="small text-secondary">
                                <i class="bi bi-router me-1"></i> <?= htmlspecialchars($if['router_name']) ?> 
                                <span class="badge bg-secondary bg-opacity-25 text-secondary ms-1"><?= htmlspecialchars($if['router_host']) ?></span>
                            </div>
                        </td>
                        <td>
                            <?php if ($status === 'UP'): ?>
                                <span class="badge-up d-inline-flex align-items-center">
                                    <span class="pulse-dot pulse-up"></span> UP / Normal
                                </span>
                            <?php elseif ($status === 'TIMEOUT'): ?>
                                <span class="badge-timeout d-inline-flex align-items-center">
                                    <span class="pulse-dot pulse-timeout"></span> TIMEOUT (Down)
                                </span>
                            <?php elseif ($status === 'DOWN'): ?>
                                <span class="badge-down d-inline-flex align-items-center">
                                    <span class="pulse-dot pulse-down"></span> DOWN
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary">UNKNOWN</span>
                            <?php endif; ?>
                            <div class="small text-secondary mt-1" style="font-size: 0.75rem;">
                                <?= $if['last_checked_at'] ? date('H:i:s', strtotime($if['last_checked_at'])) : '-' ?>
                            </div>
                        </td>
                        <td>
                            <div class="traffic-badge text-success">
                                <i class="bi bi-arrow-down me-1"></i> <?= SlaCalculator::formatBps($if['last_rx_bps']) ?>
                            </div>
                            <div class="small text-muted" style="font-size: 0.75rem;">
                                Total: <?= SlaCalculator::formatBytes($if['last_rx_bytes']) ?>
                            </div>
                        </td>
                        <td>
                            <div class="traffic-badge text-primary">
                                <i class="bi bi-arrow-up me-1"></i> <?= SlaCalculator::formatBps($if['last_tx_bps']) ?>
                            </div>
                            <div class="small text-muted" style="font-size: 0.75rem;">
                                Total: <?= SlaCalculator::formatBytes($if['last_tx_bytes']) ?>
                            </div>
                        </td>
                        <td>
                            <span class="text-white"><?= (int)$if['last_latency_ms'] ?> ms</span>
                        </td>
                        <td>
                            <span class="badge bg-dark border border-secondary text-light"><?= number_format($targetSla, 2) ?>%</span>
                        </td>
                        <td>
                            <div class="d-flex align-items-center justify-content-between small mb-1">
                                <span class="fw-bold <?= $isSlaMet ? 'text-success' : 'text-danger' ?>">
                                    <?= number_format($uptimePct, 2) ?>%
                                </span>
                                <span class="text-secondary" style="font-size: 0.75rem;">
                                    <?= $mStats['downtime_minutes'] ?>m down
                                </span>
                            </div>
                            <div class="progress" style="height: 6px; background-color: #334155;">
                                <div class="progress-bar <?= $isSlaMet ? 'bg-success' : 'bg-danger' ?>" 
                                     role="progressbar" 
                                     style="width: <?= min(100, $uptimePct) ?>%"></div>
                            </div>
                        </td>
                        <td class="text-end">
                            <a href="router_detail.php?interface_id=<?= $if['id'] ?>" class="btn btn-sm btn-outline-info" title="Lihat Grafik &amp; Detail">
                                <i class="bi bi-graph-up me-1"></i> Grafik
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Tips / cPanel Info Box -->
<div class="card p-3 border-secondary">
    <div class="d-flex align-items-center justify-content-between">
        <div class="d-flex align-items-center gap-3">
            <i class="bi bi-info-circle text-info fs-3"></i>
            <div>
                <strong class="text-white">Status Cron Job cPanel</strong>
                <p class="small text-secondary mb-0">Pastikan Cron Job di cPanel sudah disetting aktif setiap 1 menit (<code>* * * * *</code>) agar data traffic dan status interface tercatat otomatis.</p>
            </div>
        </div>
        <a href="settings.php#cron-guide" class="btn btn-sm btn-outline-secondary">Panduan Cron cPanel</a>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

