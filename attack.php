<?php
// index.php?cmd=attack
// Classroom demonstration: limited actual concurrent fetches.
if (session_status() !== PHP_SESSION_ACTIVE) {
    echo '<div class="alert alert-warning">index.php에서 접속해 주세요.</div>';
    return;
}
?>
<div class="container py-4" id="attack-demo">
  <div class="card border-0 shadow-sm">
    <div class="card-header text-white" style="background:#46765c">
      <h4 class="mb-0">비동기 요청 응답시간 측정</h4>
    </div>
    <div class="card-body">
      <form id="attack-form" class="row g-3 align-items-end">
        <div class="col-md-4">
          <label for="attack-computers" class="form-label">컴퓨터 수 (1~50)</label>
          <input id="attack-computers" class="form-control" type="number" min="1" max="50" value="10" required>
        </div>
        <div class="col-md-4">
          <label for="attack-rounds" class="form-label">실행 횟수 (1~30)</label>
          <input id="attack-rounds" class="form-control" type="number" min="1" max="30" value="5" required>
        </div>
        <div class="col-md-4 d-flex gap-2">
          <button id="attack-start" type="submit" class="btn btn-success flex-grow-1">실행</button>
          <button id="attack-stop" type="button" class="btn btn-outline-secondary" disabled>중지</button>
        </div>
      </form>
      <div class="small text-muted mt-3">
        입력한 전체 요청 수는 시뮬레이션 규모로 표시합니다. 서버 보호를 위해 실제 실측은 최대 3개 동시 요청, 최대 12개 요청만 실행합니다.
        현재 브라우저에서 localhost의 slow.php에만 요청하며 외부 서버는 대상으로 하지 않습니다.
      </div>
      <div class="row g-2 my-3">
        <div class="col-md-4"><div class="border rounded p-3"><div class="small text-muted">설정된 요청 규모</div><strong id="attack-planned">-</strong></div></div>
        <div class="col-md-4"><div class="border rounded p-3"><div class="small text-muted">실측 요청</div><strong id="attack-actual">-</strong></div></div>
        <div class="col-md-4"><div class="border rounded p-3"><div class="small text-muted">전체 실측 소요시간</div><strong id="attack-duration">-</strong></div></div>
      </div>
      <div id="attack-status" role="status" class="alert alert-secondary py-2">실행 대기 중</div>
      <div class="table-responsive">
        <table class="table table-striped table-bordered table-sm align-middle">
          <thead><tr><th>컴퓨터 번호</th><th>회차</th><th>HTTP 상태</th><th>응답시간 (ms)</th><th>결과</th></tr></thead>
          <tbody id="attack-results"><tr><td colspan="5" class="text-center text-muted">실행 결과가 없습니다.</td></tr></tbody>
        </table>
      </div>
    </div>
  </div>
</div>
<script>
(() => {
  const form = document.getElementById('attack-form');
  if (!form) return;
  const computerInput = document.getElementById('attack-computers');
  const roundInput = document.getElementById('attack-rounds');
  const startBtn = document.getElementById('attack-start');
  const stopBtn = document.getElementById('attack-stop');
  const tbody = document.getElementById('attack-results');
  const status = document.getElementById('attack-status');
  const planned = document.getElementById('attack-planned');
  const actual = document.getElementById('attack-actual');
  const duration = document.getElementById('attack-duration');
  let active = false;
  let controller = null;

  function validInt(el, min, max) {
    const n = Number(el.value);
    return Number.isInteger(n) && n >= min && n <= max ? n : null;
  }

  function appendResult(r) {
    const tr = document.createElement('tr');
    for (const value of [r.com, r.count, r.code, r.ms.toFixed(2), r.result]) {
      const td = document.createElement('td');
      td.textContent = String(value);
      tr.appendChild(td);
    }
    tbody.appendChild(tr);
  }

  async function oneRequest(task, signal) {
    const url = 'index.php?cmd=slow&com=' + encodeURIComponent(task.com)
       + '&count=' + encodeURIComponent(task.count);
    const t = performance.now();
    try {
      const response = await fetch(url, { signal, credentials: 'same-origin', cache: 'no-store' });
      // Read the body too: time measured until the whole response arrives.
      await response.text();
      return { ...task, code: response.status, ms: performance.now() - t,
               result: response.ok ? '완료' : 'HTTP 오류' };
    } catch (e) {
      return { ...task, code: '-', ms: performance.now() - t,
               result: signal.aborted ? '중지됨' : '요청 실패' };
    }
  }

  async function runBoundedTasks(tasks, signal) {
    // A worker pool demonstrates concurrency without creating an unbounded flood.
    const concurrency = Math.min(3, tasks.length);
    let cursor = 0, finished = 0;
    const workers = Array.from({ length: concurrency }, async () => {
      while (!signal.aborted) {
        const i = cursor++;
        if (i >= tasks.length) return;
        const result = await oneRequest(tasks[i], signal);
        appendResult(result);
        finished++;
        status.textContent = '실측 진행: ' + finished + ' / ' + tasks.length;
      }
    });
    await Promise.all(workers);
  }

  stopBtn.addEventListener('click', () => controller?.abort());
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (active) return;
    const computers = validInt(computerInput, 1, 50);
    const rounds = validInt(roundInput, 1, 30);
    if (computers === null || rounds === null) {
      status.textContent = '컴퓨터 수 1~50, 실행 횟수 1~30을 입력하세요.';
      return;
    }

    // Make a deterministic, small sample across the requested computers/rounds.
    const tasks = [];
    for (let round = 1; round <= rounds && tasks.length < 12; round++) {
      for (let com = 1; com <= computers && tasks.length < 12; com++) {
        tasks.push({ com, count: round });
      }
    }
    planned.textContent = (computers * rounds).toLocaleString('ko-KR') + '건';
    actual.textContent = tasks.length + '건 (최대 동시 3건)';
    duration.textContent = '-';
    tbody.replaceChildren();
    status.textContent = '측정 시작';
    status.className = 'alert alert-info py-2';
    startBtn.disabled = true;
    stopBtn.disabled = false;
    computerInput.disabled = roundInput.disabled = true;
    active = true;
    controller = new AbortController();
    const t0 = performance.now();
    try {
      await runBoundedTasks(tasks, controller.signal);
      const ms = performance.now() - t0;
      duration.textContent = (ms / 1000).toFixed(3) + '초 / ' + ms.toFixed(2) + 'ms';
      status.textContent = controller.signal.aborted ? '요청 중지됨' : '실측 완료';
      status.className = controller.signal.aborted ? 'alert alert-warning py-2' : 'alert alert-success py-2';
    } finally {
      active = false;
      startBtn.disabled = false;
      stopBtn.disabled = true;
      computerInput.disabled = roundInput.disabled = false;
      controller = null;
    }
  });
})();
</script>
