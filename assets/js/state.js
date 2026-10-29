/** Shared client state. Kept in one module so panels can import it without cycles. */

export const S = {
  csrf: '',
  user: null,
  progress: null,
  mastery: null,
  roadmap: null,
  providers: {},
  cached: new Set(),
  projects: [],
  syllabus: {},
  plan: null,
  bank: null,
  modules: [],
  checkpoints: {},
  view: 'journey',
  lesson: null,
  lessonSkill: null,
  lessonMeta: null,
  lessonAnswers: {},
  lessonDone: false,
  chat: [],
  chatBusy: false,
  activeStage: null,
  saveTimer: null,
};

export function applyState(data) {
  if (data.csrf) S.csrf = data.csrf;
  if (data.user) S.user = data.user;
  if (data.progress) S.progress = data.progress;
  if (data.mastery) S.mastery = data.mastery;
  if (data.roadmap) S.roadmap = data.roadmap;
  if (data.providers) S.providers = data.providers;
  if (data.syllabus) S.syllabus = data.syllabus;
  if (data.projects) S.projects = data.projects;
  if (Array.isArray(data.cached)) S.cached = new Set(data.cached);
  if (data.plan !== undefined) S.plan = data.plan;
  if (data.bank) S.bank = data.bank;
  if (data.modules) S.modules = data.modules;
  if (data.checkpoints) S.checkpoints = data.checkpoints;
}

export function displayName() {
  return S.user?.display || S.user?.username || 'there';
}

export function isConfigured() {
  return Boolean(S.user?.settings?.has_key) || S.user?.settings?.provider === 'ollama';
}

/** Tiny app-wide event bus so panels don't need to import app.js. */
export const bus = new EventTarget();

export function emit(type, detail = {}) {
  bus.dispatchEvent(new CustomEvent(type, { detail }));
}

export function on(type, handler) {
  bus.addEventListener(type, handler);
}
