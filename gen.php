<?php
// gen.php - index.php?cmd=gen 에서 include하는 페이지
// index.php에서 session_start()와 $conn(mysqli) 연결이 완료되어 있어야 합니다.

if (session_status() !== PHP_SESSION_ACTIVE) {
    echo '<div class="alert alert-danger">index.php를 통해 접속해 주세요.</div>';
    return;
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">데이터베이스 연결이 없습니다.</div>';
    return;
}

if (!isset($_SESSION['gen_csrf'])) {
    $_SESSION['gen_csrf'] = bin2hex(random_bytes(32));
}

$genResult = ['status' => 'idle', 'message' => '', 'temp' => '', 'hum' => '', 'time' => ''];

// 비동기 요청도 index.php를 거쳐 이 파일에 도달합니다.
// index.php가 이미 HTML 출력을 시작하므로 JSON 대신 페이지 내부 결과 요소로 응답합니다.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['gen_action'] ?? '') === 'insert') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!is_string($submittedToken) || !hash_equals($_SESSION['gen_csrf'], $submittedToken)) {
        $genResult['status'] = 'error';
        $genResult['message'] = '요청 검증에 실패했습니다.';
    } else {
        try {
            // 25.00~26.00도, 70.00~71.00% (소수점 둘째 자리)
            $temp = random_int(2500, 2600) / 100;
            $hum  = random_int(7000, 7100) / 100;
            $stmt = $conn->prepare('INSERT INTO iot (temp, hum) VALUES (?, ?)');
            $stmt->bind_param('dd', $temp, $hum);
            $stmt->execute();
            $stmt->close();
            $genResult = [
                'status' => 'ok',
                'message' => '저장 완료',
                'temp' => number_format($temp, 2, '.', ''),
                'hum' => number_format($hum, 2, '.', ''),
                'time' => date('Y-m-d H:i:s'),
            ];
        } catch (Throwable $e) {
            error_log('gen.php iot insert failed: ' . $e->getMessage());
            $genResult['status'] = 'error';
            $genResult['message'] = 'DB 저장에 실패했습니다.';
        }
    }
}
?>
<!-- 응답을 fetch()에서 추출하기 위한 요소 -->
<div id="gen-response"
     data-status="<?= htmlspecialchars($genResult['status'], ENT_QUOTES, 'UTF-8') ?>"
     data-message="<?= htmlspecialchars($genResult['message'], ENT_QUOTES, 'UTF-8') ?>"
     data-temp="<?= htmlspecialchars($genResult['temp'], ENT_QUOTES, 'UTF-8') ?>"
     data-hum="<?= htmlspecialchars($genResult['hum'], ENT_QUOTES, 'UTF-8') ?>"
     data-time="<?= htmlspecialchars($genResult['time'], ENT_QUOTES, 'UTF-8') ?>" hidden></div>

<div class="container py-3" id="gen-app">
  <div class="card border-0 shadow-sm">
    <div class="card-header text-white" style="background-color:#46765c;">
      <h4 class="mb-0">IoT 온도·습도 데이터 생성</h4>
    </div>
    <div class="card-body">
      <p class="text-muted mb-2">3초마다 온도 25~26℃, 습도 70~71% 범위의 값을 생성해 <code>iot</code> 테이블에 저장합니다.</p>
      <div class="d-flex gap-2 align-items-center mb-3">
        <button type="button" id="gen-start" class="btn btn-success">시작</button>
        <button type="button" id="gen-stop" class="btn btn-outline-secondary" disabled>중지</button>
        <span id="gen-state" class="ms-2 text-muted">대기 중</span>
      </div>
      <div class="row g-3 mb-3">
        <div class="col-md-4"><div class="border rounded p-3">최근 온도 <div class="fs-4 fw-semibold" id="gen-temp">-</div></div></div>
        <div class="col-md-4"><div class="border rounded p-3">최근 습도 <div class="fs-4 fw-semibold" id="gen-hum">-</div></div></div>
        <div class="col-md-4"><div class="border rounded p-3">저장 횟수(현재 화면) <div class="fs-4 fw-semibold" id="gen-count">0</div></div></div>
      </div>
      <div class="small text-muted mb-2" id="gen-latest">아직 저장한 데이터가 없습니다.</div>
      <div class="alert alert-danger d-none" id="gen-error" role="alert"></div>
      <div class="table-responsive"><table class="table table-sm table-striped align-middle">
        <thead><tr><th>시간</th><th>온도 (℃)</th><th>습도 (%)</th></tr></thead>
        <tbody id="gen-history"><tr id="gen-empty"><td colspan="3" class="text-muted">시작 버튼을 누르면 기록됩니다.</td></tr></tbody>
      </table></div>
    </div>
  </div>
