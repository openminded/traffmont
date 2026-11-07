<?php
/**
 * Monitoring Engine Helper Functions
 * Shared between cron.php and api/push.php
 * Compatible with MySQL and SQLite
 */

/**
 * Calculate bits per second (bps) from raw byte counter diff
 */
function calculateBps($iface, $currentRxBytes, $currentTxBytes, $nowTs) {
    $lastRxBytes = (float)($iface['last_rx_bytes'] ?? 0);
    $lastTxBytes = (float)($iface['last_tx_bytes'] ?? 0);
    $lastCheckTs = $iface['last_checked_at'] ? strtotime($iface['last_checked_at']) : 0;

    $rxBps = 0;
    $txBps = 0;

    if ($lastCheckTs > 0 && $lastRxBytes > 0 && $lastTxBytes > 0) {
        $timeDiffSec = max(1, $nowTs - $lastCheckTs);
        // Only calculate if within reasonable interval (< 300 sec)
        if ($timeDiffSec <= 300) {
            $rxDiff = $currentRxBytes - $lastRxBytes;
            $txDiff = $currentTxBytes - $lastTxBytes;

            // Handle counter rollover or reboot
            if ($rxDiff >= 0) {
                $rxBps = round(($rxDiff * 8) / $timeDiffSec);
            }
            if ($txDiff >= 0) {
                $txBps = round(($txDiff * 8) / $timeDiffSec);
            }
        }
    }

    return ['rx_bps' => $rxBps, 'tx_bps' => $txBps];
}

/**
 * Record check result to logs, update interface state, manage incidents & daily aggregates
 */
