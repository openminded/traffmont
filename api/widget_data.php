<?php
/**
 * TraffMont - Widget & Mobile App API Endpoint
 * Provides lightweight JSON data for Android AppWidget and Floating Overlay.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/SlaCalculator.php';

function formatSpeed($bps) {
    $bps = (float)$bps;
    if ($bps >= 1000000000) {
        return number_format($bps / 1000000000, 2) . ' Gbps';
    } elseif ($bps >= 1000000) {
        return number_format($bps / 1000000, 1) . ' Mbps';
    } elseif ($bps >= 1000) {
        return number_format($bps / 1000, 0) . ' Kbps';
    } else {
        return number_format($bps, 0) . ' bps';
    }
}

$now = date('Y-m-d H:i:s');
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');

$interfaceId = (int)($_GET['interface_id'] ?? $_POST['interface_id'] ?? 0);
$routerId = (int)($_GET['router_id'] ?? $_POST['router_id'] ?? 0);

try {
    // 1. Fetch Routers
    $stmtRouters = $pdo->query("SELECT id, name, host, api_type, current_status, last_checked_at FROM routers WHERE is_active = 1 ORDER BY name ASC");
    $routers = $stmtRouters->fetchAll(PDO::FETCH_ASSOC);

    // 2. Fetch Interfaces
    if ($interfaceId > 0) {
        $stmtIfaces = $pdo->prepare("
            SELECT i.*, r.name as router_name, r.host as router_host, r.target_sla_percent as router_sla
            FROM interfaces i
            JOIN routers r ON i.router_id = r.id
            WHERE i.id = ? AND i.is_active = 1
        ");
        $stmtIfaces->execute([$interfaceId]);
    } elseif ($routerId > 0) {
        $stmtIfaces = $pdo->prepare("
            SELECT i.*, r.name as router_name, r.host as router_host, r.target_sla_percent as router_sla
            FROM interfaces i
            JOIN routers r ON i.router_id = r.id
            WHERE i.router_id = ? AND i.is_active = 1
            ORDER BY i.interface_name ASC
        ");
        $stmtIfaces->execute([$routerId]);
    } else {
        $stmtIfaces = $pdo->query("
            SELECT i.*, r.name as router_name, r.host as router_host, r.target_sla_percent as router_sla
            FROM interfaces i
            JOIN routers r ON i.router_id = r.id
            WHERE i.is_active = 1
            ORDER BY r.name ASC, i.interface_name ASC
        ");
    }
    $rawIfaces = $stmtIfaces->fetchAll(PDO::FETCH_ASSOC);

    $formattedInterfaces = [];
    $totalUp = 0;
    $totalDown = 0;

    foreach ($rawIfaces as $row) {
        $status = strtoupper($row['current_status'] ?? 'UNKNOWN');
        if ($status === 'UP') {
            $totalUp++;
        } else {
            $totalDown++;
        }

        $targetSla = (float)($row['target_sla_percent'] !== null ? $row['target_sla_percent'] : ($row['router_sla'] ?? 99.50));
        
        // Calculate monthly uptime
        $monthlyStats = SlaCalculator::getMonthlyStats($pdo, (int)$row['id'], $currentYear, $currentMonth, $targetSla);
        $uptimePct = (float)($monthlyStats['uptime_percent'] ?? 100.0);

        $rxBps = (float)($row['last_rx_bps'] ?? 0);
        $txBps = (float)($row['last_tx_bps'] ?? 0);

        $displayName = !empty($row['alias_name']) ? "{$row['interface_name']} ({$row['alias_name']})" : $row['interface_name'];

        $formattedInterfaces[] = [
            'id' => (int)$row['id'],
            'router_id' => (int)$row['router_id'],
            'router_name' => $row['router_name'] ?? 'Router',
            'router_host' => $row['router_host'] ?? '',
            'interface_name' => $row['interface_name'],
            'alias_name' => $row['alias_name'] ?? '',
            'display_name' => $displayName,
            'current_status' => $status,
            'is_up' => ($status === 'UP'),
            'rx_bps' => $rxBps,
            'tx_bps' => $txBps,
            'rx_formatted' => formatSpeed($rxBps),
            'tx_formatted' => formatSpeed($txBps),
            'latency_ms' => (int)($row['last_latency_ms'] ?? 0),
            'target_sla_percent' => $targetSla,
            'monthly_uptime_percent' => round($uptimePct, 2),
            'last_checked_at' => $row['last_checked_at'] ?? $now,
            'last_status_change' => $row['last_status_change'] ?? null,
        ];
    }

    echo json_encode([
        'success' => true,
        'app_name' => 'TraffMont',
        'server_time' => $now,
        'summary' => [
            'total_routers' => count($routers),
            'total_interfaces' => count($formattedInterfaces),
            'up_count' => $totalUp,
            'down_count' => $totalDown,
            'is_all_up' => ($totalDown === 0 && count($formattedInterfaces) > 0)
        ],
        'routers' => $routers,
        'interfaces' => $formattedInterfaces
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
