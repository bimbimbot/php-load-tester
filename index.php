<?php
/*
 * ============================================================
 * SAFE WEB LOAD TESTER (MANDATORY PROXY, COOLDOWN, MAX 500 REQ, MAX 2000 MS)
 * START / PAUSE / RESUME / STOP
 * ============================================================
 *
 * Gunakan hanya untuk endpoint yang kamu miliki/berwenang uji.
 */

session_start();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    $action = $_POST['action'] ?? '';

    /*
     * Inisialisasi session test.
     */
    if ($action === 'start') {
        $url = trim($_POST['url'] ?? '');
        $total = (int)($_POST['total_requests'] ?? 50);
        $delay = (int)($_POST['delay_ms'] ?? 2000);
        
        $proxy = trim($_POST['proxy'] ?? '');
        $proxyUser = trim($_POST['proxy_user'] ?? '');
        $proxyPass = trim($_POST['proxy_pass'] ?? '');
        $referer = trim($_POST['referer'] ?? '');
        $origin = trim($_POST['origin'] ?? '');

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode([
                'ok' => false,
                'error' => 'URL tidak valid.'
            ]);
            exit;
        }

        // Validasi Wajib Proxy
        if (empty($proxy)) {
            echo json_encode([
                'ok' => false,
                'error' => 'Proxy wajib diisi! Harap masukkan IP:PORT proxy.'
            ]);
            exit;
        }

        // Batas aman backend (Max 500 Request & Max 2000 ms Delay)
        $total = max(1, min($total, 500));
        $delay = max(100, min($delay, 2000));

        $_SESSION['test'] = [
            'url' => $url,
            'total' => $total,
            'delay' => $delay,
            'proxy' => $proxy,
            'proxy_user' => $proxyUser,
            'proxy_pass' => $proxyPass,
            'referer' => $referer,
            'origin' => $origin,
            'completed' => 0,
            'success' => 0,
            'client_error' => 0,
            'server_error' => 0,
            'rate_limited' => 0,
            'proxy_failed' => 0,
            'other' => 0,
            'status' => 'running',
            'started_at' => microtime(true),
        ];

        session_write_close();

        echo json_encode([
            'ok' => true,
            'status' => 'running'
        ]);
        exit;
    }

    /*
     * Pause.
     */
    if ($action === 'pause') {
        if (isset($_SESSION['test'])) {
            $_SESSION['test']['status'] = 'paused';
        }

        session_write_close();

        echo json_encode([
            'ok' => true,
            'status' => 'paused'
        ]);
        exit;
    }

    /*
     * Resume.
     */
    if ($action === 'resume') {
        if (isset($_SESSION['test'])) {
            $_SESSION['test']['status'] = 'running';
        }

        session_write_close();

        echo json_encode([
            'ok' => true,
            'status' => 'running'
        ]);
        exit;
    }

    /*
     * Stop.
     */
    if ($action === 'stop') {
        if (isset($_SESSION['test'])) {
            $_SESSION['test']['status'] = 'stopped';
        }

        session_write_close();

        echo json_encode([
            'ok' => true,
            'status' => 'stopped'
        ]);
        exit;
    }

    /*
     * Status.
     */
    if ($action === 'status') {
        if (!isset($_SESSION['test'])) {
            session_write_close();
            echo json_encode([
                'ok' => true,
                'exists' => false
            ]);
            exit;
        }

        $testData = $_SESSION['test'];
        session_write_close();

        echo json_encode([
            'ok' => true,
            'exists' => true,
            'test' => $testData
        ]);
        exit;
    }

    /*
     * Satu request pengujian.
     */
    if ($action === 'request') {
        if (!isset($_SESSION['test'])) {
            session_write_close();
            echo json_encode([
                'ok' => false,
                'error' => 'Test belum dimulai.'
            ]);
            exit;
        }

        if ($_SESSION['test']['status'] !== 'running') {
            $testData = $_SESSION['test'];
            session_write_close();
            echo json_encode([
                'ok' => true,
                'skipped' => true,
                'status' => $testData['status'],
                'test' => $testData
            ]);
            exit;
        }

        $test =& $_SESSION['test'];

        if ($test['completed'] >= $test['total']) {
            $test['status'] = 'finished';
            $testData = $test;
            session_write_close();

            echo json_encode([
                'ok' => true,
                'status' => 'finished',
                'test' => $testData
            ]);
            exit;
        }

        $start = microtime(true);

        $ch = curl_init();

        // Kumpulan User-Agent Browser Manusia
        $humanUserAgents = [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2.1 Safari/605.1.15',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:123.0) Gecko/20100101 Firefox/123.0',
            'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36',
            'Mozilla/5.0 (iPhone; CPU iPhone OS 17_3_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.2 Mobile/15E148 Safari/604.1',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Edge/122.0.2365.66'
        ];
        $randomUserAgent = $humanUserAgents[array_rand($humanUserAgents)];

        $curlOptions = [
            CURLOPT_URL => $test['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPGET => true,
            CURLOPT_USERAGENT => $randomUserAgent,
        ];

        // Konfigurasi Proxy Wajib
        $curlOptions[CURLOPT_PROXY] = $test['proxy'];
        if (!empty($test['proxy_user'])) {
            $curlOptions[CURLOPT_PROXYUSERPWD] = $test['proxy_user'] . ':' . $test['proxy_pass'];
        }

        // Header Kustom Manusiawi
        $headers = [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: id-ID,id;q=0.9,en-US;q=0.8,en;q=0.7',
            'Cache-Control: no-cache',
            'Pragma: no-cache',
            'Sec-Ch-Ua: "Chromium";v="122", "Not(A:Brand";v="24", "Google Chrome";v="122"',
            'Sec-Ch-Ua-Mobile: ?0',
            'Sec-Ch-Ua-Platform: "Windows"',
            'Sec-Fetch-Dest: document',
            'Sec-Fetch-Mode: navigate',
            'Sec-Fetch-Site: cross-site',
            'Sec-Fetch-User: ?1',
            'Upgrade-Insecure-Requests: 1'
        ];

        if (!empty($test['referer'])) {
            $curlOptions[CURLOPT_REFERER] = $test['referer'];
        }

        if (!empty($test['origin'])) {
            $headers[] = 'Origin: ' . $test['origin'];
        }

        $curlOptions[CURLOPT_HTTPHEADER] = $headers;

        curl_setopt_array($ch, $curlOptions);

        curl_exec($ch);

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErrNo = curl_errno($ch);
        $curlError = curl_error($ch);

        curl_close($ch);

        $latency = round(
            (microtime(true) - $start) * 1000,
            2
        );

        $test['completed']++;
        $statusType = 'other';
        $statusMessage = '';

        // Deteksi Proxy Mati / Masalah Koneksi Proxy
        $isProxyError = false;
        if ($curlErrNo == 7 || $curlErrNo == 5 || $curlErrNo == 6 || $curlErrNo == 28 || strpos(strtolower($curlError), 'proxy') !== false) {
            $isProxyError = true;
        }

        if ($isProxyError || ($curlErrNo !== 0 && $httpCode === 0)) {
            $test['proxy_failed']++;
            $statusType = 'proxy_failed';
            $statusMessage = "Proxy Mati / Gagal Terhubung: " . ($curlError ?: "Network Error (Errno: $curlErrNo)");
        } elseif ($httpCode === 200) {
            $test['success']++;
            $statusType = 'success';
            $statusMessage = "OK 200";
        } elseif ($httpCode === 429) {
            $test['rate_limited']++;
            $statusType = 'rate_limited';
            $statusMessage = "Rate Limited 429 (Terlalu Banyak Request)";
        } elseif ($httpCode >= 400 && $httpCode < 500) {
            $test['client_error']++;
            $statusType = 'client_error';
            $statusMessage = "Client Error {$httpCode}";
        } elseif ($httpCode >= 500) {
            $test['server_error']++;
            $statusType = 'server_error';
            $statusMessage = "Server Error {$httpCode}";
        } else {
            $test['other']++;
            $statusType = 'other';
            $statusMessage = "HTTP Code {$httpCode}";
        }

        if ($test['completed'] >= $test['total']) {
            $test['status'] = 'finished';
        }

        $testData = $test;
        session_write_close();

        echo json_encode([
            'ok' => true,
            'status' => $testData['status'],
            'http_code' => $httpCode,
            'latency' => $latency,
            'status_type' => $statusType,
            'status_message' => $statusMessage,
            'test' => $testData
        ]);

        exit;
    }

    session_write_close();
    echo json_encode([
        'ok' => false,
        'error' => 'Action tidak dikenal.'
    ]);

    exit;
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Web Load Tester (Mandatory Proxy & Cooldown)</title>

<style>

:root {
    --bg: #080b12;
    --card: rgba(17, 24, 39, .82);
    --border: rgba(255,255,255,.08);
    --primary: #6366f1;
    --primary2: #4f46e5;
    --text: #f8fafc;
    --muted: #94a3b8;
    --success: #34d399;
    --danger: #f87171;
    --warning: #fbbf24;
    --info: #38bdf8;
}

* {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
}

body {
    min-height: 100vh;
    background:
        radial-gradient(
            circle at 10% 20%,
            rgba(99,102,241,.15),
            transparent 35%
        ),
        radial-gradient(
            circle at 90% 80%,
            rgba(14,165,233,.10),
            transparent 35%
        ),
        var(--bg);

    color: var(--text);
    font-family:
        Inter,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        "Segoe UI",
        sans-serif;

    display: flex;
    align-items: center;
    justify-content: center;

    padding: 25px;
}

.container {
    width: 100%;
    max-width: 700px;

    background: var(--card);

    border: 1px solid var(--border);

    border-radius: 22px;

    padding: 30px;

    box-shadow:
        0 30px 80px rgba(0,0,0,.55);
}

.header {
    display: flex;
    align-items: center;
    gap: 12px;

    margin-bottom: 25px;
}

.icon {
    font-size: 30px;
}

.header h1 {
    font-size: 22px;
}

.header p {
    margin-top: 4px;
    color: var(--muted);
    font-size: 13px;
}

.form-group {
    margin-bottom: 18px;
}

label {
    display: block;

    margin-bottom: 7px;

    color: var(--muted);

    font-size: 13px;

    font-weight: 600;
}

input {
    width: 100%;

    padding: 13px 14px;

    border-radius: 11px;

    border: 1px solid var(--border);

    background: rgba(15,23,42,.75);

    color: var(--text);

    outline: none;

    transition: .2s;
}

input:focus {
    border-color: var(--primary);

    box-shadow:
        0 0 0 3px rgba(99,102,241,.15);
}

.row {
    display: flex;
    gap: 15px;
}

.row > div {
    flex: 1;
}

.buttons {
    display: grid;

    grid-template-columns:
        1fr 1fr 1fr;

    gap: 10px;

    margin-top: 20px;
}

button {
    border: 0;

    border-radius: 11px;

    padding: 13px;

    color: white;

    font-weight: 700;

    cursor: pointer;

    transition: .2s;
}

button:hover {
    transform: translateY(-1px);
}

button:disabled {
    opacity: .4;

    cursor: not-allowed;

    transform: none;
}

.start {
    background:
        linear-gradient(
            135deg,
            var(--primary),
            var(--primary2)
        );
}

.pause {
    background: #334155;
}

.stop {
    background: #991b1b;
}

.status {
    margin-top: 25px;

    display: flex;

    justify-content: space-between;

    align-items: center;

    padding: 12px 15px;

    border-radius: 11px;

    background: rgba(15,23,42,.7);

    border: 1px solid var(--border);
}

.status-label {
    color: var(--muted);

    font-size: 13px;
}

#statusText {
    font-weight: 700;
}

