import { S, emit, on, isConfigured, displayName } from './state.js';
import { esc, icon, toast } from './ui.js';
import { openPlacement, skipPlacement } from './placement.js';
import { startCheckpoint } from './projects.js';
import { extendNow } from './builder.js';

let live = false;

/**
 * The strip carries numbers the background builder changes while the learner
 * is reading. Repaint it in place rather than re-rendering the whole journey
 * (which would close stage accordions and bounce the scroll position).
 */
function refreshStrip() {
  const host = document.getElementById('journey-forge-strip');
  if (!host) return;
  host.innerHTML = forgeStrip();
  host.querySelector('#goto-forge')?.addEventListener('click', () => emit('navigate', 'curriculum'));
}

function wireLive() {
  if (live) return;
  live = true;
  const tick = () => { if (S.view === 'journey') refreshStrip(); };
  on('state', tick);
  on('bank-built', tick);
}

export function openJourney() {
  const view = document.getElementById('view');
  const p = S.progress || { percent: 0, finished: 0, total: 0, stageName: '', nextSkill: null, complete: false };
  const stages = S.roadmap || [];
  const next = p.nextSkill;
  const mastery = S.mastery || {};
  const coreStages = stages.filter((s) => !s.mastery).length;
  // The placement offer only makes sense before anything is done.
  const needsPlacement = !S.user?.placement && Number(p.finished || 0) === 0;

  const openStage = stages.find((s) => s.unlocked && s.finished < s.total) || stages.find((s) => s.unlocked);

  const circumference = 2 * Math.PI * 46;
  const ringPct = p.mastered ? 100 : (p.percent || 0);
  const offset = circumference - (circumference * ringPct) / 100;

  const heading = p.mastered
    ? 'You have mastered Python'
    : p.awaitingMastery
      ? 'Core complete · the mastery track opens'
      : p.stageMastery
        ? esc(p.stageName || 'Mastery chapter')
        : `Stage ${(p.stage || 0) + 1} · ${esc(p.stageName || 'Start')}`;

  const intro = p.mastered
    ? 'Core 40 done, advanced chapters done, checkpoints passed. You can structure, test, profile and debug real Python programs. Keep going for another chapter, or go build something nobody asked for.'
    : p.awaitingMastery
      ? 'The core curriculum is complete. The forge is writing your first advanced chapter — eight units of the work developers do after the basics.'
      : p.stageMastery
        ? `${esc(displayName())}, you're past the core. These chapters are the judgement calls a working developer makes.`
        : `${esc(displayName())}, here's what stands between you and building anything.`;

  view.innerHTML = `
    <div class="panel">
      <div class="panel-head">
        <div class="eyebrow">Journey</div>
        <h2>${heading}</h2>
        <p>${intro}</p>
      </div>

      ${!isConfigured() ? `
        <div class="gate">
          ${icon('lock', 18)}
          <div>
            <b>Connect a model to begin</b>
            <p>Lessons and feedback are written by the AI model you choose. Pick one in settings — NVIDIA NIM has a free tier.</p>
            <button class="btn btn-sm btn-primary" id="goto-settings">Set up a model ${icon('arrow', 13)}</button>
          </div>
        </div>` : ''}

      <div id="journey-forge-strip">${forgeStrip()}</div>

      <div class="hero">
        <div>
          <div class="kicker">${needsPlacement ? 'Start here' : p.mastered ? 'Python mastered' : p.awaitingMastery ? 'Core complete' : p.stageMastery ? 'Advanced track' : 'Up next'}</div>
          <h2>${needsPlacement
            ? 'Find your starting point'
            : next
              ? esc(next.title)
              : p.mastered
                ? 'The whole map, plus the advanced track'
                : 'Your advanced chapter is being written'}</h2>
          <p>${needsPlacement
            ? 'Eight questions, about five minutes. Cairn will skip what you already know and start you where the gaps are.'
            : next
              ? esc(next.outcome)
              : p.mastered
                ? 'Nothing left that Cairn can teach you that you have not already done. Say the word and the forge writes another chapter.'
                : 'The forge is designing chapter 1 of the mastery track. It will be here in a moment, and the lessons will keep coming after that.'}</p>
          <div class="actions">
            ${needsPlacement
              ? `<button class="btn btn-primary" id="take-placement">${icon('spark', 13)} Take the placement test</button>
                 ${next ? `<button class="btn" id="skip-placement">Skip and start at the beginning</button>` : ''}`
              : next ? `<button class="btn btn-primary" id="start-next">${icon('play', 13)} Start this lesson</button>` : ''}
            ${p.mastered
              ? `<button class="btn btn-primary" id="keep-going" data-extend="force">${icon('wand', 13)} Keep going</button>`
              : ''}
            <button class="btn ${needsPlacement ? 'btn-ghost' : ''}" id="go-play">Open the playground</button>
          </div>
        </div>
        <div class="ring">
          <svg width="104" height="104" aria-hidden="true">
            <circle cx="52" cy="52" r="46" stroke="#1c2330" stroke-width="7" fill="none"/>
            <circle cx="52" cy="52" r="46" stroke="url(#ringGrad)" stroke-width="7" fill="none"
                    stroke-linecap="round" stroke-dasharray="${circumference.toFixed(1)}"
                    stroke-dashoffset="${offset.toFixed(1)}"/>
            <defs>
              <linearGradient id="ringGrad" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0%" stop-color="#5eead4"/>
                <stop offset="100%" stop-color="#8b9dff"/>
              </linearGradient>
            </defs>
          </svg>
          <div class="ring-label">${p.mastered ? '<span class="ring-check">✓</span>' : `${p.percent || 0}%`}<small>${p.mastered ? 'mastered' : `${p.finished}/${p.total}`}</small></div>
        </div>
      </div>

      ${masteryBanner(mastery)}

      <div class="stage-list" id="stage-list">
        ${stages.map((s, i) => {
          const label = s.mastery ? `M${s.index - coreStages + 1}` : String(s.index + 1);
          const divider = s.mastery && (i === 0 || !stages[i - 1].mastery)
            ? '<div class="stage-divider"><span>Beyond the core · mastery track</span></div>'
            : '';
          return divider + stageCard(s, label, openStage?.id === s.id);
        }).join('')}
      </div>

      <div class="dest">
        ${icon('spark', 16)}
        <div><b style="color:var(--text)">Where this ends:</b> ${esc(S.syllabus.destination || '')}</div>
      </div>
    </div>`;

  const settingsBtn = view.querySelector('#goto-settings');
  if (settingsBtn) settingsBtn.addEventListener('click', () => emit('navigate', 'settings'));

  view.querySelector('#goto-forge')?.addEventListener('click', () => emit('navigate', 'curriculum'));

  view.querySelectorAll('[data-cp-start]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      startCheckpoint(btn.getAttribute('data-cp-start'), btn);
    });
  });

  view.querySelectorAll('[data-cp-open]').forEach((btn) => {
    btn.addEventListener('click', (e) => {
      e.stopPropagation();
      S.focusProject = btn.getAttribute('data-cp-open');
      emit('navigate', 'projects');
    });
  });

  view.querySelector('#start-next')?.addEventListener('click', () => {
    if (next) emit('open-skill', next.id);
  });

  view.querySelectorAll('[data-extend]').forEach((btn) => {
    btn.addEventListener('click', async () => {
      const force = btn.getAttribute('data-extend') === 'force';
      btn.disabled = true;
      const label = btn.innerHTML;
      btn.textContent = 'Writing…';
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
        openJourney();
      } catch (err) {
        toast(err.message, 'error');
        btn.disabled = false;
        btn.innerHTML = label;
      }
    });
  });

  view.querySelector('#go-play')?.addEventListener('click', () => emit('navigate', 'playground'));
  view.querySelector('#take-placement')?.addEventListener('click', () => openPlacement());
  view.querySelector('#skip-placement')?.addEventListener('click', () => skipPlacement());

  view.querySelectorAll('[data-stage]').forEach((card) => {
    card.querySelector('[data-toggle]')?.addEventListener('click', () => {
      card.classList.toggle('open');
    });
  });

  view.querySelectorAll('[data-skill]').forEach((row) => {
    row.addEventListener('click', () => {
      const id = row.getAttribute('data-skill');
      if (row.classList.contains('locked')) {
        toast('Finish the earlier skills in this stage first.', 'warn');
        return;
      }
      emit('open-skill', id);
    });
    row.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        row.click();
      }
    });
  });

  wireLive();
}

