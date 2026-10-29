import { S, emit } from './state.js';
import { esc, icon, toast } from './ui.js';
import { createEditor, disposeAllEditors } from './editor.js';
import { runPython, TurtleStage } from './runner.js';

let editor = null;
let stage = null;
let running = false;

const DEFAULT_CODE = `# Playground — anything you type here runs in your browser.
# Nothing is sent to a server.

for i in range(1, 6):
    print("step", i, "of 5")


# Try a drawing:
# import turtle
# for _ in range(4):
#     turtle.forward(100)
#     turtle.right(90)
`;

export function openPlayground() {
  const view = document.getElementById('view');
  view.innerHTML = `
    <div class="panel flush">
      <div class="wb-head">
        <h2>Playground</h2>
        <span class="chip accent">Python 3 · runs locally</span>
        <span class="spacer"></span>
        <select class="speed-select" id="pg-speed">
          <option value="normal">Normal</option>
          <option value="slow">Slow</option>
          <option value="instant">Instant</option>
        </select>
        <button class="btn btn-sm btn-primary" id="pg-run">${icon('play', 12)} Run</button>
      </div>
      <div class="wb">
        <div class="wb-left">
          <div id="pg-editor"></div>
          <div class="inputs-strip">
            <label>Inputs</label>
            <input type="text" id="pg-inputs" placeholder="One answer per line — used by input()">
          </div>
        </div>
        <div class="wb-right">
          <div class="wb-side-head">
            Output
            <span class="spacer"></span>
            <button class="icon-btn" id="pg-clear" title="Clear output">${icon('refresh', 14)}</button>
          </div>
          <div id="pg-turtle-wrap" class="hidden"><canvas id="pg-turtle"></canvas></div>
          <div class="console" id="pg-console"><span class="meta">Press Run to execute your code.</span></div>
        </div>
      </div>
    </div>`;

  const runBtn = view.querySelector('#pg-run');
  const consoleEl = view.querySelector('#pg-console');
  const turtleWrap = view.querySelector('#pg-turtle-wrap');
  const speedSel = view.querySelector('#pg-speed');
  const inputsEl = view.querySelector('#pg-inputs');

  const saved = localStorage.getItem('cairn.playground');

  createEditor(view.querySelector('#pg-editor'), {
    value: saved || DEFAULT_CODE,
    onChange: (text) => {
      clearTimeout(window.__pgSave);
      window.__pgSave = setTimeout(() => {
        try { localStorage.setItem('cairn.playground', text); } catch { /* ignore */ }
      }, 400);
    },
  }).then((handle) => {
    editor = handle;
    handle.focus();
  }).catch((err) => {
    toast(err.message, 'error');
  });

  stage = new TurtleStage(view.querySelector('#pg-turtle'));

  view.querySelector('#pg-clear').addEventListener('click', () => {
    consoleEl.innerHTML = '';
    turtleWrap.classList.add('hidden');
    stage.reset();
  });

  const print = (text, cls = '') => {
    const line = document.createElement('div');
    if (cls) line.className = cls;
    line.textContent = text;
    consoleEl.appendChild(line);
    consoleEl.scrollTop = consoleEl.scrollHeight;
  };

  runBtn.addEventListener('click', async () => {
    if (running || !editor) return;
    running = true;
    runBtn.disabled = true;
    consoleEl.innerHTML = '';
    turtleWrap.classList.add('hidden');
    stage.reset();

    const code = editor.get();
    S.contextCode = code;
    const inputs = (inputsEl.value || '').split('\n').filter((s) => s !== '');
    let out = '';

    try {
      const res = await runPython(code, inputs, {
        onStdout: (line) => { out += line + '\n'; print(line); },
        onStderr: (line) => { out += line + '\n'; print(line, 'err'); },
        timeout: Number(S.user?.settings?.timeout || 10),
      });
      S.contextOutput = out;

      if (res.usedTurtle || (res.ops && res.ops.length)) {
        turtleWrap.classList.remove('hidden');
        stage.play(res.ops || [], speedSel.value);
      }
      if (res.error) {
        print(res.error, 'err');
      } else if (!out.trim()) {
        print('(no output)', 'meta');
      }
      print(`— finished in ${res.ms} ms`, 'meta');
    } catch (err) {
      print(err.message, 'err');
      S.contextOutput = err.message;
    } finally {
      running = false;
      runBtn.disabled = false;
    }
  });
}

export function disposePlayground() {
  if (editor) {
    try { editor.dispose(); } catch { /* ignore */ }
    editor = null;
  }
  if (stage) {
    stage.destroy();
    stage = null;
  }
  disposeAllEditors();
}

export { DEFAULT_CODE };
