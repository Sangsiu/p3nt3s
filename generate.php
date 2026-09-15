<?php
session_start();

// Optimasi resource limit untuk file laporan berukuran besar
@ini_set('memory_limit', '512M');
@set_time_limit(180);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

require_once 'generate_report.php';

// 1. Validation
if (!isset($_FILES['xml_file']) || $_FILES['xml_file']['error'] === UPLOAD_ERR_NO_FILE) {
    $_SESSION['flash_error'] = "File XML/HTML scanner hasil pentest wajib diunggah!";
    header('Location: index.php');
    exit;
}

if ($_FILES['xml_file']['size'] === 0) {
    $_SESSION['flash_error'] = "File yang diunggah kosong atau berukuran 0 bytes!";
    header('Location: index.php');
    exit;
}

if ($_FILES['xml_file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash_error'] = "Terjadi kesalahan saat mengunggah file. Kode error: " . $_FILES['xml_file']['error'];
    header('Location: index.php');
    exit;
}

// Check template existence
$template_path = "template.docx";
if (!file_exists($template_path)) {
    $_SESSION['flash_error'] = "File 'template.docx' tidak ditemukan di folder aplikasi. Pastikan template.docx sudah diletakkan di folder yang sama dengan index.php.";
    header('Location: index.php');
    exit;
}

$xml_file = $_FILES['xml_file'];
$xml_filename = $xml_file['name'];
$xml_tmp_name = $xml_file['tmp_name'];

$opd_name = isset($_POST['opd_name']) ? trim($_POST['opd_name']) : '';
$nomor_surat = isset($_POST['nomor_surat']) ? trim($_POST['nomor_surat']) : '';
$app_name = isset($_POST['app_name']) && trim($_POST['app_name']) !== '' ? trim($_POST['app_name']) : null;

// AI Enhancement options (saling mengecualikan secara eksklusif)
$use_custom_api = isset($_POST['use_custom_api']) && $_POST['use_custom_api'] === '1';
$use_ai_default = isset($_POST['use_ai']) && $_POST['use_ai'] === '1';

$use_ai = false;
$ai_provider = 'groq';
$ai_preset = 'php';
$custom_api_key = null;
$ai_model = null;

if ($use_custom_api || $use_ai_default) {
    $use_ai = true;
    $ai_provider = isset($_POST['ai_provider']) ? trim($_POST['ai_provider']) : 'groq';
    $ai_preset = isset($_POST['ai_preset']) ? trim($_POST['ai_preset']) : 'php';
    
    $ai_model_val = isset($_POST['ai_model']) ? trim($_POST['ai_model']) : '';
    $custom_model = isset($_POST['custom_model']) ? trim($_POST['custom_model']) : '';
    $ai_model = ($ai_model_val === 'custom' && !empty($custom_model)) ? $custom_model : $ai_model_val;

    if ($use_custom_api) {
        $custom_api_key = isset($_POST['custom_api_key']) ? trim($_POST['custom_api_key']) : '';
        if (empty($custom_api_key)) {
            $_SESSION['flash_error'] = "Custom API Key wajib diisi jika Anda memilih mode Kustom API!";
            header('Location: index.php');
            exit;
        }
    } else {
        // Validasi ketersediaan key bawaan server
        $key_const = 'AI_KEY_' . strtoupper($ai_provider);
        if (!defined($key_const) || trim(constant($key_const)) === '') {
            $_SESSION['flash_error'] = "API Key bawaan untuk " . ucfirst($ai_provider) . " belum dikonfigurasi di server. Silakan gunakan mode Kustom API atau pilih provider lain!";
            header('Location: index.php');
            exit;
        }
    }
}

// 2. Intelligent Default Detection
$filename_lower = strtolower($xml_filename);

$detected_opd = "Badan Kesatuan Bangsa dan Politik";
$detected_kepala = "Kepala Badan Kesatuan Bangsa dan Politik";
$detected_nomor = "1421";

if (strpos($filename_lower, 'bcc') !== false || strpos($filename_lower, 'disnaker') !== false || strpos($filename_lower, 'career') !== false) {
    $detected_opd = "Dinas Tenaga Kerja";
    $detected_kepala = "Kepala Dinas Tenaga Kerja";
    $detected_nomor = "1422";
} elseif (strpos($filename_lower, 'bakesbangpol') !== false || strpos($filename_lower, 'kesbangpol') !== false) {
    $detected_opd = "Badan Kesatuan Bangsa dan Politik";
    $detected_kepala = "Kepala Badan Kesatuan Bangsa dan Politik";
    $detected_nomor = "1421";
}

