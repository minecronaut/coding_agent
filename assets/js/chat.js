import { S, emit } from './state.js';
import { streamChat } from './api.js';
import { esc, icon, toast } from './ui.js';
import { md } from './md.js';
import { extractMemories, openMemory, memoryCount } from './memory.js';

let logEl = null;
let pending = null;
let raf = null;
let exchanges = 0;

function storageKey() {
  return `cairn.chat.${S.user?.id || 'anon'}`;
}

export function loadChat() {
  try {
    const raw = localStorage.getItem(storageKey());
    if (raw) {
      const parsed = JSON.parse(raw);
      if (Array.isArray(parsed)) S.chat = parsed.slice(-40);
    }
  } catch { /* ignore */ }
}

function saveChat() {
  try {
    localStorage.setItem(storageKey(), JSON.stringify(S.chat.slice(-40)));
  } catch { /* ignore */ }
}

const SUGGESTIONS = [
  'Explain what a variable actually is, in plain English.',
  'My loop never ends. How do I debug it?',
  'Give me a harder exercise on this topic.',
  'Why does this print 7 instead of "7"?',
  'What should I build next?',
];

export function openChat() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="panel flush">
      <div class="wb-head">
        <h2>Your tutor</h2>
        <span class="chip" style="margin-left:6px" id="chat-ctx"></span>
        <span class="spacer"></span>
        <button class="btn btn-ghost btn-sm" id="chat-memory" title="What the tutor remembers about you">${icon('book', 14)} <span id="chat-mem-count">${memoryCount()}</span></button>
        <button class="btn btn-ghost btn-sm" id="chat-clear">Clear</button>
      </div>
      <div class="chat-wrap">
        <div class="chat-log" id="chat-log"></div>
        <div class="composer">
          <div class="composer-box">
            <textarea id="chat-input" rows="1" placeholder="Ask anything about Python…"></textarea>
            <button class="btn btn-primary" id="chat-send" title="Send (Enter)">${icon('arrow', 16)}</button>
          </div>
          <div style="font-size:11.5px;color:var(--dim);margin-top:7px;padding-left:4px">
            Enter sends · Shift+Enter for a new line · the tutor can see your current lesson
          </div>
        </div>
      </div>
    </div>`;

  logEl = view.querySelector('#chat-log');
  const input = view.querySelector('#chat-input');
  const sendBtn = view.querySelector('#chat-send');

  const ctx = view.querySelector('#chat-ctx');
  if (S.lessonMeta?.title) {
    ctx.textContent = `context: ${S.lessonMeta.title}`;
    ctx.classList.add('accent');
  } else {
    ctx.textContent = 'no lesson open';
  }

  renderLog();

  const autoGrow = () => {
    input.style.height = 'auto';
    input.style.height = Math.min(160, input.scrollHeight) + 'px';
  };

  input.addEventListener('input', autoGrow);
  input.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      send(input.value);
      input.value = '';
      autoGrow();
    }
  });
  sendBtn.addEventListener('click', () => {
    send(input.value);
    input.value = '';
    autoGrow();
  });

  view.querySelector('#chat-clear').addEventListener('click', () => {
    S.chat = [];
    saveChat();
    renderLog();
    toast('Chat cleared.', 'info');
  });

  view.querySelector('#chat-memory').addEventListener('click', () => openMemory());

  setTimeout(() => input.focus(), 50);
}

function renderLog() {
  if (!logEl) return;

  if (!S.chat.length) {
    logEl.innerHTML = `
      <div class="chat-empty">
        <div style="width:46px;height:46px;border-radius:14px;margin:0 auto;display:grid;place-items:center;background:linear-gradient(145deg,rgba(94,234,212,.2),rgba(139,157,255,.16));color:var(--accent)">
          ${icon('chat', 22)}
        </div>
        <h3>Ask me anything</h3>
        <p>I know what you've covered, what you're stuck on, and what you're building toward. Start anywhere.</p>
        <div class="suggestions">
          ${SUGGESTIONS.map((s) => `<button class="suggestion">${esc(s)}</button>`).join('')}
        </div>
      </div>`;
    logEl.querySelectorAll('.suggestion').forEach((btn) => {
      btn.addEventListener('click', () => send(btn.textContent));
    });
    return;
  }

  logEl.innerHTML = S.chat.map((m, i) => messageHtml(m, i)).join('');
  scrollToBottom();
}

function messageHtml(m, index) {
  if (m.role === 'user') {
    return `
      <div class="msg me">
        <div class="who-icon">${esc((S.user?.display || 'Y').slice(0, 1).toUpperCase())}</div>
        <div class="bubble">${esc(m.content).replace(/\n/g, '<br>')}</div>
      </div>`;
  }
  return `
    <div class="msg ai" data-ai="${index}">
      <div class="who-icon">${icon('spark', 14)}</div>
      <div class="bubble md">${md(m.content)}</div>
    </div>`;
}

function scrollToBottom(force = false) {
  if (!logEl) return;
  const near = logEl.scrollHeight - logEl.scrollTop - logEl.clientHeight < 160;
  if (near || force) logEl.scrollTop = logEl.scrollHeight;
}

async function send(text) {
  const content = String(text || '').trim();
  if (!content) return;
  if (S.chatBusy) {
    toast('Wait for the current reply to finish.', 'warn');
    return;
  }

  S.chat.push({ role: 'user', content });
  saveChat();
  renderLog();

  S.chatBusy = true;
  const holder = document.createElement('div');
  holder.className = 'msg ai';
  holder.innerHTML = `<div class="who-icon">${icon('spark', 14)}</div><div class="bubble md"><span class="typing"><i></i><i></i><i></i></span></div>`;
  logEl.appendChild(holder);
  scrollToBottom(true);

  const bubble = holder.querySelector('.bubble');
  let acc = '';
  let dirty = false;

  const paint = () => {
    if (!dirty) return;
    dirty = false;
    bubble.innerHTML = md(acc);
    scrollToBottom();
  };

  try {
    await streamChat(
      {
        messages: S.chat.map((m) => ({ role: m.role, content: m.content })),
        context: {
          lessonTitle: S.lesson?.title || '',
          skillTitle: S.lessonMeta?.title || '',
          code: S.contextCode || '',
          output: S.contextOutput || '',
        },
      },
      (delta) => {
        acc += delta;
        dirty = true;
        if (!raf) {
          raf = requestAnimationFrame(() => {
            raf = null;
            paint();
          });
        }
      }
    );

    if (!acc.trim()) {
      throw new Error('The tutor returned nothing.');
    }
    S.chat.push({ role: 'assistant', content: acc });
    saveChat();
    rememberAfterExchange();
  } catch (err) {
    bubble.innerHTML = `<p style="color:var(--bad)">${esc(err.message)}</p>`;
    S.chat.pop();
    toast(err.message, 'error');
  } finally {
    S.chatBusy = false;
    pending = null;
    dirty = true;
    paint();
    scrollToBottom();
  }
}

/**
 * Every other exchange, let the memory pass read the conversation. It runs
 * after the reply is already on screen, so it never adds to the wait.
 */
function rememberAfterExchange() {
  exchanges += 1;
  if (exchanges % 2 !== 0) return;

  extractMemories(S.chat.slice(-8)).then((res) => {
    if (!res || res.error) return;
    if (res.added?.length) {
      toast(`Noted ${res.added.length} new thing${res.added.length === 1 ? '' : 's'} about you.`, 'info', 2600);
    }
    const count = document.getElementById('chat-mem-count');
    if (count) count.textContent = memoryCount();
  }).catch(() => { /* memory is best-effort */ });
}
