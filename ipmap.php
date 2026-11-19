<?php
/**
 * ipmap.php - IP geolocation and Google Maps modal practice
 * Entry: index.php?cmd=ipmap
 * index.php owns session and DB connection.
 */
$ipinfoIp = '1.2.3.4';
$ipinfoOutput = '';
$ipinfoError = '';
$ipinfoHttpCode = null;
$ipmapLat = null;
$ipmapLon = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ipinfo_run'])) {
    $ipinfoIp = trim((string)($_POST['ip'] ?? ''));

    if (!filter_var($ipinfoIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $ipinfoError = '올바른 IPv4 주소를 입력해 주세요.';
    } elseif (!function_exists('curl_init')) {
        $ipinfoError = 'PHP cURL 확장이 활성화되지 않았습니다. php.ini의 extension=curl 설정을 확인해 주세요.';
    } else {
        // The free ip-api endpoint uses HTTP, not HTTPS.
        // The destination is fixed, and the user input is checked as IPv4.
        $url = 'http://ip-api.com/json/' . rawurlencode($ipinfoIp);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_USERAGENT => 'KPC-IPInfo-Lab/1.0',
        ]);

        $response = curl_exec($ch);
        $ipinfoHttpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $ipinfoError = 'API 요청 실패: ' . $curlError;
        } elseif ($ipinfoHttpCode !== 200) {
            $ipinfoError = 'API 응답 오류: HTTP ' . $ipinfoHttpCode;
            if ($response !== '') {
                $decoded = json_decode($response, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $ipinfoOutput = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        } else {
            $decoded = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $ipinfoError = 'JSON 변환 오류: ' . json_last_error_msg();
            } else {
                $ipinfoOutput = json_encode(
                    $decoded,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                );
                if (isset($decoded['status']) && $decoded['status'] === 'fail') {
                    $ipinfoError = 'API 조회 실패: ' . (string)($decoded['message'] ?? '조회할 수 없는 IP 주소입니다.');
                } elseif (($decoded['status'] ?? '') === 'success'
                    && isset($decoded['lat'], $decoded['lon'])
                    && is_numeric($decoded['lat']) && is_numeric($decoded['lon'])) {
                    $lat = (float)$decoded['lat'];
                    $lon = (float)$decoded['lon'];
                    if ($lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180) {
                        $ipmapLat = $lat;
                        $ipmapLon = $lon;
                    }
                }
            }
        }
    }
}
?>
<div class="container py-4">
  <div class="card border-0 shadow-sm mx-auto" style="max-width: 960px;">
    <div class="card-header text-white" style="background-color: #46765c;">
      <h4 class="mb-0">IP 상세정보 조회 (ip-api.com)</h4>
    </div>
    <div class="card-body">
      <form method="post" action="index.php?cmd=ipmap" class="row g-3 align-items-end">
        <div class="col-12 col-md-9">
          <label for="ipinfo-ip" class="form-label fw-semibold">IPv4 주소</label>
          <input type="text" class="form-control" id="ipinfo-ip" name="ip"
                 value="<?= htmlspecialchars($ipinfoIp, ENT_QUOTES, 'UTF-8') ?>"
                 placeholder="예: 1.2.3.4" required>
        </div>
        <div class="col-12 col-md-3 d-grid">
          <button type="submit" class="btn btn-success" name="ipinfo_run" value="1">실행</button>
        </div>
      </form>

      <?php if ($ipinfoError !== ''): ?>
        <div class="alert alert-warning mt-4" role="alert">
          <?= htmlspecialchars($ipinfoError, ENT_QUOTES, 'UTF-8') ?>
        </div>
      <?php endif; ?>

      <?php if ($ipmapLat !== null && $ipmapLon !== null): ?>
        <?php
          // Google Maps query order: latitude,longitude (lat,lon).
          $ipmapCoordinates = $ipmapLat . ',' . $ipmapLon;
          $ipmapMapUrl = 'https://www.google.com/maps?q=' . rawurlencode($ipmapCoordinates) . '&output=embed';
        ?>
        <div class="mt-4 mb-3">
          <button type="button" class="btn btn-primary" data-bs-toggle="modal"
                  data-bs-target="#ipmap-map-modal">
            지도보기 (<?= htmlspecialchars((string)$ipmapLat, ENT_QUOTES, 'UTF-8') ?>,
            <?= htmlspecialchars((string)$ipmapLon, ENT_QUOTES, 'UTF-8') ?>)
          </button>
        </div>

        <div class="modal fade" id="ipmap-map-modal" tabindex="-1"
             aria-labelledby="ipmap-map-title" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered ipmap-modal-dialog">
            <div class="modal-content ipmap-modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="ipmap-map-title">
                  Google 지도 (<?= htmlspecialchars($ipmapCoordinates, ENT_QUOTES, 'UTF-8') ?>)
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="닫기"></button>
              </div>
              <div class="modal-body p-0 ipmap-modal-body">
                <iframe id="ipmap-map-frame" title="IP 위치 지도"
                        data-map-url="<?= htmlspecialchars($ipmapMapUrl, ENT_QUOTES, 'UTF-8') ?>"
                        loading="lazy" referrerpolicy="no-referrer-when-downgrade"
                        allowfullscreen></iframe>
              </div>
              <div class="modal-footer py-2">
                <span class="small text-muted me-auto">위치는 IP 기반 추정값입니다.</span>
                <a class="btn btn-outline-secondary btn-sm"
                   href="<?= htmlspecialchars('https://www.google.com/maps?q=' . rawurlencode($ipmapCoordinates), ENT_QUOTES, 'UTF-8') ?>"
                   target="_blank" rel="noopener noreferrer">지도 직접 열기</a>
                <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">닫기</button>
              </div>
            </div>
          </div>
        </div>
      <?php endif; ?>

      <h5 class="mt-4 mb-2">JSON 결과</h5>
      <pre class="p-3 rounded mb-0" style="background:#17241c; color:#d6f5db; min-height:200px; max-height:650px; overflow:auto; white-space:pre-wrap; overflow-wrap:anywhere;"><?= htmlspecialchars($ipinfoOutput !== '' ? $ipinfoOutput : 'IP 주소를 입력하고 실행 버튼을 눌러 주세요.', ENT_QUOTES, 'UTF-8') ?></pre>
      <p class="small text-muted mt-3 mb-0">실습용: 무료 ip-api.com 엔드포인트는 HTTP를 사용합니다. IP 위치 정보는 추정값이며, API 사용량 제한이 적용될 수 있습니다.</p>
    </div>
  </div>
</div>

<style>
  /* 90% of the browser viewport in both dimensions. */
  #ipmap-map-modal .ipmap-modal-dialog {
    width: 90vw;
    max-width: 90vw;
    height: 90vh;
    max-height: 90vh;
    margin: auto;
  }
  #ipmap-map-modal .ipmap-modal-content { height: 90vh; }
  #ipmap-map-modal .ipmap-modal-body { flex: 1 1 auto; min-height: 0; }
  #ipmap-map-modal iframe {
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
  }
</style>
<script>
(function () {
  const modal = document.getElementById('ipmap-map-modal');
  if (!modal) return;
  const frame = document.getElementById('ipmap-map-frame');
  modal.addEventListener('show.bs.modal', function () {
    frame.src = frame.dataset.mapUrl;
  });
  modal.addEventListener('hidden.bs.modal', function () {
    frame.removeAttribute('src');
  });
})();
</script>
