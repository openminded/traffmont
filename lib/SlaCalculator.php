<?php
/**
 * SlaCalculator
 * Handles SLA uptime percentage, downtime budgets, incident metrics, and statistics.
 */
class SlaCalculator
{
    /**
     * Calculate monthly SLA statistics for a given interface or router
     *
     * @param PDO $pdo
     * @param int $interfaceId
     * @param int $year
     * @param int $month
     * @param float $targetSlaPercent
     * @return array
     */
    public static function getMonthlyStats(PDO $pdo, $interfaceId, $year, $month, $targetSlaPercent = 99.50)
    {
        $startDate = sprintf('%04d-%02d-01 00:00:00', $year, $month);
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $month, $year);
        $endDate = sprintf('%04d-%02d-%02d 23:59:59', $year, $month, $daysInMonth);

        // Check if we are currently in this month
        $now = new DateTime();
        $isCurrentMonth = ($now->format('Y') == $year && $now->format('n') == $month);
        if ($isCurrentMonth) {
            $effectiveEndDate = $now->format('Y-m-d H:i:s');
            $monitoredMinutesPossible = max(1, round(($now->getTimestamp() - strtotime($startDate)) / 60));
        } else {
            $effectiveEndDate = $endDate;
            $monitoredMinutesPossible = $daysInMonth * 1440;
        }

        // Query total checks and down checks from monitoring_logs
        $stmt = $pdo->prepare("
            SELECT 
                COUNT(*) as total_checks,
                SUM(CASE WHEN status = 'UP' THEN 1 ELSE 0 END) as up_checks,
                SUM(CASE WHEN status IN ('DOWN', 'TIMEOUT') THEN 1 ELSE 0 END) as down_checks,
                AVG(latency_ms) as avg_latency,
                MAX(rx_bps) as max_rx_bps,
                MAX(tx_bps) as max_tx_bps,
                AVG(rx_bps) as avg_rx_bps,
                AVG(tx_bps) as avg_tx_bps
            FROM monitoring_logs
            WHERE interface_id = :iface_id
              AND checked_at >= :start_date
              AND checked_at <= :end_date
        ");
        $stmt->execute([
            ':iface_id' => $interfaceId,
            ':start_date' => $startDate,
            ':end_date' => $effectiveEndDate
        ]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);

        $totalChecks = (int)($stats['total_checks'] ?? 0);
        $upChecks = (int)($stats['up_checks'] ?? 0);
        $downChecks = (int)($stats['down_checks'] ?? 0);

        // Calculate actual uptime percentage
        if ($totalChecks > 0) {
            $actualUptimePercent = round(($upChecks / $totalChecks) * 100, 3);
            $downtimeMinutes = $downChecks; // Since check is every 1 minute
            $uptimeMinutes = $upChecks;
        } else {
            $actualUptimePercent = 100.00;
            $downtimeMinutes = 0;
            $uptimeMinutes = 0;
        }

        // Monthly Downtime Budget (Minutes) for full month
        $totalMonthMinutes = $daysInMonth * 1440;
        $allowedDowntimeMinutes = round($totalMonthMinutes * ((100.0 - $targetSlaPercent) / 100), 1);
        $remainingDowntimeMinutes = round($allowedDowntimeMinutes - $downtimeMinutes, 1);
        $slaStatus = ($actualUptimePercent >= $targetSlaPercent) ? 'MET' : 'BREACHED';

        // Incidents summary for this month
        $stmtInc = $pdo->prepare("
            SELECT 
                COUNT(*) as total_incidents,
                COALESCE(MAX(duration_seconds), 0) as max_duration_sec,
                COALESCE(SUM(duration_seconds), 0) as total_duration_sec
            FROM incidents
            WHERE interface_id = :iface_id
              AND started_at >= :start_date
              AND started_at <= :end_date
        ");
        $stmtInc->execute([
            ':iface_id' => $interfaceId,
            ':start_date' => $startDate,
            ':end_date' => $effectiveEndDate
        ]);
        $incData = $stmtInc->fetch(PDO::FETCH_ASSOC);
        $totalIncidents = (int)($incData['total_incidents'] ?? 0);
        $maxDowntimeSec = (int)($incData['max_duration_sec'] ?? 0);

        // MTTR (Mean Time to Recovery) in minutes
        $mttrMinutes = $totalIncidents > 0 ? round(($incData['total_duration_sec'] / 60) / $totalIncidents, 1) : 0;

        return [
            'year' => $year,
            'month' => $month,
            'days_in_month' => $daysInMonth,
            'target_sla' => (float)$targetSlaPercent,
            'actual_uptime_percent' => $actualUptimePercent,
            'sla_status' => $slaStatus,
            'total_checks' => $totalChecks,
            'up_checks' => $upChecks,
            'down_checks' => $downChecks,
            'uptime_minutes' => $uptimeMinutes,
            'downtime_minutes' => $downtimeMinutes,
            'allowed_downtime_minutes' => $allowedDowntimeMinutes,
            'remaining_downtime_minutes' => $remainingDowntimeMinutes,
            'total_incidents' => $totalIncidents,
            'longest_outage_minutes' => round($maxDowntimeSec / 60, 1),
            'mttr_minutes' => $mttrMinutes,
            'avg_latency_ms' => round((float)($stats['avg_latency'] ?? 0), 1),
            'avg_rx_bps' => (int)($stats['avg_rx_bps'] ?? 0),
            'avg_tx_bps' => (int)($stats['avg_tx_bps'] ?? 0),
            'max_rx_bps' => (int)($stats['max_rx_bps'] ?? 0),
            'max_tx_bps' => (int)($stats['max_tx_bps'] ?? 0),
        ];
    }

    /**
     * Format bits per second to human readable format (bps, Kbps, Mbps, Gbps)
     */
    public static function formatBps($bps)
    {
        $bps = (float)$bps;
        if ($bps >= 1000000000) {
            return number_format($bps / 1000000000, 2) . ' Gbps';
        } elseif ($bps >= 1000000) {
            return number_format($bps / 1000000, 2) . ' Mbps';
        } elseif ($bps >= 1000) {
            return number_format($bps / 1000, 2) . ' Kbps';
        } else {
            return number_format($bps, 0) . ' bps';
        }
    }

    /**
     * Format bytes to human readable format
     */
    public static function formatBytes($bytes)
    {
        $bytes = (float)$bytes;
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
        if ($power >= count($units)) $power = count($units) - 1;
        return number_format($bytes / pow(1024, $power), 2) . ' ' . $units[$power];
    }

    /**
     * Format minutes to human readable duration (e.g. 2d 4h 15m)
     */
    public static function formatDuration($minutes)
    {
        $minutes = (int)$minutes;
        if ($minutes <= 0) return '0 m';

        $days = floor($minutes / 1440);
        $hours = floor(($minutes % 1440) / 60);
        $mins = $minutes % 60;

        $parts = [];
        if ($days > 0) $parts[] = "{$days}d";
        if ($hours > 0) $parts[] = "{$hours}h";
        if ($mins > 0 || empty($parts)) $parts[] = "{$mins}m";

        return implode(' ', $parts);
    }
}

