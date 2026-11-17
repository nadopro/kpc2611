
<?php
// SQL Injection demonstration ONLY.
// index.php에서 session_start(), db.php 연결을 처리한다고 가정

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$message = '';
$messageType = 'danger';
$id = '';

// 로그아웃 처리
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['logout'])) {

    unset(
        $_SESSION['id'],
        $_SESSION['name'],
        $_SESSION['level']
    );

    $message = '로그아웃되었습니다.';
    $messageType = 'success';

}
// 로그인 처리
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $id = is_string($_POST['id'] ?? null)
        ? $_POST['id'] : '';

    $pass = is_string($_POST['pass'] ?? null)
        ? $_POST['pass'] : '';

    $id = str_replace("--","", $id);
    $id = str_replace("'", "", $id);
    $id = str_replace('"', "", $id);

    $pass = str_replace("--","", $pass);
    $pass = str_replace("'", "", $pass);
    $pass = str_replace('"', "", $pass);

    // SQL Injection 실습을 위한 취약 코드
    $sql = "select * from users where id='$id' and pass='$pass' ";

    //echo "$sql<br>";
    
    try {
        $result = mysqli_query($conn, $sql);
        $user = $result ? mysqli_fetch_assoc($result) : null;

        if ($user) {

            $_SESSION['id'] = $user['id'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['level'] = (int)$user['level'];

            $message = '로그인에 성공했습니다.';
            $messageType = 'success';

        } else {
            $message = '아이디 또는 비밀번호가 올바르지 않습니다.';
        }

    } catch (mysqli_sql_exception $e) {

        error_log(
            'SQL Injection lab query error: ' . $e->getMessage()
        );

        $message = 'SQL 실행 중 오류가 발생했습니다.';
    }
}
?>

<div class="container py-4">
    <div class="card border-0 shadow-sm mx-auto"
         style="max-width: 480px;">

        <div class="card-header text-white"
             style="background-color:#46765c;">
            <h4 class="mb-0">SQL Injection 로그인 실습</h4>
        </div>

        <div class="card-body"
             style="background-color:#f2faf3;">

            <?php if ($message !== ''): ?>
                <div class="alert alert-<?= $messageType ?>"
                     role="alert">
                    <?= htmlspecialchars(
                        $message,
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($_SESSION['id'])): ?>

                <!-- 로그인 상태 -->
                <div class="mb-3">
                    <strong>
                        <?= htmlspecialchars(
                            (string)$_SESSION['name'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>
                    </strong>님 로그인 중입니다.

                    <div class="small text-muted mt-2">
                        ID:
                        <?= htmlspecialchars(
                            (string)$_SESSION['id'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>

                        <br>

                        Level:
                        <?= (int)$_SESSION['level'] ?>
                    </div>
                </div>

                <!-- 로그아웃 버튼 -->
                <form method="post"
                      action="index.php?cmd=injection2">

                    <input type="hidden"
                           name="logout"
                           value="1">

                    <button type="submit"
                            class="btn btn-danger w-100">
                        로그아웃
                    </button>
                </form>

            <?php else: ?>

                <!-- 로그인 폼 -->
                <form method="post"
                      action="index.php?cmd=injection2">

                    <div class="mb-3">
                        <label for="login-id"
                               class="form-label">
                            아이디
                        </label>

                        <input type="text"
                               class="form-control"
                               id="login-id"
                               name="id"
                               value="<?= htmlspecialchars(
                                   $id,
                                   ENT_QUOTES,
                                   'UTF-8'
                               ) ?>"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="login-pass"
                               class="form-label">
                            비밀번호
                        </label>

                        <input type="password"
                               class="form-control"
                               id="login-pass"
                               name="pass"
                               required>
                    </div>

                    <button type="submit"
                            class="btn w-100 text-white"
                            style="background-color:#46765c;">
                        로그인
                    </button>

                </form>

            <?php endif; ?>

            <p class="small text-danger mt-3 mb-0">
                교육용 취약 코드입니다.
                외부 공개 서버에서 사용하지 마세요.
            </p>

        </div>
    </div>
</div>
