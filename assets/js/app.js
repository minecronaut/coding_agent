import { S, applyState, on, isConfigured, displayName } from './state.js';
import { api } from './api.js';
import { esc, icon, toast } from './ui.js';
import { openJourney } from './journey.js';
import { openLesson, openModule, disposeLesson } from './lesson.js';
import { openChat, loadChat } from './chat.js';
import { openProjects } from './projects.js';
import { openSettings } from './settings.js';
import { openPlayground, disposePlayground } from './playground.js';
import { openCurriculum } from './curriculum.js';
import { startBuilder } from './builder.js';

function markNav(name) {
  document.querySelectorAll('.nav-item').forEach((item) => {
    item.classList.toggle('active', item.dataset.view === name);
  });
}

export function go(name) {
  if (name !== 'lesson') disposeLesson();
  if (name !== 'playground') disposePlayground();

  S.view = name;
  const view = document.getElementById('view');
  if (!view) return;
  view.innerHTML = '';
  markNav(name === 'lesson' ? 'journey' : name);

  switch (name) {
    case 'journey': openJourney(); break;
    case 'curriculum': openCurriculum(); break;
    case 'playground': openPlayground(); break;
    case 'chat': openChat(); break;
    case 'projects': openProjects(); break;
    case 'settings': openSettings(); break;
    default: openJourney();
  }
  window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
}

export async function openSkill(skillId) {
  if (!isConfigured()) {
    toast('Connect a model first — lessons are written by it.', 'warn');
    go('settings');
    return;
  }
  disposePlayground();
  disposeLesson();
  S.view = 'lesson';
  markNav('journey');
  window.scrollTo(0, 0);
  await openLesson(skillId);
}

export async function openModuleLesson(moduleId) {
  if (!isConfigured()) {
    toast('Connect a model first.', 'warn');
    go('settings');
    return;
  }
  disposePlayground();
  disposeLesson();
  S.view = 'lesson';
  markNav('curriculum');
  window.scrollTo(0, 0);
  await openModule(moduleId);
}

export async function refreshState() {
  try {
    const data = await api('api/state.php');
    applyState(data);
    updateSidebar();
    return data;
  } catch (err) {
    toast(err.message, 'error');
    return null;
  }
}

function updateSidebar() {
  const p = S.progress || {};
  const bar = document.getElementById('side-bar');
  const count = document.getElementById('side-count');
  const badge = document.getElementById('nav-badge');
  const who = document.getElementById('side-name');
  const avatar = document.getElementById('side-avatar');
  const bankEl = document.getElementById('side-bank');
  const navBank = document.getElementById('nav-bank');

  const mastered = Boolean(p.mastered) || Boolean(S.mastery?.declared);
  if (bar) bar.style.width = `${mastered ? 100 : (p.percent || 0)}%`;
  if (count) count.textContent = `${p.finished || 0}/${p.total || 0}`;
  if (badge) badge.textContent = mastered ? 'Mastered' : `${p.percent || 0}%`;
  if (who) who.textContent = displayName();
  if (avatar) avatar.textContent = (displayName() || '?').slice(0, 1).toUpperCase();

  const b = S.bank;
  if (b) {
    if (bankEl) bankEl.textContent = `${b.ahead || 0}/${b.target ?? 0}`;
    if (navBank) navBank.textContent = `${b.built || 0} built`;
  }
}

function boot() {
  const payload = window.__CAIRN__ || {};
  applyState(payload.state || {});
  loadChat();

  document.querySelectorAll('.nav-item').forEach((item) => {
    item.addEventListener('click', () => go(item.dataset.view));
  });

  on('navigate', (e) => go(e.detail));
  on('open-skill', (e) => openSkill(e.detail));
  on('open-module', (e) => openModuleLesson(e.detail));
  on('state', (e) => {
    applyState(e.detail);
    updateSidebar();
  });

  updateSidebar();
  go('journey');
  startBuilder();
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}

/* Global error guard: never leave the learner staring at a blank panel. */
window.addEventListener('unhandledrejection', (e) => {
  const message = e.reason?.message || 'Something went wrong.';
  if (message) toast(message, 'error');
});
