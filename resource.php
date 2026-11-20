<?php
/**
 * resource.php - Windows 11 / XAMPP resource monitor
 * URL: index.php?cmd=resource
 * AJAX: index.php?cmd=resource&resource_data=1
 * index.php starts the session and connects $conn before including this file.
 */

$isResourceAdmin = (($_SESSION['id'] ?? '') === 'admin') || ((int)($_SESSION['level'] ?? 0) >= 9);
if (!$isResourceAdmin) {
    echo '<div class="alert alert-warning">시스템 자원 정보는 관리자만 조회할 수 있습니다.</div>';
    return;
}

if (!function_exists('resourceReadWindows')) {
    function resourceReadWindows(): array {
        if (PHP_OS_FAMILY !== 'Windows') {
            throw new RuntimeException('Windows 환경에서만 사용할 수 있습니다.');
        }
        if (!function_exists('exec') || !function_exists('iconv')) {
            throw new RuntimeException('PHP exec 또는 iconv 함수를 사용할 수 없습니다.');
        }

        // No user-supplied arguments are passed to PowerShell.
        // Win32_Processor.LoadPercentage reports a sampled utilization rate.
        // Win32_OperatingSystem memory values are in KB.
        // PowerShell output uses a fixed ASCII record rather than JSON.
        // This avoids encoding/extra-output differences between Apache and CLI.
        $script = <<<'PS'
$ErrorActionPreference = 'Stop'
$processors = @(Get-CimInstance -ClassName Win32_Processor)
if ($processors.Count -eq 0) { throw 'CPU information unavailable' }
$cpu = ($processors | Measure-Object -Property LoadPercentage -Average).Average
if ($null -eq $cpu) { $cpu = 0 }
$os = Get-CimInstance -ClassName Win32_OperatingSystem
$total = [double]$os.TotalVisibleMemorySize
$free = [double]$os.FreePhysicalMemory
if ($total -le 0) { throw 'Memory information unavailable' }
$used = [math]::Max(0, $total - $free)
$culture = [System.Globalization.CultureInfo]::InvariantCulture
$items = @(
    ([math]::Round([double]$cpu, 1)).ToString($culture),
    ([math]::Round(($used / $total) * 100, 1)).ToString($culture),
    ([math]::Round($used / 1048576, 2)).ToString($culture),
    ([math]::Round($total / 1048576, 2)).ToString($culture),
    ([math]::Round($free / 1048576, 2)).ToString($culture)
)
[Console]::Out.WriteLine('RESOURCE|' + ($items -join '|'))
PS;

        // -EncodedCommand accepts UTF-16LE Base64.
        $encoded = base64_encode(iconv('UTF-8', 'UTF-16LE', $script));
        $command = 'powershell.exe -NoLogo -NoProfile -NonInteractive -EncodedCommand ' . $encoded . ' 2>&1';
        $lines = [];
        $status = 0;
        exec($command, $lines, $status);
        $output = trim(implode("\n", $lines));
        if ($status !== 0) {
            error_log('resource.php PowerShell failed (exit ' . $status . '): ' . substr($output, 0, 800));
            throw new RuntimeException('PowerShell 조회에 실패했습니다. Apache 오류 로그를 확인하세요.');
        }

        // Find a valid record, even if PowerShell emitted other informational lines.
        if (!preg_match('/RESOURCE\|([0-9]+(?:\.[0-9]+)?)\|([0-9]+(?:\.[0-9]+)?)\|([0-9]+(?:\.[0-9]+)?)\|([0-9]+(?:\.[0-9]+)?)\|([0-9]+(?:\.[0-9]+)?)/', $output, $matches)) {
            error_log('resource.php invalid PowerShell output: ' . substr($output, 0, 800));
            throw new RuntimeException('PowerShell 결과 형식이 올바르지 않습니다. Apache 오류 로그를 확인하세요.');
        }
        $data = [
            'cpu_percent' => (float)$matches[1],
            'memory_percent' => (float)$matches[2],
            'memory_used_gb' => (float)$matches[3],
            'memory_total_gb' => (float)$matches[4],
            'memory_free_gb' => (float)$matches[5],
        ];
        return [
            'cpu_percent' => max(0, min(100, (float)$data['cpu_percent'])),
            'memory_percent' => max(0, min(100, (float)$data['memory_percent'])),
            'memory_used_gb' => (float)$data['memory_used_gb'],
            'memory_total_gb' => (float)$data['memory_total_gb'],
            'memory_free_gb' => (float)$data['memory_free_gb'],
            'measured_at' => date('Y-m-d H:i:s'),
        ];
    }
}

