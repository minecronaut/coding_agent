import { S, emit, isConfigured } from './state.js';
import { api } from './api.js';
import { esc, icon, toast, busy, timeAgo } from './ui.js';
import { md } from './md.js';

export function openProjects() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="panel">
      <div class="panel-head">
        <div class="eyebrow">Projects</div>
        <h2>Build something</h2>
        <p>Projects are where the learning sticks. Each one is written for your level and checked against what you already know.</p>
        <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
          <button class="btn btn-primary" id="new-guided">${icon('spark', 15)} Project for my level</button>
          <button class="btn" id="new-stretch">${icon('bolt', 15)} Push me harder</button>
        </div>
      </div>
      <div id="project-list"></div>
    </div>`;

  view.querySelector('#new-guided').addEventListener('click', (e) => generate(e.currentTarget, 'guided'));
  view.querySelector('#new-stretch').addEventListener('click', (e) => generate(e.currentTarget, 'stretch'));
  renderList();
}

function renderList() {
  const host = document.getElementById('project-list');
  if (!host) return;

  if (!S.projects.length) {
    host.innerHTML = `
      <div class="empty">
        <h3>No projects yet</h3>
        <p>Ask for one and it will be shaped around what you've learned so far — no guessing, no padding.</p>
      </div>`;
    return;
  }

  host.innerHTML = S.projects.map((p) => projectCard(p)).join('');
  S.projects.forEach((p) => wireCard(p));

  if (S.focusProject) {
    const target = host.querySelector(`[data-project="${S.focusProject}"]`);
    S.focusProject = null;
    if (target) {
      target.classList.add('attention');
      requestAnimationFrame(() => target.scrollIntoView({ behavior: 'smooth', block: 'center' }));
      setTimeout(() => target.classList.remove('attention'), 2600);
    }
  }
}

function projectCard(p) {
  const b = p.brief || {};
  const review = p.review;
  const isCheckpoint = p.checkpoint || p.mode === 'checkpoint';
  const passed = isCheckpoint && review && (review.score || 0) >= 0.7;
  return `
    <div class="card proj-card ${isCheckpoint ? 'checkpoint-card' : ''}" data-project="${esc(p.id)}">
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;flex-wrap:wrap">
        ${isCheckpoint
          ? `<span class="chip ${passed ? 'good' : 'why'}">${icon('wand', 12)} Stage checkpoint</span>`
          : `<span class="chip ${p.mode === 'stretch' ? 'why' : 'accent'}">${p.mode === 'stretch' ? 'Stretch' : 'Your level'}</span>`}
        ${passed ? `<span class="chip good">${icon('check', 12)} Passed</span>` : ''}
        <span style="font-size:12px;color:var(--dim)">${timeAgo(p.at)}</span>
      </div>
      <h3>${esc(b.title || 'Project')}</h3>
      ${b.pitch ? `<p class="proj-pitch">${esc(b.pitch)}</p>` : ''}
      ${b.brief ? `<div class="md proj-brief">${md(b.brief)}</div>` : ''}

      ${(b.milestones || []).length ? `
        <div class="milestones">
          ${b.milestones.map((m, i) => `
            <div class="milestone">
              <span class="num">${i + 1}</span>
              <div>
                <strong>${esc(m.title)}</strong>${m.minutes ? `<span style="color:var(--dim);font-size:11.5px"> · ${m.minutes} min</span>` : ''}
                ${m.detail ? `<p>${esc(m.detail)}</p>` : ''}
              </div>
            </div>`).join('')}
        </div>` : ''}

      ${(b.requirements || []).length ? `
        <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--dim);margin-top:16px">Requirements</div>
        <ul class="proj-req">${b.requirements.map((r) => `<li>${esc(r)}</li>`).join('')}</ul>` : ''}

      ${(b.stretch || []).length ? `<p class="stretch-line"><b>Stretch:</b> ${b.stretch.map(esc).join(' · ')}</p>` : ''}

      <details style="margin-top:18px" ${review ? 'open' : ''}>
        <summary style="cursor:pointer;font-size:13.5px;color:var(--accent);list-style:none;display:flex;align-items:center;gap:8px">
          ${icon('code', 15)} ${review ? 'Your submission' : 'Submit your code for review'}
        </summary>
        <div style="margin-top:14px">
          <textarea data-code rows="10" placeholder="# paste your project code here">${esc(p.code || '')}</textarea>
          <input type="text" data-notes style="margin-top:8px" value="${esc(p.notes || '')}" placeholder="Anything you want the reviewer to know (optional)">
          <div style="display:flex;gap:10px;margin-top:10px">
            <button class="btn btn-primary btn-sm" data-review>Review my project</button>
            <button class="btn btn-sm" data-playground>Open in playground</button>
          </div>
        </div>
      </details>

      ${review ? reviewHtml(review) : ''}
    </div>`;
}

function reviewHtml(review) {
  const pct = Math.round((review.score || 0) * 100);
  return `
    <div class="review">
      <div class="review-head">
        <span style="color:var(--accent-2)">${icon('spark', 15)}</span>
        <b>Review</b>
        <span class="score-pill" style="background:${pct >= 80 ? 'rgba(92,224,160,.12)' : pct >= 60 ? 'rgba(255,184,107,.12)' : 'rgba(255,125,125,.12)'};color:${pct >= 80 ? 'var(--good)' : pct >= 60 ? 'var(--warn)' : 'var(--bad)'}">${pct}%</span>
      </div>
      <div class="review-body">
        ${review.verdict ? `<p style="margin:0 0 12px">${esc(review.verdict)}</p>` : ''}
        ${(review.works || []).length ? `
          <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--good);margin:14px 0 6px">Working well</div>
          <ul style="margin:0;padding-left:18px;color:var(--muted);font-size:13.3px">${review.works.map((w) => `<li>${esc(w)}</li>`).join('')}</ul>` : ''}
        ${(review.issues || []).length ? `
          <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--warn);margin:16px 0 4px">Issues</div>
          ${review.issues.map((i) => `
            <div class="issue">
              <span class="sev ${esc(i.severity)}">${esc(i.severity)}</span>
              <div>
                ${i.where ? `<span style="color:var(--dim);font-family:var(--mono);font-size:12px">${esc(i.where)}</span> ` : ''}
                <span>${esc(i.what)}</span>
                ${i.fix ? `<div style="color:var(--muted);margin-top:3px">→ ${esc(i.fix)}</div>` : ''}
              </div>
            </div>`).join('')}` : ''}
        ${review.next_step ? `
          <div style="margin-top:16px;padding:12px 14px;border-radius:10px;background:var(--panel-2);border-left:2px solid var(--accent)">
            <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--dim);margin-bottom:4px">Do this next</div>
            ${esc(review.next_step)}
          </div>` : ''}
      </div>
    </div>`;
}

function wireCard(p) {
  const card = document.querySelector(`[data-project="${p.id}"]`);
  if (!card) return;
  const codeEl = card.querySelector('[data-code]');
  const notesEl = card.querySelector('[data-notes]');

  card.querySelector('[data-playground]')?.addEventListener('click', () => {
    localStorage.setItem('cairn.playground', codeEl.value || `# ${p.brief?.title || 'Project'}\n`);
    emit('navigate', 'playground');
  });

  card.querySelector('[data-review]')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const code = codeEl.value.trim();
    if (!code) {
      toast('Paste your code first.', 'warn');
      codeEl.focus();
      return;
    }
    busy(btn, true, 'Reviewing');
    try {
      const res = await api('api/project.php', {
        action: 'review',
        id: p.id,
        code,
        notes: notesEl.value,
      });
      p.code = code;
      p.notes = notesEl.value;
      p.review = res.review;
      const wasDeclared = Boolean(S.mastery?.declared);
      if (res.state) emit('state', res.state);
      renderList();
      if (res.checkpoint?.passed) {
        if (!wasDeclared && res.state?.mastery?.declared) {
          toast('That was the last chapter: you have completely mastered Python.', 'good', 7000);
        } else {
          toast('Checkpoint passed. The next stage is open.', 'good', 5000);
        }
      } else if (res.checkpoint) {
        toast('Reviewed. Pass at 70% to clear this checkpoint.', 'warn', 5000);
      } else {
        toast('Review ready.', 'good');
      }
    } catch (err) {
      toast(err.message, 'error');
      busy(btn, false);
    }
  });
}

