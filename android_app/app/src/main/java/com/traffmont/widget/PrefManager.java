package com.traffmont.widget;

import android.content.Context;
import android.content.SharedPreferences;

import org.json.JSONArray;
import org.json.JSONObject;

import java.util.ArrayList;
import java.util.List;

public class PrefManager {
    private static final String PREF_NAME = "traffmont_prefs";

    public static final String MODE_DIRECT_MIKROTIK = "direct";
    public static final String MODE_SERVER_WEB = "server";

    private static final String KEY_CONN_MODE = "conn_mode";
    private static final String KEY_SERVER_URL = "server_url";

    // Direct MikroTik credentials
    private static final String KEY_MIKROTIK_HOST = "mikrotik_host";
    private static final String KEY_MIKROTIK_PORT = "mikrotik_port";
    private static final String KEY_MIKROTIK_USER = "mikrotik_user";
    private static final String KEY_MIKROTIK_PASS = "mikrotik_pass";

    private static final String KEY_INTERFACE_ID = "interface_id";
    private static final String KEY_INTERFACE_NAME = "interface_name";
    private static final String KEY_ROUTER_NAME = "router_name";
    private static final String KEY_REFRESH_INTERVAL = "refresh_interval";
    private static final String KEY_SOUND_ALERT = "sound_alert";
    private static final String KEY_IS_FLOATING_RUNNING = "is_floating_running";

    // Multi-Server Profile Storage
    private static final String KEY_ROUTER_PROFILES_JSON = "router_profiles_json";
    private static final String KEY_ACTIVE_PROFILE_ID = "active_profile_id";

    // Per-Widget Configuration Prefixes
    private static final String PREF_WIDGET_PROFILE_PREFIX = "widget_profile_";
    private static final String PREF_WIDGET_IFACE_PREFIX = "widget_iface_";

    // Last known telemetry cache
    private static final String KEY_LAST_STATUS = "last_status";
    private static final String KEY_LAST_RX = "last_rx";
    private static final String KEY_LAST_TX = "last_tx";
    private static final String KEY_LAST_LATENCY = "last_latency";
    private static final String KEY_LAST_SLA = "last_sla";
    private static final String KEY_LAST_UPDATED = "last_updated";

    private final SharedPreferences prefs;

    public PrefManager(Context context) {
        this.prefs = context.getSharedPreferences(PREF_NAME, Context.MODE_PRIVATE);
    }

    // --- Router Profile Class ---
    public static class RouterProfile {
        public String id;
        public String name;
        public String label; // Short badge label (e.g. "LANGSAT", "PERANAP")
        public String mode; // MODE_DIRECT_MIKROTIK or MODE_SERVER_WEB
        public String host;
        public int port;
        public String user;
        public String pass;
        public String interfaceName;
        public int interfaceId;

        public RouterProfile() {
            this.id = String.valueOf(System.currentTimeMillis());
            this.name = "mns-LangsatHulu";
            this.label = "LANGSAT";
            this.mode = MODE_DIRECT_MIKROTIK;
            this.host = "103.146.38.149";
            this.port = 8484;
            this.user = "zk";
            this.pass = "";
            this.interfaceName = "sfp-sfpplus1";
            this.interfaceId = 0;
        }

        public String getBadge() {
            if (label != null && !label.trim().isEmpty()) {
                return label.trim().toUpperCase(java.util.Locale.ROOT);
            }
            if (name != null && !name.trim().isEmpty()) {
                String clean = name.trim();
                if (clean.toLowerCase().startsWith("mns-")) clean = clean.substring(4);
                if (clean.length() > 10) clean = clean.substring(0, 10);
                return clean.toUpperCase(java.util.Locale.ROOT);
            }
            return "SERVER";
        }

        public JSONObject toJson() {
            JSONObject obj = new JSONObject();
            try {
                obj.put("id", id);
                obj.put("name", name);
                obj.put("label", label);
                obj.put("mode", mode);
                obj.put("host", host);
                obj.put("port", port);
                obj.put("user", user);
                obj.put("pass", pass);
                obj.put("interfaceName", interfaceName);
                obj.put("interfaceId", interfaceId);
            } catch (Exception ignored) {
            }
            return obj;
        }

