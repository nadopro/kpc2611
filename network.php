<?php
/**
 * logNetwork.php - index.php?cmd=logNetwork
 * index.php provides session and $conn (mysqli).
 * Rebuilds data.json on each authorized page load.
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    echo '<div class="alert alert-warning">index.php를 통해 접근하세요.</div>';
    return;
}
$isAdmin = (($_SESSION['id'] ?? '') === 'admin') || ((int)($_SESSION['level'] ?? 0) >= 9);
if (!$isAdmin) {
    echo '<div class="alert alert-warning">접속 IP 분석은 관리자만 이용할 수 있습니다.</div>';
    return;
}
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">데이터베이스 연결이 없습니다.</div>';
    return;
}

$nodes = [];
$links = [];
$groups = [];
$error = '';
$saveMessage = '';
try {
    // Aggregate all accesses, preserving each original timestamp for tooltips.
    $result = $conn->query('SELECT `ip`, `time` FROM `log` ORDER BY `time` ASC, `idx` ASC');
    while ($row = $result->fetch_assoc()) {
        $ip = trim((string)$row['ip']);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            continue; // /24 groups are IPv4-only.
        }
        $parts = explode('.', $ip);
        $subnet = implode('.', array_slice($parts, 0, 3));
        if (!isset($groups[$subnet])) {
            $groups[$subnet] = ['count' => 0, 'ips' => []];
        }
        $groups[$subnet]['count']++;
        if (!isset($groups[$subnet]['ips'][$ip])) {
            $groups[$subnet]['ips'][$ip] = ['count' => 0, 'times' => []];
        }
        $groups[$subnet]['ips'][$ip]['count']++;
        $groups[$subnet]['ips'][$ip]['times'][] = (string)$row['time'];
    }
    $result->free();
    ksort($groups, SORT_NATURAL);
    // Every included IPv4 leaf belongs to this hierarchy:
    // SERVER -> first 2 octets -> first 3 octets -> full IPv4.
    $accesses = 0;
    $secondLevel = [];
    $includedGroups = [];
    foreach ($groups as $subnet => $group) {
        // Exclude /24 branches with only one access.
        if ($group['count'] <= 1) continue;
        $includedGroups[$subnet] = $group;
        $parts = explode('.', $subnet);
        $prefix2 = $parts[0] . '.' . $parts[1];
        if (!isset($secondLevel[$prefix2])) {
            $secondLevel[$prefix2] = ['count' => 0, 'ips' => []];
        }
        $secondLevel[$prefix2]['count'] += $group['count'];
        foreach ($group['ips'] as $ip => $detail) {
            $secondLevel[$prefix2]['ips'][$ip] = true;
        }
        $accesses += $group['count'];
    }
    // One central server node, regardless of the number of IP groups.
    $nodes[] = [
        'id' => 'server', 'label' => '서버', 'type' => 'server',
        'count' => $accesses, 'computers' => array_sum(array_map(
            static fn($g) => count($g['ips']), $includedGroups
        )), 'diameter' => 40, 'color' => '#356b51'
    ];
    ksort($secondLevel, SORT_NATURAL);
    foreach ($secondLevel as $prefix2 => $group2) {
        $group2Id = 'net2:' . $prefix2;
        $nodes[] = [
            'id' => $group2Id, 'label' => $prefix2, 'type' => 'network2',
            'count' => $group2['count'], 'computers' => count($group2['ips']),
            'diameter' => 24, 'color' => '#8db6a1'
        ];
        $links[] = ['source' => 'server', 'target' => $group2Id];
    }
    foreach ($includedGroups as $subnet => $group) {
        $count = $group['count'];
        $diameter = min(30, max(1, $count));
        $colorFactor = min(1, max(0, ($count - 1) / 19));
        $gb = (int)round(255 * (1 - $colorFactor));
        $hex = sprintf('#FF%02X%02X', $gb, $gb);
        $groupId = 'net:' . $subnet;
        $pieces = explode('.', $subnet);
        $group2Id = 'net2:' . $pieces[0] . '.' . $pieces[1];
        $nodes[] = [
            'id' => $groupId, 'label' => $subnet, 'type' => 'network',
            'count' => $count, 'computers' => count($group['ips']),
            'diameter' => $diameter, 'color' => $hex
        ];
        $links[] = ['source' => $group2Id, 'target' => $groupId];
        ksort($group['ips'], SORT_NATURAL);
        foreach ($group['ips'] as $ip => $detail) {
            $leafId = 'ip:' . $ip;
            $nodes[] = [
                'id' => $leafId, 'label' => $ip, 'type' => 'ip',
                'count' => $detail['count'], 'times' => $detail['times'],
                'diameter' => 8, 'color' => '#81b7e6'
            ];
            $links[] = ['source' => $groupId, 'target' => $leafId];
        }
    }
    $payload = [
        'metadata' => ['generatedAt' => date('c'), 'grouping' => 'server -> IPv4 /16 -> IPv4 /24 -> individual IP',
            'excluded' => 'networks with only one total access', 'accesses' => $accesses],
        'nodes' => $nodes, 'links' => $links
    ];
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
    // Write a complete new file, then atomically replace old data.json.
    $target = __DIR__ . DIRECTORY_SEPARATOR . 'data.json';
    $temp = tempnam(__DIR__, 'json_');
    if ($temp === false) throw new RuntimeException('임시 JSON 파일을 생성하지 못했습니다.');
    try {
        if (file_put_contents($temp, $json, LOCK_EX) === false) {
            throw new RuntimeException('JSON 파일을 기록하지 못했습니다.');
        }
        if (is_file($target) && PHP_OS_FAMILY === 'Windows' && !unlink($target)) {
            throw new RuntimeException('기존 data.json을 삭제하지 못했습니다.');
        }
        if (!rename($temp, $target)) {
            throw new RuntimeException('data.json으로 이름을 변경하지 못했습니다.');
        }
        $saveMessage = 'data.json 생성 완료 · /16 대역 ' . count($secondLevel) . '개 · /24 대역 ' . count($includedGroups) . '개 · IP ' . count(array_filter($nodes, fn($n) => $n['type'] === 'ip')) . '개';
    } finally {
        if (is_file($temp)) unlink($temp);
    }
} catch (Throwable $e) {
    error_log('logNetwork: ' . $e->getMessage());
    $error = '로그를 가져오거나 data.json을 저장하지 못했습니다. DB 권한과 PHP 폴더 쓰기 권한을 확인해 주세요.';
}
$graphJson = json_encode(['nodes' => $nodes, 'links' => $links], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<style>
.log-network-wrap {position:relative; border:1px solid #d3e1d5; border-radius:12px; background:#f9fcfa; overflow:hidden;}
#log-network-svg {display:block; width:100%; height:620px; cursor:default; user-select:none;}
#log-network-tooltip {position:absolute; display:none; pointer-events:none; z-index:4; max-width:min(380px,75vw); max-height:300px; overflow:hidden; padding:12px; background:rgba(25,35,30,.96); color:#fff; border-radius:8px; box-shadow:0 5px 20px #0003; font-size:13px; white-space:normal;}
#log-network-tooltip .times {max-height:200px; overflow:auto; white-space:pre-line; margin-top:6px; font-family:monospace; line-height:1.5;}
.log-network-hint {font-size:.85rem;color:#65736b}
@media(max-width:600px){#log-network-svg{height:480px}}
</style>
<div class="container-fluid p-0" id="log-network-app">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div><h4 class="mb-1">접속 IP 네트워크 시각화</h4><div class="log-network-hint">마우스 휠: 확대·축소 / 오른쪽 버튼 드래그: 화면 이동 / 왼쪽 버튼 드래그: 개별 노드 이동</div></div>
    <a class="btn btn-outline-success btn-sm" href="index.php?cmd=logNetwork">로그 다시 읽기 · JSON 재생성</a>
  </div>
  <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php else: ?>
    <div class="alert alert-success py-2 mb-3"><?= htmlspecialchars($saveMessage, ENT_QUOTES, 'UTF-8') ?></div>
    <div class="d-flex flex-wrap gap-3 mb-2 log-network-hint">
      <span>연결 구조: 서버 → 1.2 → 1.2.3 → 1.2.3.4</span><span>크기: 1~30회 → 지름 1~30px</span><span>색상: 1회 흰색 → 20회 빨간색</span><span>전체 접속 1회인 대역 제외</span>
    </div>
    <div class="log-network-wrap" id="log-network-area">
      <svg id="log-network-svg" aria-label="IP 접속 네트워크"></svg>
      <div id="log-network-tooltip" role="tooltip"></div>
    </div>
    <p class="small text-muted mt-2">JSON에는 전체 IP와 각 접속 시간이 저장됩니다. <strong>data.json은 민감한 접속 기록이므로 외부에서 접근하지 못하도록 웹서버 접근 통제를 설정하세요.</strong></p>
    <script type="application/json" id="log-network-data"><?= $graphJson ?></script>
    <script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
    <script>
    (() => {
      const area = document.getElementById('log-network-area');
      const svgEl = document.getElementById('log-network-svg');
      if (!area || !svgEl) return;
      if (typeof d3 === 'undefined') { area.insertAdjacentHTML('beforeend', '<div class="alert alert-warning m-3">D3.js를 불러오지 못했습니다.</div>'); return; }
      const source = JSON.parse(document.getElementById('log-network-data').textContent);
      const nodes = source.nodes.map(n => ({ ...n }));
      const links = source.links.map(l => ({ ...l }));
      const svg = d3.select(svgEl);
      const width = svgEl.clientWidth || 900, height = svgEl.clientHeight || 620;
      svg.attr('viewBox', [0, 0, width, height]);
      const layer = svg.append('g');
      const tooltip = document.getElementById('log-network-tooltip');
      const zoom = d3.zoom().scaleExtent([.15, 8])
        .filter(event => event.type === 'wheel' || (event.type === 'mousedown' && event.button === 2))
        .on('zoom', event => layer.attr('transform', event.transform));
      svg.on('contextmenu', event => event.preventDefault()).call(zoom);
      const edges = layer.append('g').attr('stroke', '#a8b8ab').attr('stroke-opacity', .65)
        .selectAll('line').data(links).join('line').attr('stroke-width', 1.2);
      const vertex = layer.append('g').selectAll('g').data(nodes).join('g').style('cursor','pointer');
      vertex.append('circle')
        .attr('r', d => d.diameter / 2)
        .attr('fill', d => d.color)
        .attr('stroke', d => d.type === 'network' ? '#a33c3c' : (d.type === 'server' ? '#254837' : '#366c94'))
        .attr('stroke-width', 1.2);
      vertex.append('text').attr('x', d => d.diameter / 2 + 5).attr('dy', '.35em')
        .attr('font-size', d => d.type === 'server' ? 14 : (d.type === 'network' || d.type === 'network2' ? 12 : 10))
        .attr('font-weight', d => d.type === 'ip' ? 400 : 700)
        .attr('fill', '#283c32').text(d => d.label);
      const simulation = d3.forceSimulation(nodes)
        .force('link', d3.forceLink(links).id(d => d.id).distance(d => d.source.type === 'server' ? 145 : (d.source.type === 'network2' ? 105 : 75)).strength(.85))
        .force('charge', d3.forceManyBody().strength(d => d.type === 'server' ? -700 : (d.type === 'network2' ? -470 : (d.type === 'network' ? -280 : -100))))
        .force('center', d3.forceCenter(width / 2, height / 2))
        .force('collide', d3.forceCollide(d => Math.max(15, d.diameter / 2 + 9)));
      const server = nodes.find(n => n.type === 'server');
      if (server) { server.fx = width / 2; server.fy = height / 2; }
      simulation.on('tick', () => {
        edges.attr('x1', d => d.source.x).attr('y1', d => d.source.y)
          .attr('x2', d => d.target.x).attr('y2', d => d.target.y);
        vertex.attr('transform', d => `translate(${d.x},${d.y})`);
      });
      const drag = d3.drag().filter(event => event.button === 0)
        .on('start', (event, d) => { if (d.type === 'server') return; if (!event.active) simulation.alphaTarget(.3).restart(); d.fx = d.x; d.fy = d.y; })
        .on('drag', (event, d) => { if (d.type === 'server') return; d.fx = event.x; d.fy = event.y; })
        .on('end', (event, d) => { if (d.type === 'server') return; if (!event.active) simulation.alphaTarget(0); d.fx = null; d.fy = null; });
      vertex.call(drag);
      function positionTooltip(event) {
        const bounds = area.getBoundingClientRect();
        let x = event.clientX - bounds.left + 14, y = event.clientY - bounds.top + 14;
        tooltip.style.display = 'block';
        x = Math.min(x, bounds.width - tooltip.offsetWidth - 8);
        y = Math.min(y, bounds.height - tooltip.offsetHeight - 8);
        tooltip.style.left = Math.max(6,x)+'px'; tooltip.style.top = Math.max(6,y)+'px';
      }
      vertex.on('mouseenter', (event, d) => {
        tooltip.replaceChildren();
        const heading = document.createElement('strong'); heading.textContent = d.label; tooltip.append(heading);
        const summary = document.createElement('div'); summary.style.marginTop = '7px';
        summary.textContent = d.type === 'ip'
          ? `총 접속: ${d.count}회`
          : `접속 컴퓨터(IP): ${d.computers}개 / 총 접속: ${d.count}회`;
        tooltip.append(summary);
        if (d.type === 'ip') {
          const times = document.createElement('div'); times.className='times';
          times.textContent = '접속 시간:\n' + (d.times || []).join('\n');
          tooltip.append(times);
        }
        positionTooltip(event);
      }).on('mousemove', positionTooltip).on('mouseleave', () => { tooltip.style.display = 'none'; });
      if (!nodes.length) {
        area.insertAdjacentHTML('beforeend','<div class="text-muted p-3">표시할 네트워크가 없습니다. (접속 1회 대역 제외)</div>');
      }
    })();
    </script>
  <?php endif; ?>
</div>
