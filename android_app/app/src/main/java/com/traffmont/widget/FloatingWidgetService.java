package com.traffmont.widget;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.app.Service;
import android.content.Context;
import android.content.Intent;
import android.graphics.PixelFormat;
import android.media.Ringtone;
import android.media.RingtoneManager;
import android.net.Uri;
import android.os.Build;
import android.os.Handler;
import android.os.IBinder;
import android.os.Looper;
import android.os.VibrationEffect;
import android.os.Vibrator;
import android.view.Gravity;
import android.view.LayoutInflater;
import android.view.MotionEvent;
import android.view.View;
import android.view.WindowManager;
import android.widget.ImageView;
import android.widget.TextView;
import android.widget.Toast;

import androidx.core.app.NotificationCompat;

import java.text.SimpleDateFormat;
import java.util.Date;
import java.util.Locale;

public class FloatingWidgetService extends Service {

    private static final String CHANNEL_ID = "traffmont_floating_channel";
    private static final int NOTIF_ID = 1001;

    private WindowManager windowManager;
    private View floatingView;
    private WindowManager.LayoutParams params;

    private View collapsedView;
    private View expandedView;

    // Pill Views
    private View pillStatusDot;
    private TextView pillTxtIface;
    private TextView pillTxtRx;
    private TextView pillTxtTx;
    private TextView pillTxtPing;

    // Expanded Views
    private View expStatusDot;
    private TextView expTxtRouter;
    private ImageView btnLock;
    private ImageView btnSettings;
    private ImageView btnMinimize;
    private ImageView btnClose;
    private TextView expTxtRx;
    private TextView expTxtRxPeak;
    private TextView expTxtTx;
    private TextView expTxtTxPeak;
    private TrafficGraphView graphView;
    private TextView expTxtFooter;

    private boolean isLocked = false;
    private PrefManager prefs;
    private final Handler handler = new Handler(Looper.getMainLooper());
    private boolean isRunning = false;
    private String lastStatus = "UP";

    private final Runnable pollRunnable = new Runnable() {
        @Override
        public void run() {
            if (!isRunning) return;
            fetchAndUpdateFloating();
            int interval = Math.max(2, prefs.getRefreshInterval()) * 1000;
            handler.postDelayed(this, interval);
        }
    };

    @Override
    public IBinder onBind(Intent intent) {
        return null;
    }

    @Override
    public void onCreate() {
        super.onCreate();
        prefs = new PrefManager(this);

        createNotificationChannel();
        if (Build.VERSION.SDK_INT >= 34) { // Android 14+
            startForeground(NOTIF_ID, createNotification(), android.content.pm.ServiceInfo.FOREGROUND_SERVICE_TYPE_DATA_SYNC);
        } else {
            startForeground(NOTIF_ID, createNotification());
        }

        windowManager = (WindowManager) getSystemService(WINDOW_SERVICE);
        LayoutInflater inflater = LayoutInflater.from(this);
        floatingView = inflater.inflate(R.layout.layout_floating_widget, null);

        collapsedView = floatingView.findViewById(R.id.floating_collapsed_view);
        expandedView = floatingView.findViewById(R.id.floating_expanded_view);

        // Pill bindings
        pillStatusDot = floatingView.findViewById(R.id.float_pill_status_dot);
        pillTxtIface = floatingView.findViewById(R.id.float_pill_iface);
        pillTxtRx = floatingView.findViewById(R.id.float_pill_rx);
        pillTxtTx = floatingView.findViewById(R.id.float_pill_tx);
        pillTxtPing = floatingView.findViewById(R.id.float_pill_ping);

        // Expanded bindings
        expStatusDot = floatingView.findViewById(R.id.float_exp_status_dot);
        expTxtRouter = floatingView.findViewById(R.id.float_exp_router_name);
        btnLock = floatingView.findViewById(R.id.float_btn_lock);
        btnSettings = floatingView.findViewById(R.id.float_btn_settings);
        btnMinimize = floatingView.findViewById(R.id.float_btn_minimize);
        btnClose = floatingView.findViewById(R.id.float_btn_close);

        expTxtRx = floatingView.findViewById(R.id.float_exp_rx);
        expTxtRxPeak = floatingView.findViewById(R.id.float_exp_rx_peak);
        expTxtTx = floatingView.findViewById(R.id.float_exp_tx);
        expTxtTxPeak = floatingView.findViewById(R.id.float_exp_tx_peak);
        graphView = floatingView.findViewById(R.id.float_graph_view);
        expTxtFooter = floatingView.findViewById(R.id.float_exp_footer);

        // Window Layout Parameters
        int layoutType;
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            layoutType = WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY;
        } else {
            layoutType = WindowManager.LayoutParams.TYPE_PHONE;
        }

