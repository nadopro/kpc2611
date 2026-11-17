<?php
session_save_path(__DIR__ . '/sess');
session_start();

require_once __DIR__ . '/db.php';

// Connect once per request. Do not display connection details to visitors.
try {
    $conn = connectDB();
} catch (mysqli_sql_exception $e) {
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(503);
    exit('데이터베이스에 연결할 수 없습니다. 관리자에게 문의해 주세요.');
}

// Allow only simple page names: prevents path traversal / local file inclusion.
$cmd = $_GET['cmd'] ?? 'init';
if (!is_string($cmd) || !preg_match('/\A[a-zA-Z0-9_-]+\z/D', $cmd)) {
    http_response_code(400);
    $cmd = null;
}

$pages = [
    '시큐어코딩' => ['injection', 'injection2', 'saveid', 'saveid2'],
    '시큐어코딩2' => ['relation', 'shell','chart', 'brute', 'brute2', 'brute3',],
    'menu3' => ['menu3-1', 'menu3-2'],
];

// Page files must be placed in the same directory as index.php.
$pageFile = $cmd === null ? null : __DIR__ . '/' . $cmd . '.php';
if ($cmd !== null && !is_file($pageFile)) {
    http_response_code(404);
    $pageFile = null;
}
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KPC 보안 프로그래밍 과정</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --green-deep: #426d58;
            --green-deeper: #315341;
            --green-soft: #edf7ee;
            --green-border: #cfe3d2;
        }
        body {
            min-height: 100vh;
            background-color: var(--green-soft);
        }
        .site-navbar { background-color: var(--green-deep); }
        .site-navbar .navbar-brand,
        .site-navbar .nav-link { color: #fff; }
        .site-navbar .nav-link:hover,
        .site-navbar .nav-link:focus { color: #d9f3df; }
        .site-navbar .dropdown-menu { border-color: var(--green-border); }
        .site-navbar .dropdown-item:active { background-color: var(--green-deep); }
        .page-content {
            background: rgba(255, 255, 255, 0.72);
            border: 1px solid var(--green-border);
            border-radius: 1rem;
            padding: clamp(1.25rem, 3vw, 2.5rem);
        }
        .site-footer {
            background-color: #e0efe3;
            color: var(--green-deeper);
            border-top: 1px solid var(--green-border);
        }
        .site-footer a { color: var(--green-deeper); }
    </style>
</head>
<body class="d-flex flex-column">
    <nav class="navbar navbar-expand-lg navbar-dark site-navbar" aria-label="주 메뉴">
        <div class="container">
            <a class="navbar-brand fw-semibold" href="index.php">KPC Secure Coding</a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse"
                    data-bs-target="#mainNavbar" aria-controls="mainNavbar"
                    aria-expanded="false" aria-label="메뉴 열기">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="mainNavbar">
                <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                    <?php foreach ($pages as $menu => $submenus): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" role="button"
                               data-bs-toggle="dropdown" aria-expanded="false">
                                <?= htmlspecialchars($menu, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <ul class="dropdown-menu">
                                <?php foreach ($submenus as $submenu): ?>
                                    <li>
                                        <a class="dropdown-item" href="index.php?cmd=<?= rawurlencode($submenu) ?>">
                                            <?= htmlspecialchars($submenu, ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </nav>

    <main class="container flex-grow-1 py-4 py-lg-5">
        <div class="page-content shadow-sm">
            <?php if ($pageFile !== null): ?>
                <?php include $pageFile; ?>
            <?php else: ?>
                <h1 class="h4">페이지를 찾을 수 없습니다.</h1>
                <p class="mb-0">요청하신 페이지가 없거나 올바르지 않은 주소입니다.</p>
            <?php endif; ?>
        </div>
    </main>

    <footer class="site-footer mt-auto py-4">
        <div class="container text-center small">
            <div class="fw-semibold mb-1">한국생산성본부(KPC)</div>
            <div>정보보호책임자: 홍길동
                (<a href="mailto:help@kpc.or.kr">help@kpc.or.kr</a>)
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
