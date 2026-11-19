# Uang — Keuangan Pribadi

Aplikasi pencatatan keuangan pribadi berbasis PHP + JSON (tanpa database),
dengan satu token akses milik pemilik.
Cukup unggah folder ini ke hosting, buka di browser, langsung pakai.

- **Satu token akses** — hanya pemilik yang bisa membuka; sesi berlaku 24 jam dan token tidak berubah sendiri.
- Tema gelap sebagai bawaan, tema terang bisa dipilih.
- Semua hitungan berjalan di browser, jadi ringan dan cepat.
- Bahasa Indonesia, tampilan menyesuaikan HP maupun komputer.
- **Scan struk belanja** — foto struk, aplikasi membaca nominal, tanggal, nama toko, dan rincian barangnya otomatis.
- **Pencarian transaksi** — cari berdasarkan nama, toko, barang, atau nominal (mis. `58000`, `25rb`, `>=100000`).
- **Bunga SeaBank otomatis** — cron PHP mencatat bunga harian (2,5%/3,5% per tahun, pajak 20%) sekali sehari, tanpa dobel.
- **Emas digital Lakuemas** — pantau harga beli/jual per gram dan catat pembelian emas sebagai pengeluaran + kepemilikan gram.
- **Scan dari galeri** — pilih foto struk/screenshot yang sudah ada, tidak harus memotret.
- **Pemasukan QRIS DANA Business** — screenshot pembayaran QRIS yang masuk langsung jadi transaksi pemasukan, lengkap dengan pengirim dan nominal.
- **Akun otomatis per kategori** — kategori *Bisnis Osint* otomatis memakai rekening *DANA Business* (bisa diatur untuk kategori lain).

---

## 1. Cara memasang di hosting (cPanel)

Ada dua paket ZIP yang bisa dibuat dengan `python tools/build_zip.py`:

| Berkas | Isi | Dipakai untuk |
|---|---|---|
| `as.zip` | aplikasi saja | memperbarui aplikasi yang sudah jalan (data tidak ditimpa) |
| `as-deploy.zip` | aplikasi + `data/db.php` + foto struk | pasang di hosting baru / pindah hosting, transaksi lama langsung ikut |

1. Buka **File Manager** cPanel, masuk ke folder `public_html`.
2. Unggah **isi** ZIP pilihan Anda (`index.php`, `api.php`, folder `assets/`, `inc/`, `data/`, `backup/`, dan file `.htaccess`).
   - Paling mudah: compress folder menjadi ZIP, unggah ZIP-nya, lalu klik **Extract**.
   - Pastikan file `.htaccess` ikut terunggah (aktifkan "Show Hidden Files" di File Manager).
3. Pastikan izin (permission) folder `data/` dan `backup/` adalah **755** (atau 775 bila server meminta).
4. Buka `https://namadomain.com/` — pada pembukaan pertama Anda diminta **membuat token**, lalu masuk.
   Lakukan ini **segera setelah unggah**, agar tidak ada orang lain yang lebih dulu membuat token.

Belum punya data? Buka **Pengaturan → Data contoh** lalu klik **Muat data contoh** untuk melihat contoh tampilan.
Data contoh diberi label jelas `🧪 DATA MOCK/SINTETIS (TESTING)` — bukan data orang nyata. Hapus dengan tombol **Kosongkan data**.

---

## 1a. Masuk dengan token (hanya sekali buat)

Aplikasi ini tidak memakai username/password biasa. Ada **satu token** yang Anda buat sendiri.

**Pertama kali membuka:**
1. Halaman **Buat token akses** muncul. Ketik token minimal **12 karakter** (kalimat panjang lebih aman), lalu ulangi di kolom kedua.
2. Klik **Buat token & masuk**. Aplikasi langsung terbuka.
3. Simpan token itu di tempat aman (kata sandi ponsel, catatan terkunci). Tidak ada orang lain yang bisa melihatnya — server hanya menyimpan bentuk acaknya (`password_hash`).

**Masuk berikutnya:**
- Selama sesi belum 24 jam, aplikasi terbuka sendiri — tidak perlu mengetik token saat refresh atau menutup-buka browser.
- Setelah **tepat 24 jam**, halaman token muncul lagi. Masukkan token yang **sama**.
- Aktivitas dan refresh **tidak** memperpanjang sesi. Ini disengaja agar token tidak "hidup selamanya" di perangkat yang lupa Anda keluarkan.

**Mengganti token:**
1. Buka **Pengaturan → Keamanan & akses → Ganti token**.
2. Isi token lama, token baru (minimal 12 karakter), lalu ulangi token baru.
3. Setelah disimpan, **token lama langsung tidak berlaku** untuk login berikutnya. Perangkat yang sedang aktif tetap masuk sampai Anda mengeluarkannya (lihat tombol di kartu yang sama).

**Kalau lupa token:** jalankan dari folder aplikasi (SSH / Terminal cPanel):

```bash
php tools/token.php --status          # cek apakah token sudah ada, jumlah sesi
php tools/token.php --ganti "token-baru-yang-panjang"
php tools/token.php --keluar-semua    # keluarkan semua perangkat, token tetap
php tools/token.php --hapus-token     # mulai dari nol: buat token baru lewat halaman
```

**Mengatur perangkat:** kartu **Keamanan & akses** menampilkan sesi aktif (perangkat, browser, sistem operasi, IP, waktu masuk, aktivitas terakhir), **Riwayat login**, tombol **Keluarkan** per perangkat, dan **Keluarkan semua perangkat**. Tombol **Keluar** di kanan atas mengakhiri sesi di perangkat itu saja.