.progress-wrap {
    margin-top: 18px;
}

.progress-info {
    display: flex;

    justify-content: space-between;

    margin-bottom: 8px;

    font-size: 13px;

    color: var(--muted);
}

.progress {
    height: 10px;

    background: #1e293b;

    border-radius: 99px;

    overflow: hidden;
}

.progress-bar {
    width: 0%;

    height: 100%;

    background:
        linear-gradient(
            90deg,
            var(--primary),
            #22d3ee
        );

    transition: width .2s;
}

.stats {
    margin-top: 22px;

    display: grid;

    grid-template-columns:
        repeat(3, 1fr);

    gap: 10px;
}

.stat {
    padding: 15px;

    border-radius: 12px;

    background: rgba(15,23,42,.6);

    border: 1px solid var(--border);
}

.stat-title {
    font-size: 12px;

    color: var(--muted);

    margin-bottom: 5px;
}

.stat-value {
    font-size: 18px;

    font-weight: 800;
}

.success {
    color: var(--success);
}

.error {
    color: var(--danger);
}

.warning {
    color: var(--warning);
}

.info {
    color: var(--info);
}

.log {
    margin-top: 20px;

    background: #05070c;

    border: 1px solid var(--border);

    border-radius: 12px;

    padding: 13px;

    height: 150px;

    overflow-y: auto;

    font-family: monospace;

    font-size: 12px;

    color: #a7f3d0;
}

