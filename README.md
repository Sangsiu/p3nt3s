# 🛡️ Pentest Report Generator V3

Aplikasi berbasis web (PHP) untuk mengonversi berkas laporan scan keamanan dari **Burp Suite (XML & HTML)** menjadi dokumen laporan rekomendasi formal Word (**DOCX**) menggunakan template kustom secara otomatis.

Aplikasi ini dilengkapi dengan modul penerjemah cerdas (Indonesian Translation Module) untuk menerjemahkan temuan secara otomatis, baik menggunakan kamus bawaan maupun Google Translate API (secara gratis) yang dilengkapi dengan caching.

---

## ✨ Fitur Unggulan

1. **Intelligent XML/HTML Parser**: Mendukung parse berkas hasil scan Burp Suite baik dalam format XML (standar/DocBook) maupun HTML.
2. **Kamus Terjemahan Indonesia bawaan ($VULN_TRANSLATION)**: Berisi kamus kerentanan paling lengkap beserta mapping skor CVSS dan CWE ke Bahasa Indonesia yang baku.
3. **Penerjemah Cadangan Cerdas (Google Translate integration)**:
   - Jika kerentanan tidak terdaftar di kamus, modul akan menerjemahkan deskripsi dan rekomendasi secara otomatis via Google Translate.
   - Dilengkapi **Local JSON Caching** (`translate_cache.json`) agar teks yang sama tidak perlu dikirim ulang ke internet (menghemat kuota, performa super cepat, dan anti rate-limit).
4. **Intelligent Filename & Input Detection**:
   - Mendeteksi nama aplikasi secara dinamis dari tag `<title>` pada response HTTP di dalam XML.
   - Mendeteksi jenis OPD & Nomor Surat default secara cerdas berdasarkan kata kunci nama file.
   - Otomatis menamai dokumen Word hasil unduhan dengan format: `SANDI_REKOMANDASI_[NAMA_APLIKASI]_[TANGGAL].docx`.
5. **Aesthetically Pleasing UI**: Desain antarmuka modern bernuansa *dark mode/light mode* yang responsif dilengkapi fitur *drag-and-drop* file.

---

## 📋 Persyaratan Sistem
- **PHP**: Versi 7.4 ke atas (Direkomendasikan PHP 8.0+)
- **PHP Extensions**: `zip`, `xml`, `mbstring`, `curl`, `openssl`
- **Composer**: Untuk instalasi dependensi Google Translate library

---

## 💻 Cara Menjalankan di Lokal (Localhost)

### Langkah 1: Kloning / Letakkan Source Code
Letakkan seluruh file project ini di folder kerja Anda (misalnya di folder XAMPP `htdocs` atau folder workspace Anda).

### Langkah 2: Install Dependensi (Composer)
Buka terminal (CMD / PowerShell / Bash) di folder project ini, lalu jalankan:
```bash
# Jika composer.phar sudah terunduh di direktori
php composer.phar install

# Atau jika Composer sudah terinstall global di sistem Anda
composer install
```

### Langkah 3: Jalankan Local PHP Server
Anda dapat langsung menggunakan PHP built-in server tanpa perlu XAMPP:
```bash
php -S localhost:9090 -t .
```
Buka browser Anda dan akses: **[http://localhost:9090](http://localhost:9090)**

---

## 🌐 Cara Install di Hosting

### Opsi A: Menggunakan SSH (VPS / Cloud / Shared Hosting dengan Terminal)
1. Upload folder project ke direktori web server (misal `/public_html` atau `/var/www/html`).
2. Masuk ke SSH server, navigasikan ke direktori project Anda.
3. Unduh composer local & jalankan install:
   ```bash
   curl -sS https://getcomposer.org/installer | php
   php composer.phar install
   ```
4. Pastikan file `template.docx` berada di folder yang sama dengan `index.php`.

### Opsi B: Tanpa SSH (Shared Hosting Konvensional / Upload FTP)
Jika panel hosting Anda tidak menyediakan akses terminal / SSH:
1. Jalankan perintah `composer install` di **komputer lokal Anda** terlebih dahulu.
2. Setelah folder `vendor/` terbuat di lokal, upload **seluruh** folder project (termasuk folder `vendor/` dan `composer.json`) ke hosting menggunakan **FTP (FileZilla)** atau **cPanel File Manager**.

> ⚠️ **PENTING**:
> Agar fitur Google Translate online berjalan lancar di server hosting, pastikan koneksi keluar (outbound request) untuk cURL ke `https://translate.google.com` tidak diblokir oleh firewall hosting Anda.

---

## 🛠️ Pemeliharaan & Troubleshooting

### 1. Masalah SSL Certificate (cURL error 60) di Lokal
Jika saat pengujian lokal muncul error *cURL error 60: SSL certificate*, hal ini terjadi karena PHP lokal Anda belum memiliki sertifikat CA tepercaya. 
**Solusi**:
1. Unduh CA Bundle terbaru dari [curl.se/ca/cacert.pem](https://curl.se/ca/cacert.pem).
2. Simpan file `cacert.pem` di folder PHP Anda.
3. Buka file `php.ini`, temukan baris berikut lalu ubah dan aktifkan (hapus titik koma `;` di depannya):
   ```ini
   curl.cainfo = "C:/path/to/php/cacert.pem"
   openssl.cafile = "C:/path/to/php/cacert.pem"
   ```
4. Restart web server / PHP CLI Anda.

### 2. Mengosongkan / Mereset Cache Terjemahan
Jika Anda merasa ada terjemahan yang kurang tepat dan ingin memperbaruinya dengan terjemahan baru dari Google Translate:
Hapus saja berkas **`translate_cache.json`** yang ada di direktori utama, sistem akan otomatis membuat ulang file cache baru ketika Anda melakukan kompilasi laporan berikutnya.

---

*Hak Cipta &copy; 2026 Sandikami Generator Pentest Report. Dibuat oleh Sangsiu.*
