import { S, emit } from './state.js';
import { api } from './api.js';
import { esc, icon, toast, busy } from './ui.js';
import { md } from './md.js';
import { createEditor, disposeAllEditors } from './editor.js';
import { runPython, TurtleStage } from './runner.js';

let solved = new Set();
let totalActivities = 0;
let currentSkill = null;
let currentModule = null;
let stages = [];

export function disposeLesson() {
  stages.forEach((s) => s.destroy());
  stages = [];
  disposeAllEditors();
}

/** The identifiers every lesson API call needs, skill or custom module. */
function lessonRef() {
  return currentModule ? { module: currentModule } : { skill: currentSkill };
}

export async function openLesson(skillId) {
  disposeLesson();
  currentSkill = skillId;
  currentModule = null;
  S.lessonSkill = skillId;
  solved = new Set();
  totalActivities = 0;

  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="panel">
      <div class="lesson-wrap">
        <div class="lesson-top">
          <span class="crumbs">Preparing your lesson…</span>
        </div>
        <div class="lesson-hero">
          <div class="skeleton" style="width:64%;height:24px"></div>
          <div class="skeleton" style="width:88%"></div>
          <div class="skeleton" style="width:40%"></div>
        </div>
        <div class="loading-line"><span class="spinner"></span> Writing this lesson around what you already know…</div>
      </div>
    </div>`;

  try {
    const data = await api('api/lesson.php', { action: 'generate', skill: skillId });
    S.lesson = data.lesson;
    S.lessonMeta = data.skill;
    S.cached.add(skillId);
    render(data.lesson, data.skill, data.cached);
  } catch (err) {
    view.innerHTML = `
      <div class="panel">
        <div class="lesson-wrap">
          <div class="empty">
            <h3>The lesson didn't come through</h3>
            <p>${esc(err.message)}</p>
            <button class="btn btn-primary" id="retry-lesson">Try again</button>
            <button class="btn btn-ghost" id="back-journey">Back to the journey</button>
          </div>
        </div>
      </div>`;
    view.querySelector('#retry-lesson').addEventListener('click', () => openLesson(skillId));
    view.querySelector('#back-journey').addEventListener('click', () => emit('navigate', 'journey'));
  }
}

/**
 * Open a module the learner asked the forge to write. It already exists, so
 * this is a plain fetch rather than a generation call.
 */
export async function openModule(moduleId) {
  disposeLesson();
  currentModule = moduleId;
  currentSkill = null;
  S.lessonSkill = moduleId;
  solved = new Set();
  totalActivities = 0;

  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="panel">
      <div class="lesson-wrap">
        <div class="lesson-top"><span class="crumbs">Opening your module…</span></div>
        <div class="lesson-hero">
          <div class="skeleton" style="width:58%;height:24px"></div>
          <div class="skeleton" style="width:86%"></div>
        </div>
        <div class="loading-line"><span class="spinner"></span> Unpacking the module…</div>
      </div>
    </div>`;

  try {
    const data = await api('api/lesson.php', { action: 'get', module: moduleId });
    if (!data.lesson) {
      throw new Error('That module has no lesson attached.');
    }
    S.lesson = data.lesson;
    S.lessonMeta = data.skill;
    render(data.lesson, data.skill, true, { module: true, done: Boolean(data.done) });
  } catch (err) {
    view.innerHTML = `
      <div class="panel">
        <div class="lesson-wrap">
          <div class="empty">
            <h3>That module didn't open</h3>
            <p>${esc(err.message)}</p>
            <button class="btn btn-primary" id="back-forge">Back to the forge</button>
          </div>
        </div>
      </div>`;
    view.querySelector('#back-forge').addEventListener('click', () => emit('navigate', 'curriculum'));
  }
}

