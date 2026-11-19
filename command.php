<?php
// Include through index.php?cmd=command_terminal.
$terminalIsFetch = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['terminal_response'] ?? '') === 'json';
$terminalReply = static function (string $message, string $prompt): void {
    echo '<script type="application/json" id="db-terminal-response">',
        json_encode(
            ['message' => $message, 'prompt' => $prompt],
            JSON_HEX_TAG | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR
        ),
        '</script>';
};

if (session_status() !== PHP_SESSION_ACTIVE || empty($_SESSION['id'])) {
    if ($terminalIsFetch) {
        $terminalReply('로그인 세션을 확인할 수 없습니다. 다시 로그인해 주세요.', 'DB 실습>');
    } else {
        echo '<p class="alert alert-warning">먼저 로그인해 주세요.</p>';
    }
    return;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    if ($terminalIsFetch) {
        $terminalReply('index.php에서 DB 연결을 초기화하지 못했습니다.', 'DB 실습>');
    } else {
        echo '<p class="alert alert-danger">DB 연결이 준비되지 않았습니다.</p>';
    }
    return;
}

$terminalEscape = static fn(string $value): string =>
    htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$_SESSION['db_terminal_token'] ??= bin2hex(random_bytes(32));
$terminalActive = !empty($_SESSION['db_terminal_active']);
$terminalOutput = '';

// Accept a single SELECT against a table in the selected database.
$terminalSelect = static function (string $sql): ?string {
    $identifier = '(?:[\p{L}_][\p{L}\p{N}_]*|' . '\x60[\p{L}\p{N}_ -]+\x60)';
    $columnList = $identifier . '(?:\s*,\s*' . $identifier . ')*';
    $aggregate = '(?:COUNT\s*\(\s*\*\s*\)|(?:COUNT|SUM|AVG|MIN|MAX)\s*\(\s*'
        . $identifier . '\s*\))';
    $item = '(?:' . $identifier . '|' . $aggregate . ')(?:\s+AS\s+' . $identifier . ')?';
    // Only doubled apostrophes can escape quotes. Backslashes and controls are excluded.
    $literal = <<<'SQL_LITERAL'
(?:-?[0-9]+(?:\.[0-9]+)?|NULL|'(?:[^'\\\x00-\x1F]|'')*')
SQL_LITERAL;
    $predicate = $identifier . '(?:\s*(?:<=|>=|<>|!=|=|<|>)\s*' . $literal
        . '|\s+(?:NOT\s+)?LIKE\s+' . $literal . '|\s+IS\s+(?:NOT\s+)?NULL)';
    $orderItem = $identifier . '(?:\s+(?:ASC|DESC))?';
    $pattern = '~\ASELECT\s+(?:DISTINCT\s+)?(?:\*|' . $item . '(?:\s*,\s*' . $item . ')*)'
        . '\s+FROM\s+' . $identifier
        . '(?:\s+WHERE\s+' . $predicate . '(?:\s+(?:AND|OR)\s+' . $predicate . ')*)?'
        . '(?:\s+GROUP\s+BY\s+' . $columnList . ')?'
        . '(?:\s+ORDER\s+BY\s+' . $orderItem . '(?:\s*,\s*' . $orderItem . ')*)?'
        . '(?:\s+LIMIT\s+(?<limit>[0-9]{1,3})(?:\s+OFFSET\s+[0-9]{1,7})?)?\z~iu';

    if (preg_match($pattern, $sql, $matches) !== 1) {
        return null;
    }

    return isset($matches['limit']) ? $sql : $sql . ' LIMIT 200';
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $token = $_POST['terminal_token'] ?? '';
    $submitted = $_POST['terminal_input'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['db_terminal_token'], $token)) {
        $terminalOutput = '요청을 확인할 수 없습니다. 페이지를 새로고침해 주세요.';
    } elseif (!is_string($submitted) || strlen($submitted) > 3000) {
        $terminalOutput = '명령이 너무 깁니다.';
    } else {
        $sql = trim($submitted);
        if (str_ends_with($sql, ';')) {
            $sql = rtrim(substr($sql, 0, -1));
        }
        $command = strtolower(preg_replace('/[ \t]+/', ' ', $sql));

        try {
            if ($command === 'exit' || $command === 'quit' || $command === '\q') {
                $terminalActive = false;
                unset($_SESSION['db_terminal_active']);
                $terminalOutput = 'Bye';
            } elseif ($command === 'mysql -u secure') {
                if (!$conn->select_db('secure')) {
                    throw new RuntimeException('Could not select secure: ' . $conn->error);
                }
                $terminalActive = true;
                $_SESSION['db_terminal_active'] = true;
                $terminalOutput = "MariaDB 조회 실습 콘솔\n"
                    . "Server version: {$conn->server_info}\n"
                    . "Current database: secure\n"
                    . '명령 목록은 help를 입력하세요.';
            } elseif (!$terminalActive) {
                $terminalOutput = 'mysql -u secure를 입력해 조회 실습을 시작하세요.';
            } elseif ($command === 'help' || $command === '\h') {
                $terminalOutput = "SHOW TABLES;\nSELECT * FROM 테이블명 LIMIT 10;\n"
                    . "SELECT 컬럼명 FROM 테이블명 WHERE 컬럼명 IS NOT NULL ORDER BY 컬럼명;\n"
                    . "SELECT COUNT(*) AS total FROM 테이블명;\n"
                    . "SELECT DATABASE();\nSELECT VERSION();\nSELECT CONNECTION_ID();\nSELECT 1;\nexit";
            } else {
                $query = match ($command) {
                    'show tables' => 'SHOW TABLES',
                    'select database()' => 'SELECT DATABASE()',
                    'select version()' => 'SELECT VERSION()',
                    'select connection_id()' => 'SELECT CONNECTION_ID()',
                    'select 1' => 'SELECT 1',
                    default => $terminalSelect($sql),
                };
                if ($query === null) {
                    $terminalOutput = '단일 테이블 SELECT를 지원합니다. 사용 예는 help에서 확인하세요.';
                } else {
                    if (!$conn->select_db('secure')) {
                        throw new RuntimeException('Could not select secure: ' . $conn->error);
                    }
                    $result = $conn->query($query);
                    if (!($result instanceof mysqli_result)) {
                        throw new RuntimeException('Query did not return rows: ' . $conn->error);
                    }
                    $headers = array_map(static fn($field): string => $field->name, $result->fetch_fields());
                    $lines = [implode("\t", $headers)];
                    $shown = 0;
                    while ($shown < 200 && ($row = $result->fetch_row()) !== null) {
                        $cells = array_map(static fn($value): string =>
                            $value === null ? 'NULL' : str_replace(["\r", "\n", "\t"], ' ', (string) $value),
                            $row
                        );
                        $lines[] = implode("\t", $cells);
                        $shown++;
                    }
                    $lines[] = $result->num_rows > $shown
                        ? "{$shown} / {$result->num_rows} row(s) shown"
                        : "{$shown} row(s)";
                    $result->free();
                    $terminalOutput = implode("\n", $lines);
                }
            }
        } catch (Throwable $exception) {
            error_log('DB terminal: ' . $exception->getMessage());
            $terminalOutput = 'DB 요청에 실패했습니다. secure 데이터베이스와 연결 계정의 권한을 확인하세요.';
        }
    }
}

