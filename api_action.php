<?php
header('Content-Type: application/json');
require_once 'generate_report.php';

$AI_MODELS_CACHE = __DIR__ . '/data/cache/ai_models.json';
$AI_MODELS_TTL_MIN = 300;

function getFallbackProviderModels() {
    return [
        'groq' => [
            ['value' => 'groq/compound', 'text' => 'Groq Compound (Default)'],
            ['value' => 'groq/compound-mini', 'text' => 'Groq Compound Mini'],
            ['value' => 'qwen/qwen3-32b', 'text' => 'Qwen 3 32B'],
            ['value' => 'openai/gpt-oss-120b', 'text' => 'GPT OSS 120B'],
            ['value' => 'openai/gpt-oss-20b', 'text' => 'GPT OSS 20B'],
            ['value' => 'llama-3.3-70b-versatile', 'text' => 'Llama 3.3 70B Versatile'],
            ['value' => 'llama-3.1-8b-instant', 'text' => 'Llama 3.1 8B Instant'],
            ['value' => 'custom', 'text' => 'Model Lainnya (Tulis Kustom...)'],
        ],
        'openai' => [
            ['value' => 'gpt-4o-mini', 'text' => 'GPT-4o-mini (Default)'],
            ['value' => 'gpt-4o', 'text' => 'GPT-4o'],
            ['value' => 'gpt-4.1-mini', 'text' => 'GPT-4.1 mini'],
            ['value' => 'o3-mini', 'text' => 'O3 Mini'],
            ['value' => 'custom', 'text' => 'Model Lainnya (Tulis Kustom...)'],
        ],
        'gemini' => [
            ['value' => 'gemini-1.5-flash', 'text' => 'Gemini-1.5-flash (Default)'],
            ['value' => 'gemini-1.5-pro', 'text' => 'Gemini-1.5-pro'],
            ['value' => 'gemini-2.0-flash', 'text' => 'Gemini-2.0-flash'],
            ['value' => 'custom', 'text' => 'Model Lainnya (Tulis Kustom...)'],
        ],
        'openrouter' => [
            ['value' => 'meta-llama/llama-3.3-70b-instruct', 'text' => 'Llama-3.3-70b (Default)'],
            ['value' => 'google/gemini-2.0-flash-exp:free', 'text' => 'Gemini-2.0-flash (Free)'],
            ['value' => 'deepseek/deepseek-chat', 'text' => 'DeepSeek Chat (V3)'],
            ['value' => 'custom', 'text' => 'Model Lainnya (Tulis Kustom...)'],
        ],
    ];
}

function readModelsCache() {
    global $AI_MODELS_CACHE;
    if (!file_exists($AI_MODELS_CACHE)) return null;
    $raw = @file_get_contents($AI_MODELS_CACHE);
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) ? $j : null;
}

function writeModelsCache($data) {
    global $AI_MODELS_CACHE;
    $dir = dirname($AI_MODELS_CACHE);
    if (!is_dir($dir)) @mkdir($dir, 0777, true);
    @file_put_contents($AI_MODELS_CACHE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

function curlGetJson($url, $headers = [], $timeout = 15) {
    if (!function_exists('curl_init')) {
        if (!ini_get('allow_url_fopen')) {
            return [0, '', 'curl mati + allow_url_fopen=Off. Aktifkan extension=curl di php.ini atau jalankan pakai "C:\xampp\php\php.exe" -S localhost:8000'];
        }
        if (!extension_loaded('openssl')) {
            return [0, '', 'openssl mati — aktifkan extension=openssl di php.ini'];
        }
        $hdrStr = implode("\r\n", $headers);
        $opts = ['http' => ['method' => 'GET', 'header' => $hdrStr, 'timeout' => $timeout, 'ignore_errors' => true, 'user_agent' => 'PentestReportGenerator/2.5']];
        $caFile = __DIR__ . '/cacert.pem';
        if (file_exists($caFile)) $opts['ssl'] = ['cafile' => $caFile, 'verify_peer' => true, 'verify_peer_name' => true];
        $ctx = stream_context_create($opts);
        $resp = @file_get_contents($url, false, $ctx);
        $code = 0;
        if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
            if (preg_match('#HTTP/\d\.\d\s+(\d+)#', $http_response_header[0], $m)) $code = (int)$m[1];
        }
        if ($resp === false) {
            $last = error_get_last();
            $msg = $last ? $last['message'] : 'file_get_contents gagal';
            if (stripos($msg, 'allow_url_fopen') !== false) $msg .= ' — aktifkan allow_url_fopen=On di php.ini';
            if (stripos($msg, 'SSL') !== false || stripos($msg, 'certificate') !== false) $msg .= ' — cek cacert.pem / extension=openssl';
            return [0, '', 'curl mati + ' . $msg];
        }
        return [$code, $resp, ''];
    }
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'PentestReportGenerator/2.5');
    $caFile = __DIR__ . '/cacert.pem';
    if (file_exists($caFile)) curl_setopt($ch, CURLOPT_CAINFO, $caFile);
    else curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return [$code, $resp, $err];
}

