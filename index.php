<?php
// ==========================================
// BAGIAN BACKEND (LAYER 7 API - RATE LIMITED)
// ==========================================

// Mulai session untuk mencatat timestamp cooldown backend
session_start();

// Batasi eksekusi PHP maksimal 120 detik untuk mengakomodasi alur 1000 request/menit
set_time_limit(120);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    // --- SECURITY CHECK: COOLDOWN DI SISI BACKEND (15 DETIK) ---
    $cooldown_limit = 15; // Waktu jeda minimal dalam detik
    $current_time = time();

    if (isset($_SESSION['last_submit_time'])) {
        $time_passed = $current_time - $_SESSION['last_submit_time'];
        if ($time_passed < $cooldown_limit) {
            $sisa_waktu = $cooldown_limit - $time_passed;
            echo json_encode([
                'error' => "Harap tunggu {$sisa_waktu} detik lagi sebelum mengirim request kembali (Backend Protection)."
            ]);
            exit;
        }
    }

    // Catat waktu request berhasil diproses
    $_SESSION['last_submit_time'] = $current_time;
    // -----------------------------------------------------------

    $url = trim($_POST['url'] ?? '');
    
    // Ambil data Referrer dan Origin dari input Frontend
    $custom_referrer = trim($_POST['referrer'] ?? '');
    $custom_origin   = trim($_POST['origin'] ?? '');

    // Validasi Total Request (Maksimal 1000 Request)
    $total_requests = (int)($_POST['total_requests'] ?? 1000);
    if ($total_requests < 1) $total_requests = 1;
    if ($total_requests > 1000) $total_requests = 1000;
    
    // Validasi Concurrent (1 - 40 agar stabil di Railway)
    $concurrent = (int)($_POST['concurrent'] ?? 25); 
    if ($concurrent < 1) $concurrent = 1;
    if ($concurrent > 40) $concurrent = 40;

    // Konfigurasi Proxy
    $proxy = trim($_POST['proxy'] ?? '');
    $proxy_auth = trim($_POST['proxy_auth'] ?? '');
    
    // 1. Validasi URL Target
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['error' => 'URL Endpoint Target tidak valid.']);
        exit;
    }

    // 2. Validasi WAJIB PROXY
    if (empty($proxy)) {
        echo json_encode(['error' => 'Alamat Proxy (IP:Port) wajib diisi untuk simulasi Layer 7!']);
        exit;
    }

    $results = [
        'sukses_200'  => 0, 
        'limit_429'   => 0, 
        'proxy_error' => 0, 
        'lainnya'     => 0, 
        'total'       => 0
    ];

    $proxy_error_messages = [];
    $ch_list = [];

    // Header Penyamaran Browser
    $human_headers = [
        "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8",
        "Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7",
        "Cache-Control: no-cache",
        "Connection: keep-alive",
        "Sec-Ch-Ua: \"Not A(Brand\";v=\"99\", \"Google Chrome\";v=\"121\", \"Chromium\";v=\"121\"",
        "Sec-Ch-Ua-Mobile: ?0",
        "Sec-Ch-Ua-Platform: \"Windows\"",
        "Sec-Fetch-Dest: document",
        "Sec-Fetch-Mode: navigate",
        "Sec-Fetch-Site: cross-site",
        "Sec-Fetch-User: ?1",
        "Upgrade-Insecure-Requests: 1"
    ];

    if (!empty($custom_referrer)) $human_headers[] = "Referer: " . $custom_referrer;
    if (!empty($custom_origin)) $human_headers[] = "Origin: " . $custom_origin;

    $waktu_mulai = microtime(true);

    for ($i = 0; $i < $total_requests; $i++) {
        $ch = curl_init();
        $target_url = $url . (strpos($url, '?') !== false ? '&' : '?') . 'l7_test_id=' . uniqid();
        
        curl_setopt($ch, CURLOPT_URL, $target_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8); // Timeout lebih agresif agar tidak numpuk
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $human_headers);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        if (!empty($proxy_auth)) curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_auth);

        $ch_list[] = $ch;
    }

    $batches = array_chunk($ch_list, $concurrent);
    $total_batches = count($batches);
    $target_total_duration = 55.0; 
    $interval_per_batch = $target_total_duration / max(1, $total_batches);

    foreach ($batches as $index => $batch) {
        $batch_start = microtime(true);
        $mh = curl_multi_init();
        foreach ($batch as $ch) { curl_multi_add_handle($mh, $ch); }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 0.01);
        } while ($running > 0);

        foreach ($batch as $ch) {
            $info = curl_getinfo($ch);
            $http_code = (int)$info['http_code'];
            $curl_err = curl_error($ch);
            
            if ($http_code == 200) { $results['sukses_200']++; 
            } elseif ($http_code == 429) { $results['limit_429']++; 
            } elseif (in_array($http_code, [0, 407, 502, 503, 504]) || !empty($curl_err)) {
                $results['proxy_error']++;
            } else { $results['lainnya']++; }
            
            $results['total']++;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        if ($index < $total_batches - 1) {
            $elapsed_batch_time = microtime(true) - $batch_start;
            $sleep_time = $interval_per_batch - $elapsed_batch_time;
            if ($sleep_time > 0) usleep((int)($sleep_time * 1000000));
        }
    }

    echo json_encode([
        'status'       => 'success', 
        'data'         => $results, 
        'waktu_eksekusi' => round(microtime(true) - $waktu_mulai, 2)
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <title>Layer 7 API</title>
    <style>
        body { font-family: sans-serif; background: #f3f4f6; padding: 2rem; }
        .container { max-width: 500px; margin: auto; background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 1rem; }
        input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: #2563eb; color: white; border: none; padding: 10px; width: 100%; font-weight: bold; cursor: pointer; border-radius: 4px; }
        .result-box { margin-top: 1.5rem; display: none; }
    </style>
</head>
<body>
<div class="container">
    <h2>🔥 Layer 7 API</h2>
    <form id="testerForm">
        <div class="form-group"><label>URL</label><input type="url" name="url" required></div>
        <div class="form-group"><label>Referrer</label><input type="url" name="referrer" required></div>
        <div class="form-group"><label>Origin</label><input type="text" name="origin" required></div>
        <div class="form-group" style="display: flex; gap: 10px;">
            <div style="flex:1"><label>Total (Max 1k)</label><input type="number" name="total_requests" value="1000" max="1000"></div>
            <div style="flex:1"><label>Concurrent</label><input type="number" name="concurrent" value="25" max="40"></div>
        </div>
        <div class="form-group"><label>Proxy IP:Port</label><input type="text" name="proxy" required></div>
        <div class="form-group"><label>Proxy Auth</label><input type="text" name="proxy_auth"></div>
        <button type="submit" id="btnSubmit">Tembak Layer 7!</button>
    </form>
    <div id="resultBox" class="result-box">
        <p>Total Terkirim: <span id="resTotal">0</span></p>
        <p style="color:green">Sukses (200): <span id="res200">0</span></p>
        <p style="color:red">Limit (429): <span id="res429">0</span></p>
        <p>Waktu: <span id="resWaktu">0</span> detik</p>
    </div>
</div>
<script>
document.getElementById('testerForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmit');
    btn.disabled = true; btn.innerText = "Processing...";
    try {
        const res = await fetch(window.location.href, { method: 'POST', body: new FormData(this) });
        const json = await res.json();
        if (json.error) alert(json.error);
        else {
            document.getElementById('resTotal').innerText = json.data.total;
            document.getElementById('res200').innerText = json.data.sukses_200;
            document.getElementById('res429').innerText = json.data.limit_429;
            document.getElementById('resWaktu').innerText = json.waktu_eksekusi;
            document.getElementById('resultBox').style.display = 'block';
        }
    } catch(err) { alert("Error: " + err.message); }
    finally { btn.disabled = false; btn.innerText = "Tembak Layer 7!"; }
});
</script>
</body>
</html>