@media(max-width:550px) {

    .container {
        padding: 20px;
    }

    .row {
        flex-direction: column;

        gap: 0;
    }

    .buttons {
        grid-template-columns: 1fr;
    }

    .stats {
        grid-template-columns: repeat(2, 1fr);
    }

}

</style>
</head>

<body>

<div class="container">

    <div class="header">

        <div class="icon">
            🧪
        </div>

        <div>
            <h1>Web Load Tester</h1>

            <p>
                Proxy Wajib, Cooldown Delay, Max 500 Req & Max 2000 ms
            </p>
        </div>

    </div>


    <form id="testerForm">

        <div class="form-group">
            <label>URL Endpoint</label>
            <input type="url" id="url" placeholder="https://domain-kamu.com/api/health" required>
        </div>

        <div class="row">
            <div class="form-group">
                <label>Total Request (Maks: 500)</label>
                <input type="number" id="total" value="50" min="1" max="500" required>
            </div>
            <div class="form-group">
                <label>Delay / Cooldown (ms) (Maks: 2000)</label>
                <input type="number" id="delay" value="2000" min="100" max="2000" required>
            </div>
        </div>

        <!-- Proxy Wajib -->
        <div class="form-group">
            <label>Proxy **(Wajib)** - Cth: 123.45.67.89:8080</label>
            <input type="text" id="proxy" placeholder="IP:PORT atau host:port" required>
        </div>

        <div class="row">
            <div class="form-group">
                <label>Proxy Username (Opsional)</label>
                <input type="text" id="proxyUser" placeholder="Username proxy">
            </div>
            <div class="form-group">
                <label>Proxy Password (Opsional)</label>
                <input type="password" id="proxyPass" placeholder="Password proxy">
            </div>
        </div>

        <div class="row">
            <div class="form-group">
                <label>Referer Header (Opsional)</label>
                <input type="url" id="referer" placeholder="https://domain-asal.com">
            </div>
            <div class="form-group">
                <label>Origin Header (Opsional)</label>
                <input type="url" id="origin" placeholder="https://domain-asal.com">
            </div>
        </div>

        <div class="buttons">
            <button type="button" class="start" id="startBtn">▶ START</button>
            <button type="button" class="pause" id="pauseBtn" disabled>⏸ PAUSE</button>
            <button type="button" class="stop" id="stopBtn" disabled>⏹ STOP</button>
        </div>

    </form>


    <div class="status">
        <span class="status-label">STATUS</span>
        <span id="statusText">IDLE</span>
    </div>


    <div class="progress-wrap">
        <div class="progress-info">
            <span id="cooldownInfo">Progress</span>
            <span id="progressText">0 / 0</span>
        </div>
        <div class="progress">
            <div class="progress-bar" id="progressBar"></div>
        </div>
    </div>


    <div class="stats">
        <div class="stat">
            <div class="stat-title">Total</div>
            <div class="stat-value" id="resTotal">0</div>
        </div>
        <div class="stat">
            <div class="stat-title">Success (200)</div>
            <div class="stat-value success" id="resSuccess">0</div>
        </div>
        <div class="stat">
            <div class="stat-title">Rate Limited (429)</div>
            <div class="stat-value warning" id="resRateLimited">0</div>
        </div>
        <div class="stat">
            <div class="stat-title">Proxy Mati / Gagal</div>
            <div class="stat-value error" id="resProxyFailed">0</div>
        </div>
        <div class="stat">
            <div class="stat-title">Client Error (4xx)</div>
            <div class="stat-value warning" id="resClient">0</div>
        </div>
        <div class="stat">
            <div class="stat-title">Server Error (5xx)</div>
            <div class="stat-value error" id="resServer">0</div>
        </div>
    </div>


    <div class="log" id="log">
        Tester siap digunakan...
    </div>