function filterOpenAiStyleModels($data) {
    if (!isset($data['data']) || !is_array($data['data'])) return [];
    $out = [];
    $block = '/(whisper|tts|dall-e|embedding|moderation|audio|transcribe|image|vector|search)/i';
    foreach ($data['data'] as $m) {
        $id = isset($m['id']) ? $m['id'] : '';
        if (!$id) continue;
        if (preg_match($block, $id)) continue;
        $created = isset($m['created']) ? (int)$m['created'] : 0;
        $out[] = ['id' => $id, 'created' => $created];
    }
    usort($out, function($a,$b){ return $b['created'] <=> $a['created']; });
    return $out;
}

function fetchGroqModels($apiKey) {
    if (empty($apiKey)) return [0, null, 'API key kosong'];
    list($code,$resp,$err) = curlGetJson('https://api.groq.com/openai/v1/models', ['Authorization: Bearer '.$apiKey]);
    if ($err) return [0, null, $err];
    if ($code !== 200) return [$code, $resp, 'HTTP '.$code];
    $j = json_decode($resp, true);
    $list = filterOpenAiStyleModels($j);
    return [$code, $list, null];
}

function fetchOpenAiModels($apiKey) {
    if (empty($apiKey)) return [0, null, 'API key kosong'];
    list($code,$resp,$err) = curlGetJson('https://api.openai.com/v1/models', ['Authorization: Bearer '.$apiKey]);
    if ($err) return [0, null, $err];
    if ($code !== 200) return [$code, $resp, 'HTTP '.$code];
    $j = json_decode($resp, true);
    $list = filterOpenAiStyleModels($j);
    // prefer gpt/o series on top
    usort($list, function($a,$b){
        $pa = preg_match('/^(gpt|o\d|chatgpt)/i', $a['id']) ? 0 : 1;
        $pb = preg_match('/^(gpt|o\d|chatgpt)/i', $b['id']) ? 0 : 1;
        if ($pa !== $pb) return $pa <=> $pb;
        return $b['created'] <=> $a['created'];
    });
    return [$code, $list, null];
}

function fetchOpenRouterModels() {
    list($code,$resp,$err) = curlGetJson('https://openrouter.ai/api/v1/models', []);
    if ($err) return [0, null, $err];
    if ($code !== 200) return [$code, $resp, 'HTTP '.$code];
    $j = json_decode($resp, true);
    if (!isset($j['data']) || !is_array($j['data'])) return [$code, null, 'Format tidak dikenal'];
    $out = [];
    foreach ($j['data'] as $m) {
        $id = isset($m['id']) ? $m['id'] : '';
        if (!$id) continue;
        $created = isset($m['created']) ? (int)$m['created'] : 0;
        $name = isset($m['name']) ? $m['name'] : $id;
        $out[] = ['id' => $id, 'name' => $name, 'created' => $created];
    }
    usort($out, function($a,$b){ return $b['created'] <=> $a['created']; });
    $out = array_slice($out, 0, 40);
    return [$code, $out, null];
}

