import { S, emit, on, isConfigured, displayName } from './state.js';
import { api } from './api.js';
import { esc, icon, toast, busy, openModal, timeAgo } from './ui.js';
import { buildNow, replan, nudgeBuilder, extendNow } from './builder.js';
import { startCheckpoint } from './projects.js';

/**
 * The forge: where the learner sees, steers and adds to the curriculum the
 * model has designed for them. Not a settings page — a workshop.
 */

let draft = { request: '', level: 'same' };
let liveWired = false;

const CHECKPOINT_LABEL = {
  locked: 'Locked',
  ready: 'Ready',
  in_progress: 'In progress',
  reviewed: 'Needs another pass',
  passed: 'Passed',
};

export function openCurriculum() {
  const view = document.getElementById('view');
  const plan = S.plan;
  const bank = S.bank || {
    total: 40, built: 0, ahead: 0, queued: 40, items: 0, target: 4, auto: true,
    planning: true, plan_ready: false, last_error: '', last_at: null,
  };

  if (!liveWired) {
    liveWired = true;
    on('state', () => {
      if (S.view !== 'curriculum') return;
      const hadPlan = Boolean(view.querySelector('.forge-plan'));
      if (!hadPlan && S.plan) {
        openCurriculum();
        return;
      }
      syncLive();
    });
    on('bank-built', () => {
      if (S.view !== 'curriculum') return;
      syncLive();
    });
  }

  const ahead = Number(bank.ahead || 0);
  const target = Number(bank.target ?? 4);
  const pct = target > 0 ? Math.min(100, Math.round((ahead / target) * 100)) : 0;

  view.innerHTML = `
    <div class="panel">
      <div class="panel-head">
        <div class="eyebrow">Curriculum forge</div>
        <h2>Your course, built ahead of you</h2>
        <p>${esc(displayName())}, this is the plan behind your lessons — every unit shaped around what you're building toward, written before you get there.</p>
      </div>

      ${!isConfigured() ? `
        <div class="gate">
          ${icon('lock', 18)}
          <div>
            <b>The forge needs a model</b>
            <p>Lesson generation, planning and grading all run on the model you pick. Connect one and the forge will start filling the bank on its own.</p>
            <button class="btn btn-sm btn-primary" id="forge-settings">Set up a model ${icon('arrow', 13)}</button>
          </div>
        </div>` : ''}

      <div class="forge-stats">
        <div class="fstat">
          <b id="fg-ahead">${ahead}</b>
          <small>lessons ahead</small>
          <div class="bar" style="margin-top:8px"><i id="fg-ahead-bar" style="width:${pct}%"></i></div>
          <span class="fstat-note" id="fg-target-note">target ${target}</span>
        </div>
        <div class="fstat">
          <b><span id="fg-built">${Number(bank.built || 0)}</span><span class="fstat-dim">/${Number(bank.total || 0)}</span></b>
          <small>lessons written</small>
        </div>
        <div class="fstat">
          <b id="fg-items">${Number(bank.items || 0)}</b>
          <small>activities banked</small>
        </div>
        <div class="fstat">
          <b id="fg-queue">${Number(bank.queued || 0)}</b>
          <small>queued</small>
        </div>
        <div class="fstat">
          <b>${plan ? `${plan.stats.checkpointsPassed}<span class="fstat-dim">/${plan.stats.checkpoints}</span>` : '—'}</b>
          <small>checkpoints passed</small>
        </div>
      </div>

      ${isConfigured() ? forgeControls(bank, target) : ''}

      ${bank.last_error ? `<div class="forge-note bad">${icon('bolt', 15)} <div><b>The forge paused.</b> ${esc(bank.last_error)}</div></div>` : ''}
      ${plan?.fallback ? `
        <div class="forge-note warn">
          ${icon('bolt', 15)}
          <div><b>This plan is the syllabus default.</b> The model could not design one${plan.note ? `: ${esc(plan.note)}` : '.'} You can try redesigning it, or keep learning — lessons still work.</div>
        </div>` : ''}

      ${plan ? planBlock(plan) : planPendingBlock(bank)}

      ${masteryBlock(S.mastery)}

      <form class="module-forge card" data-module-form>
        <div class="mf-head">
          <div>
            <h3>${icon('wand', 17)} Ask for a module</h3>
            <p>Anything the path missed. Name it and the tutor writes a one-off lesson, with exercises, at your level.</p>
          </div>
        </div>
        <textarea rows="2" data-module-request maxlength="600" placeholder="e.g. Read a CSV of football scores and find the top scorer">${esc(draft.request)}</textarea>
        <div class="mf-actions">
          <select data-module-level aria-label="Difficulty">
            ${[['easier', 'A bit easier'], ['same', 'About my level'], ['harder', 'Push me harder']].map(([v, label]) => `
              <option value="${v}" ${draft.level === v ? 'selected' : ''}>${label}</option>`).join('')}
          </select>
          <span style="flex:1"></span>
          <button class="btn btn-primary" type="submit" ${!isConfigured() ? 'disabled' : ''}>${icon('plus', 14)} Forge module</button>
        </div>
      </form>

      ${moduleList()}
    </div>`;

  wire(view, plan, bank);
}

