<?php
// index.php?cmd=slow&com=1&count=3
// index.php handles the session and database connection.
// Release the session lock so requests from this browser can overlap.
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$com = filter_input(INPUT_GET, 'com', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 50]]);
$count = filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 30]]);
if ($com === false || $com === null || $count === false || $count === null) {
    http_response_code(400);
    echo '<div class="alert alert-danger">com은 1~50, count는 1~30 범위여야 합니다.</div>';
    return;
}

$start = hrtime(true);
sleep(1);
$elapsedMs = (hrtime(true) - $start) / 1e6;
?>
<div class="container py-3">
  <div class="card shadow-sm"><div class="card-body">
    <h5 class="card-title">slow.php 실행 결과</h5>
    <p class="mb-2">com = <?= (int)$com ?>, count = <?= (int)$count ?></p>
    <p class="mb-1">PHP 실행시간: <?= number_format($elapsedMs / 1000, 3) ?>초</p>
    <p class="mb-0">PHP 실행시간: <?= number_format($elapsedMs, 2) ?>ms</p>
  </div></div>
</div>