function fetchGeminiModels($apiKey) {
    if (empty($apiKey)) return [0, null, 'API key kosong'];
    $url = 'https://generativelanguage.googleapis.com/v1beta/models?key='.rawurlencode($apiKey);
    list($code,$resp,$err) = curlGetJson($url, []);
    if ($err) return [0, null, $err];
    if ($code !== 200) return [$code, $resp, 'HTTP '.$code];
    $j = json_decode($resp, true);
    if (!isset($j['models']) || !is_array($j['models'])) return [$code, null, 'Format tidak dikenal'];
    $out = [];
    foreach ($j['models'] as $m) {
        $name = isset($m['name']) ? $m['name'] : '';
        if (!$name) continue;
        $methods = isset($m['supportedGenerationMethods']) ? $m['supportedGenerationMethods'] : [];
        if (!in_array('generateContent', $methods)) continue;
        $id = preg_replace('#^models/#', '', $name);
        $display = isset($m['displayName']) ? $m['displayName'] : $id;
        $out[] = ['id' => $id, 'name' => $display, 'created' => 0];
    }
    return [$code, $out, null];
}

function modelsToSelectOptions($provider, $rawList) {
    $opts = [];
    $seen = [];
    foreach ($rawList as $m) {
        $id = is_array($m) ? (isset($m['id']) ? $m['id'] : '') : $m;
        if (!$id || isset($seen[$id])) continue;
        $seen[$id] = true;
        $label = $id;
        if (isset($m['name']) && $m['name'] !== $id) $label = $m['name'] . ' (' . $id . ')';
        $opts[] = ['value' => $id, 'text' => $label, 'created' => isset($m['created']) ? $m['created'] : 0];
        if (count($opts) >= 30) break;
    }
    $opts[] = ['value' => 'custom', 'text' => 'Model Lainnya (Tulis Kustom...)', 'created' => 0];
    return $opts;
}

function maskApiKey($k) {
    $k = trim((string)$k);
    if (strlen($k) <= 8) return str_repeat('*', strlen($k));
    return substr($k, 0, 4) . '***' . substr($k, -4);
}

