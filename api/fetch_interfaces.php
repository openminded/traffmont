<?php
/**
 * Fetch Interfaces from MikroTik Router
 * Connects to a registered router and returns the real live interface list from RouterOS.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/MikrotikChecker.php';

Auth::startSession();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$routerId = (int)($_GET['router_id'] ?? $_POST['router_id'] ?? 0);

if ($routerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Router ID tidak valid']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM routers WHERE id = ?");
$stmt->execute([$routerId]);
$router = $stmt->fetch();

if (!$router) {
    echo json_encode(['success' => false, 'message' => 'Router tidak ditemukan di database']);
    exit;
}

// Fetch currently monitored interfaces from DB for this router
$stmtExisting = $pdo->prepare("SELECT id, interface_name, alias_name, target_sla_percent, is_active FROM interfaces WHERE router_id = ?");
$stmtExisting->execute([$routerId]);
$existingIfaces = [];
foreach ($stmtExisting->fetchAll() as $row) {
    $existingIfaces[$row['interface_name']] = $row;
}

// If router is push_agent, we can only list interfaces that have pushed or were registered
if ($router['api_type'] === 'push_agent') {
    $interfaces = [];
    foreach ($existingIfaces as $name => $ex) {
        $interfaces[] = [
            'name' => $name,
            'type' => 'push',
            'running' => true,
            'disabled' => false,
            'comment' => $ex['alias_name'] ?: '',
            'is_monitored' => true,
            'alias_name' => $ex['alias_name'] ?: '',
            'target_sla_percent' => $ex['target_sla_percent']
        ];
    }
    echo json_encode([
        'success' => true,
        'is_push' => true,
        'latency_ms' => 0,
        'interfaces' => $interfaces,
        'message' => 'Router ini menggunakan mode Push Agent.'
    ]);
    exit;
}

// Connect directly to MikroTik via RouterOS API or REST API
$testResult = MikrotikChecker::testConnection($router);

if (!$testResult['success']) {
    echo json_encode([
        'success' => false,
        'latency_ms' => $testResult['latency_ms'] ?? 0,
        'interfaces' => [],
        'message' => 'Gagal membaca interface dari MikroTik: ' . $testResult['message']
    ]);
    exit;
}

$interfaces = [];
foreach ($testResult['interfaces'] as $if) {
    $name = $if['name'];
    $isMonitored = isset($existingIfaces[$name]) && $existingIfaces[$name]['is_active'] == 1;
    $alias = isset($existingIfaces[$name]) ? $existingIfaces[$name]['alias_name'] : $if['comment'];
    $sla = isset($existingIfaces[$name]) ? $existingIfaces[$name]['target_sla_percent'] : null;
    $dbId = isset($existingIfaces[$name]) ? (int)$existingIfaces[$name]['id'] : null;

    $interfaces[] = [
        'name' => $name,
        'type' => $if['type'] ?? 'ether',
        'running' => $if['running'],
        'disabled' => $if['disabled'],
        'comment' => $if['comment'] ?? '',
        'is_monitored' => $isMonitored,
        'alias_name' => $alias,
        'target_sla_percent' => $sla,
        'interface_id' => $dbId
    ];
}

echo json_encode([
    'success' => true,
    'router_name' => $router['name'],
    'router_host' => $router['host'],
    'router_sla' => (float)$router['target_sla_percent'],
    'latency_ms' => $testResult['latency_ms'],
    'interfaces' => $interfaces,
    'message' => 'Berhasil membaca ' . count($interfaces) . ' interface dari MikroTik!'
]);

