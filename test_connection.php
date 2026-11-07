<?php
/**
 * Test Connection AJAX Handler
 */
header('Content-Type: application/json');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/MikrotikChecker.php';

Auth::startSession();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

$routerId = (int)($input['router_id'] ?? 0);
$password = $input['password'] ?? '';

// If editing an existing router and password field is empty, reuse the saved password
if (empty($password) && $routerId > 0) {
    try {
        $stmtPass = $pdo->prepare("SELECT password FROM routers WHERE id = ?");
        $stmtPass->execute([$routerId]);
        $savedPass = $stmtPass->fetchColumn();
        if ($savedPass !== false && $savedPass !== null) {
            $password = $savedPass;
        }
    } catch (Exception $ignored) {
    }
}

$routerData = [
    'host' => trim($input['host'] ?? ''),
    'api_type' => trim($input['api_type'] ?? 'routeros_api'),
    'api_port' => (int)($input['api_port'] ?? 8728),
    'username' => trim($input['username'] ?? 'admin'),
    'password' => $password,
    'use_ssl' => !empty($input['use_ssl']),
    'timeout_sec' => (int)($input['timeout_sec'] ?? 4)
];

if (empty($routerData['host'])) {
    echo json_encode(['success' => false, 'message' => 'IP Address or Hostname is required']);
    exit;
}

$result = MikrotikChecker::testConnection($routerData);
echo json_encode($result);