function isValidGroqKeyFormat($k) {
    return preg_match('/^gsk_[A-Za-z0-9]{20,}$/', trim($k));
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

if ($action === 'get_cache_info') {
    $cacheDir = __DIR__ . '/data/cache';
    $db = getCacheDb();

    if ($db) {
        $db_file  = $cacheDir . '/cache.sqlite';
        $db_size  = file_exists($db_file) ? filesize($db_file) : 0;
        $translate_entries = 0;
        $ai_entries        = 0;
        try {
            $translate_entries = (int)$db->query("SELECT COUNT(*) FROM translate_cache")->fetchColumn();
            $ai_entries        = (int)$db->query("SELECT COUNT(*) FROM ai_cache")->fetchColumn();
        } catch (Exception $e) {
            // Abaikan error DB
        }
        
        echo json_encode([
            'status'             => 'success',
            'translate_bytes'    => $db_size,
            'translate_entries'  => $translate_entries,
            'ai_bytes'           => $db_size,
            'ai_entries'         => $ai_entries,
            'translate_cache_kb' => round($db_size / 1024, 2),
            'ai_cache_kb'        => round($db_size / 1024, 2),
        ]);
    } else {
        // Fallback: JSON file cache info
        $translate_file = $cacheDir . '/translate_cache.json';
        $ai_file        = $cacheDir . '/ai_cache.json';

        $translate_size = file_exists($translate_file) ? filesize($translate_file) : 0;
        $ai_size        = file_exists($ai_file)        ? filesize($ai_file)        : 0;

        $translate_entries = 0;
        $ai_entries        = 0;
        if (file_exists($translate_file)) {
            $td = json_decode(file_get_contents($translate_file), true);
            $translate_entries = is_array($td) ? count($td) : 0;
        }
        if (file_exists($ai_file)) {
            $ad = json_decode(file_get_contents($ai_file), true);
            $ai_entries = is_array($ad) ? count($ad) : 0;
        }

        echo json_encode([
            'status'             => 'success',
            'translate_bytes'    => $translate_size,
            'translate_entries'  => $translate_entries,
            'ai_bytes'           => $ai_size,
            'ai_entries'         => $ai_entries,
            'translate_cache_kb' => round($translate_size / 1024, 2),
            'ai_cache_kb'        => round($ai_size / 1024, 2),
        ]);
    }
    exit;
}

if ($action === 'clear_cache') {
    $db = getCacheDb();
    if ($db) {
        try {
            $db->exec("DELETE FROM translate_cache");
            $db->exec("DELETE FROM ai_cache");
            $db->exec("VACUUM");
        } catch (Exception $e) {
            // Abaikan error DB
        }
        $msg = 'Cache terjemahan dan cache AI berhasil dibersihkan dari database!';
    } else {
        // Fallback: Clear JSON files
        $cacheDir = __DIR__ . '/data/cache';
        $translate_file = $cacheDir . '/translate_cache.json';
        $ai_file        = $cacheDir . '/ai_cache.json';
        @file_put_contents($translate_file, json_encode([]), LOCK_EX);
        @file_put_contents($ai_file, json_encode([]), LOCK_EX);
        $msg = 'Cache terjemahan dan cache AI (JSON) berhasil dibersihkan!';
    }
    
    echo json_encode([
        'status' => 'success',
        'message' => $msg
    ]);
    exit;
}

if ($action === 'test_connection') {
    $provider = isset($_POST['provider']) ? trim($_POST['provider']) : 'groq';
    $custom_key = isset($_POST['custom_key']) ? trim($_POST['custom_key']) : '';
    $model = isset($_POST['model']) ? trim($_POST['model']) : '';
    
    if (empty($custom_key)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Custom API Key wajib diisi untuk melakukan pengujian koneksi!'
        ]);
        exit;
    }
    
    $key_to_use = $custom_key;
    
    // Fallback model jika kosong
    $model_to_use = $model;
    if (empty($model_to_use)) {
        switch (strtolower($provider)) {
            case 'openai':
                $model_to_use = 'gpt-4o-mini';
                break;
            case 'gemini':
                $model_to_use = 'gemini-1.5-flash';
                break;
            case 'openrouter':
                $model_to_use = 'meta-llama/llama-3.3-70b-instruct';
                break;
            case 'groq':
            default:
                $model_to_use = 'groq/compound';
                break;
        }
    }
    
    if (empty($key_to_use)) {
        echo json_encode([
            'status' => 'error',
            'message' => 'API Key kosong! Masukkan Custom API Key atau konfigurasikan kunci default.'
        ]);
        exit;
    }
    
    // Uji coba koneksi ringan (ping)
    $test_prompt = "Katakan kata 'Koneksi Berhasil' saja secara singkat.";
    $response = null;
    
    try {
        switch (strtolower($provider)) {
            case 'openai':
                $response = callOpenAiCompatibleApi('https://api.openai.com/v1/chat/completions', $key_to_use, $model_to_use, $test_prompt);
                break;
            case 'gemini':
                $response = getGeminiEnhancementRaw($test_prompt, $key_to_use, $model_to_use);
                break;
            case 'openrouter':
                $response = callOpenAiCompatibleApi(
                    'https://openrouter.ai/api/v1/chat/completions',
                    $key_to_use,
                    $model_to_use,
                    $test_prompt,
                    [
                        'HTTP-Referer' => 'http://localhost:9090',
                        'X-Title' => 'Pentest Report Generator Test'
                    ]
                );
                break;
            case 'groq':
            default:
                $response = callOpenAiCompatibleApi('https://api.groq.com/openai/v1/chat/completions', $key_to_use, $model_to_use, $test_prompt);
                break;
        }
        
        if (!empty($response)) {
            echo json_encode([
                'status' => 'success',
                'message' => 'Koneksi sukses! Respons API: "' . trim($response) . '"'
            ]);
        } else {
            echo json_encode([
                'status' => 'error',
                'message' => 'API tidak merespons. Periksa koneksi internet atau kuota API Key.'
            ]);
        }
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'message' => 'Gagal menghubungkan ke server API: ' . $e->getMessage()
        ]);
    }
    exit;
}

