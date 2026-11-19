<?php
/**
 * tree.php - D3.js collapsible IP prefix tree
 * URL: index.php?cmd=tree
 * Requires $conn (mysqli) and session provided by index.php.
 */
if (!isset($conn) || !($conn instanceof mysqli)) {
    echo '<div class="alert alert-danger">데이터베이스 연결을 확인할 수 없습니다.</div>';
    return;
}

$isTreeAdmin = (($_SESSION['id'] ?? '') === 'admin') || ((int)($_SESSION['level'] ?? 0) >= 9);
if (!$isTreeAdmin) {
    echo '<div class="alert alert-warning">접속 IP 트리는 관리자만 조회할 수 있습니다.</div>';
    return;
}

if (!function_exists('treeBuildData')) {
    function treeBuildData(mysqli $conn): array {
        $root = [
            'name' => '서버',
            'prefix' => '',
            'depth' => 0,
            'count' => 0,
            'computers' => 0,
            'children' => [],
        ];

        // DB에서 IP별 로그 건수를 한 번에 집계합니다.
        $res = $conn->query("SELECT `ip`, COUNT(*) AS cnt FROM `log` GROUP BY `ip`");
        $invalid = 0;
        $excludedSingle = 0;
        $uniqueIp = 0;
        while ($row = $res->fetch_assoc()) {
            $ip = trim((string)$row['ip']);
            $count = (int)$row['cnt'];
            if ($count <= 0 || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                $invalid += $count;
                continue;
            }
            // Exclude terminal IPv4 nodes with exactly one log entry.
            // Prune before aggregation so all parent totals stay accurate.
            if ($count === 1) {
                $excludedSingle++;
                continue;
            }
            $parts = explode('.', $ip);
            $uniqueIp++;
            $root['count'] += $count;
            $root['computers']++;
            $cursor =& $root;
            $prefix = '';
            for ($level = 1; $level <= 4; $level++) {
                $part = $parts[$level - 1];
                $prefix = $prefix === '' ? $part : $prefix . '.' . $part;
                if (!isset($cursor['children'][$part])) {
                    $cursor['children'][$part] = [
                        'name' => $prefix,
                        'prefix' => $prefix,
                        'depth' => $level,
                        'count' => 0,
                        'computers' => 0,
                        'children' => [],
                    ];
                }
                $cursor =& $cursor['children'][$part];
                $cursor['count'] += $count;
                $cursor['computers']++;
            }
            unset($cursor);
        }
        $res->free();

        $sortTree = static function (array &$node) use (&$sortTree): void {
            if (!$node['children']) {
                // D3 treats a missing/empty children list as a leaf.
                unset($node['children']);
                return;
            }
            uksort($node['children'], static fn($a, $b) => (int)$a <=> (int)$b);
            foreach ($node['children'] as &$child) {
                $sortTree($child);
            }
            unset($child);
            $node['children'] = array_values($node['children']);
        };
        $sortTree($root);
        return ['tree' => $root, 'invalid' => $invalid, 'uniqueIp' => $uniqueIp, 'excludedSingle' => $excludedSingle];
    }
}

