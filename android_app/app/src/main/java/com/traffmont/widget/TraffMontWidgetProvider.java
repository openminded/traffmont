package com.traffmont.widget;

import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.appwidget.AppWidgetProvider;
import android.content.ComponentName;
import android.content.Context;
import android.content.Intent;
import android.os.Build;
import android.widget.RemoteViews;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.List;
import java.util.Locale;

public class TraffMontWidgetProvider extends AppWidgetProvider {

    public static final String ACTION_REFRESH_WIDGET = "com.traffmont.widget.ACTION_REFRESH_WIDGET";

    @Override
    public void onUpdate(Context context, AppWidgetManager appWidgetManager, int[] appWidgetIds) {
        for (int appWidgetId : appWidgetIds) {
            updateAppWidget(context, appWidgetManager, appWidgetId);
        }
    }

    @Override
    public void onReceive(Context context, Intent intent) {
        super.onReceive(context, intent);

        int pinnedWidgetId = intent.getIntExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID);
        String pinnedProfileId = intent.getStringExtra("profile_id");
        if (pinnedWidgetId != AppWidgetManager.INVALID_APPWIDGET_ID && pinnedProfileId != null) {
            PrefManager prefs = new PrefManager(context);
            String pinnedIface = intent.getStringExtra("interface_name");
            String pinnedLabel = intent.getStringExtra("server_label");
            prefs.setWidgetConfig(pinnedWidgetId, pinnedProfileId, pinnedIface, pinnedLabel);
            AppWidgetManager appWidgetManager = AppWidgetManager.getInstance(context);
            updateAppWidget(context, appWidgetManager, pinnedWidgetId);
        }

