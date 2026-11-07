<?php
require_once __DIR__ . '/RouterosAPI.php';

/**
 * MikrotikChecker
 * Handles checking MikroTik router and interface status.
 * Supports RouterOS API (v6 & v7) and RouterOS REST API (v7).
 */
class MikrotikChecker
{
    /**
     * Test connection to a router and return list of interfaces
     *
     * @param array $router [host, api_type, api_port, username, password, use_ssl, timeout_sec]
     * @return array [success => bool, latency_ms => int, interfaces => array, message => string]
     */
    public static function testConnection($router)
    {
        $startTime = microtime(true);
        $apiType = $router['api_type'] ?? 'routeros_api';
        $timeout = (int)($router['timeout_sec'] ?? 4);
        if ($timeout < 1) $timeout = 4;

        if ($apiType === 'rest_api') {
            return self::testRestApi($router, $timeout, $startTime);
        } else {
            return self::testRouterosApi($router, $timeout, $startTime);
        }
    }

    /**
     * Test via RouterOS binary API (port 8728/8729)
     */
    private static function testRouterosApi($router, $timeout, $startTime)
    {
        $api = new RouterosAPI();
        $api->timeout = $timeout;
        $api->port = (int)($router['api_port'] ?? 8728);
        $api->ssl = !empty($router['use_ssl']);

        $connected = @$api->connect(
            $router['host'],
            $router['username'],
            $router['password']
        );

        $latency = round((microtime(true) - $startTime) * 1000);

        if (!$connected) {
            return [
                'success' => false,
                'latency_ms' => $latency,
                'interfaces' => [],
                'message' => 'Connection failed or timed out: ' . $api->getError()
            ];
        }

        // Fetch all interfaces safely without restricted proplist
        $ifaces = $api->comm('/interface/print');

        $api->disconnect();

        $interfaces = [];
        if (is_array($ifaces)) {
            foreach ($ifaces as $if) {
                if (isset($if['name'])) {
                    $interfaces[] = [
                        'name' => $if['name'],
                        'type' => $if['type'] ?? 'unknown',
                        'running' => ($if['running'] ?? 'false') === 'true',
                        'disabled' => ($if['disabled'] ?? 'false') === 'true',
                        'comment' => $if['comment'] ?? ''
                    ];
                }
            }
        }

        return [
            'success' => true,
            'latency_ms' => $latency,
            'interfaces' => $interfaces,
            'message' => 'Successfully connected to MikroTik RouterOS API!'
        ];
    }

