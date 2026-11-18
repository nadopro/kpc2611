<?php
// join.php - included by index.php (session and $conn already initialized)
if (session_status() !== PHP_SESSION_ACTIVE || !isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">index.php를 통해 접속해 주세요.</div>';
    return;
}

if (empty($_SESSION['join_csrf'])) {
    $_SESSION['join_csrf'] = bin2hex(random_bytes(32));
}

$esc = static fn($value) => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$validId = static fn($value) => is_string($value) && preg_match('/\A[A-Za-z0-9_]{4,40}\z/D', $value) === 1;
$message = '';
$messageType = 'danger';
$postedId = '';
$postedName = '';
$joined = false;

// AJAX check uses the same index.php route. The enclosing index.php emits HTML,
// so return a tiny HTML marker which JavaScript reads with DOMParser.
if (isset($_GET['ajax']) && $_GET['ajax'] === 'check_id') {
    $candidate = $_GET['id'] ?? '';
    $available = false;
    $reason = '아이디는 영문, 숫자, 밑줄(_)로 4~40글자여야 합니다.';
    if (is_string($candidate) && mb_strlen($candidate, 'UTF-8') < 4) {
        $reason = '아이디는 4글자 이상입니다.';
    } elseif ($validId($candidate)) {
        try {
            $stmt = $conn->prepare('SELECT 1 FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('s', $candidate);
            $stmt->execute();
            $stmt->store_result();
            $available = $stmt->num_rows === 0;
            $reason = $available ? '사용 가능한 아이디입니다.' : '이미 사용 중인 아이디입니다.';
            $stmt->close();
        } catch (Throwable $e) {
            error_log('join ID check failed: ' . $e->getMessage());
            $reason = '중복검사를 완료하지 못했습니다.';
        }
    }
    echo '<span id="join-check-result" data-available="' . ($available ? '1' : '0') . '">' . $esc($reason) . '</span>';
    return;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['join_action'] ?? '') === 'signup') {
    $postedId = is_string($_POST['id'] ?? null) ? trim($_POST['id']) : '';
    $postedName = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
    $pass = $_POST['pass'] ?? null;
    $pass2 = $_POST['pass2'] ?? null;
    $token = $_POST['csrf'] ?? '';

    if (!is_string($token) || !hash_equals($_SESSION['join_csrf'], $token)) {
        $message = '요청을 확인할 수 없습니다. 페이지를 새로고침해 주세요.';
    } elseif (!$validId($postedId)) {
        $message = mb_strlen($postedId, 'UTF-8') < 4
            ? '아이디는 4글자 이상입니다.'
            : '아이디는 영문, 숫자, 밑줄(_)로 4~40글자여야 합니다.';
    } elseif ($postedName === '' || mb_strlen($postedName, 'UTF-8') > 100) {
        $message = '이름은 1~100글자로 입력해 주세요.';
    } elseif (!is_string($pass) || strlen($pass) < 8 || strlen($pass) > 72) {
        $message = '비밀번호는 8~72바이트로 입력해 주세요.';
    } elseif (!is_string($pass2) || !hash_equals($pass, $pass2)) {
        $message = '비밀번호가 서로 일치하지 않습니다.';
    } else {
        try {
            // Also validate on the server; the browser's AJAX status cannot be trusted.
            $stmt = $conn->prepare('SELECT 1 FROM users WHERE id = ? LIMIT 1');
            $stmt->bind_param('s', $postedId);
            $stmt->execute();
            $stmt->store_result();
            $exists = $stmt->num_rows > 0;
            $stmt->close();

            if ($exists) {
                $message = '이미 사용 중인 아이디입니다.';
            } else {
                $hash = password_hash($pass, PASSWORD_DEFAULT);
                $level = 1;
                $stmt = $conn->prepare('INSERT INTO users (id, name, pass, level) VALUES (?, ?, ?, ?)');
                $stmt->bind_param('sssi', $postedId, $postedName, $hash, $level);
                $stmt->execute();
                $stmt->close();
                $joined = true;
                $messageType = 'success';
                $message = '회원가입이 완료되었습니다. 로그인해 주세요.';
                $postedId = '';
                $postedName = '';
                $_SESSION['join_csrf'] = bin2hex(random_bytes(32));
            }
        } catch (mysqli_sql_exception $e) {
            error_log('join signup failed: ' . $e->getMessage());
            $message = $e->getCode() === 1062 ? '이미 사용 중인 아이디입니다.' : '회원가입 처리 중 오류가 발생했습니다. 테이블 구조와 DB 권한을 확인해 주세요.';
        } catch (Throwable $e) {
            error_log('join signup failed: ' . $e->getMessage());
            $message = '회원가입 처리 중 오류가 발생했습니다.';
        }
    }
}
?>
<div class="container py-4" id="join-page">
  <div class="card border-0 shadow-sm mx-auto" style="max-width:560px;">
    <div class="card-header text-white" style="background-color:#46765c;">
      <h4 class="mb-0">회원가입</h4>
    </div>
    <div class="card-body" style="background-color:#f2faf3;">
      <?php if ($message !== ''): ?>
        <div class="alert alert-<?= $esc($messageType) ?>" role="alert"><?= $esc($message) ?></div>
      <?php endif; ?>
      <?php if (!$joined): ?>
      <form id="join-form" method="post" action="index.php?cmd=join" autocomplete="off">
        <input type="hidden" name="join_action" value="signup">
        <input type="hidden" name="csrf" value="<?= $esc($_SESSION['join_csrf']) ?>">
        <div class="mb-3">
          <label class="form-label fw-semibold" for="join-id">아이디</label>
          <input class="form-control" id="join-id" name="id" type="text" maxlength="40" value="<?= $esc($postedId) ?>" required autocomplete="username" aria-describedby="join-id-status">
          <div id="join-id-status" class="form-text" aria-live="polite">아이디는 4글자 이상입니다.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="join-name">이름</label>
          <input class="form-control" id="join-name" name="name" type="text" maxlength="100" value="<?= $esc($postedName) ?>" required autocomplete="name">
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="join-pass">비밀번호</label>
          <input class="form-control" id="join-pass" name="pass" type="password" minlength="8" required autocomplete="new-password">
          <div class="form-text">8글자 이상 입력해 주세요.</div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-semibold" for="join-pass2">비밀번호 확인</label>
          <input class="form-control" id="join-pass2" name="pass2" type="password" minlength="8" required autocomplete="new-password">
          <div id="join-pass-status" class="form-text" aria-live="polite">비밀번호를 다시 입력해 주세요.</div>
        </div>
        <button type="submit" id="join-submit" class="btn btn-success w-100" disabled>회원가입</button>
      </form>
      <?php else: ?>
        <a href="index.php?cmd=injection" class="btn btn-success">로그인 페이지로 이동</a>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php if (!$joined): ?>