**Yang tidak dilakukan aplikasi:** memperbarui token sendiri, membuat token baru saat login, atau menyimpan token di URL/log. IP hanya dicatat sebagai informasi — berpindah jaringan (mis. dari Wi-Fi ke data seluler) **tidak** mengakhiri sesi.

---

### Kalau dipasang di subfolder
Contoh: `https://namadomain.com/uang/`. Tidak ada yang perlu diubah — semua tautan bersifat relatif.

### Menjalankan di komputer sendiri (untuk uji coba)
```bash
php -S 127.0.0.1:8899 -t .
```
Lalu buka `http://127.0.0.1:8899/`.

---

## 2. Mengenal menu

| Menu | Fungsi |
|---|---|
| **Ringkasan** | Saldo total, pemasukan, pengeluaran, arus kas bersih bulan ini, grafik 6 bulan, komposisi pengeluaran, transaksi terbaru, anggaran, dan saldo tiap akun. |
| **Transaksi** | Daftar semua transaksi. Bisa dicari, disaring per jenis/kategori/akun serta rentang tanggal, diubah, dan dihapus. Ada tombol **📷 Scan struk** untuk mencatat belanja dari foto struk. |
| **Anggaran** | Batas belanja per kategori untuk bulan berjalan, lengkap dengan progres pemakaian. |
| **Target** | Target tabungan (dana darurat, laptop, liburan) beserta setoran dan progresnya. |
| **Utang** | Catatan utang Anda dan piutang ke orang lain, termasuk riwayat pembayaran. |
| **Emas** | Harga beli/jual emas digital Lakuemas per gram, pencatatan pembelian, dan daftar kepemilikan emas beserta untung/rugi. |
| **Laporan** | Rekap **harian, mingguan, bulanan, atau tahunan**: pemasukan, pengeluaran, arus kas, saldo per dompet, grafik, per kategori, unduh CSV, dan cetak. |
| **Pengaturan** | Nama aplikasi, nama pemilik, tema, mata uang, akun, kategori, data contoh, cadangan, pemulihan, info foto struk, serta **bunga SeaBank otomatis** (saklar, ketentuan bunga, jadwal cron, log). |

Tombol **+ Catat** di kanan atas selalu membuka form transaksi baru dari halaman mana pun.

### Dompet dan saldo awal

Aplikasi sudah menyiapkan empat dompet: **BCA**, **GoPay**, **DANA**, dan **SeaBank**. Buka **Pengaturan → Dompet** untuk:

- mengisi **saldo awal** sesuai saldo aktual (saldo awal **bukan** pemasukan, jadi tidak mengacaukan laporan);
- menambah rekening bank, e-wallet, atau uang tunai lain;
- mengubah nama/ikon/warna, mengarsipkan dompet yang tidak dipakai, atau menghapusnya.

Saldo tiap dompet dihitung otomatis: saldo awal + pemasukan − pengeluaran + transfer masuk − transfer keluar. Total seluruh dompet tampil di halaman **Ringkasan**.

---

## 3. Mencatat transaksi

1. Klik **+ Catat** (atau **+ Transaksi** di menu Transaksi).
2. Pilih jenis: **Pengeluaran**, **Pemasukan**, atau **Transfer**.
3. Isi **Nominal** — boleh mengetik `250000`, `250.000`, atau `250,000`; aplikasi merapikan sendiri.
4. Pilih **Tanggal**, **Akun**, dan **Kategori**. Untuk transfer, pilih akun tujuan.
5. Tambahkan **Catatan** bila perlu (misalnya "belanja bulanan").
6. Klik **Simpan**.

Nominal selalu dibaca sebagai angka positif; arah uang ditentukan oleh **jenis** transaksi. Mengetik `-50000` tetap tersimpan sebagai Rp 50.000.

### Mengubah atau menghapus
- Klik ikon ✎ pada baris transaksi untuk mengubah.
- Klik ikon 🗑 untuk menghapus; akan muncul konfirmasi lebih dahulu.

### Mencari dan menyaring
Kotak pencarian di menu **Transaksi** mencari ke **nama/keterangan, nama toko, nama barang pada rincian struk, kategori, akun, dan nominal**.

Contoh pemakaian:

| Yang diketik | Hasil |
|---|---|
| `indomaret` | semua transaksi yang menyebut Indomaret (catatan/toko/barang) |
| `58000` atau `58.000` | transaksi bernominal tepat Rp58.000 |
| `25rb` atau `1,5jt` | nominal Rp25.000 atau Rp1.500.000 |
| `>=100000` | nominal Rp100.000 ke atas (`>`, `<`, `<=` juga bisa) |
| `kopi 25000` | semua kata harus cocok: catatan berisi "kopi" **dan** nominal Rp25.000 |

Saringan lain: jenis, kategori, akun, serta dua kolom tanggal **dari–sampai**. Mengisi rentang tanggal otomatis melepas saringan bulan agar hasil tidak terpotong; memilih bulan kembali akan menghapus rentang tanggal. Tombol **Reset** mengembalikan semua saringan.

---

## 3a. Scan struk belanja (foto jadi transaksi)

Tombol **📷 Scan struk** ada di kanan atas (ikon kamera), di halaman **Ringkasan**, di halaman **Transaksi**, dan di **Pengaturan**.

