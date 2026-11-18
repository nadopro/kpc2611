<?php
// monitor.php -- include from index.php?cmd=monitor
// index.php has already initialized session and $conn (mysqli).

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">데이터베이스 연결($conn)을 확인할 수 없습니다.</div>';
    return;
}

/** Retrieve the newest 100 records by idx, then order them oldest -> newest for charting. */
function monitorLatestIot(mysqli $db): array
{
    $sql = 'SELECT idx, temp, hum, time FROM '
         . '(SELECT idx, temp, hum, time FROM iot ORDER BY idx DESC LIMIT 100) AS recent '
         . 'ORDER BY idx ASC';
    $result = $db->query($sql);
    $items = [];
    while ($row = $result->fetch_assoc()) {
        $items[] = [
            'idx' => (int)$row['idx'],
            'temp' => $row['temp'] === null ? null : (float)$row['temp'],
            'hum' => $row['hum'] === null ? null : (float)$row['hum'],
            'time' => (string)$row['time'],
        ];
    }
    $result->free();
    return $items;
}

// This endpoint is still routed through index.php. Because index.php prints the
// page layout before including this file, return a JSON script node inside HTML;
// the browser extracts it instead of trying to parse the entire page as JSON.
if (isset($_GET['monitor_data']) && $_GET['monitor_data'] === '1') {
    try {
        $payload = ['ok' => true, 'rows' => monitorLatestIot($conn)];
    } catch (Throwable $e) {
        error_log('monitor.php query failed: ' . $e->getMessage());
        $payload = ['ok' => false, 'message' => 'IoT 데이터를 불러오지 못했습니다.'];
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    echo '<script type="application/json" id="iot-monitor-payload">' . $json . '</script>';
    return;
}

try {
    $initial = ['ok' => true, 'rows' => monitorLatestIot($conn)];
} catch (Throwable $e) {
    error_log('monitor.php initial query failed: ' . $e->getMessage());
    $initial = ['ok' => false, 'message' => 'IoT 데이터를 불러오지 못했습니다.'];
}
?>
<div id="iot-monitor" class="container py-3">
  <div class="card border-0 shadow-sm">
    <div class="card-header text-white" style="background:#46765c;">
      <h4 class="mb-0">IoT 온도·습도 실시간 모니터</h4>
    </div>
    <div class="card-body">
      <p class="text-muted mb-2">가장 최근 100건의 온도·습도를 3초 간격으로 갱신합니다. (기준: idx)</p>
      <div class="d-flex flex-wrap gap-3 mb-2 small">
        <span>상태: <strong id="iot-status">차트 준비 중</strong></span>
        <span>표시 건수: <strong id="iot-count">0</strong>건</span>
        <span>표시 idx: <strong id="iot-range">-</strong></span>
        <span>마지막 갱신: <strong id="iot-updated">-</strong></span>
      </div>
      <div id="iot-chart" role="img" aria-label="최근 100개 온도와 습도 꺾은선 그래프" style="width:100%;height:440px;"></div>
      <p class="small text-muted mt-2 mb-0">왼쪽 축: 온도(℃), 오른쪽 축: 습도(%). 같은 시간에 기록된 값은 idx 순서로 표시합니다.</p>
    </div>
  </div>
</div>
<script type="application/json" id="iot-monitor-initial"><?=
    json_encode($initial, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)
?></script>
<script src="https://www.gstatic.com/charts/loader.js"></script>
<script>
(function () {
  'use strict';
  const root = document.getElementById('iot-monitor');
  const chartNode = document.getElementById('iot-chart');
  const statusNode = document.getElementById('iot-status');
  const countNode = document.getElementById('iot-count');
  const rangeNode = document.getElementById('iot-range');
  const updatedNode = document.getElementById('iot-updated');
  let timer = null;
  let chart = null;
  let latestRows = [];
  let chartReady = false;

  function setStatus(message) { statusNode.textContent = message; }
  function render(rows) {
    latestRows = rows;
    countNode.textContent = String(rows.length);
    rangeNode.textContent = rows.length ? rows[0].idx + ' ~ ' + rows[rows.length - 1].idx : '-';
    updatedNode.textContent = new Date().toLocaleTimeString('ko-KR', {hour12: false});
    if (!chartReady) return;

    if (!rows.length) {
      chartNode.textContent = '표시할 IoT 데이터가 없습니다.';
      return;
    }
    const table = new google.visualization.DataTable();
    table.addColumn('string', '측정 시간');
    table.addColumn('number', '온도 (℃)');
    table.addColumn('number', '습도 (%)');
    table.addRows(rows.map(r => [
      r.time + ' (#' + r.idx + ')',
      r.temp === null ? null : Number(r.temp),
      r.hum === null ? null : Number(r.hum)
    ]));

    if (!chart) chart = new google.visualization.LineChart(chartNode);
    chart.draw(table, {
      title: 'IoT 최근 100건 추이',
      height: 440,
      legend: { position: 'top' },
      chartArea: { left: 68, right: 74, top: 68, bottom: 100, width: '80%', height: '65%' },
      hAxis: { title: '측정 시간 (idx 오름차순)', showTextEvery: Math.max(1, Math.ceil(rows.length / 8)), slantedText: true, slantedTextAngle: 35, textStyle: {fontSize: 10} },
      vAxes: {
        0: { title: '온도 (℃)', format: '0.0' },
        1: { title: '습도 (%)', format: '0.0' }
      },
      series: {
        0: { targetAxisIndex: 0, color: '#d9534f', lineWidth: 2, pointSize: 2 },
        1: { targetAxisIndex: 1, color: '#2779bd', lineWidth: 2, pointSize: 2 }
      },
      interpolateNulls: false,
      explorer: { axis: 'horizontal', keepInBounds: true },
      vAxes: {
    0: {
        title: '온도 (℃)',
        format: '0.0',
        viewWindow: { min: 0 }
    },
    1: {
        title: '습도 (%)',
        format: '0.0',
        viewWindow: { min: 0 }
    }
},
    });
  }

  async function refresh() {
    try {
      const url = new URL('index.php', window.location.href);
      url.searchParams.set('cmd', 'monitor');
      url.searchParams.set('monitor_data', '1');
      const response = await fetch(url.toString(), {cache: 'no-store', credentials: 'same-origin'});
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const script = doc.getElementById('iot-monitor-payload');
      if (!script) throw new Error('응답에서 데이터 영역을 찾을 수 없습니다.');
      const payload = JSON.parse(script.textContent);
      if (!payload.ok) throw new Error(payload.message || '조회 실패');
      render(payload.rows);
      setStatus('정상 모니터링 중');
    } catch (e) {
      setStatus('갱신 실패: ' + e.message);
    } finally {
      // Schedule AFTER the previous request to avoid overlapping fetches.
      timer = window.setTimeout(refresh, 3000);
    }
  }

  try {
    const initial = JSON.parse(document.getElementById('iot-monitor-initial').textContent);
    if (initial.ok) latestRows = initial.rows;
    else setStatus(initial.message || '초기 조회 실패');
  } catch (e) {
    setStatus('초기 데이터 해석 실패');
  }

  google.charts.load('current', {packages: ['corechart'], language: 'ko'});
  google.charts.setOnLoadCallback(function () {
    chartReady = true;
    render(latestRows);
    setStatus('정상 모니터링 중');
    timer = window.setTimeout(refresh, 3000);
  });
  window.addEventListener('resize', function () {
    if (chartReady) render(latestRows);
  });
  window.addEventListener('pagehide', function () {
    if (timer !== null) window.clearTimeout(timer);
  });
})();
</script>
