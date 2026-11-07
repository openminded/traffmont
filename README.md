# TraffMont &mdash; MikroTik Interface Traffic, Uptime & SLA Monitor (cPanel Ready)

**TraffMont** adalah sistem pemantauan (monitoring) traffic dan status link interface MikroTik yang dirancang khusus untuk berjalan di **server hosting cPanel biasa (shared hosting)** tanpa memerlukan akses terminal/SSH dan tanpa memerlukan server VPS.

Sistem ini melakukan pengecekan berkala setiap 1 menit. Jika terjadi **timeout** atau interface mengalami loss link, maka status otomatis dikenali sebagai **DOWN**, tercatat dalam histori detail, dan diakumulasikan ke dalam evaluasi pencapaian **Target SLA (Service Level Agreement) Bulanan** yang dapat disetting berbeda pada setiap server MikroTik.

---

## 🌟 Fitur Utama

1. **Khusus Hosting cPanel (Tanpa Terminal & Tanpa VPS)**
   - 100% PHP murni & MySQL/MariaDB.
   - Dilengkapi **Web Installer** otomatis &mdash; cukup buat database di cPanel dan buka melalui browser.
   - Pengecekan otomatis berjalan menggunakan fitur bawaan **cPanel Cron Jobs** (`* * * * *`).

2. **Deteksi Timeout & Down Setiap 1 Menit**
   - Menghubungi MikroTik setiap menit. Jika koneksi lambat/terputus melebihi batas waktu (timeout, misal 4 detik), langsung dicatat sebagai status **TIMEOUT (DOWN)**.
   - Mendeteksi kondisi interface: `running` (apakah kabel/fiber terpasang), `disabled`, atau link failure.

3. **Multi-Router & Multi-Interface**
   - Dapat memantau banyak IP MikroTik sekaligus.
   - Dapat memilih interface tertentu di setiap router (contoh: `ether1`, `ether2`, `sfp-plus1`, `vlan10`, dsb.).
   - Dilengkapi tombol **"Tes Koneksi & Ambil Interface Otomatis"** di web UI sehingga tidak perlu mengetik nama interface manual.

4. **Kalkulasi Real Uptime & Rekap SLA Bulanan**
   - Target SLA dapat disetting per router (contoh: 99.50%, 99.80%, 99.90%).
   - Menghitung persentase **Real Uptime** nyata:
     $$\text{Uptime \%} = \left( \frac{\text{Menit UP}}{\text{Total Menit Periode}} \right) \times 100\%$$
   - Menghitung **Downtime Budget** (sisa kuota toleransi downtime dalam menit per bulan).
   - Indikator status evaluasi SLA: **SLA TERCAPAI (MET)** atau **SLA MELANGGAR (BREACHED)**.
   - Tombol **Export Laporan ke Excel / CSV** dan **Cetak PDF**.

5. **Halaman Publik Status Uptime (Public Status Page)**
   - Link publik siap pakai (`https://domain-anda.com/status.php`) yang bisa diakses oleh klien, manajemen, atau publik tanpa perlu login.
   - Tampilan profesional ala *UptimeRobot* / *Statuspage*:
     - Banner status operasional (Semua Normal / Ada Gangguan).
     - Grafik balok status harian 30 hari terakhir (*30-day uptime bars*) dengan tooltip tanggal dan persentase uptime.
     - Riwayat insiden gangguan (transparansi 7 hari).
     - Auto-refresh otomatis setiap 60 detik.
     - Data rahasia (seperti IP router, port, username, dan password) **tidak ditampilkan ke publik**.

5. **Visualisasi Grafik Lengkap (Harian, Mingguan, Bulanan)**
   - **Harian (24 Jam)**: Grafik traffic download (RX) & upload (TX) dalam Mbps, latency respons (ms), dan timeline status per jam.
   - **Mingguan (7 Hari)**: Perbandingan persentase uptime harian dan total menit downtime 7 hari terakhir.
   - **Bulanan**: Grafik tren pencapaian SLA tanggal 1 s/d 31 dibandingkan garis batas Target SLA.

6. **Fleksibel untuk Berbagai Kondisi Jaringan (PULL & PUSH)**
   - **Mode PULL (RouterOS API / REST API)**: Server cPanel yang mengecek ke IP Public / DDNS MikroTik.
   - **Mode PUSH (Agent Script)**: Jika MikroTik berada di balik **NAT / CGNAT / IP Dinamis**, MikroTik akan mengirim statusnya ke cPanel menggunakan script bawaan `/tool fetch` setiap menit.

