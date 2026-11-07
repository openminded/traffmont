package com.traffmont.widget;

import java.io.BufferedInputStream;
import java.io.BufferedOutputStream;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.InetSocketAddress;
import java.net.Socket;
import java.security.MessageDigest;
import java.util.ArrayList;
import java.util.Collections;
import java.util.Comparator;
import java.util.HashMap;
import java.util.List;
import java.util.Locale;
import java.util.Map;

/**
 * Direct MikroTik RouterOS API Client (Port 8728)
 * Connects directly to MikroTik from Android via Socket.
 * Compatible with RouterOS v6.x and v7.x.
 */
public class MikrotikDirectClient {

    private final String host;
    private final int port;
    private final String username;
    private final String password;
    private final int timeoutMs;

    public MikrotikDirectClient(String host, int port, String username, String password, int timeoutMs) {
        this.host = host;
        this.port = port > 0 ? port : 8728;
        this.username = username != null ? username : "admin";
        this.password = password != null ? password : "";
        this.timeoutMs = timeoutMs > 0 ? timeoutMs : 10000;
    }

    public static class DirectResult {
        public boolean success;
        public String errorMessage;
        public int latencyMs;
        public List<ApiClient.InterfaceItem> interfaces = new ArrayList<>();
        public ApiClient.InterfaceItem singleTraffic;
    }

    /**
     * Connects, logs in, and fetches all interfaces from MikroTik.
     */
    public DirectResult fetchInterfaces() {
        DirectResult result = new DirectResult();
        long startTime = System.currentTimeMillis();

        Socket socket = null;
        try {
            socket = new Socket();
            socket.connect(new InetSocketAddress(host, port), timeoutMs);
            socket.setSoTimeout(timeoutMs);

            InputStream in = new BufferedInputStream(socket.getInputStream(), 16384);
            OutputStream out = new BufferedOutputStream(socket.getOutputStream(), 4096);

            // 1. Login
            boolean loggedIn = login(in, out);
            if (!loggedIn) {
                result.success = false;
                result.errorMessage = "Login MikroTik gagal. Periksa username dan password.";
                return result;
            }

            // 2. Fetch interfaces with proplist to optimize speed with 300+ interfaces
            writeSentence(out, "/interface/print", "=.proplist=name,type,running,disabled,comment");
            List<Map<String, String>> replies = readSentence(in);

            for (Map<String, String> row : replies) {
                String name = row.get("name");
                if (name == null || name.trim().isEmpty()) continue;

                ApiClient.InterfaceItem item = new ApiClient.InterfaceItem();
                item.interfaceName = name;
                item.routerName = host;
                item.aliasName = row.get("comment");
                item.displayName = (item.aliasName != null && !item.aliasName.isEmpty())
                        ? name + " (" + item.aliasName + ")"
                        : name;

                boolean disabled = "true".equalsIgnoreCase(row.get("disabled"));
                boolean running = "true".equalsIgnoreCase(row.get("running"));
                item.isUp = running && !disabled;
                item.currentStatus = item.isUp ? "UP" : (disabled ? "DISABLED" : "DOWN");

                item.rxFormatted = "0.0 Mbps";
                item.txFormatted = "0.0 Mbps";
                item.latencyMs = (int) (System.currentTimeMillis() - startTime);
                item.targetSla = 99.5;
                item.monthlyUptime = item.isUp ? 100.0 : 0.0;

                result.interfaces.add(item);
            }

            // Sort interfaces: physical (ether, sfp) first, then bridges/vlans, then dynamic pppoe
            Collections.sort(result.interfaces, new Comparator<ApiClient.InterfaceItem>() {
                @Override
                public int compare(ApiClient.InterfaceItem a, ApiClient.InterfaceItem b) {
                    int pA = getPriority(a.interfaceName);
                    int pB = getPriority(b.interfaceName);
                    if (pA != pB) {
                        return Integer.compare(pA, pB);
                    }
                    return naturalCompare(a.interfaceName, b.interfaceName);
                }

                private int getPriority(String name) {
                    String lower = name.toLowerCase(Locale.ROOT);
                    if (lower.startsWith("ether")) return 1;
                    if (lower.startsWith("sfp")) return 2;
                    if (lower.startsWith("combo")) return 3;
                    if (lower.startsWith("bridge")) return 4;
                    if (lower.startsWith("vlan")) return 5;
                    if (lower.startsWith("wlan")) return 6;
                    if (lower.startsWith("<pppoe") || lower.startsWith("pppoe")) return 9;
                    return 7;
                }

                private int naturalCompare(String s1, String s2) {
                    return s1.compareToIgnoreCase(s2);
                }
            });

            result.success = true;
            result.latencyMs = (int) (System.currentTimeMillis() - startTime);

        } catch (Exception e) {
            result.success = false;
            result.errorMessage = "Koneksi ke MikroTik (" + host + ":" + port + ") gagal: " + e.getMessage();
        } finally {
            closeQuietly(socket);
        }

        return result;
    }

