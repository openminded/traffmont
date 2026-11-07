<?php
$pageTitle = 'Log Detail Monitoring - TraffMont';
require_once __DIR__ . '/includes/header.php';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset = ($page - 1) * $perPage;

$filterRouter = (int)($_GET['router_id'] ?? 0);
$filterIface = (int)($_GET['interface_id'] ?? 0);
$filterStatus = $_GET['status'] ?? '';
$filterDate = $_GET['date'] ?? '';

// Build Query
$where = ["1=1"];
$params = [];

if ($filterRouter > 0) {
    $where[] = "l.router_id = ?";
    $params[] = $filterRouter;
}

if ($filterIface > 0) {
    $where[] = "l.interface_id = ?";
    $params[] = $filterIface;
}

if (!empty($filterStatus)) {
    $where[] = "l.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterDate)) {
    $where[] = "DATE(l.checked_at) = ?";
    $params[] = $filterDate;
}

$whereClause = implode(" AND ", $where);

// Count total
$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM monitoring_logs l WHERE {$whereClause}");
$stmtCount->execute($params);
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = ceil($totalRows / $perPage);

// Fetch logs
$sql = "
    SELECT 
        l.*,
        r.name as router_name,
        r.host as router_host,
        i.interface_name,
        i.alias_name
    FROM monitoring_logs l
    JOIN routers r ON l.router_id = r.id
    JOIN interfaces i ON l.interface_id = i.id
    WHERE {$whereClause}
    ORDER BY l.checked_at DESC
    LIMIT {$perPage} OFFSET {$offset}
";
$stmtLogs = $pdo->prepare($sql);
$stmtLogs->execute($params);
$logs = $stmtLogs->fetchAll();

// Router & Interface options for dropdown
$routers = $pdo->query("SELECT id, name FROM routers ORDER BY name ASC")->fetchAll();
$interfaces = $pdo->query("SELECT id, interface_name, alias_name, router_id FROM interfaces ORDER BY interface_name ASC")->fetchAll();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-0"><i class="bi bi-clock-history text-info me-2"></i> Log Detail Pengecekan (1 Menit)</h3>
        <p class="text-secondary small mb-0">Catatan detail setiap pengecekan status interface, response time, dan traffic bandwidth.</p>
    </div>
</div>

<!-- Filter Bar -->
<div class="card p-3 mb-4 shadow-sm border-secondary">
    <form method="GET" action="" class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label small text-secondary">Router</label>
            <select name="router_id" class="form-select form-select-sm">
                <option value="0">-- Semua Router --</option>
                <?php foreach ($routers as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= ($r['id'] === $filterRouter) ? 'selected' : '' ?>><?= htmlspecialchars($r['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-3">
            <label class="form-label small text-secondary">Interface</label>
            <select name="interface_id" class="form-select form-select-sm">
                <option value="0">-- Semua Interface --</option>
                <?php foreach ($interfaces as $if): ?>
                    <option value="<?= $if['id'] ?>" <?= ($if['id'] === $filterIface) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($if['interface_name']) ?> <?= $if['alias_name'] ? "({$if['alias_name']})" : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label small text-secondary">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Semua Status</option>
                <option value="UP" <?= ($filterStatus === 'UP') ? 'selected' : '' ?>>UP</option>
                <option value="TIMEOUT" <?= ($filterStatus === 'TIMEOUT') ? 'selected' : '' ?>>TIMEOUT</option>
                <option value="DOWN" <?= ($filterStatus === 'DOWN') ? 'selected' : '' ?>>DOWN</option>
            </select>
        </div>

        <div class="col-md-2">
            <label class="form-label small text-secondary">Tanggal</label>
            <input type="date" name="date" class="form-control form-control-sm" value="<?= htmlspecialchars($filterDate) ?>">
        </div>

        <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-primary w-100">
                <i class="bi bi-filter me-1"></i> Filter
            </button>
        </div>
    </form>
</div>

<!-- Logs Table -->
<div class="card shadow-sm mb-4">
    <div class="card-header d-flex justify-content-between align-items-center py-3">
        <h5 class="fw-bold text-white mb-0">Total: <?= number_format($totalRows) ?> Data Log</h5>
        <span class="small text-secondary">Halaman <?= $page ?> dari <?= max(1, $totalPages) ?></span>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="text-secondary small text-uppercase bg-dark bg-opacity-50">
                <tr>
                    <th>Waktu Cek</th>
                    <th>Router &amp; Interface</th>
                    <th>Status</th>
                    <th>Download (RX)</th>
                    <th>Upload (TX)</th>
                    <th>Latency</th>
                    <th>Pesan Error / Keterangan</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="7" class="text-center text-secondary py-5">Tidak ada log yang sesuai filter.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                    <tr>
                        <td class="text-white small">
                            <?= date('d/m/Y H:i:s', strtotime($l['checked_at'])) ?>
                        </td>
                        <td>
                            <div class="fw-bold text-white">
                                <?= htmlspecialchars($l['interface_name']) ?>
                                <?php if (!empty($l['alias_name'])): ?>
                                    <span class="text-info small fw-normal">(<?= htmlspecialchars($l['alias_name']) ?>)</span>
                                <?php endif; ?>
                            </div>
                            <div class="small text-secondary"><?= htmlspecialchars($l['router_name']) ?></div>
                        </td>
                        <td>
                            <?php if ($l['status'] === 'UP'): ?>
                                <span class="badge-up">UP</span>
                            <?php elseif ($l['status'] === 'TIMEOUT'): ?>
                                <span class="badge-timeout">TIMEOUT (Down)</span>
                            <?php else: ?>
                                <span class="badge-down">DOWN</span>
                            <?php endif; ?>
                        </td>
                        <td class="traffic-badge text-success small">
                            <?= SlaCalculator::formatBps($l['rx_bps']) ?>
                        </td>
                        <td class="traffic-badge text-primary small">
                            <?= SlaCalculator::formatBps($l['tx_bps']) ?>
                        </td>
                        <td class="text-secondary small"><?= (int)$l['latency_ms'] ?> ms</td>
                        <td class="text-secondary small">
                            <?= htmlspecialchars($l['error_message'] ?: 'OK') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="card-footer py-3 d-flex justify-content-center">
            <nav>
                <ul class="pagination pagination-sm mb-0">
                    <?php if ($page > 1): ?>
                        <li class="page-item"><a class="page-link bg-dark text-white border-secondary" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">&laquo; Prev</a></li>
                    <?php endif; ?>
                    <li class="page-item active"><span class="page-link bg-primary border-primary"><?= $page ?></span></li>
                    <?php if ($page < $totalPages): ?>
                        <li class="page-item"><a class="page-link bg-dark text-white border-secondary" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next &raquo;</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

