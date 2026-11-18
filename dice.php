<?php
// dice.php - index.php?cmd=dice 에서 include되는 교육용 주사위 실험
// index.php에서 공통 레이아웃, 세션 및 DB 연결을 처리합니다.

$dice = array_fill(1, 6, 0);
$attempts = 6;
$executed = false;
$error = '';
$maxAttempts = 10000000000;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_dice'])) {
    $submitted = $_POST['attempts'] ?? '';
    $validated = filter_var($submitted, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => $maxAttempts]
    ]);

    if ($validated === false) {
        $error = '실행 횟수는 1부터 ' . number_format($maxAttempts) . ' 사이의 정수로 입력해 주세요.';
        $attempts = 6;
    } else {
        $attempts = $validated;
        for ($i = 0; $i < $attempts; $i++) {
            $face = rand(1, 6);
            $dice[$face]++;
        }
        $executed = true;
    }
}

$chartRows = [];
for ($n = 1; $n <= 6; $n++) {
    $chartRows[] = [$n, $dice[$n]];
}
?>
<div class="container py-4">
    <div class="card border-0 shadow-sm">
        <div class="card-header text-white" style="background-color:#46765c;">
            <h4 class="mb-0">주사위 난수 분포 실험</h4>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">주사위를 지정한 횟수만큼 던지고 1~6의 출현 횟수를 비교합니다.</p>

            <form action="index.php?cmd=dice" method="post" class="row g-3 align-items-end mb-4">
                <div class="col-sm-5 col-md-4">
                    <label for="dice-attempts" class="form-label fw-semibold">실행 횟수</label>
                    <input id="dice-attempts" name="attempts" type="number" class="form-control"
                           value="<?= (int)$attempts ?>" min="1" max="<?= (int)$maxAttempts ?>" step="1" required>
                </div>
                <div class="col-auto">
                    <button type="submit" name="run_dice" value="1" class="btn btn-success">실행</button>
                </div>
            </form>

            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <?php if ($executed): ?>
                <h5 class="mb-3">주사위 결과 (총 <?= number_format($attempts) ?>회)</h5>
                <div class="table-responsive">
                    <table class="table table-bordered table-striped text-center align-middle">
                        <thead class="table-success">
                            <tr><th scope="col">주사위 번호</th><th scope="col">발생 횟수</th><th scope="col">발생 비율</th></tr>
                        </thead>
                        <tbody>
                            <?php for ($n = 1; $n <= 6; $n++): ?>
                                <tr>
                                    <td><?= $n ?></td>
                                    <td><?= number_format($dice[$n]) ?></td>
                                    <td><?= number_format($dice[$n] * 100 / $attempts, 2) ?>%</td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-semibold">
                                <td>합계</td><td><?= number_format(array_sum($dice)) ?></td><td>100.00%</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <p class="small text-muted">공정한 주사위라면 각 번호의 기대 발생 비율은 약 16.67%입니다.</p>
                <h5 class="mt-4 mb-2">Google Line Chart</h5>
                <div id="dice-line-chart" style="width:100%; height:390px;" role="img" aria-label="주사위 번호별 발생 횟수 선 그래프"></div>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php if ($executed): ?>
<script src="https://www.gstatic.com/charts/loader.js"></script>
<script>
(function () {
    'use strict';
    const counts = <?= json_encode($chartRows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) ?>;
    google.charts.load('current', {packages: ['corechart']});
    google.charts.setOnLoadCallback(function () {
        const element = document.getElementById('dice-line-chart');
        const data = new google.visualization.DataTable();
        data.addColumn('number', '주사위 번호');
        data.addColumn('number', '발생 횟수');
        data.addRows(counts);
        const chart = new google.visualization.LineChart(element);
        const options = {
            title: '주사위 번호별 발생 횟수',
            legend: {position: 'none'},
            colors: ['#46765c'],
            pointSize: 7,
            lineWidth: 3,
            height: 390,
            chartArea: {left: 75, top: 55, width: '80%', height: '65%'},
            hAxis: {title: '주사위 번호', ticks: [1, 2, 3, 4, 5, 6], viewWindow: {min: 1, max: 6}},
            vAxis: {title: '발생 횟수', minValue: 0, viewWindow: {min: 0}, format: '0'}
        };
        function draw() { chart.draw(data, options); }
        draw();
        window.addEventListener('resize', draw);
    });
})();
</script>
<?php endif; ?>
