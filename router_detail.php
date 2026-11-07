<?php
$pageTitle = 'Detail Interface & Grafik - TraffMont';
require_once __DIR__ . '/includes/header.php';

$interfaceId = (int)($_GET['interface_id'] ?? 0);

if ($interfaceId <= 0) {
    echo "<div class='alert alert-danger'>Interface ID tidak valid. <a href='routers.php'>Kembali ke daftar</a></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

// Fetch interface and router
$stmt = $pdo->prepare("
    SELECT i.*, r.name as router_name, r.host as router_host, r.api_type, r.target_sla_percent as router_sla
    FROM interfaces i
    JOIN routers r ON i.router_id = r.id
    WHERE i.id = ?
");
$stmt->execute([$interfaceId]);
$iface = $stmt->fetch();

if (!$iface) {
    echo "<div class='alert alert-danger'>Interface tidak ditemukan. <a href='routers.php'>Kembali</a></div>";
    require_once __DIR__ . '/includes/footer.php';
    exit;
}

$targetSla = (float)($iface['target_sla_percent'] !== null ? $iface['target_sla_percent'] : $iface['router_sla']);
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$monthlyStats = SlaCalculator::getMonthlyStats($pdo, $interfaceId, $currentYear, $currentMonth, $targetSla);

// Fetch recent 20 logs for this interface
$stmtLogs = $pdo->prepare("
    SELECT * FROM monitoring_logs 
    WHERE interface_id = ? 
    ORDER BY checked_at DESC 
    LIMIT 20
");
$stmtLogs->execute([$interfaceId]);
$recentLogs = $stmtLogs->fetchAll();
?>

<!-- Header & Breadcrumb -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1 small">
                <li class="breadcrumb-item"><a href="index.php" class="text-secondary text-decoration-none">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="routers.php" class="text-secondary text-decoration-none">MikroTik</a></li>
                <li class="breadcrumb-item active text-info" aria-current="page"><?= htmlspecialchars($iface['interface_name']) ?></li>
            </ol>
        </nav>
        <h3 class="fw-bold text-white mb-0">
            <?= htmlspecialchars($iface['interface_name']) ?>
            <?php if (!empty($iface['alias_name'])): ?>
                <span class="text-secondary fw-normal fs-5">(<?= htmlspecialchars($iface['alias_name']) ?>)</span>
            <?php endif; ?>
        </h3>
        <p class="text-secondary small mb-0">Router: <strong><?= htmlspecialchars($iface['router_name']) ?></strong> (<?= htmlspecialchars($iface['router_host']) ?>)</p>
    </div>

    <div class="d-flex align-items-center gap-2">
        <a href="sla_report.php?interface_id=<?= $interfaceId ?>" class="btn btn-outline-info">
            <i class="bi bi-file-earmark-bar-graph me-1"></i> Rekap SLA Lengkap
        </a>
        <a href="routers.php" class="btn btn-outline-secondary">
            <i class="bi bi-arrow-left me-1"></i> Kembali
        </a>
    </div>
</div>

<!-- Interface KPI & Realtime Status -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card p-3">
            <span class="text-secondary small text-uppercase">Status Link Saat Ini</span>
            <div class="mt-2">
                <?php if ($iface['current_status'] === 'UP'): ?>
                    <span class="badge-up fs-6"><span class="pulse-dot pulse-up"></span> UP / NORMAL</span>
                <?php elseif ($iface['current_status'] === 'TIMEOUT'): ?>
                    <span class="badge-timeout fs-6"><span class="pulse-dot pulse-timeout"></span> TIMEOUT (Down)</span>
                <?php elseif ($iface['current_status'] === 'DOWN'): ?>
                    <span class="badge-down fs-6"><span class="pulse-dot pulse-down"></span> DOWN</span>
                <?php else: ?>
                    <span class="badge bg-secondary fs-6">UNKNOWN</span>
                <?php endif; ?>
            </div>
            <small class="text-secondary mt-2">Dicek: <?= $iface['last_checked_at'] ? date('H:i:s', strtotime($iface['last_checked_at'])) : '-' ?></small>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card p-3">
            <span class="text-secondary small text-uppercase">Traffic Bandwidth Terakhir</span>
            <div class="mt-2">
                <div class="traffic-badge text-success"><i class="bi bi-arrow-down"></i> <?= SlaCalculator::formatBps($iface['last_rx_bps']) ?></div>
                <div class="traffic-badge text-primary"><i class="bi bi-arrow-up"></i> <?= SlaCalculator::formatBps($iface['last_tx_bps']) ?></div>
            </div>
            <small class="text-secondary mt-1">Latency: <?= (int)$iface['last_latency_ms'] ?> ms</small>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card p-3">
            <span class="text-secondary small text-uppercase">Real Uptime Bulan Ini</span>
            <div class="d-flex align-items-baseline gap-2 mt-1">
                <h3 class="fw-bold <?= ($monthlyStats['actual_uptime_percent'] >= $targetSla) ? 'text-success' : 'text-danger' ?> mb-0">
                    <?= number_format($monthlyStats['actual_uptime_percent'], 2) ?>%
                </h3>
                <span class="badge <?= ($monthlyStats['actual_uptime_percent'] >= $targetSla) ? 'bg-success' : 'bg-danger' ?>">
                    <?= $monthlyStats['sla_status'] ?>
                </span>
            </div>
            <small class="text-secondary">Target SLA: <strong><?= number_format($targetSla, 2) ?>%</strong></small>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card p-3">
            <span class="text-secondary small text-uppercase">Sisa Kuota Downtime (Bulan Ini)</span>
            <div class="mt-1">
                <h3 class="fw-bold <?= ($monthlyStats['remaining_downtime_minutes'] >= 0) ? 'text-info' : 'text-danger' ?> mb-0">
                    <?= $monthlyStats['remaining_downtime_minutes'] ?> m
                </h3>
            </div>
            <small class="text-secondary">Terpakai: <strong><?= $monthlyStats['downtime_minutes'] ?> menit</strong> (<?= $monthlyStats['total_incidents'] ?> insiden)</small>
        </div>
    </div>
</div>

<!-- Chart Group Tabs: Harian, Mingguan, Bulanan -->
<div class="card shadow-sm mb-4">
    <div class="card-header border-secondary p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <ul class="nav nav-pills" id="chartRangeTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active fw-bold" id="tab-harian" data-bs-toggle="pill" data-bs-target="#pane-harian" onclick="loadChart('harian')">
                        <i class="bi bi-calendar-day me-1"></i> Harian (24 Jam)
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link fw-bold" id="tab-mingguan" data-bs-toggle="pill" data-bs-target="#pane-mingguan" onclick="loadChart('mingguan')">
                        <i class="bi bi-calendar-week me-1"></i> Mingguan (7 Hari)
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link fw-bold" id="tab-bulanan" data-bs-toggle="pill" data-bs-target="#pane-bulanan" onclick="loadChart('bulanan')">
                        <i class="bi bi-calendar-month me-1"></i> Bulanan (SLA Report)
                    </button>
                </li>
            </ul>

            <div id="chartDateFilter" class="d-flex align-items-center gap-2">
                <input type="date" id="inputChartDate" class="form-control form-control-sm" value="<?= date('Y-m-d') ?>" onchange="reloadCurrentTab()">
            </div>
        </div>
    </div>

    <div class="card-body p-4">
        <!-- Tab Panes -->
        <div class="tab-content">
            <!-- Harian -->
            <div class="tab-pane fade show active" id="pane-harian">
                <div class="mb-4">
                    <h6 class="fw-bold text-white mb-2"><i class="bi bi-activity text-info me-1"></i> Grafik Traffic Download (RX) &amp; Upload (TX) (Mbps)</h6>
                    <div style="height: 300px;">
                        <canvas id="chartTrafficDaily"></canvas>
                    </div>
                </div>

                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 class="fw-bold text-white mb-2"><i class="bi bi-speedometer text-warning me-1"></i> Latency Response (ms)</h6>
                        <div style="height: 180px;">
                            <canvas id="chartLatencyDaily"></canvas>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold text-white mb-2"><i class="bi bi-shield-check text-success me-1"></i> Uptime Timeline Per Jam (%)</h6>
                        <div style="height: 180px;">
                            <canvas id="chartUptimeDaily"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mingguan -->
            <div class="tab-pane fade" id="pane-mingguan">
                <div class="row g-4">
                    <div class="col-md-7">
                        <h6 class="fw-bold text-white mb-2"><i class="bi bi-bar-chart-fill text-info me-1"></i> Persentase Uptime 7 Hari Terakhir (%)</h6>
                        <div style="height: 300px;">
                            <canvas id="chartWeeklyUptime"></canvas>
                        </div>
                    </div>
                    <div class="col-md-5">
                        <h6 class="fw-bold text-white mb-2"><i class="bi bi-clock-history text-danger me-1"></i> Total Downtime Harian (Menit)</h6>
                        <div style="height: 300px;">
                            <canvas id="chartWeeklyDowntime"></canvas>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bulanan -->
            <div class="tab-pane fade" id="pane-bulanan">
                <div class="row g-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold text-white mb-0"><i class="bi bi-calendar-check text-success me-1"></i> Grafik Pencapaian SLA Harian Bulan Ini (%)</h6>
                            <span class="text-secondary small">Garis merah putus-putus menunjukkan Target SLA (<?= number_format($targetSla, 2) ?>%)</span>
                        </div>
                        <div style="height: 320px;">
                            <canvas id="chartMonthlySla"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Logs Table -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <h6 class="fw-bold text-white mb-0"><i class="bi bi-clock-history text-info me-2"></i> 20 Riwayat Cek Terakhir (Interval 1 Menit)</h6>
        <a href="logs.php?interface_id=<?= $interfaceId ?>" class="btn btn-sm btn-outline-secondary">Lihat Semua Log</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="text-secondary small text-uppercase bg-dark bg-opacity-25">
                <tr>
                    <th>Waktu Cek</th>
                    <th>Status</th>
                    <th>Download (RX)</th>
                    <th>Upload (TX)</th>
                    <th>Latency</th>
                    <th>Keterangan / Alasan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentLogs)): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-4">Belum ada riwayat pengecekan untuk interface ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentLogs as $log): ?>
                    <tr>
                        <td class="text-white small"><?= date('d/m/Y H:i:s', strtotime($log['checked_at'])) ?></td>
                        <td>
                            <?php if ($log['status'] === 'UP'): ?>
                                <span class="badge-up">UP</span>
                            <?php elseif ($log['status'] === 'TIMEOUT'): ?>
                                <span class="badge-timeout">TIMEOUT (Down)</span>
                            <?php else: ?>
                                <span class="badge-down">DOWN</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-success small fw-bold"><?= SlaCalculator::formatBps($log['rx_bps']) ?></td>
                        <td class="text-primary small fw-bold"><?= SlaCalculator::formatBps($log['tx_bps']) ?></td>
                        <td class="text-secondary small"><?= (int)$log['latency_ms'] ?> ms</td>
                        <td class="text-secondary small"><?= htmlspecialchars($log['error_message'] ?: 'Normal OK') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Chart Script -->
<script>
const INTERFACE_ID = <?= $interfaceId ?>;
let currentTab = 'harian';
let chartTrafficDailyInstance = null;
let chartLatencyDailyInstance = null;
let chartUptimeDailyInstance = null;
let chartWeeklyUptimeInstance = null;
let chartWeeklyDowntimeInstance = null;
let chartMonthlySlaInstance = null;

function loadChart(range) {
    currentTab = range;
    const dateVal = document.getElementById('inputChartDate').value;

    fetch(`api/chart_data.php?interface_id=${INTERFACE_ID}&range=${range}&date=${dateVal}`)
        .then(r => r.json())
        .then(data => {
            if (range === 'harian') {
                renderDailyCharts(data);
            } else if (range === 'mingguan') {
                renderWeeklyCharts(data);
            } else if (range === 'bulanan') {
                renderMonthlyCharts(data);
            }
        });
}

function reloadCurrentTab() {
    loadChart(currentTab);
}

function renderDailyCharts(data) {
    const ctxTraffic = document.getElementById('chartTrafficDaily').getContext('2d');
    if (chartTrafficDailyInstance) chartTrafficDailyInstance.destroy();

    chartTrafficDailyInstance = new Chart(ctxTraffic, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [
                {
                    label: 'Download (RX Mbps)',
                    data: data.rx_mbps,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    fill: true,
                    tension: 0.3
                },
                {
                    label: 'Upload (TX Mbps)',
                    data: data.tx_mbps,
                    borderColor: '#0284c7',
                    backgroundColor: 'rgba(2, 132, 199, 0.1)',
                    fill: true,
                    tension: 0.3
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, grid: { color: '#334155' } },
                x: { grid: { color: '#1e293b' } }
            }
        }
    });

    const ctxLat = document.getElementById('chartLatencyDaily').getContext('2d');
    if (chartLatencyDailyInstance) chartLatencyDailyInstance.destroy();
    chartLatencyDailyInstance = new Chart(ctxLat, {
        type: 'line',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Latency (ms)',
                data: data.latency_ms,
                borderColor: '#f59e0b',
                backgroundColor: 'rgba(245, 158, 11, 0.1)',
                tension: 0.2
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, grid: { color: '#334155' } } } }
    });

    const ctxUptime = document.getElementById('chartUptimeDaily').getContext('2d');
    if (chartUptimeDailyInstance) chartUptimeDailyInstance.destroy();
    chartUptimeDailyInstance = new Chart(ctxUptime, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Uptime (%)',
                data: data.uptime_percent,
                backgroundColor: '#3b82f6',
                borderRadius: 4
            }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { min: 0, max: 100, grid: { color: '#334155' } } } }
    });
}