7. **Otomatis Ringan & Bersih**
   - Fitur retensi log otomatis yang membersihkan log mentah menit lama sesuai konfigurasi (default 60 hari) agar kuota penyimpanan shared hosting tetap aman.

---

## 💻 Cara Menjalankan di Komputer / Laptop Lokal (Local Testing)

Anda dapat langsung mencoba TraffMont di komputer lokal (Windows) dengan sangat mudah!

### Cara 1: Cara Paling Cepat (1-Klik via `start-local.bat`)
1. Pastikan di laptop Anda sudah terinstall **PHP** (baik dari XAMPP, Laragon, atau PHP standalone).
2. Di folder `traffmont`, cukup klik dua kali (double click) file:
   ```text
   start-local.bat
   ```
   *Script ini otomatis mendeteksi PHP dan langsung membuka browser ke `http://localhost:8000`.*
3. Pada halaman installer (`install.php`), pilih opsi:
   - **SQLite (Local Cepat)** &mdash; Anda tidak perlu setting database server sama sekali! Database otomatis terbentuk.
   - Atau pilih **MySQL** jika Anda menggunakan MySQL dari XAMPP/Laragon.
4. Buat username & password admin, lalu klik **Mulai Instalasi**.
5. *(Opsional)* Jika ingin langsung melihat tampilan grafik dan SLA penuh dengan data simulasi yang realistis, buka link:
   ```text
   http://localhost:8000/seed_demo_data.php
   ```
6. Untuk menjalankan pengecekan otomatis setiap 60 detik di lokal, klik dua kali file:
   ```text
   local-cron.bat
   ```

### Cara 2: Menggunakan XAMPP / Laragon
1. Salin (copy) folder `traffmont` ke:
   - Jika XAMPP: `C:\xampp\htdocs\traffmont`
   - Jika Laragon: `C:\laragon\www\traffmont`
2. Nyalakan service **Apache** dan **MySQL** di XAMPP / Laragon Control Panel.
3. Buka browser ke:
   ```text
   http://localhost/traffmont/install.php
   ```
4. Masukkan data MySQL:
   - Host: `localhost`
   - User: `root`
   - Password: (kosongkan jika default)
   - Database Name: `traffmont_db` (buat database ini terlebih dahulu di phpMyAdmin).

---

## 🚀 Panduan Instalasi di cPanel Shared Hosting (Production)

### Langkah 1: Buat Database di cPanel
1. Login ke cPanel hosting Anda (`https://domain-anda.com:2083`).
2. Masuk ke menu **MySQL Database Wizard**.
3. Masukkan nama database (misal: `cpaneluser_traffmont`).
4. Buat user database baru dan masukkan password.
5. Centang **ALL PRIVILEGES** lalu klik **Make Changes**.

### Langkah 2: Upload File ke Hosting
1. Masuk ke menu **File Manager** di cPanel.
2. Buka folder `public_html` (atau subdomain yang Anda siapkan, misal `public_html/traffmont`).
3. Upload seluruh file folder aplikasi TraffMont ini ke folder tersebut.

### Langkah 3: Jalankan Web Installer di Browser
1. Buka browser dan akses alamat instalasi:
   ```
   https://domain-anda.com/traffmont/install.php
   ```
2. Masukkan data database yang dibuat pada Langkah 1:
   - **Database Host**: `localhost` (standar cPanel).
   - **Database Name**: Nama database Anda.
   - **Database User**: User database Anda.
   - **Database Password**: Password database Anda.
3. Buat akun Admin (Username & Password).
4. Klik **Mulai Instalasi Database & TraffMont**.
5. Sistem akan otomatis membuat tabel, mengisi konfigurasi, dan menampilkan instruksi Cron Job.

### Langkah 4: Setup Cron Job di cPanel (PENTING!)
Agar sistem dapat mengecek setiap menit secara otomatis:
1. Kembali ke cPanel &gt; cari menu **Cron Jobs** (pada bagian *Advanced*).
2. Pada bagian **Common Settings**, pilih **Once Per Minute (* * * * *)**.
3. Pada kolom **Command**, masukkan perintah cURL yang diberikan installer (contoh):
   ```bash
   * * * * * curl -s "https://domain-anda.com/traffmont/cron.php?token=TOKEN_RAHASIA_ANDA" >/dev/null 2>&1
   ```
4. Klik tombol **Add New Cron Job**.

---

## 🛠️ Cara Menghubungkan MikroTik

Di dashboard TraffMont &gt; menu **MikroTik & Interfaces** &gt; klik **Tambah MikroTik**:

### Opsi A: Jika MikroTik Memiliki IP Public / DDNS / Port Forwarding
1. Pilih Metode Cek: **RouterOS API** (Port `8728` atau `8729` jika SSL) atau **RouterOS v7 REST API** (Port `443`).
2. Masukkan Host/IP, Username, Password, dan Target SLA (misal: `99.50%`).
3. Klik tombol **"Tes Sekarang"**.
4. Sistem akan menguji koneksi dan menampilkan interface yang ada di router Anda (misal: `ether1`, `ether2`, dll).
5. Centang interface yang ingin dipantau lalu klik **Simpan Router**.

> **Tips Keamanan MikroTik**: Buat user khusus di MikroTik dengan akses `read` saja:
> ```routeros
> /user group add name=monitoring policy=read,api,test
> /user add name=monuser group=monitoring password=PasswordKuatAnda
> ```

### Opsi B: Jika MikroTik Berada di Balik NAT / Tanpa IP Public (Push Agent Script)
1. Pilih Metode Cek: **Push Agent Script**.
2. Masukkan Nama Router dan Target SLA.
3. Klik **Simpan Router**.
4. Klik tombol **"Script MikroTik"** pada baris router tersebut.
5. Salin (copy) script CLI yang di-generate, lalu paste ke terminal Winbox MikroTik Anda. Script akan mengirim laporan interface ke cPanel Anda setiap 1 menit. Jika dalam &gt; 2 menit tidak ada laporan masuk, TraffMont akan menandai interface sebagai **TIMEOUT / DOWN**.

---

## 📊 Memahami Rekap SLA Bulanan

Buka menu **Rekap SLA Bulanan**:
- **Target SLA**: Persentase ketersediaan link yang disepakati (misal: `99.50%`).
- **Real Uptime Dicapai**: Persentase nyata operasional link selama sebulan penuh.
  - Jika $\ge \text{Target}$, status berwarna hijau: **SLA TERCAPAI**.
  - Jika $< \text{Target}$, status berwarna merah: **SLA MELANGGAR**.
- **Batas Kuota Downtime**: Total menit downtime yang diizinkan dalam 1 bulan (contoh: pada bulan 30 hari, SLA 99.5% memperbolehkan maksimal **216 menit** downtime).
- **Sisa Budget**: Selisih sisa toleransi downtime sebelum melanggar SLA.
- **Export & Cetak**: Anda dapat mendownload file CSV untuk laporan pimpinan atau menekan Cetak Laporan.

---

## 📱 Aplikasi Android & Floating / Home Screen Widget

TraffMont dilengkapi aplikasi Android bawaan (source code di folder `android_app/` dan file APK siap install `TraffMont-Monitor.apk`).

### Fitur Android:
1. **Home Screen Widget**:
   - Menampilkan realtime traffic Download (RX) & Upload (TX).
   - **Real-time Sparkline Graph**: Menampilkan grafik gelombang traffic langsung di layar utama HP.
   - **Peak Traffic Record**: Mencatat dan menampilkan traffic tertinggi yang pernah dicapai.
   - **Label Badge Khusus**: Label server unik seperti `[LANGSAT]`, `[PERANAP]`, `[CORE]` agar mudah membedakan banyak widget di satu layar.
   - **Indikator Darurat Merah (DOWN Alert)**: Saat interface/router mengalami gangguan, seluruh bingkai widget, teks status, dan grafik otomatis menyala **MERAH** (`⚠️ LINK DOWN / OFFLINE`).
2. **Floating Widget (Melayang di Layar)**:
   - Mode pil mini melayang dan mode kartu melebar (*expandable*).
   - Dilengkapi tombol kontrol window: 🔒 Gembok Kunci Posisi, ⚙️ Pengaturan, ➖ Minimize, dan ✕ Tutup.
   - Ketuk untuk berganti server / interface secara cepat.
3. **Multi-Server & Dua Mode Koneksi**:
   - **Direct Mode**: Terhubung langsung ke MikroTik via RouterOS API port `8728`/`8484` (tanpa perlu web server).
   - **Web Server Mode**: Terhubung ke server dashboard TraffMont Anda via API widget.

---

## 🔒 Keamanan & Pemeliharaan
- Akses cron dilindungi dengan `CRON_TOKEN` unik agar tidak dapat dipicu oleh pihak luar yang tidak berhak.
- Password admin disimpan menggunakan enkripsi hash standar industri (`BCRYPT`).
- Terdapat fitur retensi otomatis untuk menghapus log lama di atas batas hari (default 60 hari) sehingga database hosting tetap ramping dan tidak membebani cPanel.