if ($action === 'list_models') {
    $provider = isset($_GET['provider']) ? trim($_GET['provider']) : '';
    $cache = readModelsCache();
    $fallback = getFallbackProviderModels();
    if ($provider !== '' && isset($fallback[$provider])) {
        $models = null;
        $ts = null;
        if ($cache && isset($cache['models'][$provider])) {
            $models = $cache['models'][$provider];
            $ts = isset($cache['updated_at_by_provider'][$provider]) ? $cache['updated_at_by_provider'][$provider] : (isset($cache['updated_at']) ? $cache['updated_at'] : null);
        }
        if ($models === null) {
            $models = $fallback[$provider];
        }
        echo json_encode(['status' => 'success', 'provider' => $provider, 'models' => $models, 'updated_at' => $ts, 'cached' => $cache !== null && isset($cache['models'][$provider]), 'fallback' => $models === $fallback[$provider] && !isset($cache['models'][$provider])]);
        exit;
    }
    $out = [];
    $tsMap = [];
    foreach ($fallback as $prov => $fbModels) {
        if ($cache && isset($cache['models'][$prov])) {
            $out[$prov] = $cache['models'][$prov];
            $tsMap[$prov] = isset($cache['updated_at_by_provider'][$prov]) ? $cache['updated_at_by_provider'][$prov] : (isset($cache['updated_at']) ? $cache['updated_at'] : null);
        } else {
            $out[$prov] = $fbModels;
            $tsMap[$prov] = null;
        }
    }
    echo json_encode(['status' => 'success', 'models' => $out, 'updated_at' => isset($cache['updated_at']) ? $cache['updated_at'] : null, 'updated_at_by_provider' => $tsMap, 'cached' => $cache !== null]);
    exit;
}

if ($action === 'refresh_models') {
    $provider = isset($_POST['provider']) ? trim($_POST['provider']) : (isset($_GET['provider']) ? trim($_GET['provider']) : '');
    $customKey = isset($_POST['custom_key']) ? trim($_POST['custom_key']) : (isset($_POST['custom_api_key']) ? trim($_POST['custom_api_key']) : '');
    $allowed = ['groq','openai','gemini','openrouter'];
    if (!in_array(strtolower($provider), $allowed)) {
        echo json_encode(['status' => 'error', 'message' => 'Provider tidak dikenal.']);
        exit;
    }
    $provider = strtolower($provider);
    $cache = readModelsCache();
    if ($cache && isset($cache['updated_at_by_provider'][$provider])) {
        $last = strtotime($cache['updated_at_by_provider'][$provider]);
        if ($last && (time() - $last) < $AI_MODELS_TTL_MIN) {
            $wait = $AI_MODELS_TTL_MIN - (time() - $last);
            echo json_encode(['status' => 'error', 'message' => 'Terlalu sering. Coba lagi dalam ' . ceil($wait/60) . ' menit.', 'wait_seconds' => $wait, 'cached_models' => isset($cache['models'][$provider]) ? $cache['models'][$provider] : null]);
            exit;
        }
    }
    $code = 0; $list = null; $err = null;
    if ($provider === 'openrouter') {
        list($code,$list,$err) = fetchOpenRouterModels();
    } else {
        $keys = function_exists('getProviderKeys') ? getProviderKeys($provider, $customKey) : ($customKey ? [trim($customKey)] : []);
        if (empty($keys) && $provider !== 'openrouter') {
            $constMap = ['groq'=>'AI_KEY_GROQ','openai'=>'AI_KEY_OPENAI','gemini'=>'AI_KEY_GEMINI'];
            if (isset($constMap[$provider]) && defined($constMap[$provider])) {
                $k = trim(constant($constMap[$provider]));
                if ($k !== '') $keys = [$k];
            }
        }
        if (empty($keys)) {
            echo json_encode(['status' => 'error', 'message' => 'API key kosong untuk ' . $provider . '. Isi Custom API Key atau konfigurasi server.']);
            exit;
        }
        $lastErr = null;
        foreach ($keys as $tryKey) {
            if ($provider === 'groq') list($code,$list,$err) = fetchGroqModels($tryKey);
            elseif ($provider === 'openai') list($code,$list,$err) = fetchOpenAiModels($tryKey);
            elseif ($provider === 'gemini') list($code,$list,$err) = fetchGeminiModels($tryKey);
            if ($err === null && is_array($list) && !empty($list)) break;
            if ($err !== null && strpos(strtolower((string)$err), 'api key kosong') !== false) break;
            if ($code === 429 || $code === 503 || ($err && stripos($err,'rate') !== false)) { $lastErr = $err; continue; }
            if ($err !== null) { $lastErr = $err; break; }
            $lastErr = $err;
        }
        if (($list === null || (is_array($list) && empty($list))) && $lastErr !== null) $err = $lastErr;
    }
    if ($err !== null || $list === null) {
        $fb = getFallbackProviderModels();
        $msg = $err ? $err : 'Gagal mengambil daftar model.';
        if (is_string($list) && strlen($list) < 500) $msg .= ' ' . substr($list,0,200);
        echo json_encode(['status' => 'error', 'message' => 'Gagal refresh ' . $provider . ': ' . $msg, 'fallback_models' => $fb[$provider]]);
        exit;
    }
    if (is_array($list) && empty($list)) {
        $fb = getFallbackProviderModels();
        echo json_encode(['status' => 'error', 'message' => 'Provider tidak mengembalikan model.', 'fallback_models' => $fb[$provider]]);
        exit;
    }
    $opts = modelsToSelectOptions($provider, $list);
    if ($cache === null) $cache = ['models'=>[], 'updated_at'=> null, 'updated_at_by_provider'=>[]];
    if (!isset($cache['models'])) $cache['models'] = [];
    if (!isset($cache['updated_at_by_provider'])) $cache['updated_at_by_provider'] = [];
    $cache['models'][$provider] = $opts;
    $nowIso = date('c');
    $cache['updated_at'] = $nowIso;
    $cache['updated_at_by_provider'][$provider] = $nowIso;
    writeModelsCache($cache);
    echo json_encode(['status' => 'success', 'provider' => $provider, 'models' => $opts, 'updated_at' => $nowIso, 'count' => count($opts)-1]);
    exit;
}