try {
    $treeResult = treeBuildData($conn);
    $treeJson = json_encode(
        $treeResult['tree'],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR
    );
} catch (Throwable $e) {
    error_log('tree.php: ' . $e->getMessage());
    echo '<div class="alert alert-danger">접속 기록을 읽거나 트리 데이터를 생성하지 못했습니다.</div>';
    return;
}
?>
<style>
#ip-tree-app .tree-toolbar { display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; }
#ip-tree-app .tree-stage { height: min(73vh, 780px); min-height: 450px; position:relative; overflow:hidden; border:1px solid #cfe3d2; border-radius:12px; background:#fff; }
#ip-tree-app .tree-stage svg {display:block; width:100%; height:100%; touch-action:none; cursor:grab;}
#ip-tree-app .tree-stage svg:active {cursor:grabbing;}
#ip-tree-app .tree-node {cursor:pointer;}
#ip-tree-app .tree-node text {font:12px sans-serif; fill:#26382e; paint-order:stroke; stroke:#fff; stroke-width:3px; stroke-linejoin:round;}
#ip-tree-app .tree-link {fill:none; stroke:#9bb8a6; stroke-width:1.4px;}
#ip-tree-app .tree-tooltip {position:absolute; display:none; pointer-events:none; z-index:4; max-width:260px; background:#20372a; color:#fff; padding:10px 12px; border-radius:8px; font-size:13px; box-shadow:0 5px 18px #0003; white-space:pre-line;}
#ip-tree-app .tree-legend {display:flex;align-items:center;gap:.7rem;flex-wrap:wrap;font-size:.84rem;}
#ip-tree-app .tree-gradient {width:150px;height:13px;border:1px solid #bbb;border-radius:3px;background:linear-gradient(90deg,#fff,#f00);}
#ip-tree-app .tree-note {font-size:.85rem;color:#637469;}
@media (max-width:575.98px) {#ip-tree-app .tree-stage{min-height:420px;height:65vh;}}
</style>
<div id="ip-tree-app" class="container-fluid p-0">
  <h4 class="mb-2">접속 IP 계층 트리</h4>
  <p class="tree-note mb-3">서버 → 1 → 1.2 → 1.2.3 → 1.2.3.4 (총 5단계). 괄호 안 숫자는 해당 대역의 <strong>로그 접속 횟수</strong>입니다. 최종 IP 노드의 접속 횟수가 1회인 데이터는 제외합니다.</p>
  <div class="tree-toolbar mb-3">
    <button id="tree-expand" type="button" class="btn btn-outline-success btn-sm">모두 펼치기</button>
    <button id="tree-collapse" type="button" class="btn btn-outline-secondary btn-sm">모두 접기</button>
    <button id="tree-reset" type="button" class="btn btn-outline-secondary btn-sm">화면 맞춤</button>
    <span class="small text-muted ms-auto">고유 IP <?= number_format($treeResult['uniqueIp']) ?>개 · 접속 <?= number_format($treeResult['tree']['count']) ?>회</span>
  </div>
  <div class="tree-legend mb-2">
    <span>접속 1회</span><span class="tree-gradient" aria-hidden="true"></span><span>50회 이상</span>
    <span class="ms-2">● 채운 원: 접힌 노드·말단 IP</span><span>◯ 속이 빈 원: 펼친 하위 노드</span>
  </div>
  <div id="ip-tree-stage" class="tree-stage">
    <div id="ip-tree-tooltip" class="tree-tooltip" role="tooltip"></div>
  </div>
  <p class="tree-note mt-2 mb-0">노드를 클릭하면 하위 대역을 펼치거나 접습니다. 마우스 휠로 확대·축소하고 빈 공간을 드래그하면 화면을 이동할 수 있습니다. 색상은 접속 1~50회를 흰색→빨간색으로 표시하며, 50회 이상은 빨간색입니다. 펼친 노드는 빈 원에 색상 테두리로 표현합니다.</p>
  <p class="tree-note mt-2 mb-0">접속 기록이 1회뿐인 최종 IP <?= number_format($treeResult['excludedSingle']) ?>개는 제외했습니다. 상위 대역의 접속 횟수와 고유 IP 수에도 제외 결과를 반영했습니다.</p>
  <?php if ($treeResult['invalid'] > 0): ?>
    <p class="small text-muted mt-2">IPv4 형식이 아닌 접속 <?= number_format($treeResult['invalid']) ?>건은 트리에서 제외했습니다.</p>
  <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script>
(() => {
  const app = document.getElementById('ip-tree-app');
  if (!app) return;
  const stage = app.querySelector('#ip-tree-stage');
  const tip = app.querySelector('#ip-tree-tooltip');
  const raw = <?= $treeJson ?>;
  if (typeof d3 === 'undefined') {
    stage.textContent = 'D3.js 라이브러리를 불러오지 못했습니다.';
    return;
  }
  const root = d3.hierarchy(raw);
  let nextId = 0;
  root.each(d => { d.id = ++nextId; d.x0 = 0; d.y0 = 0; });
  // 첫 화면: 서버 및 1옥텟 대역을 보여주고, 아래 단계는 클릭으로 펼칩니다.
  root.eachAfter(d => {
    if (d.depth >= 1 && d.children) { d._children = d.children; d.children = null; }
  });

  const svg = d3.select(stage).append('svg').attr('role','img').attr('aria-label','접속 IP 계층 트리');
  const scene = svg.append('g');
  const linkLayer = scene.append('g');
  const nodeLayer = scene.append('g');
  const zoom = d3.zoom().scaleExtent([0.12,5]).on('zoom',ev => scene.attr('transform',ev.transform));
  svg.call(zoom).on('dblclick.zoom', null);
  const layout = d3.tree().nodeSize([32,175]);
  const duration = 350;
  const color = count => d3.interpolateRgb('#ffffff','#ff0000')((Math.min(50,Math.max(1,count))-1)/49);
  function hasDescendants(d) { return !!(d.children?.length || d._children?.length); }
  function circleFill(d) { return d.children?.length ? '#ffffff' : color(d.data.count); }
  function circleStroke(d) { return color(d.data.count); }

  function tipText(d) {
    const label = d.depth === 0 ? '서버 (전체)' : d.data.name;
    return label+'\n총 접속 횟수: '+d.data.count.toLocaleString('ko-KR')+'회\n고유 IP: '+d.data.computers.toLocaleString('ko-KR')+'개'+(hasDescendants(d)?'\n클릭: 하위 대역 펼치기/접기':'');
  }
  function moveTip(ev) {
    const bounds = stage.getBoundingClientRect();
    const x = Math.min(ev.clientX-bounds.left+14,bounds.width-tip.offsetWidth-8);
    const y = Math.min(ev.clientY-bounds.top+14,bounds.height-tip.offsetHeight-8);
    tip.style.left = Math.max(8,x)+'px';
    tip.style.top = Math.max(8,y)+'px';
  }
  function draw(source) {
    layout(root);
    const nodes = root.descendants();
    const links = root.links();
    nodes.forEach(d => d.y = d.depth * 175);
    const node = nodeLayer.selectAll('g.tree-node').data(nodes,d=>d.id);
    const enter = node.enter().append('g').attr('class','tree-node')
      .attr('transform',`translate(${source.y0},${source.x0})`)
      .on('click',(event,d)=>{
        event.stopPropagation();
        if(d.children){d._children=d.children;d.children=null;}
        else if(d._children){d.children=d._children;d._children=null;}
        else return;
        tip.style.display='none';
        draw(d);
      })
      .on('mouseenter',(event,d)=>{tip.textContent=tipText(d);tip.style.display='block';moveTip(event);})
      .on('mousemove',moveTip)
      .on('mouseleave',()=>{tip.style.display='none';});
    enter.append('circle').attr('r',1e-6).attr('stroke-width',2.5);
    enter.append('text').attr('dy','0.33em')
      .attr('x',d=>(d.children||d._children)?-13:13)
      .attr('text-anchor',d=>(d.children||d._children)?'end':'start')
      .text(d=>`${d.data.name} (${d.data.count})`)
      .style('fill-opacity',1e-6);
    const update = enter.merge(node);
    update.transition().duration(duration).attr('transform',d=>`translate(${d.y},${d.x})`);
    update.select('circle').transition().duration(duration)
      .attr('r',d=>d.depth===0?11:7)
      .attr('fill',circleFill)
      .attr('stroke',circleStroke)
      .attr('stroke-width',d=>d.depth===0?4:2.5);
    update.select('text').text(d=>`${d.data.name} (${d.data.count})`)
      .attr('x',d=>(d.children||d._children)?-13:13)
      .attr('text-anchor',d=>(d.children||d._children)?'end':'start')
      .transition().duration(duration).style('fill-opacity',1);
    node.exit().transition().duration(duration).attr('transform',`translate(${source.y},${source.x})`).remove()
      .select('circle').attr('r',1e-6);

    const diagonal = d3.linkHorizontal().x(d=>d.y).y(d=>d.x);
    const link = linkLayer.selectAll('path.tree-link').data(links,d=>d.target.id);
    link.enter().append('path').attr('class','tree-link')
      .attr('d',()=>{const p={x:source.x0,y:source.y0};return diagonal({source:p,target:p});})
      .merge(link).transition().duration(duration).attr('d',diagonal);
    link.exit().transition().duration(duration)
      .attr('d',()=>{const p={x:source.x,y:source.y};return diagonal({source:p,target:p});}).remove();
    nodes.forEach(d=>{d.x0=d.x;d.y0=d.y;});
  }

  function fit() {
    const bounds=scene.node().getBBox();
    const width=stage.clientWidth,height=stage.clientHeight;
    if(!bounds.width && !bounds.height) return;
    const scale=Math.min(1.3,Math.max(.12,.92*Math.min(width/Math.max(bounds.width,1),height/Math.max(bounds.height,1))));
    const tx=width/2-scale*(bounds.x+bounds.width/2);
    const ty=height/2-scale*(bounds.y+bounds.height/2);
    svg.transition().duration(400).call(zoom.transform,d3.zoomIdentity.translate(tx,ty).scale(scale));
  }
  function setAll(expand) {
    function visit(d) {
      const descendants = d.children || d._children || [];
      descendants.forEach(visit);
      if (expand && d._children) { d.children=d._children; d._children=null; }
      else if (!expand && d!==root && d.children) { d._children=d.children; d.children=null; }
    }
    visit(root);
    draw(root);
    window.setTimeout(fit,400);
  }
  app.querySelector('#tree-expand').addEventListener('click',()=>setAll(true));
  app.querySelector('#tree-collapse').addEventListener('click',()=>setAll(false));
  app.querySelector('#tree-reset').addEventListener('click',fit);
  window.addEventListener('resize',fit);
  draw(root);
  fit();
})();
</script>