1. Klik **📷 Scan struk**.
2. Pilih sumber gambar:
   - **📷 Kamera** — langsung memotret struk (di HP membuka kamera belakang);
   - **🖼️ Galeri** — memilih foto yang sudah ada di penyimpanan/album HP atau komputer. Cocok untuk struk lama, tangkapan layar (screenshot) pembayaran, dan foto yang dikirim lewat chat.
3. Tekan **Baca struk**.
Proses pertama butuh beberapa detik karena aplikasi memuat mesin OCR (± 13 MB, sekali saja dan tersimpan di cache browser).

4. Periksa hasil yang sudah terisi otomatis:
   - **Nama toko**, **Total belanja**, **Tanggal**, **Kategori** (ditebak dari nama toko), **Sumber rekening**, dan **Keterangan**;
   - tabel **Rincian barang** — nama, jumlah (qty), dan nilai tiap barang; semuanya bisa Anda ubah langsung;
   - **Teks hasil pembacaan** bila ingin memeriksa apa yang dibaca mesin.
5. Bila ada yang salah, perbaiki di form. Bila struk sama sekali tidak terbaca, gunakan tombol **Isi manual** (form langsung terbuka tanpa OCR) — fotonya tetap bisa disimpan sebagai bukti.
6. Klik **Simpan transaksi**.

Yang perlu diketahui:

- Foto diperkecil otomatis di browser (sisi terpanjang 1600 px, JPEG) supaya hemat kuota dan ruang hosting. Batas simpan di server **1,5 MB** per struk.
- Centang **Simpan foto struk pada transaksi** bila ingin arsip foto. Foto bisa dibuka lagi lewat ikon **🧾** pada baris transaksi.
- Foto disimpan di folder `data/struk/` (tidak bisa diakses langsung dari browser) dan **ikut terhapus** saat transaksi dihapus atau fotonya diganti.
- Butir **simpan rincian** menentukan apakah daftar barang ikut disimpan ke transaksi. Rincian tidak memengaruhi saldo; nominal transaksi tetap diambil dari kolom **Total belanja**.
- Teks struk dibaca **di perangkat Anda**. Yang dikirim ke server hanya teks hasil bacaan (untuk dicocokkan menjadi nominal/tanggal) dan foto struk bila Anda mencentang simpan foto.
- Gambar dari galeri diperlakukan sama seperti hasil kamera: diperkecil di browser, lalu dibaca mesin OCR. Tidak ada unggahan ke layanan luar.
- OCR terbaik pada foto tegak, terang, tidak miring, dan seluruh struk masuk bingkai. Hasil selalu bisa diedit — anggap sebagai isian awal, bukan keputusan akhir.

---

## 3a-1. Pemasukan QRIS DANA Business (dari tangkapan layar)

Aplikasi bisa mengenali **bukti pembayaran QRIS yang masuk ke DANA Business** dan langsung mengubahnya menjadi transaksi pemasukan.

1. Buka aplikasi DANA Business → menu **Riwayat/Notifikasi** → buka detail pembayaran yang masuk → **screenshot** layar tersebut.
2. Di Uang, klik **📷 Scan struk** → **🖼️ Galeri** → pilih screenshot tadi → **Baca struk**.
3. Aplikasi akan menandai hasilnya sebagai **pemasukan** (bukan pengeluaran):
   - **Nominal masuk** diambil dari baris total/pembayaran diterima;
   - **Nama pengirim** diisi dari baris “Dari/Pengirim” bila ada;
   - **Tanggal** diambil dari tanggal pada keterangan (tanggal masa depan otomatis dibatasi ke hari ini);
   - **Kategori** otomatis **Bisnis Osint**, **Sumber rekening** otomatis **DANA Business** (bisa diubah).
4. Periksa, lalu **Simpan transaksi**.

Catatan penting:

- Angka **saldo** dan **biaya admin** sengaja diabaikan agar nominal yang tercatat adalah uang yang benar-benar masuk.
- Bila nominal tidak terbaca, kolom nominal dibiarkan kosong dan muncul peringatan — isi manual sebelum menyimpan.
- Jenis transaksi bisa Anda ubah sendiri lewat saklar **Pengeluaran / Pemasukan** di form hasil scan.
- Berlaku juga untuk notifikasi QRIS lain (mis. GoPay Merchant, ShopeePay) selama teksnya memuat kata QRIS dan nominal pembayaran.

---

## 3b. Membeli emas digital (Lakuemas)

Menu **Emas** menampilkan harga emas digital Lakuemas terkini dan menyimpan catatan pembelian Anda.

**Harga**

- Dua angka besar: **harga beli** (saat Anda membeli) dan **harga jual** (nilai bila dijual) per gram, dibaca dari API publik `lakuemas.com` (`/api/get_buy` dan `/api/get_sell`).
- Harga disimpan sementara (cache) **5 menit** agar tidak membanjiri server Lakuemas; tombol **Perbarui harga** memaksa ambil ulang.
- Bila koneksi server sedang bermasalah, aplikasi memakai harga terakhir yang tersimpan dan menampilkan tanda **harga lama** beserta waktu pengambilannya.

**Mencatat pembelian**

1. Buka **Emas**, isi **Berat (gram)** (mis. `1`, `0,5`, `2`).
2. Kolom **Harga per gram** terisi otomatis dari harga beli — boleh diubah bila Anda mendapat harga lain.
3. Isi **Biaya tambahan** bila ada (administrasi/kirim), pilih **Sumber dana** dan **Tanggal**.
4. Angka **Total** dihitung otomatis. Klik **Simpan pembelian**.
5. Pembelian tercatat sebagai **pengeluaran** kategori **Emas Digital** (muncul di Transaksi, Ringkasan, dan Laporan), sekaligus menambah daftar kepemilikan emas.

