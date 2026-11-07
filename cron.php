<?php
/**
 * TraffMont Cron Job Runner
 * Designed to run every minute via cPanel Cron Jobs or external web cron.
 */

// Disable output buffering
if (function_exists('apache_setenv')) {
    @apache_setenv('no-gzip', 1);
}
@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
for ($i = 0; $i < ob_get_level(); $i++) {
    @ob_end_flush();
}
ob_implicit_flush(1);

// Limit execution time to 50 seconds to prevent overlap with next minute's cron
set_time_limit(50);
ini_set('memory_limit', '128M');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/RouterosAPI.php';
require_once __DIR__ . '/lib/MikrotikChecker.php';
require_once __DIR__ . '/lib/MonitoringEngine.php';

// Check access permission: CLI or Valid Token
$isCli = (php_sapi_name() === 'cli');
$cronToken = defined('CRON_TOKEN') ? CRON_TOKEN : get_setting('cron_token', '');

if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $receivedToken = $_GET['token'] ?? '';
    if (empty($cronToken) || !hash_equals($cronToken, $receivedToken)) {
        http_response_code(403);
        echo "403 Forbidden: Invalid or missing cron token.\n";
        exit;
    }
}

$now = date('Y-m-d H:i:s');
$nowTs = time();
$todayDate = date('Y-m-d');
echo "[{$now}] TraffMont Cron Started.\n";

// Fetch active routers
$stmtRouters = $pdo->prepare("SELECT * FROM routers WHERE is_active = 1");
$stmtRouters->execute();
$routers = $stmtRouters->fetchAll();

if (empty($routers)) {
    echo "No active routers configured.\n";
    exit;
}

$totalInterfacesChecked = 0;

foreach ($routers as $router) {
    $routerId = (int)$router['id'];
    $apiType = $router['api_type'];
    echo "Processing router: {$router['name']} ({$router['host']}) - Method: {$apiType}\n";

    // Fetch active interfaces for this router
    $stmtIfaces = $pdo->prepare("SELECT * FROM interfaces WHERE router_id = ? AND is_active = 1");
    $stmtIfaces->execute([$routerId]);
    $interfaces = $stmtIfaces->fetchAll();

    if (empty($interfaces)) {
        echo "  - No active interfaces found for this router.\n";
        continue;
    }

    $routerHasFailure = false;

    // Handle PUSH AGENT mode: check if push report timed out
    if ($apiType === 'push_agent') {
        foreach ($interfaces as $iface) {
            $ifaceId = (int)$iface['id'];
            $lastCheckedTs = $iface['last_checked_at'] ? strtotime($iface['last_checked_at']) : 0;
            $secondsSinceLastCheck = $nowTs - $lastCheckedTs;

            // If no push report received within 120 seconds, flag as TIMEOUT (DOWN)
            if ($secondsSinceLastCheck > 120) {
                recordCheckResult($pdo, $router, $iface, 'TIMEOUT', 0, 0, 0, 0, 0, 'Push report timed out (> 2 min without data)');
            }
            $totalInterfacesChecked++;
        }
        continue;
    }

    // Handle PULL modes: RouterOS API or REST API
    if ($apiType === 'routeros_api') {
        $api = new RouterosAPI();
        $api->timeout = (int)($router['timeout_sec'] ?? 4);
        if ($api->timeout < 1) $api->timeout = 4;
        $api->port = (int)($router['api_port'] ?? 8728);
        $api->ssl = !empty($router['use_ssl']);

        $startTime = microtime(true);
        $connected = @$api->connect($router['host'], $router['username'], $router['password']);
        $latencyMs = round((microtime(true) - $startTime) * 1000);

        if (!$connected) {
            $err = $api->getError();
            $status = (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) ? 'TIMEOUT' : 'DOWN';
            echo "  - Router connection failed: {$err} (Marking as {$status})\n";

            // Mark all interfaces for this router as DOWN / TIMEOUT
            foreach ($interfaces as $iface) {
                recordCheckResult($pdo, $router, $iface, $status, $latencyMs, 0, 0, 0, 0, "Router unreachable: {$err}");
                $totalInterfacesChecked++;
            }
            updateRouterStatus($pdo, $routerId, $status);
            continue;
        }

        // Query all interface stats in one command
        $rosData = $api->comm('/interface/print', [
            '=.proplist=name,running,disabled,rx-byte,tx-byte'
        ]);
        $api->disconnect();

        // Index routeros data by interface name
        $rosInterfaces = [];
        if (is_array($rosData)) {
            foreach ($rosData as $row) {
                if (isset($row['name'])) {
                    $rosInterfaces[$row['name']] = $row;
                }
            }
        }

        foreach ($interfaces as $iface) {
            $ifaceName = $iface['interface_name'];
            if (!isset($rosInterfaces[$ifaceName])) {
                recordCheckResult($pdo, $router, $iface, 'DOWN', $latencyMs, 0, 0, 0, 0, "Interface '{$ifaceName}' not found on router");
                $routerHasFailure = true;
            } else {
                $row = $rosInterfaces[$ifaceName];
                $running = ($row['running'] ?? 'false') === 'true';
                $disabled = ($row['disabled'] ?? 'false') === 'true';
                $rxBytes = isset($row['rx-byte']) ? (float)$row['rx-byte'] : 0;
                $txBytes = isset($row['tx-byte']) ? (float)$row['tx-byte'] : 0;

                if ($disabled) {
                    recordCheckResult($pdo, $router, $iface, 'DOWN', $latencyMs, 0, 0, $rxBytes, $txBytes, "Interface disabled in configuration");
                    $routerHasFailure = true;
                } elseif (!$running) {
                    recordCheckResult($pdo, $router, $iface, 'DOWN', $latencyMs, 0, 0, $rxBytes, $txBytes, "Interface link is down (no carrier)");
                    $routerHasFailure = true;
                } else {
                    // Interface is UP, calculate bps
                    $traffic = calculateBps($iface, $rxBytes, $txBytes, $nowTs);
                    recordCheckResult($pdo, $router, $iface, 'UP', $latencyMs, $traffic['rx_bps'], $traffic['tx_bps'], $rxBytes, $txBytes, null);
                }
            }
            $totalInterfacesChecked++;
        }

        updateRouterStatus($pdo, $routerId, $routerHasFailure ? 'DOWN' : 'UP');

    } elseif ($apiType === 'rest_api') {
        // RouterOS v7 REST API
        foreach ($interfaces as $iface) {
            $check = MikrotikChecker::checkInterface($router, $iface);
            $status = $check['status'];
            $latencyMs = $check['latency_ms'];
            $rxBytes = $check['rx_bytes'];
            $txBytes = $check['tx_bytes'];
            $error = $check['error'];

            if ($status === 'UP') {
                $traffic = calculateBps($iface, $rxBytes, $txBytes, $nowTs);
                recordCheckResult($pdo, $router, $iface, 'UP', $latencyMs, $traffic['rx_bps'], $traffic['tx_bps'], $rxBytes, $txBytes, null);
            } else {
                recordCheckResult($pdo, $router, $iface, $status, $latencyMs, 0, 0, $rxBytes, $txBytes, $error);
                $routerHasFailure = true;
            }
            $totalInterfacesChecked++;
        }
        updateRouterStatus($pdo, $routerId, $routerHasFailure ? 'DOWN' : 'UP');
    }
}

// Perform daily aggregate and cleanup at midnight (or hour 0 minute 0)
runMaintenanceIfDue($pdo);

echo "[{$now}] TraffMont Cron Completed. Checked {$totalInterfacesChecked} interfaces.\n";
