<?php
/*
 * ============================================================
 * SAFE WEB LOAD TESTER
 * START / PAUSE / RESUME / STOP
 * ============================================================
 *
 * Gunakan hanya untuk endpoint yang kamu miliki/berwenang uji.
 * Tidak menggunakan proxy rotation atau flooding burst.
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
        $delay = (int)($_POST['delay_ms'] ?? 250);

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode([
                'ok' => false,
                'error' => 'URL tidak valid.'
            ]);
            exit;
        }

        // Batas aman untuk browser/PHP tester sederhana.
        $total = max(1, min($total, 200));
        $delay = max(100, min($delay, 5000));

        $_SESSION['test'] = [
            'url' => $url,
            'total' => $total,
            'delay' => $delay,
            'completed' => 0,
            'success' => 0,
            'client_error' => 0,
            'server_error' => 0,
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

        curl_setopt_array($ch, [
            CURLOPT_URL => $test['url'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPGET => true,
            CURLOPT_USERAGENT => 'Safe-Web-Load-Tester/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: */*',
                'Cache-Control: no-cache'
            ]
        ]);

        curl_exec($ch);

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);

        curl_close($ch);

        $latency = round(
            (microtime(true) - $start) * 1000,
            2
        );

        $test['completed']++;

        if ($curlError) {
            $test['other']++;
        } elseif ($httpCode >= 200 && $httpCode < 300) {
            $test['success']++;
        } elseif ($httpCode >= 400 && $httpCode < 500) {
            $test['client_error']++;
        } elseif ($httpCode >= 500) {
            $test['server_error']++;
        } else {
            $test['other']++;
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

<title>7 Layer API</title>

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
    max-width: 650px;

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
        repeat(2, 1fr);

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
    font-size: 20px;

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

.log {
    margin-top: 20px;

    background: #05070c;

    border: 1px solid var(--border);

    border-radius: 12px;

    padding: 13px;

    height: 130px;

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
                Controlled endpoint testing
            </p>
        </div>

    </div>


    <form id="testerForm">

        <div class="form-group">

            <label>
                URL Endpoint
            </label>

            <input
                type="url"
                id="url"
                placeholder="https://domain-kamu.com/api/health"
                required
            >

        </div>


        <div class="row">

            <div class="form-group">

                <label>
                    Total Request
                </label>

                <input
                    type="number"
                    id="total"
                    value="50"
                    min="1"
                    max="200"
                    required
                >

            </div>


            <div class="form-group">

                <label>
                    Delay (ms)
                </label>

                <input
                    type="number"
                    id="delay"
                    value="250"
                    min="100"
                    max="5000"
                    required
                >

            </div>

        </div>


        <div class="buttons">

            <button
                type="button"
                class="start"
                id="startBtn"
            >
                ▶ START
            </button>

            <button
                type="button"
                class="pause"
                id="pauseBtn"
                disabled
            >
                ⏸ PAUSE
            </button>

            <button
                type="button"
                class="stop"
                id="stopBtn"
                disabled
            >
                ⏹ STOP
            </button>

        </div>

    </form>


    <div class="status">

        <span class="status-label">
            STATUS
        </span>

        <span id="statusText">
            IDLE
        </span>

    </div>


    <div class="progress-wrap">

        <div class="progress-info">

            <span>
                Progress
            </span>

            <span id="progressText">
                0 / 0
            </span>

        </div>

        <div class="progress">

            <div
                class="progress-bar"
                id="progressBar"
            ></div>

        </div>

    </div>


    <div class="stats">

        <div class="stat">

            <div class="stat-title">
                Total
            </div>

            <div
                class="stat-value"
                id="resTotal"
            >
                0
            </div>

        </div>


        <div class="stat">

            <div class="stat-title">
                Success 2xx
            </div>

            <div
                class="stat-value success"
                id="resSuccess"
            >
                0
            </div>

        </div>


        <div class="stat">

            <div class="stat-title">
                Client Error 4xx
            </div>

            <div
                class="stat-value warning"
                id="resClient"
            >
                0
            </div>

        </div>


        <div class="stat">

            <div class="stat-title">
                Server Error 5xx
            </div>

            <div
                class="stat-value error"
                id="resServer"
            >
                0
            </div>

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
const progressBar = document.getElementById('progressBar');
const resTotal = document.getElementById('resTotal');
const resSuccess = document.getElementById('resSuccess');
const resClient = document.getElementById('resClient');
const resServer = document.getElementById('resServer');
const logBox = document.getElementById('log');

let running = false;
let stopped = false;
let isPausedState = false;


/*
 * ------------------------------------------------------------
 * Helper POST
 * ------------------------------------------------------------
 */

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


/*
 * ------------------------------------------------------------
 * Log
 * ------------------------------------------------------------
 */

function log(message) {
    const time = new Date().toLocaleTimeString();
    logBox.innerHTML += `<div>[${time}] ${message}</div>`;
    logBox.scrollTop = logBox.scrollHeight;
}


/*
 * ------------------------------------------------------------
 * Update UI
 * ------------------------------------------------------------
 */

function updateUI(test) {
    if (!test) return;

    const total = Number(test.total);
    const completed = Number(test.completed);
    const percent = total > 0 ? (completed / total) * 100 : 0;

    progressBar.style.width = percent + '%';
    progressText.innerText = `${completed} / ${total}`;
    resTotal.innerText = completed;
    resSuccess.innerText = test.success;
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


/*
 * ------------------------------------------------------------
 * START
 * ------------------------------------------------------------
 */

startBtn.addEventListener('click', async () => {
    const url = document.getElementById('url').value.trim();
    const total = document.getElementById('total').value;
    const delay = document.getElementById('delay').value;

    if (!url) {
        alert('Masukkan URL endpoint.');
        return;
    }

    try {
        const result = await post('start', {
            url: url,
            total_requests: total,
            delay_ms: delay
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
        log('Test dimulai.');

        runLoop();

    } catch (error) {
        alert('Gagal memulai: ' + error.message);
    }
});


/*
 * ------------------------------------------------------------
 * PAUSE / RESUME TOGGLE
 * ------------------------------------------------------------
 */

pauseBtn.addEventListener('click', async () => {
    try {
        if (!isPausedState) {
            // Aksi Pause
            const result = await post('pause');
            if (result.ok) {
                running = false;
                isPausedState = true;
                pauseBtn.innerText = '▶ RESUME';
                log('Test dijeda.');
                updateUI(result.test);
            }
        } else {
            // Aksi Resume
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
        log('Toggle Pause/Resume error: ' + error.message);
    }
});


/*
 * ------------------------------------------------------------
 * STOP
 * ------------------------------------------------------------
 */

stopBtn.addEventListener('click', async () => {
    stopped = true;
    running = false;

    try {
        const result = await post('stop');
        if (result.ok) {
            updateUI(result.test);
            log('Test dihentikan.');
        }
    } catch (error) {
        log('Stop error: ' + error.message);
    }

    startBtn.disabled = false;
    pauseBtn.disabled = true;
    stopBtn.disabled = true;
    pauseBtn.innerText = '⏸ PAUSE';
    isPausedState = false;
});


/*
 * ------------------------------------------------------------
 * LOOP
 * ------------------------------------------------------------
 */

async function runLoop() {
    if (!running || stopped) {
        return;
    }

    try {
        const result = await post('request');

        if (!result.ok) {
            log('Request error: ' + result.error);
            running = false;
            return;
        }

        if (result.skipped) {
            updateUI(result.test);
            return;
        }

        updateUI(result.test);

        if (result.http_code) {
            log(
                `Request #${result.test.completed} → ` +
                `${result.http_code} ` +
                `(${result.latency} ms)`
            );
        } else {
            log(`Request #${result.test.completed} → network error`);
        }

        if (result.status === 'finished') {
            running = false;
            startBtn.disabled = false;
            pauseBtn.disabled = true;
            stopBtn.disabled = true;
            pauseBtn.innerText = '⏸ PAUSE';
            isPausedState = false;
            log('Test selesai.');
            return;
        }

        if (result.status === 'stopped') {
            running = false;
            startBtn.disabled = false;
            pauseBtn.disabled = true;
            stopBtn.disabled = true;
            pauseBtn.innerText = '⏸ PAUSE';
            isPausedState = false;
            return;
        }

        if (result.status === 'paused') {
            running = false;
            pauseBtn.disabled = false;
            return;
        }

        const delay = Number(document.getElementById('delay').value);
        setTimeout(runLoop, delay);

    } catch (error) {
        log('Network error: ' + error.message);
        running = false;
        startBtn.disabled = false;
        pauseBtn.disabled = true;
        stopBtn.disabled = true;
    }
}

</script>

</body>
</html>
