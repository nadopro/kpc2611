<?php
/**
 * log2.php - index.php?cmd=log2&tab=1|2|3
 * index.php에서 세션 시작 및 mysqli $conn 연결을 완료한 상태를 전제로 합니다.
 * 데이터 갱신 요청 역시 index.php를 통해 처리합니다.
 */

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">데이터베이스 연결을 확인할 수 없습니다.</div>';
    return;
}

// 접속 로그(IP, ID 등)는 민감할 수 있으므로 관리자에게만 표시합니다.
$isLogAdmin = (($_SESSION['id'] ?? '') === 'admin') || ((int)($_SESSION['level'] ?? 0) >= 9);
if (!$isLogAdmin) {
    echo '<div class="alert alert-warning">로그 조회는 관리자만 이용할 수 있습니다.</div>';
    return;
}

date_default_timezone_set('Asia/Seoul');
$tab = filter_input(INPUT_GET, 'tab', FILTER_VALIDATE_INT);
$tab = in_array($tab, [1, 2, 3], true) ? $tab : 1;
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = ($page !== false && $page !== null && $page > 0) ? $page : 1;
$isRefresh = (($_GET['refresh'] ?? '') === '1');

if (!function_exists('logEscape')) {
    function logEscape($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
if (!function_exists('logHref')) {
    function logHref(int $tab, ?int $page = null): string {
        $url = 'index.php?cmd=log2&tab=' . $tab;
        if ($page !== null) $url .= '&page=' . $page;
        return $url;
    }
}
if (!function_exists('logHourlyRows')) {
    function logHourlyRows(mysqli $conn, int $tab): array {
        $tz = new DateTimeZone('Asia/Seoul');
        $today = new DateTimeImmutable('today', $tz);
        $start = $tab === 2 ? $today : $today->modify('-6 days');
        $end = $today->modify('+1 day');
        $sql = "SELECT DATE_FORMAT(`time`, '%Y-%m-%d %H:00:00') AS hour_slot,
                       COUNT(DISTINCT `ip`) AS computers,
                       COUNT(*) AS clicks
                FROM `log`
                WHERE `time` >= ? AND `time` < ?
                GROUP BY DATE_FORMAT(`time`, '%Y-%m-%d %H:00:00')
                ORDER BY hour_slot ASC";
        $stmt = $conn->prepare($sql);
        $from = $start->format('Y-m-d H:i:s');
        $to = $end->format('Y-m-d H:i:s');
        $stmt->bind_param('ss', $from, $to);
        $stmt->execute();
        $res = $stmt->get_result();
        $lookup = [];
        while ($row = $res->fetch_assoc()) {
            $lookup[$row['hour_slot']] = [
                'computers' => (int)$row['computers'],
                'clicks' => (int)$row['clicks'],
            ];
        }
        $stmt->close();

        $data = [];
        $current = $start;
        $now = new DateTimeImmutable('now', $tz);
        while ($current < $end) {
            $key = $current->format('Y-m-d H:00:00');
            // 일간 그래프는 자정~24시, 주간은 7일 전체 168시간을 고정 축으로 표시합니다.
            $data[] = [
                'hour' => $tab === 2 ? $current->format('H:00') : $current->format('m-d H:00'),
                'computers' => $lookup[$key]['computers'] ?? 0,
                'clicks' => $lookup[$key]['clicks'] ?? 0,
                'future' => ($current > $now),
            ];
            $current = $current->modify('+1 hour');
        }
        return $data;
    }
}

// IP 정보: 모든 요청은 index.php를 경유하므로 JSON을 HTML script에 담아 전달합니다.
// 임의 URL 요청을 방지하기 위해 IP 형식과 공개 IPv4 주소만 허용합니다.
if ($tab === 1 && (($_GET['ip_lookup'] ?? '') === '1')) {
    $ip = trim((string)($_GET['ip'] ?? ''));
    $payload = ['ok' => false, 'message' => 'IP 주소가 올바르지 않습니다.'];
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        if (!function_exists('curl_init')) {
            $payload['message'] = 'PHP cURL 확장이 활성화되어 있지 않습니다.';
        } else {
            $url = 'http://ip-api.com/json/' . rawurlencode($ip);
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_USERAGENT => 'KPC-Log-IP-Lookup/1.0',
            ]);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($response === false || $httpCode !== 200) {
                $payload['message'] = 'IP API 조회 실패 (HTTP ' . $httpCode . '). ' . ($curlError ?: '잠시 후 다시 시도해 주세요.');
            } else {
                $data = json_decode($response, true);
                if (!is_array($data)) {
                    $payload['message'] = 'IP API가 유효한 JSON을 반환하지 않았습니다.';
                } elseif (($data['status'] ?? '') !== 'success') {
                    $payload['message'] = 'IP 조회 실패: ' . (string)($data['message'] ?? '위치 정보를 찾을 수 없습니다.');
                    $payload['data'] = $data;
                } else {
                    $payload = ['ok' => true, 'data' => $data];
                }
            }
        }
    }
    echo '<script type="application/json" id="log2-ip-payload">'
       . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
       . '</script>';
    return;
}

