<?php
$pageTitle = 'MikroTik & Interface Management - TraffMont';
require_once __DIR__ . '/includes/header.php';

$message = '';
$messageType = 'success';

// Handle Actions: Add, Edit, Delete Router, Sync Selected Interfaces
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_router') {
        $id = (int)($_POST['router_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $host = trim($_POST['host'] ?? '');
        $apiType = $_POST['api_type'] ?? 'routeros_api';
        $apiPort = (int)($_POST['api_port'] ?? 8728);
        $username = trim($_POST['username'] ?? 'admin');
        $password = $_POST['password'] ?? '';
        $useSsl = !empty($_POST['use_ssl']) ? 1 : 0;
        $timeoutSec = (int)($_POST['timeout_sec'] ?? 4);
        $targetSla = (float)($_POST['target_sla_percent'] ?? 99.50);
        $selectedIfaces = $_POST['selected_interfaces'] ?? [];

        if (empty($name) || empty($host)) {
            $message = 'Nama router dan Host / IP wajib diisi.';
            $messageType = 'danger';
        } else {
            if ($id > 0) {
                // Update
                if (!empty($password)) {
                    $stmt = $pdo->prepare("
                        UPDATE routers 
                        SET name = ?, host = ?, api_type = ?, api_port = ?, username = ?, password = ?, 
                            use_ssl = ?, timeout_sec = ?, target_sla_percent = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $host, $apiType, $apiPort, $username, $password, $useSsl, $timeoutSec, $targetSla, $id]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE routers 
                        SET name = ?, host = ?, api_type = ?, api_port = ?, username = ?, 
                            use_ssl = ?, timeout_sec = ?, target_sla_percent = ? 
                        WHERE id = ?
                    ");
                    $stmt->execute([$name, $host, $apiType, $apiPort, $username, $useSsl, $timeoutSec, $targetSla, $id]);
                }
                $routerId = $id;
                $message = 'Router berhasil diperbarui.';
            } else {
                // Insert
                $pushToken = bin2hex(random_bytes(16));
                $stmt = $pdo->prepare("
                    INSERT INTO routers 
                    (name, host, api_type, api_port, username, password, use_ssl, timeout_sec, target_sla_percent, push_token)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $host, $apiType, $apiPort, $username, $password, $useSsl, $timeoutSec, $targetSla, $pushToken]);
                $routerId = (int)$pdo->lastInsertId();
                $message = 'MikroTik baru berhasil ditambahkan!';
            }

            // Insert or activate selected interfaces
            if (!empty($selectedIfaces) && is_array($selectedIfaces)) {
                // Deactivate interfaces not in selected list if any were chosen
                $placeholders = implode(',', array_fill(0, count($selectedIfaces), '?'));
                $stmtDeact = $pdo->prepare("UPDATE interfaces SET is_active = 0 WHERE router_id = ? AND interface_name NOT IN ($placeholders)");
                $stmtDeact->execute(array_merge([$routerId], $selectedIfaces));

                foreach ($selectedIfaces as $ifName) {
                    $ifName = trim($ifName);
                    if (!empty($ifName)) {
                        $stmtFind = $pdo->prepare("SELECT id FROM interfaces WHERE router_id = ? AND interface_name = ?");
                        $stmtFind->execute([$routerId, $ifName]);
                        $existingId = $stmtFind->fetchColumn();

                        if ($existingId) {
                            $stmtUp = $pdo->prepare("UPDATE interfaces SET is_active = 1 WHERE id = ?");
                            $stmtUp->execute([$existingId]);
                        } else {
                            $stmtIns = $pdo->prepare("INSERT INTO interfaces (router_id, interface_name, is_active) VALUES (?, ?, 1)");
                            $stmtIns->execute([$routerId, $ifName]);
                        }
                    }
                }
            }
        }
    } elseif ($action === 'delete_router') {
        $id = (int)($_POST['router_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM routers WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Router dan seluruh interface terkait berhasil dihapus.';
        }
    } elseif ($action === 'delete_all_interfaces') {
        $routerId = (int)($_POST['router_id'] ?? 0);
        if ($routerId > 0) {
            $stmt = $pdo->prepare("DELETE FROM interfaces WHERE router_id = ?");
            $stmt->execute([$routerId]);
            $message = 'Seluruh interface pada router ini berhasil dibersihkan / dihapus.';
        }
    } elseif ($action === 'bulk_delete_interfaces') {
        $ids = $_POST['bulk_ids'] ?? [];
        if (!empty($ids) && is_array($ids)) {
            $cleanIds = array_filter(array_map('intval', $ids), function($v) { return $v > 0; });
            if (!empty($cleanIds)) {
                $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                $stmt = $pdo->prepare("DELETE FROM interfaces WHERE id IN ($placeholders)");
                $stmt->execute($cleanIds);
                $message = count($cleanIds) . ' interface berhasil dihapus secara massal.';
            }
        }
    } elseif ($action === 'bulk_deactivate_interfaces') {
        $ids = $_POST['bulk_ids'] ?? [];
        if (!empty($ids) && is_array($ids)) {
            $cleanIds = array_filter(array_map('intval', $ids), function($v) { return $v > 0; });
            if (!empty($cleanIds)) {
                $placeholders = implode(',', array_fill(0, count($cleanIds), '?'));
                $stmt = $pdo->prepare("UPDATE interfaces SET is_active = 0 WHERE id IN ($placeholders)");
                $stmt->execute($cleanIds);
                $message = count($cleanIds) . ' interface dinonaktifkan dari monitoring.';
            }
        }
    } elseif ($action === 'save_selected_interfaces') {
        // Save interfaces selected directly from MikroTik
        $routerId = (int)($_POST['router_id'] ?? 0);
        $selectedList = $_POST['monitored_interfaces'] ?? []; // array of interface names
        $aliases = $_POST['interface_alias'] ?? [];           // [ifName => alias]
        $slaTargets = $_POST['interface_sla'] ?? [];          // [ifName => sla]
        $deleteUnselected = !empty($_POST['delete_unselected']);

        if ($routerId > 0) {
            $countSaved = 0;

            // Handle interfaces not in selected list: delete or deactivate
            if (!empty($selectedList)) {
                $placeholders = implode(',', array_fill(0, count($selectedList), '?'));
                if ($deleteUnselected) {
                    $stmtClean = $pdo->prepare("DELETE FROM interfaces WHERE router_id = ? AND interface_name NOT IN ($placeholders)");
                } else {
                    $stmtClean = $pdo->prepare("UPDATE interfaces SET is_active = 0 WHERE router_id = ? AND interface_name NOT IN ($placeholders)");
                }
                $stmtClean->execute(array_merge([$routerId], $selectedList));
            } else {
                if ($deleteUnselected) {
                    $pdo->prepare("DELETE FROM interfaces WHERE router_id = ?")->execute([$routerId]);
                } else {
                    $pdo->prepare("UPDATE interfaces SET is_active = 0 WHERE router_id = ?")->execute([$routerId]);
                }
            }

            // Save or activate each checked interface
            foreach ($selectedList as $ifName) {
                $ifName = trim($ifName);
                if (empty($ifName)) continue;

                $alias = trim($aliases[$ifName] ?? '');
                $slaVal = !empty($slaTargets[$ifName]) ? (float)$slaTargets[$ifName] : null;

                $stmtFind = $pdo->prepare("SELECT id FROM interfaces WHERE router_id = ? AND interface_name = ?");
                $stmtFind->execute([$routerId, $ifName]);
                $existingId = $stmtFind->fetchColumn();

                if ($existingId) {
                    $stmtUp = $pdo->prepare("UPDATE interfaces SET is_active = 1, alias_name = ?, target_sla_percent = ? WHERE id = ?");
                    $stmtUp->execute([$alias ?: null, $slaVal, $existingId]);
                } else {
                    $stmtIns = $pdo->prepare("INSERT INTO interfaces (router_id, interface_name, alias_name, target_sla_percent, is_active) VALUES (?, ?, ?, ?, 1)");
                    $stmtIns->execute([$routerId, $ifName, $alias ?: null, $slaVal]);
                }
                $countSaved++;
            }

            $message = "Berhasil memperbarui monitoring. Sebanyak {$countSaved} interface aktif dipantau. Interface lainnya yang tidak dipilih telah dinonaktifkan.";
        }
    } elseif ($action === 'delete_interface') {
        $id = (int)($_POST['interface_id'] ?? 0);
        if ($id > 0) {
            $stmt = $pdo->prepare("DELETE FROM interfaces WHERE id = ?");
            $stmt->execute([$id]);
            $message = 'Interface berhasil dihapus dari monitoring.';
        }
    }
}

