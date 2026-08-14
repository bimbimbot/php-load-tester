<?php
// ==========================================
// BAGIAN BACKEND (LAYER 7 API - RATE LIMITED)
// ==========================================

// Mulai session untuk mencatat timestamp cooldown backend
session_start();

// Batasi eksekusi PHP maksimal 180 detik untuk mengakomodasi alur 1500 request/menit
set_time_limit(180);

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
    
    // Ambil data Referrer dan Origin dari input Frontend (Wajib diisi di frontend)
    $custom_referrer = trim($_POST['referrer'] ?? '');
    $custom_origin   = trim($_POST['origin'] ?? '');

    // Validasi Min & Max untuk Total Request (1 - 1500 Request per menit)
    $total_requests = (int)($_POST['total_requests'] ?? 1500);
    if ($total_requests < 1) $total_requests = 1;
    if ($total_requests > 1500) $total_requests = 1500;
    
    // Validasi Min & Max untuk Concurrent (1 - 50 agar stabil menghandle 1500 request tanpa timeout/crash)
    $concurrent = (int)($_POST['concurrent'] ?? 30); 
    if ($concurrent < 1) $concurrent = 1;
    if ($concurrent > 50) $concurrent = 50;

    // Konfigurasi Proxy (Proxy Wajib, Auth Opsional)
    $proxy = trim($_POST['proxy'] ?? '');
    $proxy_auth = trim($_POST['proxy_auth'] ?? '');
    
    // 1. Validasi URL Target
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['error' => 'URL Endpoint Target tidak valid.']);
        exit;
    }

    // 2. Validasi WAJIB PROXY (Layer 7 Distributed Test)
    if (empty($proxy)) {
        echo json_encode(['error' => 'Alamat Proxy (IP:Port) wajib diisi untuk simulasi Layer 7!']);
        exit;
    }

    $results = [
        'sukses_200'  => 0, 
        'limit_429'   => 0, 
        'proxy_error' => 0, // Kena limit/error proxy (407 Proxy Auth, 502/504 Bad Gateway, 0/Timeout)
        'lainnya'     => 0, 
        'total'       => 0
    ];

    $proxy_error_messages = [];
    $ch_list = [];

    // Header Penyamaran Browser (Layer 7 HTTP Simulation)
    // Referrer dan Origin dikosongkan dari backend, diambil sepenuhnya dari input user/frontend
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

    // Tambahkan Referer dan Origin dinamis jika diisi dari frontend
    if (!empty($custom_referrer)) {
        $human_headers[] = "Referer: " . $custom_referrer;
    }
    if (!empty($custom_origin)) {
        $human_headers[] = "Origin: " . $custom_origin;
    }

    $waktu_mulai = microtime(true);

    for ($i = 0; $i < $total_requests; $i++) {
        $ch = curl_init();
        
        $target_url = $url . (strpos($url, '?') !== false ? '&' : '?') . 'l7_test_id=' . uniqid();
        
        curl_setopt($ch, CURLOPT_URL, $target_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        
        // Timeout cURL diset 10 detik per-request
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $human_headers);
        curl_setopt($ch, CURLOPT_ENCODING, ""); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 

        // INJEKSI PROXY (WAJIB)
        curl_setopt($ch, CURLOPT_PROXY, $proxy);
        
        // Injeksi Proxy Auth (Jika Diisi)
        if (!empty($proxy_auth)) {
            curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_auth);
        }

        $ch_list[] = $ch;
    }

    $batches = array_chunk($ch_list, $concurrent);
    $total_batches = count($batches);
    
    // Perhitungan jeda waktu antar-batch agar 1500 request terbagi rata dalam durasi 60 detik (1 menit)
    $target_total_duration = 60.0; 
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
            
            if ($http_code == 200) { 
                $results['sukses_200']++; 
            } elseif ($http_code == 429) { 
                $results['limit_429']++; 
            } elseif (in_array($http_code, [0, 407, 502, 503, 504]) || !empty($curl_err)) {
                // Error Proxy / Proxy Auth Fail / Proxy Dead / Connection Timeout
                $results['proxy_error']++;
                if (!empty($curl_err) && count($proxy_error_messages) < 3) {
                    $proxy_error_messages[] = "Proxy Connection Error: " . $curl_err;
                } elseif ($http_code == 407) {
                    $proxy_error_messages[] = "Proxy Auth Failed (407): Username/Password Proxy Salah atau Kuota Habis.";
                } elseif ($http_code == 502 || $http_code == 504) {
                    $proxy_error_messages[] = "Proxy Gateway Error ($http_code): Server Proxy tidak merespon / offline.";
                }
            } else { 
                $results['lainnya']++; 
            }
            
            $results['total']++;
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        // Jeda waktu otomatis (pacing delay) antar-batch untuk menjaga ritme 1500 req/menit
        if ($index < $total_batches - 1) {
            $elapsed_batch_time = microtime(true) - $batch_start;
            $sleep_time = $interval_per_batch - $elapsed_batch_time;
            if ($sleep_time > 0) {
                usleep((int)($sleep_time * 1000000));
            }
        }
    }

    $waktu_selesai = microtime(true);
    $durasi = round($waktu_selesai - $waktu_mulai, 2);

    $msg_note = "";
    if (!empty($proxy_error_messages)) {
        $msg_note = implode(" | ", array_unique($proxy_error_messages));
    }

    echo json_encode([
        'status'       => 'success', 
        'data'         => $results, 
        'waktu_eksekusi' => $durasi,
        'proxy_note'     => $msg_note
    ]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Layer 7 API</title>
    <style>
        body { font-family: system-ui, -apple-system, sans-serif; background: #f3f4f6; padding: 2rem; color: #1e293b; }
        .container { max-width: 620px; margin: auto; background: white; padding: 2rem; border-radius: 10px; box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; margin-bottom: 0.4rem; font-weight: 600; font-size: 0.95rem; }
        input { width: 100%; padding: 0.65rem; border: 1px solid #cbd5e1; border-radius: 6px; box-sizing: border-box; font-size: 0.95rem; }
        input:focus { outline: none; border-color: #2563eb; box-shadow: 0 0 0 3px rgba(37,99,235,0.15); }
        .hint { font-size: 0.8rem; color: #64748b; margin-top: 4px; display: block; }
        button { background: #2563eb; color: white; border: none; padding: 0.85rem; width: 100%; font-weight: bold; font-size: 1rem; cursor: pointer; border-radius: 6px; transition: background 0.2s;}
        button:hover { background: #1d4ed8; }
        button:disabled { background: #94a3b8; cursor: not-allowed; }
        
        .result-box { margin-top: 1.5rem; padding: 1.2rem; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; display: none; }
        .stat { display: flex; justify-content: space-between; margin-bottom: 0.6rem; font-size: 0.95rem; }
        .status-200 { color: #16a34a; font-weight: bold; }
        .status-429 { color: #dc2626; font-weight: bold; }
        .status-proxy-err { color: #ea580c; font-weight: bold; }
        .waktu-box { border-top: 1px dashed #cbd5e1; margin-top: 10px; padding-top: 10px; color: #475569; }
        
        .alert-proxy { margin-top: 10px; padding: 10px; background: #fff7ed; border-left: 4px solid #f97316; color: #c2410c; font-size: 0.85rem; border-radius: 4px; display: none; word-break: break-word; }
    </style>
</head>
<body>
<div class="container">
    <h2>🔥 Layer 7 API</h2>
    <form id="testerForm">
        <div class="form-group">
            <label>URL Endpoint API</label>
            <input type="url" name="url" value="" placeholder="Masukkan URL Endpoint API" required>
        </div>

        <div class="form-group">
            <label>Referrer (Wajib)</label>
            <input type="url" name="referrer" placeholder="Contoh: https://website-kamu.com/" required>
            <span class="hint">Wajib diisi URL Referrer yang sah.</span>
        </div>

        <div class="form-group">
            <label>Origin (Wajib)</label>
            <input type="text" name="origin" placeholder="Contoh: https://website-kamu.com" required>
            <span class="hint">Wajib diisi Origin yang sah.</span>
        </div>
        
        <div class="form-group" style="display: flex; gap: 12px;">
            <div style="flex: 1;">
                <label>Total Request (1 - 1500)</label>
                <input type="number" name="total_requests" value="1500" min="1" max="1500" required>
                <span class="hint">Minimal 1, Maksimal 1500 per menit</span>
            </div>
            <div style="flex: 1;">
                <label>Concurrent (1 - 50)</label>
                <input type="number" name="concurrent" value="30" min="1" max="50" required>
                <span class="hint">Jumlah paralel request per-batch</span>
            </div>
        </div>
        
        <div class="form-group">
            <label>Proxy (Wajib) - IP:Port</label>
            <input type="text" name="proxy" placeholder="Contoh: 45.38.107.97:6014" required>
            <span class="hint">Wajib diisi IP:Port Proxy aktif.</span>
        </div>
        
        <div class="form-group">
            <label>Proxy Auth (Opsional) - Username:Password</label>
            <input type="text" name="proxy_auth" placeholder="username:password">
            <span class="hint">Kosongkan jika proxy tidak membutuhkan autentikasi login.</span>
        </div>
        
        <button type="submit" id="btnSubmit">Tembak Layer 7!</button>
    </form>

    <div id="resultBox" class="result-box">
        <h3 style="margin-top: 0; margin-bottom: 15px; font-size: 1.05rem;">📊 Hasil Pengujian Layer 7:</h3>
        <div class="stat"><span>Total Terkirim:</span> <span id="resTotal" style="font-weight: bold;">0</span></div>
        <div class="stat"><span class="status-200">Masuk / Sukses (200):</span> <span id="res200">0</span></div>
        <div class="stat"><span class="status-429">Kena Rate Limit API (429):</span> <span id="res429">0</span></div>
        <div class="stat"><span class="status-proxy-err">Proxy Error / Mati / Auth Fail:</span> <span id="resProxyErr">0</span></div>
        <div class="stat"><span>Diblokir Keamanan Lainnya:</span> <span id="resLainnya" style="font-weight: bold;">0</span></div>
        
        <div class="stat waktu-box">
            <span>⏱️ Waktu Eksekusi:</span> 
            <span id="resWaktu" style="font-weight: bold;">0 detik</span>
        </div>

        <div id="alertProxy" class="alert-proxy"></div>
    </div>
</div>

<script>
document.getElementById('testerForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmit');
    const resultBox = document.getElementById('resultBox');
    const alertProxy = document.getElementById('alertProxy');
    
    // Ubah status tombol & sembunyikan hasil sebelumnya
    btn.disabled = true; 
    btn.innerText = "⏳ Sedang Menembak Layer 7 (Proses ~1 Menit)..."; 
    resultBox.style.display = 'none';
    alertProxy.style.display = 'none';

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: new FormData(this) });
        const json = await res.json();
        
        if (json.error) {
            alert("⚠️ Error: " + json.error);
        } else {
            document.getElementById('resTotal').innerText = json.data.total;
            document.getElementById('res200').innerText = json.data.sukses_200;
            document.getElementById('res429').innerText = json.data.limit_429;
            document.getElementById('resProxyErr').innerText = json.data.proxy_error;
            document.getElementById('resLainnya').innerText = json.data.lainnya;
            
            document.getElementById('resWaktu').innerText = json.waktu_eksekusi + " detik";
            
            // Tampilkan info jika ada pesan error dari Proxy
            if (json.proxy_note && json.proxy_note !== "") {
                alertProxy.innerText = "⚠️ Catatan Proxy: " + json.proxy_note;
                alertProxy.style.display = 'block';
            }
            
            resultBox.style.display = 'block';
        }
    } catch (err) { 
        alert("⚠️ Error Jaringan atau Timeout: " + err.message); 
    } finally { 
        // -------------------------------------------------------------
        // COOLDOWN TIMEOUT FRONTEND (15 DETIK)
        // -------------------------------------------------------------
        let cooldownSec = 15;
        
        btn.disabled = true;
        
        const countdownInterval = setInterval(() => {
            btn.innerText = `⏳ Harap tunggu cooldown (${cooldownSec}s)...`;
            cooldownSec--;
            
            if (cooldownSec < 0) {
                clearInterval(countdownInterval);
                btn.disabled = false; 
                btn.innerText = "Tembak Layer 7!"; 
            }
        }, 1000);
    }
});
</script>
</body>
</html>