/**
 * Generate (or re-open) the stage checkpoint project and jump the learner
 * to it. Shared by the journey, the forge and the projects page.
 */
export async function startCheckpoint(stageId, button = null) {
  if (!isConfigured()) {
    toast('Connect a model first.', 'warn');
    emit('navigate', 'settings');
    return null;
  }
  if (button) busy(button, true, 'Designing');

  // If this stage already has a project, reuse it instead of paying twice.
  const existing = S.projects.find((p) => (p.stage || '') === stageId);
  if (existing) {
    S.focusProject = existing.id;
    if (button) busy(button, false);
    emit('navigate', 'projects');
    return existing;
  }

  try {
    const res = await api('api/project.php', { action: 'generate', stage: stageId });
    S.projects.unshift(res.project);
    S.focusProject = res.project.id;
    if (res.user) emit('state', { user: res.user });
    emit('navigate', 'projects');
    toast('Checkpoint project ready.', 'good');
    return res.project;
  } catch (err) {
    toast(err.message, 'error');
    if (button) busy(button, false);
    return null;
  }
}

async function generate(button, mode) {
  if (!isConfigured()) {
    toast('Connect a model first.', 'warn');
    emit('navigate', 'settings');
    return;
  }
  busy(button, true, 'Thinking');
  try {
    const res = await api('api/project.php', { action: 'generate', mode });
    S.projects.unshift(res.project);
    renderList();
    toast('Project ready. Scroll down.', 'good');
  } catch (err) {
    toast(err.message, 'error');
  } finally {
    busy(button, false);
  }
}