</div>


<script>

const startBtn = document.getElementById('startBtn');
const pauseBtn = document.getElementById('pauseBtn');
const stopBtn = document.getElementById('stopBtn');
const statusText = document.getElementById('statusText');
const progressText = document.getElementById('progressText');
const cooldownInfo = document.getElementById('cooldownInfo');
const progressBar = document.getElementById('progressBar');
const resTotal = document.getElementById('resTotal');
const resSuccess = document.getElementById('resSuccess');
const resRateLimited = document.getElementById('resRateLimited');
const resProxyFailed = document.getElementById('resProxyFailed');
const resClient = document.getElementById('resClient');
const resServer = document.getElementById('resServer');
const logBox = document.getElementById('log');

let running = false;
let stopped = false;
let isPausedState = false;


async function post(action, data = {}) {
    const form = new FormData();
    form.append('action', action);

    for (const key in data) {
        form.append(key, data[key]);
    }

    const response = await fetch(
        window.location.href,
        {
            method: 'POST',
            body: form,
            cache: 'no-store'
        }
    );

    return await response.json();
}


function log(message, type = 'normal') {
    const time = new Date().toLocaleTimeString();
    let colorStyle = '#a7f3d0';

    if (type === 'success') colorStyle = '#34d399';
    else if (type === 'warning') colorStyle = '#fbbf24';
    else if (type === 'error') colorStyle = '#f87171';

    logBox.innerHTML += `<div style="color: ${colorStyle}">[${time}] ${message}</div>`;
    logBox.scrollTop = logBox.scrollHeight;
}