function render(lesson, meta, cached, opts = {}) {
  const view = document.getElementById('view');
  const isModule = Boolean(opts.module || currentModule);
  const blocks = lesson.blocks || [];
  totalActivities = blocks.filter((b) => ['code', 'mcq', 'fill', 'drag', 'task'].includes(b.type)).length;

  view.innerHTML = `
    <div class="panel">
      <div class="lesson-wrap">
        <div class="lesson-top">
          <button class="btn btn-ghost btn-sm" id="lesson-back">${icon('arrow', 15)} ${isModule ? 'Forge' : 'Journey'}</button>
          <span class="crumbs">${esc(meta.stage)} · <b>${esc(meta.title)}</b></span>
          <span style="flex:1"></span>
          ${opts.done ? `<span class="chip good">${icon('check', 12)} Completed</span>` : ''}
          ${isModule ? '' : `<button class="btn btn-ghost btn-sm" id="lesson-regen" title="Write a different version of this lesson">${icon('refresh', 14)} New version</button>`}
        </div>

        <div class="lesson-hero">
          <h1>${esc(lesson.title)}</h1>
          <p>${esc(lesson.objective)}</p>
          <div class="meta-row">
            <span class="chip">${icon('bolt', 12)} ${lesson.minutes} min</span>
            ${lesson.why ? `<span class="chip why">${icon('spark', 12)} ${esc(lesson.why)}</span>` : ''}
            ${totalActivities ? `<span class="chip" id="act-count">0 / ${totalActivities} activities</span>` : ''}
          </div>
          ${totalActivities ? '<div class="bar" style="margin-top:16px"><i id="act-bar" style="width:0%"></i></div>' : ''}
        </div>

        <div class="blocks" id="blocks"></div>

        ${(lesson.takeaways || []).length ? `
          <div class="takeaways">
            <h4>You should now be able to</h4>
            <ul>${lesson.takeaways.map((t) => `<li>${esc(t)}</li>`).join('')}</ul>
          </div>` : ''}

        <div class="lesson-foot">
          ${lesson.next ? `<span style="color:var(--dim);font-size:13px;max-width:46ch">Up next: ${esc(String(lesson.next).replace(/^next:\s*/i, ''))}</span>` : ''}
          <span class="spacer"></span>
          <button class="btn btn-primary" id="lesson-complete">${icon('check', 15)} ${isModule ? 'Finish module' : 'Mark this complete'}</button>
        </div>
      </div>
    </div>`;

  const host = view.querySelector('#blocks');
  blocks.forEach((b, i) => {
    const node = renderBlock(b);
    if (node) {
      node.style.animationDelay = `${Math.min(i * 40, 240)}ms`;
      host.appendChild(node);
    }
  });

  view.querySelector('#lesson-back').addEventListener('click', () => {
    emit('navigate', isModule ? 'curriculum' : 'journey');
  });

  view.querySelector('#lesson-regen')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    busy(btn, true);
    disposeLesson();
    try {
      const data = await api('api/lesson.php', { action: 'regenerate', skill: currentSkill });
      S.lesson = data.lesson;
      render(data.lesson, data.skill, false);
      toast('Wrote a fresh version.', 'good');
    } catch (err) {
      toast(err.message, 'error');
      busy(btn, false);
    }
  });

  view.querySelector('#lesson-complete').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    busy(btn, true, 'Saving');
    const score = totalActivities ? Math.max(0.5, solved.size / totalActivities) : 0.85;
    try {
      const res = await api('api/lesson.php', {
        action: 'complete',
        ...lessonRef(),
        score,
        note: `${solved.size}/${totalActivities} activities solved`,
      });
      emit('state', res);
      if (isModule) {
        toast('Module finished. Added to the forge.', 'good');
        emit('navigate', 'curriculum');
      } else {
        toast('Skill complete. Nice work.', 'good');
        emit('navigate', 'journey');
      }
    } catch (err) {
      toast(err.message, 'error');
      busy(btn, false);
    }
  });

  // Editors need the DOM to be laid out before Monaco attaches.
  requestAnimationFrame(() => {
    host.querySelectorAll('[data-code-host]').forEach((el) => {
      const id = el.getAttribute('data-code-host');
      const block = blocks.find((b) => b.id === id);
      if (block) mountCode(block, el);
    });
  });
}

function updateProgress() {
  const bar = document.getElementById('act-bar');
  const label = document.getElementById('act-count');
  if (bar) bar.style.width = `${totalActivities ? (solved.size / totalActivities) * 100 : 0}%`;
  if (label) label.textContent = `${solved.size} / ${totalActivities} activities`;
  document.querySelectorAll('[data-block]').forEach((el) => {
    if (solved.has(el.getAttribute('data-block'))) el.classList.add('solved');
  });
}

