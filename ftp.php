<?php
/**
 * ftp.php - 관리자용 교육 파일 탐색기
 * include from index.php?cmd=ftp (session and $conn initialized in index.php)
 * 주의: PHP 및 실행 가능한 파일은 편집/생성/덮어쓰기를 지원하지 않습니다.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    echo '<div class="alert alert-danger">index.php를 통해 이용하세요.</div>';
    return;
}
$ftpAdmin = (string)($_SESSION['id'] ?? '') === 'admin' || (int)($_SESSION['level'] ?? 0) >= 9;
if (!$ftpAdmin) {
    echo '<div class="alert alert-warning">관리자만 이용할 수 있습니다.</div>';
    return;
}

$ftpRoot = realpath(__DIR__);
$ftpWorkspace = __DIR__ . DIRECTORY_SEPARATOR . 'ftp_workspace';
$ftpError = '';
$ftpMessage = '';
if (!is_dir($ftpWorkspace) && !@mkdir($ftpWorkspace, 0700, true) && !is_dir($ftpWorkspace)) {
    $ftpError = '작업 폴더를 만들 수 없습니다. 폴더 쓰기 권한을 확인하세요.';
}
// Direct HTTP access is denied on Apache with AllowOverride enabled.
$ftpDeny = "Require all denied\n";
if (is_dir($ftpWorkspace)) {
    @file_put_contents($ftpWorkspace . DIRECTORY_SEPARATOR . '.htaccess', $ftpDeny, LOCK_EX);
}
$ftpWorkspaceReal = realpath($ftpWorkspace) ?: $ftpWorkspace;
if (!isset($_SESSION['ftp_csrf'])) $_SESSION['ftp_csrf'] = bin2hex(random_bytes(32));
$ftpCsrf = (string)$_SESSION['ftp_csrf'];

if (!function_exists('ftp_h')) {
    function ftp_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    function ftp_inside(string $path, string $root): bool {
        $path = rtrim(str_replace('\\', '/', $path), '/');
        $root = rtrim(str_replace('\\', '/', $root), '/');
        return $path === $root || str_starts_with($path . '/', $root . '/');
    }
    function ftp_url(string $dir, string $file = ''): string {
        $q = ['cmd' => 'ftp', 'dir' => $dir];
        if ($file !== '') $q['file'] = $file;
        return 'index.php?' . http_build_query($q);
    }
    function ftp_hidden(string $name): bool {
        return str_starts_with($name, '.') || in_array(strtolower($name), [
            'db.php', 'config.php', 'configuration.php', 'wp-config.php',
            '.env', 'sess', 'session', 'sessions', 'vendor', 'node_modules'
        ], true);
    }
}

$ftpDir = (string)($_GET['dir'] ?? '');
$ftpDir = str_replace('\\', '/', $ftpDir);
$ftpResolvedDir = realpath($ftpRoot . DIRECTORY_SEPARATOR . $ftpDir);
if ($ftpResolvedDir === false || !is_dir($ftpResolvedDir) || !ftp_inside($ftpResolvedDir, $ftpRoot)) {
    $ftpResolvedDir = $ftpRoot;
    $ftpError = '잘못된 폴더 경로입니다.';
}
$ftpRelative = ltrim(str_replace('\\', '/', substr($ftpResolvedDir, strlen($ftpRoot))), '/');
if ($ftpRelative !== '') {
    foreach (explode('/', $ftpRelative) as $piece) {
        if (ftp_hidden($piece)) {
            $ftpResolvedDir = $ftpRoot;
            $ftpRelative = '';
            $ftpError = '이 폴더는 조회할 수 없습니다.';
            break;
        }
    }
}
$ftpIsWorkspace = ftp_inside($ftpResolvedDir, $ftpWorkspaceReal);

// Save only inert text documents within isolated workspace; never arbitrary PHP, HTML, JS or executable code.
$ftpTextExtensions = ['php','txt','md','csv','json','log','css','js','html','htm','xml','sql'];
$ftpEditableExtensions = ['txt', 'md', 'csv', 'json', 'log', 'php', 'html'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['ftp_action'] ?? '') === 'save') {
    if (!hash_equals($ftpCsrf, (string)($_POST['csrf'] ?? ''))) {
        $ftpError = '잘못된 요청입니다.';
    } elseif (!$ftpIsWorkspace) {
        $ftpError = '파일 저장은 ftp_workspace 폴더 안에서만 가능합니다.';
    } else {
        $ftpFilename = trim((string)($_POST['filename'] ?? ''));
        $ftpContent = (string)($_POST['content'] ?? '');
        $ftpExtension = strtolower(pathinfo($ftpFilename, PATHINFO_EXTENSION));
        if (!preg_match('/\A[a-zA-Z0-9가-힣_.-]{1,120}\z/uD', $ftpFilename)
            || str_starts_with($ftpFilename, '.')
            || str_contains($ftpFilename, '..')
            || !in_array($ftpExtension, $ftpEditableExtensions, true)) {
            $ftpError = '파일 이름은 일반 텍스트 확장자(txt, md, csv, json, log, php, html)만 허용합니다.';
        } elseif (strlen($ftpContent) > 1024 * 1024) {
            $ftpError = '텍스트 내용은 1MB 이하로 저장할 수 있습니다.';
        } else {
            $ftpDestination = $ftpResolvedDir . DIRECTORY_SEPARATOR . $ftpFilename;
            if (is_link($ftpDestination) || (file_exists($ftpDestination) && !is_file($ftpDestination))) {
                $ftpError = '저장 대상이 일반 파일이 아닙니다.';
            } else {
                $result = @file_put_contents($ftpDestination, $ftpContent, LOCK_EX);
                if ($result === false) $ftpError = '파일을 저장하지 못했습니다.';
                else $ftpMessage = $ftpFilename . ' 파일을 저장했습니다.';
            }
        }
    }
}

$ftpFolders = [];
$ftpFiles = [];
$scan = @scandir($ftpResolvedDir);
if (is_array($scan)) {
    foreach ($scan as $name) {
        if ($name === '.' || $name === '..' || ftp_hidden($name)) continue;
        $full = $ftpResolvedDir . DIRECTORY_SEPARATOR . $name;
        if (is_link($full)) continue;
        if (is_dir($full)) $ftpFolders[] = $name;
        elseif (is_file($full)) $ftpFiles[] = $name;
    }
}
sort($ftpFolders, SORT_NATURAL | SORT_FLAG_CASE);
sort($ftpFiles, SORT_NATURAL | SORT_FLAG_CASE);

$ftpNew = (($_GET['new'] ?? '') === '1');
$ftpSelected = $ftpNew ? '' : (string)($_GET['file'] ?? '');
$ftpPreviewKind = '';
$ftpPreviewText = '';
$ftpPreviewImage = '';
$ftpPreviewError = '';
$ftpCanEdit = false;
if ($ftpSelected !== '') {
    $full = $ftpResolvedDir . DIRECTORY_SEPARATOR . $ftpSelected;
    if (basename($ftpSelected) !== $ftpSelected || ftp_hidden($ftpSelected) || is_link($full) || !is_file($full)) {
        $ftpPreviewError = '파일을 열 수 없습니다.';
    } elseif (filesize($full) > 2 * 1024 * 1024) {
        $ftpPreviewError = '2MB 초과 파일은 미리보기를 지원하지 않습니다.';
    } else {
        $ext = strtolower(pathinfo($ftpSelected, PATHINFO_EXTENSION));
        $imageMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($full);
        if (in_array($mime, $imageMimes, true)) {
            $ftpPreviewKind = 'image';
            $ftpPreviewImage = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($full));
        } elseif (in_array($ext, $ftpTextExtensions, true)) {
            $ftpPreviewKind = 'text';
            $ftpPreviewText = (string)@file_get_contents($full);
            $ftpCanEdit = $ftpIsWorkspace && in_array($ext, $ftpEditableExtensions, true);
        } else {
            $ftpPreviewError = '미리보기를 지원하지 않는 파일 형식입니다.';
        }
    }
}
$ftpParentRelative = $ftpRelative === '' ? '' : trim(str_replace('\\', '/', dirname($ftpRelative)), '.');
$ftpParentRelative = trim($ftpParentRelative, '/');
?>
<style>
.ftp-pane {border:1px solid #dbe6de;border-radius:10px;overflow:hidden;background:white;min-height:390px}
.ftp-pane-head{background:#e8f3eb;color:#315341;padding:12px 16px;font-weight:700;border-bottom:1px solid #dbe6de}
.ftp-entry{display:block;padding:9px 15px;border-bottom:1px solid #edf1ed;color:#253d30;text-decoration:none;overflow-wrap:anywhere}
.ftp-entry:hover{background:#f0f8f2;color:#1e6d45}
.ftp-entry-active{background:#dcefe1;font-weight:700}
.ftp-offcanvas{width:min(680px,95vw)!important}
.ftp-preview{width:100%;font-family:Consolas,monospace;font-size:13px;resize:vertical}
</style>
<div class="container-fluid px-0" id="ftp-app">
  <h4 class="mb-3">파일 탐색기</h4>
  <div class="alert alert-info small">서버 파일은 읽기 전용으로 탐색할 수 있습니다. 파일 생성·편집은 접근 차단된 <strong>ftp_workspace</strong>의 일반 텍스트 문서(txt, md, csv, json, log, php, html)만 지원합니다. 다른 폴더의 PHP 등 소스는 읽기 전용입니다.</div>
  <?php if ($ftpError !== ''): ?><div class="alert alert-danger"><?= ftp_h($ftpError) ?></div><?php endif; ?>
  <?php if ($ftpMessage !== ''): ?><div class="alert alert-success"><?= ftp_h($ftpMessage) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="small text-muted">현재 위치: /<?= ftp_h($ftpRelative) ?></div>
    <a href="<?= ftp_h(ftp_url('')) ?>" class="btn btn-outline-success btn-sm">처음으로 가기</a>
  </div>
  <div class="row g-3">
    <div class="col-md-4">
      <div class="ftp-pane">
        <div class="ftp-pane-head">디렉터리 목록</div>
        <a class="ftp-entry" href="<?= ftp_h(ftp_url($ftpRelative)) ?>">📁 .</a>
        <a class="ftp-entry" href="<?= ftp_h(ftp_url($ftpParentRelative)) ?>">📁 ..</a>
        <?php foreach ($ftpFolders as $folder):
            $target = trim($ftpRelative . '/' . $folder, '/'); ?>
          <a class="ftp-entry" href="<?= ftp_h(ftp_url($target)) ?>">📁 <?= ftp_h($folder) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-md-8">
      <div class="ftp-pane">
        <div class="ftp-pane-head">파일 목록 (<?= count($ftpFiles) ?>개)</div>
        <?php if (!$ftpFiles): ?><div class="text-muted p-3">이 폴더에는 파일이 없습니다.</div><?php endif; ?>
        <?php foreach ($ftpFiles as $file): ?>
          <a class="ftp-entry <?= $ftpSelected === $file ? 'ftp-entry-active' : '' ?>" href="<?= ftp_h(ftp_url($ftpRelative, $file)) ?>" data-ftp-preview="1">📄 <?= ftp_h($file) ?></a>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
  <?php if ($ftpIsWorkspace): ?>
    <div class="mt-3"><a class="btn btn-success" href="<?= ftp_h(ftp_url($ftpRelative)) ?>&amp;new=1">새 텍스트 파일</a></div>
  <?php endif; ?>
</div>

<div class="offcanvas offcanvas-start ftp-offcanvas" tabindex="-1" id="ftp-preview-panel" aria-labelledby="ftp-preview-title">
  <div class="offcanvas-header">
    <h5 class="offcanvas-title" id="ftp-preview-title"><?= ftp_h($ftpSelected ?: '새 텍스트 파일') ?></h5>
    <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="닫기"></button>
  </div>
  <div class="offcanvas-body">
    <?php if ($ftpPreviewError !== ''): ?>
      <div class="alert alert-warning"><?= ftp_h($ftpPreviewError) ?></div>
    <?php elseif ($ftpPreviewKind === 'image'): ?>
      <img src="<?= ftp_h($ftpPreviewImage) ?>" alt="이미지 미리보기" class="img-fluid rounded border">
    <?php elseif ($ftpPreviewKind === 'text'): ?>
      <form method="post" action="<?= ftp_h(ftp_url($ftpRelative)) ?>">
        <input type="hidden" name="ftp_action" value="save">
        <input type="hidden" name="csrf" value="<?= ftp_h($ftpCsrf) ?>">
        <label class="form-label" for="ftp-content">파일 내용</label>
        <textarea class="form-control ftp-preview mb-3" rows="10" id="ftp-content" name="content" <?= $ftpCanEdit ? '' : 'readonly' ?>><?= ftp_h($ftpPreviewText) ?></textarea>
        <label class="form-label" for="ftp-filename">파일명</label>
        <input class="form-control mb-3" type="text" id="ftp-filename" name="filename" value="<?= ftp_h($ftpSelected) ?>" <?= $ftpCanEdit ? 'required' : 'readonly' ?>>
        <button class="btn btn-success" type="submit" <?= $ftpCanEdit ? '' : 'disabled' ?>>저장</button>
        <?php if (!$ftpCanEdit): ?>
          <p class="form-text mt-2">이 파일은 읽기 전용입니다. 저장은 ftp_workspace의 일반 텍스트 파일(txt, md, csv, json, log, php, html)에서만 가능합니다.</p>
        <?php endif; ?>
      </form>
    <?php elseif ($ftpIsWorkspace): ?>
      <form method="post" action="<?= ftp_h(ftp_url($ftpRelative)) ?>">
        <input type="hidden" name="ftp_action" value="save">
        <input type="hidden" name="csrf" value="<?= ftp_h($ftpCsrf) ?>">
        <label class="form-label" for="ftp-content-new">파일 내용</label>
        <textarea class="form-control ftp-preview mb-3" rows="10" id="ftp-content-new" name="content"></textarea>
        <label class="form-label" for="ftp-filename-new">파일명 (.txt, .md, .csv, .json, .log, .php, .html)</label>
        <input class="form-control mb-3" id="ftp-filename-new" name="filename" placeholder="example.txt" required>
        <button class="btn btn-success" type="submit">저장</button>
      </form>
    <?php endif; ?>
  </div>
</div>
<script>
(function(){
  function openFtpPanel(){
    if (!window.bootstrap || !bootstrap.Offcanvas) return;
    var panel=document.getElementById('ftp-preview-panel');
    if(panel) bootstrap.Offcanvas.getOrCreateInstance(panel).show();
  }
  window.addEventListener('load',function(){
    var params=new URLSearchParams(location.search);
    if(params.has('file') || params.get('new') === '1') openFtpPanel();
  });
})();
</script>
