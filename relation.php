<?php
/**
 * relation.php - D3.js person relationship graph.
 * Intended for inclusion from index.php?cmd=relation.
 * No additional HTML/head/body tags are required.
 */
?>
<section class="container-fluid py-3" id="relation-demo">
  <div class="card border-0 shadow-sm overflow-hidden mx-auto" style="max-width: 1200px;">
    <div class="card-header text-white d-flex align-items-center justify-content-between flex-wrap gap-2" style="background:#46765c;">
      <h4 class="mb-0">인물 관계 네트워크</h4>
      <button type="button" class="btn btn-sm btn-light" id="relation-reset">화면 초기화</button>
    </div>
    <div class="card-body" style="background:#f2faf3;">
      <div class="d-flex flex-wrap gap-3 mb-2 small">
        <span><span class="relation-key" style="background:#d64545"></span>부모-자식</span>
        <span><span class="relation-key" style="background:#2875d0"></span>친구</span>
        <span><span class="relation-key" style="background:#252525"></span>사제</span>
      </div>
      <div id="relation-graph" role="img" aria-label="6명의 인물과 부모 자식, 친구, 사제 관계가 연결된 네트워크 그래프"></div>
      <div class="small text-muted mt-2">마우스 휠: 확대/축소 · 오른쪽 버튼 드래그: 화면 이동 · 왼쪽 버튼 드래그: 인물 이동 · 관계선 위: 설명 보기</div>
    </div>
  </div>
</section>
<style>
  #relation-demo .relation-key { display:inline-block; width:24px; height:4px; vertical-align:middle; margin-right:6px; border-radius:2px; }
  #relation-graph { position:relative; width:100%; height:min(70vh,650px); min-height:390px; background:#fff; border:1px solid #d8e8dc; border-radius:12px; overflow:hidden; cursor:default; touch-action:none; }
  #relation-graph svg { display:block; width:100%; height:100%; user-select:none; }
  #relation-graph svg.relation-panning { cursor:grabbing; }
  #relation-graph .relation-edge { stroke-width:4px; stroke-linecap:round; }
  #relation-graph .relation-node circle { fill:#dceee2; stroke:#42765b; stroke-width:2.5px; }
  #relation-graph .relation-node text { fill:#153e2b; font-size:14px; font-weight:600; text-anchor:middle; dominant-baseline:middle; pointer-events:none; }
  #relation-graph .relation-node { cursor:grab; }
  #relation-graph .relation-node:active { cursor:grabbing; }
  #relation-graph .relation-tip { position:absolute; display:none; pointer-events:none; z-index:5; padding:8px 12px; color:#fff; background:rgba(28,45,37,.95); border-radius:7px; font-size:14px; max-width:260px; box-shadow:0 3px 10px #0002; }