function forgeControls(bank, target) {
  return `
    <div class="forge-controls card">
      <label class="switch-row">
        <span class="switch">
          <input type="checkbox" id="forge-auto" ${bank.auto ? 'checked' : ''}>
          <i></i>
        </span>
        <span class="switch-copy">
          <b>Build lessons in the background</b>
          <small>Writes one lesson at a time while you're here. Pauses when the tab is hidden.</small>
        </span>
      </label>

      <div class="fc-right">
        <label class="fc-target">
          <span>Keep</span>
          <select id="forge-target">
            ${[1, 2, 3, 4, 5, 6, 8, 10].map((n) => `<option value="${n}" ${n === target ? 'selected' : ''}>${n}</option>`).join('')}
          </select>
          <span>ahead</span>
        </label>
        <button class="btn btn-sm" id="forge-now">${icon('bolt', 13)} Build 3 now</button>
        <button class="btn btn-sm btn-ghost" id="forge-replan" title="Ask the model to redesign the whole curriculum">Redesign</button>
      </div>
    </div>`;
}

function planPendingBlock(bank) {
  return `
    <div class="forge-planning card">
      <span class="spinner"></span>
      <div>
        <b>Designing your curriculum…</b>
        <p>${bank.plan_ready ? 'The plan is ready. Lessons start writing on the next tick.' : 'The model is shaping a plan around your goal, your level and what you want to build. This takes a minute.'}</p>
      </div>
      <button class="btn btn-sm" id="forge-plan">Design now</button>
    </div>`;
}

function masteryBlock(m) {
  if (!m || !m.core_done) return '';

  const chapters = Number(m.chapters || 0);
  const units = Number(m.units || 0);
  const finished = Number(m.finished || 0);
  const checkpoints = Number(m.checkpoints || 0);
  const passed = Number(m.checkpoints_passed || 0);
  const max = Number(m.max_chapters || 100);
  const atMax = chapters >= max;

  if (m.declared) {
    return `
      <div class="mastery-block done">
        <span class="mb-icon">${icon('spark', 18)}</span>
        <div class="mb-copy">
          <div class="eyebrow">Mastery declared</div>
          <b>You have mastered Python.</b>
          <p>All 40 core skills are done and the declaration is yours. ${chapters} advanced chapter${chapters === 1 ? '' : 's'} written so far — ${finished}/${units} advanced units and ${passed}/${checkpoints} checkpoints passed. Cairn can keep writing harder material for as long as you want it.</p>
        </div>
        <div class="mb-actions">
          ${atMax
            ? `<span class="chip good">${icon('check', 11)} Final chapter</span>`
            : `<button class="btn btn-sm btn-primary" id="forge-extend">${icon('wand', 13)} Keep going</button>`}
        </div>
      </div>`;
  }

  if (chapters === 0) {
    return `
      <div class="mastery-block pending">
        <span class="mb-icon">${icon('spark', 18)}</span>
        <div class="mb-copy">
          <div class="eyebrow">Mastery track</div>
          <b>The core 40 are done. The advanced track starts now.</b>
          <p>Cairn is writing chapter 1: eight units of real developer work — testing, files, structure, concurrency, performance. Finish two complete chapters and their checkpoints and Cairn will declare that you have mastered Python.</p>
        </div>
        <div class="mb-actions">
          <button class="btn btn-sm btn-primary" id="forge-extend">${icon('wand', 13)} Write chapter 1</button>
        </div>
      </div>`;
  }

  return `
    <div class="mastery-block">
      <span class="mb-icon">${icon('spark', 18)}</span>
      <div class="mb-copy">
        <div class="eyebrow">Mastery track</div>
        <b>Chapter ${chapters} of the advanced track</b>
        <p>${finished}/${units} advanced units done · ${passed}/${checkpoints} chapter checkpoints passed. Finish two complete chapters and Cairn will declare you a Python master, then keep writing if you want more.</p>
      </div>
      <div class="mb-actions">
        ${atMax
          ? `<span class="chip good">${icon('check', 11)} Final chapter</span>`
          : m.can_extend
            ? `<button class="btn btn-sm btn-primary" id="forge-extend">${icon('wand', 13)} Write next chapter</button>`
            : `<button class="btn btn-sm" disabled title="Finish the current chapter first">${icon('lock', 12)} Next chapter</button>`}
      </div>
    </div>`;
}

