<?php
// ==========================================
// BAGIAN BACKEND (PHP API)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $url = $_POST['url'] ?? '';
    $total_requests = (int)($_POST['total_requests'] ?? 10);
    $concurrent = (int)($_POST['concurrent'] ?? 2); 
    
    // Konfigurasi Proxy dari form
    $proxy = trim($_POST['proxy'] ?? '');
    $proxy_auth = trim($_POST['proxy_auth'] ?? ''); // Format: username:password (jika proxy butuh login)
    
    if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
        echo json_encode(['error' => 'URL tidak valid.']);
        exit;
    }

    $results = ['sukses_200' => 0, 'limit_429' => 0, 'lainnya' => 0, 'total' => 0];
    $ch_list = [];

    // Header penyamaran Browser Manusia
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
        "Sec-Fetch-Site: none",
        "Sec-Fetch-User: ?1",
        "Upgrade-Insecure-Requests: 1"
    ];

    for ($i = 0; $i < $total_requests; $i++) {
        $ch = curl_init();
        
        // Tambahkan parameter acak agar tidak terkena cache (opsional untuk verifikasi)
        $target_url = $url . (strpos($url, '?') !== false ? '&' : '?') . 'test_id=' . uniqid();
        
        curl_setopt($ch, CURLOPT_URL, $target_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20); // Waktu diperpanjang karena proxy kadang lambat
        
        // Memalsukan User-Agent
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $human_headers);
        curl_setopt($ch, CURLOPT_ENCODING, ""); 
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 

        // -----------------------------------------
        // INJEKSI PROXY JIKA DIISI OLEH USER
        // -----------------------------------------
        if (!empty($proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
            // Jika proxy pakai password (misal proxy berbayar/premium)
            if (!empty($proxy_auth)) {
                curl_setopt($ch, CURLOPT_PROXYUSERPWD, $proxy_auth);
            }
        }

        $ch_list[] = $ch;
    }

    $batches = array_chunk($ch_list, $concurrent);

    foreach ($batches as $batch) {
        $mh = curl_multi_init();
        foreach ($batch as $ch) { curl_multi_add_handle($mh, $ch); }

        $running = null;
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh);
        } while ($running > 0);

        foreach ($batch as $ch) {
            $info = curl_getinfo($ch);
            $http_code = $info['http_code'];
            
            if ($http_code == 200) { $results['sukses_200']++; }
            elseif ($http_code == 429) { $results['limit_429']++; }
            else { $results['lainnya']++; }
            $results['total']++;
            
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);
    }

    echo json_encode(['status' => 'success', 'data' => $results]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Tester w/ Proxy</title>
    <style>
        body { font-family: system-ui; background: #f3f4f6; padding: 2rem; }
        .container { max-width: 600px; margin: auto; background: white; padding: 2rem; border-radius: 8px; box-shadow: 0 4px 6px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 1rem; }
        label { display: block; margin-bottom: 0.5rem; font-weight: bold; }
        input { width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { background: #3b82f6; color: white; border: none; padding: 0.75rem; width: 100%; font-weight: bold; cursor: pointer; border-radius: 4px;}
        button:disabled { background: #9ca3af; }
        .result-box { margin-top: 1.5rem; padding: 1rem; background: #f8fafc; border: 1px solid #e2e8f0; display: none; }
        .stat { display: flex; justify-content: space-between; margin-bottom: 0.5rem; }
        .status-200 { color: #16a34a; font-weight: bold; }
        .status-429 { color: #dc2626; font-weight: bold; }
    </style>
</head>
<body>
<div class="container">
    <h2>🚀 Proxy API Tester</h2>
    <form id="testerForm">
        <div class="form-group">
            <label>URL Endpoint API Kamu (InfinityFree)</label>
            <input type="url" name="url" placeholder="http://domain-kamu.epizy.com/api" required>
        </div>
        <div class="form-group" style="display: flex; gap: 10px;">
            <div style="flex: 1;">
                <label>Total Request</label>
                <input type="number" name="total_requests" value="5" min="1">
            </div>
            <div style="flex: 1;">
                <label>Concurrent</label>
                <input type="number" name="concurrent" value="1" min="1">
            </div>
        </div>
        <div class="form-group">
            <label>Proxy (Opsional) - IP:Port</label>
            <input type="text" name="proxy" placeholder="Contoh: 192.168.1.1:8080">
            <small style="color: #666;">Kosongkan jika ingin mengetes langsung dari IP Railway.</small>
        </div>
        <div class="form-group">
            <label>Proxy Auth (Jika ada)</label>
            <input type="text" name="proxy_auth" placeholder="username:password">
        </div>
        <button type="submit" id="btnSubmit">Tembak API!</button>
    </form>

    <div id="resultBox" class="result-box">
        <div class="stat"><span>Total Terkirim:</span> <span id="resTotal">0</span></div>
        <div class="stat"><span class="status-200">Masuk / Sukses (200):</span> <span id="res200">0</span></div>
        <div class="stat"><span class="status-429">Kena Rate Limit (429):</span> <span id="res429">0</span></div>
        <div class="stat"><span>Diblokir Keamanan / Bot (Lainnya):</span> <span id="resLainnya">0</span></div>
    </div>
</div>

<script>
document.getElementById('testerForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const btn = document.getElementById('btnSubmit');
    const resultBox = document.getElementById('resultBox');
    btn.disabled = true; btn.innerText = "Mengirim..."; resultBox.style.display = 'none';

    try {
        const res = await fetch(window.location.href, { method: 'POST', body: new FormData(this) });
        const json = await res.json();
        if (json.error) alert(json.error);
        else {
            document.getElementById('resTotal').innerText = json.data.total;
            document.getElementById('res200').innerText = json.data.sukses_200;
            document.getElementById('res429').innerText = json.data.limit_429;
            document.getElementById('resLainnya').innerText = json.data.lainnya;
            resultBox.style.display = 'block';
        }
    } catch (err) { alert("Error jaringan/timeout: " + err); } 
    finally { btn.disabled = false; btn.innerText = "Tembak API!"; }
});
</script>
</body>
</html>