        public static RouterProfile fromJson(JSONObject obj) {
            RouterProfile p = new RouterProfile();
            if (obj == null) return p;
            p.id = obj.optString("id", String.valueOf(System.currentTimeMillis()));
            p.name = obj.optString("name", "Router");
            p.label = obj.optString("label", "");
            p.mode = obj.optString("mode", MODE_DIRECT_MIKROTIK);
            p.host = obj.optString("host", "103.146.38.149");
            p.port = obj.optInt("port", 8484);
            p.user = obj.optString("user", "zk");
            p.pass = obj.optString("pass", "");
            p.interfaceName = obj.optString("interfaceName", "ether1");
            p.interfaceId = obj.optInt("interfaceId", 0);
            return p;
        }

        @Override
        public String toString() {
            String badge = getBadge();
            if (MODE_DIRECT_MIKROTIK.equals(mode)) {
                return "[" + badge + "] " + name + " (" + host + ":" + port + ")";
            } else {
                return "[" + badge + "] " + name + " (" + host + ")";
            }
        }
    }

    public List<RouterProfile> getProfiles() {
        List<RouterProfile> list = new ArrayList<>();
        String jsonStr = prefs.getString(KEY_ROUTER_PROFILES_JSON, null);
        if (jsonStr != null && !jsonStr.trim().isEmpty()) {
            try {
                JSONArray arr = new JSONArray(jsonStr);
                for (int i = 0; i < arr.length(); i++) {
                    list.add(RouterProfile.fromJson(arr.getJSONObject(i)));
                }
            } catch (Exception ignored) {
            }
        }

        if (list.isEmpty()) {
            // Seed with existing values
            RouterProfile def = new RouterProfile();
            def.name = getRouterName().isEmpty() ? "MikroTik Utama" : getRouterName();
            def.mode = getConnectionMode();
            def.host = getMikrotikHost().isEmpty() ? "103.146.38.149" : getMikrotikHost();
            def.port = getMikrotikPort() <= 0 ? 8484 : getMikrotikPort();
            def.user = getMikrotikUser();
            def.pass = getMikrotikPass();
            def.interfaceName = getInterfaceName();
            def.interfaceId = getInterfaceId();
            list.add(def);
            saveProfiles(list);
            setActiveProfileId(def.id);
        }

        return list;
    }

    public void saveProfiles(List<RouterProfile> list) {
        JSONArray arr = new JSONArray();
        for (RouterProfile p : list) {
            arr.put(p.toJson());
        }
        prefs.edit().putString(KEY_ROUTER_PROFILES_JSON, arr.toString()).apply();
    }

    public RouterProfile getActiveProfile() {
        String activeId = prefs.getString(KEY_ACTIVE_PROFILE_ID, null);
        List<RouterProfile> list = getProfiles();
        if (activeId != null) {
            for (RouterProfile p : list) {
                if (activeId.equals(p.id)) return p;
            }
        }
        return list.get(0);
    }

    public void setActiveProfileId(String id) {
        prefs.edit().putString(KEY_ACTIVE_PROFILE_ID, id).apply();
    }

    public RouterProfile getProfileById(String id) {
        if (id == null) return null;
        for (RouterProfile p : getProfiles()) {
            if (id.equals(p.id)) return p;
        }
        return null;
    }

    public void saveOrUpdateProfile(RouterProfile profile) {
        List<RouterProfile> list = getProfiles();
        boolean found = false;
        for (int i = 0; i < list.size(); i++) {
            if (list.get(i).id.equals(profile.id)) {
                list.set(i, profile);
                found = true;
                break;
            }
        }
        if (!found) {
            list.add(profile);
        }
        saveProfiles(list);
        setActiveProfileId(profile.id);

        // Sync legacy fields
        setConnectionMode(profile.mode);
        if (MODE_DIRECT_MIKROTIK.equals(profile.mode)) {
            setMikrotikHost(profile.host);
            setMikrotikPort(profile.port);
            setMikrotikUser(profile.user);
            setMikrotikPass(profile.pass);
        } else {
            setServerUrl(profile.host);
        }
        setRouterName(profile.name);
        setInterfaceName(profile.interfaceName);
        setInterfaceId(profile.interfaceId);
    }

    public void deleteProfile(String id) {
        List<RouterProfile> list = getProfiles();
        if (list.size() <= 1) return; // Keep at least one profile
        for (int i = 0; i < list.size(); i++) {
            if (list.get(i).id.equals(id)) {
                list.remove(i);
                break;
            }
        }
        saveProfiles(list);
        setActiveProfileId(list.get(0).id);
    }

    // --- Per-Widget Configuration Helpers ---
    private static final String PREF_WIDGET_LABEL_PREFIX = "widget_label_";

