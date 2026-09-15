<?php
/**
 * Pentest Report Generator V3 in PHP
 * Converts Burp Suite XML Scan reports into Word Document Reports using template.docx
 */

// Load Composer autoload (Google Translate library)
$_composerAutoload = __DIR__ . '/vendor/autoload.php';
if (file_exists($_composerAutoload)) {
    require_once $_composerAutoload;
    define('GOOGLE_TRANSLATE_AVAILABLE', true);
} else {
    define('GOOGLE_TRANSLATE_AVAILABLE', false);
    if (php_sapi_name() === 'cli') {
        echo "[INFO] vendor/autoload.php tidak ditemukan. Jalankan: composer install\n";
        echo "[INFO] Akan menggunakan fallback terjemahan parsial.\n";
    }
}

// =========================================================================
// KONFIGURASI API KEYS UNTUK MULTI-AI PROVIDER
// Dibaca dari environment variable atau file .env lokal (tidak di-commit).
// Gunakan koma untuk beberapa key agar rotasi tetap aktif.
// =========================================================================
function loadLocalEnvFile($path) {
    if (!is_file($path) || !is_readable($path)) return;
    $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $name = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if ($name === '' || getenv($name) !== false) continue;
        $value = trim($value, "\"'");
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

loadLocalEnvFile(__DIR__ . '/.env');

define('AI_KEY_GROQ', getenv('AI_KEY_GROQ') ?: '');
define('AI_KEY_GROQ_2', getenv('AI_KEY_GROQ_2') ?: '');
define('AI_KEY_GROQ_3', getenv('AI_KEY_GROQ_3') ?: '');
define('AI_KEY_GROQ_4', getenv('AI_KEY_GROQ_4') ?: '');
define('AI_KEY_GROQ_5', getenv('AI_KEY_GROQ_5') ?: '');
define('AI_KEY_OPENAI', getenv('AI_KEY_OPENAI') ?: '');
define('AI_KEY_GEMINI', getenv('AI_KEY_GEMINI') ?: '');
define('AI_KEY_OPENROUTER', getenv('AI_KEY_OPENROUTER') ?: '');

function parseApiKeys($raw) {
    if (is_array($raw)) return array_values(array_filter(array_map('trim', $raw)));
    $s = trim((string)$raw);
    if ($s === '') return [];
    return array_values(array_filter(array_map('trim', explode(',', $s))));
}
function getProviderKeys($provider, $customKey = null) {
    if (!empty($customKey)) {
        $ck = parseApiKeys($customKey);
        if (!empty($ck)) return $ck;
    }
    $map = ['groq'=>'AI_KEY_GROQ','openai'=>'AI_KEY_OPENAI','gemini'=>'AI_KEY_GEMINI','openrouter'=>'AI_KEY_OPENROUTER'];
    $k = strtolower($provider);
    if (!isset($map[$k])) return [];
    $const = $map[$k];
    $keys = [];
    if (defined($const)) {
        $keys = array_merge($keys, parseApiKeys(constant($const)));
        foreach ([2,3,4,5] as $n) {
            $c2 = $const . '_' . $n;
            if (defined($c2) && trim((string)constant($c2)) !== '') {
                foreach (parseApiKeys(constant($c2)) as $kk) $keys[] = $kk;
            }
        }
    }
    if (function_exists('getStoredKeysForProvider')) {
        foreach (getStoredKeysForProvider($k) as $sk) $keys[] = $sk;
    }
    return array_values(array_unique(array_filter(array_map('trim', $keys))));
}
function isRateLimitedHttp($code, $body) {
    if ($code === 429 || $code === 503) return true;
    if (!is_string($body)) return false;
    $l = strtolower($body);
    return (strpos($l, 'rate') !== false && strpos($l, 'limit') !== false) || strpos($l, 'too many requests') !== false || strpos($l, 'quota') !== false || strpos($l, 'rate_limit') !== false;
}

function getKeysStorePath() { return __DIR__ . '/data/cache/keys.json'; }
function getKeysEncryptionKeyRaw() { static $k=null; if($k===null) $k=hash('sha256', __DIR__ . '|pentest_keys_v1_secret|', true); return $k; }
function encryptStoredKey($plain) {
    $key = getKeysEncryptionKeyRaw();
    $iv = openssl_random_pseudo_bytes(16);
    if ($iv === false) $iv = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}
function decryptStoredKey($enc) {
    $key = getKeysEncryptionKeyRaw();
    $raw = base64_decode($enc, true);
    if ($raw === false || strlen($raw) < 17) return null;
    $iv = substr($raw, 0, 16);
    $cipher = substr($raw, 16);
    $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
    return $plain === false ? null : $plain;
}
function readStoredKeysRaw() {
    $path = getKeysStorePath();
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) ? $j : [];
}
function writeStoredKeysRaw($data) {
    $path = getKeysStorePath();
    $dir = dirname($path);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    return @file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX) !== false;
}
function getStoredKeysForProvider($provider) {
    $provider = strtolower(trim($provider));
    $store = readStoredKeysRaw();
    if (!isset($store[$provider]) || !is_array($store[$provider])) return [];
    $out = [];
    foreach ($store[$provider] as $enc) {
        $dec = decryptStoredKey($enc);
        if ($dec !== null && trim($dec) !== '') $out[] = trim($dec);
    }
    return $out;
}
// =========================================================================


function getDefaultModelFromCache($provider) {
    $p = strtolower(trim($provider));
    $cacheFile = __DIR__ . '/data/cache/ai_models.json';
    if (!file_exists($cacheFile)) return null;
    $raw = @file_get_contents($cacheFile);
    $j = $raw ? json_decode($raw, true) : null;
    if (!is_array($j) || !isset($j['models'][$p]) || !is_array($j['models'][$p])) return null;
    foreach ($j['models'][$p] as $o) {
        $v = isset($o['value']) ? trim($o['value']) : '';
        if ($v !== '' && $v !== 'custom') return $v;
    }
    return null;
}

// Severity Mapping Indonesian
$SEVERITY_MAP = [
    'High' => 'Tinggi (High)',
    'Medium' => 'Sedang (Medium)',
    'Low' => 'Rendah (Low)',
    'Information' => 'Informasi (Information)',
    'Critical' => 'Kritis (Critical)'
];

$MONTH_MAP = [
    'Jan' => 'Januari', 'Feb' => 'Februari', 'Mar' => 'Maret', 'Apr' => 'April',
    'May' => 'Mei', 'Jun' => 'Juni', 'Jul' => 'Juli', 'Aug' => 'Agustus',
    'Sep' => 'September', 'Oct' => 'Oktober', 'Nov' => 'November', 'Dec' => 'Desember'
];

