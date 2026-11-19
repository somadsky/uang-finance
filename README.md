💰 Uang — Personal Finance Management

<p align="center">
  <strong>Kelola uangmu, pahami keuanganmu, rencanakan masa depanmu.</strong>
</p>

<p align="center">
  Aplikasi pengelola keuangan pribadi berbasis PHP dan JSON untuk mencatat, mengelola, dan memantau seluruh aktivitas keuangan dalam satu tempat.
</p>

📌 Tentang Project

Uang adalah aplikasi Personal Finance Management yang dirancang untuk membantu pengguna mengelola keuangan pribadi dengan lebih mudah, praktis, dan terorganisir.

Aplikasi ini menyediakan berbagai fitur mulai dari pencatatan pemasukan dan pengeluaran, manajemen rekening, pengaturan anggaran, target tabungan, utang-piutang, laporan keuangan, hingga simulasi bunga tabungan SeaBank.

Dengan sistem penyimpanan berbasis JSON, aplikasi dapat dijalankan tanpa memerlukan database MySQL untuk penggunaan standar.

🎯 Tujuan Aplikasi

Mempermudah pencatatan keuangan sehari-hari.

Memantau pemasukan dan pengeluaran.

Mengelola berbagai rekening dan dompet digital.

Membantu mengontrol anggaran bulanan.

Memantau perkembangan tabungan.

Menghitung estimasi bunga tabungan.

Menyediakan laporan keuangan secara terstruktur.

✨ Fitur Utama

📊 1. Dashboard Keuangan

Menampilkan ringkasan kondisi keuangan secara keseluruhan.

Total seluruh saldo rekening.

Total pemasukan.

Total pengeluaran.

Saldo bersih.

Grafik pemasukan dan pengeluaran.

Statistik transaksi.

Ringkasan berdasarkan kategori.

Ringkasan saldo setiap rekening.

💸 2. Manajemen Transaksi

Mengelola seluruh aktivitas keuangan.

Tambah transaksi pemasukan.

Tambah transaksi pengeluaran.

Transfer antar rekening.

Edit transaksi.

Hapus transaksi.

Pencarian transaksi.

Filter berdasarkan tanggal.

Filter berdasarkan kategori.

Filter berdasarkan rekening.

Catatan tambahan transaksi.

🏦 3. Multi Wallet & Bank

Mengelola berbagai sumber penyimpanan uang.

Rekening bank.

Dompet digital.

Uang tunai.

Pengaturan saldo awal.

Pengelolaan rekening.

Pemantauan saldo setiap akun.

Transfer antar dompet.

📸 4. Scan Struk Otomatis (OCR)

Mempermudah pencatatan transaksi melalui foto struk.

Scan struk menggunakan kamera.

Upload gambar dari galeri.

Membaca teks pada struk.

Mendeteksi nominal transaksi.

Membaca tanggal transaksi.

Mendeteksi nama toko.

Membantu membuat transaksi dari hasil scan.

Menyimpan bukti transaksi.

💰 5. Bunga Tabungan SeaBank

Fitur simulasi dan pencatatan estimasi pendapatan bunga tabungan SeaBank.

Ketentuan suku bunga:

Jumlah Saldo

Suku Bunga

Rp0 – Rp149.999.999

2,50% per tahun

≥ Rp150.000.000

3,50% per tahun

Ketentuan pajak:

Pajak bunga: 20%.

Perhitungan bunga harian.

Estimasi bunga bersih setelah pajak.

Perhitungan berdasarkan saldo dan suku bunga yang berlaku.

Fitur:

Kalkulator bunga harian.

Simulasi bunga bulanan.

Simulasi bunga tahunan.

Perhitungan pajak bunga.

Riwayat bunga harian.

Pencatatan pendapatan bunga.

Otomatisasi menggunakan PHP Cron Job.

Jadwal pencatatan bawaan pukul 01.30 WIB.

Pencegahan transaksi bunga ganda.

Log aktivitas Cron Job.

Rumus perhitungan:

Bunga Kotor = Saldo × Suku Bunga / 365

Pajak = Bunga Kotor × 20%

Bunga Bersih = Bunga Kotor - Pajak

Catatan: Perhitungan merupakan simulasi berdasarkan ketentuan yang dikonfigurasi. Bukan koneksi langsung ke rekening SeaBank. Nominal bunga aktual dapat berbeda mengikuti saldo harian, pembulatan, dan kebijakan bank.