function markSolved(blockId) {
  solved.add(blockId);
  updateProgress();
}

/* ------------------------------------------------------------------ */
/* Block renderers                                                     */
/* ------------------------------------------------------------------ */

function renderBlock(b) {
  switch (b.type) {
    case 'text': {
      const el = document.createElement('div');
      el.className = 'block md';
      el.innerHTML = md(b.md);
      return el;
    }
    case 'callout': {
      const el = document.createElement('div');
      el.className = `callout ${b.tone || 'tip'}`;
      el.innerHTML = `<span class="ct-icon">${icon(b.tone === 'warn' ? 'lock' : b.tone === 'key' ? 'spark' : 'bolt', 16)}</span><div class="md">${md(b.md)}</div>`;
      return el;
    }
    case 'code': return codeBlock(b);
    case 'mcq': return mcqBlock(b);
    case 'fill': return fillBlock(b);
    case 'drag': return dragBlock(b);
    case 'task': return taskBlock(b);
    case 'project': return projectBlock(b);
    default: return null;
  }
}

function codeBlock(b) {
  const el = document.createElement('div');
  el.className = 'block activity';
  el.setAttribute('data-block', b.id);
  el.innerHTML = `
    <div class="ex-head">
      <span class="ex-tag">Code</span>
      <span class="ex-state" data-state>Not run yet</span>
    </div>
    ${b.task ? `<div class="ex-prompt">${md(b.task)}</div>` : ''}
    <div class="code-shell">
      <div class="code-bar">
        <span class="dot3"><i></i><i></i><i></i></span>
        <span class="name">main.py</span>
        <span class="spacer"></span>
        <select class="speed-select" data-speed title="Animation speed">
          <option value="normal">Normal</option>
          <option value="slow">Slow</option>
          <option value="instant">Instant</option>
        </select>
        <button class="btn btn-sm" data-review title="Ask the tutor to read your code">${icon('spark', 12)} Review</button>
        <button class="btn btn-sm btn-primary" data-run>${icon('play', 12)} Run</button>
      </div>
      <div class="editor-host" data-code-host="${esc(b.id)}"></div>
      <div class="inputs-strip hidden" data-inputs>
        <label>Inputs</label>
        <input type="text" placeholder="One answer per line — for input() calls" data-input-value>
      </div>
      <div class="code-out" data-out></div>
      <div class="ai-review hidden" data-review-out></div>
      <div class="turtle-host hidden" data-turtle><canvas></canvas></div>
    </div>`;
  return el;
}