// Translation dictionary for vulnerabilities with CVSS scores and CWE mapping
$VULN_TRANSLATION = [
    'tls certificate' => [
        'name' => 'Sertifikat TLS/SSL Valid',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-295',
        'detail' => 'Sertifikat TLS/SSL digunakan untuk mengamankan enkripsi data dalam perjalanan antara browser pengguna dan server. Temuan ini menunjukkan sertifikat yang digunakan saat ini sudah valid dan diterbitkan oleh otoritas terpercaya. Tidak ada potensi risiko langsung dari temuan ini.',
        'remediation' => 'Pastikan masa berlaku sertifikat selalu dipantau untuk perpanjangan berkala sebelum kedaluwarsa.'
    ],
    'frameable response (potential clickjacking)' => [
        'name' => 'Respon Dapat Diframe (Potensi Clickjacking)',
        'cvss' => '4.7 (Medium)',
        'cwe' => 'CWE-1021',
        'detail' => 'Halaman web tidak menetapkan header keamanan HTTP X-Frame-Options atau Content-Security-Policy (frame-ancestors) yang memadai. Kondisi ini memungkinkan penyerang untuk memuat halaman web aplikasi ke dalam frame/iframe di situs eksternal yang mereka kendalikan untuk menjebak pengguna melakukan tindakan yang tidak disengaja (Clickjacking).',
        'remediation' => 'Konfigurasikan web server atau aplikasi untuk mengirimkan header HTTP X-Frame-Options dengan nilai "SAMEORIGIN" atau "DENY", serta header Content-Security-Policy (CSP) dengan direktif frame-ancestors "self".'
    ],
    'cacheable https response' => [
        'name' => 'Respon HTTPS Dapat Disimpan dalam Cache (Cacheable HTTPS Response)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-524',
        'detail' => 'Respon HTTPS dari server tidak menyertakan instruksi kontrol cache (cache-control) untuk mencegah penyimpanan data di cache lokal browser. Jika aplikasi memuat informasi sensitif, data tersebut dapat diakses oleh pihak lain yang menggunakan perangkat komputer yang sama di masa mendatang.',
        'remediation' => 'Tambahkan header HTTP Cache-Control: no-store dan Pragma: no-cache pada setiap respon web server yang menampilkan data pribadi atau informasi sensitif.'
    ],
    'vulnerable javascript dependency' => [
        'name' => 'Dependensi JavaScript Rentan (Vulnerable JavaScript Dependency)',
        'cvss' => '5.3 (Medium)',
        'cwe' => 'CWE-1395',
        'detail' => 'Aplikasi menggunakan pustaka (library) JavaScript versi lawas (seperti Bootstrap atau jQuery versi lama) yang memiliki celah keamanan (CVE) yang terdaftar dan diketahui secara publik. Penyerang dapat memanfaatkan celah ini untuk melakukan eksploitasi keamanan pada browser pengguna.',
        'remediation' => 'Lakukan pembaruan (upgrade) pustaka JavaScript yang terdeteksi rentan ke versi terbaru yang stabil dan aman.'
    ],
    'vulnerable javascript library' => [
        'name' => 'Dependensi JavaScript Rentan (Vulnerable JavaScript Dependency)',
        'cvss' => '5.3 (Medium)',
        'cwe' => 'CWE-1395',
        'detail' => 'Aplikasi menggunakan pustaka (library) JavaScript versi lawas (seperti Bootstrap atau jQuery versi lama) yang memiliki celah keamanan (CVE) yang terdaftar dan diketahui secara publik. Penyerang dapat memanfaatkan celah ini untuk melakukan eksploitasi keamanan pada browser pengguna.',
        'remediation' => 'Lakukan pembaruan (upgrade) pustaka JavaScript yang terdeteksi rentan ke versi terbaru yang stabil dan aman.'
    ],
    'path-relative style sheet import' => [
        'name' => 'Impor Style Sheet Menggunakan Path Relatif (Path-Relative Style Sheet Import)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-20',
        'detail' => 'Style sheet diimpor menggunakan jalur (path) relatif. Dalam kondisi tertentu, jika URL dimanipulasi, browser dapat salah menginterpretasikan respon dan memicu pemuatan stylesheet eksternal palsu atau menyebabkan kebingungan browser (CSS path-relative stylesheet import vulnerability).',
        'remediation' => 'Ubah pemanggilan file CSS menggunakan path absolut dari root domain (misalnya: "/assets/css/style.css") daripada menggunakan path relatif.'
    ],
    'email addresses disclosed' => [
        'name' => 'Pengungkapan Alamat Email (Email Addresses Disclosed)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-200',
        'detail' => 'Alamat email ditemukan terekspos di dalam kode sumber halaman web atau file aset. Hal ini dapat dimanfaatkan oleh pihak luar untuk melakukan spamming secara massal atau serangan phishing yang ditargetkan kepada staf organisasi.',
        'remediation' => 'Hapus alamat email dari kode sumber html publik. Jika alamat email harus ditampilkan, gunakan metode penyamaran (obfuscation) menggunakan JavaScript atau ganti dengan formulir kontak (contact form).'
    ],
    'backup file' => [
        'name' => 'File Cadangan Ditemukan (Backup File)',
        'cvss' => '5.3 (Medium)',
        'cwe' => 'CWE-530',
        'detail' => 'File cadangan atau file sementara (seperti *.bak, *.zip, *.swp) terdeteksi berada di folder web root publik server. File-file cadangan ini sering kali memuat kode sumber sensitif lama, cadangan basis data, atau file konfigurasi rahasia yang dapat diunduh bebas oleh publik.',
        'remediation' => 'Hapus semua file cadangan dan file arsip sementara dari folder publik web root. Pastikan editor teks di server dikonfigurasi untuk tidak menyimpan file swap (*.swp) di direktori publik.'
    ],
    'sql injection' => [
        'name' => 'Injeksi SQL (SQL Injection)',
        'cvss' => '8.8 (High)',
        'cwe' => 'CWE-89',
        'detail' => 'Aplikasi menerima input pengguna langsung ke query database SQL tanpa sanitasi atau parameterisasi. Penyerang dapat mengeksploitasi celah ini untuk membaca data sensitif (seperti username dan kata sandi), mengubah data di basis data, atau bahkan mengeksekusi perintah administratif pada server database.',
        'remediation' => 'Gunakan query berparameter (Parameterized Queries / Prepared Statements) atau kerangka kerja ORM (Object-Relational Mapping) untuk seluruh interaksi database. Hindari merangkai string input langsung ke query SQL.'
    ],
    'cross-site scripting' => [
        'name' => 'Cross-Site Scripting (XSS)',
        'cvss' => '6.1 (Medium)',
        'cwe' => 'CWE-79',
        'detail' => 'Aplikasi merender input pengguna ke halaman web tanpa sanitasi atau encoding yang benar. Penyerang dapat menyisipkan skrip berbahaya (JavaScript) yang akan berjalan di browser korban, memfasilitasi pencurian session token, pengambilalihan akun pengguna, atau manipulasi tampilan web.',
        'remediation' => 'Lakukan pembersihan (encoding) input dan output secara ketat menggunakan pustaka sanitasi HTML terpercaya. Terapkan Content-Security-Policy (CSP) yang membatasi eksekusi skrip dari sumber eksternal yang tidak dikenal.'
    ],
    'cross-site scripting (reflected)' => [
        'name' => 'Cross-Site Scripting Terrefleksi (Reflected XSS)',
        'cvss' => '6.1 (Medium)',
        'cwe' => 'CWE-79',
        'detail' => 'Input pengguna dipantulkan kembali dalam respon server tanpa sanitasi. Penyerang dapat menyuntikkan skrip berbahaya melalui tautan khusus, yang apabila diakses oleh korban, akan mengeksekusi skrip tersebut di browser korban, mencuri cookie sesi, atau membajak akun.',
        'remediation' => 'Lakukan encoding output HTML sebelum merender input pengguna ke browser, dan gunakan header HTTP Content-Security-Policy (CSP) yang aman.'
    ],
    'cross-site scripting (stored)' => [
        'name' => 'Cross-Site Scripting Tersimpan (Stored XSS)',
        'cvss' => '7.2 (High)',
        'cwe' => 'CWE-79',
        'detail' => 'Skrip berbahaya disimpan secara permanen di database server (misalnya melalui kolom komentar atau profil). Ketika pengguna lain memuat halaman tersebut, skrip berbahaya akan dieksekusi secara otomatis di browser mereka, berdampak luas bagi keamanan seluruh pengguna.',
        'remediation' => 'Lakukan sanitasi input sebelum disimpan ke database dan pastikan output HTML di-encode secara ketat sebelum ditampilkan.'
    ],
    'arbitrary file upload' => [
        'name' => 'Unggahan File Sembarangan (Arbitrary File Upload)',
        'cvss' => '9.8 (Critical)',
        'cwe' => 'CWE-434',
        'detail' => 'Aplikasi mengizinkan unggahan file tanpa pemeriksaan jenis file dan ekstensi secara ketat. Penyerang dapat mengunggah file skrip server berbahaya (seperti web shell PHP, ASP, dll.) dan mengeksekusinya untuk mendapatkan kendali penuh atas sistem server aplikasi.',
        'remediation' => 'Validasi tipe MIME dan ekstensi file yang diunggah menggunakan whitelist ekstensi aman. Simpan file yang diunggah di luar folder web root publik, hilangkan hak eksekusi pada direktori tersebut, dan ubah nama file dengan format acak sebelum disimpan.'
    ],
    'command injection' => [
        'name' => 'Injeksi Perintah Sistem Operasi (OS Command Injection)',
        'cvss' => '9.8 (Critical)',
        'cwe' => 'CWE-78',
        'detail' => 'Aplikasi meneruskan input pengguna langsung ke shell sistem operasi untuk dieksekusi tanpa validasi. Penyerang dapat menyisipkan karakter pemisah perintah (seperti ";" atau "&&") untuk menjalankan perintah sewenang-wenang di server.',
        'remediation' => 'Hindari menjalankan perintah sistem operasi langsung dari aplikasi. Jika sangat diperlukan, gunakan API bawaan bahasa pemrograman yang aman, validasikan input dengan whitelist ketat, dan jalankan perintah dengan parameter terpisah.'
    ],
    'path traversal' => [
        'name' => 'Penjelajahan Jalur Direktori (Directory Traversal)',
        'cvss' => '7.5 (High)',
        'cwe' => 'CWE-22',
        'detail' => 'Aplikasi menggunakan input pengguna untuk menentukan path file yang akan dibaca tanpa validasi jalur yang benar. Penyerang dapat menggunakan karakter penunjuk folder atas (seperti "../") untuk membaca file konfigurasi atau file sistem sensitif di server.',
        'remediation' => 'Sanitasi input pengguna dari karakter "../" atau "..\\". Gunakan fungsi resolusi path absolut dan pastikan file yang diakses berada di dalam direktori kerja yang sah.'
    ],
    'os command injection' => [
        'name' => 'Injeksi Perintah Sistem Operasi (OS Command Injection)',
        'cvss' => '9.8 (Critical)',
        'cwe' => 'CWE-78',
        'detail' => 'Aplikasi meneruskan data yang dapat dikontrol oleh pengguna ke dalam perintah sistem operasi yang diproses oleh command interpreter. Penyerang dapat menyuntikkan karakter khusus shell untuk memodifikasi perintah yang dijalankan dan mengeksekusi instruksi sewenang-wenang untuk mengambil alih server aplikasi secara penuh.',
        'remediation' => 'Hindari merangkai input pengguna langsung ke shell perintah OS. Gunakan API bawaan yang aman seperti Runtime.exec di Java atau Process.Start di ASP.NET yang memisahkan nama proses dan argumennya.'
    ],
    'open redirection (dom-based)' => [
        'name' => 'Pengalihan Terbuka Berbasis DOM (DOM-Based Open Redirection)',
        'cvss' => '6.1 (Medium)',
        'cwe' => 'CWE-601',
        'detail' => 'Aplikasi secara dinamis mengarahkan pengguna ke URL luar menggunakan input pengguna yang tidak aman di sisi klien (DOM). Penyerang dapat mengeksploitasi ini untuk mengarahkan pengguna ke situs web phishing eksternal yang berbahaya.',
        'remediation' => 'Validasi URL tujuan secara ketat dengan whitelist domain tepercaya atau batasi pengalihan hanya ke path lokal/relatif.'
    ],
    'unencrypted communications' => [
        'name' => 'Komunikasi Tidak Terenkripsi (HTTP)',
        'cvss' => '5.3 (Medium)',
        'cwe' => 'CWE-319',
        'detail' => 'Aplikasi mengirimkan data sensitif melalui protokol HTTP biasa yang tidak terenkripsi. Hal ini memungkinkan penyerang di jaringan komunikasi yang sama untuk mengintai data penting (kredensial, token) secara langsung.',
        'remediation' => 'Terapkan sertifikat SSL/TLS dan konfigurasikan pengalihan otomatis seluruh lalu lintas data dari HTTP ke HTTPS menggunakan header HSTS.'
    ],
    'cross-domain referer leakage' => [
        'name' => 'Kebocoran Referer Lintas Domain (Cross-Domain Referer Leakage)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-201',
        'detail' => 'Aplikasi memuat sumber daya dari domain eksternal atau memiliki tautan luar. URL lengkap halaman web saat ini dikerjakan ke server eksternal melalui header Referer HTTP, yang berpotensi membocorkan parameter URL sensitif atau token sesi.',
        'remediation' => 'Terapkan header respon HTTP Referrer-Policy dengan nilai seperti "no-referrer" atau "strict-origin-when-cross-origin".'
    ],
    'cross-domain script include' => [
        'name' => 'Pemuatan Skrip Lintas Domain (Cross-Domain Script Inclusion)',
        'cvss' => '5.3 (Medium)',
        'cwe' => 'CWE-829',
        'detail' => 'Aplikasi memuat pustaka/skrip JavaScript dari domain pihak ketiga eksternal secara langsung. Jika domain eksternal tersebut disusupi oleh penyerang, mereka dapat memanipulasi kode JavaScript tersebut untuk mencuri data di browser aplikasi.',
        'remediation' => 'Gunakan mekanisme Subresource Integrity (SRI) atau simpan pustaka JavaScript secara lokal pada server Anda sendiri.'
    ],
    'html5 storage manipulation (dom-based)' => [
        'name' => 'Manipulasi Penyimpanan HTML5 Berbasis DOM (DOM-Based HTML5 Storage Manipulation)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-922',
        'detail' => 'Aplikasi memproses input pengguna yang tidak aman dan menyimpannya langsung ke local storage atau session storage HTML5. Kondisi ini dapat disalahgunakan untuk merusak integritas data browser atau memicu serangan XSS.',
        'remediation' => 'Lakukan sanitasi dan validasi tipe data secara ketat terhadap seluruh data input sebelum menyimpannya ke dalam local/session storage.'
    ],
    'dom data manipulation (dom-based)' => [
        'name' => 'Manipulasi Data DOM Berbasis DOM (DOM-Based Data Manipulation)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-20',
        'detail' => 'Script sisi klien memproses input pengguna secara tidak aman dan langsung menulis data tersebut ke struktur DOM halaman web. Penyerang dapat mengendalikan input tersebut untuk memodifikasi konten visual halaman atau menyuntikkan script berbahaya.',
        'remediation' => 'Gunakan metode penulisan DOM yang aman seperti textContent ketimbang innerHTML untuk merender konten dinamis.'
    ],
    'input returned in response (reflected)' => [
        'name' => 'Input Dikembalikan dalam Respon / Reflected (Input Returned in Response)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-20',
        'detail' => 'Aplikasi menerima input dari pengguna dan memantulkannya kembali ke dalam respon server tanpa sanitasi atau encoding yang memadai. Meskipun temuan ini dinilai sebagai Informasi karena tidak secara langsung memicu XSS, pemantulan input yang tidak aman merupakan indikasi kurangnya sanitasi input.',
        'remediation' => 'Pastikan seluruh input pengguna selalu disanitasi dan di-encode dengan benar sebelum ditampilkan kembali dalam respon aplikasi.'
    ],
    'suspicious input transformation (reflected)' => [
        'name' => 'Transformasi Input Mencurigakan / Reflected (Suspicious Input Transformation)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-20',
        'detail' => 'Aplikasi melakukan modifikasi atau transformasi tertentu pada input pengguna secara tidak biasa sebelum memantulkannya kembali. Temuan ini masuk dalam kategori Informasi namun perlu ditinjau untuk memastikan transformasi tersebut tidak membuka celah keamanan logika.',
        'remediation' => 'Tinjau logika pemrosesan input sisi server untuk memastikan transformasi karakter dilakukan secara aman dan konsisten.'
    ],
    'password submitted using get method' => [
        'name' => 'Pengiriman Kata Sandi Menggunakan Metode GET (Password Submitted Using GET Method)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-522',
        'detail' => 'Aplikasi mengirimkan kata sandi (kredensial login) menggunakan metode HTTP GET. Parameter GET dikirimkan melalui URL, yang berarti kata sandi dapat terekam di dalam riwayat browser, file log web server, proxy server, atau header Referer, sehingga dapat dibaca oleh pihak ketiga yang tidak berwenang.',
        'remediation' => 'Ubah proses pengiriman kredensial login menggunakan metode HTTP POST dengan enkripsi HTTPS yang aman, serta pastikan seluruh parameter sensitif dikirim dalam request body.'
    ],
    'content security policy: allows untrusted script execution' => [
        'name' => 'Kebijakan Keamanan Konten: Mengizinkan Eksekusi Skrip Tidak Terpercaya (Content Security Policy: Allows Untrusted Script Execution)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-1021',
        'detail' => 'Header Content Security Policy (CSP) dikonfigurasi terlalu longgar (misalnya menggunakan direktif \'unsafe-inline\' atau \'unsafe-eval\' tanpa pembatasan nonce atau hash yang ketat). Hal ini memungkinkan eksekusi kode JavaScript tidak sah di sisi klien.',
        'remediation' => 'Perketat konfigurasi Content-Security-Policy (CSP) dengan membatasi direktif script-src hanya pada domain yang tepercaya, serta hindari penggunaan \'unsafe-inline\' dan \'unsafe-eval\' dengan menerapkan hash atau nonce.'
    ],
    'content security policy: allows untrusted style execution' => [
        'name' => 'Kebijakan Keamanan Konten: Mengizinkan Eksekusi Style Tidak Terpercaya (Content Security Policy: Allows Untrusted Style Execution)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-1021',
        'detail' => 'Header Content Security Policy (CSP) mengizinkan eksekusi style sheet eksternal atau inline tanpa pembatasan (misalnya direktif style-src menggunakan \'unsafe-inline\'). Penyerang dapat menyalahgunakan hal ini untuk memanipulasi tampilan aplikasi secara visual demi memfasilitasi serangan rekayasa sosial.',
        'remediation' => 'Perketat konfigurasi Content-Security-Policy (CSP) pada direktif style-src untuk membatasi pemuatan file CSS hanya dari sumber internal (\'self\') atau CDN tepercaya.'
    ],
    'content security policy: allows form hijacking' => [
        'name' => 'Kebijakan Keamanan Konten: Mengizinkan Pembajakan Formulir (Content Security Policy: Allows Form Hijacking)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-1021',
        'detail' => 'Kebijakan Keamanan Konten (CSP) tidak membatasi tujuan aksi formulir (direktif form-action tidak diset). Hal ini memungkinkan manipulasi atribut action formulir HTML melalui eksploitasi di sisi klien untuk mengalihkan pengiriman data sensitif pengguna (kredensial, informasi pribadi) ke server luar yang dikendalikan penyerang.',
        'remediation' => 'Tambahkan direktif form-action \'self\' pada header HTTP Content-Security-Policy untuk memastikan data formulir hanya dapat dikirimkan kembali ke server internal yang sah.'
    ],
    'long redirection response' => [
        'name' => 'Respon Pengalihan Terlalu Panjang (Long Redirection Response)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-20',
        'detail' => 'Aplikasi menghasilkan respon pengalihan (HTTP 301/302) yang berisi konten data tubuh (response body) yang terlalu besar atau panjang. Hal ini menandakan server masih memproses dan mengirimkan seluruh isi halaman web meskipun browser diperintahkan untuk segera mengalihkan halaman, yang berpotensi membocorkan data sebelum pengalihan selesai.',
        'remediation' => 'Pastikan saat server mengirimkan instruksi pengalihan HTTP (header Location), proses eksekusi kode segera dihentikan (misalnya menggunakan perintah exit; atau die; di PHP) untuk mencegah tubuh respon dikirimkan.'
    ],
    'dom data manipulation (reflected dom-based)' => [
        'name' => 'Manipulasi Data DOM Terrefleksi Berbasis DOM (DOM-Based Data Manipulation - Reflected)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-20',
        'detail' => 'Script di sisi browser membaca data input yang dipantulkan (reflected) dari URL lalu memodifikasi struktur data DOM secara dinamis tanpa sanitasi yang memadai. Penyerang dapat merekayasa tautan untuk merusak struktur tampilan halaman atau merubah alur logika klien.',
        'remediation' => 'Sanitasi seluruh data input URL sebelum diproses di JavaScript. Hindari menulis data dinamis ke properti HTML mentah seperti innerHTML.'
    ],
    'tls certificate' => [
        'name' => 'Sertifikat TLS/SSL (TLS Certificate)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-295',
        'detail' => 'Burp Suite mendeteksi sertifikat SSL/TLS yang digunakan oleh server. Temuan ini bersifat informasional untuk mencatat detail penerbit, masa berlaku, kekuatan enkripsi, dan algoritma penandatanganan sertifikat untuk memastikan enkripsi transmisi data berjalan dengan benar.',
        'remediation' => 'Gunakan sertifikat TLS/SSL yang ditandatangani oleh Otoritas Sertifikat (CA) tepercaya secara global. Lakukan pemantauan berkala agar sertifikat selalu diperbarui sebelum masa berlakunya habis.'
    ],
    'tls cookie without secure flag set' => [
        'name' => 'Cookie TLS Tanpa Flag Secure (TLS Cookie Without Secure Flag Set)',
        'cvss' => '5.3 (Medium)',
        'cwe' => 'CWE-614',
        'detail' => 'Aplikasi menetapkan cookie sensitif (seperti session token) tanpa atribut \'secure\'. Hal ini memungkinkan browser mengirimkan cookie tersebut melalui koneksi HTTP biasa yang tidak terenkripsi jika pengguna mengakses URL non-HTTPS, sehingga penyerang dapat menyadap cookie tersebut melalui intersepsi lalu lintas jaringan.',
        'remediation' => 'Konfigurasikan aplikasi agar selalu menyertakan atribut \'secure\' pada setiap pembuatan cookie penting/sensitif untuk memastikan cookie hanya ditransmisikan melalui koneksi HTTPS yang terenkripsi.'
    ],
    'strict transport security not enforced' => [
        'name' => 'HTTP Strict Transport Security (HSTS) Tidak Diterapkan',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-523',
        'detail' => 'Aplikasi tidak mengaktifkan header HTTP Strict-Transport-Security (HSTS). Hal ini memungkinkan pengguna terhubung ke aplikasi menggunakan koneksi HTTP biasa yang tidak aman. Penyerang di jaringan yang sama dapat melakukan serangan SSL Stripping untuk menurunkan koneksi dari HTTPS ke HTTP dan menyadap data secara langsung.',
        'remediation' => 'Terapkan HTTP Strict Transport Security (HSTS) dengan menambahkan header respon \'Strict-Transport-Security: max-age=31536000; includeSubDomains\' pada konfigurasi web server.'
    ],
    'credit card numbers disclosed' => [
        'name' => 'Pengungkapan Nomor Kartu Kredit (Credit Card Numbers Disclosed)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-200',
        'detail' => 'Aplikasi terdeteksi menampilkan informasi keuangan sensitif berupa nomor kartu kredit pada tubuh respon HTTP. Pengungkapan data finansial sensitif ini perlu diverifikasi untuk memastikan tidak ada kebocoran data pengguna lain yang tidak sah.',
        'remediation' => 'Tinjau logika aplikasi untuk memastikan apakah penampilan nomor kartu kredit tersebut memang diperlukan. Jika ya, terapkan masking (misalnya menyembunyikan sebagian besar digit kartu kredit seperti \'xxxx-xxxx-xxxx-1234\') sebelum dikirimkan dalam respon.'
    ],
    'content type is not specified' => [
        'name' => 'Tipe Konten Tidak Ditentukan (Content Type Is Not Specified)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-345',
        'detail' => 'Respon HTTP dari server tidak menyertakan header Content-Type untuk menentukan jenis konten (MIME type). Hal ini menyebabkan browser mencoba menebak (MIME sniffing) jenis data tersebut. Jika halaman memuat data yang dikontrol pengguna, penyerang dapat menyalahgunakannya untuk memicu serangan XSS atau kerentanan sisi klien lainnya.',
        'remediation' => 'Pastikan setiap respon server yang memiliki message body selalu menyertakan header \'Content-Type\' yang benar dan spesifik (misalnya \'text/html\', \'application/json\', dll.), serta tambahkan header \'X-Content-Type-Options: nosniff\' untuk mencegah browser menebak tipe konten.'
    ],
    'cookie without httponly flag set' => [
        'name' => 'Cookie Tanpa Flag HttpOnly (Cookie Without HttpOnly Flag Set)',
        'cvss' => '3.7 (Low)',
        'cwe' => 'CWE-1004',
        'detail' => 'Cookie sesi atau cookie sensitif ditetapkan tanpa atribut HttpOnly. Hal ini memungkinkan JavaScript di sisi klien untuk mengakses dan mencuri cookie tersebut. Jika terjadi serangan XSS, penyerang dapat mengeksploitasi kondisi ini untuk mencuri session token pengguna.',
        'remediation' => 'Tambahkan atribut HttpOnly pada setiap cookie sensitif (terutama session cookie) sehingga cookie tidak dapat diakses melalui JavaScript dan terlindung dari serangan XSS.'
    ],
    'cookie scoped to parent domain' => [
        'name' => 'Cookie Tercakup ke Domain Induk (Cookie Scoped to Parent Domain)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-1275',
        'detail' => 'Cookie ditetapkan dengan domain yang terlalu luas sehingga tercakup ke domain induk. Hal ini memungkinkan subdomain lain dari domain yang sama untuk mengakses cookie tersebut, yang berpotensi menimbulkan risiko keamanan jika subdomain lain disusupi.',
        'remediation' => 'Tetapkan cookie hanya untuk domain atau subdomain spesifik yang membutuhkannya, bukan ke seluruh domain induk.'
    ],
    'http to https redirection' => [
        'name' => 'Pengalihan HTTP ke HTTPS (HTTP to HTTPS Redirection)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-319',
        'detail' => 'Aplikasi melakukan pengalihan dari HTTP ke HTTPS, namun pengalihan awal tetap terjadi melalui koneksi HTTP yang tidak aman. Penyerang dapat melakukan serangan man-in-the-middle selama koneksi HTTP pertama berlangsung sebelum pengalihan terjadi.',
        'remediation' => 'Konfigurasikan HSTS (HTTP Strict Transport Security) agar browser langsung menghubungi server menggunakan HTTPS tanpa melalui pengalihan HTTP terlebih dahulu.'
    ],
    'password field with autocomplete enabled' => [
        'name' => 'Field Password dengan Autocomplete Aktif (Password Field With Autocomplete Enabled)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-522',
        'detail' => 'Formulir login menggunakan field password dengan fitur autocomplete browser yang aktif. Hal ini memungkinkan browser menyimpan kata sandi pengguna secara otomatis, yang dapat membahayakan keamanan akun jika perangkat digunakan bersama atau dicuri.',
        'remediation' => 'Tambahkan atribut autocomplete="off" atau autocomplete="new-password" pada elemen input password dalam formulir login.'
    ],
    'x-content-type-options header missing' => [
        'name' => 'Header X-Content-Type-Options Tidak Ada',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-693',
        'detail' => 'Respon server tidak menyertakan header keamanan X-Content-Type-Options: nosniff. Tanpa header ini, browser lama dapat mencoba menebak tipe konten (MIME sniffing) yang dapat dieksploitasi untuk serangan XSS.',
        'remediation' => 'Tambahkan header HTTP "X-Content-Type-Options: nosniff" pada konfigurasi web server untuk mencegah browser melakukan MIME type sniffing.'
    ],
    'ssl certificate' => [
        'name' => 'Sertifikat SSL (SSL Certificate)',
        'cvss' => '0.0 (Informasi)',
        'cwe' => 'CWE-295',
        'detail' => 'Informasi sertifikat SSL/TLS server terdeteksi oleh scanner. Temuan ini bersifat informatif untuk mendokumentasikan konfigurasi enkripsi, masa berlaku sertifikat, dan penerbit sertifikat yang digunakan server.',
        'remediation' => 'Pastikan sertifikat SSL/TLS selalu diperbarui sebelum kadaluwarsa dan diterbitkan oleh otoritas sertifikasi (CA) yang tepercaya.'
    ],
    'mixed content' => [
        'name' => 'Konten Campuran HTTP/HTTPS (Mixed Content)',
        'cvss' => '3.1 (Low)',
        'cwe' => 'CWE-311',
        'detail' => 'Halaman web yang dimuat melalui HTTPS memuat sumber daya (gambar, skrip, atau stylesheet) melalui koneksi HTTP biasa. Hal ini melemahkan keamanan HTTPS karena sumber daya yang tidak terenkripsi dapat disadap atau dimodifikasi.',
        'remediation' => 'Pastikan seluruh sumber daya yang dimuat oleh halaman web menggunakan URL HTTPS. Periksa dan perbarui semua referensi sumber daya untuk menggunakan protokol HTTPS.'
    ],
    'open redirection' => [
        'name' => 'Pengalihan Terbuka (Open Redirection)',
        'cvss' => '6.1 (Medium)',
        'cwe' => 'CWE-601',
        'detail' => 'Aplikasi mengalihkan pengguna ke URL berdasarkan input yang dapat dikendalikan penyerang tanpa validasi yang memadai. Penyerang dapat memanfaatkan celah ini untuk membuat tautan phishing yang tampak berasal dari domain tepercaya, namun mengarahkan korban ke situs berbahaya.',
        'remediation' => 'Validasi URL tujuan pengalihan dengan whitelist domain tepercaya. Hindari penggunaan parameter URL yang dapat dikendalikan pengguna sebagai tujuan pengalihan langsung.'
    ],
    'server-side request forgery (ssrf)' => [
        'name' => 'Pemalsuan Permintaan Sisi Server (Server-Side Request Forgery / SSRF)',
        'cvss' => '8.6 (High)',
        'cwe' => 'CWE-918',
        'detail' => 'Aplikasi dapat dipaksa untuk membuat permintaan HTTP ke sumber daya internal atau eksternal berdasarkan input yang dikendalikan pengguna. Penyerang dapat mengeksploitasi ini untuk mengakses layanan internal yang tersembunyi, membaca file lokal, atau melakukan port scanning terhadap infrastruktur internal.',
        'remediation' => 'Validasi dan sanitasi semua URL yang disuplai pengguna. Gunakan whitelist alamat yang diizinkan dan blokir akses ke alamat IP loopback serta rentang IP private melalui firewall atau network layer.'
    ],
    'insecure deserialization' => [
        'name' => 'Deserialisasi Tidak Aman (Insecure Deserialization)',
        'cvss' => '9.8 (Critical)',
        'cwe' => 'CWE-502',
        'detail' => 'Aplikasi mendeserialisasi data yang dapat dimanipulasi oleh penyerang tanpa validasi yang memadai. Hal ini dapat memungkinkan penyerang untuk memanipulasi objek aplikasi, mengeksekusi kode sewenang-wenang, atau melakukan serangan privilege escalation.',
        'remediation' => 'Hindari deserialisasi data dari sumber yang tidak tepercaya. Terapkan validasi integritas data (seperti tanda tangan kriptografi) sebelum deserialisasi, dan gunakan mekanisme serialisasi yang lebih aman.'
    ],
    'xml external entity injection' => [
        'name' => 'Injeksi Entitas Eksternal XML (XXE Injection)',
        'cvss' => '8.2 (High)',
        'cwe' => 'CWE-611',
        'detail' => 'Parser XML aplikasi dikonfigurasi untuk memproses entitas eksternal yang dapat dikendalikan oleh penyerang. Eksploitasi ini dapat memungkinkan pembacaan file sistem, SSRF, atau dalam beberapa kasus eksekusi kode jarak jauh.',
        'remediation' => 'Nonaktifkan pemrosesan entitas eksternal XML dalam konfigurasi parser XML. Gunakan library XML yang aman dan selalu validasi/sanitasi input XML sebelum diproses.'
    ],
    'cross-site request forgery' => [
        'name' => 'Pemalsuan Permintaan Lintas Situs (Cross-Site Request Forgery / CSRF)',
        'cvss' => '6.5 (Medium)',
        'cwe' => 'CWE-352',
        'detail' => 'Aplikasi tidak menerapkan token anti-CSRF yang memadai pada formulir atau permintaan yang mengubah data. Penyerang dapat membuat halaman web berbahaya yang secara diam-diam mengirimkan permintaan atas nama pengguna yang sudah login.',
        'remediation' => 'Terapkan token CSRF (Synchronizer Token Pattern) yang unik dan acak pada setiap formulir atau permintaan yang mengubah data. Validasi token tersebut di sisi server sebelum memproses setiap permintaan.'
    ]
];

function getSlaText($severity) {
    if ($severity === 'High' || $severity === 'Critical') {
        return " [Target Perbaikan: Maksimal 7 Hari (Kritis)]";
    } elseif ($severity === 'Medium') {
        return " [Target Perbaikan: Maksimal 30 Hari (Penting)]";
    } elseif ($severity === 'Low') {
        return " [Target Perbaikan: Maksimal 90 Hari (Rutin)]";
    } else {
        return " [Target Perbaikan: Opsional / Pemeliharaan Berkala]";
    }
}

function getSlaColorHex($severity) {
    if ($severity === 'High' || $severity === 'Critical') {
        return "CC0000"; // Red
    } elseif ($severity === 'Medium') {
        return "CC6600"; // Orange
    } elseif ($severity === 'Low') {
        return "0066CC"; // Blue
    } else {
        return "666666"; // Gray
    }
}

function extractDateFromFilename($filename) {
    $basename = basename($filename);
    if (preg_match('/(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4})/i', $basename, $match)) {
        $day = $match[1];
        $month = ucfirst(strtolower($match[2]));
        $year = $match[3];
        return "$day $month $year";
    }
    return null;
}

function normalizeNomorSuratForTemplate($nomorSurat) {
    $nomorSurat = trim((string)$nomorSurat);
    // Template sudah memiliki prefix "500.12.6.4/" dan suffix "- PS".
    // Jika pengguna mengisi nomor lengkap, gunakan nomor urut di tengah saja.
    if (preg_match('/^[^\/]+\/\s*([^\s\/]+?)\s*(?:[-–]\s*PS)?$/i', $nomorSurat, $m)) {
        return trim($m[1]);
    }
    return $nomorSurat;
}

function cleanHtml($rawHtml) {
    if (!$rawHtml) {
        return "";
    }
    $text = html_entity_decode($rawHtml, ENT_QUOTES, 'UTF-8');
    $text = preg_replace('/<li>\s*/i', '* ', $text);
    $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = preg_replace('/<\/p\s*>/i', "\n\n", $text);
    $text = preg_replace('/<\/li\s*>/i', "\n", $text);
    $text = preg_replace('/<[^>]+>/', '', $text);
    $text = str_replace("\r", "", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim($text);
}

function sanitizeXmlString($string) {
    if (empty($string)) {
        return "";
    }
    // Remove invalid XML 1.0 characters
    return preg_replace('/[^\x09\x0A\x0D\x20-\xD7FF\xE000-\xFFFD]/', '', $string);
}

function getIndonesianDate($timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    $days = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
    $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", 
              "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    
    $w = date('w', $timestamp);
    $m = date('n', $timestamp);
    $d = date('j', $timestamp);
    $y = date('Y', $timestamp);
    
    return [$days[$w], "$d " . $months[$m - 1] . " $y"];
}

function terbilang($number) {
    $words = [
        "", "Satu", "Dua", "Tiga", "Empat", "Lima",
        "Enam", "Tujuh", "Delapan", "Sembilan", "Sepuluh", "Sebelas"
    ];
    
    if ($number < 12) {
        return $words[$number];
    } elseif ($number < 20) {
        return terbilang($number - 10) . " Belas";
    } elseif ($number < 100) {
        return terbilang(floor($number / 10)) . " Puluh " . terbilang($number % 10);
    } elseif ($number < 200) {
        return "Seratus " . terbilang($number - 100);
    } elseif ($number < 1000) {
        return terbilang(floor($number / 100)) . " Ratus " . terbilang($number % 100);
    } elseif ($number < 2000) {
        return "Seribu " . terbilang($number - 1000);
    } elseif ($number < 1000000) {
        return terbilang(floor($number / 1000)) . " Ribu " . terbilang($number % 1000);
    }
    return "";
}

function getIndonesianDateSpelled($timestamp = null) {
    if ($timestamp === null) {
        $timestamp = time();
    }
    $days = ["Minggu", "Senin", "Selasa", "Rabu", "Kamis", "Jumat", "Sabtu"];
    $months = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", 
              "Juli", "Agustus", "September", "Oktober", "November", "Desember"];
    
    $w = date('w', $timestamp);
    $m = date('n', $timestamp);
    $d = (int)date('j', $timestamp);
    $y = (int)date('Y', $timestamp);
    
    $daySpelled = trim(preg_replace('/\s+/', ' ', terbilang($d)));
    $yearSpelled = trim(preg_replace('/\s+/', ' ', terbilang($y)));
    
    $dateSpelled = $daySpelled . " " . $months[$m - 1] . " " . $yearSpelled;
    return [$days[$w], $dateSpelled];
}