$terminalPrompt = $terminalActive ? 'MariaDB [secure]>' : 'DB 실습>';
if ($terminalIsFetch) {
    $terminalReply($terminalOutput, $terminalPrompt);
    return;
}
?>
<section id="db-terminal">
    <h3>MariaDB 대화형 조회 실습</h3>
    <p><code>mysql -u secure</code>를 입력한 뒤 Enter를 누르세요.</p>
    <div data-terminal-log class="bg-dark text-light rounded p-3 mb-2 font-monospace"
         role="log" aria-label="콘솔 실행 기록" tabindex="0"
         style="min-height:min(18rem, 60vh);max-height:60vh;overflow:auto;">
        <pre class="m-0 text-reset"
             style="white-space:pre-wrap;overflow-wrap:anywhere;"><?=
            $terminalEscape($terminalOutput)
        ?></pre>
    </div>
    <form method="post" action="index.php?cmd=command_terminal" class="input-group">
        <input type="hidden" name="terminal_token" value="<?= $terminalEscape($_SESSION['db_terminal_token']) ?>">
        <label data-terminal-prompt for="db-terminal-input" class="input-group-text font-monospace"><?=
            $terminalEscape($terminalPrompt)
        ?></label>
        <input id="db-terminal-input" name="terminal_input" class="form-control font-monospace"
               autocomplete="off" spellcheck="false" maxlength="1000" required>
        <button type="submit" class="btn btn-success">실행</button>
    </form>
</section>
<script>
(() => {
    const terminal = document.getElementById('db-terminal');
    const form = terminal.querySelector('form');
    const input = terminal.querySelector('#db-terminal-input');
    const prompt = terminal.querySelector('[data-terminal-prompt]');
    const log = terminal.querySelector('[data-terminal-log]');
    const history = [];
    let position = 0;
    let busy = false;

    const append = (value) => {
        const line = document.createElement('pre');
        line.className = 'mt-0 mb-3 text-reset';
        line.style.whiteSpace = 'pre-wrap';
        line.style.overflowWrap = 'anywhere';
        line.textContent = value;
        log.append(line);
        log.scrollTop = log.scrollHeight;
    };

    input.addEventListener('keydown', (event) => {
        if (event.key !== 'ArrowUp' && event.key !== 'ArrowDown') return;
        if (history.length === 0 || busy) return;
        event.preventDefault();
        position = Math.max(0, Math.min(history.length, position + (event.key === 'ArrowUp' ? -1 : 1)));
        input.value = history[position] ?? '';
    });

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const command = input.value.trim();
        if (busy || !command) return;
        const data = new URLSearchParams(new FormData(form));
        data.set('terminal_response', 'json');
        busy = true;
        input.readOnly = true;
        history.push(command);
        position = history.length;
        append(prompt.textContent.trim() + ' ' + command);
        try {
            const response = await fetch(window.location.href, {
                method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store'
            });
            if (!response.ok) {
                append('서버가 요청을 처리하지 못했습니다. HTTP ' + response.status);
                return;
            }
            if (response.redirected) {
                append('요청이 다른 페이지로 이동했습니다. 로그인 상태를 확인해 주세요.');
                return;
            }
            const html = await response.text();
            const payload = html.match(/<script type="application\/json" id="db-terminal-response">([^<]*)<\/script>/);
            if (!payload) {
                append('응답에서 콘솔 결과를 찾지 못했습니다. index.php의 cmd 경로와 PHP 오류 로그를 확인해 주세요.');
                return;
            }
            const result = JSON.parse(payload[1]);
            append(result.message);
            prompt.textContent = result.prompt;
            input.value = '';
        } catch {
            append('콘솔 응답을 읽지 못했습니다. 네트워크 연결과 PHP 오류 로그를 확인해 주세요.');
        } finally {
            busy = false;
            input.readOnly = false;
            input.focus();
        }
    });
})();
</script>
