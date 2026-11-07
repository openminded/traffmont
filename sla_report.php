<?php
$pageTitle = 'Rekap SLA Bulanan - TraffMont';
require_once __DIR__ . '/includes/header.php';

$selectedYear = (int)($_GET['year'] ?? date('Y'));
$selectedMonth = (int)($_GET['month'] ?? date('n'));
$selectedRouter = (int)($_GET['router_id'] ?? 0);

// Fetch all routers for filter
$stmtAllRouters = $pdo->query("SELECT id, name FROM routers ORDER BY name ASC");
$allRouters = $stmtAllRouters->fetchAll();

// Fetch interfaces to report
$sql = "
    SELECT 
        i.id as interface_id,
        i.interface_name,
        i.alias_name,
        i.target_sla_percent as iface_sla,
        r.id as router_id,
        r.name as router_name,
        r.host as router_host,
        r.target_sla_percent as router_sla
    FROM interfaces i
    JOIN routers r ON i.router_id = r.id
    WHERE i.is_active = 1 AND r.is_active = 1
";
$params = [];

if ($selectedRouter > 0) {
    $sql .= " AND r.id = ?";
    $params[] = $selectedRouter;
}
$sql .= " ORDER BY r.name ASC, i.interface_name ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$interfaces = $stmt->fetchAll();

// CSV Export Handler
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=Rekap_SLA_' . $selectedYear . '_' . sprintf('%02d', $selectedMonth) . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Router', 'Host', 'Interface', 'Alias', 'Target SLA (%)', 'Real Uptime (%)', 'Status SLA', 'Total Cek (Menit)', 'Uptime (Menit)', 'Downtime (Menit)', 'Sisa Budget Downtime (Menit)', 'Jumlah Insiden', 'Gangguan Terlama (Menit)', 'MTTR (Menit)']);

    foreach ($interfaces as $if) {
        $targetSla = (float)($if['iface_sla'] !== null ? $if['iface_sla'] : $if['router_sla']);
        $stats = SlaCalculator::getMonthlyStats($pdo, $if['interface_id'], $selectedYear, $selectedMonth, $targetSla);
        fputcsv($out, [
            $if['router_name'],
            $if['router_host'],
            $if['interface_name'],
            $if['alias_name'] ?: '',
            number_format($targetSla, 2),
            number_format($stats['actual_uptime_percent'], 3),
            $stats['sla_status'],
            $stats['total_checks'],
            $stats['uptime_minutes'],
            $stats['downtime_minutes'],
            $stats['remaining_downtime_minutes'],
            $stats['total_incidents'],
            $stats['longest_outage_minutes'],
            $stats['mttr_minutes']
        ]);
    }
    fclose($out);
    exit;
}

// Calculate summary stats
$totalMonitored = count($interfaces);
$totalSlaMet = 0;
$totalSlaBreached = 0;
$sumUptimePercent = 0;
$totalNetworkDowntime = 0;

$reportData = [];
foreach ($interfaces as $if) {
    $targetSla = (float)($if['iface_sla'] !== null ? $if['iface_sla'] : $if['router_sla']);
    $stats = SlaCalculator::getMonthlyStats($pdo, $if['interface_id'], $selectedYear, $selectedMonth, $targetSla);
    
    if ($stats['sla_status'] === 'MET') {
        $totalSlaMet++;
    } else {
        $totalSlaBreached++;
    }
    $sumUptimePercent += $stats['actual_uptime_percent'];
    $totalNetworkDowntime += $stats['downtime_minutes'];

    $reportData[] = [
        'iface' => $if,
        'target_sla' => $targetSla,
        'stats' => $stats
    ];
}
$avgUptimePercent = ($totalMonitored > 0) ? round($sumUptimePercent / $totalMonitored, 2) : 100.00;