🪙 6. Investasi Emas Digital

Membantu memantau dan mencatat kepemilikan emas.

Informasi harga emas.

Pemantauan harga beli dan jual.

Pencatatan pembelian emas.

Pemantauan jumlah kepemilikan.

Perhitungan keuntungan dan kerugian.

🎯 7. Anggaran & Budget Management

Mengatur batas pengeluaran berdasarkan kategori.

Membuat anggaran bulanan.

Mengatur nominal anggaran.

Memantau penggunaan anggaran.

Melihat sisa anggaran.

Membandingkan anggaran dan realisasi.

Menyalin anggaran bulan sebelumnya.

🏆 8. Target Tabungan

Membantu mencapai tujuan keuangan.

Membuat target tabungan.

Menentukan nominal target.

Mencatat setoran.

Mencatat penarikan.

Melihat progres tabungan.

Memantau sisa target.

📒 9. Manajemen Utang & Piutang

Mengelola kewajiban dan tagihan pribadi.

Mencatat utang.

Mencatat piutang.

Menambahkan informasi pihak terkait.

Mencatat pembayaran.

Pembayaran sebagian atau penuh.

Riwayat pembayaran.

Status lunas.

📈 10. Laporan Keuangan

Menyediakan informasi keuangan berdasarkan periode.

Laporan harian.

Laporan mingguan.

Laporan bulanan.

Laporan tahunan.

Rekap pemasukan.

Rekap pengeluaran.

Rekap kategori.

Rekap rekening.

Grafik perkembangan keuangan.

Export CSV.

Cetak laporan.

⚙️ 11. Pengaturan Aplikasi

Pengaturan untuk menyesuaikan aplikasi dengan kebutuhan pengguna.

Pengaturan nama aplikasi.

Pengaturan identitas pengguna.

Pengaturan rekening.

Pengaturan kategori.

Pengaturan anggaran.

Dark Mode.

Light Mode.

Backup data.

Restore data.

Pengaturan fitur aplikasi.

🔐 Sistem Keamanan Token

Aplikasi menggunakan sistem autentikasi berbasis token.

Cara Kerja

Pengguna memasukkan token untuk login.

Server memverifikasi token.

Jika token benar, sesi login dibuat.

Sesi berlaku selama 24 jam.

Refresh halaman tidak meminta login ulang.

Browser ditutup dan dibuka kembali tetap login selama sesi berlaku.

Setelah 24 jam, pengguna harus login kembali menggunakan token yang sama.

Ketentuan Token

Pengaturan

Ketentuan

Metode autentikasi

Static Token

Masa berlaku sesi

24 Jam

Token berubah otomatis

Tidak

Token diganti manual

Bisa

Refresh halaman

Tetap login

Browser dibuka kembali

Tetap login

Token kedaluwarsa

Login ulang

Logout manual

Sesi berakhir

Keamanan Tambahan

Token disimpan dalam bentuk hash.

Pengenalan perangkat.

Deteksi IP Address.

Informasi browser dan sistem operasi.

Riwayat login.

Pengelolaan perangkat.

Pembatasan percobaan autentikasi.

Perlindungan CSRF.

Cookie HttpOnly dan Secure.

🛠️ Teknologi yang Digunakan

Teknologi

Fungsi

PHP

Backend aplikasi

JSON

Penyimpanan data

HTML5

Struktur halaman

CSS3

Tampilan antarmuka

JavaScript

Interaksi aplikasi

Tesseract.js

OCR

PHP Cron Job

Otomatisasi terjadwal

Apache

Web server

Database: JSON / File-based Storage.

Aplikasi tidak membutuhkan MySQL untuk penggunaan standar.

📋 Persyaratan Sistem

Sebelum menjalankan aplikasi, pastikan perangkat atau hosting memenuhi persyaratan berikut:

PHP yang kompatibel dengan source code.

Web server Apache.

Dukungan .htaccess.

PHP CLI untuk Cron Job.

Izin baca dan tulis pada direktori data.

HTTPS/SSL untuk penggunaan online.

🚀 Instalasi

A. Instalasi di Hosting / cPanel

Download source code dari GitHub.

Extract file project.

Login ke cPanel.

Buka File Manager.

Masuk ke direktori public_html.