function parseBurpDate($dateStr) {
    global $MONTH_MAP;
    try {
        $parts = preg_split('/\s+/', trim($dateStr));
        if (count($parts) >= 6) {
            $day = $parts[2];
            $monthAbbr = $parts[1];
            $year = $parts[5];
            $monthId = isset($MONTH_MAP[$monthAbbr]) ? $MONTH_MAP[$monthAbbr] : $monthAbbr;
            return "$day $monthId $year";
        }
    } catch (Exception $e) {
        echo "Warning parsing date '$dateStr': " . $e->getMessage() . "\n";
    }
    return $dateStr;
}

function extractDateFromHtmlContent($htmlContent) {
    global $MONTH_MAP;
    if (preg_match('/\bDate:\s*[A-Za-z]{3},\s*(\d{1,2})\s+([A-Za-z]{3})\s+(\d{4})\b/i', $htmlContent, $m)) {
        $month = ucfirst(strtolower($m[2]));
        foreach ($MONTH_MAP as $abbr => $monthName) {
            if (strcasecmp($abbr, $m[2]) === 0) {
                $month = $monthName;
                break;
            }
        }
        return sprintf('%02d %s %s', (int)$m[1], $month, $m[3]);
    }
    return null;
}

function getXmlId($node) {
    if ($node->hasAttributeNS('http://www.w3.org/XML/1998/namespace', 'id')) {
        return $node->getAttributeNS('http://www.w3.org/XML/1998/namespace', 'id');
    }
    if ($node->hasAttribute('xml:id')) {
        return $node->getAttribute('xml:id');
    }
    if ($node->hasAttribute('id')) {
        return $node->getAttribute('id');
    }
    return '';
}

function getParagraphText($pNode) {
    $text = '';
    $tNodes = $pNode->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
    foreach ($tNodes as $tNode) {
        $text .= $tNode->nodeValue;
    }
    return $text;
}

function setTNodeText($tNode, $text) {
    $text = sanitizeXmlString($text);
    $dom = $tNode->ownerDocument;
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    
    if (strpos($text, "\n") === false) {
        $tNode->nodeValue = $text;
        return;
    }
    
    $lines = explode("\n", $text);
    $parent = $tNode->parentNode;
    $next = $tNode->nextSibling;
    
    $parent->removeChild($tNode);
    
    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $br = $dom->createElementNS($wNs, 'w:br');
            if ($next) {
                $parent->insertBefore($br, $next);
            } else {
                $parent->appendChild($br);
            }
        }
        if ($line !== '') {
            $t = $dom->createElementNS($wNs, 'w:t');
            $t->setAttribute('xml:space', 'preserve');
            $t->nodeValue = $line;
            if ($next) {
                $parent->insertBefore($t, $next);
            } else {
                $parent->appendChild($t);
            }
        }
    }
}

function replacePlaceholdersInParagraph($pNode, $replacements, $xpath) {
    $text = getParagraphText($pNode);
    $changed = false;
    foreach ($replacements as $k => $v) {
        if (strpos($text, $k) !== false) {
            $changed = true;
        }
    }
    if (!$changed) {
        return;
    }

    $tNodes = $pNode->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
    $tNodesList = [];
    foreach ($tNodes as $tNode) {
        $tNodesList[] = $tNode;
    }

    foreach ($tNodesList as $tNode) {
        $tText = $tNode->nodeValue;
        $tChanged = false;
        foreach ($replacements as $k => $v) {
            if (strpos($tText, $k) !== false) {
                $tText = str_replace($k, $v, $tText);
                $tChanged = true;
            }
        }
        if ($tChanged) {
            setTNodeText($tNode, $tText);
        }
    }

    $textAfter = getParagraphText($pNode);
    $stillHas = false;
    foreach ($replacements as $k => $v) {
        if (strpos($textAfter, $k) !== false) {
            $stillHas = true;
            break;
        }
    }

    if ($stillHas) {
        $fullText = $text;
        foreach ($replacements as $k => $v) {
            $fullText = str_replace($k, $v, $fullText);
        }

        $tNodes = $pNode->getElementsByTagNameNS('http://schemas.openxmlformats.org/wordprocessingml/2006/main', 't');
        if ($tNodes->length > 0) {
            setTNodeText($tNodes->item(0), $fullText);
            for ($i = 1; $i < $tNodes->length; $i++) {
                $tNodes->item($i)->nodeValue = '';
            }
        }
    }
}

function addRunToParagraph($pNode, $text, $bold, $savedRPr) {
    $text = sanitizeXmlString($text);
    $dom = $pNode->ownerDocument;
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    
    $rEl = $dom->createElementNS($wNs, 'w:r');
    
    $rPr = null;
    if ($savedRPr) {
        $rPr = $savedRPr->cloneNode(true);
        $rEl->appendChild($rPr);
    }
    
    if ($bold) {
        if (!$rPr) {
            $rPr = $dom->createElementNS($wNs, 'w:rPr');
            $rEl->appendChild($rPr);
        }
        $bNodes = $rPr->getElementsByTagNameNS($wNs, 'b');
        if ($bNodes->length === 0) {
            $rPr->appendChild($dom->createElementNS($wNs, 'w:b'));
        }
        $bCsNodes = $rPr->getElementsByTagNameNS($wNs, 'bCs');
        if ($bCsNodes->length === 0) {
            $rPr->appendChild($dom->createElementNS($wNs, 'w:bCs'));
        }
    }
    
    $lines = explode("\n", $text);
    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $rEl->appendChild($dom->createElementNS($wNs, 'w:br'));
        }
        if ($line !== '') {
            $tEl = $dom->createElementNS($wNs, 'w:t');
            $tEl->setAttribute('xml:space', 'preserve');
            $tEl->nodeValue = $line;
            $rEl->appendChild($tEl);
        }
    }
    
    $pNode->appendChild($rEl);
}

function addRunToParagraphWithColor($pNode, $text, $bold, $colorHex, $savedRPr) {
    $text = sanitizeXmlString($text);
    $dom = $pNode->ownerDocument;
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    
    $rEl = $dom->createElementNS($wNs, 'w:r');
    
    $rPr = null;
    if ($savedRPr) {
        $rPr = $savedRPr->cloneNode(true);
        $rEl->appendChild($rPr);
    } else {
        $rPr = $dom->createElementNS($wNs, 'w:rPr');
        $rEl->appendChild($rPr);
    }
    
    if ($bold) {
        $bNodes = $rPr->getElementsByTagNameNS($wNs, 'b');
        if ($bNodes->length === 0) {
            $rPr->appendChild($dom->createElementNS($wNs, 'w:b'));
        }
        $bCsNodes = $rPr->getElementsByTagNameNS($wNs, 'bCs');
        if ($bCsNodes->length === 0) {
            $rPr->appendChild($dom->createElementNS($wNs, 'w:bCs'));
        }
    }
    
    if ($colorHex) {
        $colorNodes = $rPr->getElementsByTagNameNS($wNs, 'color');
        foreach ($colorNodes as $cNode) {
            $rPr->removeChild($cNode);
        }
        $colorEl = $dom->createElementNS($wNs, 'w:color');
        $colorEl->setAttribute('w:val', $colorHex);
        $rPr->appendChild($colorEl);
    }
    
    $lines = explode("\n", $text);
    foreach ($lines as $index => $line) {
        if ($index > 0) {
            $rEl->appendChild($dom->createElementNS($wNs, 'w:br'));
        }
        if ($line !== '') {
            $tEl = $dom->createElementNS($wNs, 'w:t');
            $tEl->setAttribute('xml:space', 'preserve');
            $tEl->nodeValue = $line;
            $rEl->appendChild($tEl);
        }
    }
    
    $pNode->appendChild($rEl);
    return $rEl;
}

function replaceAndBoldPlaceholdersInParagraph($pNode, $replacements, $xpath) {
    $boldKeys = ['{{TANGGAL_SCAN}}', '{{TARGET_URL}}'];
    $text = getParagraphText($pNode);
    
    $hasPlaceholder = false;
    foreach ($replacements as $k => $v) {
        if (strpos($text, $k) !== false) {
            $hasPlaceholder = true;
            break;
        }
    }
    
    if (!$hasPlaceholder) {
        return;
    }
    
    $specialKeys = array_merge($boldKeys, ['{{PARAGRAF_STATUS_KELAYAKAN}}']);
    $hasBoldKey = false;
    foreach ($specialKeys as $bk) {
        if (strpos($text, $bk) !== false) {
            $hasBoldKey = true;
            break;
        }
    }
    
    if (!$hasBoldKey) {
        replacePlaceholdersInParagraph($pNode, $replacements, $xpath);
        return;
    }
    
    $keys = array_keys($replacements);
    usort($keys, function($a, $b) {
        return strlen($b) - strlen($a);
    });
    
    $escapedKeys = array_map('preg_quote', $keys);
    $pattern = '/(' . implode('|', $escapedKeys) . ')/';
    $segments = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $rNodes = $pNode->getElementsByTagNameNS($wNs, 'r');
    $savedRPr = null;
    if ($rNodes->length > 0) {
        $firstR = $rNodes->item(0);
        $rPrNodes = $firstR->getElementsByTagNameNS($wNs, 'rPr');
        if ($rPrNodes->length > 0) {
            $savedRPr = $rPrNodes->item(0)->cloneNode(true);
        }
    }
    
    $nodesToRemove = [];
    foreach ($pNode->childNodes as $child) {
        if ($child->localName === 'r' || $child->localName === 'hyperlink') {
            $nodesToRemove[] = $child;
        }
    }
    foreach ($nodesToRemove as $node) {
        $pNode->removeChild($node);
    }
    
    foreach ($segments as $seg) {
        if ($seg === '') continue;
        
        if (isset($replacements[$seg])) {
            $val = (string)$replacements[$seg];
            if ($seg === '{{PARAGRAF_STATUS_KELAYAKAN}}') {
                $parts = preg_split('/(BELUM LAYAK UNTUK DIPUBLIKASIKAN|LAYAK UNTUK DIPUBLIKASIKAN)/', $val, -1, PREG_SPLIT_DELIM_CAPTURE);
                foreach ($parts as $part) {
                    if ($part === '') continue;
                    if ($part === 'BELUM LAYAK UNTUK DIPUBLIKASIKAN' || $part === 'LAYAK UNTUK DIPUBLIKASIKAN') {
                        addRunToParagraph($pNode, $part, true, $savedRPr);
                    } else {
                        addRunToParagraph($pNode, $part, false, $savedRPr);
                    }
                }
            } else {
                $isBold = in_array($seg, $boldKeys);
                addRunToParagraph($pNode, $val, $isBold, $savedRPr);
            }
        } else {
            addRunToParagraph($pNode, $seg, false, $savedRPr);
        }
    }
}

function insertParagraphAfter($prevPNode, $dom) {
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $newP = $dom->createElementNS($wNs, 'w:p');
    
    $pPrNodes = $prevPNode->getElementsByTagNameNS($wNs, 'pPr');
    if ($pPrNodes->length > 0) {
        $newP->appendChild($pPrNodes->item(0)->cloneNode(true));
    }
    
    if ($prevPNode->nextSibling) {
        $prevPNode->parentNode->insertBefore($newP, $prevPNode->nextSibling);
    } else {
        $prevPNode->parentNode->appendChild($newP);
    }
    return $newP;
}

function getCleanSummaryRemediation($text) {
    if (empty($text)) return "";
    
    // 1. Pecah berdasarkan separator code block AI dan ambil bagian non-kodenya saja
    $parts = explode("----------------------------------------", $text);
    $cleanParts = [];
    foreach ($parts as $idx => $part) {
        if ($idx % 2 === 0) {
            $cleanParts[] = $part;
        }
    }
    $text = implode(" ", $cleanParts);
    
    // 2. Bersihkan sisa tag markdown code block
    $text = preg_replace('/```.*?```/s', '', $text);
    $text = str_replace(['`', "'"], '', $text);
    
    // 3. Gabungkan whitespace ganda
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);
    
    // 4. Batasi panjang agar pas untuk daftar ringkasan (ambil sekitar 2-3 kalimat pertama, maks 280 char)
    if (strlen($text) > 300) {
        $sentences = preg_split('/(?<=[.!?])\s+/', $text);
        $shortText = '';
        foreach ($sentences as $s) {
            if (strlen($shortText . ' ' . $s) < 280) {
                $shortText .= (empty($shortText) ? '' : ' ') . $s;
            } else {
                break;
            }
        }
        $text = !empty($shortText) ? $shortText : substr($text, 0, 250) . '...';
    }
    
    return $text;
}

function populateRecommendations($pNode, $findings, $xpath) {
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $nodesToRemove = [];
    foreach ($pNode->childNodes as $child) {
        if ($child->localName !== 'pPr') {
            $nodesToRemove[] = $child;
        }
    }
    foreach ($nodesToRemove as $node) {
        $pNode->removeChild($node);
    }
    
    if (empty($findings)) {
        addRunToParagraph($pNode, "Tidak ada rekomendasi perbaikan mendesak.", false, null);
        return;
    }
    
    $dom = $pNode->ownerDocument;
    
    // Setup paragraph properties (hanging indentation)
    $pPrNodes = $pNode->getElementsByTagNameNS($wNs, 'pPr');
    if ($pPrNodes->length > 0) {
        $pPr = $pPrNodes->item(0);
    } else {
        $pPr = $dom->createElementNS($wNs, 'w:pPr');
        $pNode->insertBefore($pPr, $pNode->firstChild);
    }
    $indNodes = $pPr->getElementsByTagNameNS($wNs, 'ind');
    foreach ($indNodes as $indNode) {
        $pPr->removeChild($indNode);
    }
    $indEl = $dom->createElementNS($wNs, 'w:ind');
    $indEl->setAttribute('w:left', '360');
    $indEl->setAttribute('w:firstLine', '-360');
    $pPr->appendChild($indEl);
    
    // Gunakan daftar temuan dengan rekomendasi perbaikan (bila mode AI aktif, f['remediation'] sudah berisi penjelasan AI)
    $f = $findings[0];
    addRunToParagraph($pNode, "1.\t" . $f['name'] . ": ", true, null);
    
    $remText = getCleanSummaryRemediation($f['remediation']);
    addRunToParagraph($pNode, $remText . " ", false, null);
    
    $slaText = getSlaText($f['severity']);
    $slaColor = getSlaColorHex($f['severity']);
    addRunToParagraphWithColor($pNode, $slaText, true, $slaColor, null);
    
    $currentP = $pNode;
    foreach (array_slice($findings, 1) as $idx => $f) {
        $num = $idx + 2;
        $newP = insertParagraphAfter($currentP, $dom);
        
        $newPPr = $newP->getElementsByTagNameNS($wNs, 'pPr')->item(0);
        $indNodes = $newPPr->getElementsByTagNameNS($wNs, 'ind');
        foreach ($indNodes as $indNode) {
            $newPPr->removeChild($indNode);
        }
        $indEl = $dom->createElementNS($wNs, 'w:ind');
        $indEl->setAttribute('w:left', '360');
        $indEl->setAttribute('w:firstLine', '-360');
        $newPPr->appendChild($indEl);
        
        addRunToParagraph($newP, "$num.\t" . $f['name'] . ": ", true, null);
        
        $remText = getCleanSummaryRemediation($f['remediation']);
        addRunToParagraph($newP, $remText . " ", false, null);
        
        $slaText = getSlaText($f['severity']);
        $slaColor = getSlaColorHex($f['severity']);
        addRunToParagraphWithColor($newP, $slaText, true, $slaColor, null);
        
        $currentP = $newP;
    }
}

function populateConclusions($pNode, $highCount, $xpath) {
    global $GENERATOR_CONFIG;
    $aiConclusionsList = isset($GENERATOR_CONFIG['ai_conclusions']) ? $GENERATOR_CONFIG['ai_conclusions'] : [];
    
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $nodesToRemove = [];
    foreach ($pNode->childNodes as $child) {
        if ($child->localName !== 'pPr') {
            $nodesToRemove[] = $child;
        }
    }
    foreach ($nodesToRemove as $node) {
        $pNode->removeChild($node);
    }
    
    $dom = $pNode->ownerDocument;
    
    // Remove list indentation properties (if any) to render as standard text paragraph
    $pPrNodes = $pNode->getElementsByTagNameNS($wNs, 'pPr');
    if ($pPrNodes->length > 0) {
        $pPr = $pPrNodes->item(0);
        $indNodes = $pPr->getElementsByTagNameNS($wNs, 'ind');
        foreach ($indNodes as $indNode) {
            $pPr->removeChild($indNode);
        }
    }
    
    // Jika ada daftar kesimpulan hasil AI, gunakan langsung sebagai paragraf narasi terpadu!
    if (!empty($aiConclusionsList)) {
        $con = $aiConclusionsList[0];
        addRunToParagraph($pNode, $con, false, null);
        return;
    }
    
    // Fallback static paragraphs
    if ($highCount > 0) {
        $con = "Berdasarkan hasil pemeriksaan keamanan yang telah dilaksanakan, ditemukan sejumlah celah kerentanan bertingkat Tinggi (High) yang secara langsung mengancam integritas dan keberlangsungan sistem. Mengingat tingkat risiko yang signifikan tersebut, sistem dinyatakan belum memenuhi standar keamanan informasi minimum yang dipersyaratkan, sehingga penerbitan layanan kepada publik wajib ditunda hingga seluruh celah kritis berhasil diperbaiki sepenuhnya.";
    } else {
        $con = "Berdasarkan hasil pemeriksaan keamanan yang telah dilaksanakan, tidak ditemukan celah kerentanan dengan tingkat keparahan Tinggi (High) maupun Kritis (Critical). Secara keseluruhan, sistem dinilai telah memenuhi standar keamanan siber minimum yang dipersyaratkan sehingga layak untuk diterbitkan kepada publik, dengan rekomendasi agar administrator tetap menjalankan mitigasi berkala terhadap temuan bertingkat menengah dan rendah guna memperkuat ketahanan sistem.";
    }
    
    addRunToParagraph($pNode, $con, false, null);
}

function setParagraphAlignmentRight($pNode) {
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $dom = $pNode->ownerDocument;
    $pPrNodes = $pNode->getElementsByTagNameNS($wNs, 'pPr');
    if ($pPrNodes->length > 0) {
        $pPr = $pPrNodes->item(0);
    } else {
        $pPr = $dom->createElementNS($wNs, 'w:pPr');
        $pNode->insertBefore($pPr, $pNode->firstChild);
    }
    
    $jcNodes = $pPr->getElementsByTagNameNS($wNs, 'jc');
    foreach ($jcNodes as $jcNode) {
        $pPr->removeChild($jcNode);
    }
    
    $jcEl = $dom->createElementNS($wNs, 'w:jc');
    $jcEl->setAttribute('w:val', 'right');
    $pPr->appendChild($jcEl);
}

function setTcWidth($tcNode, $widthTwips, $dom, $wNs) {
    $tcPrNodes = $tcNode->getElementsByTagNameNS($wNs, 'tcPr');
    if ($tcPrNodes->length > 0) {
        $tcPr = $tcPrNodes->item(0);
    } else {
        $tcPr = $dom->createElementNS($wNs, 'w:tcPr');
        $tcNode->insertBefore($tcPr, $tcNode->firstChild);
    }
    
    // Remove existing tcW
    $tcWNodes = $tcPr->getElementsByTagNameNS($wNs, 'tcW');
    foreach ($tcWNodes as $tcWNode) {
        $tcPr->removeChild($tcWNode);
    }
    
    $tcWEl = $dom->createElementNS($wNs, 'w:tcW');
    $tcWEl->setAttribute('w:w', (string)$widthTwips);
    $tcWEl->setAttribute('w:type', 'dxa');
    $tcPr->insertBefore($tcWEl, $tcPr->firstChild);
}

function setRowColumnWidths($trNode, $widths, $dom) {
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $tcNodes = [];
    foreach ($trNode->childNodes as $child) {
        if ($child->localName === 'tc') {
            $tcNodes[] = $child;
        }
    }
    foreach ($tcNodes as $idx => $tcNode) {
        if (isset($widths[$idx])) {
            setTcWidth($tcNode, $widths[$idx], $dom, $wNs);
        }
    }
}

