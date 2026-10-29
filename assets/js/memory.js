import { S, applyState, emit, isConfigured } from './state.js';
import { api } from './api.js';
import { esc, icon, toast, openModal } from './ui.js';

/**
 * The tutor's long-term memory.
 *
 * Facts are written by lessons, grading, project reviews and a quiet chat
 * pass. This module is only the interface for looking at them, pinning the
 * ones that matter, and forgetting the ones that are wrong.
 */

export const MEMORY_KINDS = [
  { id: 'goal', label: 'Goal' },
  { id: 'preference', label: 'Preference' },
  { id: 'interest', label: 'Interest' },
  { id: 'struggle', label: 'Struggle' },
  { id: 'strength', label: 'Strength' },
  { id: 'context', label: 'Context' },
];

const KIND_LABEL = Object.fromEntries(MEMORY_KINDS.map((k) => [k.id, k.label]));

function facts() {
  return Array.isArray(S.user?.memory?.facts) ? S.user.memory.facts : [];
}

function factRow(f) {
  const kind = KIND_LABEL[f.kind] ? f.kind : 'context';
  return `
    <div class="mem-row ${f.pinned ? 'pinned' : ''}" data-fact="${esc(f.id)}">
      <span class="mem-kind ${esc(kind)}">${esc(KIND_LABEL[kind] || 'Context')}</span>
      <span class="mem-text">${esc(f.text)}</span>
      <span class="mem-acts">
        <button class="icon-btn" data-pin="${esc(f.id)}" title="${f.pinned ? 'Unpin' : 'Pin this fact'}" aria-label="${f.pinned ? 'Unpin' : 'Pin'}">${icon('pin', 14)}</button>
        <button class="icon-btn" data-forget="${esc(f.id)}" title="Forget this" aria-label="Forget this">${icon('trash', 14)}</button>
      </span>
    </div>`;
}

function inner() {
  const list = facts();
  return `
    <div class="mem-head">
      <h3>${icon('book', 16)} What Cairn remembers</h3>
      <span class="chip" data-mem-count>${list.length} ${list.length === 1 ? 'fact' : 'facts'}</span>
    </div>
    <p class="mem-sub">Durable things the tutor has learned about you — goals, taste, habits, sticking points. It writes them as you learn. Pin anything that matters; forget anything wrong.</p>
    <div class="mem-list" data-mem-list>
      ${list.length
        ? list.map(factRow).join('')
        : `<div class="mem-empty">Nothing yet. Finish a lesson, chat for a bit, or add something below.</div>`}
    </div>
    <form class="mem-add" data-mem-form>
      <select data-mem-kind aria-label="Kind of fact">
        ${MEMORY_KINDS.map((k) => `<option value="${k.id}">${k.label}</option>`).join('')}
      </select>
      <input type="text" data-mem-text maxlength="300" placeholder="Something the tutor should remember…" aria-label="New fact">
      <button class="btn btn-sm" type="submit">${icon('plus', 13)} Add</button>
    </form>`;
}

/** Full card for Settings. */
export function memoryCardHtml() {
  return `<div class="card set-card memory-card" data-memory>${inner()}</div>`;
}

/** Compact block for the chat sidebar / modal. */
export function memoryPanelHtml() {
  return `<div class="memory-card bare" data-memory>${inner()}</div>`;
}

export function memoryCount() {
  return facts().length;
}

function paint() {
  document.querySelectorAll('[data-memory]').forEach((card) => {
    const list = card.querySelector('[data-mem-list]');
    const count = card.querySelector('[data-mem-count]');
    const data = facts();
    if (list) {
      list.innerHTML = data.length
        ? data.map(factRow).join('')
        : `<div class="mem-empty">Nothing yet. Finish a lesson, chat for a bit, or add something below.</div>`;
    }
    if (count) count.textContent = `${data.length} ${data.length === 1 ? 'fact' : 'facts'}`;
  });
}

/** Delegate once per card so repainting the list never loses handlers. */
export function wireMemory(scope = document) {
  scope.querySelectorAll('[data-memory]').forEach((card) => {
    if (card.dataset.memWired === '1') return;
    card.dataset.memWired = '1';

    card.addEventListener('click', async (e) => {
      const forget = e.target.closest('[data-forget]');
      const pin = e.target.closest('[data-pin]');
      if (!forget && !pin) return;

      const id = (forget || pin).getAttribute(forget ? 'data-forget' : 'data-pin');
      const fact = facts().find((f) => f.id === id);
      try {
        if (forget) {
          const res = await api('api/memory.php', { action: 'delete', id });
          applyState(res);
          emit('state', res);
          paint();
          toast('Forgotten.', 'info', 2200);
        } else {
          const res = await api('api/memory.php', {
            action: 'update',
            id,
            pinned: !(fact?.pinned),
          });
          applyState(res);
          emit('state', res);
          paint();
          toast(fact?.pinned ? 'Unpinned.' : 'Pinned to the top.', 'good', 2200);
        }
      } catch (err) {
        toast(err.message, 'error');
      }
    });

    card.addEventListener('submit', async (e) => {
      const form = e.target.closest('[data-mem-form]');
      if (!form) return;
      e.preventDefault();
      const text = form.querySelector('[data-mem-text]');
      const kind = form.querySelector('[data-mem-kind]');
      const value = text.value.trim();
      if (!value) {
        text.focus();
        return;
      }
      const btn = form.querySelector('button[type="submit"]');
      btn.disabled = true;
      try {
        const res = await api('api/memory.php', { action: 'add', text: value, kind: kind.value });
        applyState(res);
        emit('state', res);
        text.value = '';
        paint();
        toast('Added to memory.', 'good', 2200);
      } catch (err) {
        toast(err.message, 'error');
      } finally {
        btn.disabled = false;
      }
    });
  });
}

/** Open the same list in a modal, from the chat header. */
export function openMemory() {
  const { body } = openModal({
    title: 'Tutor memory',
    wide: true,
    body: memoryPanelHtml(),
  });
  wireMemory(body);
}

let extracting = false;

/**
 * Let the model decide what is worth keeping from a short exchange.
 * Deliberately quiet: no UI state, no error toast unless asked for.
 *
 * @returns {Promise<{added:array,removed:array,error:string}|null>}
 */
export async function extractMemories(history) {
  if (extracting || !isConfigured()) return null;

  const clean = (Array.isArray(history) ? history : [])
    .filter((m) => m && typeof m.content === 'string' && m.content.trim())
    .slice(-8)
    .map((m) => ({
      role: m.role === 'assistant' ? 'assistant' : 'user',
      content: m.content.slice(0, 1500),
    }));

  if (clean.length < 2) return null;

  extracting = true;
  try {
    const res = await api('api/memory.php', { action: 'extract', history: clean });
    if (res.user) {
      applyState(res);
      emit('state', { user: res.user });
    }
    paint();
    return res;
  } catch (err) {
    return { added: [], removed: [], error: err.message };
  } finally {
    extracting = false;
  }
}