// Fetch all routers with active interface count
$stmtRouters = $pdo->query("
    SELECT r.*, 
           COUNT(CASE WHEN i.is_active = 1 THEN i.id ELSE NULL END) as total_interfaces
    FROM routers r
    LEFT JOIN interfaces i ON r.id = i.router_id
    GROUP BY r.id
    ORDER BY r.name ASC
");
$routers = $stmtRouters->fetchAll();
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <div>
        <h3 class="fw-bold text-white mb-0">Manajemen MikroTik &amp; Interface</h3>
        <p class="text-secondary small mb-0">Daftarkan IP MikroTik, lalu pilih interface langsung yang dibaca dari MikroTik untuk dipantau.</p>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRouterModal" onclick="resetAddRouterModal()">
            <i class="bi bi-plus-lg me-1"></i> Tambah MikroTik
        </button>
    </div>
</div>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
        <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Routers Accordion / Card List -->
<div class="row g-4">
    <?php if (empty($routers)): ?>
        <div class="col-12">
            <div class="card p-5 text-center text-secondary">
                <i class="bi bi-router display-3 mb-3 text-info"></i>
                <h5 class="text-white">Belum ada MikroTik yang didaftarkan</h5>
                <p>Klik tombol <strong>Tambah MikroTik</strong> di atas untuk mendaftarkan IP router pertama Anda.</p>
                <div>
                    <button class="btn btn-primary mt-2" data-bs-toggle="modal" data-bs-target="#addRouterModal">
                        <i class="bi bi-plus-lg me-1"></i> Tambah MikroTik Sekarang
                    </button>
                </div>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($routers as $r): 
            // Fetch active interfaces for this router
            $stmtIf = $pdo->prepare("SELECT * FROM interfaces WHERE router_id = ? AND is_active = 1 ORDER BY interface_name ASC");
            $stmtIf->execute([$r['id']]);
            $routerIfaces = $stmtIf->fetchAll();
        ?>
        <div class="col-12">
            <div class="card shadow-sm border-secondary">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center py-3">
                    <div class="d-flex align-items-center gap-3">
                        <div class="bg-primary bg-opacity-10 text-primary p-2 rounded-3 fs-4">
                            <i class="bi bi-router"></i>
                        </div>
                        <div>
                            <h5 class="fw-bold text-white mb-0"><?= htmlspecialchars($r['name']) ?></h5>
                            <div class="small text-secondary">
                                Host/IP: <code class="text-info"><?= htmlspecialchars($r['host']) ?></code> &bull;
                                Port: <?= $r['api_port'] ?> &bull;
                                Tipe: <span class="badge bg-dark border border-secondary"><?= htmlspecialchars($r['api_type']) ?></span> &bull;
                                Target SLA: <span class="badge bg-info bg-opacity-25 text-info fw-bold"><?= number_format($r['target_sla_percent'], 2) ?>%</span> &bull;
                                Dipantau: <span class="badge bg-success bg-opacity-25 text-success"><?= count($routerIfaces) ?> Interface</span>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex align-items-center gap-2 mt-2 mt-md-0">
                        <!-- Primary Action: Read & Select Interface directly from MikroTik -->
                        <button class="btn btn-sm btn-info text-dark fw-bold" onclick="openSelectInterfacesModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['name'])) ?>', '<?= htmlspecialchars(addslashes($r['host'])) ?>')">
                            <i class="bi bi-arrow-repeat me-1"></i> Baca &amp; Pilih Interface dari MikroTik
                        </button>

                        <?php if (!empty($routerIfaces)): ?>
                            <!-- Bersihkan / Hapus Semua Interface -->
                            <form method="POST" action="" onsubmit="return confirm('PENTING: Hapus seluruh <?= count($routerIfaces) ?> interface dari router ini?\n\nSemua interface lama akan dibersihkan agar Anda bisa memilih ulang interface yang dibutuhkan.');" class="d-inline">
                                <input type="hidden" name="action" value="delete_all_interfaces">
                                <input type="hidden" name="router_id" value="<?= $r['id'] ?>">
                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Bersihkan Seluruh Interface">
                                    <i class="bi bi-trash3 me-1"></i> Bersihkan Semua Interface
                                </button>
                            </form>
                        <?php endif; ?>

                        <?php if ($r['api_type'] === 'push_agent'): ?>
                            <a href="api/mikrotik_script.php?router_id=<?= $r['id'] ?>" target="_blank" class="btn btn-sm btn-outline-warning">
                                <i class="bi bi-code-slash me-1"></i> Script MikroTik
                            </a>
                        <?php endif; ?>

                        <button class="btn btn-sm btn-outline-secondary" onclick='editRouter(<?= json_encode($r) ?>)' title="Edit Router">
                            <i class="bi bi-pencil me-1"></i> Edit
                        </button>
                        <form method="POST" action="" onsubmit="return confirm('Hapus router <?= htmlspecialchars(addslashes($r['name'])) ?> beserta semua riwayat log dan interfacenya?');" class="d-inline">
                            <input type="hidden" name="action" value="delete_router">
                            <input type="hidden" name="router_id" value="<?= $r['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus Router">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card-body p-0">
                    <?php if (empty($routerIfaces)): ?>
                        <div class="p-4 text-center text-secondary">
                            <i class="bi bi-ethernet fs-3 mb-2 text-warning d-block"></i>
                            <div>Belum ada interface yang dipilih untuk dipantau pada router ini.</div>
                            <button class="btn btn-sm btn-outline-info mt-2" onclick="openSelectInterfacesModal(<?= $r['id'] ?>, '<?= htmlspecialchars(addslashes($r['name'])) ?>', '<?= htmlspecialchars(addslashes($r['host'])) ?>')">
                                <i class="bi bi-arrow-repeat me-1"></i> Ambil &amp; Pilih Interface dari MikroTik Sekarang
                            </button>
                        </div>
                    <?php else: ?>
                        <!-- Table Filter and Bulk Action Toolbar -->
                        <div class="px-3 py-2 bg-dark bg-opacity-50 border-bottom border-secondary d-flex flex-wrap justify-content-between align-items-center gap-2">
                            <div class="d-flex align-items-center gap-2" style="min-width: 250px; max-width: 380px;">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                                    <input type="text" class="form-control bg-dark border-secondary text-white" placeholder="Cari ether / nama interface..." onkeyup="filterRouterTable(<?= $r['id'] ?>, this.value)">
                                </div>
                            </div>
                            <div id="bulkToolbar_<?= $r['id'] ?>" class="d-none align-items-center gap-2">
                                <span class="badge bg-primary text-white" id="bulkCount_<?= $r['id'] ?>">0 Dipilih</span>
                                <button type="button" class="btn btn-sm btn-danger fw-bold" onclick="executeBulkAction(<?= $r['id'] ?>, 'bulk_delete_interfaces')">
                                    <i class="bi bi-trash me-1"></i> Hapus Terpilih
                                </button>
                                <button type="button" class="btn btn-sm btn-warning text-dark fw-bold" onclick="executeBulkAction(<?= $r['id'] ?>, 'bulk_deactivate_interfaces')">
                                    <i class="bi bi-eye-slash me-1"></i> Nonaktifkan
                                </button>
                            </div>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-0" id="table_ifaces_<?= $r['id'] ?>">
                                <thead class="text-secondary small text-uppercase bg-dark bg-opacity-25">
                                    <tr>
                                        <th style="width: 40px;" class="text-center">
                                            <input type="checkbox" class="form-check-input" id="checkAll_<?= $r['id'] ?>" onclick="toggleAllTableCheckboxes(<?= $r['id'] ?>, this.checked)" title="Pilih Semua">
                                        </th>
                                        <th>Interface / Ether</th>
                                        <th>Alias / Keterangan</th>
                                        <th>Status Link</th>
                                        <th>Traffic Terakhir (RX / TX)</th>
                                        <th>Target SLA</th>
                                        <th>Terakhir Dicek</th>
                                        <th class="text-end">Aksi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($routerIfaces as $if): 
                                        $targetSlaIf = (float)($if['target_sla_percent'] !== null ? $if['target_sla_percent'] : $r['target_sla_percent']);
                                    ?>
                                    <tr data-iface-name="<?= htmlspecialchars(strtolower($if['interface_name'])) ?>" data-iface-alias="<?= htmlspecialchars(strtolower($if['alias_name'] ?? '')) ?>">
                                        <td class="text-center">
                                            <input type="checkbox" value="<?= $if['id'] ?>" class="form-check-input iface-row-chk-<?= $r['id'] ?>" onchange="updateBulkToolbar(<?= $r['id'] ?>)">
                                        </td>
                                        <td>
                                            <a href="router_detail.php?interface_id=<?= $if['id'] ?>" class="fw-bold text-white text-decoration-none">
                                                <i class="bi bi-ethernet me-1 text-info"></i> <?= htmlspecialchars($if['interface_name']) ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="text-secondary"><?= htmlspecialchars($if['alias_name'] ?: '-') ?></span>
                                        </td>
                                        <td>
                                            <?php if ($if['current_status'] === 'UP'): ?>
                                                <span class="badge-up"><span class="pulse-dot pulse-up"></span> UP</span>
                                            <?php elseif ($if['current_status'] === 'TIMEOUT'): ?>
                                                <span class="badge-timeout"><span class="pulse-dot pulse-timeout"></span> TIMEOUT</span>
                                            <?php elseif ($if['current_status'] === 'DOWN'): ?>
                                                <span class="badge-down"><span class="pulse-dot pulse-down"></span> DOWN</span>
                                            <?php else: ?>
                                                <span class="badge bg-secondary">UNKNOWN</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="text-success small fw-bold"><i class="bi bi-arrow-down"></i> <?= SlaCalculator::formatBps($if['last_rx_bps']) ?></span> / 
                                            <span class="text-primary small fw-bold"><i class="bi bi-arrow-up"></i> <?= SlaCalculator::formatBps($if['last_tx_bps']) ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-dark border border-secondary text-light"><?= number_format($targetSlaIf, 2) ?>%</span>
                                        </td>
                                        <td>
                                            <span class="text-secondary small"><?= $if['last_checked_at'] ? date('H:i:s', strtotime($if['last_checked_at'])) : '-' ?></span>
                                        </td>
                                        <td class="text-end">
                                            <a href="router_detail.php?interface_id=<?= $if['id'] ?>" class="btn btn-sm btn-outline-info" title="Grafik &amp; SLA Detail">
                                                <i class="bi bi-graph-up me-1"></i> Grafik
                                            </a>
                                            <form method="POST" action="" onsubmit="return confirm('Hapus interface <?= htmlspecialchars(addslashes($if['interface_name'])) ?> dari monitoring?');" class="d-inline">
                                                <input type="hidden" name="action" value="delete_interface">
                                                <input type="hidden" name="interface_id" value="<?= $if['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" title="Hapus Interface">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal: Baca & Pilih Interface Langsung dari MikroTik -->
<div class="modal fade" id="selectInterfacesModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content bg-dark text-white border-secondary">
            <form method="POST" action="" id="formSelectIfaces">
                <input type="hidden" name="action" value="save_selected_interfaces">
                <input type="hidden" name="router_id" id="modalRouterId" value="0">

                <div class="modal-header border-secondary">
                    <div>
                        <h5 class="modal-title fw-bold text-white mb-0">
                            <i class="bi bi-ethernet text-info me-2"></i> Pilih Interface dari MikroTik: <span id="modalRouterNameTitle" class="text-info"></span>
                        </h5>
                        <small class="text-secondary">Host/IP: <code id="modalRouterHostTitle" class="text-white"></code></small>
                    </div>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body p-4">
                    <!-- Loading state -->
                    <div id="modalLoadingIfaces" class="text-center py-5">
                        <div class="spinner-border text-info mb-3" style="width: 3rem; height: 3rem;" role="status"></div>
                        <h6 class="text-white fw-bold">Menghubungi MikroTik &amp; Membaca Daftar Interface...</h6>
                        <p class="text-secondary small">Sistem sedang login ke router melalui API untuk mengambil seluruh interface yang ada.</p>
                    </div>

                    <!-- Error State -->
                    <div id="modalErrorIfaces" class="alert alert-danger d-none my-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <i class="bi bi-exclamation-triangle-fill fs-4"></i>
                            <strong class="fs-6">Gagal Menghubungi MikroTik!</strong>
                        </div>
                        <p id="modalErrorMsg" class="mb-2 small fw-bold text-white"></p>
                        <div class="small text-secondary mb-3">
                            <i class="bi bi-info-circle me-1"></i> <strong>Tips:</strong> Pastikan IP MikroTik dapat dijangkau dari komputer/server Anda, username &amp; password benar, dan port API aktif di MikroTik dengan perintah Winbox: <code>/ip service enable api</code>
                        </div>

                        <!-- Fallback button for local testing -->
                        <div class="p-3 bg-dark border border-secondary rounded">
                            <div class="fw-bold text-warning small mb-1"><i class="bi bi-laptop me-1"></i> Sedang Menguji Coba di Local / Router Belum Tersambung?</div>
                            <div class="small text-secondary mb-2">Jika Anda sedang mengetes di laptop dan belum menyambungkan router MikroTik asli, Anda bisa langsung memuat daftar interface standar MikroTik (ether1 s/d ether5) untuk simulasi.</div>
                            <button type="button" class="btn btn-sm btn-outline-warning fw-bold" onclick="loadDefaultFallbackInterfaces()">
                                <i class="bi bi-magic me-1"></i> Muat Interface Standar MikroTik (ether1 - ether5)
                            </button>
                        </div>
                    </div>

                    <!-- Interface List Content -->
                    <div id="modalContentIfaces" class="d-none">
                        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
                            <div>
                                <span class="badge bg-success bg-opacity-25 text-success border border-success me-2" id="modalSuccessBadge"></span>
                                <span class="text-secondary small">Centang interface yang ingin dipantau traffic dan SLA-nya.</span>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-info" onclick="toggleAllIfaces(true)">
                                    <i class="bi bi-check-all me-1"></i> Centang Semua
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAllIfaces(false)">
                                    <i class="bi bi-dash-circle me-1"></i> Batal Semua
                                </button>
                            </div>
                        </div>

                        <!-- Search & Quick Filter -->
                        <div class="row g-2 mb-3 align-items-center">
                            <div class="col-md-6">
                                <div class="input-group input-group-sm">
                                    <span class="input-group-text bg-dark border-secondary text-secondary"><i class="bi bi-search"></i></span>
                                    <input type="text" id="ifaceFilterSearch" class="form-control text-white bg-dark border-secondary" placeholder="Cari interface (contoh: ether, sfp, bridge)..." onkeyup="filterInterfacesList()">
                                </div>
                            </div>
                            <div class="col-md-6 text-md-end">
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-info active" id="btnFilterAll" onclick="setIfaceCategoryFilter('all')">Semua</button>
                                    <button type="button" class="btn btn-outline-info" id="btnFilterEther" onclick="setIfaceCategoryFilter('ether')">Ether / SFP</button>
                                    <button type="button" class="btn btn-outline-info" id="btnFilterBridge" onclick="setIfaceCategoryFilter('bridge')">Bridge / VLAN</button>
                                    <button type="button" class="btn btn-outline-info" id="btnFilterPppoe" onclick="setIfaceCategoryFilter('pppoe')">PPPoE</button>
                                </div>
                            </div>
                        </div>

                        <div class="table-responsive border border-secondary rounded" style="max-height: 480px; overflow-y: auto;">
                            <table class="table table-hover align-middle mb-0">
                                <thead class="text-secondary small text-uppercase bg-dark sticky-top">
                                    <tr>
                                        <th style="width: 50px;" class="text-center">Pantau</th>
                                        <th>Nama Interface</th>
                                        <th>Tipe</th>
                                        <th>Status Link di MikroTik</th>
                                        <th>Alias / Label Keterangan</th>
                                        <th style="width: 160px;">Target SLA (%)</th>
                                    </tr>
                                </thead>
                                <tbody id="modalIfacesTableBody">
                                    <!-- Populated via AJAX -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-secondary justify-content-between">
                    <div class="d-flex flex-column align-items-start text-start">
                        <div class="form-check form-switch mb-1">
                            <input class="form-check-input" type="checkbox" name="replace_all_mode" id="chkReplaceAll" value="1" checked>
                            <label class="form-check-label text-info small fw-bold" for="chkReplaceAll">
                                Hanya pantau yang dicentang (Nonaktifkan interface lain yang tidak dicentang)
                            </label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="delete_unselected" id="chkDeleteUnselected" value="1">
                            <label class="form-check-label text-warning small" for="chkDeleteUnselected">
                                Hapus permanen interface yang tidak dicentang dari database (bersihkan sisa ratusan PPPoE)
                            </label>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
                        <button type="submit" class="btn btn-primary fw-bold px-4" id="btnSaveIfaces" disabled>
                            <i class="bi bi-check-lg me-1"></i> Simpan Pilihan Interface
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Add / Edit Router -->
<div class="modal fade" id="addRouterModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content bg-dark text-white border-secondary">
            <form method="POST" action="" id="routerForm">
                <input type="hidden" name="action" value="save_router">
                <input type="hidden" name="router_id" id="formRouterId" value="0">

                <div class="modal-header border-secondary">
                    <h5 class="modal-title fw-bold" id="routerModalTitle"><i class="bi bi-router text-info me-2"></i> Tambah MikroTik Baru</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Nama Router / Lokasi</label>
                            <input type="text" name="name" id="formName" class="form-control" placeholder="Contoh: Router Core Datacenter" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small text-secondary">Host / IP Address / DDNS</label>
                            <input type="text" name="host" id="formHost" class="form-control" placeholder="192.168.88.1 atau IP Public" required>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label small text-secondary">Metode Cek</label>
                            <select name="api_type" id="formApiType" class="form-select" onchange="adjustPortDefault()">
                                <option value="routeros_api">RouterOS API (Port 8728 / 8729)</option>
                                <option value="rest_api">RouterOS v7 REST API (HTTPS/HTTP)</option>
                                <option value="push_agent">Push Agent Script (MikroTik ke Hosting)</option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label small text-secondary">Port API</label>
                            <input type="number" name="api_port" id="formPort" class="form-control" value="8728">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label small text-secondary">Target SLA Server (%)</label>
                            <div class="input-group">
                                <input type="number" step="0.01" min="80" max="100" name="target_sla_percent" id="formTargetSla" class="form-control" value="99.50" required>
                                <span class="input-group-text bg-dark border-secondary text-secondary">%</span>
                            </div>
                            <div class="form-text text-secondary" style="font-size: 0.75rem;">SLA bulanan yang ditargetkan untuk router ini.</div>
                        </div>
                    </div>

                    <div id="credentialSection">
                        <div class="row g-3 mb-3">
                            <div class="col-md-5">
                                <label class="form-label small text-secondary">Username RouterOS</label>
                                <input type="text" name="username" id="formUsername" class="form-control" value="admin">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label small text-secondary">Password RouterOS</label>
                                <input type="password" name="password" id="formPassword" class="form-control" placeholder="Kosongkan jika tanpa password / tidak diubah">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small text-secondary">Timeout</label>
                                <div class="input-group">
                                    <input type="number" name="timeout_sec" id="formTimeout" class="form-control" value="4" min="1" max="15">
                                    <span class="input-group-text bg-dark border-secondary text-secondary">s</span>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3 form-check">
                            <input type="checkbox" name="use_ssl" id="formUseSsl" class="form-check-input" value="1">
                            <label class="form-check-label small text-secondary" for="formUseSsl">Gunakan SSL / TLS (Port 8729 atau HTTPS 443)</label>
                        </div>

                        <!-- Live Test Connection Button -->
                        <div class="p-3 border border-secondary rounded-3 bg-dark mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong class="text-white small"><i class="bi bi-lightning-charge text-warning"></i> Tes Koneksi &amp; Ambil Interface Langsung</strong>
                                    <div class="small text-secondary" style="font-size: 0.75rem;">Uji apakah IP MikroTik dapat dijangkau dan baca daftar interface sekarang.</div>
                                </div>
                                <button type="button" class="btn btn-sm btn-outline-warning" id="btnTestConn" onclick="runConnectionTest()">
                                    <i class="bi bi-play-circle me-1"></i> Tes Sekarang
                                </button>
                            </div>

                            <div id="testConnResult" class="mt-2 d-none"></div>

                            <!-- Interface Selection Checkboxes from Test -->
                            <div id="ifaceSelectorBox" class="mt-3 d-none">
                                <label class="form-label small text-info fw-bold">Pilih Interface yang Ingin Dipantau:</label>
                                <div id="ifaceCheckboxes" class="d-flex flex-wrap gap-2 p-2 bg-black rounded border border-secondary"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer border-secondary">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-save me-1"></i> Simpan Router</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Bulk Action Form -->
<form id="bulkActionForm" method="POST" action="" style="display:none;">
    <input type="hidden" name="action" id="bulkActionInput" value="">
    <input type="hidden" name="router_id" id="bulkRouterIdInput" value="">
    <div id="bulkIdsContainer"></div>
</form>

<script>
function adjustPortDefault() {
    const apiType = document.getElementById('formApiType').value;
    const portInput = document.getElementById('formPort');
    const credSection = document.getElementById('credentialSection');

    if (apiType === 'routeros_api') {
        portInput.value = '8728';
        credSection.classList.remove('d-none');
    } else if (apiType === 'rest_api') {
        portInput.value = '443';
        credSection.classList.remove('d-none');
    } else if (apiType === 'push_agent') {
        credSection.classList.add('d-none');
    }
}

function resetAddRouterModal() {
    document.getElementById('routerModalTitle').innerHTML = '<i class="bi bi-router text-info me-2"></i> Tambah MikroTik Baru';
    document.getElementById('formRouterId').value = 0;
    document.getElementById('formName').value = '';
    document.getElementById('formHost').value = '';
    document.getElementById('formApiType').value = 'routeros_api';
    document.getElementById('formPort').value = '8728';
    document.getElementById('formUsername').value = 'admin';
    document.getElementById('formPassword').value = '';
    document.getElementById('formTimeout').value = 4;
    document.getElementById('formTargetSla').value = '99.50';
    document.getElementById('formUseSsl').checked = false;
    document.getElementById('credentialSection').classList.remove('d-none');
    document.getElementById('testConnResult').classList.add('d-none');
    document.getElementById('ifaceSelectorBox').classList.add('d-none');
    document.getElementById('ifaceCheckboxes').innerHTML = '';
}

function editRouter(router) {
    document.getElementById('routerModalTitle').innerHTML = '<i class="bi bi-pencil-square text-info me-2"></i> Edit MikroTik: ' + router.name;
    document.getElementById('formRouterId').value = router.id;
    document.getElementById('formName').value = router.name;
    document.getElementById('formHost').value = router.host;
    document.getElementById('formApiType').value = router.api_type;
    document.getElementById('formPort').value = router.api_port;
    document.getElementById('formUsername').value = router.username;
    document.getElementById('formPassword').value = '';
    document.getElementById('formTimeout').value = router.timeout_sec || 4;
    document.getElementById('formTargetSla').value = router.target_sla_percent || 99.50;
    document.getElementById('formUseSsl').checked = (router.use_ssl == 1);

    // Toggle credential visibility without overwriting custom port
    const credSection = document.getElementById('credentialSection');
    if (router.api_type === 'push_agent') {
        credSection.classList.add('d-none');
    } else {
        credSection.classList.remove('d-none');
    }

    document.getElementById('testConnResult').classList.add('d-none');
    document.getElementById('ifaceSelectorBox').classList.add('d-none');
    document.getElementById('ifaceCheckboxes').innerHTML = '';

    new bootstrap.Modal(document.getElementById('addRouterModal')).show();
}

// Bulk Actions on Router Interface Table
function toggleAllTableCheckboxes(routerId, isChecked) {
    const checkboxes = document.querySelectorAll('.iface-row-chk-' + routerId);
    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        if (row && row.style.display !== 'none') {
            cb.checked = isChecked;
        }
    });
    updateBulkToolbar(routerId);
}

