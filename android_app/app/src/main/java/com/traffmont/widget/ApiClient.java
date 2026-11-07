package com.traffmont.widget;

import org.json.JSONArray;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.InputStream;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.text.SimpleDateFormat;
import java.util.ArrayList;
import java.util.Date;
import java.util.List;
import java.util.Locale;

public class ApiClient {

    public static class InterfaceItem {
        public int id;
        public int routerId;
        public String routerName;
        public String interfaceName;
        public String aliasName;
        public String displayName;
        public String currentStatus;
        public boolean isUp;
        public double rxBps;
        public double txBps;
        public String rxFormatted;
        public String txFormatted;
        public int latencyMs;
        public double targetSla;
        public double monthlyUptime;
        public String lastCheckedAt;

        @Override
        public String toString() {
            return displayName != null ? displayName : interfaceName;
        }
    }

    public static class WidgetResponse {
        public boolean success;
        public String errorMessage;
        public String serverTime;
        public List<InterfaceItem> interfaces = new ArrayList<>();
    }

    /**
     * Unified fetch: automatically determines whether to query direct MikroTik API
     * or TraffMont Web Server based on user preferences.
     */
    public static WidgetResponse fetchTelemetry(PrefManager prefs) {
        PrefManager.RouterProfile active = prefs.getActiveProfile();
        return fetchTelemetryForProfile(active, prefs.getInterfaceName());
    }

    /**
     * Fetches telemetry for a specific RouterProfile and interface.
     */
    public static WidgetResponse fetchTelemetryForProfile(PrefManager.RouterProfile profile, String ifaceName) {
        WidgetResponse resp = new WidgetResponse();
        if (profile == null) {
            resp.success = false;
            resp.errorMessage = "Profil router tidak ditemukan";
            return resp;
        }

        if (PrefManager.MODE_DIRECT_MIKROTIK.equals(profile.mode)) {
            String targetIface = (ifaceName != null && !ifaceName.trim().isEmpty()) ? ifaceName : profile.interfaceName;
            MikrotikDirectClient directClient = new MikrotikDirectClient(profile.host, profile.port, profile.user, profile.pass, 5000);
            MikrotikDirectClient.DirectResult res = directClient.monitorTraffic(targetIface);

            resp.success = res.success;
            resp.errorMessage = res.errorMessage;
            resp.serverTime = new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(new Date());

            if (res.success && res.singleTraffic != null) {
                res.singleTraffic.routerName = profile.name;
                resp.interfaces.add(res.singleTraffic);
            }
            return resp;
        } else {
            return fetchWidgetData(profile.host, profile.interfaceId);
        }
    }