async function mountCode(b, host) {
  const block = host.closest('.block');
  const out = block.querySelector('[data-out]');
  const stateEl = block.querySelector('[data-state]');
  const runBtn = block.querySelector('[data-run]');
  const reviewBtn = block.querySelector('[data-review]');
  const reviewOut = block.querySelector('[data-review-out]');
  const speedSel = block.querySelector('[data-speed]');
  const turtleWrap = block.querySelector('[data-turtle]');
  const inputsWrap = block.querySelector('[data-inputs]');
  const inputEl = block.querySelector('[data-input-value]');
  let stage = null;
  let running = false;
  let lastOutput = '';

  const editor = await createEditor(host, {
    value: b.starter,
    onChange: (text) => {
      inputsWrap.classList.toggle('hidden', !/\binput\s*\(/.test(text));
    },
  });
  inputsWrap.classList.toggle('hidden', !/\binput\s*\(/.test(b.starter));

  const append = (text, cls = '') => {
    const line = document.createElement('div');
    if (cls) line.className = cls;
    line.textContent = text;
    out.appendChild(line);
  };

  const setState = (text, cls = '') => {
    stateEl.textContent = text;
    stateEl.className = `ex-state ${cls}`;
  };

  const check = async () => {
    try {
      const res = await api('api/lesson.php', {
        action: 'check',
        ...lessonRef(),
        blockId: b.id,
        payload: { output: lastOutput },
      });
      if (res.ok) {
        setState('Output matched', 'ok');
        markSolved(b.id);
        if (res.explain) append('✓ ' + res.explain, 'ok');
      } else {
        setState('Not quite — check the output', 'bad');
      }
      return res.ok;
    } catch (err) {
      setState('Could not check', 'bad');
      toast(err.message, 'error');
      return false;
    }
  };

  reviewBtn?.addEventListener('click', async () => {
    const code = editor.get().trim();
    if (!code) {
      toast('Write a little code first.', 'warn');
      return;
    }
    busy(reviewBtn, true, 'Reading');
    reviewOut.classList.remove('hidden');
    reviewOut.innerHTML = '<div class="loading-line"><span class="spinner"></span> Your tutor is reading the code…</div>';
    try {
      const res = await api('api/grade.php', {
        kind: 'code',
        task: b.task || '',
        code,
        output: lastOutput,
      });
      if (res.state) emit('state', res.state);
      reviewOut.innerHTML = gradeHtml(res);
      if (res.passed) {
        setState('Reviewed — looks right', 'ok');
        markSolved(b.id);
      } else {
        setState('Reviewed — read the feedback', 'bad');
      }
    } catch (err) {
      reviewOut.innerHTML = `<p class="grade-error">${esc(err.message)}</p>`;
      toast(err.message, 'error');
    } finally {
      busy(reviewBtn, false);
    }
  });

  runBtn.addEventListener('click', async () => {
    if (running) return;
    running = true;
    out.innerHTML = '';
    lastOutput = '';
    setState('Running…');
    runBtn.disabled = true;

    if (!stage) {
      stage = new TurtleStage(turtleWrap.querySelector('canvas'));
      stages.push(stage);
    }
    stage.reset();
    turtleWrap.classList.add('hidden');

    const inputs = (inputEl.value || '').split('\n').map((s) => s).filter((s, i, a) => i < a.length - 1 || s !== '');

    try {
      const res = await runPython(editor.get(), inputs, {
        onStdout: (line) => { lastOutput += line + '\n'; append(line); },
        onStderr: (line) => { lastOutput += line + '\n'; append(line, 'err'); },
        timeout: Number(b.timeout || S.user?.settings?.timeout || 10),
      });

      if (res.usedTurtle || (res.ops && res.ops.length)) {
        turtleWrap.classList.remove('hidden');
        stage.play(res.ops || [], speedSel.value);
      }
      if (res.error) {
        append(res.error, 'err');
        setState('Error — read the traceback', 'bad');
      } else {
        append(`— finished in ${res.ms} ms`, 'sys');
        await check();
      }
    } catch (err) {
      append(err.message, 'err');
      setState('Stopped', 'bad');
    } finally {
      running = false;
      runBtn.disabled = false;
    }
  });
}

function taskBlock(b) {
  const el = document.createElement('div');
  el.className = 'block activity task-block';
  el.setAttribute('data-block', b.id);
  el.innerHTML = `
    <div class="ex-head">
      <span class="ex-tag">In your words</span>
      <span class="ex-state" data-state>Explaining it is how you keep it</span>
    </div>
    <div class="ex-prompt">${md(b.prompt)}</div>
    ${b.hint ? `<div class="task-hint">${icon('spark', 12)} ${esc(b.hint)}</div>` : ''}
    <textarea rows="5" data-answer placeholder="Write it however you'd say it out loud — no code needed"></textarea>
    ${(b.rubric || []).length ? `
      <div class="rubric">
        <span>A strong answer covers</span>
        <ul>${b.rubric.map((r) => `<li>${esc(r)}</li>`).join('')}</ul>
      </div>` : ''}
    <div class="task-actions">
      <button class="btn btn-sm btn-primary" data-grade>${icon('spark', 13)} Get feedback</button>
      <span class="task-note">Graded by your tutor, not a keyword matcher.</span>
    </div>
    <div class="ai-review hidden" data-grade-out></div>`;

  const textarea = el.querySelector('[data-answer]');
  const stateEl = el.querySelector('[data-state]');
  const out = el.querySelector('[data-grade-out]');

  el.querySelector('[data-grade]').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const answer = textarea.value.trim();
    if (answer.length < 3) {
      toast('Write an answer first — even a rough one.', 'warn');
      textarea.focus();
      return;
    }
    busy(btn, true, 'Reading');
    out.classList.remove('hidden');
    out.innerHTML = '<div class="loading-line"><span class="spinner"></span> Thinking about what you said…</div>';
    try {
      const res = await api('api/grade.php', {
        kind: 'answer',
        task: b.prompt,
        answer,
      });
      if (res.state) emit('state', res.state);
      out.innerHTML = gradeHtml(res);
      stateEl.textContent = res.passed ? 'Got it — that holds up' : 'Close — read the fixes';
      stateEl.className = `ex-state ${res.passed ? 'ok' : 'bad'}`;
      if (res.passed) markSolved(b.id);
    } catch (err) {
      out.innerHTML = `<p class="grade-error">${esc(err.message)}</p>`;
      toast(err.message, 'error');
    } finally {
      busy(btn, false);
    }
  });

  return el;
}

