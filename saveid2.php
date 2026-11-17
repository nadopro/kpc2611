<?php
/**
 * KPC Secure Coding lab: encoding demonstration, NOT encryption.
 * Included by index.php after session_start() and connectDB().
 * Uses an intentionally SQL-injection-vulnerable query for isolated teaching only.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/db.php';
    $conn = connectDB();
}

if (!function_exists('saveid2Escape')) {
    function saveid2Escape($value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$message = '';
$messageType = 'danger';
$id = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? 'login';
    if ($action === 'logout') {
        unset($_SESSION['id'], $_SESSION['name'], $_SESSION['level']);
        //session_regenerate_id(true);
        $message = '로그아웃되었습니다.';
        $messageType = 'success';
    } elseif ($action === 'login') {
        // These are Base64 strings, not encrypted ciphertext.
        $id64 = $_POST['encoded_id'] ?? null;
        $pass64 = $_POST['encoded_pass'] ?? null;
        $id = is_string($id64) ? base64_decode($id64, true) : false;
        $pass = is_string($pass64) ? base64_decode($pass64, true) : false;

        if ($id === false || $pass === false || $id === '' || $pass === ''
            || strlen($id) > 500 || strlen($pass) > 500) {
            $id = '';
            $message = '입력 데이터가 올바르지 않습니다.';
        } else {
            // Intentionally unsafe SQL, retained to demonstrate SQL Injection.
            $sql = "select * from users where id='$id' and pass='$pass' ";
            try {
                $result = mysqli_query($conn, $sql);
                $user = $result ? mysqli_fetch_assoc($result) : null;
                if ($user) {
                    //session_regenerate_id(true);
                    $_SESSION['id'] = $user['id'];
                    $_SESSION['name'] = $user['name'];
                    $_SESSION['level'] = (int)$user['level'];
                    $message = '로그인에 성공했습니다.';
                    $messageType = 'success';
                } else {
                    $message = '아이디 또는 비밀번호가 올바르지 않습니다.';
                }
            } catch (mysqli_sql_exception $e) {
                error_log('KPC lab query error: ' . $e->getMessage());
                $message = 'SQL 실행 중 오류가 발생했습니다.';
            }
        }
    }
}
?>
<div class="container py-4">
  <div class="card border-0 shadow-sm mx-auto" style="max-width: 500px;">
    <div class="card-header text-white" style="background-color:#46765c;">
      <h4 class="mb-0">SQL Injection 로그인 실습 2</h4>
    </div>
    <div class="card-body" style="background-color:#f2faf3;">
      <?php if ($message !== ''): ?>
        <div class="alert alert-<?= saveid2Escape($messageType) ?>" role="alert">
          <?= saveid2Escape($message) ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($_SESSION['id'])): ?>
        <p class="mb-3">
          <strong><?= saveid2Escape($_SESSION['name'] ?? '') ?></strong>님 로그인 중입니다.<br>
          ID: <?= saveid2Escape($_SESSION['id']) ?> /
          Level: <?= (int)($_SESSION['level'] ?? 1) ?>
        </p>
        <form method="post" action="index.php?cmd=saveid2">
          <input type="hidden" name="action" value="logout">
          <button type="submit" class="btn btn-outline-danger w-100">로그아웃</button>
        </form>
      <?php else: ?>
        <form method="post" action="index.php?cmd=saveid2" id="loginForm">
          <input type="hidden" name="action" value="login">
          <input type="hidden" name="encoded_id" id="encoded_id">
          <input type="hidden" name="encoded_pass" id="encoded_pass">
          <div class="mb-3">
            <label for="login_id" class="form-label">ID</label>
            <div class="input-group">
              <div class="input-group-text">
                <input type="checkbox" id="save_id" class="form-check-input mt-0">
                <label for="save_id" class="ms-2 mb-0">저장</label>
              </div>
              <input type="text" id="login_id" class="form-control"
                     value="<?= saveid2Escape($id) ?>" placeholder="아이디 입력"
                     autocomplete="username" required>
            </div>
          </div>
          <div class="mb-3">
            <label for="login_pass" class="form-label">Password</label>
            <div class="input-group">
              <div class="input-group-text">
                <input type="checkbox" id="save_pass" class="form-check-input mt-0">
                <label for="save_pass" class="ms-2 mb-0">저장</label>
              </div>
              <input type="password" id="login_pass" class="form-control"
                     placeholder="비밀번호 입력" autocomplete="current-password" required>
              <button type="button" id="toggle_pass" class="btn btn-outline-secondary"
                      aria-label="비밀번호 보기" aria-pressed="false">보기</button>
            </div>
          </div>
          <button type="submit" class="btn w-100 text-white" style="background-color:#46765c;">
            로그인
          </button>
        </form>
      <?php endif; ?>
      <p class="small text-danger mt-3 mb-0">
        격리된 교육용 예제입니다. Base64는 암호화가 아닌 인코딩이며 누구나 복원할 수 있습니다.
        패킷/저장소의 실제 비밀번호 보호에는 HTTPS와 안전한 비밀번호 관리가 필요합니다.
        이 예제에는 SQL Injection 취약점도 남아 있습니다.
      </p>
    </div>
  </div>
</div>
<script>
(() => {
  'use strict';
  const form = document.getElementById('loginForm');
  if (!form) return;

  const id = document.getElementById('login_id');
  const pass = document.getElementById('login_pass');
  const saveId = document.getElementById('save_id');
  const savePass = document.getElementById('save_pass');
  const toggle = document.getElementById('toggle_pass');
  const encodedId = document.getElementById('encoded_id');
  const encodedPass = document.getElementById('encoded_pass');
  const ID_KEY = 'kpc_lab_saved_id';
  const PASS_KEY = 'kpc_lab2_encoded_pass';

  // UTF-8-safe Base64 conversion (e.g. Korean IDs/passwords).
  function encode(value) {
    const bytes = new TextEncoder().encode(value);
    let binary = '';
    for (const b of bytes) binary += String.fromCharCode(b);
    return btoa(binary);
  }
  function decode(value) {
    const binary = atob(value);
    const bytes = Uint8Array.from(binary, c => c.charCodeAt(0));
    return new TextDecoder('utf-8', {fatal: true}).decode(bytes);
  }

  try {
    // Delete plaintext left by the earlier saveid.php example.
    localStorage.removeItem('kpc_lab_saved_pass');
    const savedId = localStorage.getItem(ID_KEY);
    const savedPass = localStorage.getItem(PASS_KEY);
    if (savedId !== null) { id.value = savedId; saveId.checked = true; }
    if (savedPass !== null) {
      try { pass.value = decode(savedPass); savePass.checked = true; }
      catch (e) { localStorage.removeItem(PASS_KEY); }
    }
  } catch (e) { console.warn('저장소를 사용할 수 없습니다.', e); }

  saveId.addEventListener('change', () => {
    if (!saveId.checked) try { localStorage.removeItem(ID_KEY); } catch (e) {}
  });
  savePass.addEventListener('change', () => {
    if (!savePass.checked) try { localStorage.removeItem(PASS_KEY); } catch (e) {}
  });

  toggle.addEventListener('click', () => {
    const showing = pass.type === 'password';
    pass.type = showing ? 'text' : 'password';
    toggle.textContent = showing ? '숨기기' : '보기';
    toggle.setAttribute('aria-pressed', String(showing));
    toggle.setAttribute('aria-label', showing ? '비밀번호 숨기기' : '비밀번호 보기');
  });

  form.addEventListener('submit', (event) => {
    try {
      // The visible fields have no name, so raw values are not form fields.
      encodedId.value = encode(id.value);
      encodedPass.value = encode(pass.value);
      if (saveId.checked) localStorage.setItem(ID_KEY, id.value);
      else localStorage.removeItem(ID_KEY);
      if (savePass.checked) localStorage.setItem(PASS_KEY, encodedPass.value);
      else localStorage.removeItem(PASS_KEY);
    } catch (e) {
      event.preventDefault();
      alert('인코딩 중 오류가 발생했습니다.');
    }
  });
})();
</script>
