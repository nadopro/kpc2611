<?php
/**
 * command.php - KPC Secure Coding 교육용 MySQL 콘솔
 * index.php?cmd=command 에서 include하여 사용합니다.
 * index.php가 session_start()와 $conn(mysqli) 초기화를 수행해야 합니다.
 * OS 명령을 실행하는 웹셸이 아닌, 기존 DB 연결을 사용하는 SQL 콘솔입니다.
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
    echo '<div class="alert alert-danger">index.php를 통해 접근해 주세요.</div>';
    return;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">DB 연결이 준비되지 않았습니다.</div>';
    return;
}
if (empty($_SESSION['id'])) {
    echo '<div class="alert alert-warning">먼저 로그인해 주세요.</div>';
    return;
}

if (!isset($_SESSION['command_console']) || !is_array($_SESSION['command_console'])) {
    $_SESSION['command_console'] = ['mysql_active' => false, 'database' => null];
}
if (empty($_SESSION['command_csrf'])) {
    $_SESSION['command_csrf'] = bin2hex(random_bytes(32));
}

$state =& $_SESSION['command_console'];
$input = '';
$output = '';
$error = false;

// SELECT 등 조회 결과는 탭 구분 텍스트로 출력합니다.
$formatResult = static function (mysqli_result $result): string {
    $fields = $result->fetch_fields();
    $headers = array_map(static fn($field) => $field->name, $fields);
    $lines = [implode("\t", $headers)];
    $rows = 0;
    while ($row = $result->fetch_row()) {
        $values = array_map(static function ($value): string {
            if ($value === null) return 'NULL';
            if (!is_scalar($value)) return '[value]';
            return str_replace(["\r", "\n", "\t"], [' ', ' ', ' '], (string)$value);
        }, $row);
        $lines[] = implode("\t", $values);
        $rows++;
        if ($rows >= 200) {
            $lines[] = '(화면 출력은 최대 200행으로 제한됩니다.)';
            break;
        }
    }
    $result->free();
    $lines[] = "\n{$rows} row(s) shown";
    return implode("\n", $lines);
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['command_csrf'], $token)) {
        $error = true;
        $output = '잘못된 요청입니다. 화면을 새로고침해 주세요.';
    } else {
        $input = trim((string)($_POST['command'] ?? ''));
        if ($input === '') {
            $error = true;
            $output = '명령을 입력해 주세요.';
        } elseif (preg_match('/^mysql\s+-u\s+secure\s*$/i', $input)) {
            // 이미 index.php가 인증한 DB 연결을 확인합니다.
            // 실제 mysql.exe 신규 로그인이나 비밀번호 검증을 수행하지 않습니다.
            try {
                $r = $conn->query('SELECT USER() AS login_user, CURRENT_USER() AS grant_user, VERSION() AS server_version, DATABASE() AS db_name');
                $info = $r->fetch_assoc();
                $r->free();
                $loginUser = (string)$info['login_user'];
                $account = explode('@', $loginUser, 2)[0];
                if (strcasecmp($account, 'secure') !== 0) {
                    $state['mysql_active'] = false;
                    $error = true;
                    $output = "secure 계정 연결을 확인할 수 없습니다.\n기존 DB 연결 계정: {$loginUser}\nindex.php의 DB 설정을 확인하세요.";
                } else {
                    $state['mysql_active'] = true;
                    $state['database'] = $info['db_name'];
                    $output = "Welcome to the MySQL/MariaDB practice monitor.\n"
                        . "Connection: 기존 index.php DB 연결 재사용\n"
                        . "Logged in as: {$loginUser}\n"
                        . "Grant account: {$info['grant_user']}\n"
                        . "Server version: {$info['server_version']}\n"
                        . "Current database: " . ($info['db_name'] ?: '(none)') . "\n\nmysql>";
                }
            } catch (mysqli_sql_exception $e) {
                $state['mysql_active'] = false;
                $error = true;
                $output = 'DB 연결 확인 실패: ' . $e->getMessage();
            }
        } elseif (preg_match('/^mysql\b/i', $input)) {
            $error = true;
            $output = "이 실습 화면의 접속 형식은 mysql -u secure 입니다.\n실제 mysql.exe 로그인 또는 -p 비밀번호 프롬프트는 제공하지 않습니다.";
        } elseif (preg_match('/^(exit|quit|\\\\q)\s*;?$/i', $input)) {
            $state['mysql_active'] = false;
            $state['database'] = null;
            $output = "Bye\n(MySQL 실습 콘솔 종료)";
        } elseif (preg_match('/^php\s+-v\s*$/i', $input)) {
            $output = 'PHP ' . PHP_VERSION . "\nSAPI: " . PHP_SAPI . "\nOS: " . PHP_OS_FAMILY;
        } elseif (preg_match('/^php\s+-m\s*$/i', $input)) {
            $output = implode("\n", get_loaded_extensions());
        } elseif (
            preg_match('~^(?:php|(?:[A-Za-z]:[\\\\/][^\r\n"<>|]+[\\\\/])?php\.exe)\s+-r\s+["\']phpinfo\(\);["\']\s*$~i', $input)
            || preg_match('~^php\s+-r\s+["\']phpinfo\(\);["\']\s*$~i', $input)
        ) {
            ob_start();
            phpinfo(INFO_GENERAL | INFO_CONFIGURATION | INFO_MODULES);
            $html = (string)ob_get_clean();
            $output = trim(html_entity_decode(strip_tags(preg_replace('~</(?:tr|h[1-6]|p|li)>~i', "\n", $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        } elseif (!$state['mysql_active']) {
            $error = true;
            $output = "MySQL 실습 콘솔에 접속하지 않았습니다.\n먼저 mysql -u secure 를 입력하세요.\n참고: 운영체제 임의 명령 실행 기능은 포함되지 않습니다.";
        } else {
            try {
                // 매 요청에 DB 연결은 새로 생성될 수 있어 선택 DB를 복원합니다.
                if (is_string($state['database']) && $state['database'] !== '') {
                    $conn->select_db($state['database']);
                }
                // mysql CLI 관례: 마지막 세미콜론을 붙이지 않아도 SQL 실행.
                $sql = preg_replace('/;\s*$/', '', $input);
                $result = $conn->query($sql);
                if ($result instanceof mysqli_result) {
                    $output = $formatResult($result);
                } else {
                    $output = 'Query OK, ' . $conn->affected_rows . ' row(s) affected.';
                }
                $dbResult = $conn->query('SELECT DATABASE() AS db_name');
                $dbRow = $dbResult->fetch_assoc();
                $dbResult->free();
                $state['database'] = $dbRow['db_name'];
                $output .= "\n\nmysql>";
            } catch (mysqli_sql_exception $e) {
                $error = true;
                $output = 'SQL Error: ' . $e->getMessage() . "\n\nmysql>";
            }
        }
    }
}
?>
<div class="container py-4">
    <div class="card border-0 shadow-sm">
        <div class="card-header text-white" style="background-color:#46765c;">
            <h4 class="mb-0">Command 실습 콘솔</h4>
        </div>
        <div class="card-body">
            <div class="small text-muted mb-3">
                <?= !empty($state['mysql_active']) ? 'MySQL 콘솔 연결 상태 (기존 DB 연결 사용)' : 'MySQL 콘솔 미접속' ?>
                <?php if (!empty($state['database'])): ?>
                    · DB: <?= htmlspecialchars((string)$state['database'], ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
            <form method="post" action="index.php?cmd=command">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['command_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                <label for="command-input" class="form-label fw-bold">명령 입력</label>
                <textarea id="command-input" name="command" class="form-control font-monospace" rows="3" spellcheck="false" placeholder="mysql -u secure 또는 SQL 입력" required><?= htmlspecialchars($input, ENT_QUOTES, 'UTF-8') ?></textarea>
                <button type="submit" class="btn btn-success mt-3">실행</button>
            </form>
            <hr>
            <h5>실행 결과</h5>
            <pre class="p-3 rounded" style="background:#17241c;color:<?= $error ? '#ffaaaa' : '#b8f5c7' ?>;min-height:230px;max-height:550px;overflow:auto;white-space:pre-wrap;overflow-wrap:anywhere;"><?= htmlspecialchars($output, ENT_QUOTES, 'UTF-8') ?></pre>
            <p class="small text-muted mb-0">사용 예: mysql -u secure → SHOW DATABASES; → USE test; → SHOW TABLES; → exit<br>php -v, php -m, php -r "phpinfo();"도 시연할 수 있습니다. PHP 정보는 웹서버 환경의 출력입니다. 실제 OS 명령이나 mysql.exe를 실행하지 않습니다.</p>
            <p class="small text-danger mt-2 mb-0">교육 전용: 입력한 SQL은 연결 계정 권한으로 실제 실행됩니다. 운영 DB나 외부 공개 서버에서는 사용하지 마세요.</p>
        </div>
    </div>
</div>
