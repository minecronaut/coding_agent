import { S, emit, isConfigured } from './state.js';
import { api } from './api.js';
import { toast } from './ui.js';

/**
 * The curriculum forge's engine.
 *
 * Runs quietly in the background: makes sure the learner has a structured
 * plan, then writes one lesson at a time until the bank is `target` lessons
 * ahead. It pauses when the tab is hidden and backs off when the model
 * complains, so a cheap model on a laptop does not hammer anybody's API.
 */

const FAST = 900;
const SLOW = 16000;

let started = false;
let timer = null;
let inFlight = false;
let failures = 0;
let lastError = '';
let warned = false;
let plannedThisSession = false;
let lastBuildAt = 0;
let extendPauseUntil = 0;
let extending = false;

export function builderStatus() {
  return {
    running: started,
    busy: inFlight,
    failures,
    error: lastError,
    lastBuildAt,
  };
}

export function startBuilder() {
  if (started) return;
  started = true;
  schedule(1500);
}

export function nudgeBuilder() {
  failures = 0;
  warned = false;
  extendPauseUntil = 0;
  schedule(150);
}

function schedule(ms) {
  if (!started) return;
  clearTimeout(timer);
  timer = setTimeout(tick, ms);
}

async function tick() {
  if (!started) return;
  if (inFlight) {
    schedule(FAST);
    return;
  }

  const bank = S.bank || {};

  if (!isConfigured()) {
    schedule(SLOW);
    return;
  }

  if (!bank.plan_ready) {
    if (plannedThisSession) {
      schedule(SLOW);
      return;
    }
    plannedThisSession = true;
    if (!(await requestPlan())) {
      plannedThisSession = false;
    }
    schedule(FAST);
    return;
  }

  if (!bank.auto) {
    schedule(SLOW);
    return;
  }

  if (document.hidden) {
    // Nobody is watching; save the tokens until they come back.
    schedule(5000);
    return;
  }

  // The core path is finished. Ask for the next advanced chapter before
  // worrying about the bank — it has nothing to build until that exists.
  if (S.mastery?.can_extend && Date.now() > extendPauseUntil) {
    const ok = await extendMastery(false);
    if (!ok) extendPauseUntil = Date.now() + 2 * 60 * 1000;
    schedule(ok ? FAST : SLOW);
    return;
  }

  const ahead = Number(bank.ahead || 0);
  const target = Number(bank.target ?? 4);
  if (ahead >= target) {
    schedule(SLOW);
    return;
  }

  await buildOne();
  schedule(failures > 0 ? Math.min(60000, 4000 * failures) : FAST);
}

async function requestPlan() {
  inFlight = true;
  try {
    const data = await api('api/curriculum.php', { action: 'plan' });
    emit('state', data);
    lastError = '';
    return true;
  } catch (err) {
    lastError = err.message;
    noteFailure(err.message);
    return false;
  } finally {
    inFlight = false;
  }
}

async function buildOne() {
  inFlight = true;
  try {
    const data = await api('api/curriculum.php', { action: 'pregen', count: 1 });
    if (data.state) emit('state', data.state);
    if (data.error) {
      lastError = data.error;
      noteFailure(data.error);
    } else {
      failures = 0;
      warned = false;
      lastError = '';
      if (data.built?.length) {
        lastBuildAt = Date.now();
        emit('bank-built', data.built[0]);
      }
    }
    if (data.busy) {
      // Another tab or the CLI holds the writer lock. Wait our turn.
      schedule(6000);
    }
  } catch (err) {
    noteFailure(err.message);
  } finally {
    inFlight = false;
  }
}

function noteFailure(message) {
  failures++;
  lastError = message || 'The forge stalled.';
  if (!warned) {
    warned = true;
    toast(`Curriculum forge paused: ${lastError}`, 'warn', 6000);
  }
  emit('bank-error', lastError);
}

/** Ask the model to write the next mastery chapter (core must be finished). */
export async function extendNow(force = false) {
  const data = await api('api/curriculum.php', { action: 'extend', force });
  if (data.state) emit('state', data.state);
  return data;
}

/** Background variant: never throws, and never runs twice at once. */
async function extendMastery(force) {
  if (extending) return false;
  extending = true;
  inFlight = true;
  try {
    const data = await extendNow(force);
    if (data.added) {
      failures = 0;
      warned = false;
      lastError = '';
      toast(`New mastery chapter: ${data.chapter?.title || 'advanced Python'}`, 'good', 6000);
      emit('mastery-extended', data.chapter);
      return true;
    }
    // Another tab owns the writer lock — let the pregen tick wait its turn.
    if (data.busy) return true;
    return false;
  } catch (err) {
    noteFailure(err.message);
    return false;
  } finally {
    extending = false;
    inFlight = false;
  }
}

/** Forge a lesson right now, ignoring the "already ahead" rule. */
export async function buildNow(count = 3) {
  if (!isConfigured()) {
    toast('Connect a model first.', 'warn');
    return { built: [], error: 'No model configured.' };
  }
  const data = await api('api/curriculum.php', { action: 'pregen', count });
  if (data.state) emit('state', data.state);
  if (data.error) {
    lastError = data.error;
    emit('bank-error', data.error);
  }
  return data;
}

/** Rebuild the plan (used by the "Redesign" button). */
export async function replan() {
  const data = await api('api/curriculum.php', { action: 'replan' });
  emit('state', data);
  plannedThisSession = true;
  nudgeBuilder();
  return data;
}