function updateUI(test) {
    if (!test) return;

    const total = Number(test.total);
    const completed = Number(test.completed);
    const percent = total > 0 ? (completed / total) * 100 : 0;

    progressBar.style.width = percent + '%';
    progressText.innerText = `${completed} / ${total}`;
    resTotal.innerText = completed;
    resSuccess.innerText = test.success;
    resRateLimited.innerText = test.rate_limited;
    resProxyFailed.innerText = test.proxy_failed;
    resClient.innerText = test.client_error;
    resServer.innerText = test.server_error;
    statusText.innerText = String(test.status).toUpperCase();

    if (test.status === 'running') {
        statusText.style.color = '#34d399';
    } else if (test.status === 'paused') {
        statusText.style.color = '#fbbf24';
    } else if (test.status === 'stopped') {
        statusText.style.color = '#f87171';
    } else {
        statusText.style.color = '#94a3b8';
    }
}


startBtn.addEventListener('click', async () => {
    let total = parseInt(document.getElementById('total').value);
    let delay = parseInt(document.getElementById('delay').value);
    const url = document.getElementById('url').value.trim();
    const proxy = document.getElementById('proxy').value.trim();
    const proxyUser = document.getElementById('proxyUser').value.trim();
    const proxyPass = document.getElementById('proxyPass').value.trim();
    const referer = document.getElementById('referer').value.trim();
    const origin = document.getElementById('origin').value.trim();

    if (!url) {
        alert('Masukkan URL endpoint.');
        return;
    }

    if (!proxy) {
        alert('Proxy wajib diisi!');
        return;
    }

    // Validasi Front-end Maksimal Request & Delay (ms)
    if (total > 500) {
        alert('Maksimal Total Request adalah 500.');
        document.getElementById('total').value = 500;
        total = 500;
    }

    if (delay > 2000) {
        alert('Maksimal Delay / Cooldown adalah 2000 ms (2 detik).');
        document.getElementById('delay').value = 2000;
        delay = 2000;
    }

    try {
        const result = await post('start', {
            url: url,
            total_requests: total,
            delay_ms: delay,
            proxy: proxy,
            proxy_user: proxyUser,
            proxy_pass: proxyPass,
            referer: referer,
            origin: origin
        });

        if (!result.ok) {
            alert(result.error);
            return;
        }

        running = true;
        stopped = false;
        isPausedState = false;

        startBtn.disabled = true;
        pauseBtn.disabled = false;
        stopBtn.disabled = false;
        pauseBtn.innerText = '⏸ PAUSE';

        logBox.innerHTML = '';
        log('Test dimulai dengan cooldown aman.');

        runLoop();

    } catch (error) {
        alert('Gagal memulai: ' + error.message);
    }
});


