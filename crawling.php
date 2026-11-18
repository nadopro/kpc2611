<?php
// crawling.php: include via index.php?cmd=crawling
// Seoul National University press-release titles, pages 1 through 10.
// All network requests pass through index.php and are rate-limited in the browser.
echo 'PHP 버전: ' . PHP_VERSION . '<br>';
echo '설정 파일: ' . php_ini_loaded_file() . '<br>';
echo 'cURL: ' . (extension_loaded('curl') ? '활성' : '비활성');


if (!function_exists('snuCrawlTitles')) {
    function snuCrawlTitles(int $page): array
    {
        if ($page < 1 || $page > 10) {
            throw new RuntimeException('허용된 페이지 범위는 1~10입니다.');
        }
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL 확장이 활성화되어 있지 않습니다.');
        }
        if (!class_exists('DOMDocument')) {
            throw new RuntimeException('PHP DOM 확장이 활성화되어 있지 않습니다.');
        }

        $url = 'https://www.snu.ac.kr/snunow/press?page=' . $page;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERAGENT => 'KPC-Classroom-TitleCrawler/1.0 (educational demonstration)',
            CURLOPT_HTTPHEADER => ['Accept: text/html', 'Accept-Language: ko-KR,ko;q=0.9'],
            CURLOPT_ENCODING => '',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $html = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($html === false) {
            throw new RuntimeException('cURL 요청 실패: ' . $error);
        }
        if ($status !== 200) {
            throw new RuntimeException('원격 서버 HTTP 응답: ' . $status);
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8"?>' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            throw new RuntimeException('페이지의 HTML을 해석하지 못했습니다.');
        }

        $xpath = new DOMXPath($dom);
        $nodes = $xpath->query("//td[contains(concat(' ', normalize-space(@class), ' '), ' col-title ')]/a[1]");
        $items = [];
        foreach ($nodes as $a) {
            $titleNode = $xpath->query(".//span[contains(concat(' ', normalize-space(@class), ' '), ' txt ')]", $a)->item(0);
            if ($titleNode === null) {
                continue;
            }
            $title = trim(preg_replace('/\s+/u', ' ', $titleNode->textContent));
            if ($title === '') {
                continue;
            }
            $href = $a->getAttribute('href');
            // Link only to the same known site's press articles.
            if (!preg_match('~^/snunow/press\?md=v&bbsidx=\d+$~', $href)) {
                $href = '';
            }
            $dateNode = $xpath->query("./ancestor::tr[1]/td[contains(concat(' ', normalize-space(@class), ' '), ' col-date ')]", $a)->item(0);
            $items[] = [
                'title' => $title,
                'date' => $dateNode ? trim($dateNode->textContent) : '',
                'url' => $href !== '' ? 'https://www.snu.ac.kr' . $href : '',
            ];
        }
        if (!$items) {
            throw new RuntimeException('제목을 찾지 못했습니다. 사이트의 HTML 구조 또는 접근 제한을 확인하세요.');
        }
        return $items;
    }
}

// index.php prints the global HTML layout, so AJAX uses a small embedded JSON
// element rather than emitting a bare JSON document from an included file.
if (isset($_GET['crawl_ajax']) && $_GET['crawl_ajax'] === '1') {
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    try {
        if ($page === false || $page === null) {
            throw new RuntimeException('페이지 번호가 올바르지 않습니다.');
        }
        $items = snuCrawlTitles($page);
        $payload = ['ok' => true, 'page' => $page, 'items' => $items];
    } catch (Throwable $e) {
        $payload = ['ok' => false, 'page' => $page, 'error' => $e->getMessage()];
    }
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    echo '<div id="snu-crawl-response" data-payload="', htmlspecialchars($json, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), '"></div>';
    return;
}
?>
<div class="container py-4" id="snu-crawl-app">
    <div class="card border-0 shadow-sm">
        <div class="card-header text-white" style="background-color:#46765c;">
            <h4 class="mb-0">서울대학교 보도자료 제목 크롤링</h4>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">1~10페이지를 순서대로 수집합니다. 페이지마다 5초 간격으로 요청하며, 실제 게시물 수에 따라 각 페이지의 결과 건수는 달라질 수 있습니다.</p>
            <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                <button type="button" id="snu-crawl-start" class="btn btn-success">크롤링 시작</button>
                <button type="button" id="snu-crawl-stop" class="btn btn-outline-secondary" disabled>중지</button>
                <span id="snu-crawl-status" class="small text-muted" role="status" aria-live="polite">시작 버튼을 누르세요.</span>
            </div>
            <div class="progress mb-3" style="height: 10px;" role="progressbar" aria-label="크롤링 진행률" aria-valuemin="0" aria-valuemax="10" aria-valuenow="0">
                <div id="snu-crawl-bar" class="progress-bar bg-success" style="width:0%"></div>
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-hover align-middle">
                    <thead class="table-light"><tr><th style="width:70px">순서</th><th style="width:90px">페이지</th><th>게시글 제목</th><th style="width:140px">등록일</th></tr></thead>
                    <tbody id="snu-crawl-rows"><tr><td colspan="4" class="text-center text-muted py-4">수집된 데이터가 없습니다.</td></tr></tbody>
                </table>
            </div>
            <p class="small text-muted mb-0">출처: <a href="https://www.snu.ac.kr/snunow/press?page=1" target="_blank" rel="noopener noreferrer">서울대학교 보도자료</a> · DB에는 저장하지 않습니다.</p>
        </div>
    </div>