function renderWeeklyCharts(data) {
    const ctxUp = document.getElementById('chartWeeklyUptime').getContext('2d');
    if (chartWeeklyUptimeInstance) chartWeeklyUptimeInstance.destroy();

    chartWeeklyUptimeInstance = new Chart(ctxUp, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Uptime (%)',
                data: data.uptime_percent,
                backgroundColor: data.uptime_percent.map(val => (val >= data.target_sla) ? '#10b981' : '#ef4444'),
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { min: 80, max: 100, grid: { color: '#334155' } }
            }
        }
    });

    const ctxDown = document.getElementById('chartWeeklyDowntime').getContext('2d');
    if (chartWeeklyDowntimeInstance) chartWeeklyDowntimeInstance.destroy();
    chartWeeklyDowntimeInstance = new Chart(ctxDown, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Downtime (Menit)',
                data: data.downtime_minutes,
                backgroundColor: '#ef4444',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { beginAtZero: true, grid: { color: '#334155' } }
            }
        }
    });
}

function renderMonthlyCharts(data) {
    const ctxSla = document.getElementById('chartMonthlySla').getContext('2d');
    if (chartMonthlySlaInstance) chartMonthlySlaInstance.destroy();

    chartMonthlySlaInstance = new Chart(ctxSla, {
        type: 'line',
        data: {
            labels: data.labels.map(l => 'Tgl ' + l),
            datasets: [
                {
                    label: 'Uptime (%)',
                    data: data.uptime_percent,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.15)',
                    fill: true,
                    tension: 0.1
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: { min: 80, max: 100, grid: { color: '#334155' } },
                x: { grid: { color: '#1e293b' } }
            }
        }
    });
}

// Initial Load
document.addEventListener('DOMContentLoaded', () => {
    loadChart('harian');
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