**Daftar kepemilikan**

- Menampilkan tanggal, berat, harga per gram, modal, **nilai kini** (berat x harga jual), dan selisih untung/rugi.
- Ringkasan di atas tabel: total gram, total modal, nilai kini, dan untung/rugi.
- Tombol hapus menghapus catatan **beserta transaksi pengeluarannya**.

> Aplikasi ini **tidak** membeli emas atas nama Anda dan tidak menyimpan kredensial Lakuemas. Pembelian tetap Anda lakukan di aplikasi/situs Lakuemas; di sini hanya dibaca harga publiknya dan dicatat hasilnya. Selalu periksa harga di aplikasi resmi sebelum bertransaksi.

---

## 3c. Bunga SeaBank otomatis (cron PHP)

Bunga tabungan dicatat **otomatis setiap hari pukul 01:30 WIB** oleh cron PHP di server — bukan timer JavaScript, jadi browser boleh tertutup.

### Mengaktifkan

1. Buka **Pengaturan** lalu kartu **Bunga SeaBank otomatis**.
2. Centang **Aktifkan pencatatan otomatis**.
3. Pilih **Akun SeaBank**, **Mode**, dan **Jam jalan (WIB)**.
4. Periksa ketentuan bunga (semuanya **data yang bisa diubah**, bukan tertanam di kode):
   - bunga **2,5% per tahun** bila saldo **di bawah** ambang;
   - bunga **3,5% per tahun** bila saldo **mencapai/melebihi** ambang (bawaan Rp150.000.000);
   - **pajak bunga 20%**;
   - **hari per tahun 365**.
5. Klik **Simpan setelan**.

### Mode simulasi vs aktual

| Mode | Yang terjadi |
|---|---|
| **Simulasi** (bawaan) | Bunga dihitung dan ditulis ke log saja. **Tidak ada transaksi baru, saldo tidak berubah.** Aman untuk mencoba. |
| **Aktual** | Bunga dicatat sebagai pemasukan dan saldo akun bertambah. |

Tombol **Simulasi hari ini** selalu aman (tidak menyimpan). Tombol **Jalankan sekarang** mengikuti mode yang dipilih.

### Perhitungan

```
bunga kotor  = saldo akhir hari sebelumnya x suku bunga / 365
pajak        = bunga kotor x 20%
bunga bersih = bunga kotor - pajak
```

Contoh saldo Rp3.639.839 (bunga 2,5%): kotor Rp249,30, pajak Rp49,86, **bersih Rp199,44**. Nominal itulah yang dicatat (transaksi: tipe **income**, kategori **Bunga Tabungan**, keterangan **Bunga Harian SeaBank**, akun **SeaBank**, pukul 01:30 WIB).

### Yang dijamin

- **Tidak dobel**: setiap catatan punya kunci `bunga:<akun>:<tanggal>`. Cron dijalankan dua kali (atau bersamaan) tetap menghasilkan **satu** transaksi per akun per tanggal.
- **Atomik**: transaksi dan pembaruan saldo terjadi dalam satu penulisan. Bila penyimpanan gagal, **saldo tidak berubah** dan statusnya dilaporkan gagal.
- **Saldo dasar** = saldo akun dari transaksi **sebelum** tanggal bunga (saldo akhir hari sebelumnya).
- **Log eksekusi** tersimpan di `data/logs/bunga-YYYY-MM.log` (satu baris JSON per eksekusi: waktu, mode, status, saldo, bunga kotor/pajak/bersih, ID transaksi). Ringkasan log terakhir juga tampil di kartu Pengaturan.
- Transaksi bunga tampil di **Transaksi** (dengan penanda **otomatis**), **Ringkasan**, dan **Laporan Pendapatan** (kategori Bunga Tabungan).

### Memasang cron job

**cPanel (hosting bersama)**

1. cPanel lalu **Cron Jobs** lalu **Add New Cron Job**.
2. Isi **Minute** `30`, **Hour** `1`, **Day** `*`, **Month** `*`, **Weekday** `*`.
3. Command (sesuaikan jalur; lihat hasil `pwd` di File Manager):

```bash
/usr/bin/php /home/USER/public_html/Uang/cron/bunga_seabank.php --quiet
```

Bila `/usr/bin/php` tidak ada, coba `/usr/local/bin/php` atau jalur PHP dari **MultiPHP Manager**.

**VPS/Linux**

```bash
crontab -e
# tambahkan:
30 1 * * * /usr/bin/php /var/www/uang/cron/bunga_seabank.php --quiet
```

Pastikan zona waktu server WIB, atau biarkan aplikasi yang menentukan tanggal (aplikasi memakai `Asia/Jakarta`):

```bash
timedatectl set-timezone Asia/Jakarta
```

**Hosting yang hanya mengizinkan URL**

Kartu Pengaturan menampilkan alamat lengkap dengan token rahasia. Contoh:

```bash
30 1 * * * curl -s "https://alamat-anda.com/Uang/cron/bunga_seabank.php?token=TOKEN_ANDA" > /dev/null
```

Tanpa token yang benar, alamat itu menjawab **403** dan tidak melakukan apa pun. Tombol **Ganti token** membuat token baru (alamat lama langsung tidak berlaku).

