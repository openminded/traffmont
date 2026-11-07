<?php
$pageTitle = 'Riwayat Gangguan & Insiden - TraffMont';
require_once __DIR__ . '/includes/header.php';

$selectedRouter = (int)($_GET['router_id'] ?? 0);
$statusFilter = $_GET['status'] ?? 'ALL';

$sql = "
    SELECT 
        inc.*,
        i.interface_name,
        i.alias_name,
        r.name as router_name,
        r.host as router_host
    FROM incidents inc
    JOIN interfaces i ON inc.interface_id = i.id
    JOIN routers r ON inc.router_id = r.id
    WHERE 1=1
";
$params = [];

if ($selectedRouter > 0) {
    $sql .= " AND inc.router_id = ?";
    $params[] = $selectedRouter;
}

if ($statusFilter === 'OPEN' || $statusFilter === 'RESOLVED') {
    $sql .= " AND inc.status = ?";
    $params[] = $statusFilter;
}

$sql .= " ORDER BY inc.started_at DESC LIMIT 100";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$incidents = $stmt->fetchAll();

// Router list for filter
$stmtRouters = $pdo->query("SELECT id, name FROM routers ORDER BY name ASC");
$allRouters = $stmtRouters->fetchAll();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-0"><i class="bi bi-exclamation-diamond text-danger me-2"></i> Log Gangguan &amp; Insiden (Outage)</h3>
        <p class="text-secondary small mb-0">Daftar kejadian interface down atau koneksi timeout, durasi gangguan, dan waktu pemulihan.</p>
    </div>
</div>

<!-- Filter Bar -->
<div class="card p-3 mb-4 shadow-sm border-secondary">
    <form method="GET" action="" class="row g-3 align-items-end">
        <div class="col-md-5">
            <label class="form-label small text-secondary">Filter Router</label>
            <select name="router_id" class="form-select form-select-sm">
                <option value="0">-- Semua Router --</option>
                <?php foreach ($allRouters as $r): ?>
                    <option value="<?= $r['id'] ?>" <?= ($r['id'] === $selectedRouter) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($r['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label small text-secondary">Status Insiden</label>
            <select name="status" class="form-select form-select-sm">
                <option value="ALL" <?= ($statusFilter === 'ALL') ? 'selected' : '' ?>>Semua Status</option>
                <option value="OPEN" <?= ($statusFilter === 'OPEN') ? 'selected' : '' ?>>OPEN (Sedang Berlangsung)</option>
                <option value="RESOLVED" <?= ($statusFilter === 'RESOLVED') ? 'selected' : '' ?>>RESOLVED (Telah Pulih)</option>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-sm btn-primary w-100">
                <i class="bi bi-filter me-1"></i> Terapkan Filter
            </button>
        </div>
    </form>
</div>

<!-- Incidents Table -->
<div class="card shadow-sm mb-4">
    <div class="card-header py-3">
        <h5 class="fw-bold text-white mb-0">Daftar 100 Kejadian Terakhir</h5>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="text-secondary small text-uppercase bg-dark bg-opacity-50">
                <tr>
                    <th>Status</th>
                    <th>MikroTik &amp; Interface</th>
                    <th>Waktu Mulai Down</th>
                    <th>Waktu Pulih (Up)</th>
                    <th>Durasi Downtime</th>
                    <th>Penyebab / Pesan Error</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($incidents)): ?>
                    <tr><td colspan="6" class="text-center text-secondary py-5">Tidak ada riwayat gangguan tercatat. Semua normal!</td></tr>
                <?php else: ?>
                    <?php foreach ($incidents as $inc): 
                        $isOpen = ($inc['status'] === 'OPEN');
                        $durationMin = $isOpen 
                            ? round((time() - strtotime($inc['started_at'])) / 60)
                            : round($inc['duration_seconds'] / 60);
                    ?>
                    <tr>
                        <td>
                            <?php if ($isOpen): ?>
                                <span class="badge bg-danger pulse-dot-wrapper d-inline-flex align-items-center">
                                    <span class="pulse-dot pulse-down"></span> OPEN (DOWN)
                                </span>
                            <?php else: ?>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success">
                                    <i class="bi bi-check2 me-1"></i> RESOLVED
                                </span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="fw-bold text-white">
                                <a href="router_detail.php?interface_id=<?= $inc['interface_id'] ?>" class="text-white text-decoration-none">
                                    <?= htmlspecialchars($inc['interface_name']) ?>
                                    <?php if (!empty($inc['alias_name'])): ?>
                                        <span class="text-info small fw-normal">(<?= htmlspecialchars($inc['alias_name']) ?>)</span>
                                    <?php endif; ?>
                                </a>
                            </div>
                            <div class="small text-secondary"><?= htmlspecialchars($inc['router_name']) ?> (<?= htmlspecialchars($inc['router_host']) ?>)</div>
                        </td>
                        <td class="text-white small">
                            <?= date('d/m/Y H:i:s', strtotime($inc['started_at'])) ?>
                        </td>
                        <td class="text-secondary small">
                            <?= $inc['ended_at'] ? date('d/m/Y H:i:s', strtotime($inc['ended_at'])) : '<span class="text-danger fw-bold">Masih Berlangsung</span>' ?>
                        </td>
                        <td>
                            <strong class="text-danger">
                                <?= SlaCalculator::formatDuration($durationMin) ?>
                            </strong>
                            <div class="small text-secondary" style="font-size: 0.75rem;">(<?= $durationMin ?> menit)</div>
                        </td>
                        <td>
                            <span class="small text-secondary"><?= htmlspecialchars($inc['root_cause']) ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>

