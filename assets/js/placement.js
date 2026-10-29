import { S, emit, isConfigured } from './state.js';
import { api } from './api.js';
import { esc, icon, toast, openModal } from './ui.js';

export async function openPlacement() {
  if (!isConfigured()) {
    toast('Connect a model first — the quiz is written by it.', 'warn');
    emit('navigate', 'settings');
    return;
  }

  const modal = openModal({
    title: 'Placement test',
    wide: true,
    body: `<div class="loading-line"><span class="spinner"></span> Writing questions for you…</div>`,
  });

  let questions = [];
  let answers = {};
  let index = 0;

  try {
    const res = await api('api/placement.php', { action: 'quiz' });
    questions = res.questions || [];
    if (!questions.length) throw new Error('No questions came back.');
    showQuestion();
  } catch (err) {
    modal.body.innerHTML = `
      <div class="empty" style="padding:28px 0">
        <h3>Couldn't create the quiz</h3>
        <p>${esc(err.message)}</p>
        <button class="btn btn-primary" id="pl-retry">Try again</button>
      </div>`;
    modal.body.querySelector('#pl-retry').addEventListener('click', () => {
      modal.close();
      openPlacement();
    });
  }

  function showQuestion() {
    const q = questions[index];
    const isLast = index === questions.length - 1;
    const current = answers[q.id];

    modal.body.innerHTML = `
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px">
        <span style="font-size:12px;color:var(--dim);font-variant-numeric:tabular-nums">Question ${index + 1} of ${questions.length}</span>
        <span class="chip">${esc(q.topic || 'python')}</span>
        <span style="flex:1"></span>
      </div>
      <div class="bar" style="margin-bottom:20px"><i style="width:${((index) / questions.length) * 100}%"></i></div>

      <div class="ex-prompt" style="font-size:15.5px">${esc(q.question)}</div>
      ${q.code ? `<pre class="md-pre" style="margin:12px 0"><code>${esc(q.code)}</code></pre>` : ''}

      <div class="opts" style="margin-top:16px">
        ${q.options.map((o, i) => `
          <button class="opt ${current === i ? 'picked' : ''}" data-opt="${i}">
            <span class="key">${String.fromCharCode(65 + i)}</span>
            <span>${esc(o)}</span>
          </button>`).join('')}
      </div>

      <div style="display:flex;gap:10px;margin-top:22px;align-items:center">
        <button class="btn btn-ghost btn-sm" id="pl-back" ${index === 0 ? 'disabled' : ''}>Back</button>
        <span style="flex:1"></span>
        <span style="font-size:12px;color:var(--dim)">${Object.keys(answers).length} answered</span>
        <button class="btn btn-primary" id="pl-next" ${current === undefined ? 'disabled' : ''}>
          ${isLast ? 'See my result' : 'Next'}
        </button>
      </div>`;

    modal.body.querySelectorAll('[data-opt]').forEach((btn) => {
      btn.addEventListener('click', () => {
        answers[q.id] = Number(btn.getAttribute('data-opt'));
        modal.body.querySelectorAll('[data-opt]').forEach((b) => b.classList.remove('picked'));
        btn.classList.add('picked');
        modal.body.querySelector('#pl-next').disabled = false;
      });
    });

    modal.body.querySelector('#pl-back').addEventListener('click', () => {
      if (index > 0) { index--; showQuestion(); }
    });

    modal.body.querySelector('#pl-next').addEventListener('click', async () => {
      if (index < questions.length - 1) {
        index++;
        showQuestion();
      } else {
        await submit();
      }
    });
  }

  async function submit() {
    modal.body.innerHTML = `<div class="loading-line"><span class="spinner"></span> Reading your answers…</div>`;
    try {
      const res = await api('api/placement.php', { action: 'submit', answers });
      const stage = res.placement.stage;
      const stageInfo = (res.roadmap || []).find((s) => s.index === stage) || {};
      const nextSkill = (res.roadmap || [])
        .flatMap((s) => s.skills)
        .find((s) => s.current);

      modal.body.innerHTML = `
        <div style="text-align:center;padding:6px 0 20px">
          <div style="font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--accent);font-weight:640;margin-bottom:8px">You're starting at stage ${stage + 1}</div>
          <h3 style="margin:0 0 10px;font-size:22px;letter-spacing:-.02em">${esc(stageInfo.title || '')}</h3>
          <p style="color:var(--muted);font-size:14px;max-width:46ch;margin:0 auto">${esc(res.placement.summary || '')}</p>
        </div>

        ${(res.placement.strengths || []).length ? `
          <div class="card" style="margin-bottom:12px">
            <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--good);margin-bottom:8px">Already solid</div>
            <div style="font-size:13.5px;color:var(--muted)">${res.placement.strengths.map(esc).join(' · ')}</div>
          </div>` : ''}

        ${(res.placement.gaps || []).length ? `
          <div class="card" style="margin-bottom:12px">
            <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--warn);margin-bottom:8px">Worth closing first</div>
            <div style="font-size:13.5px;color:var(--muted)">${res.placement.gaps.map(esc).join(' · ')}</div>
          </div>` : ''}

        ${nextSkill ? `
          <div class="card" style="border-color:rgba(94,234,212,.25)">
            <div style="font-size:11px;letter-spacing:.1em;text-transform:uppercase;color:var(--dim);margin-bottom:6px">First lesson</div>
            <div style="font-size:15px;font-weight:550">${esc(nextSkill.title)}</div>
            <div style="font-size:13px;color:var(--dim);margin-top:2px">${esc(nextSkill.outcome)}</div>
          </div>` : ''}

        <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end">
          <button class="btn btn-ghost" id="pl-close">Later</button>
          <button class="btn btn-primary" id="pl-start">Start learning ${icon('arrow', 14)}</button>
        </div>`;

      emit('state', res);

      modal.body.querySelector('#pl-close').addEventListener('click', () => {
        modal.close();
        emit('navigate', 'journey');
      });

      modal.body.querySelector('#pl-start').addEventListener('click', () => {
        modal.close();
        emit('navigate', 'journey');
        if (nextSkill) {
          setTimeout(() => emit('open-skill', nextSkill.id), 120);
        }
      });
    } catch (err) {
      modal.body.innerHTML = `
        <div class="empty" style="padding:24px 0">
          <h3>Couldn't read the results</h3>
          <p>${esc(err.message)}</p>
          <button class="btn btn-primary" id="pl-again">Try again</button>
        </div>`;
      modal.body.querySelector('#pl-again').addEventListener('click', () => {
        modal.close();
        openPlacement();
      });
    }
  }
}

/**
 * Start the curriculum from the very first skill, with no placement test.
 * The server records this so the journey stops asking for a placement.
 */
export async function skipPlacement() {
  try {
    const res = await api('api/placement.php', { action: 'skip' });
    emit('state', res);
    toast('Starting at the beginning. You can take the placement test later in Settings.', 'info');
    emit('navigate', 'journey');
  } catch (err) {
    toast(err.message, 'error');
  }
}