function setCellShading($pNode, $colorHex) {
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    $dom = $pNode->ownerDocument;
    
    // Find the parent cell <w:tc> node
    $tcNode = $pNode;
    while ($tcNode && $tcNode->localName !== 'tc') {
        $tcNode = $tcNode->parentNode;
    }
    
    if ($tcNode) {
        $tcPrNodes = $tcNode->getElementsByTagNameNS($wNs, 'tcPr');
        if ($tcPrNodes->length > 0) {
            $tcPr = $tcPrNodes->item(0);
        } else {
            $tcPr = $dom->createElementNS($wNs, 'w:tcPr');
            $tcNode->insertBefore($tcPr, $tcNode->firstChild);
        }
        
        // Remove existing shd
        $shdNodes = $tcPr->getElementsByTagNameNS($wNs, 'shd');
        foreach ($shdNodes as $shdNode) {
            $tcPr->removeChild($shdNode);
        }
        
        $shdEl = $dom->createElementNS($wNs, 'w:shd');
        $shdEl->setAttribute('w:val', 'clear');
        $shdEl->setAttribute('w:color', 'auto');
        $shdEl->setAttribute('w:fill', $colorHex);
        $tcPr->appendChild($shdEl);
    }
}

function replaceAllPlaceholdersInParagraph($pNode, $replacements, $findings, $highCount, $xpath) {
    $text = getParagraphText($pNode);
    if (strpos($text, '{{REKOMENDASI_ISI}}') !== false) {
        populateRecommendations($pNode, $findings, $xpath);
    } elseif (strpos($text, '{{KESIMPULAN_ISI}}') !== false) {
        populateConclusions($pNode, $highCount, $xpath);
    } else {
        $localReplacements = $replacements;
        if (strpos($text, '{{TANGGAL_SURAT}}') !== false && strpos($text, 'menerangkan') !== false) {
            list($dayNameSpelled, $dateStrSpelled) = getIndonesianDateSpelled();
            $localReplacements['{{TANGGAL_SURAT}}'] = $dateStrSpelled;
        }
        if (strpos($text, '{{TANGGAL_SURAT}}') !== false && strpos($text, 'Bogor,') !== false) {
            setParagraphAlignmentRight($pNode);
        }
        
        if (strpos($text, '{{RISK_APP_LEVEL}}') !== false || strpos($text, '{{RISK_CONFIG_LEVEL}}') !== false || strpos($text, '{{RISK_INFO_LEVEL}}') !== false || strpos($text, '{{RISK_AVAIL_LEVEL}}') !== false) {
            $val = '';
            if (strpos($text, '{{RISK_APP_LEVEL}}') !== false) $val = $replacements['{{RISK_APP_LEVEL}}'];
            elseif (strpos($text, '{{RISK_CONFIG_LEVEL}}') !== false) $val = $replacements['{{RISK_CONFIG_LEVEL}}'];
            elseif (strpos($text, '{{RISK_INFO_LEVEL}}') !== false) $val = $replacements['{{RISK_INFO_LEVEL}}'];
            elseif (strpos($text, '{{RISK_AVAIL_LEVEL}}') !== false) $val = $replacements['{{RISK_AVAIL_LEVEL}}'];
            
            $colorHex = 'EAECEE'; // default soft gray
            if ($val === 'Tinggi') $colorHex = 'FADBD8'; // soft red
            elseif ($val === 'Sedang') $colorHex = 'FDEBD0'; // soft orange
            elseif ($val === 'Rendah') $colorHex = 'D6EAF8'; // soft blue
            elseif ($val === 'Informasi') $colorHex = 'EAECEE'; // soft gray
            
            setCellShading($pNode, $colorHex);
        }
        
        replaceAndBoldPlaceholdersInParagraph($pNode, $localReplacements, $xpath);
    }
}

function parseXmlReport($xmlPath) {
    echo "Parsing Burp Suite XML: $xmlPath\n";
    
    $xmlContent = file_get_contents($xmlPath);
    if ($xmlContent === false) {
        throw new Exception("Gagal membaca berkas XML scan.");
    }
    
    // Sanitize raw XML string from invalid control characters (Burp suite binary payload residues)
    $cleanedXmlContent = preg_replace('/[^\x09\x0A\x0D\x20-\xD7FF\xE000-\xFFFD]/', '', $xmlContent);
    
    $disableEntities = PHP_VERSION_ID < 80000;
    if ($disableEntities) {
        $oldEntityLoader = libxml_disable_entity_loader(true);
    }
    $xml = simplexml_load_string($cleanedXmlContent);
    if ($disableEntities && isset($oldEntityLoader)) {
        libxml_disable_entity_loader($oldEntityLoader);
    }
    
    if ($xml === false) {
        throw new Exception("Format XML tidak valid atau berkas tidak dapat dibaca.");
    }
    
    $rootName = strtolower($xml->getName());
    
    if (strpos($rootName, 'article') !== false) {
        return parseDocbookBurpXml($xml, $xmlPath);
    } else {
        $issues = $xml->xpath('//issue');
        if (!empty($issues)) {
            return parseStandardBurpXml($xml, $xmlPath);
        } else {
            return parseDocbookBurpXml($xml, $xmlPath);
        }
    }
}

function parseHtmlReport($htmlPath) {
    $logDir = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }
    $logFile = $logDir . DIRECTORY_SEPARATOR . 'debug_parser.log';
    file_put_contents($logFile, "=== Start Parsing HTML: " . date('Y-m-d H:i:s') . " ===\n");
    file_put_contents($logFile, "File Path: " . $htmlPath . "\n", FILE_APPEND);
    
    $htmlContent = file_get_contents($htmlPath);
    if ($htmlContent === false) {
        file_put_contents($logFile, "Error: Gagal membaca berkas HTML.\n", FILE_APPEND);
        throw new Exception("Gagal membaca berkas HTML scan.");
    }
    
    file_put_contents($logFile, "Loaded HTML content length: " . strlen($htmlContent) . " bytes\n", FILE_APPEND);
    
    $dom = new DOMDocument();
    
    $disableEntities = PHP_VERSION_ID < 80000;
    if ($disableEntities) {
        $oldEntityLoader = libxml_disable_entity_loader(true);
    }
    libxml_use_internal_errors(true);
    $dom->loadHTML($htmlContent);
    libxml_clear_errors();
    if ($disableEntities && isset($oldEntityLoader)) {
        libxml_disable_entity_loader($oldEntityLoader);
    }
    
    $xpath = new DOMXPath($dom);
    
    // In standard Burp Suite HTML reports, each finding instance has a table with class "summary_table"
    $summaryTables = $xpath->query("//table[@class='summary_table']");
    file_put_contents($logFile, "Found " . $summaryTables->length . " summary tables.\n", FILE_APPEND);
    
    $rawFindings = [];
    $targetUrl = "";
    $targetIp = "";
    // Ambil tanggal asli dari header Date di dalam hasil scan terlebih dahulu.
    $scanDate = extractDateFromHtmlContent($htmlContent);
    if (!$scanDate) $scanDate = extractDateFromFilename($htmlPath);
    if (!$scanDate) {
        $dateInfo = getIndonesianDate();
        $scanDate = $dateInfo[1];
    }
    
    $appNameFromHtml = null;
    
    $loopIndex = 0;
    foreach ($summaryTables as $table) {
        $loopIndex++;
        // 1. Find the closest preceding class="BODH0" span to get the vulnerability category name
        $categoryNode = $xpath->query("preceding::span[@class='BODH0'][1]", $table)->item(0);
        if (!$categoryNode) {
            file_put_contents($logFile, "  [$loopIndex] Warning: categoryNode not found. Skipping table.\n", FILE_APPEND);
            continue;
        }
        file_put_contents($logFile, "  [$loopIndex] Processing category: " . trim($categoryNode->textContent) . "\n", FILE_APPEND);
        
        $rawCategoryName = preg_replace('/^[\s\x{00a0}]+|[\s\x{00a0}]+$/u', '', $categoryNode->textContent);
        // Clean category name, remove numbers like "6. " or "6. "
        $name = preg_replace('/^\d+\.[\s\x{00a0}]*/u', '', $rawCategoryName);
        $name = preg_replace('/^[\s\x{00a0}]+|[\s\x{00a0}]+$/u', '', $name);
        
        // 2. Parse the properties inside the summary table
        $severity = "Information";
        $hostVal = "";
        $path = "";
        
        $rows = $table->getElementsByTagName('tr');
        foreach ($rows as $row) {
            $cells = $row->getElementsByTagName('td');
            if ($cells->length >= 3) {
                $key = preg_replace('/^[\s\x{00a0}]+|[\s\x{00a0}]+$/u', '', $cells->item(1)->textContent);
                $val = preg_replace('/^[\s\x{00a0}]+|[\s\x{00a0}]+$/u', '', $cells->item(2)->textContent);
            } else if ($cells->length >= 2) {
                $key = preg_replace('/^[\s\x{00a0}]+|[\s\x{00a0}]+$/u', '', $cells->item(0)->textContent);
                $val = preg_replace('/^[\s\x{00a0}]+|[\s\x{00a0}]+$/u', '', $cells->item(1)->textContent);
            } else {
                continue;
            }
            
            $keyClean = preg_replace('/[\s\x{00a0}:]+/u', '', $key);
            $keyLower = strtolower($keyClean);
            if ($keyLower === 'severity') {
                $severity = $val;
            } elseif ($keyLower === 'host') {
                $hostVal = $val;
            } elseif ($keyLower === 'path') {
                $path = $val;
            }
        }
        
        // 3. Extract background and remediation from adjacent preceding headings of the category
        $issueBg = "";
        $remBg = "";
        
        $sib = $categoryNode->nextSibling;
        while ($sib) {
            if ($sib->nodeType === XML_ELEMENT_NODE && $sib instanceof DOMElement) {
                // Stop if we hit a BODH1 or the next BODH0 (so we don't bleed into next categories)
                if ($sib->nodeName === 'span' && ($sib->getAttribute('class') === 'BODH1' || $sib->getAttribute('class') === 'BODH0')) {
                    break;
                }
                if ($sib->nodeName === 'h2') {
                    $headingText = strtolower(trim($sib->textContent));
                    if ($headingText === 'issue background' || $headingText === 'issue description') {
                        // Find next TEXT span
                        $n = $sib->nextSibling;
                        while ($n) {
                            if ($n->nodeType === XML_ELEMENT_NODE && $n instanceof DOMElement) {
                                if ($n->nodeName === 'span' && $n->getAttribute('class') === 'TEXT') {
                                    $issueBg = trim($n->textContent);
                                    break;
                                }
                                break;
                            }
                            $n = $n->nextSibling;
                        }
                    } elseif ($headingText === 'issue remediation' || $headingText === 'remediation background' || $headingText === 'remediation detail') {
                        // Find next TEXT span
                        $n = $sib->nextSibling;
                        while ($n) {
                            if ($n->nodeType === XML_ELEMENT_NODE && $n instanceof DOMElement) {
                                if ($n->nodeName === 'span' && $n->getAttribute('class') === 'TEXT') {
                                    $remBg = trim($n->textContent);
                                    break;
                                }
                                break;
                            }
                            $n = $n->nextSibling;
                        }
                    }
                }
            }
            $sib = $sib->nextSibling;
        }
        
        // 4. Extract issue detail (following sibling h2 with text "Issue detail" within the same block)
        $issueDetail = "";
        $sibling = $table->nextSibling;
        while ($sibling) {
            if ($sibling->nodeType === XML_ELEMENT_NODE && $sibling instanceof DOMElement) {
                // Stop if we hit the next summary table or a separator
                if ($sibling->nodeName === 'table' && $sibling->getAttribute('class') === 'summary_table') {
                    break;
                }
                if ($sibling->nodeName === 'div' && $sibling->getAttribute('class') === 'rule') {
                    break;
                }
                if ($sibling->nodeName === 'h2' && strtolower(trim($sibling->textContent)) === 'issue detail') {
                    // Find next TEXT span
                    $next = $sibling->nextSibling;
                    while ($next) {
                        if ($next->nodeType === XML_ELEMENT_NODE && $next instanceof DOMElement) {
                            if ($next->nodeName === 'span' && $next->getAttribute('class') === 'TEXT') {
                                $issueDetail = trim($next->textContent);
                                break;
                            }
                            break;
                        }
                        $next = $next->nextSibling;
                    }
                }
            }
            $sibling = $sibling->nextSibling;
        }
        
        if (empty($targetUrl) && !empty($hostVal)) {
            $targetUrl = $hostVal;
        }
        
        $param = "";
        $payload = "";
        if (!empty($issueDetail)) {
            if (preg_match('/([a-zA-Z0-9_.-]+) parameter/i', $issueDetail, $mParam)) {
                $param = $mParam[1];
            }
            if (preg_match('/(?:payload was|payload)\s+([^\s]+)/i', $issueDetail, $mPayload)) {
                $payload = trim($mPayload[1], '.,;()[]');
            }
        }
        
        file_put_contents($logFile, "    Parsed Instance: name='{$name}' severity='{$severity}' host='{$hostVal}' path='{$path}'\n", FILE_APPEND);
        
        $rawFindings[] = [
            'name' => $name,
            'severity' => $severity,
            'host' => $hostVal,
            'path' => $path,
            'background' => $issueBg,
            'remediation' => !empty($remBg) ? $remBg : "Terapkan praktik pengkodean aman dan ikuti rekomendasi standar.",
            'detail' => $issueDetail,
            'parameter' => $param,
            'payload' => $payload
        ];
    }
    
    if (!empty($targetUrl)) {
        $hostClean = preg_replace('/^https?:\/\//i', '', $targetUrl);
        $hostClean = preg_replace('/\/.*$/', '', $hostClean);
        $hostClean = preg_replace('/:.*$/', '', $hostClean);
        if (filter_var($hostClean, FILTER_VALIDATE_IP)) {
            $targetIp = $hostClean;
        } else {
            $ip = @gethostbyname($hostClean);
            if ($ip && $ip !== $hostClean) {
                $targetIp = $ip;
            }
        }
    }
    
    file_put_contents($logFile, "Processed raw findings count: " . count($rawFindings) . "\n", FILE_APPEND);
    $result = processRawFindings($rawFindings, $targetUrl, $targetIp, $scanDate, $appNameFromHtml);
    file_put_contents($logFile, "Final processed findings count: " . count($result['findings']) . "\n", FILE_APPEND);
    return $result;
}

function parseStandardBurpXml($xml, $xmlPath) {
    $issues = $xml->xpath('//issue');
    $rawFindings = [];
    
    $targetUrl = "";
    $targetIp = "";
    
    $scanDate = extractDateFromFilename($xmlPath);
    if (!$scanDate) {
        $exportTime = (string)$xml['exportTime'];
        if ($exportTime) {
            $scanDate = parseBurpDate($exportTime);
        } else {
            $dateInfo = getIndonesianDate();
            $scanDate = $dateInfo[1];
        }
    }
    
    $appNameFromHtml = null;
    
    foreach ($issues as $issue) {
        $name = isset($issue->name) ? (string)$issue->name : "Unknown";
        $severity = isset($issue->severity) ? (string)$issue->severity : "Information";
        
        $hostVal = isset($issue->host) ? (string)$issue->host : "";
        $hostIpVal = "";
        if (isset($issue->host) && $issue->host->attributes()) {
            $attrs = $issue->host->attributes();
            if (isset($attrs['ip'])) {
                $hostIpVal = (string)$attrs['ip'];
            }
        }
        
        $path = isset($issue->path) ? (string)$issue->path : "";
        
        $issueBg = isset($issue->issueBackground) ? (string)$issue->issueBackground : "";
        $remBg = isset($issue->remediationBackground) ? (string)$issue->remediationBackground : "";
        $issueDetail = isset($issue->issueDetail) ? (string)$issue->issueDetail : "";
        $remDetail = isset($issue->remediationDetail) ? (string)$issue->remediationDetail : "";
        
        if (empty($targetUrl) && !empty($hostVal)) {
            $targetUrl = $hostVal;
        }
        if (empty($targetIp) && !empty($hostIpVal)) {
            $targetIp = $hostIpVal;
        }
        
        if ($appNameFromHtml === null) {
            if (isset($issue->requestresponse)) {
                foreach ($issue->requestresponse as $rr) {
                    if (isset($rr->response) && !empty((string)$rr->response)) {
                        try {
                            $respText = (string)$rr->response;
                            $respBytes = base64_decode($respText);
                            if ($respBytes !== false) {
                                if (preg_match('/<title>(.*?)<\/title>/is', $respBytes, $match)) {
                                    $candidate = trim($match[1]);
                                    $candidate = preg_replace('/\s*-\s*.*/', '', $candidate);
                                    $candLower = strtolower($candidate);
                                    $ignoredTitles = ["404 not found", "not found", "403 forbidden", "forbidden", "error", "untitled"];
                                    if (!in_array($candLower, $ignoredTitles)) {
                                        $appNameFromHtml = $candidate;
                                        echo "Extracted Application Title from HTML Response: $appNameFromHtml\n";
                                        break;
                                    }
                                }
                            }
                        } catch (Exception $e) {
                            // ignore
                        }
                    }
                }
            }
        }
        
        $param = "";
        $payload = "";
        if (!empty($issueDetail)) {
            if (preg_match('/([a-zA-Z0-9_.-]+) parameter/i', $issueDetail, $mParam)) {
                $param = $mParam[1];
            }
            if (preg_match('/(?:payload was|payload)\s+([^\s]+)/i', $issueDetail, $mPayload)) {
                $payload = trim($mPayload[1], '.,;()[]');
            }
        }
        
        $rawFindings[] = [
            'name' => $name,
            'severity' => $severity,
            'host' => $hostVal,
            'path' => $path,
            'background' => $issueBg,
            'remediation' => !empty($remBg) ? $remBg : (!empty($remDetail) ? $remDetail : "Terapkan praktik pengkodean aman dan ikuti rekomendasi standar."),
            'detail' => $issueDetail,
            'parameter' => $param,
            'payload' => $payload
        ];
    }
    
    return processRawFindings($rawFindings, $targetUrl, $targetIp, $scanDate, $appNameFromHtml);
}

function parseDocbookBurpXml($xmlSimple, $xmlPath) {
    $dom = new DOMDocument();
    
    $xmlContent = file_get_contents($xmlPath);
    if ($xmlContent === false) {
        throw new Exception("Gagal membaca berkas XML scan Docbook.");
    }
    $cleanedXmlContent = preg_replace('/[^\x09\x0A\x0D\x20-\xD7FF\xE000-\xFFFD]/', '', $xmlContent);
    
    $disableEntities = PHP_VERSION_ID < 80000;
    if ($disableEntities) {
        $oldEntityLoader = libxml_disable_entity_loader(true);
    }
    $dom->loadXML($cleanedXmlContent);
    if ($disableEntities && isset($oldEntityLoader)) {
        libxml_disable_entity_loader($oldEntityLoader);
    }
    
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('db', 'http://docbook.org/ns/docbook');
    
    $contentsSec = null;
    $sections = $xpath->query('//db:section[@xml:id="contents"] | //db:section[@id="contents"]');
    if ($sections->length > 0) {
        $contentsSec = $sections->item(0);
    } else {
        $contentsSec = $dom->documentElement;
    }
    
    $elements = $contentsSec->getElementsByTagName('*');
    $numElements = $elements->length;
    
    $rawFindings = [];
    $currentFinding = null;
    
    $targetUrl = "";
    $targetIp = "";
    
    $scanDate = extractDateFromFilename($xmlPath);
    if (!$scanDate) {
        $dateInfo = getIndonesianDate();
        $scanDate = $dateInfo[1];
    }
    
    $i = 0;
    while ($i < $numElements) {
        $child = $elements->item($i);
        $tag = $child->localName;
        $xmlId = getXmlId($child);
        
        if ($tag === 'anchor' && preg_match('/^id_\d+$/', $xmlId)) {
            if ($currentFinding) {
                foreach ($currentFinding['instances'] as $inst) {
                    $rawFindings[] = [
                        'name' => $currentFinding['name'],
                        'severity' => $inst['severity'],
                        'host' => $inst['host'],
                        'path' => $inst['path'],
                        'background' => $currentFinding['background'],
                        'remediation' => $currentFinding['remediation'],
                        'detail' => !empty($inst['detail']) ? $inst['detail'] : $currentFinding['background'],
                        'parameter' => $inst['parameter'],
                        'payload' => $inst['payload']
                    ];
                }
            }
            
            $vulnName = "Unknown";
            $maxLookAhead = min($i + 10, $numElements);
            for ($j = $i + 1; $j < $maxLookAhead; $j++) {
                $aheadEl = $elements->item($j);
                if ($aheadEl->localName === 'link') {
                    $vulnName = trim($aheadEl->textContent);
                    break;
                }
            }
            
            $currentFinding = [
                'id' => $xmlId,
                'name' => $vulnName,
                'background' => "",
                'remediation' => "",
                'instances' => []
            ];
        } elseif ($currentFinding) {
            if ($tag === 'section') {
                $titleEl = null;
                foreach ($child->childNodes as $cn) {
                    if ($cn->localName === 'title') {
                        $titleEl = $cn;
                        break;
                    }
                }
                $title = $titleEl ? trim($titleEl->textContent) : "";
                
                if (strpos($xmlId, 'issue-background') !== false || $title === "Issue background") {
                    $bgText = trim($child->textContent);
                    $currentFinding['background'] = trim(str_replace("Issue background", "", $bgText));
                } elseif (strpos($xmlId, 'issue-remediation') !== false || $title === "Issue remediation") {
                    $remText = trim($child->textContent);
                    $currentFinding['remediation'] = trim(str_replace("Issue remediation", "", $remText));
                } elseif (strpos($xmlId, 'summary-') !== false || $title === "Summary") {
                    $tables = $child->getElementsByTagName('informaltable');
                    if ($tables->length > 0) {
                        $table = $tables->item(0);
                        $severity = "Information";
                        $host = "";
                        $path = "";
                        
                        $rows = $table->getElementsByTagName('row');
                        foreach ($rows as $row) {
                            $entries = $row->getElementsByTagName('entry');
                            $rowText = [];
                            foreach ($entries as $entry) {
                                $rowText[] = trim($entry->textContent);
                            }
                            
                            foreach ($rowText as $rIdx => $rTxt) {
                                if (strpos($rTxt, 'Severity:') !== false && $rIdx + 1 < count($rowText)) {
                                    $severity = $rowText[$rIdx + 1];
                                } elseif (strpos($rTxt, 'Host:') !== false && $rIdx + 1 < count($rowText)) {
                                    $host = $rowText[$rIdx + 1];
                                } elseif (strpos($rTxt, 'Path:') !== false && $rIdx + 1 < count($rowText)) {
                                    $path = $rowText[$rIdx + 1];
                                }
                            }
                        }
                        
                        $currentFinding['instances'][] = [
                            'severity' => $severity,
                            'host' => $host,
                            'path' => $path,
                            'detail' => '',
                            'parameter' => '',
                            'payload' => ''
                        ];
                    }
                } elseif (strpos($xmlId, 'issue-detail') !== false || $title === "Issue detail") {
                    if (!empty($currentFinding['instances'])) {
                        $detailText = trim($child->textContent);
                        $detailText = trim(str_replace("Issue detail", "", $detailText));
                        
                        $lastIdx = count($currentFinding['instances']) - 1;
                        $currentFinding['instances'][$lastIdx]['detail'] = $detailText;
                        
                        if (preg_match('/([a-zA-Z0-9_.-]+) parameter/i', $detailText, $mParam)) {
                            $currentFinding['instances'][$lastIdx]['parameter'] = $mParam[1];
                        }
                        
                        $payloads = [];
                        $emps = $child->getElementsByTagName('emphasis');
                        foreach ($emps as $emp) {
                            if ($emp->getAttribute('role') === 'strong') {
                                $payloads[] = trim($emp->textContent);
                            }
                        }
                        if (!empty($payloads)) {
                            $currentFinding['instances'][$lastIdx]['payload'] = $payloads[0];
                        }
                    }
                }
            }
        }
        $i++;
    }
    
    if ($currentFinding) {
        foreach ($currentFinding['instances'] as $inst) {
            $rawFindings[] = [
                'name' => $currentFinding['name'],
                'severity' => $inst['severity'],
                'host' => $inst['host'],
                'path' => $inst['path'],
                'background' => $currentFinding['background'],
                'remediation' => $currentFinding['remediation'],
                'detail' => !empty($inst['detail']) ? $inst['detail'] : $currentFinding['background'],
                'parameter' => $inst['parameter'],
                'payload' => $inst['payload']
            ];
        }
    }
    
    foreach ($rawFindings as $f) {
        if (empty($targetUrl) && !empty($f['host'])) {
            $targetUrl = $f['host'];
        }
        if (empty($targetIp) && !empty($f['host'])) {
            $targetIp = $f['host'];
        }
    }
    
    $appNameFromHtml = null;
    return processRawFindings($rawFindings, $targetUrl, $targetIp, $scanDate, $appNameFromHtml);
}

