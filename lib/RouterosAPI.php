<?php
/**
 * RouterOS API client class
 * Compatible with MikroTik RouterOS v6.x and v7.x
 * Pure PHP - No PECL extensions required, works on standard cPanel hosting.
 */
class RouterosAPI
{
    public $debug     = false; // Set to true to print debug info
    public $connected = false;
    public $port      = 8728;  // Default RouterOS API port
    public $ssl       = false; // Connect using SSL (port 8729)
    public $timeout   = 4;     // Connection timeout in seconds
    public $attempts  = 1;     // Connection attempts
    public $delay     = 1;     // Delay between attempts in seconds

    private $socket;
    private $error_no;
    private $error_str;

    /**
     * Connect to RouterOS
     *
     * @param string $ip
     * @param string $login
     * @param string $password
     * @return bool
     */
    public function connect($ip, $login, $password)
    {
        for ($ATTEMPT = 1; $ATTEMPT <= $this->attempts; $ATTEMPT++) {
            $this->connected = false;
            $protocol = ($this->ssl ? 'ssl://' : '');
            $context = stream_context_create([
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                ]
            ]);

            $this->socket = @stream_socket_client(
                $protocol . $ip . ':' . $this->port,
                $this->error_no,
                $this->error_str,
                $this->timeout,
                STREAM_CLIENT_CONNECT,
                $context
            );

            if ($this->socket) {
                socket_set_timeout($this->socket, $this->timeout);
                $this->write('/login', false);
                $this->write('=name=' . $login, false);
                $this->write('=password=' . $password);

                $response = $this->read(false);

                if (isset($response[0])) {
                    if ($response[0] == '!done') {
                        // RouterOS v6.43+ and v7 standard login
                        if (!isset($response[1])) {
                            $this->connected = true;
                            return true;
                        } else {
                            // Old challenge-response login mechanism (RouterOS < 6.43)
                            $matches = [];
                            if (preg_match_all('/[^=]+/i', $response[1], $matches)) {
                                if (isset($matches[0][1]) && $matches[0][0] == 'ret') {
                                    $challenge = hex2bin($matches[0][1]);
                                    $pass_hash = md5(chr(0) . $password . $challenge);
                                    $this->write('/login', false);
                                    $this->write('=name=' . $login, false);
                                    $this->write('=response=00' . $pass_hash);
                                    $response2 = $this->read(false);
                                    if (isset($response2[0]) && $response2[0] == '!done') {
                                        $this->connected = true;
                                        return true;
                                    }
                                }
                            }
                        }
                    } elseif ($response[0] == '!trap') {
                        $trapMsg = 'cannot log in';
                        foreach ($response as $item) {
                            if (strpos($item, '=message=') === 0) {
                                $trapMsg = substr($item, 9);
                            }
                        }
                        $this->error_str = "Login MikroTik ditolak ({$trapMsg}). Periksa username & password router Anda.";
                    }
                }
                @fclose($this->socket);
            } else {
                if (empty($this->error_str)) {
                    $this->error_str = "Koneksi ke {$ip}:{$this->port} gagal atau timeout. Pastikan IP bisa di-ping dan port API aktif (/ip service enable api).";
                }
            }
            if ($ATTEMPT < $this->attempts) {
                sleep($this->delay);
            }
        }

        return false;
    }

    /**
     * Disconnect from RouterOS
     */
    public function disconnect()
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->connected = false;
    }

    /**
     * Write word to RouterOS socket
     *
     * @param string $word
     * @param bool $below
     * @return bool
     */
    public function write($word, $below = true)
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        $length = strlen($word);
        if ($length < 0x80) {
            $len_str = chr($length);
        } elseif ($length < 0x4000) {
            $length |= 0x8000;
            $len_str = chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x200000) {
            $length |= 0xC00000;
            $len_str = chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } elseif ($length < 0x10000000) {
            $length |= 0xE0000000;
            $len_str = chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        } else {
            $len_str = chr(0xF0) . chr(($length >> 24) & 0xFF) . chr(($length >> 16) & 0xFF) . chr(($length >> 8) & 0xFF) . chr($length & 0xFF);
        }

        $res = @fwrite($this->socket, $len_str . $word);
        if ($below) {
            @fwrite($this->socket, chr(0));
        }
        return $res !== false;
    }

    /**
     * Read response from RouterOS
     *
     * @param bool $parse
     * @return array
     */
    public function read($parse = true)
    {
        $response = [];
        if (!is_resource($this->socket)) {
            return $response;
        }

        while (true) {
            $byte = @fread($this->socket, 1);
            if ($byte === false || $byte === '') {
                break;
            }
            $len = ord($byte);
            if ($len & 0x80) {
                if (($len & 0xC0) == 0x80) {
                    $len = (($len & 0x3F) << 8) + ord(fread($this->socket, 1));
                } elseif (($len & 0xE0) == 0xC0) {
                    $len = (($len & 0x1F) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
                } elseif (($len & 0xF0) == 0xE0) {
                    $len = (($len & 0x0F) << 24) + (ord(fread($this->socket, 1)) << 16) + (ord(fread($this->socket, 1)) << 8) + ord(fread($this->socket, 1));
                }
            }

            if ($len == 0) {
                if (count($response) > 0 && in_array(end($response), ['!done', '!trap', '!fatal'])) {
                    break;
                }
                continue;
            }

            $word = '';
            while ($len > 0) {
                $chunk = @fread($this->socket, $len);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $word .= $chunk;
                $len -= strlen($chunk);
            }

            $response[] = $word;
            if ($word == '!done' || $word == '!fatal') {
                break;
            }
        }

        if ($parse) {
            return $this->parseResponse($response);
        }
        return $response;
    }

    /**
     * Parse raw RouterOS words into structured associative array
     *
     * @param array $response
     * @return array
     */
    public function parseResponse(array $response)
    {
        $parsed = [];
        $current = [];
        $is_trap = false;

        foreach ($response as $item) {
            if ($item === '!re') {
                if (!empty($current)) {
                    $parsed[] = $current;
                    $current = [];
                }
            } elseif ($item === '!trap') {
                $is_trap = true;
            } elseif ($item === '!done') {
                if (!empty($current)) {
                    $parsed[] = $current;
                }
                break;
            } else {
                if (strpos($item, '=') === 0) {
                    $first_eq = 1;
                    $second_eq = strpos($item, '=', $first_eq);
                    if ($second_eq !== false) {
                        $key = substr($item, $first_eq, $second_eq - $first_eq);
                        $val = substr($item, $second_eq + 1);
                        $current[$key] = $val;
                    }
                }
            }
        }

        return $parsed;
    }

    /**
     * Helper to execute command and return results
     *
     * @param string $command e.g. /interface/print
     * @param array $params e.g. ["?name=ether1"]
     * @return array
     */
    public function comm($command, array $params = [])
    {
        $count = count($params);
        $this->write($command, $count == 0);
        $i = 0;
        foreach ($params as $param) {
            $i++;
            $this->write($param, $i == $count);
        }
        return $this->read();
    }

    public function getError()
    {
        return $this->error_str ?: 'Connection failed or timed out';
    }
}