    /**
     * Fetches real-time traffic for a specific interface using `/interface/monitor-traffic =once=`.
     */
    public DirectResult monitorTraffic(String ifaceName) {
        DirectResult result = new DirectResult();
        long startTime = System.currentTimeMillis();

        Socket socket = null;
        try {
            socket = new Socket();
            socket.connect(new InetSocketAddress(host, port), timeoutMs);
            socket.setSoTimeout(timeoutMs);

            InputStream in = new BufferedInputStream(socket.getInputStream(), 8192);
            OutputStream out = new BufferedOutputStream(socket.getOutputStream(), 2048);

            boolean loggedIn = login(in, out);
            if (!loggedIn) {
                result.success = false;
                result.errorMessage = "Login MikroTik gagal.";
                return result;
            }

            // Command: /interface/monitor-traffic =interface=... =once=
            writeSentence(out, "/interface/monitor-traffic", "=interface=" + ifaceName, "=once=");
            List<Map<String, String>> replies = readSentence(in);

            ApiClient.InterfaceItem item = new ApiClient.InterfaceItem();
            item.interfaceName = ifaceName;
            item.routerName = host;
            item.displayName = ifaceName;

            double rxBps = 0;
            double txBps = 0;

            if (!replies.isEmpty()) {
                Map<String, String> first = replies.get(0);
                try {
                    String rxStr = first.get("rx-bits-per-second");
                    String txStr = first.get("tx-bits-per-second");
                    if (rxStr != null) rxBps = Double.parseDouble(rxStr);
                    if (txStr != null) txBps = Double.parseDouble(txStr);
                } catch (Exception ignored) {
                }
            }

            item.rxBps = rxBps;
            item.txBps = txBps;
            item.rxFormatted = formatSpeed(rxBps);
            item.txFormatted = formatSpeed(txBps);
            item.currentStatus = "UP";
            item.isUp = true;
            item.latencyMs = (int) (System.currentTimeMillis() - startTime);
            item.monthlyUptime = 100.0;
            item.targetSla = 99.5;

            result.singleTraffic = item;
            result.success = true;
            result.latencyMs = item.latencyMs;

        } catch (Exception e) {
            result.success = false;
            result.errorMessage = "Gagal membaca traffic: " + e.getMessage();
        } finally {
            closeQuietly(socket);
        }

        return result;
    }

    private boolean login(InputStream in, OutputStream out) throws Exception {
        // Step 1: Send /login with username & password (RouterOS v6.43+ & v7 standard)
        writeSentence(out, "/login", "=name=" + username, "=password=" + password);
        List<Map<String, String>> resp = readSentence(in);

        if (resp.isEmpty()) {
            return false;
        }

        Map<String, String> first = resp.get(0);
        if ("!done".equals(first.get("!word"))) {
            // Check challenge for older RouterOS (<6.43)
            String ret = first.get("ret");
            if (ret != null && !ret.isEmpty()) {
                // Challenge-response mode
                byte[] challenge = hexStringToByteArray(ret);
                MessageDigest md5 = MessageDigest.getInstance("MD5");
                md5.update((byte) 0);
                md5.update(password.getBytes("UTF-8"));
                md5.update(challenge);
                byte[] hash = md5.digest();
                String hexHash = "00" + bytesToHex(hash);

                writeSentence(out, "/login", "=name=" + username, "=response=" + hexHash);
                List<Map<String, String>> resp2 = readSentence(in);
                return !resp2.isEmpty() && "!done".equals(resp2.get(0).get("!word"));
            }
            return true;
        }

        return false;
    }

    private void writeSentence(OutputStream out, String... words) throws Exception {
        for (String w : words) {
            writeWord(out, w);
        }
        out.write(0); // empty word marks end of sentence
        out.flush();
    }

