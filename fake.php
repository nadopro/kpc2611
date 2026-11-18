<?php
// fake.php - index.php?cmd=fake 로 실행하는 조선시대풍 가상 인물 생성 실습
// 이름의 각 요소는 조선시대 역사 인물들의 성씨/이름 글자에서 참고했지만
// 임의로 재조합하므로 실제 역사 인물과 무관한 가상 정보입니다.

$name1 = [
    '김', '김', '박', '최', '이', '오', '선우', '독고', '정', '조',
    '신', '홍', '윤', '유', '권', '송', '남', '성', '안', '한',
    '허', '황', '서', '강', '민', '심', '임', '배', '장', '백',
    '곽', '문', '노', '류', '전', '양', '차', '채', '주', '고',
    '구', '하', '전', '엄', '변', '나', '손', '봉', '표', '탁'
];

// 역사 인물 이름 예: 김홍도, 김정희, 김시습, 이순신, 이항복,
// 박지원, 박제가, 박문수, 최명길, 정약용, 정인지, 조광조,
// 신숙주, 신채호, 홍대용, 윤선도, 유성룡, 송시열 등.
$name2 = [
    '홍', '정', '시', '상', '옥', '좌', '순', '항', '덕', '원',
    '지', '중', '규', '지', '제', '문', '세', '명', '한', '익',
    '약', '인', '여', '희', '광', '위', '숙', '채', '경', '윤',
    '대', '국', '봉', '선', '두', '성', '득', '길', '인', '상',
    '준', '익', '구', '효', '삼', '수', '치', '명', '윤', '재'
];

$name3 = [
    '도', '희', '습', '헌', '균', '근', '신', '복', '형', '익',
    '함', '환', '경', '원', '가', '수', '당', '길', '기', '현',
    '용', '지', '립', '량', '조', '한', '주', '호', '준', '복',
    '용', '영', '한', '도', '서', '룡', '공', '준', '석', '하',
    '길', '필', '만', '온', '문', '침', '근', '회', '윤', '필'
];

$regions = ['서울', '경기', '충청', '전라', '경상', '제주'];
$limit = 10000; // 브라우저 출력량 보호용
$countText = (string)($_POST['count'] ?? '6');
$count = 6;
$errors = [];
$people = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!preg_match('/\A[0-9]+\z/D', $countText)) {
        $errors[] = '생성 횟수는 정수로 입력해 주세요.';
    } else {
        $count = (int)$countText;
        if ($count < 1 || $count > $limit) {
            $errors[] = '생성 횟수는 1~' . number_format($limit) . ' 사이로 입력해 주세요.';
        }
    }

    if (!$errors) {
        // 1500-01-01 ~ 1900-12-31 사이의 날짜를 고르게 선택합니다.
        // 임의의 연도/월/일을 따로 뽑으면 잘못된 날짜가 발생할 수 있으므로 일수 기반으로 선택합니다.
        $startDay = intdiv(strtotime('1500-01-01 12:00:00 UTC'), 86400);
        $endDay   = intdiv(strtotime('1900-12-31 12:00:00 UTC'), 86400);
        for ($i = 1; $i <= $count; $i++) {
            $surname = $name1[array_rand($name1)];
            $middle  = $name2[array_rand($name2)];
            $last    = $name3[array_rand($name3)];
            $birthDay = random_int($startDay, $endDay) * 86400;
            $people[] = [
                'no' => $i,
                'name' => $surname . $middle . $last,
                'birth' => gmdate('Y-m-d', $birthDay),
                'region' => $regions[array_rand($regions)],
            ];
        }
    }
}

function fakeEscape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<div class="container py-4">
    <div class="card border-0 shadow-sm">
        <div class="card-header text-white" style="background-color:#46765c;">
            <h4 class="mb-0">조선시대풍 가상 인물 데이터 생성</h4>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">역사 인물 이름에서 참고한 글자들을 임의로 조합합니다. 생성되는 인물과 생년월일 및 출신지는 모두 가상 정보입니다.</p>
            <form action="index.php?cmd=fake" method="post" class="row g-2 align-items-end mb-4">
                <div class="col-sm-4 col-lg-3">
                    <label for="fake-count" class="form-label fw-semibold">생성 횟수</label>
                    <input class="form-control" type="number" id="fake-count" name="count" min="1" max="<?= $limit ?>" step="1" required value="<?= fakeEscape($countText) ?>">
                </div>
                <div class="col-auto">
                    <button class="btn text-white" style="background-color:#46765c;" type="submit">생성하기</button>
                </div>
            </form>
            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger" role="alert"><?= fakeEscape($error) ?></div>
            <?php endforeach; ?>
            <?php if ($people): ?>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h5 class="mb-0">생성 결과</h5>
                    <span class="text-muted small">총 <?= number_format(count($people)) ?>명</span>
                </div>
                <div class="table-responsive" style="max-height: 650px; overflow-y: auto;">
                    <table class="table table-bordered table-hover table-striped align-middle text-center mb-0">
                        <thead class="table-light sticky-top">
                            <tr><th scope="col" style="width:10%">순서</th><th scope="col">이름</th><th scope="col">생년월일</th><th scope="col">지역</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($people as $person): ?>
                            <tr>
                                <td><?= $person['no'] ?></td>
                                <td><?= fakeEscape($person['name']) ?></td>
                                <td><?= fakeEscape($person['birth']) ?></td>
                                <td><?= fakeEscape($person['region']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