function processRawFindings($rawFindings, $targetUrl, $targetIp, $scanDate, $appNameFromHtml) {
    global $SEVERITY_MAP, $VULN_TRANSLATION;
    
    $groupedFindings = [];
    
    foreach ($rawFindings as &$f) {
        $f['name'] = trim(preg_replace('/\s+/', ' ', $f['name']));
    }
    unset($f);
    
    foreach ($rawFindings as $f) {
        $key = $f['name'] . '|' . $f['severity'];
        if (!isset($groupedFindings[$key])) {
            $groupedFindings[$key] = [
                'name' => $f['name'],
                'severity' => $f['severity'],
                'instances' => [],
                'background' => $f['background'],
                'remediation' => $f['remediation'],
                'detail' => $f['detail']
            ];
        }
        $groupedFindings[$key]['instances'][] = [
            'path' => !empty($f['path']) ? $f['path'] : "Seluruh Domain",
            'parameter' => isset($f['parameter']) ? $f['parameter'] : '',
            'payload' => isset($f['payload']) ? $f['payload'] : ''
        ];
    }
    
    // Warm up translation cache in parallel to optimize Google Translate execution time
    if (GOOGLE_TRANSLATE_AVAILABLE) {
        $textsToTranslate = [];
        foreach ($groupedFindings as $key => $data) {
            $nameClean = strtolower(trim(preg_replace('/\s+/', ' ', $data['name'])));
            
            // Check if it's already in the local dictionary. If it is, no translation is needed
            $matched = false;
            if (isset($VULN_TRANSLATION[$nameClean])) {
                $matched = true;
            } else {
                foreach ($VULN_TRANSLATION as $k => $t) {
                    if (strpos($nameClean, $k) !== false) {
                        $matched = true;
                        break;
                    }
                }
            }
            
            if (!$matched) {
                $cleanedBg = cleanHtml($data['background']);
                $cleanedRem = cleanHtml($data['remediation']);
                if (!empty(trim($cleanedBg))) {
                    $textsToTranslate[] = $cleanedBg;
                }
                if (!empty(trim($cleanedRem))) {
                    $textsToTranslate[] = $cleanedRem;
                }
            }
        }
        if (!empty($textsToTranslate)) {
            warmUpTranslateCacheParallel($textsToTranslate);
        }
    }
    
    $findings = [];
    foreach ($groupedFindings as $key => $data) {
        $instStrings = [];
        $seenInsts = [];
        $uniqueInstances = [];
        foreach ($data['instances'] as $inst) {
            $instKey = $inst['path'] . '|' . $inst['parameter'] . '|' . $inst['payload'];
            if (in_array($instKey, $seenInsts)) {
                continue;
            }
            $seenInsts[] = $instKey;
            $uniqueInstances[] = $inst;
        }
        
        $maxDisplay = 10;
        $totalInstances = count($uniqueInstances);
        $displayInstances = array_slice($uniqueInstances, 0, $maxDisplay);
        
        foreach ($displayInstances as $inst) {
            $s = "- " . $inst['path'];
            if (!empty($inst['parameter'])) {
                $s .= " (Parameter: " . $inst['parameter'] . ")";
            }
            if (!empty($inst['payload'])) {
                $payStr = $inst['payload'];
                if (strlen($payStr) > 60) {
                    $payStr = substr($payStr, 0, 57) . "...";
                }
                $s .= " [Payload: " . $payStr . "]";
            }
            $instStrings[] = $s;
        }
        
        if ($totalInstances > $maxDisplay) {
            $remaining = $totalInstances - $maxDisplay;
            $instStrings[] = "... dan {$remaining} path/parameter lainnya (daftar lengkap tersedia pada berkas hasil scan).";
        }
        
        $pathsStr = implode("\n", $instStrings);
        
        list($idName, $idDetail, $idRemediation) = getTranslatedFinding(
            $data['name'], $data['severity'], $data['background'], $data['remediation']
        );
        
        $cvssScore = "";
        $cweId = "";
        $nameClean = strtolower(trim(preg_replace('/\s+/', ' ', $data['name'])));
        
        if (isset($VULN_TRANSLATION[$nameClean])) {
            $cvssScore = isset($VULN_TRANSLATION[$nameClean]['cvss']) ? $VULN_TRANSLATION[$nameClean]['cvss'] : '';
            $cweId = isset($VULN_TRANSLATION[$nameClean]['cwe']) ? $VULN_TRANSLATION[$nameClean]['cwe'] : '';
        } else {
            foreach ($VULN_TRANSLATION as $k => $t) {
                if (strpos($nameClean, $k) !== false) {
                    $cvssScore = isset($t['cvss']) ? $t['cvss'] : '';
                    $cweId = isset($t['cwe']) ? $t['cwe'] : '';
                    break;
                }
            }
        }
        
        $displayName = $idName;
        if (!empty($cweId)) {
            $displayName = "$idName [$cweId]";
        }
        
        $findings[] = [
            'name' => $displayName,
            'severity' => $data['severity'],
            'severity_id' => isset($SEVERITY_MAP[$data['severity']]) ? $SEVERITY_MAP[$data['severity']] : $data['severity'],
            'cvss' => $cvssScore,
            'paths_str' => $pathsStr,
            'background' => $idDetail,
            'remediation' => $idRemediation,
            'detail' => $idDetail
        ];
    }
    
    $rawCounts = [
        'Critical'    => 0,
        'High'        => 0,
        'Medium'      => 0,
        'Low'         => 0,
        'Information' => 0
    ];
    foreach ($rawFindings as $f) {
        $sev = $f['severity'];
        if ($sev === 'Critical') {
            $rawCounts['Critical']++;
            $rawCounts['High']++; // tetap hitung ke High untuk kompatibilitas logic kelayakan
        } elseif ($sev === 'High') {
            $rawCounts['High']++;
        } elseif ($sev === 'Medium') {
            $rawCounts['Medium']++;
        } elseif ($sev === 'Low') {
            $rawCounts['Low']++;
        } else {
            $rawCounts['Information']++;
        }
    }
    
    return [
        'findings' => $findings,
        'target_url' => $targetUrl,
        'target_ip' => $targetIp,
        'scan_date' => $scanDate,
        'html_app_name' => $appNameFromHtml,
        'raw_counts' => $rawCounts
    ];
}

/**
 * Terjemahkan teks Inggris dari Burp Suite ke Bahasa Indonesia.
 * Digunakan sebagai fallback saat vulnerability tidak ada di kamus $VULN_TRANSLATION.
 */
function translateBurpText($text) {
    if (empty($text)) return $text;
    
    // Frasa umum Burp Suite yang perlu diterjemahkan
    $phraseMap = [
        // Header/kata kunci teknis Burp
        'Issue background'        => 'Latar Belakang Masalah',
        'Issue remediation'       => 'Langkah Perbaikan',
        'Issue detail'            => 'Detail Masalah',
        'Remediation background'  => 'Latar Belakang Perbaikan',
        'Remediation detail'      => 'Detail Perbaikan',
        // Frasa umum dalam deskripsi Burp
        'The application'         => 'Aplikasi',
        'This application'        => 'Aplikasi ini',
        'The server'              => 'Server',
        'The web server'          => 'Web server',
        'This issue'              => 'Masalah ini',
        'This vulnerability'      => 'Kerentanan ini',
        'An attacker'             => 'Penyerang',
        'A malicious user'        => 'Pengguna jahat',
        'could be used'           => 'dapat digunakan',
        'can be used'             => 'dapat digunakan',
        'could allow'             => 'dapat memungkinkan',
        'may allow'               => 'dapat memungkinkan',
        'it is possible'          => 'hal ini memungkinkan',
        'It is possible'          => 'Hal ini memungkinkan',
        'for example'             => 'misalnya',
        'For example'             => 'Misalnya',
        'such as'                 => 'seperti',
        'in order to'             => 'untuk',
        'should be'               => 'harus',
        'should not'              => 'tidak boleh',
        'it is recommended'       => 'direkomendasikan',
        'It is recommended'       => 'Direkomendasikan',
        'it is advisable'         => 'disarankan',
        'It is advisable'         => 'Disarankan',
        'you should'              => 'Anda harus',
        'You should'              => 'Anda harus',
        'Note that'               => 'Perlu diperhatikan bahwa',
        'note that'               => 'perlu diperhatikan bahwa',
        'In general'              => 'Secara umum',
        'in general'              => 'secara umum',
        'as well as'              => 'maupun',
        'as well'                 => 'juga',
        'however'                 => 'namun',
        'However'                 => 'Namun',
        'therefore'               => 'oleh karena itu',
        'Therefore'               => 'Oleh karena itu',
        'Furthermore'             => 'Selain itu',
        'furthermore'             => 'selain itu',
        'Additionally'            => 'Selain itu',
        'additionally'            => 'selain itu',
        'In addition'             => 'Selain itu',
        'in addition'             => 'selain itu',
        'sensitive data'          => 'data sensitif',
        'sensitive information'   => 'informasi sensitif',
        'user input'              => 'input pengguna',
        'User input'              => 'Input pengguna',
        'web application'         => 'aplikasi web',
        'Web application'         => 'Aplikasi web',
        'web page'                => 'halaman web',
        'Web page'                => 'Halaman web',
        'web browser'             => 'browser web',
        'session cookie'          => 'cookie sesi',
        'session token'           => 'token sesi',
        'access token'            => 'token akses',
        'authentication'          => 'autentikasi',
        'authorization'           => 'otorisasi',
        'privilege escalation'    => 'eskalasi hak akses',
        'remote code execution'   => 'eksekusi kode jarak jauh',
        'code execution'          => 'eksekusi kode',
        'denial of service'       => 'penolakan layanan',
        'data exfiltration'       => 'eksfiltrasi data',
        'SQL query'               => 'query SQL',
        'database'                => 'basis data',
        'administrator'           => 'administrator',
        'server-side'             => 'sisi server',
        'client-side'             => 'sisi klien',
        'same-origin policy'      => 'kebijakan same-origin',
        'HTTP response'           => 'respon HTTP',
        'HTTP request'            => 'permintaan HTTP',
        'HTTP header'             => 'header HTTP',
        'response header'         => 'header respon',
        'request header'          => 'header permintaan',
        'third-party'             => 'pihak ketiga',
        'third party'             => 'pihak ketiga',
        'cross-origin'            => 'lintas-origin',
        'Same Site'               => 'Situs yang Sama',
        'same site'               => 'situs yang sama',
        'whitelist'               => 'daftar putih (whitelist)',
        'blacklist'               => 'daftar hitam (blacklist)',
        'encryption'              => 'enkripsi',
        'decryption'              => 'dekripsi',
        'certificate'             => 'sertifikat',
        'vulnerability'           => 'kerentanan',
        'vulnerabilities'         => 'kerentanan',
        'exploit'                 => 'eksploitasi',
        'payload'                 => 'payload',
        'injection'               => 'injeksi',
        'sanitization'            => 'sanitasi',
        'validation'              => 'validasi',
        'input validation'        => 'validasi input',
        'output encoding'         => 'encoding output',
        'encode'                  => 'encode',
        'encoding'                => 'encoding',
        'malicious'               => 'berbahaya',
        'attacker'                => 'penyerang',
        'victim'                  => 'korban',
        'password'                => 'kata sandi',
        'username'                => 'nama pengguna',
        'credentials'             => 'kredensial',
        'logged in'               => 'sudah login',
        'log in'                  => 'login',
        'login'                   => 'login',
        'logout'                  => 'logout',
        'file upload'             => 'unggahan file',
        'file system'             => 'sistem file',
        'directory'               => 'direktori',
        'server root'             => 'root server',
        'web root'                => 'web root',
        'configuration'           => 'konfigurasi',
        'misconfiguration'        => 'kesalahan konfigurasi',
        'hardcoded'               => 'hardcoded (dikodekan langsung)',
        'default credentials'     => 'kredensial default',
        'best practice'           => 'praktik terbaik',
        'best practices'          => 'praktik terbaik',
        'security header'         => 'header keamanan',
        'security headers'        => 'header keamanan',
        'Content Security Policy' => 'Kebijakan Keamanan Konten (CSP)',
        'clickjacking'            => 'Clickjacking',
        'cross-site scripting'    => 'Cross-Site Scripting (XSS)',
        'cross-site request forgery' => 'Pemalsuan Permintaan Lintas Situs (CSRF)',
    ];
    
    // Ganti frasa yang lebih panjang terlebih dahulu untuk menghindari penggantian parsial
    $pairs = $phraseMap;
    uksort($pairs, function($a, $b) { return strlen($b) - strlen($a); });
    
    foreach ($pairs as $en => $id) {
        $text = str_replace($en, $id, $text);
    }
    
    return $text;
}

/**
 * Terjemahkan teks menggunakan Google Translate via stichoza/google-translate-php.
 * Dilengkapi cache JSON lokal agar teks yang sama tidak diterjemah ulang.
 *
 * @param  string $text  Teks bahasa Inggris
 * @return string        Teks bahasa Indonesia (atau teks asli jika gagal)
 */
function translateWithGoogle($text) {
    if (empty(trim($text))) return $text;
    if (!GOOGLE_TRANSLATE_AVAILABLE) return $text;

    // Key cache: MD5 dari teks agar key tetap pendek
    $cacheKey = md5($text);

    // 1. Ambil dari database cache (SQLite) jika tersedia
    $db = getCacheDb();
    if ($db) {
        try {
            $stmt = $db->prepare("SELECT translated_text FROM translate_cache WHERE cache_key = :key");
            $stmt->execute([':key' => $cacheKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row['translated_text'];
            }
        } catch (Exception $e) {
            // Abaikan error DB, coba langsung ke API
        }
    } else {
        // Fallback: JSON file cache
        $cacheFile = __DIR__ . '/data/cache/translate_cache.json';
        if (file_exists($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $cache = ($raw !== false) ? json_decode($raw, true) : [];
            if (is_array($cache) && isset($cache[$cacheKey])) {
                return $cache[$cacheKey];
            }
        }
    }

    try {
        // Google Translate membatasi ~5000 karakter per request
        $chunk = mb_substr($text, 0, 4900, 'UTF-8');
        $tr = new Stichoza\GoogleTranslate\GoogleTranslate('id', 'en');
        $result = $tr->translate($chunk);

        if (!empty($result)) {
            // 2. Simpan ke database cache (SQLite) atau file JSON
            if ($db) {
                try {
                    $stmt = $db->prepare("INSERT OR REPLACE INTO translate_cache (cache_key, original_text, translated_text) VALUES (:key, :original, :translated)");
                    $stmt->execute([
                        ':key' => $cacheKey,
                        ':original' => $text,
                        ':translated' => $result
                    ]);
                } catch (Exception $e) {
                    // Abaikan error simpan DB
                }
            } else {
                // Fallback: Tulis ke JSON file cache secara aman dengan lock
                $cacheFile = __DIR__ . '/data/cache/translate_cache.json';
                $cache = [];
                if (file_exists($cacheFile)) {
                    $raw = @file_get_contents($cacheFile);
                    $cache = ($raw !== false) ? json_decode($raw, true) : [];
                }
                if (!is_array($cache)) $cache = [];
                $cache[$cacheKey] = $result;
                @file_put_contents(
                    $cacheFile,
                    json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                    LOCK_EX
                );
            }
            if (php_sapi_name() === 'cli') {
                echo "[Translate] \u2713 \"" . mb_substr($text, 0, 55, 'UTF-8') . "...\"\n";
            }
            return $result;
        }
    } catch (\Exception $e) {
        if (php_sapi_name() === 'cli') {
            echo "[Translate] \u26a0 Gagal: " . $e->getMessage() . "\n";
        }
    }

    // Jika Google Translate gagal, kembalikan teks asli
    return $text;
}

/**
 * Melakukan pemanasan cache terjemahan secara paralel menggunakan curl_multi.
 * Menerjemahkan semua teks unik yang belum ada di cache dalam satu waktu.
 *
 * @param array $texts Array berisi teks bahasa Inggris yang perlu diterjemahkan
 */
function warmUpTranslateCacheParallel($texts) {
    if (empty($texts)) return;
    
    // 1. Unikkan teks dan saring yang kosong
    $texts = array_filter(array_unique($texts), function($t) {
        return !empty(trim($t));
    });
    if (empty($texts)) return;

    $db = getCacheDb();
    $jsonCache = null;
    $cacheFile = __DIR__ . '/data/cache/translate_cache.json';

    if (!$db) {
        if (file_exists($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $jsonCache = ($raw !== false) ? json_decode($raw, true) : [];
        }
        if (!is_array($jsonCache)) $jsonCache = [];
    }

    // 2. Filter hanya teks yang belum ada di cache (SQLite / JSON)
    $missedTexts = [];
    foreach ($texts as $text) {
        $cacheKey = md5($text);
        $cached = false;

        if ($db) {
            try {
                $stmt = $db->prepare("SELECT 1 FROM translate_cache WHERE cache_key = :key");
                $stmt->execute([':key' => $cacheKey]);
                if ($stmt->fetch()) {
                    $cached = true;
                }
            } catch (Exception $e) {
                // Abaikan error DB
            }
        } else {
            if (isset($jsonCache[$cacheKey])) {
                $cached = true;
            }
        }

        if (!$cached) {
            $missedTexts[$cacheKey] = $text;
        }
    }

    if (empty($missedTexts)) return;

    // 3. Siapkan permintaan curl_multi
    $mh = curl_multi_init();
    $handles = [];

    foreach ($missedTexts as $cacheKey => $text) {
        $chunk = mb_substr($text, 0, 4900, 'UTF-8');
        
        $baseUrl = 'https://translate.google.com/translate_a/single';
        $params = [
            'client' => 'gtx',
            'sl' => 'en',
            'tl' => 'id',
            'dt' => 't',
            'ie' => 'UTF-8',
            'oe' => 'UTF-8',
            'q' => $chunk
        ];
        $url = $baseUrl . '?' . http_build_query($params);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 12);
        
        // Gunakan user-agent modern agar tidak dianggap bot
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
        
        $caFile = __DIR__ . '/cacert.pem';
        if (file_exists($caFile)) {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
        }

        curl_multi_add_handle($mh, $ch);
        $handles[$cacheKey] = $ch;
    }

    // 4. Jalankan transaksi curl secara paralel
    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) {
            // Tunggu aktivitas curl agar tidak memakan CPU 100%
            curl_multi_select($mh, 0.05);
        }
    } while ($active && $status == CURLM_OK);

    // 5. Baca dan simpan respons ke Cache
    foreach ($handles as $cacheKey => $ch) {
        $response = curl_multi_getcontent($ch);
        $info = curl_getinfo($ch);
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);

        if ($info['http_code'] === 200 && !empty($response)) {
            // Perbaiki response string untuk mencegah error JSON
            $bodyJson = preg_replace(
                ['/,+/', '/\[,/'],
                [',', '['],
                $response
            );
            
            try {
                $bodyArray = json_decode($bodyJson, true, 512, JSON_THROW_ON_ERROR);
                if (is_array($bodyArray) && isset($bodyArray[0])) {
                    if (is_array($bodyArray[0])) {
                        $result = (string) array_reduce($bodyArray[0], function ($carry, $item) {
                            $carry .= $item[0];
                            return $carry;
                        });
                    } else {
                        $result = (string) $bodyArray[0];
                    }

                    if (!empty(trim($result))) {
                        $originalText = $missedTexts[$cacheKey];
                        
                        // Simpan ke SQLite
                        if ($db) {
                            try {
                                $stmt = $db->prepare("INSERT OR REPLACE INTO translate_cache (cache_key, original_text, translated_text) VALUES (:key, :original, :translated)");
                                $stmt->execute([
                                    ':key' => $cacheKey,
                                    ':original' => $originalText,
                                    ':translated' => $result
                                ]);
                            } catch (Exception $e) {
                                // Abaikan error DB
                            }
                        } else {
                            // Simpan ke JSON
                            $jsonCache[$cacheKey] = $result;
                        }
                    }
                }
            } catch (Exception $e) {
                // Abaikan error decoding JSON
            }
        }
    }

    curl_multi_close($mh);

    // 6. Jika menggunakan JSON, tulis kembali berkas cache
    if (!$db && !empty($jsonCache)) {
        @file_put_contents(
            $cacheFile,
            json_encode($jsonCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            LOCK_EX
        );
    }
}