    private void writeWord(OutputStream out, String word) throws Exception {
        byte[] bytes = word.getBytes("UTF-8");
        int len = bytes.length;

        if (len < 0x80) {
            out.write((byte) len);
        } else if (len < 0x4000) {
            len |= 0x8000;
            out.write((byte) ((len >> 8) & 0xFF));
            out.write((byte) (len & 0xFF));
        } else if (len < 0x200000) {
            len |= 0xC00000;
            out.write((byte) ((len >> 16) & 0xFF));
            out.write((byte) ((len >> 8) & 0xFF));
            out.write((byte) (len & 0xFF));
        } else if (len < 0x10000000) {
            len |= 0xE0000000;
            out.write((byte) ((len >> 24) & 0xFF));
            out.write((byte) ((len >> 16) & 0xFF));
            out.write((byte) ((len >> 8) & 0xFF));
            out.write((byte) (len & 0xFF));
        } else {
            out.write((byte) 0xF0);
            out.write((byte) ((len >> 24) & 0xFF));
            out.write((byte) ((len >> 16) & 0xFF));
            out.write((byte) ((len >> 8) & 0xFF));
            out.write((byte) (len & 0xFF));
        }

        out.write(bytes);
    }

    private List<Map<String, String>> readSentence(InputStream in) throws Exception {
        List<Map<String, String>> list = new ArrayList<>();
        Map<String, String> current = new HashMap<>();

        while (true) {
            String word = readWord(in);
            if (word == null) break;

            if (word.isEmpty()) {
                // End of sentence
                if (!current.isEmpty()) {
                    list.add(new HashMap<>(current));
                    if ("!done".equals(current.get("!word")) || "!trap".equals(current.get("!word")) || "!fatal".equals(current.get("!word"))) {
                        break;
                    }
                    current.clear();
                }
            } else if (word.startsWith("!")) {
                current.put("!word", word);
            } else if (word.startsWith("=")) {
                int eq2 = word.indexOf('=', 1);
                if (eq2 != -1) {
                    String k = word.substring(1, eq2);
                    String v = word.substring(eq2 + 1);
                    current.put(k, v);
                } else {
                    current.put(word.substring(1), "");
                }
            }
        }

        return list;
    }

    private String readWord(InputStream in) throws Exception {
        int b = in.read();
        if (b == -1) return null;

        int len = 0;
        if ((b & 0x80) == 0) {
            len = b;
        } else if ((b & 0xC0) == 0x80) {
            len = ((b & ~0xC0) << 8) | in.read();
        } else if ((b & 0xE0) == 0xC0) {
            len = ((b & ~0xE0) << 16) | (in.read() << 8) | in.read();
        } else if ((b & 0xF0) == 0xE0) {
            len = ((b & ~0xF0) << 24) | (in.read() << 16) | (in.read() << 8) | in.read();
        } else if ((b & 0xF8) == 0xF0) {
            len = (in.read() << 24) | (in.read() << 16) | (in.read() << 8) | in.read();
        }

        if (len == 0) return "";

        byte[] buf = new byte[len];
        int read = 0;
        while (read < len) {
            int r = in.read(buf, read, len - read);
            if (r == -1) break;
            read += r;
        }

        return new String(buf, 0, read, "UTF-8");
    }

    private static String formatSpeed(double bps) {
        if (bps >= 1000000000) {
            return String.format(Locale.US, "%.2f Gbps", bps / 1000000000.0);
        } else if (bps >= 1000000) {
            return String.format(Locale.US, "%.1f Mbps", bps / 1000000.0);
        } else if (bps >= 1000) {
            return String.format(Locale.US, "%.0f Kbps", bps / 1000.0);
        } else {
            return String.format(Locale.US, "%.0f bps", bps);
        }
    }

    private static byte[] hexStringToByteArray(String s) {
        int len = s.length();
        byte[] data = new byte[len / 2];
        for (int i = 0; i < len; i += 2) {
            data[i / 2] = (byte) ((Character.digit(s.charAt(i), 16) << 4)
                    + Character.digit(s.charAt(i + 1), 16));
        }
        return data;
    }

    private static String bytesToHex(byte[] bytes) {
        StringBuilder sb = new StringBuilder();
        for (byte b : bytes) {
            sb.append(String.format("%02x", b));
        }
        return sb.toString();
    }

    private static void closeQuietly(Socket socket) {
        if (socket != null) {
            try {
                socket.close();
            } catch (Exception ignored) {
            }
        }
    }
}