function masteryBanner(m) {
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
      <div class="mastery-banner done">
        <span class="mb-icon">${icon('spark', 20)}</span>
        <div class="mb-copy">
          <div class="eyebrow">Mastery declared</div>
          <b>You have completely mastered Python.</b>
          <p>All ${Number(m.core_total || 40)} core skills are done and the mastery declaration is yours. ${chapters} advanced chapter${chapters === 1 ? '' : 's'} written so far — ${finished}/${units} advanced units and ${passed}/${checkpoints} chapter checkpoints passed. From here it is judgement and taste, not new syntax: keep asking for chapters or go build.</p>
        </div>
        <div class="mb-actions">
          ${atMax
            ? `<span class="chip good">${icon('check', 11)} Final chapter</span>`
            : `<button class="btn btn-sm btn-primary" data-extend="force">${icon('wand', 13)} Keep going</button>`}
        </div>
      </div>`;
  }

  if (chapters === 0) {
    return `
      <div class="mastery-banner pending">
        <span class="mb-icon">${icon('spark', 20)}</span>
        <div class="mb-copy">
          <div class="eyebrow">Mastery track</div>
          <b>Core 40 complete — chapter 1 is being written.</b>
          <p>Cairn adds an advanced chapter of eight units after the core. Finish two complete chapters and their checkpoints and it will declare that you have mastered Python — then keep going for more.</p>
        </div>
        <div class="mb-actions">
          <button class="btn btn-sm" data-extend="auto">${icon('wand', 13)} Write it now</button>
        </div>
      </div>`;
  }

  return `
    <div class="mastery-banner">
      <span class="mb-icon">${icon('spark', 20)}</span>
      <div class="mb-copy">
        <div class="eyebrow">Mastery track</div>
        <b>Advanced chapter ${chapters}</b>
        <p>${finished}/${units} advanced units done · ${passed}/${checkpoints} chapter checkpoints passed. Two full chapters unlock the mastery declaration; after that Cairn keeps writing on request.</p>
      </div>
      <div class="mb-actions">
        ${atMax
          ? `<span class="chip good">${icon('check', 11)} Final chapter</span>`
          : m.can_extend
            ? `<button class="btn btn-sm btn-primary" data-extend="auto">${icon('wand', 13)} Write next chapter</button>`
            : `<span class="chip">Chapter in progress</span>`}
      </div>
    </div>`;
}

function forgeStrip() {
  const b = S.bank;
  if (!b) return '';
  const target = Number(b.target ?? 4);
  const ahead = Number(b.ahead || 0);
  const pct = target > 0 ? Math.min(100, Math.round((ahead / target) * 100)) : 0;
  const ready = b.plan_ready;
  return `
    <div class="forge-strip">
      <span class="fs-icon">${icon('wand', 16)}</span>
      <div class="fs-copy">
        <b>${ready ? 'Lessons are being forged ahead of you' : 'Forging your curriculum'}</b>
        <small>${ahead} ready · target ${target}${b.items ? ` · ${b.items} activities banked` : ''}${b.last_error ? ' · paused' : ''}</small>
      </div>
      <div class="fs-bar"><i style="width:${pct}%"></i></div>
      <button class="btn btn-sm" id="goto-forge">Open the forge ${icon('arrow', 12)}</button>
    </div>`;
}

function checkpointMini(cp) {
  if (!cp) return '';
  const state = cp.state || 'locked';
  const pct = cp.score != null ? Math.round(cp.score * 100) : null;
  const label = {
    locked: 'Finish this stage to unlock',
    ready: 'Stage complete — checkpoint ready',
    in_progress: 'Project in progress',
    reviewed: 'Needs another pass',
    passed: pct != null ? `Passed · ${pct}%` : 'Passed',
  }[state] || state;

  return `
    <div class="journey-cp ${state}">
      <span class="jcp-icon">${icon(state === 'passed' ? 'check' : state === 'locked' ? 'lock' : 'wand', 14)}</span>
      <div class="jcp-copy">
        <b>${esc(cp.title || 'Stage checkpoint')}</b>
        <small>${esc(label)}</small>
      </div>
      ${state === 'ready'
        ? `<button class="btn btn-sm btn-primary" data-cp-start="${esc(cp.stage)}">Start checkpoint</button>`
        : state === 'in_progress' || state === 'reviewed'
          ? `<button class="btn btn-sm" data-cp-open="${esc(cp.projectId || '')}">${state === 'in_progress' ? 'Continue' : 'Retry'}</button>`
          : state === 'passed'
            ? `<span class="chip good">${icon('check', 11)} Cleared</span>`
            : `<span class="chip" style="color:var(--dim)">${cp.finished}/${cp.total}</span>`}
    </div>`;
}

function stageCard(stage, label, open) {
  const total = stage.skills.length;
  const finished = stage.finished;
  const cp = stage.checkpoint || {};
  const cls = [
    'stage',
    open ? 'open' : '',
    !stage.unlocked ? 'locked' : '',
    finished === total && total > 0 ? 'done' : '',
    stage.skills.some((s) => s.current) ? 'current' : '',
    cp.state === 'passed' ? 'checkpoint-passed' : '',
  ].filter(Boolean).join(' ');

  return `
    <div class="${cls}" data-stage="${esc(stage.id)}">
      <div class="stage-head" data-toggle>
        <span class="stage-num">${finished === total && total > 0 ? icon('check', 14) : label}</span>
        <div class="stage-title">
          <h3>${esc(stage.title)}</h3>
          <p>${esc(stage.outcome || '')}</p>
        </div>
        ${cp.state === 'ready' ? `<span class="stage-cp ready" title="Checkpoint ready">${icon('wand', 12)}</span>` : ''}
        ${cp.state === 'passed' ? `<span class="stage-cp done" title="Checkpoint passed">${icon('check', 12)}</span>` : ''}
        <span class="stage-count"><b>${finished}</b>/${total}</span>
        <span class="chev">${icon('arrow', 15)}</span>
      </div>
      <div class="skills">
        ${stage.skills.map((skill) => skillRow(skill)).join('')}
        ${checkpointMini(stage.checkpoint)}
      </div>
    </div>`;
}

function skillRow(skill) {
  const cls = [
    'skill',
    skill.done ? 'done' : '',
    skill.current ? 'current' : '',
    skill.built ? 'built' : '',
    !skill.unlocked && !skill.done ? 'locked' : '',
  ].filter(Boolean).join(' ');

  const cta = skill.done ? 'Revisit' : !skill.unlocked ? 'Locked' : skill.built ? 'Ready' : 'Start';

  return `
    <div class="${cls}" data-skill="${esc(skill.id)}" role="button" tabindex="0">
      <span class="skill-dot">${skill.done ? icon('check', 11) : skill.built ? icon('book', 10) : ''}</span>
      <span class="skill-body">
        <strong>${esc(skill.title)}</strong>
        <small>${esc(skill.outcome)}</small>
      </span>
      <span class="skill-cta">${cta}</span>
    </div>`;
}