        params = new WindowManager.LayoutParams(
                WindowManager.LayoutParams.WRAP_CONTENT,
                WindowManager.LayoutParams.WRAP_CONTENT,
                layoutType,
                WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE,
                PixelFormat.TRANSLUCENT
        );

        params.gravity = Gravity.TOP | Gravity.START;
        params.x = 80;
        params.y = 200;

        try {
            windowManager.addView(floatingView, params);
        } catch (Exception e) {
            stopSelf();
            return;
        }

        setupTouchListener();
        setupActions();

        // Initial Values from Cache
        updateUiValues(
                prefs.getRouterName(),
                prefs.getInterfaceName(),
                prefs.getLastStatus(),
                prefs.getLastRx(),
                prefs.getLastTx(),
                0,
                0,
                prefs.getLastLatency()
        );

        isRunning = true;
        prefs.setFloatingRunning(true);
        handler.post(pollRunnable);
    }

    private void setupTouchListener() {
        View.OnTouchListener touchListener = new View.OnTouchListener() {
            private int initialX;
            private int initialY;
            private float initialTouchX;
            private float initialTouchY;
            private long startClickTime;

            @Override
            public boolean onTouch(View v, MotionEvent event) {
                switch (event.getAction()) {
                    case MotionEvent.ACTION_DOWN:
                        initialX = params.x;
                        initialY = params.y;
                        initialTouchX = event.getRawX();
                        initialTouchY = event.getRawY();
                        startClickTime = System.currentTimeMillis();
                        return true;

                    case MotionEvent.ACTION_MOVE:
                        if (!isLocked) {
                            params.x = initialX + (int) (event.getRawX() - initialTouchX);
                            params.y = initialY + (int) (event.getRawY() - initialTouchY);
                            windowManager.updateViewLayout(floatingView, params);
                        }
                        return true;

                    case MotionEvent.ACTION_UP:
                        int diffX = (int) Math.abs(event.getRawX() - initialTouchX);
                        int diffY = (int) Math.abs(event.getRawY() - initialTouchY);
                        if (diffX < 12 && diffY < 12) {
                            long duration = System.currentTimeMillis() - startClickTime;
                            if (v == collapsedView) {
                                if (duration > 700) {
                                    // Long press on pill -> Switch to next server
                                    cycleNextProfile();
                                } else {
                                    // Single Click -> Toggle Expand
                                    collapsedView.setVisibility(View.GONE);
                                    expandedView.setVisibility(View.VISIBLE);
                                }
                            }
                        }
                        return true;
                }
                return false;
            }
        };

        collapsedView.setOnTouchListener(touchListener);
        expandedView.setOnTouchListener(touchListener);
    }

    private void setupActions() {
        btnLock.setOnClickListener(v -> {
            isLocked = !isLocked;
            if (isLocked) {
                btnLock.setImageResource(R.drawable.ic_lock);
                Toast.makeText(this, "🔒 Posisi widget dikunci", Toast.LENGTH_SHORT).show();
            } else {
                btnLock.setImageResource(R.drawable.ic_lock_open);
                Toast.makeText(this, "🔓 Posisi widget dapat digeser", Toast.LENGTH_SHORT).show();
            }
        });

        btnSettings.setOnClickListener(v -> {
            Intent intent = new Intent(FloatingWidgetService.this, MainActivity.class);
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK);
            startActivity(intent);
        });

        btnMinimize.setOnClickListener(v -> {
            expandedView.setVisibility(View.GONE);
            collapsedView.setVisibility(View.VISIBLE);
        });

        btnClose.setOnClickListener(v -> {
            stopSelf();
        });

        expTxtRouter.setOnClickListener(v -> cycleNextProfile());
    }

    private void cycleNextProfile() {
        java.util.List<PrefManager.RouterProfile> list = prefs.getProfiles();
        if (list.size() <= 1) {
            Toast.makeText(this, "Hanya 1 server tersimpan. Tambah server di aplikasi TraffMont.", Toast.LENGTH_SHORT).show();
            return;
        }

        PrefManager.RouterProfile current = prefs.getActiveProfile();
        int nextIdx = 0;
        for (int i = 0; i < list.size(); i++) {
            if (list.get(i).id.equals(current.id)) {
                nextIdx = (i + 1) % list.size();
                break;
            }
        }
        PrefManager.RouterProfile next = list.get(nextIdx);
        prefs.setActiveProfileId(next.id);
        Toast.makeText(this, "🔄 Beralih ke: " + next.name + " (" + next.interfaceName + ")", Toast.LENGTH_SHORT).show();

        if (graphView != null) graphView.resetPeaks();
        fetchAndUpdateFloating();
    }

    private void fetchAndUpdateFloating() {
        new Thread(() -> {
            ApiClient.WidgetResponse resp = ApiClient.fetchTelemetry(prefs);
            if (!resp.success || resp.interfaces.isEmpty()) {
                return;
            }

            ApiClient.InterfaceItem selected = null;
            int ifaceId = prefs.getInterfaceId();
            String ifaceName = prefs.getInterfaceName();

            for (ApiClient.InterfaceItem item : resp.interfaces) {
                if (ifaceId > 0 && item.id == ifaceId) {
                    selected = item;
                    break;
                } else if (ifaceName != null && ifaceName.equalsIgnoreCase(item.interfaceName)) {
                    selected = item;
                    break;
                }
            }
            if (selected == null && !resp.interfaces.isEmpty()) {
                selected = resp.interfaces.get(0);
            }

            if (selected != null) {
                final ApiClient.InterfaceItem finalItem = selected;
                handler.post(() -> {
                    // Check if transitioned to DOWN -> play alert
                    if ("UP".equalsIgnoreCase(lastStatus) && !"UP".equalsIgnoreCase(finalItem.currentStatus)) {
                        triggerAlertNotification();
                    }
                    lastStatus = finalItem.currentStatus;

                    updateUiValues(
                            finalItem.routerName,
                            finalItem.displayName != null ? finalItem.displayName : finalItem.interfaceName,
                            finalItem.currentStatus,
                            finalItem.rxFormatted,
                            finalItem.txFormatted,
                            finalItem.rxBps,
                            finalItem.txBps,
                            finalItem.latencyMs
                    );

                    String timeStr = new SimpleDateFormat("HH:mm:ss", Locale.getDefault()).format(new Date());
                    prefs.saveTelemetry(
                            finalItem.currentStatus,
                            finalItem.rxFormatted,
                            finalItem.txFormatted,
                            finalItem.latencyMs,
                            finalItem.monthlyUptime,
                            timeStr,
                            finalItem.rxBps,
                            finalItem.txBps
                    );
                });
            }
        }).start();
    }

    private void updateUiValues(String router, String iface, String status, String rxFormatted, String txFormatted, double rxBps, double txBps, int latency) {
        PrefManager.RouterProfile currentProfile = prefs.getActiveProfile();
        String badge = currentProfile != null ? currentProfile.getBadge() : "SERVER";
        String serverDisplayName = currentProfile != null ? currentProfile.name : router;

        // 1. Pill Mode
        pillTxtIface.setText("[" + badge + "] " + (iface != null ? iface : "ether1"));
        pillTxtRx.setText(rxFormatted);
        pillTxtTx.setText(txFormatted);
        pillTxtPing.setText(latency + "ms");

        boolean isDown = !"UP".equalsIgnoreCase(status);
        if (isDown) {
            pillStatusDot.setBackgroundColor(0xFFEF4444);
            pillTxtIface.setTextColor(0xFFEF4444);
            collapsedView.setBackgroundResource(R.drawable.bg_floating_pill_down);

            if (expStatusDot != null) expStatusDot.setBackgroundColor(0xFFEF4444);
            expTxtRouter.setTextColor(0xFFEF4444);
            expTxtRx.setTextColor(0xFFEF4444);
            expTxtTx.setTextColor(0xFFEF4444);
            expandedView.setBackgroundResource(R.drawable.bg_card_down);
            if (graphView != null) graphView.setDown(true);
        } else {
            pillStatusDot.setBackgroundColor(0xFF10B981);
            pillTxtIface.setTextColor(0xFF38BDF8);
            collapsedView.setBackgroundResource(R.drawable.bg_floating_pill);

            if (expStatusDot != null) expStatusDot.setBackgroundColor(0xFF10B981);
            expTxtRouter.setTextColor(0xFFF8FAFC);
            expTxtRx.setTextColor(0xFF38BDF8);
            expTxtTx.setTextColor(0xFFC084FC);
            expandedView.setBackgroundResource(R.drawable.bg_card_rounded);
            if (graphView != null) graphView.setDown(false);
        }

        // 2. Expanded Mode (Matches Screenshot)
        expTxtRouter.setText(serverDisplayName);

        // Format Speeds with bps/Kbps/Mbps
        String rxDisplay = rxBps > 0 ? TrafficGraphView.formatSpeed(rxBps) : rxFormatted;
        String txDisplay = txBps > 0 ? TrafficGraphView.formatSpeed(txBps) : txFormatted;
        expTxtRx.setText(rxDisplay);
        expTxtTx.setText(txDisplay);

        if (graphView != null) {
            graphView.addSample(rxBps, txBps);
            expTxtRxPeak.setText("Peak: " + TrafficGraphView.formatSpeed(graphView.getPeakRx()));
            expTxtTxPeak.setText("Peak: " + TrafficGraphView.formatSpeed(graphView.getPeakTx()));
        }

        expTxtFooter.setText("Connected to " + serverDisplayName + " • " + (iface != null ? iface : "ether1") + " (" + latency + "ms)");
    }

    private void triggerAlertNotification() {
        if (!prefs.isSoundAlertEnabled()) return;

        try {
            Uri alert = RingtoneManager.getDefaultUri(RingtoneManager.TYPE_NOTIFICATION);
            Ringtone r = RingtoneManager.getRingtone(getApplicationContext(), alert);
            if (r != null) r.play();

            Vibrator v = (Vibrator) getSystemService(Context.VIBRATOR_SERVICE);
            if (v != null) {
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                    v.vibrate(VibrationEffect.createOneShot(500, VibrationEffect.DEFAULT_AMPLITUDE));
                } else {
                    v.vibrate(500);
                }
            }
        } catch (Exception ignored) {
        }
    }

    private void createNotificationChannel() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            NotificationChannel channel = new NotificationChannel(
                    CHANNEL_ID,
                    "TraffMont Monitoring Service",
                    NotificationManager.IMPORTANCE_LOW
            );
            channel.setDescription("Menampilkan widget melayang dan memantau status Mikrotik.");
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null) {
                manager.createNotificationChannel(channel);
            }
        }
    }

    private Notification createNotification() {
        Intent notifIntent = new Intent(this, MainActivity.class);
        PendingIntent pendingIntent = PendingIntent.getActivity(
                this, 0, notifIntent,
                Build.VERSION.SDK_INT >= Build.VERSION_CODES.M ? PendingIntent.FLAG_IMMUTABLE : 0
        );

        return new NotificationCompat.Builder(this, CHANNEL_ID)
                .setContentTitle("TraffMont Floating Widget")
                .setContentText("Memantau traffic realtime di layar...")
                .setSmallIcon(R.drawable.ic_traffmont_logo)
                .setContentIntent(pendingIntent)
                .setOngoing(true)
                .build();
    }

    @Override
    public void onDestroy() {
        isRunning = false;
        prefs.setFloatingRunning(false);
        handler.removeCallbacks(pollRunnable);

        if (floatingView != null && windowManager != null) {
            try {
                windowManager.removeView(floatingView);
            } catch (Exception ignored) {
            }
        }
        super.onDestroy();
    }
}
