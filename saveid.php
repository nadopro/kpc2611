<?php
// saveid.php - LocalStorage credential-saving demonstration (local teaching only).
// index.php should include db.php and create $conn (mysqli).
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$message = '';
$messageType = 'danger';
$id = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    unset($_SESSION['id'], $_SESSION['name'], $_SESSION['level']);
    $message = '로그아웃되었습니다.';
    $messageType = 'success';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
    $pass = is_string($_POST['pass'] ?? null) ? $_POST['pass'] : '';

    try {
        // Prepared statements prevent SQL injection. The supplied original had
        // its $sql assignment commented out, leaving $sql undefined.
        $stmt = mysqli_prepare($conn, 'SELECT * FROM users WHERE id = ? AND pass = ? LIMIT 1');
        mysqli_stmt_bind_param($stmt, 'ss', $id, $pass);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $user = $result ? mysqli_fetch_assoc($result) : null;
        mysqli_stmt_close($stmt);

        if ($user) {
            session_regenerate_id(true);
            $_SESSION['id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['level'] = (int)$user['level'];
            $message = '로그인에 성공했습니다.';
            $messageType = 'success';
        } else {
            $message = '아이디 또는 비밀번호가 올바르지 않습니다.';
        }
    } catch (mysqli_sql_exception $e) {
        error_log('saveid.php login query error: ' . $e->getMessage());
        $message = 'SQL 실행 중 오류가 발생했습니다.';
    }
}
?>
<div class="container py-4">
    <div class="card border-0 shadow-sm mx-auto" style="max-width:480px;">
        <div class="card-header text-white" style="background-color:#46765c;">
            <h4 class="mb-0">로그인 정보 저장 실습</h4>
        </div>
        <div class="card-body" style="background-color:#f2faf3;">
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($messageType, ENT_QUOTES, 'UTF-8') ?>" role="alert">
                    <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['id'])): ?>
                <div class="mb-3">
                    <strong><?= htmlspecialchars((string)$_SESSION['name'], ENT_QUOTES, 'UTF-8') ?></strong>님 로그인 중입니다.
                    <div class="small text-muted mt-2">
                        ID: <?= htmlspecialchars((string)$_SESSION['id'], ENT_QUOTES, 'UTF-8') ?><br>
                        Level: <?= (int)$_SESSION['level'] ?>
                    </div>
                </div>
                <form method="post" action="index.php?cmd=saveid">
                    <input type="hidden" name="logout" value="1">
                    <button type="submit" class="btn btn-danger w-100">로그아웃</button>
                </form>
            <?php else: ?>
                <form id="saveid-login-form" method="post" action="index.php?cmd=saveid">
                    <div class="mb-3">
                        <label for="saveid-login-id" class="form-label">아이디</label>
                        <input type="text" class="form-control" id="saveid-login-id" name="id"
                               value="<?= htmlspecialchars($id, ENT_QUOTES, 'UTF-8') ?>"
                               autocomplete="username" required>
                    </div>
                    <div class="mb-3">
                        <label for="saveid-login-pass" class="form-label">비밀번호</label>
                        <input type="password" class="form-control" id="saveid-login-pass" name="pass"
                               autocomplete="current-password" required>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="saveid-remember-id">
                        <label class="form-check-label" for="saveid-remember-id">아이디 저장</label>
                    </div>
                    <div class="form-check mb-3">
                        <input type="checkbox" class="form-check-input" id="saveid-remember-pass">
                        <label class="form-check-label" for="saveid-remember-pass">비밀번호 저장</label>
                    </div>
                    <button type="submit" class="btn w-100 text-white" style="background-color:#46765c;">로그인</button>
                </form>
            <?php endif; ?>

            <p class="small text-danger mt-3 mb-0">
                교육용 예제입니다. localStorage에 저장된 비밀번호는 암호화되지 않고
                브라우저 스크립트에서 읽을 수 있습니다. 실제 계정이나 공용 컴퓨터에서는 사용하지 마세요.
            </p>
        </div>
    </div>
</div>
<script>
(function () {
    'use strict';
    const form = document.getElementById('saveid-login-form');
    if (!form) return;

    const idInput = document.getElementById('saveid-login-id');
    const passInput = document.getElementById('saveid-login-pass');
    const rememberId = document.getElementById('saveid-remember-id');
    const rememberPass = document.getElementById('saveid-remember-pass');
    const idKey = 'saveid.savedId.v2';
    const passKey = 'saveid.savedPass.v2';
    const oldKey = 'saveid.rememberedCredentials.v1';

    try {
        // 이전 버전의 아이디/비밀번호 묶음 저장값 제거
        localStorage.removeItem(oldKey);

        const savedId = localStorage.getItem(idKey);
        const savedPass = localStorage.getItem(passKey);

        if (savedId !== null) {
            idInput.value = savedId;
            rememberId.checked = true;
        }
        if (savedPass !== null) {
            passInput.value = savedPass;
            rememberPass.checked = true;
        }
    } catch (err) {
        console.warn('저장된 로그인 정보를 불러올 수 없습니다.', err);
    }

    // 저장 옵션을 끄면 해당 항목만 즉시 삭제
    rememberId.addEventListener('change', function () {
        if (!rememberId.checked) {
            try { localStorage.removeItem(idKey); }
            catch (err) { console.warn(err); }
        }
    });
    rememberPass.addEventListener('change', function () {
        if (!rememberPass.checked) {
            try { localStorage.removeItem(passKey); }
            catch (err) { console.warn(err); }
        }
    });

    form.addEventListener('submit', function () {
        try {
            if (rememberId.checked) {
                localStorage.setItem(idKey, idInput.value);
            } else {
                localStorage.removeItem(idKey);
            }

            if (rememberPass.checked) {
                localStorage.setItem(passKey, passInput.value);
            } else {
                localStorage.removeItem(passKey);
            }
        } catch (err) {
            console.warn('로그인 정보를 저장할 수 없습니다.', err);
        }
    });
})();
</script>