// AJAX 요청도 index.php를 거치기 때문에 JSON 단독 응답 대신
// HTML 안의 application/json 요소를 읽어 갑니다.
if ($isRefresh && $tab !== 1) {
    try {
        $payload = ['ok' => true, 'rows' => logHourlyRows($conn, $tab), 'updated' => date('Y-m-d H:i:s')];
    } catch (Throwable $e) {
        error_log('log2.php refresh: ' . $e->getMessage());
        $payload = ['ok' => false, 'message' => '통계를 불러오지 못했습니다.'];
    }
    echo '<script type="application/json" id="log-refresh-payload">'
       . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
       . '</script>';
    return;
}
?>
<style>
.log-tabs .nav-link { color:#426d58; }
.log-tabs .nav-link.active { color:#315341; font-weight:700; }
.log-table th, .log-table td { vertical-align:middle; }
.log-table .log-work { overflow-wrap:anywhere; max-width:450px; }
.log-chart { width:100%; height:480px; }
.log-page-btn { min-width:40px; }
@media (max-width:575.98px) { .log-chart {height:360px;} .log-table {font-size:.84rem;} }
</style>
<div class="container-fluid p-0" id="log-app">
  <h4 class="mb-3">접속 로그 모니터링</h4>
  <ul class="nav nav-tabs log-tabs mb-3">
    <li class="nav-item"><a class="nav-link <?= $tab === 1 ? 'active' : '' ?>" href="<?= logHref(1) ?>">표</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 2 ? 'active' : '' ?>" href="<?= logHref(2) ?>">차트(일)</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 3 ? 'active' : '' ?>" href="<?= logHref(3) ?>">차트(주간)</a></li>
  </ul>
<?php if ($tab === 1): ?>
<?php
    $perPage = 30;
    $groupSize = 5;
    $total = (int)$conn->query('SELECT COUNT(*) AS cnt FROM `log`')->fetch_assoc()['cnt'];
    $totalPages = max(1, (int)ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $stmt = $conn->prepare('SELECT `idx`, `ip`, `id`, `work`, `time` FROM `log` ORDER BY `idx` DESC LIMIT ? OFFSET ?');
    $stmt->bind_param('ii', $perPage, $offset);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $currentGroup = intdiv($page - 1, $groupSize) + 1;
    $totalGroups = (int)ceil($totalPages / $groupSize);
    $startPage = ($currentGroup - 1) * $groupSize + 1;
    $endPage = min($startPage + $groupSize - 1, $totalPages);
?>
  <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
    <span>전체 <?= number_format($total) ?>건 · 페이지당 30건</span>
    <span><?= number_format($page) ?> / <?= number_format($totalPages) ?> 페이지</span>
  </div>
  <div class="table-responsive">
    <table class="table table-bordered table-striped table-hover log-table align-middle">
      <thead class="table-light">
        <tr><th>idx</th><th>ip</th><th>id</th><th>work</th><th>time</th><th class="text-center">flag</th><th class="text-center">비고</th></tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $row): ?>
        <tr>
          <td><?= (int)$row['idx'] ?></td>
          <td class="font-monospace"><?= logEscape($row['ip']) ?></td>
          <td><?= logEscape($row['id']) ?></td>
          <td class="log-work"><?= logEscape($row['work']) ?></td>
          <td class="text-nowrap"><?= logEscape($row['time']) ?></td>
          <td class="text-center"><span class="text-muted" title="국기 표시 예정">—</span></td>
          <td class="text-center text-nowrap">
            <button type="button" class="btn btn-sm btn-outline-secondary log-ip-info" data-ip="<?= logEscape($row['ip']) ?>">IP 상세정보</button>
            <button type="button" class="btn btn-sm btn-outline-success log-map-view" data-ip="<?= logEscape($row['ip']) ?>">지도 보기</button>
          </td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="text-center text-muted py-4">표시할 로그가 없습니다.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <nav aria-label="로그 목록 페이지" class="mt-3">
    <div class="d-flex flex-wrap justify-content-center gap-1">
      <?php
        $links = [
          ['맨앞', 1, $currentGroup >= 3],
          ['이전', max(1, $startPage - $groupSize), $currentGroup >= 2]
        ];
        foreach ($links as [$label, $dest, $enabled]):
      ?>
        <?php if ($enabled): ?>
        <a class="btn btn-outline-success btn-sm log-page-btn" href="<?= logHref(1, $dest) ?>"><?= $label ?></a>
        <?php else: ?>
        <span class="btn btn-outline-secondary btn-sm disabled log-page-btn" aria-disabled="true"><?= $label ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
      <?php for ($p = $startPage; $p <= $endPage; $p++): ?>
        <a class="btn btn-sm log-page-btn <?= $p === $page ? 'btn-success' : 'btn-outline-success' ?>" <?= $p === $page ? 'aria-current="page"' : '' ?> href="<?= logHref(1, $p) ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php
        $links = [
          ['다음', min($totalPages, $endPage + 1), $currentGroup < $totalGroups],
          ['마지막', $totalPages, ($totalGroups - $currentGroup) >= 2]
        ];
        foreach ($links as [$label, $dest, $enabled]):
      ?>
        <?php if ($enabled): ?>
        <a class="btn btn-outline-success btn-sm log-page-btn" href="<?= logHref(1, $dest) ?>"><?= $label ?></a>
        <?php else: ?>
        <span class="btn btn-outline-secondary btn-sm disabled log-page-btn" aria-disabled="true"><?= $label ?></span>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </nav>
  <!-- IP 상세 정보: 왼쪽 Offcanvas -->
  <div class="offcanvas offcanvas-start" tabindex="-1" id="log2IpCanvas"
       aria-labelledby="log2IpTitle" style="width:min(560px, 95vw);">
    <div class="offcanvas-header border-bottom">
      <h5 class="offcanvas-title" id="log2IpTitle">IP 상세정보</h5>
      <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="닫기"></button>
    </div>
    <div class="offcanvas-body">
      <div id="log2IpStatus" class="small text-muted mb-2" role="status"></div>
      <pre id="log2IpJson" class="p-3 rounded bg-dark text-light"
           style="white-space:pre-wrap; overflow-wrap:anywhere; min-height:160px;"></pre>
    </div>
  </div>

  <!-- 지도: 상단에서 아래로 내려오는 Offcanvas, 브라우저 높이 90% -->
  <div class="offcanvas offcanvas-top" tabindex="-1" id="log2MapCanvas"
       aria-labelledby="log2MapTitle" style="height:90vh; max-height:90vh;">
    <div class="offcanvas-header border-bottom">
      <div>
        <h5 class="offcanvas-title" id="log2MapTitle">IP 지도 보기</h5>
        <div class="small text-muted" id="log2MapStatus" role="status"></div>
      </div>
      <div class="d-flex align-items-center gap-2">
        <a id="log2MapLink" class="btn btn-sm btn-outline-primary d-none" target="_blank" rel="noopener noreferrer">지도 직접 열기</a>
        <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="닫기"></button>
      </div>
    </div>
    <div class="offcanvas-body p-2 d-flex flex-column" style="min-height:0;">
      <div id="log2MapPlaceholder" class="alert alert-info mb-2">위치를 조회하고 있습니다.</div>
      <iframe id="log2MapFrame" title="IP 위치 Google 지도" class="border-0 rounded flex-grow-1 w-100 d-none"
              referrerpolicy="no-referrer-when-downgrade" loading="lazy"></iframe>
      <div class="small text-muted mt-2">IP 기반 위치는 실제 컴퓨터의 정확한 위치와 다를 수 있습니다. 지도가 표시되지 않으면 ‘지도 직접 열기’를 이용하세요.</div>
    </div>
  </div>

  <script>
  function initLog2Actions() {
    const root = document.getElementById('log-app');
    if (!root) return;
    if (!window.bootstrap || !window.bootstrap.Offcanvas) {
      console.error("Bootstrap Offcanvas 라이브러리가 로드되지 않았습니다.");
      return;
    }
    const ipCanvasEl = document.getElementById('log2IpCanvas');
    const mapCanvasEl = document.getElementById('log2MapCanvas');
    const ipCanvas = bootstrap.Offcanvas.getOrCreateInstance(ipCanvasEl);
    const mapCanvas = bootstrap.Offcanvas.getOrCreateInstance(mapCanvasEl);
    const ipJson = document.getElementById('log2IpJson');
    const ipStatus = document.getElementById('log2IpStatus');
    const mapStatus = document.getElementById('log2MapStatus');
    const mapFrame = document.getElementById('log2MapFrame');
    const mapPlaceholder = document.getElementById('log2MapPlaceholder');
    const mapLink = document.getElementById('log2MapLink');
    const cache = new Map();
    let requestSerial = 0;

    async function lookup(ip) {
      if (cache.has(ip)) return cache.get(ip);
      const url = 'index.php?cmd=log2&tab=1&ip_lookup=1&ip=' + encodeURIComponent(ip);
      const response = await fetch(url, {credentials:'same-origin', cache:'no-store'});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const node = doc.getElementById('log2-ip-payload');
      if (!node) throw new Error('IP 조회 응답을 찾을 수 없습니다. 로그인 상태를 확인하세요.');
      const result = JSON.parse(node.textContent);
      if (!result.ok) throw new Error(result.message || 'IP 조회에 실패했습니다.');
      cache.set(ip, result.data);
      return result.data;
    }

    root.querySelectorAll('.log-ip-info').forEach(btn => {
      btn.addEventListener('click', async () => {
        const ip = btn.dataset.ip || '';
        const serial = ++requestSerial;
        document.getElementById('log2IpTitle').textContent = 'IP 상세정보: ' + ip;
        ipJson.textContent = '조회 중...';
        ipStatus.textContent = 'ip-api.com에서 정보를 가져오는 중입니다.';
        ipCanvas.show();
        try {
          const data = await lookup(ip);
          if (serial !== requestSerial) return;
          ipJson.textContent = JSON.stringify(data, null, 4);
          ipStatus.textContent = '조회 완료';
        } catch (error) {
          if (serial !== requestSerial) return;
          ipJson.textContent = '';
          ipStatus.textContent = '조회 실패: ' + error.message;
        }
      });
    });

    root.querySelectorAll('.log-map-view').forEach(btn => {
      btn.addEventListener('click', async () => {
        const ip = btn.dataset.ip || '';
        const serial = ++requestSerial;
        document.getElementById('log2MapTitle').textContent = 'IP 지도 보기: ' + ip;
        mapStatus.textContent = '위도·경도 조회 중...';
        mapPlaceholder.textContent = '위치를 조회하고 있습니다.';
        mapPlaceholder.classList.remove('d-none');
        mapFrame.classList.add('d-none');
        mapFrame.removeAttribute('src');
        mapLink.classList.add('d-none');
        mapLink.removeAttribute('href');
        mapCanvas.show();
        try {
          const data = await lookup(ip);
          if (serial !== requestSerial) return;
          const lat = Number(data.lat), lon = Number(data.lon);
          if (!Number.isFinite(lat) || !Number.isFinite(lon) || Math.abs(lat) > 90 || Math.abs(lon) > 180) {
            throw new Error('유효한 좌표를 찾지 못했습니다.');
          }
          mapStatus.textContent = `${data.city || ''} ${data.country || ''} · 위도 ${lat}, 경도 ${lon}`;
          const query = encodeURIComponent(lat + ',' + lon);
          mapFrame.src = 'https://www.google.com/maps?q=' + query + '&output=embed';
          mapLink.href = 'https://www.google.com/maps?q=' + query;
          mapLink.classList.remove('d-none');
          mapPlaceholder.classList.add('d-none');
          mapFrame.classList.remove('d-none');
        } catch (error) {
          if (serial !== requestSerial) return;
          mapStatus.textContent = '지도 조회 실패';
          mapPlaceholder.textContent = error.message;
        }
      });
    });

    mapCanvasEl.addEventListener('hidden.bs.offcanvas', () => {
      mapFrame.removeAttribute('src');
    });
  }
  // index.php는 Bootstrap bundle을 본문 include 이후에 로드하므로
  // DOMContentLoaded 이후에 Offcanvas를 초기화해야 합니다.
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initLog2Actions, { once: true });
  } else {
    initLog2Actions();
  }

  </script>