function recordCheckResult(PDO $pdo, $router, $iface, $status, $latencyMs, $rxBps, $txBps, $rawRxBytes, $rawTxBytes, $errorMessage) {
    $now = date('Y-m-d H:i:s');
    $routerId = (int)$router['id'];
    $ifaceId = (int)$iface['id'];
    $prevStatus = $iface['current_status'] ?? 'UNKNOWN';

    // 1. Insert into monitoring_logs
    $stmtLog = $pdo->prepare("
        INSERT INTO monitoring_logs 
        (router_id, interface_id, checked_at, status, latency_ms, rx_bps, tx_bps, raw_rx_bytes, raw_tx_bytes, error_message)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmtLog->execute([
        $routerId, $ifaceId, $now, $status, $latencyMs, $rxBps, $txBps, $rawRxBytes, $rawTxBytes, $errorMessage
    ]);

    // 2. Incident Management
    $statusChanged = ($prevStatus !== $status);
    $statusChangeSql = $statusChanged ? ", last_status_change = '{$now}'" : "";

    $isNowDown = ($status === 'DOWN' || $status === 'TIMEOUT');
    $wasDown = ($prevStatus === 'DOWN' || $prevStatus === 'TIMEOUT');

    if ($isNowDown && !$wasDown) {
        // Transition from UP -> DOWN: OPEN new incident
        $stmtInc = $pdo->prepare("
            INSERT INTO incidents (router_id, interface_id, started_at, status, root_cause)
            VALUES (?, ?, ?, 'OPEN', ?)
        ");
        $stmtInc->execute([$routerId, $ifaceId, $now, $errorMessage ?: 'Interface Down / Timeout']);
    } elseif (!$isNowDown && $wasDown) {
        // Transition from DOWN -> UP: RESOLVE existing open incident
        if (function_exists('is_sqlite') && is_sqlite()) {
            $stmtResolve = $pdo->prepare("
                UPDATE incidents 
                SET ended_at = ?, 
                    duration_seconds = CAST((strftime('%s', ?) - strftime('%s', started_at)) AS INTEGER),
                    status = 'RESOLVED'
                WHERE interface_id = ? AND status = 'OPEN'
            ");
        } else {
            $stmtResolve = $pdo->prepare("
                UPDATE incidents 
                SET ended_at = ?, 
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, ?),
                    status = 'RESOLVED'
                WHERE interface_id = ? AND status = 'OPEN'
            ");
        }
        $stmtResolve->execute([$now, $now, $ifaceId]);
    }

    // 3. Update interface state
    $stmtUpdateIface = $pdo->prepare("
        UPDATE interfaces 
        SET current_status = ?,
            last_rx_bytes = ?,
            last_tx_bytes = ?,
            last_rx_bps = ?,
            last_tx_bps = ?,
            last_latency_ms = ?,
            last_checked_at = ?
            {$statusChangeSql}
        WHERE id = ?
    ");
    $stmtUpdateIface->execute([
        $status, $rawRxBytes, $rawTxBytes, $rxBps, $txBps, $latencyMs, $now, $ifaceId
    ]);

    // 4. Update today's daily aggregates
    updateDailyAggregate($pdo, $routerId, $ifaceId, $status, $latencyMs, $rxBps, $txBps);
}

function updateRouterStatus(PDO $pdo, $routerId, $status) {
    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare("UPDATE routers SET current_status = ?, last_checked_at = ? WHERE id = ?");
    $stmt->execute([$status, $now, $routerId]);
}

/**
 * Upsert today's aggregate statistics
 */
function updateDailyAggregate(PDO $pdo, $routerId, $ifaceId, $status, $latencyMs, $rxBps, $txBps) {
    $today = date('Y-m-d');
    $isUp = ($status === 'UP') ? 1 : 0;
    $isDown = ($status === 'DOWN' || $status === 'TIMEOUT') ? 1 : 0;

    if (function_exists('is_sqlite') && is_sqlite()) {
        $stmt = $pdo->prepare("
            INSERT INTO daily_aggregates (
                router_id, interface_id, date, total_checks, up_checks, down_checks, 
                uptime_percent, avg_latency_ms, max_rx_bps, max_tx_bps, total_downtime_minutes
            ) VALUES (
                :router_id, :iface_id, :date, 1, :is_up, :is_down,
                :uptime_pct, :latency, :rx_bps, :tx_bps, :is_down
            ) ON CONFLICT(interface_id, date) DO UPDATE SET
                total_checks = total_checks + 1,
                up_checks = up_checks + excluded.up_checks,
                down_checks = down_checks + excluded.down_checks,
                uptime_percent = ROUND(((up_checks + excluded.up_checks) * 100.0 / (total_checks + 1)), 2),
                avg_latency_ms = ROUND(((avg_latency_ms * total_checks) + excluded.avg_latency_ms) / (total_checks + 1)),
                max_rx_bps = MAX(max_rx_bps, excluded.max_rx_bps),
                max_tx_bps = MAX(max_tx_bps, excluded.max_tx_bps),
                total_downtime_minutes = total_downtime_minutes + excluded.total_downtime_minutes
        ");
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO daily_aggregates (
                router_id, interface_id, date, total_checks, up_checks, down_checks, 
                uptime_percent, avg_latency_ms, max_rx_bps, max_tx_bps, total_downtime_minutes
            ) VALUES (
                :router_id, :iface_id, :date, 1, :is_up, :is_down,
                :uptime_pct, :latency, :rx_bps, :tx_bps, :is_down
            ) ON DUPLICATE KEY UPDATE
                total_checks = total_checks + 1,
                up_checks = up_checks + :is_up,
                down_checks = down_checks + :is_down,
                uptime_percent = ROUND(((up_checks + :is_up) / (total_checks + 1)) * 100, 2),
                avg_latency_ms = ROUND(((avg_latency_ms * total_checks) + :latency) / (total_checks + 1)),
                max_rx_bps = GREATEST(max_rx_bps, :rx_bps),
                max_tx_bps = GREATEST(max_tx_bps, :tx_bps),
                total_downtime_minutes = total_downtime_minutes + :is_down
        ");
    }

    $stmt->execute([
        ':router_id' => $routerId,
        ':iface_id' => $ifaceId,
        ':date' => $today,
        ':is_up' => $isUp,
        ':is_down' => $isDown,
        ':uptime_pct' => $isUp ? 100.00 : 0.00,
        ':latency' => $latencyMs,
        ':rx_bps' => $rxBps,
        ':tx_bps' => $txBps
    ]);
}

/**
 * Maintenance routine: Clean old raw logs to keep database clean
 */
function runMaintenanceIfDue(PDO $pdo) {
    $currentMinute = (int)date('i');
    $currentHour = (int)date('G');

    // Run at 03:00 AM once a day
    if ($currentHour === 3 && $currentMinute === 0) {
        $retentionDays = (int)get_setting('retention_days', 60);
        if ($retentionDays < 7) $retentionDays = 60;

        $cutoffDate = date('Y-m-d 00:00:00', strtotime("-{$retentionDays} days"));
        $stmt = $pdo->prepare("DELETE FROM monitoring_logs WHERE checked_at < ?");
        $stmt->execute([$cutoffDate]);
    }
}