        if (ACTION_REFRESH_WIDGET.equals(intent.getAction())) {
            AppWidgetManager appWidgetManager = AppWidgetManager.getInstance(context);
            int widgetId = intent.getIntExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, AppWidgetManager.INVALID_APPWIDGET_ID);
            if (widgetId != AppWidgetManager.INVALID_APPWIDGET_ID) {
                refreshAllWidgetsAsync(context, appWidgetManager, new int[]{widgetId});
            } else {
                ComponentName thisWidget = new ComponentName(context, TraffMontWidgetProvider.class);
                int[] allWidgetIds = appWidgetManager.getAppWidgetIds(thisWidget);
                refreshAllWidgetsAsync(context, appWidgetManager, allWidgetIds);
            }
        }
    }

    @Override
    public void onDeleted(Context context, int[] appWidgetIds) {
        super.onDeleted(context, appWidgetIds);
        PrefManager prefs = new PrefManager(context);
        for (int id : appWidgetIds) {
            prefs.removeWidgetConfig(id);
        }
    }

    public static void updateAppWidget(Context context, AppWidgetManager appWidgetManager, int appWidgetId) {
        PrefManager prefs = new PrefManager(context);
        RemoteViews views = new RemoteViews(context.getPackageName(), R.layout.widget_traffmont);

        String profileId = prefs.getWidgetProfileId(appWidgetId);
        PrefManager.RouterProfile profile = profileId != null ? prefs.getProfileById(profileId) : prefs.getActiveProfile();
        if (profile == null) profile = prefs.getActiveProfile();

        if (profileId == null && profile != null) {
            prefs.setWidgetConfig(appWidgetId, profile.id, profile.interfaceName, profile.getBadge());
        }

        String ifaceName = prefs.getWidgetInterfaceName(appWidgetId);
        if (ifaceName == null || ifaceName.isEmpty()) {
            ifaceName = profile.interfaceName;
        }

        String customLabel = prefs.getWidgetLabel(appWidgetId);
        String badge = (customLabel != null && !customLabel.trim().isEmpty()) ? customLabel.trim().toUpperCase(Locale.ROOT) : profile.getBadge();

        views.setTextViewText(R.id.widget_txt_server_badge, badge);
        views.setTextViewText(R.id.widget_txt_router_name, profile.name);
        views.setTextViewText(R.id.widget_txt_iface_name, ifaceName);
        views.setTextViewText(R.id.widget_txt_host_sub, "• " + profile.host);

        String cachedRx = prefs.getLastRx();
        if (cachedRx == null || cachedRx.trim().isEmpty()) cachedRx = "0.00 bps";
        String cachedTx = prefs.getLastTx();
        if (cachedTx == null || cachedTx.trim().isEmpty()) cachedTx = "0.00 bps";

        views.setTextViewText(R.id.widget_txt_rx, cachedRx);
        views.setTextViewText(R.id.widget_txt_tx, cachedTx);
        views.setTextViewText(R.id.widget_txt_rx_peak, "Peak: " + TrafficGraphView.formatSpeed(prefs.getPeakRxBps()));
        views.setTextViewText(R.id.widget_txt_tx_peak, "Peak: " + TrafficGraphView.formatSpeed(prefs.getPeakTxBps()));
        views.setTextViewText(R.id.widget_txt_latency_sla, String.format(Locale.getDefault(), "⚡ %d ms  •  🎯 %.1f%% SLA", prefs.getLastLatency(), prefs.getLastSla()));
        views.setTextViewText(R.id.widget_txt_updated, prefs.getLastUpdated());

        String status = prefs.getLastStatus();
        boolean isDown = !"UP".equalsIgnoreCase(status);
        if (isDown) {
            views.setTextViewText(R.id.widget_txt_status, "DOWN");
            views.setTextColor(R.id.widget_txt_status, 0xFFFCA5A5);
            views.setInt(R.id.widget_txt_status, "setBackgroundResource", R.drawable.bg_status_down);

            views.setInt(R.id.widget_root, "setBackgroundResource", R.drawable.bg_card_down);
            views.setTextColor(R.id.widget_txt_iface_name, 0xFFEF4444);
            views.setTextColor(R.id.widget_txt_server_badge, 0xFFEF4444);
            views.setInt(R.id.widget_txt_server_badge, "setBackgroundResource", R.drawable.bg_server_badge_down);

            views.setTextColor(R.id.widget_txt_rx, 0xFFEF4444);
            views.setTextColor(R.id.widget_txt_tx, 0xFFEF4444);
        } else {
            views.setTextViewText(R.id.widget_txt_status, "UP");
            views.setTextColor(R.id.widget_txt_status, 0xFF10B981);
            views.setInt(R.id.widget_txt_status, "setBackgroundResource", R.drawable.bg_status_up);

            views.setInt(R.id.widget_root, "setBackgroundResource", R.drawable.bg_card_rounded);
            views.setTextColor(R.id.widget_txt_iface_name, 0xFF38BDF8);
            views.setTextColor(R.id.widget_txt_server_badge, 0xFF38BDF8);
            views.setInt(R.id.widget_txt_server_badge, "setBackgroundResource", R.drawable.bg_server_badge);

            views.setTextColor(R.id.widget_txt_rx, 0xFF38BDF8);
            views.setTextColor(R.id.widget_txt_tx, 0xFFC084FC);
        }

        List<Double> initRx = prefs.getWidgetHistoryRx(appWidgetId);
        List<Double> initTx = prefs.getWidgetHistoryTx(appWidgetId);
        android.graphics.Bitmap initBmp = TrafficGraphView.generateGraphBitmap(360, 80, initRx, initTx, isDown);
        views.setImageViewBitmap(R.id.widget_img_graph, initBmp);

        // Click Intent for whole widget -> Open MainActivity
        Intent mainIntent = new Intent(context, MainActivity.class);
        PendingIntent pendingMain = PendingIntent.getActivity(
                context, appWidgetId, mainIntent,
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE : PendingIntent.FLAG_UPDATE_CURRENT
        );
        views.setOnClickPendingIntent(R.id.widget_root, pendingMain);

        // Click Intent for Refresh Button -> Refresh Data
        Intent refreshIntent = new Intent(context, TraffMontWidgetProvider.class);
        refreshIntent.setAction(ACTION_REFRESH_WIDGET);
        refreshIntent.putExtra(AppWidgetManager.EXTRA_APPWIDGET_ID, appWidgetId);
        PendingIntent pendingRefresh = PendingIntent.getBroadcast(
                context, appWidgetId, refreshIntent,
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE : PendingIntent.FLAG_UPDATE_CURRENT
        );
        views.setOnClickPendingIntent(R.id.widget_btn_refresh, pendingRefresh);

        appWidgetManager.updateAppWidget(appWidgetId, views);

        // Trigger network fetch in background
        refreshAllWidgetsAsync(context, appWidgetManager, new int[]{appWidgetId});
    }

    private static void refreshAllWidgetsAsync(Context context, AppWidgetManager appWidgetManager, int[] appWidgetIds) {
        new Thread(() -> {
            PrefManager prefs = new PrefManager(context);
            for (int appWidgetId : appWidgetIds) {
                String profileId = prefs.getWidgetProfileId(appWidgetId);
                PrefManager.RouterProfile profile = profileId != null ? prefs.getProfileById(profileId) : prefs.getActiveProfile();
                if (profile == null) profile = prefs.getActiveProfile();

                String ifaceName = prefs.getWidgetInterfaceName(appWidgetId);
                if (ifaceName == null || ifaceName.isEmpty()) {
                    ifaceName = profile.interfaceName;
                }

                ApiClient.WidgetResponse resp = ApiClient.fetchTelemetryForProfile(profile, ifaceName);
                if (!resp.success || resp.interfaces.isEmpty()) {
                    continue;
                }

                ApiClient.InterfaceItem selected = resp.interfaces.get(0);
                String timeStr = new SimpleDateFormat("HH:mm:ss", Locale.getDefault()).format(new Date());

                String customLabel = prefs.getWidgetLabel(appWidgetId);
                String badge = (customLabel != null && !customLabel.trim().isEmpty()) ? customLabel.trim().toUpperCase(Locale.ROOT) : profile.getBadge();

                RemoteViews views = new RemoteViews(context.getPackageName(), R.layout.widget_traffmont);
                views.setTextViewText(R.id.widget_txt_server_badge, badge);
                views.setTextViewText(R.id.widget_txt_router_name, profile.name);
                views.setTextViewText(R.id.widget_txt_iface_name, selected.displayName != null ? selected.displayName : ifaceName);
                views.setTextViewText(R.id.widget_txt_host_sub, "• " + profile.host);

                String rxStr = selected.rxFormatted;
                if (rxStr == null || rxStr.trim().isEmpty() || ("0.0 Mbps".equals(rxStr) && selected.rxBps > 0)) {
                    rxStr = TrafficGraphView.formatSpeed(selected.rxBps);
                }
                if (rxStr == null || rxStr.trim().isEmpty()) {
                    rxStr = "0.00 bps";
                }

                String txStr = selected.txFormatted;
                if (txStr == null || txStr.trim().isEmpty() || ("0.0 Mbps".equals(txStr) && selected.txBps > 0)) {
                    txStr = TrafficGraphView.formatSpeed(selected.txBps);
                }
                if (txStr == null || txStr.trim().isEmpty()) {
                    txStr = "0.00 bps";
                }

                views.setTextViewText(R.id.widget_txt_rx, rxStr);
                views.setTextViewText(R.id.widget_txt_tx, txStr);

                prefs.appendWidgetHistory(appWidgetId, selected.rxBps, selected.txBps);
                List<Double> rxHist = prefs.getWidgetHistoryRx(appWidgetId);
                List<Double> txHist = prefs.getWidgetHistoryTx(appWidgetId);
                boolean isDown = !"UP".equalsIgnoreCase(selected.currentStatus);
                if (isDown) {
                    views.setTextViewText(R.id.widget_txt_status, "DOWN");
                    views.setTextColor(R.id.widget_txt_status, 0xFFFCA5A5);
                    views.setInt(R.id.widget_txt_status, "setBackgroundResource", R.drawable.bg_status_down);

                    views.setInt(R.id.widget_root, "setBackgroundResource", R.drawable.bg_card_down);
                    views.setTextColor(R.id.widget_txt_iface_name, 0xFFEF4444);
                    views.setTextColor(R.id.widget_txt_server_badge, 0xFFEF4444);
                    views.setInt(R.id.widget_txt_server_badge, "setBackgroundResource", R.drawable.bg_server_badge_down);

                    views.setTextColor(R.id.widget_txt_rx, 0xFFEF4444);
                    views.setTextColor(R.id.widget_txt_tx, 0xFFEF4444);
                } else {
                    views.setTextViewText(R.id.widget_txt_status, "UP");
                    views.setTextColor(R.id.widget_txt_status, 0xFF10B981);
                    views.setInt(R.id.widget_txt_status, "setBackgroundResource", R.drawable.bg_status_up);

                    views.setInt(R.id.widget_root, "setBackgroundResource", R.drawable.bg_card_rounded);
                    views.setTextColor(R.id.widget_txt_iface_name, 0xFF38BDF8);
                    views.setTextColor(R.id.widget_txt_server_badge, 0xFF38BDF8);
                    views.setInt(R.id.widget_txt_server_badge, "setBackgroundResource", R.drawable.bg_server_badge);

                    views.setTextColor(R.id.widget_txt_rx, 0xFF38BDF8);
                    views.setTextColor(R.id.widget_txt_tx, 0xFFC084FC);
                }

                android.graphics.Bitmap graphBmp = TrafficGraphView.generateGraphBitmap(360, 80, rxHist, txHist, isDown);
                views.setImageViewBitmap(R.id.widget_img_graph, graphBmp);

                appWidgetManager.updateAppWidget(appWidgetId, views);
            }
        }).start();
    }
}
