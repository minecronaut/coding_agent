/** Monaco bootstrap + a small factory used by the playground and lesson blocks. */

let readyPromise = null;
let themeDefined = false;

function config() {
  return window.CAIRN_CFG || {};
}

export function loadMonaco() {
  if (readyPromise) return readyPromise;

  readyPromise = new Promise((resolve, reject) => {
    const finish = () => {
      if (!window.monaco) {
        reject(new Error('The editor failed to load.'));
        return;
      }
      if (!themeDefined) {
        window.monaco.editor.defineTheme('cairn', {
          base: 'vs-dark',
          inherit: true,
          rules: [
            { token: 'comment', foreground: '5f7285', fontStyle: 'italic' },
            { token: 'keyword', foreground: '8b9dff' },
            { token: 'string', foreground: '7ce0c8' },
            { token: 'number', foreground: 'ffb86b' },
            { token: 'delimiter', foreground: '9aa8bd' },
            { token: 'function', foreground: 'e9edf5' },
            { token: 'type', foreground: '5eead4' },
          ],
          colors: {
            'editor.background': '#0a0d12',
            'editor.foreground': '#d7e0ec',
            'editorLineNumber.foreground': '#37424f',
            'editorLineNumber.activeForeground': '#7d8b9e',
            'editor.lineHighlightBackground': '#12171f',
            'editor.selectionBackground': '#1d3a42',
            'editorCursor.foreground': '#5eead4',
            'editorIndentGuide.background1': '#1b222c',
            'editorGutter.background': '#0a0d12',
            'scrollbarSlider.background': '#232c3a66',
            'scrollbarSlider.hoverBackground': '#2e394b88',
          },
        });
        themeDefined = true;
      }
      resolve(window.monaco);
    };

    if (window.monaco) {
      finish();
      return;
    }

    const loader = document.createElement('script');
    loader.src = config().monacoUrl + '/loader.js';
    loader.onload = () => {
      window.require.config({ paths: { vs: config().monacoUrl } });
      window.require(['vs/editor/editor.main'], finish, () => reject(new Error('Monaco failed to load.')));
    };
    loader.onerror = () => reject(new Error('Could not download the code editor.'));
    document.head.appendChild(loader);

    setTimeout(() => {
      if (!window.monaco) reject(new Error('The code editor is taking too long to load.'));
    }, 30000);
  });

  return readyPromise;
}

const instances = new Set();

export async function createEditor(host, { value = '', language = 'python', onChange, readOnly = false } = {}) {
  const monaco = await loadMonaco();

  const model = monaco.editor.createModel(value, language);
  const editor = monaco.editor.create(host, {
    model,
    theme: 'cairn',
    readOnly,
    automaticLayout: true,
    fontSize: 13,
    lineHeight: 21,
    fontFamily: 'ui-monospace, "SF Mono", Menlo, Consolas, monospace',
    fontLigatures: false,
    minimap: { enabled: false },
    scrollBeyondLastLine: false,
    padding: { top: 12, bottom: 12 },
    renderLineHighlight: 'line',
    tabSize: 4,
    insertSpaces: true,
    wordWrap: 'on',
    scrollbar: { verticalScrollbarSize: 8, horizontalScrollbarSize: 8, alwaysConsumeMouseWheel: false },
    overviewRulerLanes: 0,
    lineNumbersMinChars: 3,
    glyphMargin: false,
    folding: false,
    contextmenu: false,
    quickSuggestions: false,
    suggestOnTriggerCharacters: false,
    occurrencesHighlight: 'off',
    selectionHighlight: false,
    guides: { indentation: false },
  });

  if (onChange) {
    editor.onDidChangeModelContent(() => onChange(editor.getValue()));
  }

  const handle = {
    editor,
    get: () => editor.getValue(),
    set: (text) => {
      if (editor.getValue() !== text) editor.setValue(text);
    },
    dispose: () => {
      instances.delete(handle);
      editor.dispose();
      model.dispose();
    },
    focus: () => editor.focus(),
  };

  instances.add(handle);
  return handle;
}

export function disposeAllEditors() {
  for (const h of Array.from(instances)) {
    try {
      h.dispose();
    } catch { /* ignore */ }
  }
  instances.clear();
}

/** Insert a line at the cursor, used by "insert snippet" affordances. */
export function insertAtEnd(handle, text) {
  const model = handle.editor.getModel();
  if (!model) return;
  const line = model.getLineCount();
  handle.editor.executeEdits('cairn', [
    {
      range: { startLineNumber: line, startColumn: model.getLineMaxColumn(line), endLineNumber: line, endColumn: model.getLineMaxColumn(line) },
      text: '\n' + text,
    },
  ]);
  handle.editor.revealLine(line + 1);
}