</div>

<script>
(() => {
  // POST 응답은 index.php 전체 HTML이므로 DOMParser로 결과 요소만 꺼냅니다.
  const app = document.getElementById('gen-app');
  if (!app) return;
  const start = app.querySelector('#gen-start');
  const stop = app.querySelector('#gen-stop');
  const state = app.querySelector('#gen-state');
  const error = app.querySelector('#gen-error');
  const rows = app.querySelector('#gen-history');
  let active = false;
  let timer = null;
  let count = 0;
  let controller = null;
  const csrf = <?= json_encode($_SESSION['gen_csrf'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

  const setState = (message) => { state.textContent = message; };
  const showError = (message) => {
    error.textContent = message;
    error.classList.remove('d-none');
  };
  const schedule = () => {
    if (active) timer = setTimeout(sendReading, 3000);
  };

  async function sendReading() {
    if (!active) return;
    setState('저장 중...');
    controller = new AbortController();
    try {
      const data = new URLSearchParams({gen_action: 'insert', csrf_token: csrf});
      const response = await fetch('index.php?cmd=gen', {
        method: 'POST',
        body: data,
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const result = doc.getElementById('gen-response');
      if (!result) throw new Error('서버 응답에서 저장 결과를 찾을 수 없습니다.');
      if (result.dataset.status !== 'ok') throw new Error(result.dataset.message || '저장 실패');
      if (!active) return;
      count++;
      app.querySelector('#gen-temp').textContent = result.dataset.temp + ' ℃';
      app.querySelector('#gen-hum').textContent = result.dataset.hum + ' %';
      app.querySelector('#gen-count').textContent = String(count);
      app.querySelector('#gen-latest').textContent = '최근 저장: ' + result.dataset.time;
      app.querySelector('#gen-empty')?.remove();
      const row = document.createElement('tr');
      for (const value of [result.dataset.time, result.dataset.temp, result.dataset.hum]) {
        const cell = document.createElement('td');
        cell.textContent = value;
        row.appendChild(cell);
      }
      rows.prepend(row);
      while (rows.children.length > 20) rows.lastElementChild.remove();
      error.classList.add('d-none');
      setState('실행 중 (3초 간격)');
    } catch (e) {
      if (e.name === 'AbortError') return;
      showError(e.message);
      active = false;
      start.disabled = false;
      stop.disabled = true;
      setState('오류로 중지됨');
    } finally {
      controller = null;
      schedule();
    }
  }

  start.addEventListener('click', () => {
    if (active) return;
    active = true;
    start.disabled = true;
    stop.disabled = false;
    error.classList.add('d-none');
    setState('실행 중 (3초 간격)');
    schedule();
  });
  stop.addEventListener('click', () => {
    active = false;
    if (timer) clearTimeout(timer);
    controller?.abort();
    start.disabled = false;
    stop.disabled = true;
    setState('중지됨');
  });
  window.addEventListener('pagehide', () => {
    active = false;
    if (timer) clearTimeout(timer);
    controller?.abort();
  });
})();
</script>
