<?php
/**
 * board.php - include from index.php?cmd=board
 * Requires index.php to have started the session and initialized $conn (mysqli).
 * Table: board(idx,id,name,gid,thread,title,notice,content,hit,file,fname,time,ip)
 * HTML/JS demo preview runs in a sandboxed iframe; uploads stored in data/board.
 */
if (session_status() !== PHP_SESSION_ACTIVE || !isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">index.php를 통해 접속해 주세요.</div>';
    return;
}

// Choose your admin policy here. In this training project the admin login is "admin".
$boardUserId = (string)($_SESSION['id'] ?? '');
$boardUserName = (string)($_SESSION['name'] ?? '');
$boardLoggedIn = $boardUserId !== '';
$boardIsAdmin = $boardLoggedIn && $boardUserId === 'admin';
$boardMode = (string)($_GET['mode'] ?? 'list');
if (!in_array($boardMode, ['list','show','write','delete'], true)) $boardMode = 'list';
$boardIdx = filter_var($_GET['idx'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$boardUidx = filter_var($_GET['uidx'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$boardRidx = filter_var($_GET['ridx'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$boardPage = max(1, (int)($_GET['page'] ?? 1));
$boardError = '';
$boardSuccess = '';
$boardSticky = null;

if (!isset($_SESSION['board_csrf'])) $_SESSION['board_csrf'] = bin2hex(random_bytes(32));
$boardCsrf = (string)$_SESSION['board_csrf'];

if (!function_exists('board_h')) {
    function board_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    // Render only simple formatting tags in board titles; never execute script in list rows.
    // Escape all user input first, then restore explicitly allowed tag tokens without attributes.
    function board_title_html(string $title): string {
        $escaped = board_h($title);
        return preg_replace_callback(
            '~&lt;(/?)(h[1-6]|b|strong|i|em|u|br)\s*/?&gt;~i',
            static function (array $m): string {
                $tag = strtolower($m[2]);
                if ($tag === 'br') return '<br>';
                return '<' . $m[1] . $tag . '>';
            },
            $escaped
        );
    }
    function board_url(array $query = []): string { return 'index.php?' . http_build_query(array_merge(['cmd'=>'board'], $query)); }
    function board_fetch(mysqli $db, int $idx): ?array {
        $s = $db->prepare('SELECT * FROM board WHERE idx=?');
        $s->bind_param('i', $idx); $s->execute();
        $r = $s->get_result()->fetch_assoc(); $s->close();
        return $r ?: null;
    }
    function board_thread_suffix(int $n): string {
        // A, B, ... Z, AA, AB, ... (supports more than 26 replies)
        $out = '';
        while ($n > 0) { $n--; $out = chr(65 + ($n % 26)) . $out; $n = intdiv($n, 26); }
        return $out;
    }
    function board_save_upload(array $up): array {
        if (($up['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return ['', ''];
        if (($up['error'] ?? 99) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$up['tmp_name'])) {
            throw new RuntimeException('첨부파일 업로드에 실패했습니다.');
        }
        if (($up['size'] ?? 0) > 10 * 1024 * 1024) throw new RuntimeException('첨부파일은 10MB 이하여야 합니다.');
        $original = basename(str_replace('\\','/',(string)($up['name'] ?? '')));
        $original = preg_replace('/[\x00-\x1f\x7f]/u', '', $original) ?: 'file';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $allowed = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif',
                    'webp'=>'image/webp','pdf'=>'application/pdf','txt'=>'text/plain','zip'=>'application/zip'];
        if (!isset($allowed[$ext])) throw new RuntimeException('허용된 파일 형식: jpg, jpeg, png, gif, webp, pdf, txt, zip');
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'], true)) {
            $info = @getimagesize((string)$up['tmp_name']);
            if (!$info || ($info['mime'] ?? '') !== $allowed[$ext]) throw new RuntimeException('이미지 파일 형식을 확인할 수 없습니다.');
        } else {
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file((string)$up['tmp_name']);
            $valid = match ($ext) {
                'pdf' => $mime === 'application/pdf',
                'txt' => in_array($mime, ['text/plain','text/x-php'], true) === false && $mime === 'text/plain',
                'zip' => in_array($mime, ['application/zip','application/x-zip','application/x-zip-compressed'], true),
                default => false
            };
            if (!$valid) throw new RuntimeException('첨부파일의 실제 형식이 확장자와 다릅니다.');
        }
        $dir = __DIR__ . '/data/board';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('첨부파일 폴더를 만들 수 없습니다.');
        // Apache: never execute code from the upload directory.
        $htaccess = $dir . '/.htaccess';
        if (!is_file($htaccess)) @file_put_contents($htaccess, "Options -ExecCGI\n<FilesMatch \"\\.(php[0-9]?|phtml|phar|cgi|pl|asp|aspx|jsp)$\">\nRequire all denied\n</FilesMatch>\n");
        // YYYYmmddHHMMss.ext. If same-second collision occurs, move forward a second.
        $target = '';
        for ($i=0; $i<100; $i++) {
            $candidate = date('YmdHis', time()+$i) . '.' . $ext;
            if (!file_exists($dir . '/' . $candidate)) { $target = $candidate; break; }
        }
        if ($target === '') throw new RuntimeException('첨부파일 이름을 생성하지 못했습니다.');
        if (!move_uploaded_file((string)$up['tmp_name'], $dir . '/' . $target)) throw new RuntimeException('첨부파일 저장에 실패했습니다.');
        return [$target, mb_substr($original, 0, 255, 'UTF-8')];
    }
    function board_quote(string $text): string {
        // Each source line prefixed with >>, as requested.
        $text = preg_replace('/\r\n?|\n/', "\n", $text);
        return "\n\n" . preg_replace('/^/m', '>> ', $text);
    }
    function board_file_url(string $filename): string {
        return 'data/board/' . rawurlencode($filename);
    }
    function board_preview_html(string $html): string {
        // Untrusted stored HTML runs only in an opaque-origin sandbox iframe.
        // Inline JS allowed for teaching; network access, forms, navigation blocked.
        $csp = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src data:; connect-src 'none'; form-action 'none'; base-uri 'none'";
        return '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
            . '<meta http-equiv="Content-Security-Policy" content="' . board_h($csp) . '">'
            . '<style>body{margin:12px;font:16px/1.6 system-ui,sans-serif;overflow-wrap:anywhere}</style>'
            . '</head><body>' . $html . '</body></html>';
    }
}

// POST action is handled before producing this page's content.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['board_action'] ?? '') !== '') {
    $action = (string)$_POST['board_action'];
    if (!$boardLoggedIn) $boardError = '로그인 후 이용할 수 있습니다.';
    elseif (!hash_equals($boardCsrf, (string)($_POST['csrf'] ?? ''))) $boardError = '잘못된 요청입니다. 새로고침 후 다시 시도하세요.';
    else {
        try {
            if ($action === 'save') {
                $uidx = max(0, (int)($_POST['uidx'] ?? 0));
                $ridx = max(0, (int)($_POST['ridx'] ?? 0));
                $title = trim((string)($_POST['title'] ?? ''));
                $content = (string)($_POST['content'] ?? '');
                $notice = $boardIsAdmin && isset($_POST['notice']) ? 1 : 0;
                if ($title === '' || mb_strlen($title, 'UTF-8') > 255) throw new RuntimeException('제목은 1~255자로 입력해 주세요.');
                if (trim($content) === '') throw new RuntimeException('내용을 입력해 주세요.');
                if (strlen($content) > 2 * 1024 * 1024) throw new RuntimeException('본문이 너무 깁니다.');
                if ($uidx && $ridx) throw new RuntimeException('잘못된 등록 요청입니다.');
                $old = $uidx ? board_fetch($conn, $uidx) : null;
                if ($uidx && !$old) throw new RuntimeException('수정할 글이 없습니다.');
                if ($old && !$boardIsAdmin && (string)$old['id'] !== $boardUserId) throw new RuntimeException('수정 권한이 없습니다.');
                $parent = $ridx ? board_fetch($conn, $ridx) : null;
                if ($ridx && !$parent) throw new RuntimeException('답글을 달 원글이 없습니다.');
                $fileName = $old['file'] ?? '';
                $originalName = $old['fname'] ?? '';
                $newFile = '';
                if (!empty($_FILES['attachment']) && ($_FILES['attachment']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    [$newFile, $newOriginal] = board_save_upload($_FILES['attachment']);
                    $fileName = $newFile; $originalName = $newOriginal;
                } elseif ($old && isset($_POST['remove_file'])) {
                    $fileName = ''; $originalName = '';
                }
                $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                $ip = mb_substr($ip, 0, 20, 'UTF-8');
                $redirectIdx = 0;
                if ($old) {
                    $s = $conn->prepare('UPDATE board SET title=?,content=?,notice=?,file=?,fname=? WHERE idx=?');
                    $s->bind_param('ssissi', $title, $content, $notice, $fileName, $originalName, $uidx);
                    $s->execute(); $s->close();
                    $redirectIdx = $uidx;
                } else {
                    // Serialize group/thread assignment so concurrent writers cannot pick the same position.
                    $lk = $conn->query("SELECT GET_LOCK('board_thread_allocate', 10) AS ok")->fetch_assoc();
                    if ((int)($lk['ok'] ?? 0) !== 1) throw new RuntimeException('잠시 후 다시 등록해 주세요.');
                    try {
                        $conn->begin_transaction();
                        if ($parent) {
                            $gid = (int)$parent['gid'];
                            $prefix = (string)$parent['thread'];
                            $s = $conn->prepare('SELECT thread FROM board WHERE gid=? AND thread LIKE CONCAT(?,\'%\')');
                            $s->bind_param('is', $gid, $prefix); $s->execute();
                            $rs = $s->get_result(); $used = [];
                            while ($r = $rs->fetch_assoc()) $used[(string)$r['thread']] = true;
                            $s->close();
                            // A + A, A + B, ..., AB + A, AB + B. Choose shortest available child thread.
                            $seq = 1;
                            while (isset($used[$prefix . board_thread_suffix($seq)])) $seq++;
                            $thread = $prefix . board_thread_suffix($seq);
                        } else {
                            $rs = $conn->query('SELECT COALESCE(MAX(gid),0)+1 AS next_gid FROM board');
                            $gid = (int)$rs->fetch_assoc()['next_gid'];
                            $rs->free();
                            $thread = 'A';
                        }
                        $s = $conn->prepare('INSERT INTO board (id,name,gid,thread,title,notice,content,hit,file,fname,time,ip) VALUES (?,?,?,?,?,?,?,0,?,?,NOW(),?)');
                        $s->bind_param('ssississss', $boardUserId, $boardUserName, $gid, $thread, $title, $notice, $content, $fileName, $originalName, $ip);
                        $s->execute(); $redirectIdx = (int)$conn->insert_id; $s->close();
                        $conn->commit();
                    } catch (Throwable $e) { $conn->rollback(); throw $e; }
                    finally { $conn->query("SELECT RELEASE_LOCK('board_thread_allocate')"); }
                }
                // The header and navbar were already sent by index.php, so use JS navigation.
                echo '<script>location.replace(' . json_encode(board_url(['mode'=>'show','idx'=>$redirectIdx]), JSON_UNESCAPED_SLASHES) . ');</script>';
                echo '<div class="alert alert-success">게시글이 저장되었습니다. <a href="' . board_h(board_url(['mode'=>'show','idx'=>$redirectIdx])) . '">글 보기</a></div>';
                return;
            }
            if ($action === 'delete') {
                $deleteIdx = max(0, (int)($_POST['idx'] ?? 0));
                $old = $deleteIdx ? board_fetch($conn, $deleteIdx) : null;
                if (!$old) throw new RuntimeException('삭제할 글이 없습니다.');
                if (!$boardIsAdmin && (string)$old['id'] !== $boardUserId) throw new RuntimeException('삭제 권한이 없습니다.');
                // Don't delete attachments: they may be retained for audit/backup.
                $s = $conn->prepare('DELETE FROM board WHERE idx=?');
                $s->bind_param('i', $deleteIdx); $s->execute(); $s->close();
                echo '<script>location.replace(' . json_encode(board_url(['mode'=>'list']), JSON_UNESCAPED_SLASHES) . ');</script>';
                echo '<div class="alert alert-success">삭제되었습니다. <a href="' . board_h(board_url()) . '">목록으로</a></div>';
                return;
            }
            throw new RuntimeException('알 수 없는 요청입니다.');
        } catch (Throwable $e) {
            if ($conn->errno) error_log('board.php: ' . $e->getMessage());
            $boardError = $e instanceof RuntimeException ? $e->getMessage() : '처리 중 오류가 발생했습니다.';
            $boardMode = $action === 'delete' ? 'delete' : 'write';
            $boardSticky = $_POST;
        }
    }
}
?>
<style>
.board-wrap{max-width:1100px;margin:auto}.board-title{color:#315341}
.board-table th{background:#e8f3eb!important}.board-table td{vertical-align:middle}
.board-small{color:#67756b;font-size:.9rem}.board-pre{white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.8}
.board-content{min-height:170px;white-space:pre-wrap;overflow-wrap:anywhere;line-height:1.85}
.board-file-image{max-width:100%;max-height:560px;object-fit:contain;border:1px solid #d9e6dd;border-radius:8px}
@media(max-width:576px){.board-hide-mobile{display:none!important}.board-wrap{font-size:.95rem}}
</style>
<div class="board-wrap">
  <div class="d-flex align-items-center justify-content-between mb-3">
    <h3 class="board-title mb-0">게시판</h3>
    <a class="btn btn-outline-secondary btn-sm" href="<?= board_h(board_url(['mode'=>'list'])) ?>">목록</a>
  </div>
<?php if ($boardError !== ''): ?><div class="alert alert-danger"><?= board_h($boardError) ?></div><?php endif; ?>
<?php if ($boardSuccess !== ''): ?><div class="alert alert-success"><?= board_h($boardSuccess) ?></div><?php endif; ?>
<?php
if ($boardMode === 'list'):
    $perPage = 10;
    $res = $conn->query('SELECT COUNT(*) AS n FROM board');
    $total = (int)$res->fetch_assoc()['n']; $res->free();
    $pages = max(1, (int)ceil($total/$perPage));
    $boardPage = min($boardPage,$pages);
    $offset = ($boardPage-1)*$perPage;
    $s = $conn->prepare('SELECT idx,gid,thread,title,name,notice,file,time,hit FROM board ORDER BY notice DESC, gid DESC, thread ASC, idx ASC LIMIT ? OFFSET ?');
    $s->bind_param('ii',$perPage,$offset); $s->execute(); $rows = $s->get_result()->fetch_all(MYSQLI_ASSOC); $s->close();
?>
  <div class="table-responsive">
    <table class="table table-hover board-table align-middle">
      <thead><tr>
        <th class="board-hide-mobile text-center" style="width:75px">순서</th>
        <th>제목</th><th style="width:100px">작성자</th>
        <th class="board-hide-mobile text-center" style="width:65px">파일</th>
        <th class="board-hide-mobile" style="width:155px">작성일</th>
        <th class="board-hide-mobile text-center" style="width:65px">읽음</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $i=>$r):
          $depth = max(0, strlen((string)$r['thread'])-1);
      ?>
        <tr>
          <td class="board-hide-mobile text-center"><?= $offset+$i+1 ?></td>
          <td>
            <a class="text-decoration-none text-dark" href="<?= board_h(board_url(['mode'=>'show','idx'=>(int)$r['idx'],'page'=>$boardPage])) ?>">
              <?php if ((int)$r['notice'] === 1): ?><span class="badge bg-danger me-1">공지</span><?php endif; ?>
              <?php if ($depth): ?><span class="text-muted" style="padding-left:<?= min(5,$depth)*10 ?>px">↳</span><?php endif; ?>
              <?= board_title_html((string)$r['title']) ?>
            </a>
          </td>
          <td><?= board_h($r['name']) ?></td>
          <td class="board-hide-mobile text-center"><?= $r['file'] ? '📎' : '' ?></td>
          <td class="board-hide-mobile"><?= board_h($r['time']) ?></td>
          <td class="board-hide-mobile text-center"><?= (int)$r['hit'] ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-muted py-4">등록된 글이 없습니다.</td></tr><?php endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages>1): ?>
  <nav aria-label="게시판 페이지"><ul class="pagination justify-content-center flex-wrap">
    <?php for ($p=max(1,$boardPage-4); $p<=min($pages,max(1,$boardPage-4)+8);$p++): ?>
      <li class="page-item <?= $p===$boardPage?'active':'' ?>"><a class="page-link" href="<?= board_h(board_url(['mode'=>'list','page'=>$p])) ?>"><?= $p ?></a></li>
    <?php endfor; ?>
  </ul></nav>
  <?php endif; ?>
  <div class="d-flex justify-content-end mt-3">
    <a class="btn btn-success" href="<?= board_h(board_url(['mode'=>'write'])) ?>">글쓰기</a>
  </div>
<?php
elseif ($boardMode === 'show'):
    $post = $boardIdx ? board_fetch($conn,$boardIdx) : null;
    if (!$post):
?>
  <div class="alert alert-warning">게시글이 존재하지 않습니다.</div>
<?php else:
    // Refresh-safe hit counter: increments for every show request, not attachments.
    $s = $conn->prepare('UPDATE board SET hit=hit+1 WHERE idx=?');
    $s->bind_param('i',$boardIdx);$s->execute();$s->close();
    $post['hit'] = (int)$post['hit']+1;
    $canEdit = $boardIsAdmin || ($boardLoggedIn && $boardUserId === (string)$post['id']);
    // Previous/next follows precisely the requested list order.
    $prev = null; $next = null;
    $s = $conn->prepare('SELECT idx FROM board WHERE notice > ? OR (notice = ? AND (gid > ? OR (gid = ? AND (thread < ? OR (thread = ? AND idx < ?))))) ORDER BY notice ASC, gid ASC, thread DESC, idx DESC LIMIT 1');
    $s->bind_param('iiiissi',$post['notice'],$post['notice'],$post['gid'],$post['gid'],$post['thread'],$post['thread'],$post['idx']);
    $s->execute();$prev = $s->get_result()->fetch_assoc();$s->close();
    $s = $conn->prepare('SELECT idx FROM board WHERE notice < ? OR (notice = ? AND (gid < ? OR (gid = ? AND (thread > ? OR (thread = ? AND idx > ?))))) ORDER BY notice DESC, gid DESC, thread ASC, idx ASC LIMIT 1');
    $s->bind_param('iiiissi',$post['notice'],$post['notice'],$post['gid'],$post['gid'],$post['thread'],$post['thread'],$post['idx']);
    $s->execute();$next = $s->get_result()->fetch_assoc();$s->close();
    $ext = strtolower(pathinfo((string)$post['file'],PATHINFO_EXTENSION));
    $isImage = in_array($ext,['jpg','jpeg','png','gif','webp'],true);
?>
  <div class="card shadow-sm">
    <div class="card-header bg-white py-3">
      <div class="mb-2"><?php if ((int)$post['notice']): ?><span class="badge bg-danger align-middle me-2">공지</span><?php endif; ?>
        <strong>제목 (HTML 실습 미리보기)</strong>
      </div>
      <iframe title="게시글 제목 HTML 미리보기" sandbox="allow-scripts allow-modals" referrerpolicy="no-referrer"
              class="w-100 border rounded bg-white" style="height:100px"
              srcdoc="<?= board_h(board_preview_html((string)$post['title'])) ?>"></iframe>
      <div class="board-small">작성자: <?= board_h($post['name']) ?> · 작성일: <?= board_h($post['time']) ?> · 읽음: <?= (int)$post['hit'] ?>
        <?php if ($boardIsAdmin): ?> · IP: <?= board_h($post['ip']) ?><?php endif; ?>
      </div>
    </div>
    <div class="card-body">
      <div class="small text-muted mb-2">내용 (HTML / JavaScript 실습 미리보기 - 격리된 실행 영역)</div>
      <iframe title="게시글 내용 HTML 미리보기" sandbox="allow-scripts allow-modals" referrerpolicy="no-referrer"
              class="w-100 border rounded bg-white mb-3" style="height:340px"
              srcdoc="<?= board_h(board_preview_html((string)$post['content'])) ?>"></iframe>
      <?php if ($post['file']): ?>
        <?php if ($isImage): ?><div class="my-3"><img class="board-file-image" src="<?= board_h(board_file_url($post['file'])) ?>" alt="첨부 이미지"></div><?php endif; ?>
        <div class="border-top pt-3">
          첨부파일: <a href="<?= board_h(board_file_url($post['file'])) ?>" download="<?= board_h($post['fname']) ?>"><?= board_h($post['fname']) ?></a>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="d-flex flex-wrap justify-content-between gap-2 mt-3">
    <div class="d-flex gap-2">
      <a class="btn btn-outline-secondary" href="<?= board_h(board_url(['mode'=>'list','page'=>$boardPage])) ?>">목록</a>
      <?php if ($boardLoggedIn): ?><a class="btn btn-success" href="<?= board_h(board_url(['mode'=>'write','ridx'=>$boardIdx])) ?>">답글</a><?php endif; ?>
    </div>
    <?php if ($canEdit): ?><div class="d-flex gap-2">
      <a class="btn btn-outline-primary" href="<?= board_h(board_url(['mode'=>'write','uidx'=>$boardIdx])) ?>">수정</a>
      <a class="btn btn-outline-danger" href="<?= board_h(board_url(['mode'=>'delete','idx'=>$boardIdx])) ?>">삭제</a>
    </div><?php endif; ?>
  </div>
  <div class="d-flex justify-content-between gap-2 mt-4">
    <div><?php if ($prev): ?><a href="<?= board_h(board_url(['mode'=>'show','idx'=>(int)$prev['idx']])) ?>">← 이전글</a><?php endif; ?></div>
    <div><?php if ($next): ?><a href="<?= board_h(board_url(['mode'=>'show','idx'=>(int)$next['idx']])) ?>">다음글 →</a><?php endif; ?></div>
  </div>
<?php endif;
elseif ($boardMode === 'write'):
    if (!$boardLoggedIn): ?>
      <div class="alert alert-warning">로그인 후 글을 작성할 수 있습니다.</div>
    <?php else:
        $editing = $boardUidx ? board_fetch($conn,$boardUidx) : null;
        $replyTo = (!$boardUidx && $boardRidx) ? board_fetch($conn,$boardRidx) : null;
        if (($boardUidx && !$editing) || ($boardRidx && !$replyTo && !$boardUidx)):
    ?>
      <div class="alert alert-warning">대상 게시글이 없습니다.</div>
    <?php elseif ($editing && !$boardIsAdmin && (string)$editing['id'] !== $boardUserId): ?>
      <div class="alert alert-danger">수정 권한이 없습니다.</div>
    <?php else:
        $title = (string)($boardSticky['title'] ?? ($editing['title'] ?? ($replyTo ? 'Re: ' . $replyTo['title'] : '')));
        $content = (string)($boardSticky['content'] ?? ($editing['content'] ?? ($replyTo ? board_quote((string)$replyTo['content']) : '')));
        $checkedNotice = isset($boardSticky) ? isset($boardSticky['notice']) : (int)($editing['notice'] ?? 0)===1;
    ?>
      <h5 class="mb-3"><?= $editing ? '글 수정' : ($replyTo ? '답글 작성' : '새 글 작성') ?></h5>
      <form action="<?= board_h(board_url(['mode'=>'write'])) ?>" method="post" enctype="multipart/form-data">
        <input type="hidden" name="csrf" value="<?= board_h($boardCsrf) ?>">
        <input type="hidden" name="board_action" value="save">
        <input type="hidden" name="uidx" value="<?= $editing ? (int)$editing['idx']:0 ?>">
        <input type="hidden" name="ridx" value="<?= $replyTo ? (int)$replyTo['idx']:0 ?>">
        <div class="mb-3"><label class="form-label" for="board-title-input">제목</label>
          <input class="form-control" type="text" id="board-title-input" name="title" maxlength="255" value="<?= board_h($title) ?>" required></div>
        <div class="mb-3"><label class="form-label">작성자</label>
          <input class="form-control" type="text" value="<?= board_h($editing['name'] ?? $boardUserName) ?>" readonly></div>
        <?php if ($boardIsAdmin): ?><div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" name="notice" value="1" id="board-notice" <?= $checkedNotice?'checked':'' ?>>
          <label class="form-check-label" for="board-notice">공지사항</label>
        </div><?php endif; ?>
        <div class="mb-3"><label class="form-label" for="board-content-input">내용 (HTML 및 JavaScript 실습 가능)</label>
          <textarea class="form-control" id="board-content-input" name="content" rows="12" required><?= board_h($content) ?></textarea></div>
        <div class="mb-3"><label class="form-label" for="board-file-input">첨부파일 (최대 10MB)</label>
          <input class="form-control" type="file" id="board-file-input" name="attachment" accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.txt,.zip">
          <?php if ($editing && $editing['file']): ?><div class="mt-2 small">현재 파일: <?= board_h($editing['fname']) ?>
            <label class="ms-2"><input type="checkbox" name="remove_file" value="1"> 기존 파일 제거</label></div><?php endif; ?>
        </div>
        <div class="d-flex justify-content-end gap-2">
          <a class="btn btn-outline-secondary" href="<?= board_h(board_url(['mode'=>'list'])) ?>">목록</a>
          <button type="submit" class="btn btn-success"><?= $editing?'수정':'등록' ?></button>
        </div>
        <div class="form-text mt-2">제목·내용에 HTML과 인라인 JavaScript를 입력할 수 있습니다. 실행은 글보기의 격리된 미리보기에서만 이루어지며, 목록에서는 제목의 기본 HTML 서식만 적용되고 JavaScript는 실행되지 않습니다.</div>
      </form>
    <?php endif; endif;
elseif ($boardMode === 'delete'):
    $target = $boardIdx ? board_fetch($conn,$boardIdx) : null;
    if (!$target): ?>
      <div class="alert alert-warning">게시글이 없습니다.</div>
    <?php elseif (!$boardIsAdmin && (!$boardLoggedIn || $boardUserId !== (string)$target['id'])): ?>
      <div class="alert alert-danger">삭제 권한이 없습니다.</div>
    <?php else: ?>
      <h5>게시글 삭제</h5>
      <p class="mb-1"><strong><?= board_h($target['title']) ?></strong></p>
      <div class="alert alert-danger mt-3">삭제한 글은 복구할 수 없습니다.<br>정말 삭제하시겠습니까?</div>
      <form method="post" action="<?= board_h(board_url(['mode'=>'delete','idx'=>$boardIdx])) ?>" onsubmit="return confirm('삭제한 글은 복구할 수 없습니다.\n정말 삭제하시겠습니까?');">
        <input type="hidden" name="board_action" value="delete">
        <input type="hidden" name="csrf" value="<?= board_h($boardCsrf) ?>">
        <input type="hidden" name="idx" value="<?= $boardIdx ?>">
        <a class="btn btn-outline-secondary" href="<?= board_h(board_url(['mode'=>'show','idx'=>$boardIdx])) ?>">취소</a>
        <button class="btn btn-danger" type="submit">삭제</button>
      </form>
    <?php endif;
endif;
?>
</div>
