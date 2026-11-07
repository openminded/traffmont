package com.traffmont.widget;

import android.app.AlertDialog;
import android.app.PendingIntent;
import android.appwidget.AppWidgetManager;
import android.content.ComponentName;
import android.content.Intent;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.os.Handler;
import android.os.Looper;
import android.provider.Settings;
import android.view.View;
import android.widget.AdapterView;
import android.widget.ArrayAdapter;
import android.widget.Button;
import android.widget.RadioButton;
import android.widget.RadioGroup;
import android.widget.Spinner;
import android.widget.TextView;
import android.widget.Toast;

import androidx.activity.result.ActivityResultLauncher;
import androidx.activity.result.contract.ActivityResultContracts;
import androidx.appcompat.app.AppCompatActivity;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import com.google.android.material.materialswitch.MaterialSwitch;
import com.google.android.material.textfield.TextInputEditText;

import java.util.ArrayList;
import java.util.List;
import java.util.Locale;

public class MainActivity extends AppCompatActivity {

    // Server Profile Views
    private Spinner spinnerServers;
    private Button btnAddServer;
    private Button btnDeleteServer;
    private TextInputEditText inputServerName;
    private TextInputEditText inputServerBadge;

    private RadioGroup radioGroupMode;
    private RadioButton radioModeDirect;
    private RadioButton radioModeServer;
    private View layoutDirectForm;
    private View layoutServerForm;

    // Direct Form Inputs
    private TextInputEditText inputMikrotikHost;
    private TextInputEditText inputMikrotikPort;
    private TextInputEditText inputMikrotikUser;
    private TextInputEditText inputMikrotikPass;

    // Server Form Inputs
    private TextInputEditText inputServerUrl;

    private Button btnTestConnect;
    private Spinner spinnerInterfaces;
    private Spinner spinnerInterval;
    private MaterialSwitch switchFloating;
    private MaterialSwitch switchAlert;
    private Button btnPinWidget;
    private Button btnDemoMode;
    private SwipeRefreshLayout swipeRefresh;

    // Quick Preview Views
    private View layoutPreview;
    private TextView previewTxtName;
    private TextView previewTxtStatus;
    private TextView previewTxtRx;
    private TextView previewTxtTx;
    private TextView previewTxtLatency;

    private PrefManager prefs;
    private final List<ApiClient.InterfaceItem> interfaceList = new ArrayList<>();
    private ArrayAdapter<ApiClient.InterfaceItem> ifaceAdapter;

    private final List<PrefManager.RouterProfile> serverList = new ArrayList<>();
    private ArrayAdapter<PrefManager.RouterProfile> serverAdapter;

    private ActivityResultLauncher<Intent> overlayPermissionLauncher;
    private boolean isUpdatingUi = false;

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        try {
            prefs = new PrefManager(this);

            overlayPermissionLauncher = registerForActivityResult(
                    new ActivityResultContracts.StartActivityForResult(),
                    result -> {
                        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M && Settings.canDrawOverlays(this)) {
                            startFloatingService();
                        } else {
                            switchFloating.setChecked(false);
                            Toast.makeText(this, "Izin Tampilkan di Atas Aplikasi Lain dibutuhkan untuk Floating Widget.", Toast.LENGTH_LONG).show();
                        }
                    }
            );

            initViews();
            setupServerSelector();
            setupModeSwitching();
            setupIntervalSpinner();
            loadSavedConfig();