/**
 * Memformat output Markdown dari AI agar tampil rapi di dokumen Word (.docx)
 */
function formatMarkdownForWord($text) {
    if (empty($text)) return $text;
    
    // Ganti format inline code `code` menjadi tanda kutip tunggal
    $text = preg_replace('/`([^`]+)`/', "'$1'", $text);
    
    // Ganti blok kode markdown ```lang ... ``` dengan separator rapi untuk dokumen Word
    $text = preg_replace('/```[a-zA-Z]*\n?/i', "----------------------------------------\n", $text);
    $text = str_replace('```', "----------------------------------------\n", $text);
    
    return $text;
}

/**
 * Helper untuk memanggil API yang kompatibel dengan format OpenAI (OpenAI, Groq, OpenRouter)
 */
function callOpenAiCompatibleApi($url, $apiKey, $model, $prompt, $additionalHeaders = []) {
    if (empty($apiKey)) return null;
    $payload = ['model' => $model, 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.2];
    $headers = array_merge(['Content-Type: application/json','Authorization: Bearer ' . $apiKey], $additionalHeaders);
    $jsonPayload = json_encode($payload);
    $caFile = __DIR__ . '/cacert.pem';
    if (!function_exists('curl_init')) {
        $hdrStr = implode("\r\n", $headers);
        $opts = ['http' => ['method' => 'POST', 'header' => $hdrStr, 'content' => $jsonPayload, 'timeout' => 30, 'ignore_errors' => true]];
        if (file_exists($caFile)) $opts['ssl'] = ['cafile' => $caFile, 'verify_peer' => true];
        $ctx = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        $httpCode = 0;
        if (isset($http_response_header) && preg_match('#HTTP/\d\.\d\s+(\d+)#', $http_response_header[0] ?? '', $m)) $httpCode = (int)$m[1];
        if ($httpCode === 200 && !empty($response)) {
            $j = json_decode($response, true);
            if (isset($j['choices'][0]['message']['content'])) return trim($j['choices'][0]['message']['content']);
        }
        return null;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    if (file_exists($caFile)) curl_setopt($ch, CURLOPT_CAINFO, $caFile);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && !empty($response)) {
        $j = json_decode($response, true);
        if (isset($j['choices'][0]['message']['content'])) return trim($j['choices'][0]['message']['content']);
    }
    return null;
}

/**
 * Panggil Google Gemini API secara langsung menggunakan prompt mentah
 */
function getGeminiEnhancementRaw($prompt, $geminiKey, $model = 'gemini-1.5-flash') {
    if (empty($geminiKey)) return null;
    if (empty($model)) $model = 'gemini-1.5-flash';
    $url = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model) . ":generateContent?key=" . rawurlencode($geminiKey);
    $payload = ['contents' => [['parts' => [['text' => $prompt]]]], 'generationConfig' => ['maxOutputTokens' => 1500, 'temperature' => 0.2]];
    $jsonPayload = json_encode($payload);
    $caFile = __DIR__ . '/cacert.pem';
    if (!function_exists('curl_init')) {
        $opts = ['http' => ['method' => 'POST', 'header' => "Content-Type: application/json\r\n", 'content' => $jsonPayload, 'timeout' => 30, 'ignore_errors' => true]];
        if (file_exists($caFile)) $opts['ssl'] = ['cafile' => $caFile, 'verify_peer' => true];
        $ctx = stream_context_create($opts);
        $response = @file_get_contents($url, false, $ctx);
        $httpCode = 0;
        if (isset($http_response_header) && preg_match('#HTTP/\d\.\d\s+(\d+)#', $http_response_header[0] ?? '', $m)) $httpCode = (int)$m[1];
        if ($httpCode === 200 && !empty($response)) {
            $j = json_decode($response, true);
            if (isset($j['candidates'][0]['content']['parts'][0]['text'])) return trim($j['candidates'][0]['content']['parts'][0]['text']);
        }
        return null;
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonPayload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    if (file_exists($caFile)) curl_setopt($ch, CURLOPT_CAINFO, $caFile);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($httpCode === 200 && !empty($response)) {
        $j = json_decode($response, true);
        if (isset($j['candidates'][0]['content']['parts'][0]['text'])) return trim($j['candidates'][0]['content']['parts'][0]['text']);
    }
    return null;
}

/**
 * Mengirimkan prompt ke provider terpilih dan mengembalikan respon teks mentah
 */
function getAIResponse($prompt, $provider = 'groq') {
    global $GENERATOR_CONFIG;
    $custom_key = isset($GENERATOR_CONFIG['custom_api_key']) ? $GENERATOR_CONFIG['custom_api_key'] : '';
    $custom_model = isset($GENERATOR_CONFIG['ai_model']) ? $GENERATOR_CONFIG['ai_model'] : '';
    $model_to_use = $custom_model;
    if (empty($model_to_use)) {
        $cachedDefault = getDefaultModelFromCache($provider);
        if (!empty($cachedDefault)) {
            $model_to_use = $cachedDefault;
        } else {
            switch (strtolower($provider)) {
                case 'openai': $model_to_use = 'gpt-4o-mini'; break;
                case 'gemini': $model_to_use = 'gemini-1.5-flash'; break;
                case 'openrouter': $model_to_use = 'meta-llama/llama-3.3-70b-instruct'; break;
                case 'groq': default: $model_to_use = 'groq/compound'; break;
            }
        }
    }
    $keys = getProviderKeys($provider, $custom_key);
    if (empty($keys)) return null;
    $p = strtolower($provider);
    foreach ($keys as $key) {
        $res = null;
        if ($p === 'openai') {
            $res = callOpenAiCompatibleApi('https://api.openai.com/v1/chat/completions', $key, $model_to_use, $prompt);
        } elseif ($p === 'gemini') {
            $res = getGeminiEnhancementRaw($prompt, $key, $model_to_use);
        } elseif ($p === 'openrouter') {
            $res = callOpenAiCompatibleApi('https://openrouter.ai/api/v1/chat/completions', $key, $model_to_use, $prompt, ['HTTP-Referer: http://localhost:9090','X-Title: Pentest Report Generator']);
        } else {
            $res = callOpenAiCompatibleApi('https://api.groq.com/openai/v1/chat/completions', $key, $model_to_use, $prompt);
        }
        if (!empty($res)) return $res;
    }
    return null;
}

/**
 * Menyediakan basis pengetahuan offline rekomendasi perbaikan untuk temuan utama
 */
function getOfflineEnhancedRemediation($vulnName, $originalRem, $preset) {
    $nameClean = strtolower(trim(preg_replace('/\s+/', ' ', $vulnName)));
    
    $kb = [
        'sql injection' => [
            'php' => "Langkah Remediasi:\n1. Gunakan Parameterized Queries (Prepared Statements) dengan PDO:\n   ```php\n   \$stmt = \$pdo->prepare('SELECT * FROM users WHERE email = :email');\n   \$stmt->execute(['email' => \$userInput]);\n   \$user = \$stmt->fetch();\n   ```\n2. Hindari merangkai input secara langsung menggunakan penggabungan string (.).",
            'nodejs' => "Langkah Remediasi:\n1. Gunakan query berparameter pada database driver:\n   ```javascript\n   // Contoh Node-postgres (pg)\n   const query = 'SELECT * FROM users WHERE email = $1';\n   const values = [userInput];\n   const res = await client.query(query, values);\n   ```\n2. Hindari string interpolation atau concatenation.",
            'python' => "Langkah Remediasi:\n1. Gunakan Query ORM bawaan (contoh: Django ORM):\n   ```python\n   user = User.objects.filter(email=user_input)\n   ```\n2. Jika terpaksa menggunakan SQL mentah:\n   ```python\n   cursor.execute(\"SELECT * FROM users WHERE email = %s\", [user_input])\n   ```"
        ],
        'cross-site scripting' => [
            'php' => "Langkah Remediasi:\n1. Lakukan output encoding saat menampilkan data ke browser menggunakan `htmlspecialchars`:\n   ```php\n   echo htmlspecialchars(\$userInput, ENT_QUOTES, 'UTF-8');\n   ```\n2. Terapkan sistem template engine yang otomatis melakukan auto-escaping seperti Twig atau Blade.",
            'nodejs' => "Langkah Remediasi:\n1. Gunakan package sanitasi HTML seperti `dompurify` atau `sanitize-html`:\n   ```javascript\n   const sanitizeHtml = require('sanitize-html');\n   const cleanHtml = sanitizeHtml(userInput);\n   ```\n2. Hindari penggunaan API mentah seperti `innerHTML` di React.",
            'python' => "Langkah Remediasi:\n1. Gunakan Jinja2 template engine bawaan (otomatis melakukan auto-escaping):\n   ```html\n   <!-- Flask / Django Template -->\n   {{ user_input }}\n   ```\n2. Hindari menandai data input pengguna dengan tag `|safe` di Django secara sembarangan."
        ],
        'arbitrary file upload' => [
            'php' => "Langkah Remediasi:\n1. Validasi jenis file menggunakan Whitelist ekstensi dan MIME type:\n   ```php\n   \$allowed = ['jpg', 'jpeg', 'png', 'pdf'];\n   \$ext = pathinfo(\$_FILES['upload']['name'], PATHINFO_EXTENSION);\n   if (!in_array(strtolower(\$ext), \$allowed)) {\n       die('Tipe file dilarang!');\n   }\n   ```\n2. Jangan simpan file di folder web root publik secara langsung. Matikan hak akses eksekusi script server pada direktori penyimpanan.",
            'nodejs' => "Langkah Remediasi:\n1. Gunakan library upload file seperti `multer` dan lakukan filter ekstensi:\n   ```javascript\n   const fileFilter = (req, file, cb) => {\n     const allowedMime = ['image/jpeg', 'image/png'];\n     if (allowedMime.includes(file.mimetype)) cb(null, true);\n     else cb(new Error('Format file tidak valid'), false);\n   };\n   ```"
        ]
    ];
    
    // Cek kecocokan di basis pengetahuan lokal
    foreach ($kb as $key => $presets) {
        if (strpos($nameClean, $key) !== false) {
            $lang = isset($presets[$preset]) ? $preset : 'php';
            if (isset($presets[$lang])) {
                return $presets[$lang];
            }
        }
    }
    
    return $originalRem;
}

/**
 * Panggil AI secara efisien dalam satu request untuk memproses Dampak Potensial dan Rekomendasi (Token Saving)
 */
function getAIVulnAnalysis($vulnName, $originalBg, $originalRem, $preset, $provider = 'groq') {
    $prompt = "Anda adalah Lead Penetration Tester dan Auditor Keamanan Siber Senior. Berikan analisis dampak dan rekomendasi mitigasi untuk kerentanan berikut:\n"
            . "Nama Kerentanan: " . $vulnName . "\n"
            . "Fokus teknologi: " . strtoupper($preset) . "\n\n"
            . "INSTRUKSI GAYA PENULISAN (WAJIB DIPATUHI):\n"
            . "1. Gunakan Bahasa Indonesia formal korporat yang luwes dan alami (human-written style).\n"
            . "2. HINDARI frasa pembuka yang kaku dan berulang seperti 'Dampak dari temuan ini adalah...', 'Hal ini dapat mengakibatkan...', atau 'Untuk mengatasi masalah ini...'.\n"
            . "3. Dampak potensial harus langsung menjelaskan bahaya teknis/konsekuensi logis (contoh: 'Intersepsi data sensitif dapat terjadi karena...', 'Celah ini membuka ruang bagi penyerang untuk...', 'Browser pengguna rentan terhadap manipulasi jika...'). Maksimal 2 kalimat padat.\n"
            . "4. Rekomendasi mitigasi harus ditulis menggunakan kalimat instruktif aktif yang tegas (contoh: 'Terapkan filter...', 'Gunakan parameterized query...', 'Konfigurasikan header...'). Maksimal 4 kalimat termasuk contoh kode praktis yang bersih dan aman.\n\n"
            . "Format Output (langsung mulai dengan tag [DAMPAK], tanpa basa-basi):\n"
            . "[DAMPAK]\n"
            . "(Analisis dampak luwes)\n\n"
            . "[REKOMENDASI]\n"
            . "(Langkah mitigasi taktis & contoh kode)";

    $response = getAIResponse($prompt, $provider);
    if (!empty($response)) {
        $dampak = '';
        $rekomendasi = '';
        
        if (preg_match('/\[DAMPAK\](.*?)(?:\[REKOMENDASI\]|$)/is', $response, $matches)) {
            $dampak = trim($matches[1]);
        }
        if (preg_match('/\[REKOMENDASI\](.*)$/is', $response, $matches)) {
            $rekomendasi = trim($matches[1]);
        }
        
        if (!empty($dampak) || !empty($rekomendasi)) {
            return [$dampak, $rekomendasi];
        }
    }
    return [$originalBg, $originalRem];
}

/**
 * Meminta AI menghasilkan ringkasan Kesimpulan pengujian keamanan secara terstruktur (Token Saving)
 */
function getSummaryAIAnalysis($findings, $provider) {
    $findingsList = [];
    foreach ($findings as $f) {
        $name = preg_replace('/\s*\[CWE-\d+\]$/i', '', $f['name']);
        $findingsList[] = "- " . $name . " (Severity: " . $f['severity'] . ")";
    }
    $findingsStr = implode("\n", $findingsList);
    
    $prompt = "Anda adalah Lead Penetration Tester Senior yang menyusun laporan pengujian keamanan siber profesional. "
            . "Tuliskan satu paragraf ringkasan kesimpulan pengujian (bukan audit) yang sangat profesional, mendalam, dan mengalir secara alami berdasarkan daftar celah keamanan berikut:\n"
            . $findingsStr . "\n\n"
            . "Persyaratan Penulisan (WAJIB DIPATUHI):\n"
            . "1. Tulis dalam bentuk SATU paragraf utuh tanpa poin-poin, list, atau penomoran.\n"
            . "2. Gunakan Bahasa Indonesia yang baku sesuai KBBI, formal, dan bergaya konsultan keamanan siber profesional.\n"
            . "3. JANGAN gunakan kata 'audit' — ganti dengan frasa yang sesuai seperti 'pemeriksaan keamanan', 'pengujian penetrasi', atau 'pengujian keamanan'.\n"
            . "4. Panjang paragraf antara 60 hingga 90 kata.\n"
            . "5. Fokus pada dampak teknis dan strategis (risiko kompromi server, integritas data sensitif, kelemahan konfigurasi pertahanan).\n"
            . "6. Langsung mulai dengan teks kesimpulan tanpa kata pengantar, sapaan, atau penutup.";
            
    $response = getAIResponse($prompt, $provider);
    
    if (!empty($response)) {
        $paragraph = trim($response);
        $paragraph = preg_replace('/^\[KESIMPULAN\]\s*/i', '', $paragraph);
        $paragraph = preg_replace('/^-\s*/', '', $paragraph);
        if (!empty($paragraph)) {
            return [$paragraph];
        }
    }
    
    return [
        "Secara keseluruhan, hasil pemeriksaan keamanan yang telah dilaksanakan mengungkap sejumlah kelemahan sistemik pada aspek sanitasi masukan dan pengerasan konfigurasi sistem pertahanan. Kondisi tersebut berpotensi dieksploitasi oleh pihak yang tidak berwenang untuk melakukan kompromi sistem secara mendalam maupun mengakses data sensitif tanpa izin. Oleh karena itu, diperlukan penguatan postur keamanan siber secara menyeluruh serta perbaikan segera terhadap seluruh celah kritis sebelum aplikasi diterbitkan ke publik."
    ];
}

/**
 * Panggil AI secara efisien dalam satu request untuk memproses seluruh kerentanan secara batch (Token Saving & Anti-Timeout)
 */
/**
 * Melakukan sensor data sensitif (IP, cookies, token) sebelum dikirim ke API AI eksternal
 */
function maskSensitiveData($text) {
    if (empty($text)) return $text;
    // Sensor IPv4 Addresses
    $text = preg_replace('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP_MASKED]', $text);
    // Sensor Kredensial, Cookies, Token Otorisasi
    $text = preg_replace('/(Authorization|Bearer|Token|Cookie|Session|Password|Secret|Key):\s*[^\s\n]+/i', '$1: [MASKED]', $text);
    // Sensor Email
    $text = preg_replace('/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', '[EMAIL_MASKED]', $text);
    return $text;
}