    public void setWidgetConfig(int appWidgetId, String profileId, String interfaceName, String label) {
        prefs.edit()
                .putString(PREF_WIDGET_PROFILE_PREFIX + appWidgetId, profileId)
                .putString(PREF_WIDGET_IFACE_PREFIX + appWidgetId, interfaceName)
                .putString(PREF_WIDGET_LABEL_PREFIX + appWidgetId, label)
                .apply();
    }

    public void setWidgetConfig(int appWidgetId, String profileId, String interfaceName) {
        setWidgetConfig(appWidgetId, profileId, interfaceName, null);
    }

    public String getWidgetProfileId(int appWidgetId) {
        return prefs.getString(PREF_WIDGET_PROFILE_PREFIX + appWidgetId, null);
    }

    public String getWidgetInterfaceName(int appWidgetId) {
        return prefs.getString(PREF_WIDGET_IFACE_PREFIX + appWidgetId, null);
    }

    public String getWidgetLabel(int appWidgetId) {
        return prefs.getString(PREF_WIDGET_LABEL_PREFIX + appWidgetId, null);
    }

    public void removeWidgetConfig(int appWidgetId) {
        prefs.edit()
                .remove(PREF_WIDGET_PROFILE_PREFIX + appWidgetId)
                .remove(PREF_WIDGET_IFACE_PREFIX + appWidgetId)
                .remove(PREF_WIDGET_LABEL_PREFIX + appWidgetId)
                .apply();
    }

    // --- Standard Getters & Setters ---
    public String getConnectionMode() {
        return prefs.getString(KEY_CONN_MODE, MODE_DIRECT_MIKROTIK);
    }

    public void setConnectionMode(String mode) {
        prefs.edit().putString(KEY_CONN_MODE, mode).apply();
    }

    public String getMikrotikHost() {
        return prefs.getString(KEY_MIKROTIK_HOST, "103.146.38.149");
    }

    public void setMikrotikHost(String host) {
        prefs.edit().putString(KEY_MIKROTIK_HOST, host != null ? host.trim() : "").apply();
    }

    public int getMikrotikPort() {
        return prefs.getInt(KEY_MIKROTIK_PORT, 8484);
    }

    public void setMikrotikPort(int port) {
        prefs.edit().putInt(KEY_MIKROTIK_PORT, port > 0 ? port : 8484).apply();
    }

    public String getMikrotikUser() {
        return prefs.getString(KEY_MIKROTIK_USER, "zk");
    }

    public void setMikrotikUser(String user) {
        prefs.edit().putString(KEY_MIKROTIK_USER, user != null ? user.trim() : "").apply();
    }

    public String getMikrotikPass() {
        return prefs.getString(KEY_MIKROTIK_PASS, "");
    }

    public void setMikrotikPass(String pass) {
        prefs.edit().putString(KEY_MIKROTIK_PASS, pass != null ? pass : "").apply();
    }

    public String getServerUrl() {
        return prefs.getString(KEY_SERVER_URL, "http://10.0.2.2/traffmont");
    }

    public void setServerUrl(String url) {
        prefs.edit().putString(KEY_SERVER_URL, url != null ? url.trim() : "").apply();
    }

    public int getInterfaceId() {
        return prefs.getInt(KEY_INTERFACE_ID, 0);
    }

    public void setInterfaceId(int id) {
        prefs.edit().putInt(KEY_INTERFACE_ID, id).apply();
    }

    public String getInterfaceName() {
        return prefs.getString(KEY_INTERFACE_NAME, "ether1");
    }

    public void setInterfaceName(String name) {
        prefs.edit().putString(KEY_INTERFACE_NAME, name).apply();
    }

    public String getRouterName() {
        return prefs.getString(KEY_ROUTER_NAME, "MikroTik Utama");
    }

    public void setRouterName(String name) {
        prefs.edit().putString(KEY_ROUTER_NAME, name).apply();
    }

    public int getRefreshInterval() {
        return prefs.getInt(KEY_REFRESH_INTERVAL, 3);
    }

    public void setRefreshInterval(int seconds) {
        prefs.edit().putInt(KEY_REFRESH_INTERVAL, seconds).apply();
    }

    public boolean isSoundAlertEnabled() {
        return prefs.getBoolean(KEY_SOUND_ALERT, true);
    }

    public void setSoundAlertEnabled(boolean enabled) {
        prefs.edit().putBoolean(KEY_SOUND_ALERT, enabled).apply();
    }

