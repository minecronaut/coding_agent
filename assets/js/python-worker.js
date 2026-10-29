/**
 * Runs the learner's Python off the main thread so a runaway loop can be
 * killed by terminating this worker. Everything is client-side: no code ever
 * reaches the server.
 */

let pyodide = null;
let booted = false;

const BOOTSTRAP = String.raw`
import sys, types, json, builtins, traceback

_next_inputs = []
_turtle = None

class _CairnStream:
    def __init__(self, emit):
        self._emit = emit
        self._buf = ""
    def write(self, s):
        self._buf += str(s)
        while "\n" in self._buf:
            line, self._buf = self._buf.split("\n", 1)
            self._emit(line)
        return len(s)
    def flush(self):
        if self._buf:
            self._emit(self._buf)
            self._buf = ""
    def isatty(self):
        return False
    def readable(self):
        return False
    def writable(self):
        return True

def __cairn_install(out_emit, err_emit):
    sys.stdout = _CairnStream(out_emit)
    sys.stderr = _CairnStream(err_emit)

def __cairn_set_inputs(raw):
    global _next_inputs
    try:
        _next_inputs = [str(x) for x in json.loads(raw)] if raw else []
    except Exception:
        _next_inputs = []

def __cairn_input(prompt=""):
    global _next_inputs
    if _next_inputs:
        val = _next_inputs.pop(0)
        print(str(prompt) + val)
        return val
    raise EOFError(
        "This program asked for input but the Inputs box is empty. "
        "Add one answer per line under the editor, then run again."
    )

builtins.input = __cairn_input

def __cairn_install_turtle(src):
    global _turtle
    mod = types.ModuleType("turtle")
    mod.__dict__["__name__"] = "turtle"
    exec(compile(src, "<turtle>", "exec"), mod.__dict__)
    sys.modules["turtle"] = mod
    _turtle = mod
    return True

def _cairn_traceback():
    etype, evalue, tb = sys.exc_info()
    out = ["Traceback (most recent call last):"]
    for frame in traceback.extract_tb(tb):
        if frame.filename in ("<you>", "<turtle>"):
            out.append('  File "%s", line %s, in %s' % (frame.filename, frame.lineno, frame.name))
            if frame.line:
                out.append("    " + frame.line.strip())
    out.extend(x.rstrip("\n") for x in traceback.format_exception_only(etype, evalue))
    return "\n".join(out)

def __cairn_run(code, inputs_raw):
    global _turtle
    __cairn_set_inputs(inputs_raw)
    if _turtle is not None:
        _turtle._reset()
    err = None
    scope = {"__name__": "__main__", "__builtins__": builtins}
    try:
        exec(compile(code, "<you>", "exec"), scope)
    except SystemExit:
        pass
    except BaseException:
        err = _cairn_traceback()
    try:
        sys.stdout.flush()
        sys.stderr.flush()
    except Exception:
        pass
    ops = _turtle._take() if _turtle is not None else []
    # Did the learner touch turtle at all? "import turtle as t" binds the module
    # object, "from turtle import forward" binds a function whose __module__ is
    # "turtle". The text check catches imports inside functions and comments.
    used_turtle = "turtle" in code
    if not used_turtle:
        for value in scope.values():
            if value is _turtle or getattr(value, "__module__", "") == "turtle":
                used_turtle = True
                break
    # The shim records bookkeeping ops (background, cursor) on every run, so a
    # plain print() would otherwise look like a drawing. Anything that mentions
    # turtle keeps the canvas; anything else only shows it if it actually drew.
    if not used_turtle and not any(op.get("k") in ("move", "dot", "fill", "text") for op in ops):
        ops = []
    return json.dumps({"error": err, "used_turtle": used_turtle, "ops": ops})
`;

function post(msg) {
  self.postMessage(msg);
}

async function init({ indexURL, shimUrl }) {
  if (booted) {
    post({ type: 'ready' });
    return;
  }

  const mod = await import(indexURL + 'pyodide.mjs');
  pyodide = await mod.loadPyodide({ indexURL });

  await pyodide.runPythonAsync(BOOTSTRAP);

  pyodide.globals.set('__cairn_emit_out', (line) => post({ type: 'stdout', text: line }));
  pyodide.globals.set('__cairn_emit_err', (line) => post({ type: 'stderr', text: line }));
  await pyodide.runPythonAsync('__cairn_install(__cairn_emit_out, __cairn_emit_err)');

  // Revalidate rather than force-cache: the shim changes with the app, and a
  // stale copy would silently break new turtle features after a deploy.
  const shim = await (await fetch(shimUrl, { cache: 'no-cache' })).text();
  pyodide.globals.set('__cairn_shim', shim);
  await pyodide.runPythonAsync('__cairn_install_turtle(__cairn_shim)');

  booted = true;
  post({ type: 'ready', version: pyodide.version });
}

async function run({ code, inputs }) {
  if (!booted) {
    post({ type: 'error', message: 'Python is still starting up. Give it a second and run again.' });
    return;
  }
  const started = performance.now();

  pyodide.globals.set('__cairn_code', code);
  pyodide.globals.set('__cairn_inputs', JSON.stringify(inputs || []));

  let raw;
  try {
    raw = pyodide.runPython('__cairn_run(__cairn_code, __cairn_inputs)');
  } catch (err) {
    post({
      type: 'error',
      message: 'The runtime hit an internal error: ' + (err && err.message ? err.message : String(err)),
    });
    return;
  }

  let parsed;
  try {
    parsed = JSON.parse(String(raw));
  } catch {
    post({ type: 'error', message: 'Could not read the runtime output.' });
    return;
  }

  post({
    type: 'done',
    error: parsed.error || null,
    usedTurtle: Boolean(parsed.used_turtle) || (parsed.ops || []).length > 0,
    ops: parsed.ops || [],
    ms: Math.round(performance.now() - started),
  });
}

self.onmessage = async (event) => {
  const msg = event.data || {};
  try {
    if (msg.type === 'init') {
      await init(msg);
    } else if (msg.type === 'run') {
      await run(msg);
    }
  } catch (err) {
    post({
      type: 'fatal',
      message: err && err.message ? err.message : String(err),
    });
  }
};