function updateBulkToolbar(routerId) {
    const checked = document.querySelectorAll('.iface-row-chk-' + routerId + ':checked');
    const toolbar = document.getElementById('bulkToolbar_' + routerId);
    const countBadge = document.getElementById('bulkCount_' + routerId);
    if (!toolbar) return;

    if (checked.length > 0) {
        toolbar.classList.remove('d-none');
        toolbar.classList.add('d-flex');
        countBadge.textContent = checked.length + ' Dipilih';
    } else {
        toolbar.classList.add('d-none');
        toolbar.classList.remove('d-flex');
    }
}

function executeBulkAction(routerId, actionName) {
    const checked = document.querySelectorAll('.iface-row-chk-' + routerId + ':checked');
    if (checked.length === 0) {
        alert('Pilih minimal satu interface terlebih dahulu.');
        return;
    }

    const actionText = actionName === 'bulk_delete_interfaces' 
        ? 'Hapus ' + checked.length + ' interface yang dipilih secara permanen?' 
        : 'Nonaktifkan ' + checked.length + ' interface yang dipilih dari monitoring?';

    if (!confirm(actionText)) return;

    const form = document.getElementById('bulkActionForm');
    document.getElementById('bulkActionInput').value = actionName;
    document.getElementById('bulkRouterIdInput').value = routerId;

    const container = document.getElementById('bulkIdsContainer');
    container.innerHTML = '';
    checked.forEach(cb => {
        const inp = document.createElement('input');
        inp.type = 'hidden';
        inp.name = 'bulk_ids[]';
        inp.value = cb.value;
        container.appendChild(inp);
    });

    form.submit();
}