    /**
     * Test via RouterOS v7 REST API (HTTP/HTTPS)
     */
    private static function testRestApi($router, $timeout, $startTime)
    {
        $proto = !empty($router['use_ssl']) ? 'https' : 'http';
        $port = (int)($router['api_port'] ?? (!empty($router['use_ssl']) ? 443 : 80));
        $url = "{$proto}://{$router['host']}:{$port}/rest/interface";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "{$router['username']}:{$router['password']}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $latency = round((microtime(true) - $startTime) * 1000);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            return [
                'success' => false,
                'latency_ms' => $latency,
                'interfaces' => [],
                'message' => 'cURL Error: ' . $curlErr
            ];
        }

        if ($httpCode !== 200) {
            return [
                'success' => false,
                'latency_ms' => $latency,
                'interfaces' => [],
                'message' => "HTTP Response code: {$httpCode}. Verify RouterOS REST API is enabled and credentials are correct."
            ];
        }

        $data = json_decode($response, true);
        $interfaces = [];
        if (is_array($data)) {
            foreach ($data as $if) {
                if (isset($if['name'])) {
                    $interfaces[] = [
                        'name' => $if['name'],
                        'type' => $if['type'] ?? 'unknown',
                        'running' => ($if['running'] ?? 'false') === 'true' || ($if['running'] ?? false) === true,
                        'disabled' => ($if['disabled'] ?? 'false') === 'true' || ($if['disabled'] ?? false) === true,
                        'comment' => $if['comment'] ?? ''
                    ];
                }
            }
        }

        return [
            'success' => true,
            'latency_ms' => $latency,
            'interfaces' => $interfaces,
            'message' => 'Successfully connected to RouterOS REST API!'
        ];
    }

    /**
     * Check a specific interface status on a router
     *
     * @param array $router
     * @param array $interface
     * @return array [status => 'UP'|'DOWN'|'TIMEOUT', latency_ms => int, rx_bytes => int, tx_bytes => int, error => string|null]
     */
    public static function checkInterface($router, $interface)
    {
        $startTime = microtime(true);
        $apiType = $router['api_type'] ?? 'routeros_api';
        $timeout = (int)($router['timeout_sec'] ?? 4);
        if ($timeout < 1) $timeout = 4;
        $ifaceName = $interface['interface_name'];

        if ($apiType === 'rest_api') {
            return self::checkInterfaceRest($router, $ifaceName, $timeout, $startTime);
        } else {
            return self::checkInterfaceRouterosApi($router, $ifaceName, $timeout, $startTime);
        }
    }

    private static function checkInterfaceRouterosApi($router, $ifaceName, $timeout, $startTime)
    {
        $api = new RouterosAPI();
        $api->timeout = $timeout;
        $api->port = (int)($router['api_port'] ?? 8728);
        $api->ssl = !empty($router['use_ssl']);

        $connected = @$api->connect(
            $router['host'],
            $router['username'],
            $router['password']
        );

        $latency = round((microtime(true) - $startTime) * 1000);

        if (!$connected) {
            $err = $api->getError();
            $status = (stripos($err, 'timed out') !== false || stripos($err, 'timeout') !== false) ? 'TIMEOUT' : 'DOWN';
            return [
                'status' => $status,
                'latency_ms' => $latency,
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'error' => 'Router unreachable: ' . $err
            ];
        }

        // Query interface details
        $res = $api->comm('/interface/print', [
            '?name=' . $ifaceName,
            '=.proplist=name,running,disabled,rx-byte,tx-byte'
        ]);

        $api->disconnect();

        if (empty($res) || !isset($res[0])) {
            return [
                'status' => 'DOWN',
                'latency_ms' => $latency,
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'error' => "Interface '{$ifaceName}' not found on router"
            ];
        }

        $row = $res[0];
        $running = ($row['running'] ?? 'false') === 'true';
        $disabled = ($row['disabled'] ?? 'false') === 'true';
        $rxBytes = isset($row['rx-byte']) ? (float)$row['rx-byte'] : 0;
        $txBytes = isset($row['tx-byte']) ? (float)$row['tx-byte'] : 0;

        if ($disabled) {
            return [
                'status' => 'DOWN',
                'latency_ms' => $latency,
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
                'error' => "Interface '{$ifaceName}' is administratively disabled"
            ];
        }

        if (!$running) {
            return [
                'status' => 'DOWN',
                'latency_ms' => $latency,
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
                'error' => "Interface '{$ifaceName}' link is down (no carrier/cable unplugged)"
            ];
        }

        return [
            'status' => 'UP',
            'latency_ms' => $latency,
            'rx_bytes' => $rxBytes,
            'tx_bytes' => $txBytes,
            'error' => null
        ];
    }

    private static function checkInterfaceRest($router, $ifaceName, $timeout, $startTime)
    {
        $proto = !empty($router['use_ssl']) ? 'https' : 'http';
        $port = (int)($router['api_port'] ?? (!empty($router['use_ssl']) ? 443 : 80));
        $encodedName = rawurlencode($ifaceName);
        $url = "{$proto}://{$router['host']}:{$port}/rest/interface/{$encodedName}";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, "{$router['username']}:{$router['password']}");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $latency = round((microtime(true) - $startTime) * 1000);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $isTimeout = (stripos($curlErr, 'timed out') !== false || stripos($curlErr, 'timeout') !== false);
            return [
                'status' => $isTimeout ? 'TIMEOUT' : 'DOWN',
                'latency_ms' => $latency,
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'error' => 'Connection failed: ' . $curlErr
            ];
        }

        if ($httpCode !== 200) {
            return [
                'status' => 'DOWN',
                'latency_ms' => $latency,
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'error' => "HTTP error {$httpCode}"
            ];
        }

        $row = json_decode($response, true);
        if (!$row || !isset($row['name'])) {
            return [
                'status' => 'DOWN',
                'latency_ms' => $latency,
                'rx_bytes' => 0,
                'tx_bytes' => 0,
                'error' => "Interface '{$ifaceName}' data invalid or not found"
            ];
        }

        $running = ($row['running'] ?? 'false') === 'true' || ($row['running'] ?? false) === true;
        $disabled = ($row['disabled'] ?? 'false') === 'true' || ($row['disabled'] ?? false) === true;
        $rxBytes = isset($row['rx-byte']) ? (float)$row['rx-byte'] : 0;
        $txBytes = isset($row['tx-byte']) ? (float)$row['tx-byte'] : 0;

        if ($disabled) {
            return [
                'status' => 'DOWN',
                'latency_ms' => $latency,
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
                'error' => "Interface '{$ifaceName}' is administratively disabled"
            ];
        }

        if (!$running) {
            return [
                'status' => 'DOWN',
                'latency_ms' => $latency,
                'rx_bytes' => $rxBytes,
                'tx_bytes' => $txBytes,
                'error' => "Interface '{$ifaceName}' link is down"
            ];
        }

        return [
            'status' => 'UP',
            'latency_ms' => $latency,
            'rx_bytes' => $rxBytes,
            'tx_bytes' => $txBytes,
            'error' => null
        ];
    }
}

