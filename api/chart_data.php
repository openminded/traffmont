<?php
/**
 * TraffMont Chart & Analytics API
 * Returns structured JSON for Chart.js daily, weekly, and monthly views.
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../lib/Auth.php';
require_once __DIR__ . '/../lib/SlaCalculator.php';

Auth::startSession();
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$interfaceId = (int)($_GET['interface_id'] ?? 0);
$range = $_GET['range'] ?? 'harian'; // 'harian', 'mingguan', 'bulanan'
$date = $_GET['date'] ?? date('Y-m-d');
$month = (int)($_GET['month'] ?? date('n'));
$year = (int)($_GET['year'] ?? date('Y'));

if ($interfaceId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Interface ID is required']);
    exit;
}

// Fetch interface and router
$stmt = $pdo->prepare("
    SELECT i.*, r.name as router_name, r.target_sla_percent as router_sla
    FROM interfaces i
    JOIN routers r ON i.router_id = r.id
    WHERE i.id = ?
");
$stmt->execute([$interfaceId]);
$iface = $stmt->fetch();

if (!$iface) {
    http_response_code(404);
    echo json_encode(['error' => 'Interface not found']);
    exit;
}

$targetSla = (float)($iface['target_sla_percent'] !== null ? $iface['target_sla_percent'] : $iface['router_sla']);

if ($range === 'harian') {
    // 24 Hours of the specified date
    $startOfDay = "{$date} 00:00:00";
    $endOfDay = "{$date} 23:59:59";

    // Hourly aggregation for traffic and latency
    $isSqlite = function_exists('is_sqlite') && is_sqlite();
    if ($isSqlite) {
        $stmtLogs = $pdo->prepare("
            SELECT 
                CAST(strftime('%H', checked_at) AS INTEGER) as hour_num,
                COUNT(*) as total_checks,
                SUM(CASE WHEN status = 'UP' THEN 1 ELSE 0 END) as up_checks,
                SUM(CASE WHEN status IN ('DOWN', 'TIMEOUT') THEN 1 ELSE 0 END) as down_checks,
                ROUND(AVG(rx_bps)) as avg_rx_bps,
                ROUND(AVG(tx_bps)) as avg_tx_bps,
                MAX(rx_bps) as max_rx_bps,
                MAX(tx_bps) as max_tx_bps,
                ROUND(AVG(latency_ms)) as avg_latency
            FROM monitoring_logs
            WHERE interface_id = :id
              AND checked_at >= :start
              AND checked_at <= :end
            GROUP BY strftime('%H', checked_at)
            ORDER BY strftime('%H', checked_at) ASC
        ");
    } else {
        $stmtLogs = $pdo->prepare("
            SELECT 
                HOUR(checked_at) as hour_num,
                COUNT(*) as total_checks,
                SUM(CASE WHEN status = 'UP' THEN 1 ELSE 0 END) as up_checks,
                SUM(CASE WHEN status IN ('DOWN', 'TIMEOUT') THEN 1 ELSE 0 END) as down_checks,
                ROUND(AVG(rx_bps)) as avg_rx_bps,
                ROUND(AVG(tx_bps)) as avg_tx_bps,
                MAX(rx_bps) as max_rx_bps,
                MAX(tx_bps) as max_tx_bps,
                ROUND(AVG(latency_ms)) as avg_latency
            FROM monitoring_logs
            WHERE interface_id = :id
              AND checked_at >= :start
              AND checked_at <= :end
            GROUP BY HOUR(checked_at)
            ORDER BY HOUR(checked_at) ASC
        ");
    }
    $stmtLogs->execute([':id' => $interfaceId, ':start' => $startOfDay, ':end' => $endOfDay]);
    $hourlyRaw = $stmtLogs->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $labels = [];
    $rxData = [];
    $txData = [];
    $latencyData = [];
    $uptimeData = [];

    for ($h = 0; $h < 24; $h++) {
        $labels[] = sprintf('%02d:00', $h);
        if (isset($hourlyRaw[$h])) {
            $row = $hourlyRaw[$h];
            $rxData[] = round((int)$row['avg_rx_bps'] / 1000000, 2); // In Mbps
            $txData[] = round((int)$row['avg_tx_bps'] / 1000000, 2); // In Mbps
            $latencyData[] = (int)$row['avg_latency'];
            $tot = (int)$row['total_checks'];
            $uptimeData[] = $tot > 0 ? round(((int)$row['up_checks'] / $tot) * 100, 2) : 100.00;
        } else {
            $rxData[] = 0;
            $txData[] = 0;
            $latencyData[] = 0;
            $uptimeData[] = null;
        }
    }

    // Recent 60 minutes ticks for visual uptime pulse bar
    $timeFormatSql = (function_exists('is_sqlite') && is_sqlite()) ? "strftime('%H:%M', checked_at)" : "DATE_FORMAT(checked_at, '%H:%i')";
    $stmtRecent = $pdo->prepare("
        SELECT {$timeFormatSql} as time_str, status, latency_ms
        FROM monitoring_logs
        WHERE interface_id = ?
        ORDER BY checked_at DESC
        LIMIT 60
    ");
    $stmtRecent->execute([$interfaceId]);
    $recentTicks = array_reverse($stmtRecent->fetchAll());

    echo json_encode([
        'range' => 'harian',
        'date' => $date,
        'labels' => $labels,
        'rx_mbps' => $rxData,
        'tx_mbps' => $txData,
        'latency_ms' => $latencyData,
        'uptime_percent' => $uptimeData,
        'recent_ticks' => $recentTicks,
        'target_sla' => $targetSla
    ]);
    exit;

} elseif ($range === 'mingguan') {
    // Last 7 days
    $days = [];
    for ($i = 6; $i >= 0; $i--) {
        $days[] = date('Y-m-d', strtotime("-{$i} days"));
    }

    $stmtAgg = $pdo->prepare("
        SELECT date, total_checks, up_checks, down_checks, uptime_percent, avg_latency_ms, max_rx_bps, max_tx_bps, total_downtime_minutes
        FROM daily_aggregates
        WHERE interface_id = :id AND date >= :start_date AND date <= :end_date
    ");
    $stmtAgg->execute([
        ':id' => $interfaceId,
        ':start_date' => $days[0],
        ':end_date' => end($days)
    ]);
    $aggRows = $stmtAgg->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $labels = [];
    $uptimeData = [];
    $downtimeMinutes = [];
    $maxTrafficMbps = [];

    foreach ($days as $d) {
        $labels[] = date('D (d M)', strtotime($d));
        if (isset($aggRows[$d])) {
            $row = $aggRows[$d];
            $uptimeData[] = (float)$row['uptime_percent'];
            $downtimeMinutes[] = (int)$row['total_downtime_minutes'];
            $maxTrafficMbps[] = round(max((int)$row['max_rx_bps'], (int)$row['max_tx_bps']) / 1000000, 2);
        } else {
            $uptimeData[] = 100.0;
            $downtimeMinutes[] = 0;
            $maxTrafficMbps[] = 0;
        }
    }

    echo json_encode([
        'range' => 'mingguan',
        'labels' => $labels,
        'uptime_percent' => $uptimeData,
        'downtime_minutes' => $downtimeMinutes,
        'max_traffic_mbps' => $maxTrafficMbps,
        'target_sla' => $targetSla
    ]);
    exit;

} elseif ($range === 'bulanan') {
    // Monthly breakdown for given month and year
    $stats = SlaCalculator::getMonthlyStats($pdo, $interfaceId, $year, $month, $targetSla);
    $daysInMonth = $stats['days_in_month'];

    $startDate = sprintf('%04d-%02d-01', $year, $month);
    $endDate = sprintf('%04d-%02d-%02d', $year, $month, $daysInMonth);

    $stmtAgg = $pdo->prepare("
        SELECT date, uptime_percent, total_downtime_minutes, max_rx_bps, max_tx_bps
        FROM daily_aggregates
        WHERE interface_id = :id AND date >= :start AND date <= :end
    ");
    $stmtAgg->execute([':id' => $interfaceId, ':start' => $startDate, ':end' => $endDate]);
    $aggRows = $stmtAgg->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);

    $labels = [];
    $uptimeData = [];
    $downtimeData = [];

    for ($day = 1; $day <= $daysInMonth; $day++) {
        $dateStr = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $labels[] = (string)$day;

        if (isset($aggRows[$dateStr])) {
            $uptimeData[] = (float)$aggRows[$dateStr]['uptime_percent'];
            $downtimeData[] = (int)$aggRows[$dateStr]['total_downtime_minutes'];
        } else {
            // If date is in future, set null
            if (strtotime($dateStr) > time()) {
                $uptimeData[] = null;
                $downtimeData[] = null;
            } else {
                $uptimeData[] = 100.0;
                $downtimeData[] = 0;
            }
        }
    }

    echo json_encode([
        'range' => 'bulanan',
        'year' => $year,
        'month' => $month,
        'labels' => $labels,
        'uptime_percent' => $uptimeData,
        'downtime_minutes' => $downtimeData,
        'sla_summary' => $stats
    ]);
    exit;
}