function filterRouterTable(routerId, query) {
    const q = (query || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#table_ifaces_' + routerId + ' tbody tr');
    rows.forEach(row => {
        const name = (row.getAttribute('data-iface-name') || '').toLowerCase();
        const alias = (row.getAttribute('data-iface-alias') || '').toLowerCase();
        if (!q || name.includes(q) || alias.includes(q)) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

/**
 * Open Modal to fetch and select interfaces directly from a saved MikroTik
 */
function openSelectInterfacesModal(routerId, routerName, routerHost) {
    document.getElementById('modalRouterId').value = routerId;
    document.getElementById('modalRouterNameTitle').textContent = routerName;
    document.getElementById('modalRouterHostTitle').textContent = routerHost;

    const loadingBox = document.getElementById('modalLoadingIfaces');
    const errorBox = document.getElementById('modalErrorIfaces');
    const contentBox = document.getElementById('modalContentIfaces');
    const tbody = document.getElementById('modalIfacesTableBody');
    const btnSave = document.getElementById('btnSaveIfaces');

    loadingBox.classList.remove('d-none');
    errorBox.classList.add('d-none');
    contentBox.classList.add('d-none');
    btnSave.disabled = true;
    tbody.innerHTML = '';

    const modal = new bootstrap.Modal(document.getElementById('selectInterfacesModal'));
    modal.show();

    fetch('api/fetch_interfaces.php?router_id=' + routerId)
        .then(r => r.json())
        .then(data => {
            loadingBox.classList.add('d-none');

            if (!data.success) {
                errorBox.classList.remove('d-none');
                document.getElementById('modalErrorMsg').textContent = data.message;
                return;
            }

            renderInterfacesTable(data);
        })
        .catch(err => {
            loadingBox.classList.add('d-none');
            errorBox.classList.remove('d-none');
            document.getElementById('modalErrorMsg').textContent = 'Kesalahan jaringan: ' + err;
        });
}

let currentIfaceCategory = 'all';

function setIfaceCategoryFilter(cat) {
    currentIfaceCategory = cat;
    ['btnFilterAll', 'btnFilterEther', 'btnFilterBridge', 'btnFilterPppoe'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.classList.remove('active');
    });
    const activeBtn = document.getElementById(cat === 'all' ? 'btnFilterAll' : (cat === 'ether' ? 'btnFilterEther' : (cat === 'bridge' ? 'btnFilterBridge' : 'btnFilterPppoe')));
    if (activeBtn) activeBtn.classList.add('active');
    filterInterfacesList();
}

function filterInterfacesList() {
    const q = (document.getElementById('ifaceFilterSearch').value || '').toLowerCase().trim();
    const rows = document.querySelectorAll('#modalIfacesTableBody tr');

    rows.forEach(row => {
        const name = row.getAttribute('data-iface-name') || '';
        const type = row.getAttribute('data-iface-type') || '';

        let matchSearch = !q || name.includes(q);
        let matchCat = true;

        if (currentIfaceCategory === 'ether') {
            matchCat = type === 'ether' || name.startsWith('ether') || name.startsWith('sfp');
        } else if (currentIfaceCategory === 'bridge') {
            matchCat = type === 'bridge' || type === 'vlan' || name.startsWith('bridge') || name.startsWith('vlan');
        } else if (currentIfaceCategory === 'pppoe') {
            matchCat = type === 'pppoe-in' || type === 'pppoe-out' || type.includes('ppp') || name.includes('pppoe');
        }

        row.style.display = (matchSearch && matchCat) ? '' : 'none';
    });
}

function renderInterfacesTable(data) {
    const contentBox = document.getElementById('modalContentIfaces');
    const tbody = document.getElementById('modalIfacesTableBody');
    const btnSave = document.getElementById('btnSaveIfaces');

    contentBox.classList.remove('d-none');
    btnSave.disabled = false;
    document.getElementById('modalSuccessBadge').textContent = 'Ditemukan ' + (data.interfaces ? data.interfaces.length : 0) + ' Interface (' + (data.latency_ms || 0) + ' ms)';

    if (!data.interfaces || data.interfaces.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center py-4 text-secondary">Tidak ada interface yang ditemukan pada router ini.</td></tr>';
        return;
    }

    // Sort: Physical (ether, sfp) first, then bridge/vlan, then pppoe
    const sortedIfaces = [...data.interfaces].sort((a, b) => {
        const getPriority = (item) => {
            const n = (item.name || '').toLowerCase();
            const t = (item.type || '').toLowerCase();
            if (t === 'ether' || n.startsWith('ether') || n.startsWith('sfp')) return 1;
            if (t === 'bridge' || n.startsWith('bridge')) return 2;
            if (t === 'vlan' || n.startsWith('vlan')) return 3;
            if (n.startsWith('lo') || t === 'loopback') return 4;
            return 5;
        };
        const pA = getPriority(a);
        const pB = getPriority(b);
        if (pA !== pB) return pA - pB;
        return (a.name || '').localeCompare(b.name || '', undefined, { numeric: true, sensitivity: 'base' });
    });

    let html = '';
    sortedIfaces.forEach((iface, idx) => {
        const isChecked = iface.is_monitored ? 'checked' : '';
        const statusBadge = iface.running 
            ? '<span class="badge-up small"><span class="pulse-dot pulse-up"></span> UP / Running</span>'
            : '<span class="badge-down small"><span class="pulse-dot pulse-down"></span> DOWN (No Link)</span>';
        
        const disabledBadge = iface.disabled 
            ? '<span class="badge bg-secondary ms-1">Disabled</span>' 
            : '';

        const aliasVal = iface.alias_name || iface.comment || '';
        const slaVal = iface.target_sla_percent || '';

        html += `
            <tr data-iface-name="${iface.name.toLowerCase()}" data-iface-type="${(iface.type || 'ether').toLowerCase()}">
                <td class="text-center">
                    <input type="hidden" name="all_fetched_interfaces[]" value="${iface.name}">
                    <input type="checkbox" name="monitored_interfaces[]" value="${iface.name}" class="form-check-input iface-check-item" id="chk_iface_${idx}" ${isChecked}>
                </td>
                <td>
                    <label for="chk_iface_${idx}" class="fw-bold text-white mb-0 cursor-pointer d-flex align-items-center gap-1">
                        <i class="bi bi-ethernet text-info"></i> ${iface.name}
                    </label>
                    ${disabledBadge}
                </td>
                <td>
                    <span class="badge bg-dark border border-secondary text-secondary small">${iface.type || 'ether'}</span>
                </td>
                <td>
                    ${statusBadge}
                </td>
                <td>
                    <input type="text" name="interface_alias[${iface.name}]" class="form-control form-control-sm" placeholder="Contoh: Uplink Telkom" value="${aliasVal}">
                </td>
                <td>
                    <div class="input-group input-group-sm">
                        <input type="number" step="0.01" min="80" max="100" name="interface_sla[${iface.name}]" class="form-control" placeholder="${data.router_sla || 99.50}" value="${slaVal}">
                        <span class="input-group-text bg-dark border-secondary text-secondary">%</span>
                    </div>
                </td>
            </tr>
        `;
    });
    tbody.innerHTML = html;
    filterInterfacesList();
}

function loadDefaultFallbackInterfaces() {
    const defaultList = [
        { name: 'ether1', type: 'ether', running: true, disabled: false, comment: 'Uplink ISP 1 (Primary)', is_monitored: true },
        { name: 'ether2', type: 'ether', running: true, disabled: false, comment: 'Backup ISP 2', is_monitored: true },
        { name: 'ether3', type: 'ether', running: true, disabled: false, comment: 'LAN Kantor / Users', is_monitored: true },
        { name: 'ether4', type: 'ether', running: false, disabled: false, comment: 'Hotspot / Guest', is_monitored: false },
        { name: 'ether5', type: 'ether', running: false, disabled: false, comment: 'Management LAN', is_monitored: false },
        { name: 'sfp-plus1', type: 'sfp-plus', running: true, disabled: false, comment: 'Backbone Fiber 10G', is_monitored: false }
    ];
    renderInterfacesTable({
        success: true,
        latency_ms: 1,
        interfaces: defaultList,
        router_sla: 99.50
    });
    document.getElementById('modalErrorIfaces').classList.add('d-none');
}

function toggleAllIfaces(checked) {
    const checkboxes = document.querySelectorAll('.iface-check-item');
    checkboxes.forEach(c => c.checked = checked);
}

function runConnectionTest() {
    const btn = document.getElementById('btnTestConn');
    const resultBox = document.getElementById('testConnResult');
    const ifaceBox = document.getElementById('ifaceSelectorBox');
    const ifaceCheckboxes = document.getElementById('ifaceCheckboxes');

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Menguji...';
    resultBox.classList.remove('d-none');
    resultBox.innerHTML = '<div class="text-secondary small"><i class="bi bi-arrow-repeat spin"></i> Menghubungi MikroTik...</div>';

    const payload = {
        router_id: document.getElementById('formRouterId').value,
        host: document.getElementById('formHost').value,
        api_type: document.getElementById('formApiType').value,
        api_port: document.getElementById('formPort').value,
        username: document.getElementById('formUsername').value,
        password: document.getElementById('formPassword').value,
        use_ssl: document.getElementById('formUseSsl').checked ? 1 : 0,
        timeout_sec: document.getElementById('formTimeout').value
    };

    fetch('test_connection.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
    .then(r => r.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle me-1"></i> Tes Sekarang';

        if (data.success) {
            resultBox.innerHTML = '<div class="alert alert-success py-2 mb-0 small"><i class="bi bi-check-circle-fill me-1"></i> ' + data.message + ' (Latency: ' + data.latency_ms + ' ms)</div>';

            if (data.interfaces && data.interfaces.length > 0) {
                ifaceBox.classList.remove('d-none');
                let html = '';
                // Sort: physical ether/sfp first
                const sorted = [...data.interfaces].sort((a, b) => {
                    const isEthA = (a.name || '').toLowerCase().startsWith('ether') || (a.name || '').toLowerCase().startsWith('sfp');
                    const isEthB = (b.name || '').toLowerCase().startsWith('ether') || (b.name || '').toLowerCase().startsWith('sfp');
                    if (isEthA && !isEthB) return -1;
                    if (!isEthA && isEthB) return 1;
                    return (a.name || '').localeCompare(b.name || '', undefined, { numeric: true });
                });

                sorted.forEach(iface => {
                    const isPhysical = (iface.name || '').toLowerCase().startsWith('ether') || (iface.name || '').toLowerCase().startsWith('sfp');
                    // ONLY check physical interfaces by default, NEVER pppoe!
                    const isChecked = isPhysical ? 'checked' : '';
                    const statusText = iface.running ? '<span class="text-success">[UP]</span>' : '<span class="text-danger">[DOWN]</span>';
                    html += `
                        <div class="form-check form-check-inline border border-secondary rounded px-3 py-1 bg-dark">
                            <input class="form-check-input" type="checkbox" name="selected_interfaces[]" value="${iface.name}" id="chk_${iface.name}" ${isChecked}>
                            <label class="form-check-label text-white small" for="chk_${iface.name}">
                                <strong>${iface.name}</strong> ${statusText}
                            </label>
                        </div>
                    `;
                });
                ifaceCheckboxes.innerHTML = html;
            }
        } else {
            resultBox.innerHTML = '<div class="alert alert-danger py-2 mb-0 small"><i class="bi bi-exclamation-triangle-fill me-1"></i> ' + data.message + ' (Latency: ' + data.latency_ms + ' ms)</div>';
            ifaceBox.classList.add('d-none');
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-play-circle me-1"></i> Tes Sekarang';
        resultBox.innerHTML = '<div class="alert alert-danger py-2 mb-0 small"><i class="bi bi-x-circle me-1"></i> Gagal melakukan pengujian: ' + err + '</div>';
    });
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
