<?php
/**
 * saveid.php - KPC SQL Injection / browser storage demonstration.
 * Include from index.php after $conn = connectDB() and session_start().
 * Intentionally vulnerable query and plaintext password storage are for an isolated lab ONLY.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (!isset($conn) || !($conn instanceof mysqli)) {
    require_once __DIR__ . '/db.php';
    $conn = connectDB();
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
        $id = is_string($_POST['id'] ?? null) ? $_POST['id'] : '';
        $pass = is_string($_POST['pass'] ?? null) ? $_POST['pass'] : '';

        // WARNING: Intentionally vulnerable to SQL Injection (educational demo only).
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
            error_log('SQL Injection lab query error: ' . $e->getMessage());
            $message = 'SQL 실행 중 오류가 발생했습니다.';
        }
    }
}

function saveidEscape($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<div class="container py-4">
    <div class="card border-0 shadow-sm mx-auto" style="max-width: 500px;">
        <div class="card-header text-white" style="background-color:#46765c;">
            <h4 class="mb-0">SQL Injection 로그인 실습</h4>
        </div>
        <div class="card-body" style="background-color:#f2faf3;">
            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= saveidEscape($messageType) ?>" role="alert">
                    <?= saveidEscape($message) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['id'])): ?>
                <p class="mb-3">
                    <strong><?= saveidEscape($_SESSION['name'] ?? '') ?></strong>님 로그인 중입니다.<br>
                    ID: <?= saveidEscape($_SESSION['id']) ?> /
                    Level: <?= (int)($_SESSION['level'] ?? 1) ?>
                </p>
                <form method="post" action="index.php?cmd=saveid">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-outline-danger w-100">로그아웃</button>
                </form>
            <?php else: ?>
                <form method="post" action="index.php?cmd=saveid" id="loginForm">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label for="login_id" class="form-label">ID</label>
                        <div class="input-group">
                            <div class="input-group-text">
                                <input type="checkbox" id="save_id" class="form-check-input mt-0">
                                <label for="save_id" class="ms-2 mb-0">저장</label>
                            </div>
                            <input type="text" id="login_id" name="id" class="form-control"
                                   value="<?= saveidEscape($id) ?>" placeholder="아이디 입력" autocomplete="username" required>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label for="login_pass" class="form-label">Password</label>
                        <div class="input-group">
                            <div class="input-group-text">
                                <input type="checkbox" id="save_pass" class="form-check-input mt-0">
                                <label for="save_pass" class="ms-2 mb-0">저장</label>
                            </div>
                            <input type="password" id="login_pass" name="pass" class="form-control"
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
                교육용 취약 코드입니다. 비밀번호는 브라우저에 평문 저장됩니다.
                외부 공개 서버나 실제 계정에서는 사용하지 마세요.
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
    const ID_KEY = 'kpc_lab_saved_id';
    const PASS_KEY = 'kpc_lab_saved_pass';

    // Storage can be unavailable (private browsing or browser settings).
    try {
        const savedId = localStorage.getItem(ID_KEY);
        const savedPass = localStorage.getItem(PASS_KEY);
        if (savedId !== null) {
            id.value = savedId;
            saveId.checked = true;
        }
        if (savedPass !== null) {
            pass.value = savedPass;
            savePass.checked = true;
        }
    } catch (e) {
        console.warn('실습용 저장소를 사용할 수 없습니다.', e);
    }

    saveId.addEventListener('change', () => {
        if (!saveId.checked) {
            try { localStorage.removeItem(ID_KEY); } catch (e) {}
        }
    });
    savePass.addEventListener('change', () => {
        if (!savePass.checked) {
            try { localStorage.removeItem(PASS_KEY); } catch (e) {}
        }
    });
    toggle.addEventListener('click', () => {
        const showing = pass.type === 'password';
        pass.type = showing ? 'text' : 'password';
        toggle.textContent = showing ? '숨기기' : '보기';
        toggle.setAttribute('aria-pressed', String(showing));
        toggle.setAttribute('aria-label', showing ? '비밀번호 숨기기' : '비밀번호 보기');
    });
    form.addEventListener('submit', () => {
        try {
            if (saveId.checked) localStorage.setItem(ID_KEY, id.value);
            else localStorage.removeItem(ID_KEY);
            if (savePass.checked) localStorage.setItem(PASS_KEY, pass.value);
            else localStorage.removeItem(PASS_KEY);
        } catch (e) { console.warn('입력값을 저장하지 못했습니다.', e); }
    });
})();
</script>
