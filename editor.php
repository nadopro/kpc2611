<?php
// editor.php - index.php?cmd=editor 에서 include되는 편집기 예제
// 저장할 게시판 테이블이 지정되지 않아, 본 예제는 DB에 저장하지 않습니다.
?>
<link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
<style>
  .editor-demo { max-width: 980px; margin: 0 auto; }
  .editor-demo .editor-head { background:#46765c; color:#fff; padding:14px 18px; border-radius:9px 9px 0 0; }
  .editor-demo .editor-body { border:1px solid #d6e5d9; border-top:0; border-radius:0 0 9px 9px; background:#fff; padding:20px; }
  .editor-demo .editor-toolbar { display:flex; align-items:center; gap:6px; padding:9px; background:#f2f7f3; border:1px solid #d3ddd5; border-bottom:0; border-radius:7px 7px 0 0; }
  .editor-demo .format-btn { display:inline-flex; align-items:center; justify-content:center; width:42px; height:38px; border:1px solid #d3ddd5; border-radius:5px; color:#32493b; background:#fff; cursor:pointer; }
  .editor-demo .format-btn:hover, .editor-demo .format-btn:focus-visible { background:#e4f1e6; outline:2px solid transparent; }
  .editor-demo .format-btn[aria-pressed="true"] { background:#d2ead6; border-color:#498b5b; }
  .editor-demo .format-btn .material-icons { font-size:22px; }
  .editor-demo .edit-area { border:1px solid #d3ddd5; border-radius:0 0 7px 7px; min-height:260px; padding:16px; overflow-wrap:anywhere; line-height:1.65; background:#fff; }
  .editor-demo .edit-area:focus { outline:2px solid #90b99a; outline-offset:-2px; }
  .editor-demo .edit-area:empty::before { content:attr(data-placeholder); color:#8a9890; pointer-events:none; }
  .editor-demo .preview-html { white-space:pre-wrap; overflow-wrap:anywhere; min-height:90px; max-height:250px; overflow:auto; background:#18271f; color:#d9f4df; padding:14px; border-radius:6px; font-size:13px; }
</style>

<div class="editor-demo">
  <div class="editor-head"><h4 class="mb-0">WYSIWYG 게시글 작성</h4></div>
  <div class="editor-body">
    <div class="mb-3">
      <label for="editor-title" class="form-label fw-semibold">제목</label>
      <input id="editor-title" name="title" type="text" class="form-control" maxlength="200" placeholder="게시글 제목을 입력하세요">
    </div>

    <label id="editor-content-label" class="form-label fw-semibold">내용</label>
    <div class="editor-toolbar" role="toolbar" aria-label="글자 서식">
      <button type="button" class="format-btn" data-format="bold" aria-label="굵게" aria-pressed="false" title="굵게 (Ctrl+B)"><span class="material-icons" aria-hidden="true">format_bold</span></button>
      <button type="button" class="format-btn" data-format="italic" aria-label="기울임" aria-pressed="false" title="기울임 (Ctrl+I)"><span class="material-icons" aria-hidden="true">format_italic</span></button>
      <button type="button" class="format-btn" data-format="underline" aria-label="밑줄" aria-pressed="false" title="밑줄 (Ctrl+U)"><span class="material-icons" aria-hidden="true">format_underlined</span></button>
      <span class="ms-2 small text-muted">글자를 선택한 뒤 서식 버튼을 누르세요.</span>
    </div>
    <div id="editor-content" class="edit-area" contenteditable="true" role="textbox" aria-multiline="true" aria-labelledby="editor-content-label" data-placeholder="게시글 내용을 입력하세요."></div>

    <div class="d-flex flex-wrap gap-2 mt-3">
      <button type="button" id="editor-show-html" class="btn btn-success">HTML 코드 보기</button>
      <button type="button" id="editor-clear" class="btn btn-outline-secondary">초기화</button>
    </div>
    <div class="mt-3" id="editor-html-wrap" hidden>
      <label class="form-label fw-semibold" for="editor-html-output">작성된 본문 HTML (확인용)</label>
      <pre class="preview-html mb-0" id="editor-html-output"></pre>
    </div>
    <div class="small text-muted mt-3">이 화면은 WYSIWYG 편집 실습용입니다. DB 저장 기능은 포함되지 않았습니다. 사용자 입력 HTML을 게시판에서 표시할 때에는 서버 측 HTML 정화가 필요합니다.</div>
  </div>
</div>

<script>
(function () {
  const editor = document.getElementById('editor-content');
  const toolbar = document.querySelector('.editor-demo .editor-toolbar');
  const htmlWrap = document.getElementById('editor-html-wrap');
  const htmlOutput = document.getElementById('editor-html-output');
  const showHtml = document.getElementById('editor-show-html');
  const clear = document.getElementById('editor-clear');
  if (!editor || !toolbar) return;

  function updatePressed() {
    const selection = window.getSelection();
    if (!selection || !selection.anchorNode || !editor.contains(selection.anchorNode)) {
      toolbar.querySelectorAll('[data-format]').forEach(btn => btn.setAttribute('aria-pressed', 'false'));
      return;
    }
    toolbar.querySelectorAll('[data-format]').forEach(btn => {
      let active = false;
      try { active = document.queryCommandState(btn.dataset.format); } catch (_) {}
      btn.setAttribute('aria-pressed', String(active));
    });
  }

  // 서식 버튼 클릭 시 기존 텍스트 선택 영역을 유지합니다.
  toolbar.addEventListener('mousedown', event => {
    if (event.target.closest('[data-format]')) event.preventDefault();
  });
  toolbar.addEventListener('click', event => {
    const btn = event.target.closest('[data-format]');
    if (!btn) return;
    editor.focus();
    // 교육용 기본 서식 API: 브라우저에서 지원하지만 표준상 deprecated입니다.
    document.execCommand(btn.dataset.format, false, null);
    updatePressed();
    if (!htmlWrap.hidden) htmlOutput.textContent = editor.innerHTML;
  });

  editor.addEventListener('keyup', updatePressed);
  editor.addEventListener('mouseup', updatePressed);
  editor.addEventListener('input', () => {
    updatePressed();
    if (!htmlWrap.hidden) htmlOutput.textContent = editor.innerHTML;
  });

  showHtml.addEventListener('click', () => {
    htmlWrap.hidden = !htmlWrap.hidden;
    showHtml.textContent = htmlWrap.hidden ? 'HTML 코드 보기' : 'HTML 코드 숨기기';
    if (!htmlWrap.hidden) htmlOutput.textContent = editor.innerHTML;
  });
  clear.addEventListener('click', () => {
    document.getElementById('editor-title').value = '';
    editor.innerHTML = '';
    htmlOutput.textContent = '';
    updatePressed();
    editor.focus();
  });
  document.addEventListener('selectionchange', updatePressed);
})();
</script>