            // Set checked state before listener is active to avoid triggering service start on app launch
            switchFloating.setChecked(prefs.isFloatingRunning());
            setupListeners();

        } catch (Exception e) {
            Toast.makeText(this, "Inisialisasi aplikasi: " + e.getMessage(), Toast.LENGTH_LONG).show();
        }
    }

    private void initViews() {
        spinnerServers = findViewById(R.id.spinner_servers);
        btnAddServer = findViewById(R.id.btn_add_server);
        btnDeleteServer = findViewById(R.id.btn_delete_server);
        inputServerName = findViewById(R.id.input_server_name);
        inputServerBadge = findViewById(R.id.input_server_badge);

        radioGroupMode = findViewById(R.id.radio_group_mode);
        radioModeDirect = findViewById(R.id.radio_mode_direct);
        radioModeServer = findViewById(R.id.radio_mode_server);
        layoutDirectForm = findViewById(R.id.layout_direct_form);
        layoutServerForm = findViewById(R.id.layout_server_form);

        inputMikrotikHost = findViewById(R.id.input_mikrotik_host);
        inputMikrotikPort = findViewById(R.id.input_mikrotik_port);
        inputMikrotikUser = findViewById(R.id.input_mikrotik_user);
        inputMikrotikPass = findViewById(R.id.input_mikrotik_pass);
        inputServerUrl = findViewById(R.id.input_server_url);

        btnTestConnect = findViewById(R.id.btn_test_connect);
        spinnerInterfaces = findViewById(R.id.spinner_interfaces);
        spinnerInterval = findViewById(R.id.spinner_interval);
        switchFloating = findViewById(R.id.switch_floating);
        switchAlert = findViewById(R.id.switch_alert);
        btnPinWidget = findViewById(R.id.btn_pin_widget);
        btnDemoMode = findViewById(R.id.btn_demo_mode);
        swipeRefresh = findViewById(R.id.swipe_refresh);

        layoutPreview = findViewById(R.id.layout_selected_preview);
        previewTxtName = findViewById(R.id.preview_txt_name);
        previewTxtStatus = findViewById(R.id.preview_txt_status);
        previewTxtRx = findViewById(R.id.preview_txt_rx);
        previewTxtTx = findViewById(R.id.preview_txt_tx);
        previewTxtLatency = findViewById(R.id.preview_txt_latency);

        ifaceAdapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_dropdown_item, interfaceList);
        spinnerInterfaces.setAdapter(ifaceAdapter);

        serverAdapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_dropdown_item, serverList);
        spinnerServers.setAdapter(serverAdapter);
    }

    private void setupServerSelector() {
        refreshServerList();

        spinnerServers.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
            @Override
            public void onItemSelected(AdapterView<?> parent, View view, int position, long id) {
                if (position >= 0 && position < serverList.size() && !isUpdatingUi) {
                    PrefManager.RouterProfile selected = serverList.get(position);
                    prefs.setActiveProfileId(selected.id);
                    populateProfileFields(selected);
                }
            }

            @Override
            public void onNothingSelected(AdapterView<?> parent) {
            }
        });

        btnAddServer.setOnClickListener(v -> {
            PrefManager.RouterProfile newProf = new PrefManager.RouterProfile();
            newProf.name = "MikroTik " + (serverList.size() + 1);
            newProf.host = "103.146.38.149";
            newProf.port = 8484;
            newProf.user = "zk";
            newProf.pass = "";
            newProf.interfaceName = "ether1";
            prefs.saveOrUpdateProfile(newProf);

            refreshServerList();
            // Select newly added
            for (int i = 0; i < serverList.size(); i++) {
                if (serverList.get(i).id.equals(newProf.id)) {
                    spinnerServers.setSelection(i);
                    populateProfileFields(newProf);
                    break;
                }
            }
            Toast.makeText(this, "Server baru ditambahkan! Silakan isi host & port.", Toast.LENGTH_SHORT).show();
        });

        btnDeleteServer.setOnClickListener(v -> {
            if (serverList.size() <= 1) {
                Toast.makeText(this, "Minimal harus ada 1 server profil.", Toast.LENGTH_SHORT).show();
                return;
            }
            PrefManager.RouterProfile current = prefs.getActiveProfile();
            new AlertDialog.Builder(this)
                    .setTitle("Hapus Server")
                    .setMessage("Hapus profil server '" + current.name + "'?")
                    .setPositiveButton("Hapus", (d, w) -> {
                        prefs.deleteProfile(current.id);
                        refreshServerList();
                        populateProfileFields(prefs.getActiveProfile());
                        Toast.makeText(this, "Server berhasil dihapus.", Toast.LENGTH_SHORT).show();
                    })
                    .setNegativeButton("Batal", null)
                    .show();
        });
    }

    private void refreshServerList() {
        isUpdatingUi = true;
        serverList.clear();
        serverList.addAll(prefs.getProfiles());
        serverAdapter.notifyDataSetChanged();

        PrefManager.RouterProfile active = prefs.getActiveProfile();
        int activeIdx = 0;
        for (int i = 0; i < serverList.size(); i++) {
            if (serverList.get(i).id.equals(active.id)) {
                activeIdx = i;
                break;
            }
        }
        spinnerServers.setSelection(activeIdx);
        isUpdatingUi = false;
    }

    private void populateProfileFields(PrefManager.RouterProfile profile) {
        if (profile == null) return;
        isUpdatingUi = true;

        inputServerName.setText(profile.name);
        if (inputServerBadge != null) {
            inputServerBadge.setText(profile.label != null && !profile.label.isEmpty() ? profile.label : profile.getBadge());
        }
        if (PrefManager.MODE_SERVER_WEB.equals(profile.mode)) {
            radioModeServer.setChecked(true);
            layoutDirectForm.setVisibility(View.GONE);
            layoutServerForm.setVisibility(View.VISIBLE);
            inputServerUrl.setText(profile.host);
        } else {
            radioModeDirect.setChecked(true);
            layoutDirectForm.setVisibility(View.VISIBLE);
            layoutServerForm.setVisibility(View.GONE);
            inputMikrotikHost.setText(profile.host);
            inputMikrotikPort.setText(String.valueOf(profile.port > 0 ? profile.port : 8484));
            inputMikrotikUser.setText(profile.user);
            inputMikrotikPass.setText(profile.pass);
        }

        previewTxtName.setText(profile.name + " (" + profile.interfaceName + ")");
        isUpdatingUi = false;
    }

    private void setupModeSwitching() {
        radioGroupMode.setOnCheckedChangeListener((group, checkedId) -> {
            if (checkedId == R.id.radio_mode_direct) {
                layoutDirectForm.setVisibility(View.VISIBLE);
                layoutServerForm.setVisibility(View.GONE);
                btnTestConnect.setText("Hubungkan ke MikroTik & Ambil Interface");
            } else {
                layoutDirectForm.setVisibility(View.GONE);
                layoutServerForm.setVisibility(View.VISIBLE);
                btnTestConnect.setText("Hubungkan ke Server Web & Ambil Interface");
            }
        });
    }

    private void setupIntervalSpinner() {
        Integer[] intervals = {1, 2, 3, 5, 10, 30, 60};
        String[] labels = {"1 detik", "2 detik", "3 detik", "5 detik", "10 detik", "30 detik", "1 menit"};

        ArrayAdapter<String> adapter = new ArrayAdapter<>(this, android.R.layout.simple_spinner_dropdown_item, labels);
        spinnerInterval.setAdapter(adapter);

        int current = prefs.getRefreshInterval();
        int selectedIndex = 2; // default 3s
        for (int i = 0; i < intervals.length; i++) {
            if (intervals[i] == current) {
                selectedIndex = i;
                break;
            }
        }
        spinnerInterval.setSelection(selectedIndex);

        spinnerInterval.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
            @Override
            public void onItemSelected(AdapterView<?> parent, View view, int position, long id) {
                prefs.setRefreshInterval(intervals[position]);
            }

            @Override
            public void onNothingSelected(AdapterView<?> parent) {
            }
        });
    }

    private void loadSavedConfig() {
        PrefManager.RouterProfile active = prefs.getActiveProfile();
        populateProfileFields(active);

        switchAlert.setChecked(prefs.isSoundAlertEnabled());

        previewTxtStatus.setText(prefs.getLastStatus());
        previewTxtRx.setText("⬇ " + prefs.getLastRx());
        previewTxtTx.setText("⬆ " + prefs.getLastTx());
        previewTxtLatency.setText(String.format(Locale.getDefault(), "⚡ %d ms", prefs.getLastLatency()));

        if ("UP".equalsIgnoreCase(prefs.getLastStatus())) {
            previewTxtStatus.setTextColor(0xFF10B981);
        } else {
            previewTxtStatus.setTextColor(0xFFEF4444);
        }
    }

    private void setupListeners() {
        swipeRefresh.setOnRefreshListener(() -> {
            loadInterfaces();
        });

        btnTestConnect.setOnClickListener(v -> {
            saveCurrentInputs();
            loadInterfaces();
        });

        spinnerInterfaces.setOnItemSelectedListener(new AdapterView.OnItemSelectedListener() {
            @Override
            public void onItemSelected(AdapterView<?> parent, View view, int position, long id) {
                if (position >= 0 && position < interfaceList.size()) {
                    ApiClient.InterfaceItem item = interfaceList.get(position);
                    selectInterface(item);
                }
            }

            @Override
            public void onNothingSelected(AdapterView<?> parent) {
            }
        });

        // Use isPressed check to ensure programmatic changes don't trigger service
        switchFloating.setOnCheckedChangeListener((buttonView, isChecked) -> {
            if (!buttonView.isPressed()) return;

            if (isChecked) {
                checkOverlayPermissionAndStart();
            } else {
                stopFloatingService();
            }
        });

        switchAlert.setOnCheckedChangeListener((buttonView, isChecked) -> {
            prefs.setSoundAlertEnabled(isChecked);
        });

        btnPinWidget.setOnClickListener(v -> {
            pinWidgetToHomeScreen();
        });

        btnDemoMode.setOnClickListener(v -> {
            loadDemoMode();
        });
    }

    private void saveCurrentInputs() {
        PrefManager.RouterProfile current = prefs.getActiveProfile();

        String sName = inputServerName.getText() != null ? inputServerName.getText().toString().trim() : "";
        if (!sName.isEmpty()) current.name = sName;

        if (inputServerBadge != null && inputServerBadge.getText() != null) {
            String badge = inputServerBadge.getText().toString().trim().toUpperCase(Locale.ROOT);
            current.label = badge;
        }

        if (radioModeDirect.isChecked()) {
            current.mode = PrefManager.MODE_DIRECT_MIKROTIK;
            String host = inputMikrotikHost.getText() != null ? inputMikrotikHost.getText().toString().trim() : "";
            String portStr = inputMikrotikPort.getText() != null ? inputMikrotikPort.getText().toString().trim() : "8484";
            int port = 8484;
            try {
                port = Integer.parseInt(portStr);
            } catch (Exception ignored) {
            }
            String user = inputMikrotikUser.getText() != null ? inputMikrotikUser.getText().toString().trim() : "zk";
            String pass = inputMikrotikPass.getText() != null ? inputMikrotikPass.getText().toString() : "";

            current.host = host;
            current.port = port;
            current.user = user;
            current.pass = pass;
        } else {
            current.mode = PrefManager.MODE_SERVER_WEB;
            String url = inputServerUrl.getText() != null ? inputServerUrl.getText().toString().trim() : "";
            current.host = url;
        }

        prefs.saveOrUpdateProfile(current);
        refreshServerList();
    }

    private void loadInterfaces() {
        swipeRefresh.setRefreshing(true);
        saveCurrentInputs();

        PrefManager.RouterProfile current = prefs.getActiveProfile();

        new Thread(() -> {
            if (PrefManager.MODE_DIRECT_MIKROTIK.equals(current.mode)) {
                MikrotikDirectClient client = new MikrotikDirectClient(current.host, current.port, current.user, current.pass, 10000);
                MikrotikDirectClient.DirectResult res = client.fetchInterfaces();

                new Handler(Looper.getMainLooper()).post(() -> {
                    swipeRefresh.setRefreshing(false);
                    if (res.success && !res.interfaces.isEmpty()) {
                        interfaceList.clear();
                        interfaceList.addAll(res.interfaces);
                        ifaceAdapter.notifyDataSetChanged();

                        String savedName = current.interfaceName;
                        int targetIndex = 0;
                        for (int i = 0; i < interfaceList.size(); i++) {
                            if (interfaceList.get(i).interfaceName.equalsIgnoreCase(savedName)) {
                                targetIndex = i;
                                break;
                            }
                        }
                        spinnerInterfaces.setSelection(targetIndex);
                        selectInterface(interfaceList.get(targetIndex));

                        Toast.makeText(MainActivity.this, "Berhasil terhubung ke " + current.name + "! Ditemukan " + res.interfaces.size() + " interface.", Toast.LENGTH_SHORT).show();
                    } else {
                        String msg = res.errorMessage != null ? res.errorMessage : "Koneksi gagal atau tidak ada interface.";
                        Toast.makeText(MainActivity.this, msg, Toast.LENGTH_LONG).show();
                    }
                });

            } else {
                ApiClient.WidgetResponse resp = ApiClient.fetchWidgetData(current.host, 0);

                new Handler(Looper.getMainLooper()).post(() -> {
                    swipeRefresh.setRefreshing(false);
                    if (resp.success && !resp.interfaces.isEmpty()) {
                        interfaceList.clear();
                        interfaceList.addAll(resp.interfaces);
                        ifaceAdapter.notifyDataSetChanged();

                        int targetIndex = 0;
                        for (int i = 0; i < interfaceList.size(); i++) {
                            if (interfaceList.get(i).interfaceName.equalsIgnoreCase(current.interfaceName)) {
                                targetIndex = i;
                                break;
                            }
                        }
                        spinnerInterfaces.setSelection(targetIndex);
                        selectInterface(interfaceList.get(targetIndex));

                        Toast.makeText(MainActivity.this, "Berhasil memuat " + resp.interfaces.size() + " interface dari server!", Toast.LENGTH_SHORT).show();
                    } else {
                        String msg = resp.errorMessage != null ? resp.errorMessage : "Tidak ada interface ditemukan.";
                        Toast.makeText(MainActivity.this, msg, Toast.LENGTH_LONG).show();
                    }
                });
            }
        }).start();
    }

    private void selectInterface(ApiClient.InterfaceItem item) {
        PrefManager.RouterProfile current = prefs.getActiveProfile();
        current.interfaceName = item.interfaceName;
        current.interfaceId = item.id;
        prefs.saveOrUpdateProfile(current);

        previewTxtName.setText(current.name + " (" + item.displayName + ")");
        previewTxtStatus.setText(item.currentStatus);
        previewTxtRx.setText("⬇ " + item.rxFormatted);
        previewTxtTx.setText("⬆ " + item.txFormatted);
        previewTxtLatency.setText(String.format(Locale.getDefault(), "⚡ %d ms", item.latencyMs));

        if ("UP".equalsIgnoreCase(item.currentStatus)) {
            previewTxtStatus.setTextColor(0xFF10B981);
        } else {
            previewTxtStatus.setTextColor(0xFFEF4444);
        }
    }

    private void checkOverlayPermissionAndStart() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.M) {
            if (!Settings.canDrawOverlays(this)) {
                new AlertDialog.Builder(this)
                        .setTitle("Izin Diperlukan")
                        .setMessage("Agar widget dapat melayang di layar, aktifkan izin 'Tampilkan di atas aplikasi lain' untuk TraffMont.")
                        .setPositiveButton("Buka Pengaturan", (dialog, which) -> {
                            Intent intent = new Intent(
                                    Settings.ACTION_MANAGE_OVERLAY_PERMISSION,
                                    Uri.parse("package:" + getPackageName())
                            );
                            overlayPermissionLauncher.launch(intent);
                        })
                        .setNegativeButton("Batal", (dialog, which) -> {
                            switchFloating.setChecked(false);
                        })
                        .show();
                return;
            }
        }
        startFloatingService();
    }

    private void startFloatingService() {
        try {
            Intent serviceIntent = new Intent(this, FloatingWidgetService.class);
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                startForegroundService(serviceIntent);
            } else {
                startService(serviceIntent);
            }
            prefs.setFloatingRunning(true);
            Toast.makeText(this, "Floating Widget Diaktifkan! Silakan geser di layar.", Toast.LENGTH_SHORT).show();
        } catch (Exception e) {
            prefs.setFloatingRunning(false);
            switchFloating.setChecked(false);
            Toast.makeText(this, "Gagal memulai Floating Widget: " + e.getMessage(), Toast.LENGTH_LONG).show();
        }
    }

    private void stopFloatingService() {
        try {
            Intent serviceIntent = new Intent(this, FloatingWidgetService.class);
            stopService(serviceIntent);
            prefs.setFloatingRunning(false);
            Toast.makeText(this, "Floating Widget Dimatikan.", Toast.LENGTH_SHORT).show();
        } catch (Exception ignored) {
        }
    }

    private void pinWidgetToHomeScreen() {
        saveCurrentInputs();
        List<PrefManager.RouterProfile> profiles = prefs.getProfiles();

        String[] serverChoices = new String[profiles.size()];
        for (int i = 0; i < profiles.size(); i++) {
            serverChoices[i] = profiles.get(i).name + " (" + profiles.get(i).host + " - " + profiles.get(i).interfaceName + ")";
        }

        new AlertDialog.Builder(this)
                .setTitle("Pilih Server untuk Widget Home Screen")
                .setItems(serverChoices, (dialog, which) -> {
                    PrefManager.RouterProfile chosen = profiles.get(which);
                    promptInterfaceForWidget(chosen);
                })
                .setNegativeButton("Batal", null)
                .show();
    }

    private void promptInterfaceForWidget(PrefManager.RouterProfile profile) {
        android.widget.LinearLayout layout = new android.widget.LinearLayout(this);
        layout.setOrientation(android.widget.LinearLayout.VERTICAL);
        int pad = (int) (18 * getResources().getDisplayMetrics().density);
        layout.setPadding(pad, pad / 2, pad, pad / 2);

        android.widget.TextView txtBadge = new android.widget.TextView(this);
        txtBadge.setText("Label Badge Server (Contoh: LANGSAT, PERANAP, CORE):");
        txtBadge.setTextSize(13);
        layout.addView(txtBadge);

        final android.widget.EditText inputBadge = new android.widget.EditText(this);
        inputBadge.setText(profile.getBadge());
        inputBadge.setHint("LANGSAT");
        layout.addView(inputBadge);

        android.widget.TextView txtIface = new android.widget.TextView(this);
        txtIface.setText("Nama Interface MikroTik:");
        txtIface.setTextSize(13);
        txtIface.setPadding(0, pad / 2, 0, 0);
        layout.addView(txtIface);

        final android.widget.EditText inputIface = new android.widget.EditText(this);
        inputIface.setText(profile.interfaceName != null && !profile.interfaceName.isEmpty() ? profile.interfaceName : "ether1");
        inputIface.setHint("sfp-sfpplus1");
        layout.addView(inputIface);

        new AlertDialog.Builder(this)
                .setTitle("Pasang Widget: " + profile.name)
                .setView(layout)
                .setPositiveButton("Pasang Widget", (dialog, which) -> {
                    String badge = inputBadge.getText().toString().trim().toUpperCase(Locale.ROOT);
                    if (badge.isEmpty()) badge = profile.getBadge();

                    String iface = inputIface.getText().toString().trim();
                    if (iface.isEmpty()) iface = "ether1";

                    profile.label = badge;
                    profile.interfaceName = iface;
                    prefs.saveOrUpdateProfile(profile);

                    executePinWidget(profile, iface, badge);
                })
                .setNegativeButton("Batal", null)
                .show();
    }

    private void executePinWidget(PrefManager.RouterProfile profile, String ifaceName, String badge) {
        AppWidgetManager appWidgetManager = AppWidgetManager.getInstance(this);
        ComponentName myProvider = new ComponentName(this, TraffMontWidgetProvider.class);

        prefs.setActiveProfileId(profile.id);
        prefs.setInterfaceName(ifaceName);
        prefs.setRouterName(profile.name);

        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
            if (appWidgetManager.isRequestPinAppWidgetSupported()) {
                Intent pinnedWidgetCallbackIntent = new Intent(this, TraffMontWidgetProvider.class);
                pinnedWidgetCallbackIntent.putExtra("profile_id", profile.id);
                pinnedWidgetCallbackIntent.putExtra("interface_name", ifaceName);
                pinnedWidgetCallbackIntent.putExtra("server_label", badge);

                PendingIntent successCallback = PendingIntent.getBroadcast(
                        this, (int) System.currentTimeMillis(),
                        pinnedWidgetCallbackIntent,
                        PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE
                );
                appWidgetManager.requestPinAppWidget(myProvider, null, successCallback);
                Toast.makeText(this, "Silakan konfirmasi 'Tambah ke Layar Utama'", Toast.LENGTH_LONG).show();
                return;
            }
        }

        new AlertDialog.Builder(this)
                .setTitle("Cara Pasang Widget ke Home Screen")
                .setMessage("1. Buka Home Screen HP Anda.\n2. Tekan dan tahan (Long Press) area kosong di layar.\n3. Pilih menu 'Widgets' atau 'Widget'.\n4. Cari 'TraffMont' lalu seret widget ke layar Anda.\n5. Selesai! Widget akan langsung aktif.")
                .setPositiveButton("Mengerti", null)
                .show();
    }

    private void loadDemoMode() {
        interfaceList.clear();

        ApiClient.InterfaceItem item1 = new ApiClient.InterfaceItem();
        item1.id = 1;
        item1.interfaceName = "ether1";
        item1.displayName = "ether1 (Uplink Telkom 100M)";
        item1.currentStatus = "UP";
        item1.rxBps = 84200000;
        item1.txBps = 22600000;
        item1.rxFormatted = "84.2 Mbps";
        item1.txFormatted = "22.6 Mbps";
        item1.latencyMs = 7;
        item1.monthlyUptime = 99.85;
        item1.routerName = "MikroTik Utama";

        ApiClient.InterfaceItem item2 = new ApiClient.InterfaceItem();
        item2.id = 2;
        item2.interfaceName = "ether2";
        item2.displayName = "ether2 (Backup Indosat 50M)";
        item2.currentStatus = "UP";
        item2.rxBps = 12400000;
        item2.txBps = 4100000;
        item2.rxFormatted = "12.4 Mbps";
        item2.txFormatted = "4.1 Mbps";
        item2.latencyMs = 12;
        item2.monthlyUptime = 99.40;
        item2.routerName = "MikroTik Utama";

        interfaceList.add(item1);
        interfaceList.add(item2);
        ifaceAdapter.notifyDataSetChanged();

        selectInterface(item1);
        Toast.makeText(this, "Mode Demo diaktifkan untuk pengujian visual.", Toast.LENGTH_SHORT).show();
    }
}