pauseBtn.addEventListener('click', async () => {
    try {
        if (!isPausedState) {
            const result = await post('pause');
            if (result.ok) {
                running = false;
                isPausedState = true;
                pauseBtn.innerText = '▶ RESUME';
                log('Test dijeda.', 'warning');
                updateUI(result.test);
            }
        } else {
            const result = await post('resume');
            if (result.ok) {
                running = true;
                isPausedState = false;
                pauseBtn.innerText = '⏸ PAUSE';
                log('Test dilanjutkan.');
                updateUI(result.test);
                runLoop();
            }
        }
    } catch (error) {
        log('Toggle Pause/Resume error: ' + error.message, 'error');
    }
});


stopBtn.addEventListener('click', async () => {
    stopped = true;
    running = false;

    try {
        const result = await post('stop');
        if (result.ok) {
            updateUI(result.test);
            log('Test dihentikan.', 'error');
        }
    } catch (error) {
        log('Stop error: ' + error.message, 'error');
    }

    startBtn.disabled = false;
    pauseBtn.disabled = true;
    stopBtn.disabled = true;
    pauseBtn.innerText = '⏸ PAUSE';
    isPausedState = false;
    cooldownInfo.innerText = 'Progress';
});


async function runLoop() {
    if (!running || stopped) {
        return;
    }

    try {
        const result = await post('request');

        if (!result.ok) {
            log('Request error: ' + result.error, 'error');
            running = false;
            return;
        }

        if (result.skipped) {
            updateUI(result.test);
            return;
        }

        updateUI(result.test);

        let logType = 'normal';
        if (result.status_type === 'success') logType = 'success';
        else if (result.status_type === 'rate_limited' || result.status_type === 'client_error') logType = 'warning';
        else if (result.status_type === 'proxy_failed' || result.status_type === 'server_error') logType = 'error';

        log(
            `Request #${result.test.completed} → [${result.status_message}] (${result.latency} ms)`,
            logType
        );

        if (result.status === 'finished') {
            running = false;
            startBtn.disabled = false;
            pauseBtn.disabled = true;
            stopBtn.disabled = true;
            pauseBtn.innerText = '⏸ PAUSE';
            isPausedState = false;
            cooldownInfo.innerText = 'Progress';
            log('Test selesai.', 'success');
            return;
        }

        if (result.status === 'stopped') {
            running = false;
            startBtn.disabled = false;
            pauseBtn.disabled = true;
            stopBtn.disabled = true;
            pauseBtn.innerText = '⏸ PAUSE';
            isPausedState = false;
            cooldownInfo.innerText = 'Progress';
            return;
        }

        if (result.status === 'paused') {
            running = false;
            pauseBtn.disabled = false;
            return;
        }

        let delay = Number(document.getElementById('delay').value);
        if (delay > 2000) delay = 2000;

        // Tampilkan Hitung Mundur Cooldown (Cooldown Button / Text Indicator sebelum lanjut)
        let remainingTime = delay;
        const intervalStep = 100;

        const cooldownTimer = setInterval(() => {
            if (!running || stopped || isPausedState) {
                clearInterval(cooldownTimer);
                return;
            }

            remainingTime -= intervalStep;
            if (remainingTime > 0) {
                cooldownInfo.innerText = `Cooldown (Tunggu ${(remainingTime / 1000).toFixed(1)}s)...`;
            } else {
                clearInterval(cooldownTimer);
                cooldownInfo.innerText = 'Progress';
            }
        }, intervalStep);

        setTimeout(runLoop, delay);

    } catch (error) {
        log('Network error: ' + error.message, 'error');
        running = false;
        startBtn.disabled = false;
        pauseBtn.disabled = true;
        stopBtn.disabled = true;
        cooldownInfo.innerText = 'Progress';
    }
}

</script>

</body>
</html>