// Month names in Indonesian
$bulanIndo = [
    1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
    5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
    9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-0"><i class="bi bi-file-earmark-bar-graph text-info me-2"></i> Rekap Real Uptime &amp; SLA Bulanan</h3>
        <p class="text-secondary small mb-0">Laporan evaluasi performa uptime nyata dan pencapaian target SLA periode <?= $bulanIndo[$selectedMonth] ?> <?= $selectedYear ?>.</p>
    </div>

    <!-- Export & Print Actions -->
    <div class="d-flex align-items-center gap-2">
        <a href="?year=<?= $selectedYear ?>&month=<?= $selectedMonth ?>&router_id=<?= $selectedRouter ?>&export=csv" class="btn btn-outline-success">
            <i class="bi bi-file-earmark-spreadsheet me-1"></i> Export Excel / CSV
        </a>
        <button onclick="window.print()" class="btn btn-outline-light">
            <i class="bi bi-printer me-1"></i> Cetak Laporan
        </button>
    </div>
</div>

<!-- Filters Bar -->
<div class="card p-3 mb-4 shadow-sm border-secondary">
    <form method="GET" action="" class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label small text-secondary">Bulan</label>
            <select name="month" class="form-select form-select-sm">
                <?php for ($m = 1; $m <= 12; $m++): ?>
                    <option value="<?= $m ?>" <?= ($m === $selectedMonth) ? 'selected' : '' ?>>
                        <?= $bulanIndo[$m] ?>
                    </option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small text-secondary">Tahun</label>
            <select name="year" class="form-select form-select-sm">
                <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                    <option value="<?= $y ?>" <?= ($y === $selectedYear) ? 'selected' : '' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small text-secondary">Filter MikroTik</label>
            <select name="router_id" class="form-select form-select-sm">
                <option value="0">-- Semua MikroTik Server --</option>
                <?php foreach ($allRouters as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= ($r['id'] === $selectedRouter) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-primary w-100">
                <i class="bi bi-filter me-1"></i> Tampilkan
            </button>
        </div>
    </form>
</div>

<!-- Executive Summary Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3">
            <span class="text-secondary small text-uppercase">Total Link Dipantau</span>
            <h3 class="fw-bold text-white mt-1 mb-0"><?= $totalMonitored ?></h3>
            <small class="text-secondary">Interface aktif</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3">
            <span class="text-secondary small text-uppercase">Target SLA Tercapai</span>
            <h3 class="fw-bold text-success mt-1 mb-0"><?= $totalSlaMet ?> <small class="fs-6 text-secondary">/ <?= $totalMonitored ?></small></h3>
            <small class="text-success"><?= $totalMonitored > 0 ? round(($totalSlaMet / $totalMonitored) * 100, 1) : 0 ?>% Patuh SLA</small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3">
            <span class="text-secondary small text-uppercase">Target SLA Melanggar (Breached)</span>
            <h3 class="fw-bold <?= ($totalSlaBreached > 0) ? 'text-danger' : 'text-secondary' ?> mt-1 mb-0"><?= $totalSlaBreached ?></h3>
            <small class="text-secondary"><?= ($totalSlaBreached > 0) ? 'Downtime melebihi batas' : 'Nol pelanggaran' ?></small>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card p-3">
            <span class="text-secondary small text-uppercase">Rata-rata Uptime Nyata</span>
            <h3 class="fw-bold text-info mt-1 mb-0"><?= number_format($avgUptimePercent, 2) ?>%</h3>
            <small class="text-secondary">Total downtime: <?= SlaCalculator::formatDuration($totalNetworkDowntime) ?></small>
        </div>
    </div>
</div>

<!-- SLA Table Report -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <h5 class="fw-bold text-white mb-0">
            Tabel Evaluasi SLA: <?= $bulanIndo[$selectedMonth] ?> <?= $selectedYear ?>
        </h5>
        <span class="badge bg-dark border border-secondary text-secondary">Interval Cek: 1 Menit</span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="text-secondary small text-uppercase bg-dark bg-opacity-50">
                <tr>
                    <th>MikroTik &amp; Interface</th>
                    <th class="text-center">Target SLA</th>
                    <th class="text-center">Real Uptime Dicapai</th>
                    <th class="text-center">Status SLA</th>
                    <th class="text-center">Downtime Riil</th>
                    <th class="text-center">Batas Kuota Downtime</th>
                    <th class="text-center">Sisa Budget</th>
                    <th class="text-center">Jumlah Insiden</th>
                    <th class="text-center">MTTR</th>
                    <th class="text-end">Aksi</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reportData)): ?>
                    <tr><td colspan="10" class="text-center text-secondary py-5">Tidak ada interface yang cocok dengan kriteria filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($reportData as $row): 
                        $if = $row['iface'];
                        $stats = $row['stats'];
                        $targetSla = $row['target_sla'];
                        $isMet = ($stats['sla_status'] === 'MET');
                    ?>
                    <tr>
                        <td>
                            <div class="fw-bold text-white">
                                <a href="router_detail.php?interface_id=<?= $if['interface_id'] ?>" class="text-white text-decoration-none">
                                    <?= htmlspecialchars($if['interface_name']) ?>
                                    <?php if (!empty($if['alias_name'])): ?>
                                        <span class="text-info small fw-normal">(<?= htmlspecialchars($if['alias_name']) ?>)</span>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <div class="small text-secondary">
                                <?= htmlspecialchars($if['router_name']) ?> &bull; <code><?= htmlspecialchars($if['router_host']) ?></code>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-dark border border-secondary text-light fw-bold"><?= number_format($targetSla, 2) ?>%</span>
                        </td>
                        <td class="text-center">
                            <strong class="fs-6 <?= $isMet ? 'text-success' : 'text-danger' ?>">
                                <?= number_format($stats['actual_uptime_percent'], 3) ?>%
                            </strong>
                        </td>
                        <td class="text-center">
                            <?php if ($isMet): ?>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success px-2 py-1">
                                    <i class="bi bi-check-circle me-1"></i> SLA TERCAPAI
                                </span>
                            <?php else: ?>
                                <span class="badge bg-danger bg-opacity-25 text-danger border border-danger px-2 py-1">
                                    <i class="bi bi-x-circle me-1"></i> SLA MELANGGAR
                                </span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="<?= ($stats['downtime_minutes'] > 0) ? 'text-danger fw-bold' : 'text-secondary' ?>">
                                <?= SlaCalculator::formatDuration($stats['downtime_minutes']) ?>
                            </span>
                            <div class="small text-secondary" style="font-size: 0.75rem;">(<?= $stats['downtime_minutes'] ?> menit)</div>
                        </td>
                        <td class="text-center text-secondary small">
                            <?= SlaCalculator::formatDuration($stats['allowed_downtime_minutes']) ?>
                        </td>
                        <td class="text-center">
                            <?php if ($stats['remaining_downtime_minutes'] >= 0): ?>
                                <span class="text-success small fw-bold">+<?= SlaCalculator::formatDuration($stats['remaining_downtime_minutes']) ?></span>
                            <?php else: ?>
                                <span class="text-danger small fw-bold">-<?= SlaCalculator::formatDuration(abs($stats['remaining_downtime_minutes'])) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-secondary"><?= $stats['total_incidents'] ?> kali</span>
                            <?php if ($stats['longest_outage_minutes'] > 0): ?>
                                <div class="small text-secondary" style="font-size: 0.75rem;">Maks: <?= $stats['longest_outage_minutes'] ?>m</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-secondary small">
                            <?= $stats['mttr_minutes'] > 0 ? $stats['mttr_minutes'] . 'm' : '-' ?>
                        </td>
                        <td class="text-end">
                            <a href="router_detail.php?interface_id=<?= $if['interface_id'] ?>" class="btn btn-sm btn-outline-info" title="Lihat Grafik">
                                <i class="bi bi-graph-up"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- SLA Calculation Formula Reference Box -->
<div class="card p-3 border-secondary bg-dark bg-opacity-50">
    <h6 class="fw-bold text-secondary mb-2"><i class="bi bi-calculator me-1"></i> Rumus Perhitungan Real Uptime &amp; Budget Downtime</h6>
    <div class="row g-3 small text-secondary">
        <div class="col-md-6">
            <strong>1. Real Uptime (%):</strong><br>
            <code>Uptime % = (Total Menit Status UP / Total Menit Periode Pengecekan) &times; 100%</code>
        </div>
        <div class="col-md-6">
            <strong>2. Batas Toleransi Downtime Bulanan:</strong><br>
            <code>Batas Downtime = Total Menit Sebulan (misal 30 hari = 43.200 m) &times; (100% - Target SLA)</code><br>
            <em>Contoh SLA 99,5%: Maksimal downtime yang ditoleransi = 216 menit (3,6 jam).</em>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