if ($action === 'keys_list') {
    $provider = isset($_GET['provider']) ? trim($_GET['provider']) : (isset($_POST['provider']) ? trim($_POST['provider']) : 'groq');
    $provider = strtolower($provider);
    $allowed = ['groq','openai','gemini','openrouter'];
    if (!in_array($provider, $allowed)) $provider = 'groq';
    $constKeys = [];
    $constMap = ['groq'=>'AI_KEY_GROQ','openai'=>'AI_KEY_OPENAI','gemini'=>'AI_KEY_GEMINI','openrouter'=>'AI_KEY_OPENROUTER'];
    if (isset($constMap[$provider]) && defined($constMap[$provider])) {
        foreach (parseApiKeys(constant($constMap[$provider])) as $k) $constKeys[] = ['masked' => maskApiKey($k), 'source' => 'config'];
        foreach ([2,3,4,5] as $n) {
            $c2 = $constMap[$provider] . '_' . $n;
            if (defined($c2) && trim((string)constant($c2)) !== '') foreach (parseApiKeys(constant($c2)) as $k) $constKeys[] = ['masked' => maskApiKey($k), 'source' => 'config'];
        }
    }
    $vault = [];
    if (function_exists('readStoredKeysRaw')) {
        $rawStore = readStoredKeysRaw();
        $arr = isset($rawStore[$provider]) ? $rawStore[$provider] : [];
        foreach ($arr as $idx => $enc) {
            $dec = function_exists('decryptStoredKey') ? decryptStoredKey($enc) : null;
            $vault[] = ['idx' => $idx, 'masked' => $dec ? maskApiKey($dec) : '****', 'source' => 'vault'];
        }
    }
    echo json_encode(['status' => 'success', 'provider' => $provider, 'config_keys' => $constKeys, 'vault_keys' => $vault, 'total' => count($constKeys) + count($vault)]);
    exit;
}