function planBlock(plan) {
  const stages = plan.stages || [];
  const coreCount = stages.filter((s) => !s.mastery).length;
  const openIndex = stages.findIndex((s) => s.finished < s.total);
  return `
    <div class="forge-plan">
      <div class="fp-head">
        <div>
          <div class="eyebrow" style="margin-bottom:6px">The plan</div>
          <h3>${esc(plan.title || 'Your curriculum')}</h3>
          <p>${esc(plan.throughline || '')}</p>
        </div>
        <div class="fp-meta">
          <span class="chip">${icon('book', 12)} ${plan.stats.units} units</span>
          <span class="chip">${icon('wand', 12)} ${plan.stats.checkpoints} checkpoints</span>
          ${plan.model ? `<span class="chip" title="The model that wrote this plan">${esc(plan.model)}</span>` : ''}
          <span class="fp-when">${timeAgo(plan.at)}</span>
        </div>
      </div>

      <div class="fstage-list">
        ${stages.map((stage, i) => fstage(stage, i, i === (openIndex === -1 ? stages.length - 1 : openIndex), coreCount)).join('')}
      </div>
    </div>`;
}

function fstage(stage, index, open, coreCount) {
  const built = stage.units.filter((u) => u.built).length;
  const label = stage.mastery ? `M${index - coreCount + 1}` : String(index + 1);
  return `
    <details class="fstage" ${open ? 'open' : ''}>
      <summary>
        <span class="fstage-num">${stage.mastery || !(stage.finished === stage.total && stage.total > 0) ? label : icon('check', 13)}</span>
        <span class="fstage-title">
          <b>${esc(stage.title)}</b>
          <small>${esc(stage.emphasis || '')}</small>
        </span>
        ${stage.mastery ? `<span class="chip mastery-chip" title="Advanced chapter after the core">${icon('spark', 11)} Mastery</span>` : ''}
        <span class="fstage-count"><b>${stage.finished}</b>/${stage.total} done · ${built} banked</span>
        <span class="chev">${icon('arrow', 14)}</span>
      </summary>
      <div class="fstage-body">
        <div class="unit-grid">
          ${stage.units.map(unitChip).join('')}
        </div>
        ${checkpointRow(stage.project)}
      </div>
    </details>`;
}

function unitChip(unit) {
  const state = unit.done ? 'done' : unit.built ? 'built' : 'queued';
  const unlocked = isUnlocked(unit.skill);
  const label = state === 'done' ? 'Done' : state === 'built' ? 'Ready' : unlocked ? 'Write on open' : 'Locked';
  return `
    <button class="unit ${state} ${!unlocked ? 'locked' : ''}" data-unit="${esc(unit.skill)}"
            title="${esc(unit.focus || unit.title)}">
      <span class="unit-top">
        <span class="unit-dot">${state === 'done' ? icon('check', 10) : state === 'built' ? icon('book', 10) : ''}</span>
        <span class="unit-name">${esc(unit.title)}</span>
      </span>
      <span class="unit-meta">
        <span class="unit-state">${label}</span>
        <span>${unit.minutes} min</span>
      </span>
    </button>`;
}