<script>
(() => {
    const id = document.getElementById('join-id');
    const name = document.getElementById('join-name');
    const pass = document.getElementById('join-pass');
    const pass2 = document.getElementById('join-pass2');
    const idStatus = document.getElementById('join-id-status');
    const passStatus = document.getElementById('join-pass-status');
    const submit = document.getElementById('join-submit');
    const form = document.getElementById('join-form');
    let availableId = null;
    let requestNumber = 0;
    let controller = null;

    function validate() {
        const match = pass.value !== '' && pass.value === pass2.value;
        passStatus.textContent = match ? '비밀번호가 일치합니다.' : (pass2.value ? '비밀번호가 일치하지 않습니다.' : '비밀번호를 다시 입력해 주세요.');
        passStatus.className = match ? 'form-text text-success' : 'form-text text-danger';
        submit.disabled = !(availableId === id.value && match && pass.value.length >= 8 && name.value.trim().length > 0);
    }

    async function checkId() {
        const value = id.value.trim();
        id.value = value;
        availableId = null;
        submit.disabled = true;
        const currentRequest = ++requestNumber;
        if (controller) controller.abort();
        if (value.length < 4) {
            idStatus.textContent = '아이디는 4글자 이상입니다.';
            idStatus.className = 'form-text text-danger';
            return;
        }
        if (!/^[A-Za-z0-9_]{4,40}$/.test(value)) {
            idStatus.textContent = '아이디는 영문, 숫자, 밑줄(_)로 4~40글자여야 합니다.';
            idStatus.className = 'form-text text-danger';
            return;
        }
        idStatus.textContent = '아이디 중복 확인 중...';
        idStatus.className = 'form-text text-secondary';
        controller = new AbortController();
        try {
            const url = new URL('index.php', window.location.href);
            url.searchParams.set('cmd', 'join');
            url.searchParams.set('ajax', 'check_id');
            url.searchParams.set('id', value);
            const response = await fetch(url.toString(), {cache:'no-store', credentials:'same-origin', signal:controller.signal});
            if (!response.ok) throw new Error('HTTP ' + response.status);
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const result = doc.getElementById('join-check-result');
            if (!result) throw new Error('AJAX 응답을 확인할 수 없습니다.');
            if (currentRequest !== requestNumber || id.value !== value) return;
            const available = result.dataset.available === '1';
            availableId = available ? value : null;
            idStatus.textContent = result.textContent;
            idStatus.className = available ? 'form-text text-success' : 'form-text text-danger';
            validate();
        } catch (e) {
            if (e.name === 'AbortError' || currentRequest !== requestNumber) return;
            availableId = null;
            idStatus.textContent = '중복검사를 완료하지 못했습니다.';
            idStatus.className = 'form-text text-danger';
            validate();
        }
    }

    id.addEventListener('input', checkId);
    [name, pass, pass2].forEach(el => el.addEventListener('input', validate));
    form.addEventListener('submit', event => {
        validate();
        if (submit.disabled) event.preventDefault();
    });
    if (id.value) checkId();
    else validate();
})();
</script>
<?php endif; ?>