</style>
<script src="https://cdn.jsdelivr.net/npm/d3@7/dist/d3.min.js"></script>
<script>
(function initRelationGraph() {
  'use strict';
  const host = document.getElementById('relation-graph');
  if (!host || host.dataset.initialized === 'true') return;
  if (!window.d3) {
    host.textContent = 'D3.js를 불러오지 못했습니다. 인터넷 연결 또는 CDN 접근을 확인하세요.';
    return;
  }
  host.dataset.initialized = 'true';

  // Original sample: "선생님" means P2 (이율곡) is the teacher of P5 (이순신).
  const graph = {
    nodes: [
      {id:'P1', name:'신사임당'}, {id:'P2', name:'이율곡'},
      {id:'P3', name:'홍대감'}, {id:'P4', name:'홍길동'},
      {id:'P5', name:'이순신'}, {id:'P6', name:'유성룡'}
    ],
    links: [
      {source:'P1', target:'P2', type:'family', relation:'아들', description:'이율곡은 신사임당의 아들'},
      {source:'P3', target:'P4', type:'family', relation:'아들', description:'홍길동은 홍대감의 아들'},
      {source:'P2', target:'P4', type:'friend', relation:'친구', description:'이율곡과 홍길동은 친구'},
      {source:'P5', target:'P6', type:'friend', relation:'친구', description:'이순신과 유성룡은 친구'},
      {source:'P5', target:'P2', type:'teacher', relation:'선생님', description:'이율곡은 이순신의 선생님'}
    ]
  };
  const colors = {family:'#d64545', friend:'#2875d0', teacher:'#252525'};
  const d3 = window.d3;
  let width = host.clientWidth || 900;
  let height = host.clientHeight || 500;
  const svg = d3.select(host).append('svg').attr('viewBox', `0 0 ${width} ${height}`);
  const scene = svg.append('g');
  const tip = d3.select(host).append('div').attr('class','relation-tip').attr('role','tooltip');
  const zoom = d3.zoom().scaleExtent([0.3, 4])
    // Only right-button dragging pans; zoom via wheel still works.
    .filter(event => event.type === 'wheel' || (event.type === 'mousedown' && event.button === 2))
    .on('zoom', event => scene.attr('transform', event.transform))
    .on('start', event => { if (event.sourceEvent && event.sourceEvent.button === 2) svg.classed('relation-panning',true); })
    .on('end', () => svg.classed('relation-panning',false));
  svg.call(zoom).on('dblclick.zoom',null);
  // Suppress native context menu so right-drag can pan.
  svg.on('contextmenu', event => event.preventDefault());

  const edge = scene.append('g').selectAll('line')
    .data(graph.links).enter().append('line')
    .attr('class','relation-edge')
    .attr('stroke', d => colors[d.type]);

  // A wider, transparent hit target makes the 4px line easy to hover.
  const edgeHit = scene.append('g').selectAll('line')
    .data(graph.links).enter().append('line')
    .attr('stroke','transparent').attr('stroke-width',18)
    .attr('pointer-events','stroke')
    .on('mouseenter', function(event,d) { tip.text(d.description).style('display','block'); })
    .on('mousemove', function(event) {
      const rect=host.getBoundingClientRect();
      const x=Math.min(event.clientX-rect.left+14, rect.width-220);
      const y=Math.max(8,event.clientY-rect.top-48);
      tip.style('left',Math.max(8,x)+'px').style('top',y+'px');
    })
    .on('mouseleave', () => tip.style('display','none'));

  const node = scene.append('g').selectAll('g')
    .data(graph.nodes).enter().append('g').attr('class','relation-node');
  node.append('circle').attr('r',39);
  node.append('text').text(d=>d.name);
  node.append('title').text(d=>d.name);

  const simulation = d3.forceSimulation(graph.nodes)
    .force('link',d3.forceLink(graph.links).id(d=>d.id).distance(180).strength(0.8))
    .force('charge',d3.forceManyBody().strength(-600))
    .force('center',d3.forceCenter(width/2,height/2))
    .force('collision',d3.forceCollide(56))
    .on('tick', () => {
      edge.attr('x1',d=>d.source.x).attr('y1',d=>d.source.y)
        .attr('x2',d=>d.target.x).attr('y2',d=>d.target.y);
      edgeHit.attr('x1',d=>d.source.x).attr('y1',d=>d.source.y)
        .attr('x2',d=>d.target.x).attr('y2',d=>d.target.y);
      node.attr('transform',d=>`translate(${d.x},${d.y})`);
    });

  node.call(d3.drag().filter(event=>event.button===0)
    .on('start',(event,d)=>{
      if(!event.active) simulation.alphaTarget(0.3).restart();
      d.fx=d.x; d.fy=d.y;
    })
    .on('drag',(event,d)=>{
      d.fx=event.x; d.fy=event.y;
    })
    .on('end',(event,d)=>{
      if(!event.active) simulation.alphaTarget(0);
      d.fx=null; d.fy=null;
    }));

  document.getElementById('relation-reset')?.addEventListener('click',()=>{
    svg.transition().duration(350).call(zoom.transform,d3.zoomIdentity);
    graph.nodes.forEach(d=>{d.fx=null;d.fy=null;});
    simulation.alpha(0.8).restart();
  });
  if ('ResizeObserver' in window) {
    new ResizeObserver(() => {
      const w=host.clientWidth,h=host.clientHeight;
      if (w===width && h===height) return;
      width=w; height=h;
      svg.attr('viewBox',`0 0 ${width} ${height}`);
      simulation.force('center',d3.forceCenter(width/2,height/2));
      simulation.alpha(0.25).restart();
    }).observe(host);
  }
})();
</script>