function checkpointRow(cp) {
  if (!cp) return '';
  const state = cp.state || 'locked';
  const pct = cp.score != null ? Math.round(cp.score * 100) : null;
  const passed = state === 'passed';
  const actionable = state === 'ready';
  const openable = state === 'in_progress' || state === 'reviewed';

  return `
    <div class="checkpoint ${state}">
      <span class="cp-icon">${icon(passed ? 'check' : 'wand', 15)}</span>
      <div class="cp-copy">
        <b>${esc(cp.title || 'Stage checkpoint')}</b>
        <small>${esc(cp.pitch || 'A project that puts this whole stage together.')}</small>
        <div class="cp-meta">
          <span class="cp-badge">${CHECKPOINT_LABEL[state] || state}</span>
          ${pct != null ? `<span>${pct}%</span>` : `<span>${cp.finished}/${cp.total} stage lessons</span>`}
          ${state === 'locked' && cp.total ? `<span>${cp.total - cp.finished} to go</span>` : ''}
        </div>
      </div>
      ${actionable
        ? `<button class="btn btn-sm btn-primary" data-cp-start="${esc(cp.stage)}">${icon('play', 12)} Start checkpoint</button>`
        : openable
          ? `<button class="btn btn-sm" data-cp-open="${esc(cp.projectId || '')}">${state === 'in_progress' ? 'Continue' : 'Try again'}</button>`
          : passed
            ? `<span class="chip good">${icon('check', 11)} Cleared</span>`
            : `<span class="cp-locked">${icon('lock', 12)}</span>`}
    </div>`;
}

function moduleList() {
  if (!S.modules.length) return '';
  return `
    <div class="module-list">
      <div class="ml-head">
        <h3>Your modules</h3>
        <span class="chip">${S.modules.length}</span>
      </div>
      <div class="module-grid">
        ${S.modules.map((m) => `
          <div class="module-card ${m.done ? 'done' : ''}">
            <div class="mc-head">
              <span class="mc-dot">${m.done ? icon('check', 10) : icon('wand', 11)}</span>
              <b>${esc(m.title)}</b>
            </div>
            <p>${esc(m.objective || m.request)}</p>
            <div class="mc-meta">
              <span>${esc(m.level === 'easier' ? 'Easier' : m.level === 'harder' ? 'Harder' : 'Your level')}</span>
              <span>${m.minutes} min</span>
              <span>${m.activities} activities</span>
              <span>${timeAgo(m.at)}</span>
            </div>
            <div class="mc-actions">
              <button class="btn btn-sm" data-module-open="${esc(m.id)}">${m.done ? 'Revisit' : 'Open module'}</button>
              <button class="icon-btn" data-module-remove="${esc(m.id)}" title="Remove module" aria-label="Remove module">${icon('trash', 14)}</button>
            </div>
          </div>`).join('')}
      </div>
    </div>`;
}

function isUnlocked(skillId) {
  for (const stage of S.roadmap || []) {
    const skill = (stage.skills || []).find((s) => s.id === skillId);
    if (skill) return Boolean(skill.unlocked || skill.done);
  }
  return true;
}

