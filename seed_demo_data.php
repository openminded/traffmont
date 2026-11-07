<?php
/**
 * TraffMont Demo Data Seeder
 * Populates sample routers, interfaces, 24-hour logs, and 30-day aggregates
 * for instant visual testing on local environment.
 */
require_once __DIR__ . '/db.php';

echo "<h3>Mengisi Data Simulasi Demo TraffMont...</h3>";

// 1. Insert Demo Routers
$routers = [
    [
        'name' => 'MikroTik Core Datacenter',
        'host' => '192.168.88.1',
        'api_type' => 'routeros_api',
        'api_port' => 8728,
        'username' => 'admin',
        'password' => '',
        'target_sla_percent' => 99.80,
        'current_status' => 'UP',
        'last_checked_at' => date('Y-m-d H:i:s')
    ],
    [
        'name' => 'MikroTik Edge Kantor Cabang',
        'host' => '10.10.1.1',
        'api_type' => 'routeros_api',
        'api_port' => 8728,
        'username' => 'admin',
        'password' => '',
        'target_sla_percent' => 99.50,
        'current_status' => 'UP',
        'last_checked_at' => date('Y-m-d H:i:s')
    ]
];

$routerIds = [];
foreach ($routers as $r) {
    $stmt = $pdo->prepare("SELECT id FROM routers WHERE name = ?");
    $stmt->execute([$r['name']]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $routerIds[] = (int)$existing;
    } else {
        $stmtIns = $pdo->prepare("
            INSERT INTO routers (name, host, api_type, api_port, username, password, target_sla_percent, current_status, last_checked_at, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmtIns->execute([
            $r['name'], $r['host'], $r['api_type'], $r['api_port'], $r['username'], $r['password'],
            $r['target_sla_percent'], $r['current_status'], $r['last_checked_at']
        ]);
        $routerIds[] = (int)$pdo->lastInsertId();
    }
}

// 2. Insert Demo Interfaces
$interfaces = [
    [
        'router_id' => $routerIds[0],
        'interface_name' => 'ether1',
        'alias_name' => 'Uplink ISP Utama (Telkom)',
        'target_sla_percent' => 99.80,
        'current_status' => 'UP',
        'last_rx_bps' => 64500000, // 64.5 Mbps
        'last_tx_bps' => 28200000, // 28.2 Mbps
        'last_latency_ms' => 4,
        'last_checked_at' => date('Y-m-d H:i:s')
    ],
    [
        'router_id' => $routerIds[0],
        'interface_name' => 'sfp-plus1',
        'alias_name' => 'Backbone Fiber Core 10G',
        'target_sla_percent' => 99.95,
        'current_status' => 'UP',
        'last_rx_bps' => 345000000, // 345 Mbps
        'last_tx_bps' => 180000000, // 180 Mbps
        'last_latency_ms' => 1,
        'last_checked_at' => date('Y-m-d H:i:s')
    ],
    [
        'router_id' => $routerIds[1],
        'interface_name' => 'ether1',
        'alias_name' => 'VPN IPsec ke Kantor Pusat',
        'target_sla_percent' => 99.50,
        'current_status' => 'UP',
        'last_rx_bps' => 18500000, // 18.5 Mbps
        'last_tx_bps' => 8400000,  // 8.4 Mbps
        'last_latency_ms' => 12,
        'last_checked_at' => date('Y-m-d H:i:s')
    ]
];

$ifaceIds = [];
foreach ($interfaces as $if) {
    $stmt = $pdo->prepare("SELECT id FROM interfaces WHERE router_id = ? AND interface_name = ?");
    $stmt->execute([$if['router_id'], $if['interface_name']]);
    $existing = $stmt->fetchColumn();

    if ($existing) {
        $ifaceIds[] = (int)$existing;
    } else {
        $stmtIns = $pdo->prepare("
            INSERT INTO interfaces (router_id, interface_name, alias_name, target_sla_percent, current_status, last_rx_bps, last_tx_bps, last_latency_ms, last_checked_at, is_active)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
        ");
        $stmtIns->execute([
            $if['router_id'], $if['interface_name'], $if['alias_name'], $if['target_sla_percent'],
            $if['current_status'], $if['last_rx_bps'], $if['last_tx_bps'], $if['last_latency_ms'], $if['last_checked_at']
        ]);
        $ifaceIds[] = (int)$pdo->lastInsertId();
    }
}

// 3. Generate Historical Logs for the last 24 hours (every 10 minutes to populate graphs)
echo "Generating historical traffic and logs...<br>";
$nowTs = time();
$mainIfaceId = $ifaceIds[0];
$mainRouterId = $routerIds[0];

// Seed 144 data points (24 hours * 6 per hour)
for ($i = 144; $i >= 0; $i--) {
    $logTime = date('Y-m-d H:i:s', $nowTs - ($i * 600));
    $isDownPoint = ($i >= 30 && $i <= 32); // simulate a 20-minute outage 5 hours ago

    $status = $isDownPoint ? 'DOWN' : 'UP';
    $latency = $isDownPoint ? 0 : rand(3, 18);
    $rxBps = $isDownPoint ? 0 : rand(25000000, 85000000);
    $txBps = $isDownPoint ? 0 : rand(10000000, 35000000);
    $err = $isDownPoint ? 'Connection Timeout / Interface Down' : null;

    $stmtLog = $pdo->prepare("
        INSERT INTO monitoring_logs (router_id, interface_id, checked_at, status, latency_ms, rx_bps, tx_bps, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtLog->execute([$mainRouterId, $mainIfaceId, $logTime, $status, $latency, $rxBps, $txBps, $err]);
}

// 4. Seed Incident Record
$incidentStart = date('Y-m-d H:i:s', $nowTs - (32 * 600));
$incidentEnd = date('Y-m-d H:i:s', $nowTs - (29 * 600));
$stmtInc = $pdo->prepare("
    INSERT INTO incidents (router_id, interface_id, started_at, ended_at, duration_seconds, status, root_cause)
    VALUES (?, ?, ?, ?, 1800, 'RESOLVED', 'Link Loss / Fiber Cut Temporary')
");
$stmtInc->execute([$mainRouterId, $mainIfaceId, $incidentStart, $incidentEnd]);

// 5. Seed Daily Aggregates for the last 30 days
echo "Generating 30-day SLA aggregates...<br>";
for ($d = 30; $d >= 0; $d--) {
    $dateStr = date('Y-m-d', strtotime("-{$d} days"));
    $hasOutage = ($d === 2 || $d === 15);
    $downMins = $hasOutage ? rand(15, 35) : 0;
    $totalChecks = 1440;
    $upChecks = $totalChecks - $downMins;
    $uptimePct = round(($upChecks / $totalChecks) * 100, 2);

    $isSqlite = function_exists('is_sqlite') && is_sqlite();
    if ($isSqlite) {
        $stmtAgg = $pdo->prepare("
            INSERT INTO daily_aggregates (router_id, interface_id, date, total_checks, up_checks, down_checks, uptime_percent, avg_latency_ms, max_rx_bps, max_tx_bps, total_downtime_minutes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON CONFLICT(interface_id, date) DO UPDATE SET
                uptime_percent = excluded.uptime_percent,
                total_downtime_minutes = excluded.total_downtime_minutes
        ");
    } else {
        $stmtAgg = $pdo->prepare("
            INSERT INTO daily_aggregates (router_id, interface_id, date, total_checks, up_checks, down_checks, uptime_percent, avg_latency_ms, max_rx_bps, max_tx_bps, total_downtime_minutes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                uptime_percent = VALUES(uptime_percent),
                total_downtime_minutes = VALUES(total_downtime_minutes)
        ");
    }

    $stmtAgg->execute([
        $mainRouterId, $mainIfaceId, $dateStr, $totalChecks, $upChecks, $downMins,
        $uptimePct, rand(4, 9), 85000000, 38000000, $downMins
    ]);
}

echo "<div style='color: green; font-weight: bold; margin-top: 20px;'>";
echo "✓ Berhasil! Data demo simulasi telah dibuat.<br>";
echo "<a href='index.php'>Buka Dashboard TraffMont Sekarang</a>";
echo "</div>";