// index.php has already emitted HTML; embed AJAX JSON in the returned document.
if (($_GET['resource_data'] ?? '') === '1') {
    // Let other same-session requests proceed while PowerShell runs.
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    try {
        $payload = ['ok' => true, 'data' => resourceReadWindows()];
    } catch (Throwable $e) {
        $payload = ['ok' => false, 'message' => $e->getMessage()];
    }
    echo '<script type="application/json" id="resource-data-payload">'
        . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE)
        . '</script>';
    return;
}
?>
<style>
.resource-card {border: 1px solid #cfe3d2; border-radius: 12px; background: #fff;}
.resource-number {font-size: clamp(2rem, 5vw, 3.2rem); font-weight: 750; font-variant-numeric: tabular-nums;}
.resource-track {height: 20px; background: #e9eee9; border-radius: 999px; overflow:hidden;}
.resource-fill {height:100%;width:0; background:#46765c; transition:width .35s ease;}
.resource-caption {color:#61766a;font-size:.9rem;}
</style>
<div id="resource-monitor" class="container-fluid p-0">
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h4 class="mb-0">Windows 시스템 자원 모니터링</h4>
    <span id="resource-status" class="small text-muted" role="status">측정 준비 중...</span>
  </div>
  <div class="row g-3">
    <div class="col-md-6"><div class="resource-card p-4 h-100">
      <h5>CPU 사용률</h5>
      <div class="resource-number my-3" id="resource-cpu">--.-%</div>
      <div class="resource-track" role="progressbar" aria-label="CPU 사용률" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="resource-cpu-track">
        <div class="resource-fill" id="resource-cpu-bar"></div>
      </div>
      <p class="resource-caption mt-3 mb-0">Windows 프로세서의 샘플 사용률</p>
    </div></div>
    <div class="col-md-6"><div class="resource-card p-4 h-100">
      <h5>메모리 사용률</h5>
      <div class="resource-number my-3" id="resource-memory">--.-%</div>
      <div class="resource-track" role="progressbar" aria-label="메모리 사용률" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" id="resource-memory-track">
        <div class="resource-fill" id="resource-memory-bar"></div>
      </div>
      <p class="resource-caption mt-3 mb-0" id="resource-memory-details">사용량 계산 중...</p>
    </div></div>
  </div>
  <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mt-3">
    <span class="small text-muted">3초마다 측정 완료 후 자동 갱신 · 서버 컴퓨터 기준</span>
    <button id="resource-retry" type="button" class="btn btn-outline-success btn-sm">지금 갱신</button>
  </div>
  <div id="resource-error" class="alert alert-danger mt-3 d-none" role="alert"></div>
</div>
<script>
(function () {
  const root = document.getElementById('resource-monitor');
  if (!root) return;
  const byId = id => document.getElementById(id);
  const status = byId('resource-status');
  const error = byId('resource-error');
  const retry = byId('resource-retry');
  let timer = null;
  let running = false;
  let stopped = false;

  function updateProgress(name, value) {
    const percent = Math.max(0, Math.min(100, Number(value)));
    byId('resource-' + name).textContent = percent.toFixed(1) + '%';
    byId('resource-' + name + '-bar').style.width = percent + '%';
    byId('resource-' + name + '-track').setAttribute('aria-valuenow', percent.toFixed(1));
  }
  async function refresh() {
    if (running || stopped || !document.contains(root)) return;
    running = true;
    clearTimeout(timer);
    retry.disabled = true;
    status.textContent = '측정 중...';
    try {
      const response = await fetch('index.php?cmd=resource&resource_data=1&_=' + Date.now(), {
        method: 'GET', credentials: 'same-origin', cache: 'no-store'
      });
      if (!response.ok) throw new Error('HTTP ' + response.status);
      const html = await response.text();
      const doc = new DOMParser().parseFromString(html, 'text/html');
      const node = doc.getElementById('resource-data-payload');
      if (!node) throw new Error('응답 데이터를 찾지 못했습니다. 관리자 로그인 상태를 확인하세요.');
      const payload = JSON.parse(node.textContent);
      if (!payload.ok) throw new Error(payload.message || '측정 실패');
      const data = payload.data;
      updateProgress('cpu', data.cpu_percent);
      updateProgress('memory', data.memory_percent);
      byId('resource-memory-details').textContent =
        '사용 ' + Number(data.memory_used_gb).toFixed(2) + 'GB / 전체 ' +
        Number(data.memory_total_gb).toFixed(2) + 'GB (여유 ' +
        Number(data.memory_free_gb).toFixed(2) + 'GB)';
      status.textContent = '마지막 측정: ' + data.measured_at;
      error.classList.add('d-none');
    } catch (e) {
      status.textContent = '측정 오류';
      error.textContent = e.message;
      error.classList.remove('d-none');
    } finally {
      running = false;
      retry.disabled = false;
      if (!stopped && document.contains(root)) timer = setTimeout(refresh, 3000);
    }
  }
  retry.addEventListener('click', refresh);
  window.addEventListener('pagehide', () => { stopped = true; clearTimeout(timer); });
  refresh();
})();
</script>