function getBatchAIVulnAnalysis($findings, $preset, $provider) {
    $uniqueNames = [];
    $toAnalyze = [];
    $results = [];
    
    foreach ($findings as $f) {
        $nameClean = preg_replace('/\s*\[CWE-\d+\]$/i', '', $f['name']);
        $nameClean = preg_replace('/\s*\((?:Tinggi|Sedang|Rendah|Informasi|Kritis)[^)]*\(?[^)]*\)?\)\s*$/', '', $nameClean);
        $nameClean = trim($nameClean);
        $nameKey = strtolower($nameClean);
        
        $uniqueNames[$nameKey] = [
            'name' => $nameClean,
            'detail' => $f['background'],
            'remediation' => $f['remediation']
        ];
    }
    
    // Periksa dari cache lokal (SQLite atau JSON) terlebih dahulu
    $db = getCacheDb();
    $jsonCache = null;
    $cacheFile = __DIR__ . '/data/cache/ai_cache.json';

    if (!$db) {
        // Load JSON cache if SQLite is unavailable
        if (file_exists($cacheFile)) {
            $raw = @file_get_contents($cacheFile);
            $jsonCache = ($raw !== false) ? json_decode($raw, true) : [];
        }
        if (!is_array($jsonCache)) $jsonCache = [];
    }

    foreach ($uniqueNames as $nameKey => $info) {
        $cacheKey = $nameKey . '_' . $preset;
        $cachedItem = null;
        
        if ($db) {
            try {
                $stmt = $db->prepare("SELECT dampak, rekomendasi FROM ai_cache WHERE cache_key = :key");
                $stmt->execute([':key' => $cacheKey]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row) {
                    $cachedItem = [
                        'dampak' => $row['dampak'],
                        'rekomendasi' => $row['rekomendasi']
                    ];
                }
            } catch (Exception $e) {
                // Abaikan error DB
            }
        } else {
            if (isset($jsonCache[$cacheKey])) {
                $cachedItem = $jsonCache[$cacheKey];
            }
        }
        
        if ($cachedItem !== null) {
            $results[$nameKey] = $cachedItem;
        } else {
            $toAnalyze[$nameKey] = $info;
        }
    }
    
    // Jika semua kerentanan sudah ada di cache, langsung kembalikan tanpa hit API (Instant 0 detik)
    if (empty($toAnalyze)) {
        return $results;
    }
    
    $prompt = "Anda adalah Lead Penetration Tester dan Auditor Keamanan Siber Senior. Berikan analisis dampak dan rekomendasi mitigasi untuk daftar kerentanan berikut dengan fokus teknologi: " . strtoupper($preset) . ".\n\n"
            . "Persyaratan Output & Gaya Penulisan (WAJIB DIPATUHI SECARA KETAT):\n"
            . "1. Gunakan Bahasa Indonesia formal korporat yang luwes dan alami (human-written style).\n"
            . "2. HINDARI frasa pembuka yang kaku dan berulang seperti 'Dampak dari temuan ini adalah...', 'Hal ini dapat mengakibatkan...', atau 'Untuk mengatasi masalah ini...'.\n"
            . "3. Dampak potensial harus langsung menjelaskan bahaya teknis/konsekuensi logis (contoh: 'Intersepsi data sensitif dapat terjadi karena...', 'Celah ini membuka ruang bagi penyerang untuk...', 'Browser pengguna rentan terhadap manipulasi jika...'). Maksimal 2 kalimat padat.\n"
            . "4. Rekomendasi mitigasi harus ditulis menggunakan kalimat instruktif aktif yang tegas (contoh: 'Terapkan filter...', 'Gunakan parameterized query...', 'Konfigurasikan header...'). Maksimal 4 kalimat termasuk contoh kode praktis yang bersih dan aman.\n"
            . "5. Gunakan format pembatas eksak di bawah untuk setiap temuan:\n\n";
            
    foreach ($toAnalyze as $nameKey => $info) {
        $prompt .= "==TEMUAN_START==\n"
                 . "NAMA: " . $info['name'] . "\n"
                 . "DETAIL: " . maskSensitiveData(strip_tags($info['detail'])) . "\n"
                 . "REMEDIASI: " . maskSensitiveData(strip_tags($info['remediation'])) . "\n"
                 . "==TEMUAN_END==\n\n";
    }
    
    $prompt .= "\nUntuk setiap blok temuan, berikan respon Anda dalam format pembatas eksak berikut (NAMA harus persis sama):\n"
             . "==ANALISIS_START==\n"
             . "NAMA: (Nama temuan)\n"
             . "[DAMPAK]\n"
             . "(Penjelasan dampak singkat, maks 2 kalimat)\n"
             . "[REKOMENDASI]\n"
             . "(Langkah mitigasi taktis & contoh kode, maks 4 kalimat)\n"
             . "==ANALISIS_END==\n";
             
    $response = getAIResponse($prompt, $provider);
    
    if (!empty($response)) {
        preg_match_all('/==ANALISIS_START==(.*?)==ANALISIS_END==/is', $response, $blocks);
        
        $insertStmt = null;
        if ($db) {
            try {
                $insertStmt = $db->prepare("INSERT OR REPLACE INTO ai_cache (cache_key, vuln_name, preset, dampak, rekomendasi) VALUES (:key, :name, :preset, :dampak, :rekomendasi)");
            } catch (Exception $e) {
                $insertStmt = null;
            }
        }

        foreach ($blocks[1] as $block) {
            $name = '';
            $dampak = '';
            $rekomendasi = '';
            
            if (preg_match('/NAMA:\s*(.*?)(?=\n|\[DAMPAK\]|\[REKOMENDASI\])/i', $block, $m)) {
                $name = strtolower(trim($m[1]));
            }
            if (preg_match('/\[DAMPAK\](.*?)(?=\[REKOMENDASI\]|$)/is', $block, $m)) {
                $dampak = trim($m[1]);
            }
            if (preg_match('/\[REKOMENDASI\](.*)$/is', $block, $m)) {
                $rekomendasi = trim($m[1]);
            }
            
            if (!empty($name)) {
                $itemResult = [
                    'dampak' => formatMarkdownForWord($dampak),
                    'rekomendasi' => formatMarkdownForWord($rekomendasi)
                ];
                $results[$name] = $itemResult;
                
                // Simpan ke SQLite
                if ($insertStmt) {
                    try {
                        $cacheKey = $name . '_' . $preset;
                        $insertStmt->execute([
                            ':key' => $cacheKey,
                            ':name' => $name,
                            ':preset' => $preset,
                            ':dampak' => $itemResult['dampak'],
                            ':rekomendasi' => $itemResult['rekomendasi']
                        ]);
                    } catch (Exception $e) {
                        // Abaikan error DB
                    }
                } else {
                    // Fallback: Simpan ke JSON
                    $cacheKey = $name . '_' . $preset;
                    $jsonCache[$cacheKey] = $itemResult;
                }
            }
        }

        // Tulis kembali JSON jika database tidak aktif
        if (!$db && !empty($jsonCache)) {
            @file_put_contents(
                $cacheFile,
                json_encode($jsonCache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }
    }
    
    return $results;
}


/**
 * Mengkoordinasikan AI Enhancer untuk langkah perbaikan
 */
function enhanceRemediation($vulnName, $originalRem, $preset, $provider = 'groq') {
    // Fungsi ini dipertahankan untuk kompatibilitas jika dipanggil bagian lain
    $resp = getAIResponse("Berikan penjelasan remediasi singkat dalam 2 kalimat untuk: " . $vulnName, $provider);
    return !empty($resp) ? $resp : getOfflineEnhancedRemediation($vulnName, $originalRem, $preset);
}

function getTranslatedFinding($name, $severity, $defaultBg, $defaultRem) {
    global $VULN_TRANSLATION, $SEVERITY_MAP, $GENERATOR_CONFIG;
    $nameClean = trim(preg_replace('/\s+/', ' ', $name));
    $normalizedName = strtolower($nameClean);

    $idName = '';
    $idDetail = '';
    $idRemediation = '';

    // === Level 1: Kamus lokal (tercepat, paling akurat) ===
    if (isset($VULN_TRANSLATION[$normalizedName])) {
        $t = $VULN_TRANSLATION[$normalizedName];
        $idName = $t['name'];
        $idDetail = $t['detail'];
        $idRemediation = $t['remediation'];
    } else {
        $matched = false;
        foreach ($VULN_TRANSLATION as $k => $t) {
            if (strpos($normalizedName, $k) !== false) {
                $idName = $t['name'];
                $idDetail = $t['detail'];
                $idRemediation = $t['remediation'];
                $matched = true;
                break;
            }
        }
        
        if (!$matched) {
            $idSev  = isset($SEVERITY_MAP[$severity]) ? $SEVERITY_MAP[$severity] : $severity;
            $idName = "$nameClean ($idSev)";
            $cleanedBg  = cleanHtml($defaultBg);
            $cleanedRem = cleanHtml($defaultRem);

            // === Level 2: Terjemahan frasa parsial (offline, tidak butuh internet) ===
            $partialBg  = translateBurpText($cleanedBg);
            $partialRem = translateBurpText($cleanedRem);

            $idDetail = $partialBg;
            $idRemediation = $partialRem;

            // === Level 3: Google Translate via library (online, paling akurat) ===
            if (GOOGLE_TRANSLATE_AVAILABLE) {
                if (!empty(trim($cleanedBg))) {
                    $googleBg = translateWithGoogle($cleanedBg);
                    if (!empty($googleBg) && $googleBg !== $cleanedBg) {
                        $idDetail = $googleBg;
                    }
                }
                if (!empty(trim($cleanedRem))) {
                    $googleRem = translateWithGoogle($cleanedRem);
                    if (!empty($googleRem) && $googleRem !== $cleanedRem) {
                        $idRemediation = $googleRem;
                    }
                }
            }
        }
    }


    // === Level 4: Default fallback jika semua kosong ===
    if (empty(trim($idDetail))) {
        $idDetail = "Burp Suite mendeteksi kerentanan \"$nameClean\" pada aplikasi. "
                 . "Kerentanan ini berpotensi dieksploitasi oleh penyerang untuk mengakses atau memanipulasi data pada sistem.";
    }
    if (empty(trim($idRemediation))) {
        $idRemediation = "Terapkan praktik pengkodean aman sesuai panduan OWASP dan ikuti rekomendasi keamanan "
                  . "standar untuk jenis kerentanan ini. Konsultasikan dengan tim keamanan untuk penanganan lebih lanjut.";
    }

    return [$idName, $idDetail, $idRemediation];
}

function getScoreLabel($score) {
    if ($score >= 80) {
        return "Baik";
    } elseif ($score >= 70) {
        return "Cukup";
    } else {
        return "Kurang";
    }
}

function generateReport($xmlPath, $templatePath, $outputPath, $opdName, $kepalaOpd, $customAppName = null, $nomorSurat = "000/1234/DKISP", $useAi = false, $aiProvider = 'groq', $aiPreset = 'php', $customApiKey = null, $aiModel = null) {
    global $GENERATOR_CONFIG;
    $GENERATOR_CONFIG = [
        'use_ai' => $useAi,
        'ai_provider' => $aiProvider,
        'ai_preset' => $aiPreset,
        'custom_api_key' => $customApiKey,
        'ai_model' => $aiModel
    ];
    
    $ext = strtolower(pathinfo($xmlPath, PATHINFO_EXTENSION));
    if ($ext === 'html' || $ext === 'htm') {
        $parsed = parseHtmlReport($xmlPath);
    } else {
        $parsed = parseXmlReport($xmlPath);
    }
    $findings = $parsed['findings'];
    $targetUrl = $parsed['target_url'];
    $targetIp = $parsed['target_ip'];
    $scanDate = $parsed['scan_date'];
    $htmlAppName = $parsed['html_app_name'];
    $rawCounts = $parsed['raw_counts'];
    
    // Overwrite findings dengan hasil AI dan generate ringkasan jika mode AI aktif
    $aiRecommendationsList = [];
    $aiConclusionsList = [];
    
    if ($useAi && !empty($findings)) {
        if (php_sapi_name() === 'cli') {
            echo "[AI] Menjalankan analisis batch untuk " . count($findings) . " temuan...\n";
        }
        
        // 1. Run batch AI analysis for all findings in a single call
        $batchAiResults = getBatchAIVulnAnalysis($findings, $aiPreset, $aiProvider);
        
        // 2. Loop and overwrite details and remediations in findings array
        foreach ($findings as &$f) {
            $nameClean = preg_replace('/\s*\[CWE-\d+\]$/i', '', $f['name']);
            $nameClean = preg_replace('/\s*\((?:Tinggi|Sedang|Rendah|Informasi|Kritis)[^)]*\(?[^)]*\)?\)\s*$/', '', $nameClean);
            $nameKey = strtolower(trim($nameClean));
            
            if (isset($batchAiResults[$nameKey])) {
                if (!empty($batchAiResults[$nameKey]['dampak'])) {
                    $f['detail'] = $batchAiResults[$nameKey]['dampak'];
                    $f['background'] = $batchAiResults[$nameKey]['dampak'];
                }
                if (!empty($batchAiResults[$nameKey]['rekomendasi'])) {
                    $f['remediation'] = $batchAiResults[$nameKey]['rekomendasi'];
                }
            }
        }
        unset($f); // break reference
        
        // 3. Generate AI Summary for Conclusions list
        $aiConclusionsList = getSummaryAIAnalysis($findings, $aiProvider);
    }
    
    // Update global config with summaries
    $GENERATOR_CONFIG['ai_recommendations'] = [];
    $GENERATOR_CONFIG['ai_conclusions'] = $aiConclusionsList;
    
    echo "Parsed raw findings from file:\n";
    foreach ($findings as $f) {
        echo "  Finding Name: " . $f['name'] . " | Severity: " . $f['severity'] . "\n";
    }
    
    // Detect app name from filename as a smart fallback
    $detectedAppName = "Aplikasi Sistem Informasi";
    $basename = basename($xmlPath);
    $basename_lower = strtolower($basename);
    if (strpos($basename_lower, 'bcc') !== false || strpos($basename_lower, 'career') !== false) {
        $detectedAppName = "Bogor Career Center";
    } elseif (strpos($basename_lower, 'bakesbangpol') !== false || strpos($basename_lower, 'kesbangpol') !== false) {
        $detectedAppName = "Pelayanan Online Bakesbangpol";
    } elseif (strpos($basename_lower, 'disnaker') !== false) {
        $detectedAppName = "Dinas Tenaga Kerja";
    } else {
        // Clean filename: remove extension, dates, scan/pentest keywords
        $cleaned = pathinfo($basename, PATHINFO_FILENAME);
        // Remove date like "10 agustus 2026" or "10-08-2026" or "2026-08-10"
        $cleaned = preg_replace('/(\d{1,2})\s+([a-zA-Z]+)\s+(\d{4})/i', '', $cleaned);
        $cleaned = preg_replace('/\d{4}[-._]\d{2}[-._]\d{2}/', '', $cleaned);
        $cleaned = preg_replace('/\d{2}[-._]\d{2}[-._]\d{4}/', '', $cleaned);
        $cleaned = preg_replace('/\b(scan|report|burp|pentest|hasil|pengujian|v3|v2|v1)\b/i', '', $cleaned);
        $cleaned = preg_replace('/[^a-zA-Z0-9\s-]/', ' ', $cleaned);
        $cleaned = preg_replace('/\s+/', ' ', $cleaned);
        $cleaned = trim($cleaned);
        if (!empty($cleaned)) {
            $detectedAppName = ucwords($cleaned);
        }
    }

    $appName = $customAppName ? $customAppName : ($htmlAppName ? $htmlAppName : $detectedAppName);
    
    // Normalize Bakesbangpol or Kesbangpol name
    if (stripos($appName, "Bakesbangpol") !== false || stripos($appName, "Kesbangpol") !== false) {
        $appName = "Pelayanan Online Bakesbangpol";
    }
    
    echo "Vulnerability count: " . count($findings) . " unique categories.\n";
    
    $critCount  = $rawCounts['Critical'];
    $highCount  = $rawCounts['High'];         // sudah termasuk Critical
    $highOnly   = $highCount - $critCount;    // High murni (tanpa Critical)
    $medCount   = $rawCounts['Medium'];
    $lowCount   = $rawCounts['Low'];
    $infoCount  = $rawCounts['Information'];
    
    $totalFindingsCount = $critCount + $highOnly + $medCount + $lowCount + $infoCount;
    $totalVulnsStr = "$totalFindingsCount Temuan ($highCount High, $medCount Medium, $lowCount Low, $infoCount Information)";
    
    // Calculate scores dynamically using deduction weights across all severities
    $scoreApp = max(40, 100 - ($highCount * 15) - ($medCount * 5) - ($lowCount * 1.5) - ($infoCount * 0.5));
    $scoreServer = max(45, 100 - ($highCount * 10) - ($medCount * 4) - ($lowCount * 2) - ($infoCount * 0.5));
    $scoreAvail = max(50, 100 - ($highCount * 8) - ($medCount * 3) - ($lowCount * 1) - ($infoCount * 0.2));
    $scoreInfra = max(50, 100 - ($highCount * 8) - ($medCount * 3) - ($lowCount * 1.5) - ($infoCount * 0.3));
    $scoreOps = max(60, 100 - ($highCount * 5) - ($medCount * 2) - ($lowCount * 1.5) - ($infoCount * 0.5));
    
    // Calculate aspect risk levels dynamically based on findings categorizations
    $aspectMaxSeverity = [
        'App' => 'Information',
        'Config' => 'Information',
        'Info' => 'Information',
        'Avail' => 'Information'
    ];
    
    $sevWeights = [
        'Critical' => 5,
        'High' => 4,
        'Medium' => 3,
        'Low' => 2,
        'Information' => 1
    ];
    
    foreach ($findings as $f) {
        $nameClean = strtolower(trim(preg_replace('/\s+/', ' ', $f['name'])));
        
        // Categorize finding to aspect
        $aspect = 'Config'; // default
        
        $appKeywords = [
            'injection', 'xss', 'cross-site scripting', 'upload', 'traversal', 
            'redirection', 'manipulation', 'password submitted', 'csrf', 'forgery'
        ];
        $infoKeywords = [
            'disclosed', 'disclosure', 'leakage', 'leak', 'backup', 'source code', 'version'
        ];
        $availKeywords = [
            'redirection response', 'slow', 'timeout', 'denial'
        ];
        
        foreach ($appKeywords as $kw) {
            if (strpos($nameClean, $kw) !== false) {
                $aspect = 'App';
                break;
            }
        }
        
        if ($aspect === 'Config') {
            foreach ($infoKeywords as $kw) {
                if (strpos($nameClean, $kw) !== false) {
                    $aspect = 'Info';
                    break;
                }
            }
        }
        
        if ($aspect === 'Config') {
            foreach ($availKeywords as $kw) {
                if (strpos($nameClean, $kw) !== false) {
                    $aspect = 'Avail';
                    break;
                }
            }
        }
        
        $currentSev = $f['severity'];
        $currentWeight = isset($sevWeights[$currentSev]) ? $sevWeights[$currentSev] : 1;
        
        $maxSev = $aspectMaxSeverity[$aspect];
        $maxWeight = isset($sevWeights[$maxSev]) ? $sevWeights[$maxSev] : 1;
        
        if ($currentWeight > $maxWeight) {
            $aspectMaxSeverity[$aspect] = $currentSev;
        }
    }
    
    $sevToRiskLabel = [
        'Critical' => 'Tinggi',
        'High' => 'Tinggi',
        'Medium' => 'Sedang',
        'Low' => 'Rendah',
        'Information' => 'Informasi'
    ];
    
    $riskAppLevel = isset($sevToRiskLabel[$aspectMaxSeverity['App']]) ? $sevToRiskLabel[$aspectMaxSeverity['App']] : 'Rendah';
    $riskConfigLevel = isset($sevToRiskLabel[$aspectMaxSeverity['Config']]) ? $sevToRiskLabel[$aspectMaxSeverity['Config']] : 'Rendah';
    $riskInfoLevel = isset($sevToRiskLabel[$aspectMaxSeverity['Info']]) ? $sevToRiskLabel[$aspectMaxSeverity['Info']] : 'Informasi';
    $riskAvailLevel = isset($sevToRiskLabel[$aspectMaxSeverity['Avail']]) ? $sevToRiskLabel[$aspectMaxSeverity['Avail']] : 'Rendah';
    
    if ($aspectMaxSeverity['App'] === 'Information') $riskAppLevel = 'Rendah';
    if ($aspectMaxSeverity['Config'] === 'Information') $riskConfigLevel = 'Rendah';
    if ($aspectMaxSeverity['Info'] === 'Information') $riskInfoLevel = 'Informasi';
    if ($aspectMaxSeverity['Avail'] === 'Information') $riskAvailLevel = 'Rendah';
    
    // ── Tingkat keamanan umum ─────────────────────────────────────────────
    if ($critCount > 0 || $highOnly > 0) {
        $tingkatKeamanan = 'Rendah';
    } elseif ($medCount > 0) {
        $tingkatKeamanan = 'Sedang';
    } else {
        $tingkatKeamanan = 'Baik';
    }

    // ── Threat Level: satu label tunggal berdasarkan severity tertinggi ──────
    if ($critCount > 0) {
        $threatLevel       = 'Kritis';
        $threatLevelNarasi = 'Kritis (Critical) dan Tinggi (High)';
    } elseif ($highOnly > 0) {
        $threatLevel       = 'Tinggi';
        $threatLevelNarasi = 'Tinggi (High)';
    } elseif ($medCount > 0) {
        $threatLevel       = 'Sedang';
        $threatLevelNarasi = 'Sedang (Medium)';
    } elseif ($lowCount > 0) {
        $threatLevel       = 'Rendah';
        $threatLevelNarasi = 'Rendah (Low)';
    } else {
        $threatLevel       = 'Informatif';
        $threatLevelNarasi = 'Informational';
    }

    // ── Status kelayakan ─────────────────────────────────────────────────
    if ($highCount > 0) {
        $statusKelayakan = "BELUM LAYAK UNTUK DIPUBLIKASIKAN";
        $layakShort      = "BELUM LAYAK";
        $kondisiUmum     = "Sistem masih memiliki $highCount temuan tingkat Tinggi (High) yang wajib diperbaiki sebelum publikasi.";

        $pStatus   = "Berdasarkan pengujian yang dilakukan, sistem saat ini dinyatakan BELUM LAYAK UNTUK DIPUBLIKASIKAN karena masih ditemukan celah keamanan kritis berisiko tinggi yang dapat membahayakan integritas data dan server.";
        $pHasil    = "Hasil pemeriksaan menunjukkan adanya kerentanan tingkat Tinggi (High) yang berpotensi dimanfaatkan oleh penyerang untuk melakukan tindakan tidak sah atau mengompromikan sistem secara keseluruhan. Perbaikan segera wajib dilakukan sebelum sistem dipublikasikan.";
        $pAnalisis = "Hasil analisis risiko menunjukkan sistem berada pada tingkat risiko Tinggi. Celah keamanan yang ditemukan mempermudah eksploitasi data sensitif atau pengambilalihan hak akses administrator.";

        // Sisa temuan yang tidak menghalangi setelah high diperbaiki
        $sisaTemuan = '';
        if ($medCount > 0 && $lowCount > 0 && $infoCount > 0) {
            $sisaTemuan = 'Medium, Low, dan Informational';
        } elseif ($medCount > 0 && $lowCount > 0) {
            $sisaTemuan = 'Medium dan Low';
        } elseif ($medCount > 0 && $infoCount > 0) {
            $sisaTemuan = 'Medium dan Informational';
        } elseif ($medCount > 0) {
            $sisaTemuan = 'Medium';
        } elseif ($lowCount > 0) {
            $sisaTemuan = 'Low dan Informational';
        } else {
            $sisaTemuan = 'Informational';
        }

        // Paragraf 2 — ada Critical/High yang menghalangi
        $para2Isi = "Hasil pemeriksaan menunjukkan bahwa secara umum sistem memiliki tingkat keamanan {$tingkatKeamanan}. "
                 . "Berdasarkan hasil analisis terhadap aspek keamanan aplikasi, konfigurasi, infrastruktur, ketersediaan layanan, dan kesiapan operasional, "
                 . "ditemukan kerentanan pada tingkat {$threatLevelNarasi} yang berpotensi menghambat operasional dan kelayakan sistem untuk digunakan serta dipublikasikan.";

        // Paragraf 4 — penutup untuk BELUM LAYAK
        // Berbeda substansial dari LAYAK: tegas bahwa publikasi HARUS ditunda
        // (bukan sekadar "catatan"), dan tidak ada kalimat "tidak menjadi penghalang"
        $para4Isi = "Status tersebut diberikan berdasarkan hasil pengujian keamanan pada saat pemeriksaan dilakukan, "
                 . "di mana ditemukan kerentanan dengan tingkat risiko {$threatLevelNarasi} yang wajib diselesaikan sebelum sistem diterbitkan kepada publik. "
                 . "Penerbitan sistem harus ditunda hingga seluruh temuan berisiko tinggi tersebut berhasil diperbaiki sepenuhnya. "
                 . "Selain itu, seluruh temuan lain yang teridentifikasi, termasuk temuan dengan tingkat risiko {$sisaTemuan}, "
                 . "direkomendasikan untuk turut ditindaklanjuti sebagai bagian dari upaya peningkatan keamanan sistem secara menyeluruh. "
                 . "Pengelola sistem juga diwajibkan untuk melaksanakan pemantauan keamanan secara berkala "
                 . "serta melakukan pengujian keamanan ulang setelah perbaikan dilakukan, terlebih apabila terdapat perubahan signifikan pada aplikasi, infrastruktur, atau konfigurasi sistem.";
    } else {
        $statusKelayakan = "LAYAK UNTUK DIPUBLIKASIKAN";
        $layakShort      = "LAYAK";
        $kondisiUmum     = "Sistem dalam kondisi aman untuk diakses publik. Tidak ditemukan kerentanan dengan tingkat risiko Tinggi (High).";

        $pStatus   = "Berdasarkan pengujian yang dilakukan, sistem dinilai memiliki postur keamanan yang baik dan LAYAK UNTUK DIPUBLIKASIKAN untuk mendukung pelayanan publik.";
        $pHasil    = "Hasil pemeriksaan menunjukkan bahwa tidak ditemukan kerentanan dengan tingkat risiko Kritis (Critical) maupun Tinggi (High). Beberapa kerentanan tingkat Menengah (Medium) dan Rendah (Low) yang ditemukan tidak menghalangi kelayakan sistem, namun disarankan untuk diperbaiki guna memperkuat sistem.";
        $pAnalisis = "Hasil analisis risiko menunjukkan sistem berada pada tingkat risiko Rendah hingga Sedang secara keseluruhan. Konfigurasi pertahanan bawaan sistem berjalan efektif menghadapi skenario pengujian.";

        // Sisa temuan non-critical/high
        $sisaTemuan = '';
        if ($medCount > 0 && $lowCount > 0 && $infoCount > 0) {
            $sisaTemuan = 'Medium, Low, dan Informational';
        } elseif ($medCount > 0 && $lowCount > 0) {
            $sisaTemuan = 'Medium dan Low';
        } elseif ($medCount > 0 && $infoCount > 0) {
            $sisaTemuan = 'Medium dan Informational';
        } elseif ($medCount > 0) {
            $sisaTemuan = 'Medium';
        } elseif ($lowCount > 0 && $infoCount > 0) {
            $sisaTemuan = 'Low dan Informational';
        } elseif ($lowCount > 0) {
            $sisaTemuan = 'Low';
        } else {
            $sisaTemuan = 'Informational';
        }

        // Paragraf 2 — tidak ada Critical/High
        $para2Isi = "Hasil pemeriksaan menunjukkan bahwa secara umum sistem memiliki tingkat keamanan {$tingkatKeamanan}. "
                 . "Berdasarkan hasil analisis terhadap aspek keamanan aplikasi, konfigurasi, infrastruktur, ketersediaan layanan, dan kesiapan operasional, "
                 . "tidak ditemukan kerentanan pada tingkat Critical maupun High yang berpotensi menghambat operasional atau kelayakan sistem untuk digunakan dan dipublikasikan.";

        // Paragraf 4 — penutup untuk LAYAK
        $para4Isi = "Status tersebut diberikan dengan mempertimbangkan hasil pengujian keamanan pada saat pemeriksaan dilakukan. "
                 . "Temuan dengan tingkat risiko {$sisaTemuan} yang masih terdapat pada sistem tidak menjadi penghalang terhadap kelayakan publikasi, "
                 . "namun tetap direkomendasikan untuk ditindaklanjuti sebagai bagian dari upaya peningkatan keamanan sistem secara berkelanjutan. "
                 . "Dengan demikian, {$appName} dinyatakan LAYAK untuk dipublikasikan, dengan catatan bahwa pengelola sistem tetap perlu melakukan perbaikan terhadap temuan yang telah diidentifikasi, "
                 . "melaksanakan pemantauan keamanan secara berkala, serta melakukan pengujian keamanan ulang apabila terdapat perubahan signifikan pada aplikasi, infrastruktur, atau konfigurasi sistem.";
    }
    
    $skorAkhir = round(($scoreApp + $scoreServer + $scoreAvail + $scoreInfra + $scoreOps) / 5);
    
    // Group finding names by aspect for detailed descriptions
    $aspectFindingNames = [
        'App' => [],
        'Config' => [],
        'Info' => [],
        'Avail' => []
    ];
    
    foreach ($findings as $f) {
        $nameClean = strtolower(trim(preg_replace('/\s+/', ' ', $f['name'])));
        $aspect = 'Config'; // default
        
        $appKeywords = [
            'injection', 'xss', 'cross-site scripting', 'upload', 'traversal', 
            'redirection', 'manipulation', 'password submitted', 'csrf', 'forgery'
        ];
        $infoKeywords = [
            'disclosed', 'disclosure', 'leakage', 'leak', 'backup', 'source code', 'version'
        ];
        $availKeywords = [
            'redirection response', 'slow', 'timeout', 'denial'
        ];
        
        foreach ($appKeywords as $kw) {
            if (strpos($nameClean, $kw) !== false) {
                $aspect = 'App';
                break;
            }
        }
        if ($aspect === 'Config') {
            foreach ($infoKeywords as $kw) {
                if (strpos($nameClean, $kw) !== false) {
                    $aspect = 'Info';
                    break;
                }
            }
        }
        if ($aspect === 'Config') {
            foreach ($availKeywords as $kw) {
                if (strpos($nameClean, $kw) !== false) {
                    $aspect = 'Avail';
                    break;
                }
            }
        }
        
        // Strip CWE block jika ada: [CWE-xxx]
        $cleanDisplayName = preg_replace('/\s*\[CWE-\d+\]$/i', '', $f['name']);
        // Strip suffix severity Indonesia: (Tinggi (High)), (Sedang (Medium)), (Rendah (Low)), (Informasi (Information)), (Kritis (Critical))
        $cleanDisplayName = preg_replace('/\s*\((?:Tinggi|Sedang|Rendah|Informasi|Kritis)[^)]*\(?[^)]*\)?\)\s*$/', '', $cleanDisplayName);
        // Strip bagian bahasa Inggris dalam tanda kurung di akhir nama: "Nama Indonesia (English Name)"
        // Hanya strip jika sebelumnya sudah ada nama Indonesia (ada kata huruf besar selain kata pertama)
        $cleanDisplayName = preg_replace('/\s*\([A-Z][^)]{3,}\)$/', '', $cleanDisplayName);
        $cleanDisplayName = trim($cleanDisplayName);

        if (!in_array($cleanDisplayName, $aspectFindingNames[$aspect])) {
            $aspectFindingNames[$aspect][] = $cleanDisplayName;
        }
    }
    
    // Generate dynamic descriptions — format dengan bullet list per baris
    function buildRiskText($level, $names, $emptyMsg, $suffix) {
        if (empty($names)) {
            return $emptyMsg;
        }
        $bullet = "\xE2\x80\xA2"; // karakter bullet • dalam UTF-8
        $bullets = array_map(function($n) use ($bullet) { return $bullet . " " . $n; }, $names);
        return "Ditemukan celah keamanan tingkat " . $level . " " . $suffix . ":\n"
             . implode("\n", $bullets);
    }
    
    $riskApp = buildRiskText(
        $riskAppLevel,
        $aspectFindingNames['App'],
        "Tidak ditemukan celah keamanan pada tingkat logika aplikasi atau transmisi data.",
        "pada tingkat logika aplikasi atau transmisi data"
    );
    
    $riskConfig = buildRiskText(
        $riskConfigLevel,
        $aspectFindingNames['Config'],
        "Tidak ditemukan celah keamanan pada konfigurasi SSL/TLS server, header HTTP keamanan, atau cache browser.",
        "pada konfigurasi SSL/TLS, header HTTP keamanan, atau cache browser"
    );
    
    $riskInfo = buildRiskText(
        $riskInfoLevel,
        $aspectFindingNames['Info'],
        "Tidak ditemukan celah keamanan berupa kebocoran detail server atau konfigurasi informasional.",
        "berupa kebocoran informasi atau konfigurasi server"
    );
    
    $riskAvail = buildRiskText(
        $riskAvailLevel,
        $aspectFindingNames['Avail'],
        "Stabilitas respon aplikasi berjalan normal dan aman dari potensi gangguan ketersediaan.",
        "yang berpotensi mempengaruhi stabilitas ketersediaan layanan"
    );
    
    $scoreAppLabel = getScoreLabel($scoreApp);
    $scoreServerLabel = getScoreLabel($scoreServer);
    $scoreAvailLabel = getScoreLabel($scoreAvail);
    $scoreInfraLabel = getScoreLabel($scoreInfra);
    $scoreOpsLabel = getScoreLabel($scoreOps);
    
    $scoreAppDesc = $scoreApp < 70 ? "Terdapat celah keamanan tinggi wajib perbaikan." : ($scoreApp < 80 ? "Keamanan aplikasi cukup baik dengan temuan menengah." : "Keamanan aplikasi sangat baik, bebas temuan tinggi.");
    $scoreServerDesc = $scoreServer < 70 ? "Konfigurasi server rentan." : ($scoreServer < 80 ? "Konfigurasi server cukup baik dengan temuan minor." : "Konfigurasi server aman dan stabil.");
    $scoreAvailDesc = $scoreAvail >= 80 ? "Layanan merespon normal dan stabil." : "Layanan rentan mengalami penurunan respon.";
    $scoreInfraDesc = $scoreInfra >= 80 ? "Infrastruktur host dinilai aman." : "Infrastruktur memerlukan hardening.";
    $scoreOpsDesc = $scoreOps >= 80 ? "Sistem siap dioperasikan penuh." : "Sistem perlu perbaikan operasional.";
    
    list($dayName, $dateStr) = getIndonesianDate();
    
    // ── Bangun 4 paragraf kesimpulan terstruktur ─────────────────────────
    $para1Isi = "Berdasarkan hasil pemeriksaan keamanan (penetration testing) terhadap {$appName} yang diakses melalui {$targetUrl}, "
              . "diperoleh hasil pengujian sebanyak {$totalFindingsCount} temuan, yang terdiri atas "
              . "{$critCount} Critical, {$highOnly} High, {$medCount} Medium, {$lowCount} Low, dan {$infoCount} Informational.";

    $para3Isi = "Berdasarkan hasil penilaian kelayakan, {$appName} memperoleh nilai sebesar {$skorAkhir}/100 dan dinyatakan:";

    $replacements = [
        '{{KEPALA_OPD}}' => $kepalaOpd,
        '{{OPD}}' => $opdName,
        '{{NAMA_APLIKASI}}' => $appName,
        '{{TARGET_URL}}' => $targetUrl,
        '{{TARGET_IP}}' => $targetIp,
        '{{TANGGAL_SCAN}}' => $scanDate,
        '{{PARAGRAF_STATUS_KELAYAKAN}}' => $pStatus,
        '{{NOMOR_SURAT}}' => normalizeNomorSuratForTemplate($nomorSurat),
        '{{TANGGAL_SURAT}}' => $dateStr,
        '{{HARI}}' => $dayName,
        '{{STATUS_KELAYAKAN}}' => $statusKelayakan,
        '{{PARAGRAF_HASIL_PEMERIKSAAN}}' => $pHasil,
        '{{PARAGRAF_ANALISIS}}' => $pAnalisis,
        '{{SKOR_AKHIR}}' => (string)$skorAkhir,
        // Kesimpulan 4 paragraf terstruktur (menggantikan KESIMPULAN_ISI & KESIMPULAN_AKHIR)
        '{{KESIMPULAN_PARA1}}' => $para1Isi,
        '{{KESIMPULAN_PARA2}}' => $para2Isi,
        '{{KESIMPULAN_PARA3}}' => $para3Isi,
        '{{KESIMPULAN_PARA4}}' => $para4Isi,
        // Tetap untuk kompatibilitas placeholder lain di template
        '{{PARAGRAF_KESIMPULAN_TEMUAN}}' => "ditemukan total $totalFindingsCount celah keamanan.",
        '{{KESIMPULAN_KELAYAKAN}}' => $highCount > 0
            ? "Sistem dinyatakan BELUM LAYAK UNTUK DIPUBLIKASIKAN. Penerbitan layanan wajib ditunda sampai seluruh temuan berisiko tinggi berhasil diperbaiki sepenuhnya."
            : "Sistem dinyatakan LAYAK UNTUK DIPUBLIKASIKAN. Pengelola sistem direkomendasikan untuk tetap menindaklanjuti seluruh temuan yang telah teridentifikasi.",
        
        // Table 2
        '{{THREAT_LEVEL}}' => $threatLevel,
        '{{TOTAL_TEMUAN}}' => $totalVulnsStr,
        '{{KONDISI_UMUM}}' => $kondisiUmum,
        
        // Table 4
        '{{RISK_APP}}' => $riskApp,
        '{{RISK_APP_LEVEL}}' => $riskAppLevel,
        '{{RISK_CONFIG}}' => $riskConfig,
        '{{RISK_CONFIG_LEVEL}}' => $riskConfigLevel,
        '{{RISK_INFO}}' => $riskInfo,
        '{{RISK_INFO_LEVEL}}' => $riskInfoLevel,
        '{{RISK_AVAIL}}' => $riskAvail,
        '{{RISK_AVAIL_LEVEL}}' => $riskAvailLevel,
        
        // Table 5
        '{{SCORE_APP}}' => (string)$scoreApp,
        '{{SCORE_APP_LABEL}}' => $scoreAppLabel,
        '{{SCORE_APP_DESC}}' => $scoreAppDesc,
        '{{SCORE_SERVER}}' => (string)$scoreServer,
        '{{SCORE_SERVER_LABEL}}' => $scoreServerLabel,
        '{{SCORE_SERVER_DESC}}' => $scoreServerDesc,
        '{{SCORE_AVAIL}}' => (string)$scoreAvail,
        '{{SCORE_AVAIL_LABEL}}' => $scoreAvailLabel,
        '{{SCORE_AVAIL_DESC}}' => $scoreAvailDesc,
        '{{SCORE_INFRA}}' => (string)$scoreInfra,
        '{{SCORE_INFRA_LABEL}}' => $scoreInfraLabel,
        '{{SCORE_INFRA_DESC}}' => $scoreInfraDesc,
        '{{SCORE_OPS}}' => (string)$scoreOps,
        '{{SCORE_OPS_LABEL}}' => $scoreOpsLabel,
        '{{SCORE_OPS_DESC}}' => $scoreOpsDesc,
    ];
    
    echo "Opening template: $templatePath\n";
    if (!copy($templatePath, $outputPath)) {
        throw new Exception("Gagal membuat berkas laporan output di $outputPath.");
    }
    
    $zip = new ZipArchive();
    $res = $zip->open($outputPath);
    if ($res !== TRUE) {
        throw new Exception("Gagal membuka berkas ZIP Docx di $outputPath (Kode Error: $res).");
    }
    
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entryName = $zip->getNameIndex($i);
        if ($entryName === 'word/document.xml' || preg_match('/^word\/(header|footer)\d+\.xml$/', $entryName)) {
            $xmlContent = $zip->getFromIndex($i);
            
            $dom = new DOMDocument();
            $dom->preserveWhiteSpace = true;
            $dom->loadXML($xmlContent);
            
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            
            if ($entryName === 'word/document.xml') {
                $bodyParagraphs = $xpath->query('//w:body/w:p | //w:body/*[not(self::w:tbl)]//w:p[not(ancestor::w:tbl)]');
                foreach ($bodyParagraphs as $pNode) {
                    replaceAllPlaceholdersInParagraph($pNode, $replacements, $findings, $highCount, $xpath);
                }
                
                $tables = $xpath->query('//w:tbl');
                foreach ($tables as $tableNode) {
                    $isFindingsTable = false;
                    $cellParagraphs = $xpath->query('.//w:p', $tableNode);
                    foreach ($cellParagraphs as $pNode) {
                        if (strpos(getParagraphText($pNode), '{{FINDING_NAME}}') !== false) {
                            $isFindingsTable = true;
                            break;
                        }
                    }
                    
                    if ($isFindingsTable) {
                        echo "Populating findings table (detected dynamically via placeholder)...\n";
                        $rows = $xpath->query('w:tr', $tableNode);
                        if ($rows->length > 1) {
                            $templateRow = $rows->item(1);
                            
                            // Fixed column widths (in twips): No=400, Jenis Kerentanan=1800, Kategori Risiko=1200, Dampak=5600
                            $colWidths = [400, 1800, 1200, 5600];
                            
                            foreach ($findings as $f_idx => $f) {
                                $newRow = $templateRow->cloneNode(true);
                                $templateRow->parentNode->insertBefore($newRow, $templateRow);
                                
                                // Enforce fixed column widths on every data row
                                setRowColumnWidths($newRow, $colWidths, $dom);
                                
                                $sevText = $f['severity_id'];
                                if (!empty($f['cvss'])) {
                                    $sevText .= "\n(CVSS: " . $f['cvss'] . ")";
                                }
                                
                                $findingReps = [
                                    '{{NO}}' => (string)($f_idx + 1),
                                    '{{FINDING_NAME}}' => $f['name'],
                                    '{{FINDING_PATH}}' => $f['paths_str'],
                                    '{{FINDING_SEVERITY}}' => $sevText,
                                    '{{FINDING_DETAIL}}' => $f['detail'],
                                    '{{FINDING_REMEDIATION}}' => $f['remediation']
                                ];
                                
                                $rowParagraphs = $xpath->query('.//w:p', $newRow);
                                foreach ($rowParagraphs as $pNode) {
                                    replaceAndBoldPlaceholdersInParagraph($pNode, $findingReps, $xpath);
                                }
                            }
                            
                            $templateRow->parentNode->removeChild($templateRow);
                        }
                    } else {
                        foreach ($cellParagraphs as $pNode) {
                            replaceAllPlaceholdersInParagraph($pNode, $replacements, $findings, $highCount, $xpath);
                        }
                    }
                }
            } else {
                $allParagraphs = $xpath->query('//w:p');
                foreach ($allParagraphs as $pNode) {
                    replaceAllPlaceholdersInParagraph($pNode, $replacements, $findings, $highCount, $xpath);
                }
            }
            
            $modifiedXml = $dom->saveXML();
            $zip->addFromString($entryName, $modifiedXml);
        }
    }
    
    $zip->close();
    echo "Report generated successfully at $outputPath!\n";
    return $appName;
}

// CLI Mode execution
if (php_sapi_name() === 'cli' && isset($_SERVER['PHP_SELF']) && basename($_SERVER['PHP_SELF']) === 'generate_report.php') {
    $options = getopt("", ["xml:", "template:", "output:", "opd:", "kepala:", "app::", "nomor:"]);
    
    $xml = isset($options['xml']) ? $options['xml'] : "bakesbangpol 10 agustus 2026.xml";
    $template = isset($options['template']) ? $options['template'] : "template.docx";
    $output = isset($options['output']) ? $options['output'] : "output_report.docx";
    $opd = isset($options['opd']) ? $options['opd'] : "Badan Kesatuan Bangsa dan Politik";
    $kepala = isset($options['kepala']) ? $options['kepala'] : "Kepala Badan Kesatuan Bangsa dan Politik";
    $app = isset($options['app']) ? $options['app'] : null;
    $nomor = isset($options['nomor']) ? $options['nomor'] : "1421";
    
    if (!file_exists($xml)) {
        echo "Error: XML file '$xml' not found.\n";
        exit(1);
    }
    if (!file_exists($template)) {
        echo "Error: Template file '$template' not found.\n";
        exit(1);
    }
    
    try {
        generateReport($xml, $template, $output, $opd, $kepala, $app, $nomor);
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
        exit(1);
    }
}

/**
 * ── SQLITE CACHE DB WRAPPER ──────────────────────────────────────────
 */
function getCacheDb() {
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    
    // Cek ketersediaan driver SQLite secara aman
    if (!class_exists('PDO') || !in_array('sqlite', PDO::getAvailableDrivers())) {
        return null;
    }
    
    $cacheDir = __DIR__ . '/data/cache';
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0777, true);
    }
    $dbPath = $cacheDir . '/cache.sqlite';
    $dbExists = file_exists($dbPath);
    
    try {
        $db = new PDO("sqlite:" . $dbPath);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        $db->exec("CREATE TABLE IF NOT EXISTS translate_cache (
            cache_key TEXT PRIMARY KEY,
            original_text TEXT,
            translated_text TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        $db->exec("CREATE TABLE IF NOT EXISTS ai_cache (
            cache_key TEXT PRIMARY KEY,
            vuln_name TEXT,
            preset TEXT,
            dampak TEXT,
            rekomendasi TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        if (!$dbExists) {
            migrateOldJsonCaches($db, $cacheDir);
        }
    } catch (Exception $e) {
        $db = null;
    }
    
    return $db;
}

function migrateOldJsonCaches($db, $cacheDir) {
    if (!$db) return;
    
    // Migrasi translate_cache
    $trFile = $cacheDir . '/translate_cache.json';
    if (file_exists($trFile)) {
        $raw = @file_get_contents($trFile);
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO translate_cache (cache_key, original_text, translated_text) VALUES (:key, :original, :translated)");
            foreach ($data as $key => $translated) {
                $stmt->execute([
                    ':key' => $key,
                    ':original' => '',
                    ':translated' => $translated
                ]);
            }
        }
        @unlink($trFile);
    }
    
    // Migrasi ai_cache
    $aiFile = $cacheDir . '/ai_cache.json';
    if (file_exists($aiFile)) {
        $raw = @file_get_contents($aiFile);
        $data = json_decode($raw, true);
        if (is_array($data)) {
            $stmt = $db->prepare("INSERT OR IGNORE INTO ai_cache (cache_key, vuln_name, preset, dampak, rekomendasi) VALUES (:key, :name, :preset, :dampak, :rekomendasi)");
            foreach ($data as $key => $val) {
                $parts = explode('_', $key);
                $preset = array_pop($parts);
                $name = implode('_', $parts);
                if (empty($name)) {
                    $name = $key;
                    $preset = 'php';
                }
                
                $stmt->execute([
                    ':key' => $key,
                    ':name' => $name,
                    ':preset' => $preset,
                    ':dampak' => isset($val['dampak']) ? $val['dampak'] : '',
                    ':rekomendasi' => isset($val['rekomendasi']) ? $val['rekomendasi'] : ''
                ]);
            }
        }
        @unlink($aiFile);
    }
}
