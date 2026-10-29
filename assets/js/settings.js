import { S, emit, isConfigured } from './state.js';
import { api } from './api.js';
import { esc, icon, toast, busy } from './ui.js';
import { openPlacement } from './placement.js';
import { memoryCardHtml, wireMemory } from './memory.js';

export function openSettings() {
  const view = document.getElementById('view');
  const s = S.user?.settings || {};
  const p = S.user?.profile || {};
  const provider = S.providers[s.provider] || {};

  view.innerHTML = `
    <div class="panel">
      <div class="panel-head">
        <div class="eyebrow">Settings</div>
        <h2>Your setup</h2>
        <p>Bring your own model. Cairn never runs your code on a server, and your API key is stored encrypted on this server only so it can be used to talk to your provider.</p>
      </div>

      ${!isConfigured() ? `
        <div class="gate">
          ${icon('lock', 18)}
          <div>
            <b>One thing first: connect a model.</b>
            <p>Cairn writes every lesson with an AI model of your choosing. NVIDIA NIM is a good free starting point.</p>
            <a class="btn btn-sm btn-primary" href="${esc(provider.key_url || 'https://build.nvidia.com/')}" target="_blank" rel="noopener">Get an NVIDIA key ${icon('arrow', 13)}</a>
          </div>
        </div>` : ''}

      <div class="settings-grid">
        <div class="card set-card">
          <h3>Model provider</h3>
          <p>Any OpenAI-compatible endpoint works, plus Anthropic and Gemini.</p>

          <div class="form-row">
            <div class="label">Provider</div>
            <div>
              <select id="set-provider">
                ${Object.entries(S.providers).map(([id, prov]) => `
                  <option value="${esc(id)}" ${id === s.provider ? 'selected' : ''}>${esc(prov.label)}</option>`).join('')}
              </select>
              <div class="hint" id="provider-note" style="font-size:12px;color:var(--dim);margin-top:5px">${esc(provider.note || '')}</div>
            </div>
          </div>

          <div class="form-row">
            <div class="label">Model</div>
            <div>
              <div class="inline">
                <input type="text" id="set-model" value="${esc(s.model || '')}" placeholder="model id" list="model-list">
                <button class="btn btn-sm" id="load-models">Load list</button>
              </div>
              <datalist id="model-list">
                ${(provider.models || []).map((m) => `<option value="${esc(m)}"></option>`).join('')}
              </datalist>
              <div class="hint" style="font-size:12px;color:var(--dim);margin-top:5px">
                Bigger isn't always better here — a fast, cheap model writes a fine lesson. Use a stronger one if lessons fail to parse.
              </div>
            </div>
          </div>

          <div class="form-row">
            <div class="label">API key
              <small>${s.has_key ? `Saved: ${esc(s.key_hint)}` : 'Required for most providers'}</small>
            </div>
            <div>
              <input type="password" id="set-key" placeholder="${s.has_key ? 'Leave blank to keep the saved key' : 'paste your key'}">
              <div class="key-status ${s.has_key ? 'on' : ''}" id="key-status">
                <span class="dot"></span>${s.has_key ? 'A key is saved for this account' : 'No key saved yet'}
              </div>
            </div>
          </div>

          <div class="form-row">
            <div class="label">Base URL
              <small>Only needed for a custom endpoint</small>
            </div>
            <div>
              <input type="text" id="set-base" value="${esc(s.base_url || '')}" placeholder="${esc(provider.base || 'https://…/v1')}">
            </div>
          </div>

          <div class="form-row">
            <div class="label">Tutor style</div>
            <div>
              <select id="set-style">
                ${[['socratic', 'Socratic — leads you to the answer'],
                   ['direct', 'Direct — explains plainly'],
                   ['friendly', 'Friendly — patient and warm'],
                   ['challenge', 'Challenging — pushes you hard']].map(([v, label]) => `
                  <option value="${v}" ${s.tutor_style === v ? 'selected' : ''}>${label}</option>`).join('')}
              </select>
            </div>
          </div>

          <div class="form-row">
            <div class="label">Creativity
              <small>Higher means more varied lessons</small>
            </div>
            <div class="slider-row">
              <input type="range" id="set-temp" min="0" max="1.2" step="0.05" value="${Number(s.temperature ?? 0.7)}">
              <span id="temp-value" style="font-variant-numeric:tabular-nums;color:var(--muted);width:34px;text-align:right">${Number(s.temperature ?? 0.7).toFixed(2)}</span>
            </div>
          </div>

          <div class="form-row">
            <div class="label">Run time limit</div>
            <div>
              <select id="set-timeout">
                ${[5, 10, 15, 20, 30].map((t) => `<option value="${t}" ${Number(s.timeout) === t ? 'selected' : ''}>${t} seconds</option>`).join('')}
              </select>
            </div>
          </div>

          <div style="display:flex;gap:10px;margin-top:18px;flex-wrap:wrap">
            <button class="btn btn-primary" id="save-provider">Save</button>
            <button class="btn" id="test-provider">Test connection</button>
            <span style="flex:1"></span>
            ${s.has_key ? '<button class="btn btn-ghost btn-sm" id="clear-key" style="color:var(--bad)">Remove key</button>' : ''}
          </div>
          <div id="test-result"></div>
        </div>

        <div class="card set-card">
          <h3>About you</h3>
          <p>This is what makes your lessons yours. The more specific, the better.</p>

          <div class="form-row">
            <div class="label">Name</div>
            <div><input type="text" id="prof-display" value="${esc(S.user?.display || '')}"></div>
          </div>
          <div class="form-row">
            <div class="label">What do you want to build?
              <small>Concrete beats vague</small>
            </div>
            <div><textarea id="prof-goal" rows="2" placeholder="A small game, a data dashboard, an app for my shop…">${esc(p.goal || '')}</textarea></div>
          </div>
          <div class="form-row">
            <div class="label">Experience so far</div>
            <div><input type="text" id="prof-exp" value="${esc(p.experience || '')}" placeholder="Never coded / some Scratch / a bit of JavaScript…"></div>
          </div>
          <div class="form-row">
            <div class="label">Time per week</div>
            <div><input type="text" id="prof-time" value="${esc(p.time_per_week || '')}" placeholder="2 hours on weekends"></div>
          </div>
          <div class="form-row">
            <div class="label">Interests
              <small>Comma separated — used for examples</small>
            </div>
            <div><input type="text" id="prof-interests" value="${esc((p.interests || []).join(', '))}" placeholder="football, cooking, astronomy"></div>
          </div>
          <div class="form-row">
            <div class="label">Anything else?</div>
            <div><textarea id="prof-more" rows="2" placeholder="I learn better with pictures / I get bored by long explanations…">${esc(p.explained || '')}</textarea></div>
          </div>

          <div style="margin-top:18px">
            <button class="btn btn-primary" id="save-profile">Save profile</button>
          </div>
        </div>

        <div class="card set-card">
          <h3>Placement</h3>
          ${S.user?.placement ? `
            <p style="margin-bottom:12px">
              ${esc(S.user.placement.summary || '')}
              ${S.user.placement.confidence ? `<br><span style="color:var(--dim);font-size:12px">Confidence ${(S.user.placement.confidence * 100).toFixed(0)}%</span>` : ''}
            </p>
            ${(S.user.placement.gaps || []).length ? `<div style="font-size:13px;color:var(--muted)"><b style="color:var(--text)">Gaps to close:</b> ${S.user.placement.gaps.map(esc).join(' · ')}</div>` : ''}
            <div style="margin-top:16px"><button class="btn btn-sm" id="retake-placement">Retake the placement test</button></div>
          ` : `
            <p>Eight questions, about five minutes. It finds your starting point so you don't sit through things you already know.</p>
            <button class="btn btn-primary" id="retake-placement">Take the placement test</button>
          `}
        </div>

        <div class="card set-card">
          <h3>Curriculum forge</h3>
          <p>Your plan and the lessons written ahead of you. Auto-build is ${S.bank?.auto ? 'on' : 'off'}, keeping ${S.bank?.target ?? 4} lessons ready.</p>
          <div class="set-stats">
            <span><b>${S.bank?.built ?? 0}</b> written</span>
            <span><b>${S.bank?.ahead ?? 0}</b> ahead</span>
            <span><b>${S.bank?.items ?? 0}</b> activities</span>
          </div>
          <div style="margin-top:16px">
            <button class="btn btn-sm" id="goto-forge">${icon('wand', 13)} Open the forge</button>
          </div>
        </div>

        ${memoryCardHtml()}

        <div class="card set-card">
          <h3>Sign out</h3>
          <p>Your progress stays on this server.</p>
          <a class="btn btn-sm" href="?page=logout">Sign out</a>
        </div>
      </div>
    </div>`;

  wireSettings(view);
}