function gradeHtml(res) {
  const pct = Math.round((Number(res.score) || 0) * 100);
  const tone = res.passed ? 'pass' : 'fail';
  const color = pct >= 80 ? 'var(--good)' : pct >= 60 ? 'var(--warn)' : 'var(--bad)';
  return `
    <div class="grade ${tone}">
      <div class="grade-head">
        <span class="score-pill" style="background:rgba(255,255,255,.05);color:${color}">${pct}%</span>
        <b>${res.passed ? 'That works' : 'Not quite yet'}</b>
      </div>
      ${res.feedback ? `<p class="grade-feedback">${esc(res.feedback)}</p>` : ''}
      ${(res.strengths || []).length ? `
        <div class="grade-group"><span class="ok">What you got right</span>
          <ul>${res.strengths.map((s) => `<li>${esc(s)}</li>`).join('')}</ul>
        </div>` : ''}
      ${(res.fixes || []).length ? `
        <div class="grade-group"><span class="warn">What to fix</span>
          <ul>${res.fixes.map((s) => `<li>${esc(s)}</li>`).join('')}</ul>
        </div>` : ''}
      ${res.misconception ? `<div class="grade-mis">${icon('bolt', 13)} ${esc(res.misconception)}</div>` : ''}
    </div>`;
}

function mcqBlock(b) {
  const el = document.createElement('div');
  el.className = 'block activity';
  el.setAttribute('data-block', b.id);
  el.innerHTML = `
    <div class="ex-head">
      <span class="ex-tag">Check</span>
      <span class="ex-state" data-state>Pick one</span>
    </div>
    <div class="ex-prompt">${md(b.question)}</div>
    <div class="opts" data-opts>
      ${b.options.map((o, i) => `
        <button class="opt" data-opt="${i}">
          <span class="key">${String.fromCharCode(65 + i)}</span>
          <span>${md(o)}</span>
        </button>`).join('')}
    </div>
    <div class="explain hidden" data-explain></div>`;

  const stateEl = el.querySelector('[data-state]');
  const explain = el.querySelector('[data-explain]');

  el.querySelectorAll('[data-opt]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      if (el.dataset.done === '1') return;
      const choice = Number(btn.getAttribute('data-opt'));
      el.querySelectorAll('[data-opt]').forEach((b2) => b2.classList.remove('picked'));
      btn.classList.add('picked');

      try {
        const res = await api('api/lesson.php', {
          action: 'check', ...lessonRef(), blockId: b.id, payload: { choice },
        });
        el.dataset.done = '1';
        btn.classList.remove('picked');
        btn.classList.add(res.ok ? 'right' : 'wrong');
        el.querySelectorAll('[data-opt]').forEach((b2) => { b2.disabled = true; });
        stateEl.textContent = res.ok ? 'Correct' : 'Not quite';
        stateEl.className = `ex-state ${res.ok ? 'ok' : 'bad'}`;
        if (res.explain) {
          explain.innerHTML = md(res.explain);
          explain.classList.remove('hidden');
        }
        if (res.ok) markSolved(b.id);
      } catch (err) {
        toast(err.message, 'error');
      }
    });
  });

  return el;
}