</div>
<script>
(function () {
    const startBtn = document.getElementById('snu-crawl-start');
    const stopBtn = document.getElementById('snu-crawl-stop');
    const status = document.getElementById('snu-crawl-status');
    const rows = document.getElementById('snu-crawl-rows');
    const bar = document.getElementById('snu-crawl-bar');
    const progress = bar.parentElement;
    let active = false;
    let timer = null;
    let controller = null;
    let serial = 0;
    let count = 0;
    let completed = 0;

    function setProgress(value) {
        progress.setAttribute('aria-valuenow', String(value));
        bar.style.width = (value * 10) + '%';
    }
    function stop(message) {
        active = false;
        if (timer !== null) clearTimeout(timer);
        timer = null;
        if (controller !== null) controller.abort();
        controller = null;
        startBtn.disabled = false;
        stopBtn.disabled = true;
        if (message) status.textContent = message;
    }
    function addRows(page, items) {
        const fragment = document.createDocumentFragment();
        for (const item of items) {
            const tr = document.createElement('tr');
            const numberCell = document.createElement('td');
            numberCell.textContent = String(++count);
            const pageCell = document.createElement('td');
            pageCell.textContent = String(page);
            const titleCell = document.createElement('td');
            if (item.url) {
                const link = document.createElement('a');
                link.href = item.url;
                link.target = '_blank';
                link.rel = 'noopener noreferrer';
                link.textContent = item.title;
                titleCell.appendChild(link);
            } else {
                titleCell.textContent = item.title;
            }
            const dateCell = document.createElement('td');
            dateCell.textContent = item.date || '-';
            tr.append(numberCell, pageCell, titleCell, dateCell);
            fragment.appendChild(tr);
        }
        rows.appendChild(fragment);
    }
    async function crawlPage(page, runId) {
        if (!active || runId !== serial) return;
        status.textContent = page + '페이지 요청 중... (누적 ' + count + '건)';
        controller = new AbortController();
        try {
            const response = await fetch('index.php?cmd=crawling&crawl_ajax=1&page=' + page, {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal
            });
            if (!response.ok) throw new Error('웹서버 HTTP ' + response.status);
            const html = await response.text();
            if (!active || runId !== serial) return;
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const dataNode = doc.getElementById('snu-crawl-response');
            if (!dataNode) throw new Error('크롤링 응답 형식을 읽을 수 없습니다.');
            const data = JSON.parse(dataNode.getAttribute('data-payload'));
            if (!data.ok) throw new Error(data.error || '크롤링 실패');
            if (count === 0) rows.replaceChildren();
            addRows(page, data.items);
            completed = page;
            setProgress(completed);
            status.textContent = page + '페이지 완료: ' + data.items.length + '건 / 누적 ' + count + '건';
            if (page >= 10) {
                stop('1~10페이지 수집 완료 (총 ' + count + '건)');
                return;
            }
            // Wait five seconds AFTER the current request completes.
            timer = setTimeout(function () { crawlPage(page + 1, runId); }, 5000);
        } catch (error) {
            if (error.name === 'AbortError') return;
            stop(page + '페이지 오류: ' + error.message);
        } finally {
            controller = null;
        }
    }
    startBtn.addEventListener('click', function () {
        if (active) return;
        serial++;
        active = true;
        count = 0;
        completed = 0;
        rows.innerHTML = '<tr><td colspan="4" class="text-center text-muted">1페이지 요청 중...</td></tr>';
        startBtn.disabled = true;
        stopBtn.disabled = false;
        setProgress(0);
        crawlPage(1, serial);
    });
    stopBtn.addEventListener('click', function () {
        serial++;
        stop('사용자가 중지했습니다. (' + completed + '페이지, ' + count + '건)');
    });
    window.addEventListener('pagehide', function () {
        serial++;
        stop();
    });
})();
</script>