// Apply user overrides or defaults
$final_opd = !empty($opd_name) ? $opd_name : $detected_opd;

// Derive kepala title based on OPD name
if (!empty($opd_name)) {
    if (stripos($opd_name, 'kepala') !== 0) {
        $final_kepala = "Kepala " . $opd_name;
    } else {
        $final_kepala = $opd_name;
        $final_opd = trim(preg_replace('/^[kK]epala\s+/i', '', $opd_name));
    }
} else {
    $final_kepala = $detected_kepala;
}

$final_nomor = !empty($nomor_surat) ? $nomor_surat : $detected_nomor;

// Create temporary directory to safely handle file generation
$unique_id = uniqid('pentest_', true);
$temp_folder = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $unique_id;

if (!mkdir($temp_folder, 0777, true)) {
    $_SESSION['flash_error'] = "Gagal membuat direktori sementara.";
    header('Location: index.php');
    exit;
}

// Save XML file with original filename to preserve scan date in filename
$xml_path = $temp_folder . DIRECTORY_SEPARATOR . $xml_filename;
if (!move_uploaded_file($xml_tmp_name, $xml_path)) {
    $_SESSION['flash_error'] = "Gagal memindahkan file upload.";
    @rmdir($temp_folder);
    header('Location: index.php');
    exit;
}

$output_path = $temp_folder . DIRECTORY_SEPARATOR . "Laporan_Pentest_Generated.docx";

try {
    @file_put_contents(__DIR__ . '/data/logs/generate.log', date('Y-m-d H:i:s') . " OPD:$final_opd | Kepala:$final_kepala | App:" . ($app_name ?: 'null') . " | Nomor:$final_nomor | File:$xml_filename\n", FILE_APPEND);
    while (ob_get_level() > 0) @ob_end_clean();
    ob_start();
    $actual_app_name = generateReport($xml_path, $template_path, $output_path, $final_opd, $final_kepala, $app_name, $final_nomor, $use_ai, $ai_provider, $ai_preset, $custom_api_key, $ai_model);
    $genLog = ob_get_clean();
    if ($genLog !== '' && trim($genLog) !== '') @file_put_contents(__DIR__ . '/data/logs/generate.log', $genLog . "\n", FILE_APPEND);
    
    if (!file_exists($output_path) || filesize($output_path) < 1024) throw new Exception("File hasil laporan tidak terbuat.");
    if (class_exists('ZipArchive')) {
        $docxZip = new ZipArchive();
        if ($docxZip->open($output_path) !== true || $docxZip->locateName('[Content_Types].xml') === false) {
            if ($docxZip->status === ZipArchive::ER_OK) $docxZip->close();
            throw new Exception('File DOCX rusak setelah proses AI. Cek API key/provider dan log server.');
        }
        $docxZip->close();
    }
    $clean_app_name = $actual_app_name ?: "Aplikasi Sistem Informasi";
    $app_name_upper = strtoupper(trim($clean_app_name));
    $app_name_clean = preg_replace('/[^A-Z0-9_]/', '_', str_replace(' ', '_', $app_name_upper));
    $app_name_clean = preg_replace('/_+/', '_', $app_name_clean);
    $app_name_clean = trim($app_name_clean, '_');
    if ($app_name_clean === '') $app_name_clean = 'APLIKASI_SISTEM_INFORMASI';
    list($day_name, $date_str) = getIndonesianDate();
    $today_date_upper = strtoupper(str_replace(' ', '_', $date_str));
    $download_name = "SANDI_REKOMANDASI_" . $app_name_clean . "_" . $today_date_upper . ".docx";
    while (ob_get_level() > 0) @ob_end_clean();
    if (isset($_POST['download_token'])) setcookie('download_token', $_POST['download_token'], time() + 300, '/', '', false, false);
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($output_path));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('X-Content-Type-Options: nosniff');
    readfile($output_path);
    @flush();
    @unlink($xml_path);
    @unlink($output_path);
    @rmdir($temp_folder);
    exit;
} catch (Exception $e) {
    while (ob_get_level() > 0) @ob_end_clean();
    if (!headers_sent() && !empty($output_path) && file_exists($output_path)) {
        @unlink($output_path);
    }
    if (!empty($xml_path) && file_exists($xml_path)) @unlink($xml_path);
    if (!empty($temp_folder) && is_dir($temp_folder)) @rmdir($temp_folder);
    $isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    $acceptJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;
    if ($isAjax || $acceptJson || isset($_POST['ajax'])) {
        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: application/json');
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        exit;
    }
    $_SESSION['flash_error'] = "Gagal memproses file: " . $e->getMessage();
    header('Location: index.php');
    exit;
}