    public boolean isFloatingRunning() {
        return prefs.getBoolean(KEY_IS_FLOATING_RUNNING, false);
    }

    public void setFloatingRunning(boolean running) {
        prefs.edit().putBoolean(KEY_IS_FLOATING_RUNNING, running).apply();
    }

    // Cache Telemetry
    private static final String KEY_PEAK_RX = "peak_rx_bps";
    private static final String KEY_PEAK_TX = "peak_tx_bps";
    private static final String KEY_WIDGET_HIST_RX = "hist_rx_";
    private static final String KEY_WIDGET_HIST_TX = "hist_tx_";

    public void saveTelemetry(String status, String rx, String tx, int latency, double sla, String updated, double rxBps, double txBps) {
        float curPeakRx = prefs.getFloat(KEY_PEAK_RX, 0f);
        float curPeakTx = prefs.getFloat(KEY_PEAK_TX, 0f);
        if (rxBps > curPeakRx) curPeakRx = (float) rxBps;
        if (txBps > curPeakTx) curPeakTx = (float) txBps;

        prefs.edit()
                .putString(KEY_LAST_STATUS, status)
                .putString(KEY_LAST_RX, rx)
                .putString(KEY_LAST_TX, tx)
                .putInt(KEY_LAST_LATENCY, latency)
                .putFloat(KEY_LAST_SLA, (float) sla)
                .putString(KEY_LAST_UPDATED, updated)
                .putFloat(KEY_PEAK_RX, curPeakRx)
                .putFloat(KEY_PEAK_TX, curPeakTx)
                .apply();
    }

    public void saveTelemetry(String status, String rx, String tx, int latency, double sla, String updated) {
        saveTelemetry(status, rx, tx, latency, sla, updated, 0, 0);
    }

    public float getPeakRxBps() {
        return prefs.getFloat(KEY_PEAK_RX, 0f);
    }

    public float getPeakTxBps() {
        return prefs.getFloat(KEY_PEAK_TX, 0f);
    }

    public void appendWidgetHistory(int widgetId, double rxBps, double txBps) {
        List<Double> rxList = getWidgetHistory(KEY_WIDGET_HIST_RX + widgetId);
        List<Double> txList = getWidgetHistory(KEY_WIDGET_HIST_TX + widgetId);

        rxList.add(Math.max(0, rxBps));
        if (rxList.size() > 30) rxList.remove(0);

        txList.add(Math.max(0, txBps));
        if (txList.size() > 30) txList.remove(0);

        saveWidgetHistory(KEY_WIDGET_HIST_RX + widgetId, rxList);
        saveWidgetHistory(KEY_WIDGET_HIST_TX + widgetId, txList);
    }

    public List<Double> getWidgetHistoryRx(int widgetId) {
        return getWidgetHistory(KEY_WIDGET_HIST_RX + widgetId);
    }

    public List<Double> getWidgetHistoryTx(int widgetId) {
        return getWidgetHistory(KEY_WIDGET_HIST_TX + widgetId);
    }

    private List<Double> getWidgetHistory(String key) {
        List<Double> list = new ArrayList<>();
        String raw = prefs.getString(key, "");
        if (raw != null && !raw.trim().isEmpty()) {
            for (String s : raw.split(",")) {
                try {
                    list.add(Double.parseDouble(s));
                } catch (Exception ignored) {
                }
            }
        }
        if (list.size() < 2) {
            for (int i = 0; i < 20; i++) list.add(0.0);
        }
        return list;
    }

    private void saveWidgetHistory(String key, List<Double> list) {
        StringBuilder sb = new StringBuilder();
        for (int i = 0; i < list.size(); i++) {
            if (i > 0) sb.append(",");
            sb.append(list.get(i));
        }
        prefs.edit().putString(key, sb.toString()).apply();
    }

    public String getLastStatus() {
        return prefs.getString(KEY_LAST_STATUS, "UP");
    }

    public String getLastRx() {
        return prefs.getString(KEY_LAST_RX, "0.0 Mbps");
    }

    public String getLastTx() {
        return prefs.getString(KEY_LAST_TX, "0.0 Mbps");
    }

    public int getLastLatency() {
        return prefs.getInt(KEY_LAST_LATENCY, 10);
    }

    public float getLastSla() {
        return prefs.getFloat(KEY_LAST_SLA, 99.5f);
    }

    public String getLastUpdated() {
        return prefs.getString(KEY_LAST_UPDATED, "Menunggu data...");
    }
}