<?php else: ?>
  <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
    <span class="badge bg-success"><?= $tab === 2 ? '오늘 00:00~24:00 (시간별)' : '오늘 포함 최근 7일 (시간별)' ?></span>
    <span class="small text-muted">IP 수 = 시간대별 고유 IP 개수 / 클릭수 = 시간대별 로그 건수</span>
  </div>
  <div class="row g-3 mb-3">
    <div class="col-6"><div class="card"><div class="card-body"><div class="text-muted small">표시 구간의 클릭수</div><div class="fs-4 fw-semibold" id="log-total-clicks">-</div></div></div></div>
    <div class="col-6"><div class="card"><div class="card-body"><div class="text-muted small">시간대별 IP 수 합계</div><div class="fs-4 fw-semibold" id="log-total-ips">-</div></div></div></div>
  </div>
  <div id="log-chart-status" class="small text-muted mb-2" aria-live="polite">차트를 불러오는 중...</div>
  <div id="log-line-chart" class="log-chart" role="img" aria-label="시간대별 접속 IP 수와 클릭수 선 그래프"></div>
  <p class="small text-muted mt-2">시간대별 고유 IP 수는 동일 IP가 서로 다른 시간대에 나타나면 각각 집계합니다. IP 수는 실제 컴퓨터 수와 일치하지 않을 수 있습니다. 차트는 <?= $tab === 2 ? '5' : '10' ?>초마다 갱신됩니다.</p>
  <script src="https://www.gstatic.com/charts/loader.js"></script>
  <script>
  (function () {
    const tab = <?= $tab ?>;
    const intervalMs = tab === 2 ? 5000 : 10000;
    const chartElement = document.getElementById('log-line-chart');
    const statusElement = document.getElementById('log-chart-status');
    const clickTotal = document.getElementById('log-total-clicks');
    const ipTotal = document.getElementById('log-total-ips');
    let chart = null;
    let rows = [];
    let timer = null;
    let stopped = false;
    let loaded = false;
    function drawChart() {
      if (!loaded || !chartElement || !document.contains(chartElement)) return;
      const data = new google.visualization.DataTable();
      data.addColumn('string', '시간');
      data.addColumn('number', '컴퓨터수(IP)');
      data.addColumn('number', '클릭수(로그)');
      rows.forEach(item => data.addRow([
        String(item.hour), Number(item.computers), Number(item.clicks)
      ]));
      if (!chart) chart = new google.visualization.LineChart(chartElement);
      chart.draw(data, {
        title: tab === 2 ? '오늘 시간대별 접속 통계' : '최근 7일 시간대별 접속 통계',
        backgroundColor: 'transparent',
        height: chartElement.clientHeight || 480,
        legend: { position: 'bottom' },
        chartArea: { left: 75, top: 65, width: '78%', height: '65%' },
        hAxis: { title: '시간', showTextEvery: tab === 2 ? 2 : 12, slantedText: tab === 3 },
        vAxis: { title: '건수', minValue: 0, viewWindow: { min: 0 }, format: '0' },
        pointSize: tab === 2 ? 4 : 0,
        lineWidth: 2,
        explorer: tab === 3 ? { axis: 'horizontal', keepInBounds: true } : undefined
      });
    }
    function schedule() {
      clearTimeout(timer);
      if (!stopped) timer = setTimeout(refresh, intervalMs);
    }
    async function refresh() {
      if (stopped || !document.contains(chartElement)) return;
      try {
        const res = await fetch('index.php?cmd=log2&tab=' + tab + '&refresh=1&_=' + Date.now(), {
          credentials: 'same-origin', cache: 'no-store'
        });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const html = await res.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const node = doc.getElementById('log-refresh-payload');
        if (!node) throw new Error('통계 데이터가 없습니다. 관리자 로그인 상태를 확인하세요.');
        const payload = JSON.parse(node.textContent);
        if (!payload.ok) throw new Error(payload.message || '통계 오류');
        rows = payload.rows || [];
        clickTotal.textContent = rows.reduce((a, r) => a + Number(r.clicks), 0).toLocaleString('ko-KR');
        ipTotal.textContent = rows.reduce((a, r) => a + Number(r.computers), 0).toLocaleString('ko-KR');
        statusElement.textContent = '마지막 갱신: ' + payload.updated;
        drawChart();
      } catch (err) {
        statusElement.textContent = '갱신 실패: ' + err.message;
      } finally { schedule(); }
    }
    if (typeof google !== 'undefined' && google.charts) {
      google.charts.load('current', { packages: ['corechart'], language: 'ko' });
      google.charts.setOnLoadCallback(() => { loaded = true; refresh(); });
    } else {
      statusElement.textContent = 'Google Charts 라이브러리를 불러오지 못했습니다.';
    }
    window.addEventListener('resize', drawChart);
    window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
  })();
  </script>
<?php endif; ?>
</div>