    public static WidgetResponse fetchWidgetData(String baseUrl, int interfaceId) {
        WidgetResponse response = new WidgetResponse();

        if (baseUrl == null || baseUrl.trim().isEmpty()) {
            response.success = false;
            response.errorMessage = "URL Server belum diisi";
            return response;
        }

        HttpURLConnection conn = null;
        try {
            String endpoint = baseUrl.trim();
            if (endpoint.endsWith("/")) {
                endpoint = endpoint.substring(0, endpoint.length() - 1);
            }
            if (!endpoint.endsWith("/api/widget_data.php")) {
                if (endpoint.endsWith("/api")) {
                    endpoint += "/widget_data.php";
                } else {
                    endpoint += "/api/widget_data.php";
                }
            }

            if (interfaceId > 0) {
                endpoint += "?interface_id=" + interfaceId;
            }

            URL url = new URL(endpoint);
            conn = (HttpURLConnection) url.openConnection();
            conn.setRequestMethod("GET");
            conn.setConnectTimeout(5000);
            conn.setReadTimeout(5000);
            conn.setRequestProperty("Accept", "application/json");

            int code = conn.getResponseCode();
            if (code >= 200 && code < 300) {
                InputStream is = conn.getInputStream();
                BufferedReader reader = new BufferedReader(new InputStreamReader(is));
                StringBuilder sb = new StringBuilder();
                String line;
                while ((line = reader.readLine()) != null) {
                    sb.append(line);
                }
                reader.close();

                JSONObject root = new JSONObject(sb.toString());
                response.success = root.optBoolean("success", false);
                response.serverTime = root.optString("server_time", "");

                JSONArray ifacesArr = root.optJSONArray("interfaces");
                if (ifacesArr != null) {
                    for (int i = 0; i < ifacesArr.length(); i++) {
                        JSONObject obj = ifacesArr.getJSONObject(i);
                        InterfaceItem item = new InterfaceItem();
                        item.id = obj.optInt("id", 0);
                        item.routerId = obj.optInt("router_id", 0);
                        item.routerName = obj.optString("router_name", "Router");
                        item.interfaceName = obj.optString("interface_name", "ether1");
                        item.aliasName = obj.optString("alias_name", "");
                        item.displayName = obj.optString("display_name", item.interfaceName);
                        item.currentStatus = obj.optString("current_status", "UNKNOWN");
                        item.isUp = obj.optBoolean("is_up", "UP".equalsIgnoreCase(item.currentStatus));
                        item.rxBps = obj.optDouble("rx_bps", 0);
                        item.txBps = obj.optDouble("tx_bps", 0);
                        item.rxFormatted = obj.optString("rx_formatted", "0.0 Mbps");
                        item.txFormatted = obj.optString("tx_formatted", "0.0 Mbps");
                        item.latencyMs = obj.optInt("latency_ms", 0);
                        item.targetSla = obj.optDouble("target_sla_percent", 99.5);
                        item.monthlyUptime = obj.optDouble("monthly_uptime_percent", 100.0);
                        item.lastCheckedAt = obj.optString("last_checked_at", "");

                        response.interfaces.add(item);
                    }
                }
            } else {
                response.success = false;
                response.errorMessage = "Server HTTP error: " + code;
            }

        } catch (Exception e) {
            response.success = false;
            response.errorMessage = "Gagal terhubung ke server: " + e.getMessage();
        } finally {
            if (conn != null) {
                conn.disconnect();
            }
        }

        return response;
    }

    public static WidgetResponse generateMockData() {
        WidgetResponse resp = new WidgetResponse();
        resp.success = true;
        resp.serverTime = new SimpleDateFormat("yyyy-MM-dd HH:mm:ss", Locale.getDefault()).format(new Date());

        // Interface 1 (ISP Utama)
        InterfaceItem i1 = new InterfaceItem();
        i1.id = 1;
        i1.routerId = 1;
        i1.routerName = "MikroTik-Core";
        i1.interfaceName = "ether1";
        i1.aliasName = "ISP Dedicated";
        i1.displayName = "ether1 (ISP Dedicated)";
        i1.currentStatus = "UP";
        i1.isUp = true;
        double rxM = 24.5 + (Math.random() * 15.0);
        double txM = 4.2 + (Math.random() * 3.0);
        i1.rxFormatted = String.format(Locale.US, "%.1f Mbps", rxM);
        i1.txFormatted = String.format(Locale.US, "%.1f Mbps", txM);
        i1.latencyMs = 12 + (int) (Math.random() * 8);
        i1.targetSla = 99.5;
        i1.monthlyUptime = 99.92;
        i1.lastCheckedAt = resp.serverTime;
        resp.interfaces.add(i1);

        // Interface 2 (Backup)
        InterfaceItem i2 = new InterfaceItem();
        i2.id = 2;
        i2.routerId = 1;
        i2.routerName = "MikroTik-Core";
        i2.interfaceName = "ether2";
        i2.aliasName = "Backup Fiber";
        i2.displayName = "ether2 (Backup Fiber)";
        i2.currentStatus = "UP";
        i2.isUp = true;
        i2.rxFormatted = "10.2 Mbps";
        i2.txFormatted = "2.1 Mbps";
        i2.latencyMs = 18;
        i2.targetSla = 99.0;
        i2.monthlyUptime = 99.50;
        i2.lastCheckedAt = resp.serverTime;
        resp.interfaces.add(i2);

        return resp;
    }
}
