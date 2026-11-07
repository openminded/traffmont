<?php
/**
 * TraffMont Push Agent Endpoint
 * MikroTik routers can push their status and traffic using `/tool fetch`
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/MonitoringEngine.php';

$token = $_REQUEST['token'] ?? '';
$routerId = (int)($_REQUEST['router_id'] ?? 0);
$ifaceName = trim($_REQUEST['interface'] ?? '');
$status = strtoupper(trim($_REQUEST['status'] ?? 'UP'));
$rxBytes = (float)($_REQUEST['rx'] ?? 0);
$txBytes = (float)($_REQUEST['tx'] ?? 0);
$latencyMs = (int)($_REQUEST['latency'] ?? 0);

if (empty($token) || $routerId <= 0 || empty($ifaceName)) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing required parameters: token, router_id, interface']);
    exit;
}

// Verify router token
$stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ? AND is_active = 1");
$stmt->execute([$routerId]);
$router = $stmt->fetch();

if (!$router || empty($router['push_token']) || !hash_equals($router['push_token'], $token)) {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid router ID or push token']);
    exit;
}

// Verify interface
$stmt = $pdo->prepare("SELECT * FROM interfaces WHERE router_id = ? AND interface_name = ? AND is_active = 1");
$stmt->execute([$routerId, $ifaceName]);
$iface = $stmt->fetch();

if (!$iface) {
    http_response_code(404);
    echo json_encode(['error' => "Interface '{$ifaceName}' not found or inactive for router #{$routerId}"]);
    exit;
}

$nowTs = time();
$calcStatus = ($status === 'DOWN' || $status === 'TIMEOUT') ? $status : 'UP';
$traffic = calculateBps($iface, $rxBytes, $txBytes, $nowTs);

recordCheckResult(
    $pdo,
    $router,
    $iface,
    $calcStatus,
    $latencyMs,
    $traffic['rx_bps'],
    $traffic['tx_bps'],
    $rxBytes,
    $txBytes,
    ($calcStatus !== 'UP') ? 'Reported down by MikroTik push agent' : null
);

updateRouterStatus($pdo, $routerId, $calcStatus);

echo json_encode([
    'status' => 'success',
    'message' => 'Metric recorded',
    'recorded_status' => $calcStatus,
    'rx_bps' => $traffic['rx_bps'],
    'tx_bps' => $traffic['tx_bps']
]);