Upload seluruh file aplikasi.

Extract jika masih berbentuk ZIP.

Pastikan file .htaccess ikut terunggah.

Pastikan direktori data memiliki izin akses yang sesuai.

Buka domain melalui browser.

Lakukan konfigurasi awal dan buat token autentikasi.

B. Menjalankan di Komputer Lokal

Pastikan PHP sudah terinstal.

Buka terminal di direktori project, kemudian jalankan:

php -S 127.0.0.1:8899 -t .

Buka browser:

http://127.0.0.1:8899/

Aplikasi akan berjalan melalui server PHP lokal.

⏰ Konfigurasi Cron Job SeaBank

Untuk menjalankan pencatatan bunga secara otomatis, gunakan fitur Cron Jobs pada cPanel atau VPS.

Contoh jadwal:

30 1 * * * /usr/bin/php /home/USER/public_html/uang/cron/bunga_seabank.php --quiet

Keterangan:

Parameter

Keterangan

30

Menit ke-30

1

Jam 01

*

Setiap tanggal

*

Setiap bulan

*

Setiap hari

Pastikan zona waktu server menggunakan Asia/Jakarta atau jadwal Cron Job disesuaikan dengan zona waktu server.

📂 Struktur Project

uang-finance/
│
├── assets/
│   ├── css/
│   ├── js/
│   └── ...
│
├── backup/
│
├── cron/
│   └── bunga_seabank.php
│
├── data/
│
├── inc/
│   ├── auth.php
│   ├── bootstrap.php
│   ├── config.php
│   ├── finance.php
│   ├── gold.php
│   ├── interest.php
│   ├── login.php
│   ├── store.php
│   └── struk.php
│
├── tools/
│
├── .gitignore
├── .htaccess
├── api.php
├── diagnosa.php
├── index.php
├── PANDUAN.md
└── README.md

Catatan: struktur di atas merupakan gambaran direktori utama. File aktual dapat berbeda mengikuti versi project.

💾 Backup & Restore

Aplikasi menyediakan fitur backup dan restore untuk membantu menjaga keamanan data.

Backup

Mencadangkan data aplikasi.

Menyimpan salinan data.

Mengunduh file backup.

Menyimpan bukti transaksi jika didukung oleh jenis backup.

Restore

Memulihkan data dari file backup.

Mengembalikan data aplikasi.

Memindahkan data ke instalasi lain.

Rekomendasi: lakukan backup secara berkala dan simpan salinan di lokasi berbeda dari server utama.

🔒 Keamanan & Privasi

Untuk menjaga keamanan data keuangan:

Gunakan repository Private jika source code belum dipublikasikan.

Jangan mengunggah token autentikasi ke GitHub.

Jangan mengunggah database pribadi.

Jangan mengunggah file backup ke repository.

Gunakan HTTPS.

Lindungi direktori data dari akses publik.

Batasi izin akses file.

Simpan salinan backup secara terpisah.

🗺️ Roadmap Pengembangan

Beberapa pengembangan yang dapat dilakukan selanjutnya:

Riwayat perubahan transaksi.

Tempat sampah transaksi.

Import transaksi Excel.

Pengingat tagihan.

Transaksi berulang.

Rekap keuangan mingguan otomatis.

Saldo harian.

Wishlist belanja.

Limit pengeluaran harian.

AI Financial Assistant.

📌 Informasi Project

Informasi

Detail

Nama Project

Uang

Nama Lengkap

Personal Finance Management

Repository

uang-finance

Versi

1.4.0

Platform

Web Application

Backend

PHP

Penyimpanan

JSON

Bahasa

Indonesia

Status

Pengembangan

🤝 Kontribusi

Kontribusi dan saran pengembangan sangat terbuka.

Jika menemukan bug atau memiliki ide fitur baru:

Buat laporan melalui Issues.

Jelaskan masalah atau fitur yang diusulkan.

Sertakan langkah untuk mereproduksi masalah jika melaporkan bug.

📄 Lisensi

Project ini belum menetapkan lisensi distribusi.

Penggunaan, penyalinan, dan distribusi source code mengikuti ketentuan pemilik repository.

<p align="center">   <strong>💚 UANG — PERSONAL FINANCE MANAGEMENT</strong>   <br>   Kelola uangmu dengan lebih mudah, praktis, dan terorganisir. </p>