if ($action === 'keys_add') {
    $provider = isset($_POST['provider']) ? trim($_POST['provider']) : (isset($_GET['provider']) ? trim($_GET['provider']) : 'groq');
    $provider = strtolower($provider);
    $allowed = ['groq','openai','gemini','openrouter'];
    if (!in_array($provider, $allowed)) $provider = 'groq';
    $newKey = isset($_POST['api_key']) ? trim($_POST['api_key']) : (isset($_POST['custom_key']) ? trim($_POST['custom_key']) : (isset($_POST['key']) ? trim($_POST['key']) : ''));
    if ($newKey === '') { echo json_encode(['status'=>'error','message'=>'API key kosong.']); exit; }
    if ($provider === 'groq' && !isValidGroqKeyFormat($newKey)) { echo json_encode(['status'=>'error','message'=>'Format Groq key tidak valid. Harus diawali gsk_ dan min 20 karakter alfanumerik.']); exit; }
    if (function_exists('getProviderKeys')) {
        $existing = getProviderKeys($provider, null);
        foreach ($existing as $ek) { if (trim($ek) === trim($newKey)) { echo json_encode(['status'=>'error','message'=>'Key sudah ada (duplikat) — tidak dimasukkan.']); exit; } }
    }
    $code = 0; $list = null; $err = null;
    if ($provider === 'groq') list($code,$list,$err) = fetchGroqModels($newKey);
    elseif ($provider === 'openai') list($code,$list,$err) = fetchOpenAiModels($newKey);
    elseif ($provider === 'openrouter') list($code,$list,$err) = fetchOpenRouterModels();
    elseif ($provider === 'gemini') list($code,$list,$err) = fetchGeminiModels($newKey);
    if ($err !== null && stripos($err, 'api key kosong') !== false) { echo json_encode(['status'=>'error','message'=>$err]); exit; }
    if ($code === 401 || $code === 403) { echo json_encode(['status'=>'error','message'=>'API key tidak valid (ditolak server Groq: HTTP '.$code.').']); exit; }
    if ($err !== null && is_string($err) && (stripos($err,'invalid') !== false || stripos($err,'unauthorized') !== false || stripos($err,'incorrect') !== false)) { echo json_encode(['status'=>'error','message'=>'API key tidak valid: '.$err]); exit; }
    if ($provider !== 'openrouter' && ($list === null || (is_string($list) && stripos($list,'invalid') !== false))) { echo json_encode(['status'=>'error','message'=>'API key tidak valid atau tidak bisa memuat model (HTTP '.$code.').']); exit; }
    if ($provider === 'openrouter' && $list === null) { echo json_encode(['status'=>'error','message'=>'Gagal validasi OpenRouter: '.$err]); exit; }
    if (!function_exists('encryptStoredKey') || !function_exists('readStoredKeysRaw') || !function_exists('writeStoredKeysRaw')) { echo json_encode(['status'=>'error','message'=>'Penyimpanan vault tidak tersedia di server.']); exit; }
    $store = readStoredKeysRaw();
    if (!isset($store[$provider]) || !is_array($store[$provider])) $store[$provider] = [];
    if (count($store[$provider]) >= 20) { echo json_encode(['status'=>'error','message'=>'Vault penuh (maks 20 key per provider). Hapus dulu yang lama.']); exit; }
    $store[$provider][] = encryptStoredKey($newKey);
    if (!writeStoredKeysRaw($store)) { echo json_encode(['status'=>'error','message'=>'Gagal menyimpan key (folder data/cache tidak writable).']); exit; }
    echo json_encode(['status'=>'success','message'=>'Key valid & tersimpan. Rotasi otomatis aktif.','masked'=>maskApiKey($newKey)]);
    exit;
}

if ($action === 'keys_remove') {
    $provider = isset($_POST['provider']) ? trim($_POST['provider']) : (isset($_GET['provider']) ? trim($_GET['provider']) : 'groq');
    $provider = strtolower($provider);
    $allowed = ['groq','openai','gemini','openrouter'];
    if (!in_array($provider, $allowed)) $provider = 'groq';
    $idx = isset($_POST['idx']) ? (int)$_POST['idx'] : (isset($_POST['index']) ? (int)$_POST['index'] : -1);
    if ($idx < 0) { echo json_encode(['status'=>'error','message'=>'Index tidak valid.']); exit; }
    if (!function_exists('readStoredKeysRaw') || !function_exists('writeStoredKeysRaw')) { echo json_encode(['status'=>'error','message'=>'Penyimpanan vault tidak tersedia.']); exit; }
    $store = readStoredKeysRaw();
    if (!isset($store[$provider]) || !is_array($store[$provider]) || !isset($store[$provider][$idx])) { echo json_encode(['status'=>'error','message'=>'Key tidak ditemukan di vault (hanya vault yang bisa dihapus).']); exit; }
    array_splice($store[$provider], $idx, 1);
    if (!writeStoredKeysRaw($store)) { echo json_encode(['status'=>'error','message'=>'Gagal menghapus key.']); exit; }
    echo json_encode(['status'=>'success','message'=>'Key dihapus dari vault.']);
    exit;
}

echo json_encode([
    'status' => 'error',
    'message' => 'Aksi tidak valid.'
]);