function fillBlock(b) {
  const el = document.createElement('div');
  el.className = 'block activity';
  el.setAttribute('data-block', b.id);

  const parts = b.code.split('___');
  const codeHtml = parts
    .map((p, i) => `${esc(p)}${i < parts.length - 1 ? `<span class="blank" data-blank="${i}" contenteditable="false"></span>` : ''}`)
    .join('');

  const allChoices = Array.from(new Set(b.blanks.flatMap((x) => x.choices)));

  el.innerHTML = `
    <div class="ex-head">
      <span class="ex-tag">Fill in</span>
      <span class="ex-state" data-state>Tap a blank, then a chip</span>
    </div>
    ${b.prompt ? `<div class="ex-prompt">${md(b.prompt)}</div>` : ''}
    <div class="fill-code">${codeHtml}</div>
    <div class="choices">${allChoices.map((c) => `<button class="choice" data-choice="${esc(c)}">${esc(c)}</button>`).join('')}</div>
    <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
      <button class="btn btn-sm" data-clear>Clear</button>
      <button class="btn btn-sm btn-primary" data-check>${icon('check', 13)} Check</button>
    </div>
    <div class="explain hidden" data-explain></div>`;

  const blanks = Array.from(el.querySelectorAll('[data-blank]'));
  const values = new Array(blanks.length).fill('');
  let active = 0;

  const paint = () => {
    blanks.forEach((node, i) => {
      node.textContent = values[i] || '';
      node.classList.toggle('filled', Boolean(values[i]));
      node.classList.toggle('active', i === active && !values[i]);
    });
  };

  blanks.forEach((node, i) => {
    node.addEventListener('click', () => {
      active = i;
      values[i] = '';
      node.classList.remove('ok', 'bad');
      paint();
    });
  });

  el.querySelectorAll('[data-choice]').forEach((chip) => {
    chip.addEventListener('click', () => {
      const text = chip.getAttribute('data-choice');
      let target = active;
      if (values[target]) {
        const free = values.findIndex((v) => !v);
        if (free !== -1) target = free;
      }
      values[target] = text;
      const nextFree = values.findIndex((v) => !v);
      active = nextFree === -1 ? target : nextFree;
      blanks.forEach((n) => n.classList.remove('ok', 'bad'));
      paint();
    });
  });

  el.querySelector('[data-clear]').addEventListener('click', () => {
    values.fill('');
    active = 0;
    blanks.forEach((n) => n.classList.remove('ok', 'bad'));
    paint();
  });

  el.querySelector('[data-check]').addEventListener('click', async () => {
    try {
      const res = await api('api/lesson.php', {
        action: 'check', ...lessonRef(), blockId: b.id, payload: { blanks: values },
      });
      const stateEl = el.querySelector('[data-state]');
      (res.detail || []).forEach((d, i) => {
        blanks[i]?.classList.remove('ok', 'bad');
        blanks[i]?.classList.add(d.ok ? 'ok' : 'bad');
      });
      stateEl.textContent = res.ok ? 'All correct' : 'Some blanks are off';
      stateEl.className = `ex-state ${res.ok ? 'ok' : 'bad'}`;
      const explain = el.querySelector('[data-explain]');
      if (res.explain) {
        explain.innerHTML = md(res.explain);
        explain.classList.remove('hidden');
      }
      if (res.ok) markSolved(b.id);
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  paint();
  return el;
}

function dragBlock(b) {
  const el = document.createElement('div');
  el.className = 'block activity';
  el.setAttribute('data-block', b.id);
  el.innerHTML = `
    <div class="ex-head">
      <span class="ex-tag">Order it</span>
      <span class="ex-state" data-state>Drag a chip into a slot</span>
    </div>
    ${b.prompt ? `<div class="ex-prompt">${md(b.prompt)}</div>` : ''}
    <div class="tokens">
      ${b.tokens.map((t) => `<div class="token" draggable="true" data-token="${esc(t)}">${esc(t)}</div>`).join('')}
    </div>
    <div class="zones">
      ${b.targets.map((t, i) => `
        <div class="zone-row">
          <span>${esc(t.label)}</span>
          <div class="zone" data-zone="${i}" data-value="">drop here</div>
        </div>`).join('')}
    </div>
    <div style="margin-top:14px;display:flex;gap:10px;align-items:center">
      <button class="btn btn-sm" data-clear>Reset</button>
      <button class="btn btn-sm btn-primary" data-check>${icon('check', 13)} Check</button>
    </div>
    <div class="explain hidden" data-explain></div>`;

  const zones = Array.from(el.querySelectorAll('[data-zone]'));
  const tokens = Array.from(el.querySelectorAll('[data-token]'));
  let selected = null;

  const setZone = (zone, value) => {
    zone.dataset.value = value;
    zone.textContent = value || 'drop here';
    zone.classList.toggle('filled', Boolean(value));
    zone.classList.remove('ok', 'bad');
    tokens.forEach((t) => t.classList.toggle('placed', Array.from(zones).some((z) => z.dataset.value === t.dataset.token)));
  };

  const place = (zone, token) => {
    // If this token is already somewhere, clear that slot.
    zones.forEach((z) => {
      if (z !== zone && z.dataset.value === token.dataset.token) setZone(z, '');
    });
    setZone(zone, token.dataset.token);
  };

  tokens.forEach((token) => {
    token.addEventListener('dragstart', (e) => {
      e.dataTransfer.setData('text/plain', token.dataset.token);
      token.classList.add('dragging');
    });
    token.addEventListener('dragend', () => token.classList.remove('dragging'));
    token.addEventListener('click', () => {
      tokens.forEach((t) => t.style.outline = '');
      if (selected === token) {
        selected = null;
      } else {
        selected = token;
        token.style.outline = '2px solid var(--accent)';
      }
    });
  });

  zones.forEach((zone) => {
    zone.addEventListener('dragover', (e) => {
      e.preventDefault();
      zone.classList.add('over');
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('over'));
    zone.addEventListener('drop', (e) => {
      e.preventDefault();
      zone.classList.remove('over');
      const value = e.dataTransfer.getData('text/plain');
      const token = tokens.find((t) => t.dataset.token === value);
      if (token) place(zone, token);
    });
    zone.addEventListener('click', () => {
      if (selected) {
        place(zone, selected);
        selected.style.outline = '';
        selected = null;
      } else if (zone.dataset.value) {
        setZone(zone, '');
      }
    });
  });

  el.querySelector('[data-clear]').addEventListener('click', () => {
    zones.forEach((z) => setZone(z, ''));
    selected = null;
    tokens.forEach((t) => t.style.outline = '');
  });

  el.querySelector('[data-check]').addEventListener('click', async () => {
    const values = zones.map((z) => z.dataset.value || '');
    try {
      const res = await api('api/lesson.php', {
        action: 'check', ...lessonRef(), blockId: b.id, payload: { targets: values },
      });
      const stateEl = el.querySelector('[data-state]');
      (res.detail || []).forEach((d, i) => {
        zones[i].classList.remove('ok', 'bad');
        zones[i].classList.add(d.ok ? 'ok' : 'bad');
      });
      stateEl.textContent = res.ok ? 'All correct' : 'Not in the right order yet';
      stateEl.className = `ex-state ${res.ok ? 'ok' : 'bad'}`;
      const explain = el.querySelector('[data-explain]');
      if (res.explain) {
        explain.innerHTML = md(res.explain);
        explain.classList.remove('hidden');
      }
      if (res.ok) markSolved(b.id);
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  return el;
}

function projectBlock(b) {
  const el = document.createElement('div');
  el.className = 'block activity';
  el.innerHTML = `
    <div class="ex-head">
      <span class="ex-tag">Build</span>
      <span class="ex-state">${b.requirements?.length || 0} requirements</span>
    </div>
    <h3 style="margin:4px 0 8px;font-size:17px;letter-spacing:-.02em">${esc(b.title)}</h3>
    <div class="md" style="color:var(--muted);font-size:14px">${md(b.brief)}</div>
    ${(b.requirements || []).length ? `<ul class="proj-req">${b.requirements.map((r) => `<li>${esc(r)}</li>`).join('')}</ul>` : ''}
    ${(b.stretch || []).length ? `<p class="stretch-line"><b>If you're flying:</b> ${b.stretch.map(esc).join(' · ')}</p>` : ''}
    <div style="margin-top:14px">
      <button class="btn btn-sm" data-try>Open in the playground ${icon('arrow', 13)}</button>
    </div>`;

  el.querySelector('[data-try]').addEventListener('click', () => {
    localStorage.setItem('cairn.playground', `# ${b.title}\n# ${b.brief.replace(/\s+/g, ' ')}\n\n`);
    emit('navigate', 'playground');
  });

  return el;
}