function wire(view, plan, bank) {
  view.querySelector('#forge-settings')?.addEventListener('click', () => emit('navigate', 'settings'));

  view.querySelectorAll('[data-unit]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const id = btn.getAttribute('data-unit');
      if (!isConfigured()) {
        toast('Connect a model first.', 'warn');
        emit('navigate', 'settings');
        return;
      }
      if (!isUnlocked(id)) {
        toast('Finish the earlier stage first.', 'warn');
        return;
      }
      emit('open-skill', id);
    });
  });

  view.querySelectorAll('[data-cp-start]').forEach((btn) => {
    btn.addEventListener('click', () => startCheckpoint(btn.getAttribute('data-cp-start'), btn));
  });

  view.querySelectorAll('[data-cp-open]').forEach((btn) => {
    btn.addEventListener('click', () => {
      S.focusProject = btn.getAttribute('data-cp-open');
      emit('navigate', 'projects');
    });
  });

  const auto = view.querySelector('#forge-auto');
  const target = view.querySelector('#forge-target');
  const saveSettings = async (announce) => {
    try {
      const res = await api('api/curriculum.php', {
        action: 'settings',
        auto: auto?.checked ?? true,
        target: Number(target?.value || 4),
      });
      emit('state', res);
      syncLive();
      if (announce) toast(`Keeping ${res.bank?.target ?? Number(target?.value || 4)} lessons ahead.`, 'good', 2600);
      nudgeBuilder();
    } catch (err) {
      toast(err.message, 'error');
    }
  };
  auto?.addEventListener('change', () => saveSettings(true));
  target?.addEventListener('change', () => saveSettings(true));

  view.querySelector('#forge-now')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    busy(btn, true, 'Writing');
    try {
      const data = await buildNow(3);
      if (data.error) {
        toast(data.error, 'error');
      } else if (!data.built?.length) {
        toast('Nothing left to build just yet.', 'info');
      } else {
        toast(`Forge wrote ${data.built.length} lesson${data.built.length === 1 ? '' : 's'} ahead.`, 'good');
      }
      syncLive();
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      busy(btn, false);
    }
  });

  view.querySelector('#forge-extend')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const force = !S.mastery?.can_extend;
    busy(btn, true, 'Writing');
    try {
      const data = await extendNow(force);
      if (data.added) {
        toast(`New mastery chapter: ${data.chapter?.title || 'advanced Python'}`, 'good', 5000);
      } else if (data.busy) {
        toast('The forge is already writing that chapter.', 'info');
      } else if (data.reason === 'max') {
        toast('That is the last mastery chapter Cairn will write.', 'info');
      } else {
        toast('Finish the current chapter before starting the next.', 'info');
      }
      openCurriculum();
    } catch (err) {
      toast(err.message, 'error');
      busy(btn, false);
    }
  });

  view.querySelector('#forge-replan')?.addEventListener('click', () => {
    const { close, root } = openModal({
      title: 'Redesign the curriculum?',
      body: `<p style="margin:0;color:var(--muted);font-size:14px">The model will write a fresh plan around your profile and progress. Lessons you have already finished stay finished, and already-written lessons stay in the bank. This costs one large model call.</p>`,
      footer: `<button class="btn" data-cancel>Cancel</button><button class="btn btn-primary" data-go>${icon('wand', 13)} Redesign</button>`,
    });
    root.querySelector('[data-cancel]').addEventListener('click', close);
    root.querySelector('[data-go]').addEventListener('click', async (e) => {
      const btn = e.currentTarget;
      busy(btn, true, 'Designing');
      try {
        await replan();
        close();
        toast('New plan forged.', 'good');
        openCurriculum();
      } catch (err) {
        toast(err.message, 'error');
        busy(btn, false);
      }
    });
  });

  view.querySelector('#forge-plan')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    busy(btn, true, 'Designing');
    try {
      const data = await api('api/curriculum.php', { action: 'plan' });
      emit('state', data);
      openCurriculum();
    } catch (err) {
      toast(err.message, 'error');
      busy(btn, false);
    }
  });

  const form = view.querySelector('[data-module-form]');
  const requestEl = form?.querySelector('[data-module-request]');
  const levelEl = form?.querySelector('[data-module-level]');

  requestEl?.addEventListener('input', () => { draft.request = requestEl.value; });
  levelEl?.addEventListener('change', () => { draft.level = levelEl.value; });

  form?.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!isConfigured()) {
      toast('Connect a model first.', 'warn');
      emit('navigate', 'settings');
      return;
    }
    const request = requestEl.value.trim();
    if (request.length < 3) {
      toast('Tell the tutor what you want to learn.', 'warn');
      requestEl.focus();
      return;
    }
    const btn = form.querySelector('button[type="submit"]');
    busy(btn, true, 'Forging');
    try {
      const res = await api('api/curriculum.php', {
        action: 'module',
        request,
        level: levelEl.value,
      });
      emit('state', res.state);
      draft = { request: '', level: 'same' };
      toast('Module ready.', 'good');
      emit('open-module', res.module.id);
    } catch (err) {
      toast(err.message, 'error');
      busy(btn, false);
    }
  });

  view.querySelectorAll('[data-module-open]').forEach((btn) => {
    btn.addEventListener('click', () => emit('open-module', btn.getAttribute('data-module-open')));
  });

  view.querySelectorAll('[data-module-remove]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const id = btn.getAttribute('data-module-remove');
      btn.disabled = true;
      try {
        const res = await api('api/curriculum.php', { action: 'module_remove', id });
        emit('state', res);
        openCurriculum();
        toast('Module removed.', 'info');
      } catch (err) {
        toast(err.message, 'error');
        btn.disabled = false;
      }
    });
  });
}

/** Keep the numbers honest while the builder writes in the background. */
function syncLive() {
  const bank = S.bank || {};
  const set = (id, value) => {
    const el = document.getElementById(id);
    if (el) el.textContent = value;
  };
  set('fg-ahead', Number(bank.ahead || 0));
  set('fg-built', Number(bank.built || 0));
  set('fg-items', Number(bank.items || 0));
  set('fg-queue', Number(bank.queued || 0));
  const target = Number(bank.target ?? 4);
  const bar = document.getElementById('fg-ahead-bar');
  if (bar) bar.style.width = `${target > 0 ? Math.min(100, Math.round((Number(bank.ahead || 0) / target) * 100)) : 0}%`;
  const note = document.getElementById('fg-target-note');
  if (note) note.textContent = `target ${target}`;
}