function wireSettings(view) {
  const providerSel = view.querySelector('#set-provider');
  const modelInput = view.querySelector('#set-model');
  const keyInput = view.querySelector('#set-key');
  const baseInput = view.querySelector('#set-base');
  const tempInput = view.querySelector('#set-temp');
  const tempLabel = view.querySelector('#temp-value');
  const styleSel = view.querySelector('#set-style');
  const timeoutSel = view.querySelector('#set-timeout');
  const noteEl = view.querySelector('#provider-note');
  const listEl = view.querySelector('#model-list');

  const applyProvider = () => {
    const p = S.providers[providerSel.value] || {};
    noteEl.textContent = p.note || '';
    listEl.innerHTML = (p.models || []).map((m) => `<option value="${esc(m)}"></option>`).join('');
    baseInput.placeholder = p.base || 'https://…/v1';
    if (!modelInput.value || !(p.models || []).includes(modelInput.value)) {
      if (p.models && p.models.length) modelInput.value = p.models[0];
    }
  };

  providerSel.addEventListener('change', applyProvider);

  tempInput.addEventListener('input', () => {
    tempLabel.textContent = Number(tempInput.value).toFixed(2);
  });

  const payload = () => ({
    provider: providerSel.value,
    model: modelInput.value.trim(),
    api_key: keyInput.value.trim(),
    base_url: baseInput.value.trim(),
    temperature: Number(tempInput.value),
    tutor_style: styleSel.value,
    timeout: Number(timeoutSel.value),
  });

  view.querySelector('#load-models')?.addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    busy(btn, true);
    try {
      const res = await api('api/settings.php', { action: 'models', ...payload() });
      if (!res.models.length) {
        toast('The provider returned no models.', 'warn');
      } else {
        listEl.innerHTML = res.models.map((m) => `<option value="${esc(m)}"></option>`).join('');
        toast(`${res.models.length} models available. Start typing in the model box.`, 'good');
      }
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      busy(btn, false);
    }
  });

  view.querySelector('#test-provider').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    const box = view.querySelector('#test-result');
    box.innerHTML = '';
    busy(btn, true, 'Testing');
    try {
      const res = await api('api/settings.php', { action: 'test', ...payload() });
      box.innerHTML = `<div class="test-result ok">Connected to <b>${esc(res.model)}</b> in ${res.ms} ms${res.reply ? ` — replied “${esc(res.reply)}”` : ''}</div>`;
    } catch (err) {
      box.innerHTML = `<div class="test-result bad">${esc(err.message)}</div>`;
    } finally {
      busy(btn, false);
    }
  });

  view.querySelector('#save-provider').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    busy(btn, true, 'Saving');
    try {
      const res = await api('api/settings.php', { action: 'save', ...payload() });
      S.user = res.user;
      keyInput.value = '';
      toast('Settings saved.', 'good');
      emit('state', res);
      openSettings();
    } catch (err) {
      toast(err.message, 'error');
      busy(btn, false);
    }
  });

  view.querySelector('#clear-key')?.addEventListener('click', async () => {
    try {
      const res = await api('api/settings.php', { action: 'clear_key' });
      S.user = res.user;
      toast('Key removed.', 'info');
      emit('state', res);
      openSettings();
    } catch (err) {
      toast(err.message, 'error');
    }
  });

  view.querySelector('#save-profile').addEventListener('click', async (e) => {
    const btn = e.currentTarget;
    busy(btn, true, 'Saving');
    try {
      const res = await api('api/settings.php', {
        action: 'profile',
        profile: {
          display: view.querySelector('#prof-display').value,
          goal: view.querySelector('#prof-goal').value,
          experience: view.querySelector('#prof-exp').value,
          time_per_week: view.querySelector('#prof-time').value,
          interests: view.querySelector('#prof-interests').value.split(',').map((x) => x.trim()).filter(Boolean),
          explained: view.querySelector('#prof-more').value,
        },
      });
      S.user = res.user;
      toast('Profile saved. Memory and your next lesson both updated.', 'good');
      emit('state', res);
      openSettings();
    } catch (err) {
      toast(err.message, 'error');
    } finally {
      busy(btn, false);
    }
  });

  view.querySelector('#retake-placement').addEventListener('click', () => openPlacement());
  view.querySelector('#goto-forge')?.addEventListener('click', () => emit('navigate', 'curriculum'));

  wireMemory(view);
}