### Menguji tanpa menunggu jam 01:30

```bash
# lihat hasil perhitungan tanpa menyimpan
php cron/bunga_seabank.php --simulate --json

# catat sungguhan untuk tanggal tertentu (hormati saklar aktif/mode)
php cron/bunga_seabank.php --date=2026-11-19 --json
```

Pada Windows (XAMPP): `C:\xampp\php\php.exe cron\bunga_seabank.php --simulate --json`.

### Bila bunga tidak muncul

1. Pastikan saklar **Aktifkan pencatatan otomatis** menyala dan **Mode = Aktual**.
2. Buka `diagnosa.php` dan periksa baris **skrip cron bunga**, **Folder data/logs/**, dan **PHP CLI untuk cron job**.
3. Lihat `data/logs/bunga-YYYY-MM.log`; status yang mungkin: `created` (berhasil), `already` (sudah ada hari itu), `simulated`, `disabled`, `no-account`, `zero-balance`, `too-small`, `error`.
4. Bila hosting memakai URL, pastikan token sama dengan yang tampil di Pengaturan.

---

## 3d. Akun otomatis per kategori

Supaya tidak perlu memilih rekening berulang kali, setiap kategori bisa dipasangkan ke satu akun.

1. Buka **Pengaturan** → kartu **Akun otomatis per kategori**.
2. Untuk tiap kategori, pilih akun pada daftar di sebelahnya (mis. **Bisnis Osint → DANA Business**).
3. Klik **Simpan aturan**.

Cara kerjanya:

- Saat mencatat transaksi dan memilih kategori tersebut, **Sumber rekening** langsung terisi akun pasangannya.
- Aturan hanya saran awal: Anda tetap bisa mengganti akun secara manual, dan pilihan manual itu yang dipakai.
- Aturan juga dipakai pada hasil **Scan struk/QRIS**, kecuali aplikasi sudah mendeteksi akun lain dari struk.
- Pilihan **— tidak diatur —** mematikan aturan untuk kategori itu.
- Aturan disimpan di dalam data aplikasi (`settings.accountRules`), jadi ikut terbawa saat cadangan dipulihkan atau aplikasi dipindah hosting.

---

## 4. Mengatur anggaran

1. Buka **Anggaran**, klik **+ Anggaran**.
2. Pilih kategori dan isi batas nominal untuk bulan tersebut.
3. Klik **Simpan**. Bar progres akan menunjukkan berapa persen yang sudah terpakai.
4. Warna bar berubah menjadi peringatan saat pemakaian mendekati atau melewati batas.

Tombol **Salin dari bulan lalu** menyalin semua anggaran bulan sebelumnya ke bulan yang sedang dibuka — praktis untuk anggaran yang rutin sama.

---

## 5. Target tabungan

1. Buka **Target**, klik **+ Target**.
2. Isi nama target, nominal yang ingin dicapai, dan tanggal perkiraan (opsional).
3. Simpan, lalu tambahkan setoran lewat tombol **Setor** atau catat penarikan bila dana dipakai.

Catatan penting: setoran target adalah **catatan perencanaan** dan tidak mengubah saldo akun. Bila uang benar-benar dipindahkan, catat juga sebagai **Transfer** antar akun di menu Transaksi.

---

## 6. Utang dan piutang

- **+ Utang** untuk mencatat kewajiban Anda kepada orang lain.
- **+ Piutang** untuk mencatat uang Anda yang dipinjam orang lain.
- Tombol **Bayar** / **Terima bayaran** mencatat pembayaran sebagian atau penuh.
- **Riwayat** menampilkan semua pembayaran; satu pembayaran bisa dihapus bila salah catat.
- Bila sisa mencapai nol, catatan otomatis berlabel **lunas**.

---

## 7. Laporan, CSV, dan cetak

- Menu **Laporan** punya empat periode: **Harian**, **Mingguan** (Senin–Minggu), **Bulanan**, dan **Tahunan**. Gunakan tombol ‹ › untuk mundur/maju satu periode, atau **Sekarang** untuk kembali ke periode berjalan.
- Setiap periode menampilkan pemasukan, pengeluaran, arus kas bersih, saldo total, grafik arus kas, komposisi pengeluaran, tabel per kategori, tabel per dompet, dan rincian per hari/bulan.
- **⬇ CSV** mengunduh transaksi pada periode yang sedang dibuka (termasuk saat memakai rentang tanggal); file bisa dibuka di Excel/Google Sheets.
- **🖨 Cetak** menyiapkan halaman rapi untuk dicetak atau disimpan sebagai PDF (pilih "Save as PDF" pada dialog cetak).

---

## 8. Pengaturan

- **Nama aplikasi** dan **nama pemilik** — tampil di header.
- **Tema** — gelap atau terang.
- **Mata uang** dan format angka.
- **Akun** — tambah, ubah, hapus akun (tunai, bank, e-wallet). Akun yang sudah dipakai transaksi tidak bisa dihapus; nonaktifkan saja agar tetap tersimpan di riwayat.
- **Kategori** — tambah/ubah/hapus kategori pemasukan dan pengeluaran beserta warna dan ikonnya.
- **Data contoh** — muat/hapus data mock untuk mencoba aplikasi.
- **Cadangan** — unduh seluruh data sebagai satu file JSON, atau pulihkan dari file tersebut.
- **Scan struk belanja** — kartu informasi berisi jumlah transaksi yang sudah punya foto struk dan tombol untuk membuka pemindai.

### Penting soal cadangan
Unduh cadangan secara berkala (misalnya sekali sebulan) dan simpan di tempat aman. File cadangan berisi seluruh catatan keuangan Anda.

---

Kartu **Akun otomatis per kategori** memasangkan tiap kategori ke satu rekening (lihat bagian 3d). Kartu ini juga tempat mengatur profil, tema, dan format angka.

---

## 9. Keamanan data

- Aplikasi ini dilindungi **satu token akses**. Tanpa sesi yang sah, halaman hanya menampilkan form token dan seluruh API menolak permintaan (`401`).
- Token disimpan sebagai hash `password_hash()` dan diverifikasi dengan `password_verify()` — token asli tidak pernah ditulis ke berkas, URL, atau log.
- Sesi berlaku **tepat 24 jam** sejak login dan tidak diperpanjang oleh aktivitas. Cookie sesi memakai `HttpOnly`, `SameSite=Lax`, dan `Secure` saat situs sudah HTTPS.
- Session ID dibuat ulang setiap login (32 byte acak, disimpan sebagai hash). Cookie sesi diset dengan masa berlaku 24 jam agar sesi bertahan setelah browser ditutup.
- Percobaan token salah dibatasi **5 kali per 15 menit per IP**; setelah itu muncul pesan tunggu.
- Semua POST memakai token CSRF per sesi (header `X-CSRF-Token`). Permintaan tanpa token CSRF ditolak (`419`).
- `data/auth.php` (token + sesi) ikut diblokir `.htaccess`, tidak ikut dalam cadangan ZIP, dan tidak ikut paket deploy.
- **Gunakan HTTPS.** Bila situs belum HTTPS, halaman token menampilkan peringatan agar Anda memasang SSL di cPanel (AutoSSL/Let's Encrypt).
- Folder `data/`, `inc/`, dan `backup/` sudah diblokir dari akses web melalui `.htaccess`.
- Foto struk disimpan di `data/struk/` dan hanya bisa dibuka lewat `api.php?action=receipt.get&id=...` (aplikasi memeriksa dulu bahwa transaksinya ada). Folder itu punya `.htaccess` sendiri yang menolak akses langsung.
- Berkas data disimpan sebagai `data/db.php` dan dibuka dengan `<?php exit; ?>`. Bila alamatnya dibuka lewat browser (atau `.htaccess` tidak aktif, misalnya di nginx), server berhenti di baris pertama sehingga isi catatan keuangan tidak pernah terkirim.
- Permintaan yang mengubah data hanya diterima dari aplikasi sendiri (header `X-Requested-With`), sehingga formulir dari situs lain tidak bisa menulis data.
- Halaman diberi tanda `noindex, nofollow` agar tidak muncul di mesin pencari.

---

## 9a. Cadangan & pulihkan (agar tidak perlu input ulang)

Data Anda hanya ada di satu berkas: `data/db.php`. Jadi mencadangkan = menyalin
berkas itu. Ada tiga cara, pakai salah satu (disarankan dua-duanya).

### A. Unduh dari aplikasi (paling mudah, tiap bulan)

1. Buka **Pengaturan** → kartu **Data & cadangan**.
2. Klik **⬇ Unduh cadangan (JSON)**. Berkas `uang-backup-YYYYMMDD-HHMMSS.json`
   masuk ke folder Unduhan.
3. Simpan di tempat aman: Google Drive/OneDrive, flashdisk, atau kirim ke diri
   sendiri lewat email.

### B. Cadangan lengkap termasuk foto struk (ZIP)

Jalankan di komputer tempat aplikasi berada:

```
python tools/backup.py
```

Hasilnya `backup/uang-backup-YYYYMMDD-HHMMSS.zip` berisi:

- `db.json` — seluruh data (akun, transaksi, anggaran, target, utang, emas, setelan)
- `db.php` — berkas asli, bisa langsung dipakai menimpa `data/db.php`
- `struk/` — semua foto struk
- `CARA-PULIHKAN.txt` — petunjuk singkat

Mau disimpan di tempat lain (mis. flashdisk)? Sebutkan tujuannya:

```
python tools/backup.py D:/arsip/uang-2026-11.zip
```

Sebelum mengubah/menambah fitur, biasakan jalankan ini dulu — satu perintah,
semua data (termasuk foto) ikut tersimpan.

### C. Cadangan otomatis (sudah jalan sendiri)

Setiap kali data berubah, aplikasi menyalin kondisi **sebelum** perubahan ke
`backup/data/db-YYYYMMDD_HHMMSS.php`. Maksimal 40 berkas terakhir, yang paling
lama dibuang otomatis. Jadi salah hapus transaksi pun masih bisa diselamatkan.

### Cara memulihkan (kalau data hilang / salah ubah)

Lewat aplikasi:

1. **Pengaturan** → **Data & cadangan** → **⬆ Pulihkan dari berkas**.
2. Pilih berkas `.json` hasil cadangan (kalau dari ZIP, ekstrak dulu `db.json`).
3. Muncul konfirmasi **Pulihkan data?** → klik **Pulihkan**.
4. Data saat ini otomatis disalin dulu ke `backup/data/`, baru diganti. Jadi
   pemulihan pun bisa dibatalkan dengan memulihkan snapshot tadi.

Foto struk **tidak** ikut lewat berkas JSON. Kalau pindah hosting atau foto
hilang, ekstrak folder `struk/` dari ZIP ke `data/struk/` di server.

Manual tanpa aplikasi (kalau halaman tidak bisa dibuka):

1. Unduh `data/db.php` yang sekarang ke komputer (jaga-jaga).
2. Timpa `data/db.php` dengan `db.php` dari ZIP cadangan.
3. Pastikan izin berkas 644 dan folder `data/` 755 (atau 775 bila hosting
   memakai PHP sebagai pengguna lain), lalu buka aplikasi.

### Pindah hosting tanpa input ulang

1. Cadangkan: `python tools/backup.py`.
2. Unggah aplikasi baru ke hosting tujuan: pakai `as-deploy.zip` bila ingin data
   langsung ikut, atau `as.zip` bila akan mengunggah `db.php` sendiri.
3. Unggah `db.php` dari ZIP ke `data/db.php` (timpa berkas contoh).
4. Unggah semua isi `struk/` ke `data/struk/`.
   - Lebih cepat: pakai `as-deploy.zip` — `db.php` dan foto struk sudah ada di dalamnya,
     jadi langkah 3 dan 4 tidak perlu dilakukan lagi.
5. Buka aplikasi → cek **Ringkasan** dan **Transaksi**; jumlah transaksi harus
   sama dengan sebelumnya.
6. Bila memakai bunga otomatis: buka **Pengaturan** → kartu bunga → **Ganti
   token**, lalu perbarui cron dengan token baru.

Catatan: berkas cadangan berisi seluruh keuangan Anda — jangan dibagikan ke
orang lain dan jangan diunggah ke folder publik hosting.

## 10. Struktur file

```
index.php            Halaman aplikasi
api.php              Endpoint data (JSON)
diagnosa.php         Pemeriksa hosting (hapus setelah aplikasi normal)
assets/style.css     Tampilan
assets/scan.css      Tampilan khusus pemindai struk
assets/fitur.css     Tampilan kartu bunga otomatis & halaman emas
assets/app.js        Logika antarmuka & perhitungan
assets/ocr/          Mesin OCR (tesseract.js + bahasa Indonesia) — dipakai saat scan struk
inc/bootstrap.php    Penjaga versi PHP & kompatibilitas
inc/galat.php        Halaman pesan kesalahan yang bisa dibaca
inc/config.php       Konfigurasi & nilai awal (bisa diubah)
inc/helpers.php      Utilitas
inc/store.php        Baca/tulis data JSON (aman untuk request bersamaan)
inc/finance.php      Perhitungan untuk ekspor & ringkasan
inc/demo.php         Data contoh sintetis
inc/struk.php        Pembaca teks struk + penyimpanan foto struk
inc/interest.php     Bunga harian otomatis: hitung, pencegahan dobel, atomic write, log
inc/gold.php         Harga emas Lakuemas + pencatatan pembelian emas
cron/bunga_seabank.php  Skrip cron bunga (CLI atau URL bertoken)
data/db.php          Data Anda (dibuat otomatis saat pertama dipakai, tidak bisa diunduh)
data/struk/          Foto struk (dibuat otomatis, maksimal 1,5 MB per berkas)
data/logs/           Log eksekusi cron bunga (bunga-YYYY-MM.log)
data/cache/          Cache harga emas (gold.json, 5 menit)
data/cron-token.php  Token rahasia untuk memicu cron lewat URL (dibuat otomatis, tidak bisa dibaca dari browser)
backup/              Tempat cadangan manual
test/                Skrip uji (tidak wajib diunggah ke hosting; berisi gambar struk MOCK)
```

Nilai awal (akun, kategori, mata uang, tema) adalah **data** yang dapat diubah lewat menu Pengaturan — bukan tertanam di kode.

---

## 11. Kalau muncul HTTP 500 setelah diunggah

HTTP 500 berarti server berhenti sebelum halaman sempat tampil. Tiga sebab paling sering:

1. **Versi PHP hosting terlalu lama.** Aplikasi butuh **PHP 7.4 atau lebih baru** (disarankan 8.1–8.3). Kalau versi lebih lama, aplikasi sekarang menampilkan halaman penjelasan, bukan 500 kosong. Ubah di cPanel → **MultiPHP Manager**.
2. **Berkas `.htaccess` tidak didukung penuh.** Versi paket ini sudah dibuat tanpa `Options -Indexes` dan tanpa `php_value`, dua direktif yang paling sering ditolak hosting bersama. Bila masih 500, ganti nama `.htaccess` menjadi `.htaccess-off` lalu muat ulang: kalau halaman jadi normal, penyebabnya `.htaccess`.
3. **Folder `data/` tidak bisa ditulis.** Setel izin `755` (atau `775` bila perlu), dan berkas `data/db.php` menjadi `644`.

Langkah cepat memeriksa:

1. Buka `https://alamat-anda.com/diagnosa.php` — halaman ini menampilkan versi PHP, ekstensi, izin folder, dan hasil uji tulis, lengkap dengan saran perbaikan.
2. Bila semuanya hijau tetapi halaman utama tetap 500, buka cPanel → **Errors** (atau berkas `error_log` di `public_html`) dan cari baris `PHP Fatal error`.
3. Setelah aplikasi normal, **hapus `diagnosa.php`** dari hosting.

Halaman galat aplikasi juga menyebutkan penyebab yang paling mungkin, misalnya izin folder, dan bisa diminta lebih rinci dengan menambahkan `?debug=1` di alamatnya.

---

## 12. Untuk pengembang — menjalankan uji

Uji yang tidak butuh server (data sementara, tidak menyentuh data pengguna):

```bash
php test/struk_parse_test.php   # 79 pemeriksaan — parser struk & QRIS DANA Business
php test/bunga_test.php         # 67 pemeriksaan — bunga, pajak, saldo, anti-dobel, log, token
php test/gold_test.php          # 53 pemeriksaan — harga emas, pembelian, hapus, ringkasan
```

Uji API & antarmuka otomatis (menyiapkan salinan aplikasi + data SINTETIS sendiri):

```bash
python test/api_fitur_test.py   # 99 pemeriksaan — API bunga, cron CLI/HTTP, emas, aturan akun, QRIS
python test/run_ui_fitur.py     # 70 pemeriksaan — pencarian, emas, kartu bunga, galeri, QRIS, aturan akun, pulihkan berkas, tata letak
python test/smoke_paket.py      # 17 pemeriksaan — paket as.zip hasil build (butuh tools/build_zip.py)
python test/backup_test.py      # 19 pemeriksaan — unduh cadangan, pulihkan, snapshot, ZIP
python test/deploy_paket_test.py # 15 pemeriksaan — as-deploy.zip berisi data yang sama + gerbang token
python test/auth_test.py        # 56 pemeriksaan — token, sesi 24 jam, CSRF, batas percobaan, perangkat, ganti token
python test/run_ui_auth.py      # 18 pemeriksaan — setup token, login, kartu Keamanan, logout, token lama ditolak
```

Uji fitur scan struk (menyiapkan server + Edge headless sendiri, data SINTETIS):

```bash
python test/run_api_struk.py    # 25 pemeriksaan - API scan struk (butuh requests + Pillow)
python test/run_ui_struk.py     # 25 pemeriksaan - OCR sungguhan dari foto mock lewat input Galeri
```

Bila ingin menjalankan manual tanpa skrip pembungkus:

```bash
php -S 127.0.0.1:8099 -t .                       # terminal 1
python test/api_struk_test.py                    # terminal 2 (UANG_ROOT bisa diarahkan)
"/c/Program Files (x86)/Microsoft/Edge/Application/msedge.exe" \
  --headless=new --remote-debugging-port=9333 \
  --user-data-dir="$LOCALAPPDATA/Temp/uang-edge" about:blank &
node test/ui_struk_test.mjs
```
```

Catatan: `test/sandbox.py` menyalin aplikasi ke folder sementara (`data/` baru, transaksi sintetis
bertanda uji), jadi `api_fitur_test.py` dan `run_ui_fitur.py` aman dijalankan berulang kali.

Catatan uji:

- `test/fixtures/struk_mock.png` dibuat otomatis oleh `api_struk_test.py`; isinya **struk sintetis** berlabel `CONTOH STRUK - DATA MOCK`.
- `test/ocr_probe.html` adalah halaman diagnosa OCR (`?lang=ind&oem=1&raw=1`, tambahkan `&cdn=1` untuk membandingkan dengan CDN).
- `api_struk_test.py` dan `ui_struk_test.mjs` membuat transaksi uji lalu **menghapusnya lagi** di akhir. Tetap jalankan pada data contoh/salinan, bukan data asli.
- `bunga_test.php` dan `gold_test.php` memakai folder `DATA_DIR` sementara, jadi log/token/cache uji tidak masuk ke data Anda.
- `api_fitur_test.py` menguji juga cron: CLI (`--date`, `--simulate`), penolakan tanggal salah, saklar nonaktif, dan token URL (403 tanpa token).
- `auth_test.py` dan `run_ui_auth.py` memakai token uji (`token-uji-...`) di salinan sementara; token asli Anda tidak pernah dipakai.
- Semua data uji bersifat **SINTETIS/MOCK**.

### Catatan teknis fitur scan struk

- Mesin OCR: `tesseract.js` 5.1.1 + `tesseract.js-core` (varian LSTM) + `ind.traineddata`, semuanya disajikan dari `assets/ocr/` — tanpa CDN, tanpa mengirim gambar ke pihak ketiga.
- Bahasa harus **tanpa kompresi** (`ind.traineddata`, bukan `.gz`) dan `gzip: false`; kombinasi `.gz` gagal saat inisialisasi di sebagian browser.
- `workerBlobURL: false` + URL absolut wajib, karena worker yang dijalankan dari blob tidak bisa menyelesaikan path relatif.
- Parser (`inc/struk.php`) bekerja di server dan tidak menyentuh basis data: `receipt.parse` hanya mengembalikan usulan isian.
- Foto struk divalidasi ulang di server (mime asli lewat `getimagesizefromstring`, batas 1,5 MB) sebelum disimpan.

---

## 13. Riwayat versi

| Versi | Perubahan |
|---|---|
| 1.4.0 | Token akses tunggal (permanen, diganti manual), sesi 24 jam, CSRF, batas percobaan, riwayat login, daftar perangkat, keluarkan perangkat, ganti token, alat `tools/token.php`. |
| 1.3.0 | Scan struk dari kamera **atau galeri**, pemasukan QRIS DANA Business, akun otomatis per kategori (Bisnis Osint → DANA Business). |
| 1.2.0 | Bunga SeaBank otomatis, emas Lakuemas, pencarian transaksi, cadangan ZIP, paket deploy. |

---

⚠️ Data contoh dalam aplikasi ini SINTETIS/MOCK — testing only, bukan data orang nyata.
