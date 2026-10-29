/**
 * Main-thread side of the Python runtime: one worker, reusable, killable.
 * Also contains the canvas renderer that replays `turtle` draw commands.
 */

let worker = null;
let readyPromise = null;
let activeTimeout = null;

function cfg() {
  return window.CAIRN_CFG || {};
}

function spawn() {
  const w = new Worker(cfg().workerUrl, { type: 'module' });
  return w;
}

function boot() {
  if (readyPromise) return readyPromise;

  worker = spawn();
  readyPromise = new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error('Python took too long to start. Check your connection.')), 90000);

    const onMessage = (event) => {
      const msg = event.data || {};
      if (msg.type === 'ready') {
        clearTimeout(timer);
        worker.removeEventListener('message', onMessage);
        resolve();
      } else if (msg.type === 'fatal') {
        clearTimeout(timer);
        worker.removeEventListener('message', onMessage);
        reject(new Error(msg.message || 'The Python runtime failed to start.'));
      }
    };

    worker.addEventListener('message', onMessage);
    worker.postMessage({
      type: 'init',
      indexURL: cfg().pyodideUrl,
      shimUrl: cfg().shimUrl,
    });
  }).catch((err) => {
    readyPromise = null;
    throw err;
  });

  return readyPromise;
}

export function pythonStatus() {
  return readyPromise ? (worker ? 'ready' : 'loading') : 'idle';
}

function kill() {
  if (worker) {
    worker.terminate();
  }
  worker = null;
  readyPromise = null;
  if (activeTimeout) {
    clearTimeout(activeTimeout);
    activeTimeout = null;
  }
}

/**
 * @param {string} code
 * @param {string[]} inputs  one entry per input() call
 * @param {{onStdout?:Function, onStderr?:Function, timeout?:number}} handlers
 * @returns {Promise<{error:?string, ops:array, ms:number}>}
 */
export async function runPython(code, inputs, handlers = {}) {
  await boot();

  const timeoutMs = (handlers.timeout ?? (window.CAIRN_CFG?.timeout || 10)) * 1000;

  return new Promise((resolve, reject) => {
    const w = worker;
    if (!w) {
      reject(new Error('The Python runtime is not running. Reload the page.'));
      return;
    }

    const cleanup = () => {
      w.removeEventListener('message', onMessage);
      w.removeEventListener('error', onError);
      if (activeTimeout) {
        clearTimeout(activeTimeout);
        activeTimeout = null;
      }
    };

    const onMessage = (event) => {
      const msg = event.data || {};
      if (msg.type === 'stdout') {
        handlers.onStdout?.(msg.text, 'out');
      } else if (msg.type === 'stderr') {
        handlers.onStderr?.(msg.text, 'err');
      } else if (msg.type === 'done') {
        cleanup();
        resolve({ error: msg.error || null, usedTurtle: Boolean(msg.usedTurtle), ops: msg.ops || [], ms: msg.ms || 0 });
      } else if (msg.type === 'error') {
        cleanup();
        reject(new Error(msg.message || 'Runtime error.'));
      } else if (msg.type === 'fatal') {
        cleanup();
        kill();
        reject(new Error(msg.message || 'The Python runtime crashed. It will restart on the next run.'));
      }
    };

    const onError = (event) => {
      cleanup();
      kill();
      reject(new Error('The Python runtime crashed: ' + (event.message || 'unknown reason')));
    };

    w.addEventListener('message', onMessage);
    w.addEventListener('error', onError);

    activeTimeout = setTimeout(() => {
      cleanup();
      kill();
      reject(new Error(
        `Your program ran for more than ${Math.round(timeoutMs / 1000)} seconds, so it was stopped. ` +
        'Look for a loop that never ends (a while loop whose condition never becomes False).'
      ));
    }, timeoutMs);

    w.postMessage({ type: 'run', code, inputs });
  });
}

export function resetRuntime() {
  kill();
}

/* ------------------------------------------------------------------ */
/* Turtle canvas                                                       */
/* ------------------------------------------------------------------ */

export class TurtleStage {
  constructor(canvas) {
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.ops = [];
    this.index = 0;
    this.transform = null;
    this.bg = '#ffffff';
    this.finalCursor = null;
    this.timers = [];
    this.onResize = () => {
      if (this.ops.length) {
        this.prepare();
        this.redraw();
      }
    };
    window.addEventListener('resize', this.onResize);
  }

  destroy() {
    window.removeEventListener('resize', this.onResize);
    this.stop();
  }

  stop() {
    this.timers.forEach((t) => cancelAnimationFrame(t));
    this.timers = [];
  }

  reset() {
    this.stop();
    this.ops = [];
    this.index = 0;
    this.finalCursor = null;
    this.bg = '#ffffff';
    this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
  }

  /** @param {array} ops @param {'slow'|'normal'|'instant'} mode */
  play(ops, mode = 'normal') {
    this.stop();
    this.ops = ops;
    this.index = 0;
    this.finalCursor = null;
    this.bg = '#ffffff';
    this.prepare();
    this.clearAll();

    if (!ops.length) return;

    if (mode === 'instant') {
      this.drawRange(0, ops.length);
      this.index = ops.length;
      this.drawCursor();
      return;
    }

    const perOp = mode === 'slow' ? 55 : 14;
    const start = performance.now();
    const tick = () => {
      const elapsed = performance.now() - start;
      const target = Math.min(this.ops.length, Math.floor(elapsed / perOp) + 1);
      this.drawRange(this.index, target);
      this.index = target;
      if (this.index < this.ops.length) {
        this.timers.push(requestAnimationFrame(tick));
      } else {
        this.drawCursor();
      }
    };
    this.timers.push(requestAnimationFrame(tick));
  }

  prepare() {
    const pts = [];
    for (const op of this.ops) {
      if (op.k === 'move') {
        pts.push([op.x1, op.y1], [op.x2, op.y2]);
      } else if (op.k === 'dot') {
        pts.push([op.x - op.r, op.y - op.r], [op.x + op.r, op.y + op.r]);
      } else if (op.k === 'cursor') {
        pts.push([op.x, op.y]);
      } else if (op.k === 'fill' && Array.isArray(op.pts)) {
        op.pts.forEach((p) => pts.push(p));
      } else if (op.k === 'text') {
        pts.push([op.x, op.y]);
      }
    }

    if (!pts.length) {
      pts.push([-160, -160], [160, 160]);
    }

    let minX = Infinity, minY = Infinity, maxX = -Infinity, maxY = -Infinity;
    for (const [x, y] of pts) {
      if (!Number.isFinite(x) || !Number.isFinite(y)) continue;
      minX = Math.min(minX, x);
      minY = Math.min(minY, y);
      maxX = Math.max(maxX, x);
      maxY = Math.max(maxY, y);
    }
    if (!Number.isFinite(minX)) {
      minX = -160; minY = -160; maxX = 160; maxY = 160;
    }
    if (maxX - minX < 1) { minX -= 40; maxX += 40; }
    if (maxY - minY < 1) { minY -= 40; maxY += 40; }

    const pad = 14;
    const dpr = Math.min(2, window.devicePixelRatio || 1);
    const cssW = this.canvas.clientWidth || 420;
    const cssH = this.canvas.clientHeight || 420;
    this.canvas.width = Math.round(cssW * dpr);
    this.canvas.height = Math.round(cssH * dpr);
    this.ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

    const w = maxX - minX;
    const h = maxY - minY;
    const scale = Math.min((cssW - pad * 2) / w, (cssH - pad * 2) / h);

    this.transform = {
      minX, minY, scale, pad, cssW, cssH,
      offsetX: (cssW - w * scale) / 2,
      offsetY: (cssH - h * scale) / 2,
    };
  }

  pt(x, y) {
    const t = this.transform;
    return [
      t.offsetX + (x - t.minX) * t.scale,
      t.cssH - t.offsetY - (y - t.minY) * t.scale,
    ];
  }

  clearAll() {
    const t = this.transform;
    if (!t) return;
    this.ctx.save();
    this.ctx.fillStyle = '#ffffff';
    this.ctx.fillRect(0, 0, t.cssW, t.cssH);
    this.ctx.restore();
  }

  redraw() {
    this.clearAll();
    this.drawRange(0, this.index);
    if (this.index >= this.ops.length) this.drawCursor();
  }

  drawRange(from, to) {
    const ctx = this.ctx;
    for (let i = from; i < to; i++) {
      const op = this.ops[i];
      if (!op) continue;
      if (op.k === 'bg') {
        this.bg = op.c || '#ffffff';
        this.clearAll();
        ctx.save();
        ctx.fillStyle = this.bg;
        ctx.fillRect(0, 0, this.transform.cssW, this.transform.cssH);
        ctx.restore();
      } else if (op.k === 'clear' || op.k === 'reset') {
        this.clearAll();
        ctx.save();
        ctx.fillStyle = this.bg;
        ctx.fillRect(0, 0, this.transform.cssW, this.transform.cssH);
        ctx.restore();
      } else if (op.k === 'move') {
        const [x1, y1] = this.pt(op.x1, op.y1);
        const [x2, y2] = this.pt(op.x2, op.y2);
        ctx.save();
        ctx.strokeStyle = op.c || '#222';
        ctx.lineWidth = Math.max(1, (op.w || 1) * this.transform.scale);
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.stroke();
        ctx.restore();
      } else if (op.k === 'dot') {
        const [x, y] = this.pt(op.x, op.y);
        ctx.save();
        ctx.fillStyle = op.c || '#222';
        ctx.beginPath();
        ctx.arc(x, y, Math.max(1, op.r * this.transform.scale), 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
      } else if (op.k === 'fill' && Array.isArray(op.pts) && op.pts.length > 2) {
        ctx.save();
        ctx.fillStyle = op.c || '#f0c';
        ctx.globalAlpha = 0.85;
        ctx.beginPath();
        op.pts.forEach((p, i) => {
          const [x, y] = this.pt(p[0], p[1]);
          if (i === 0) ctx.moveTo(x, y);
          else ctx.lineTo(x, y);
        });
        ctx.closePath();
        ctx.fill();
        ctx.restore();
      } else if (op.k === 'text') {
        const [x, y] = this.pt(op.x, op.y);
        ctx.save();
        ctx.fillStyle = op.c || '#222';
        const size = Math.max(9, (op.size || 12) * Math.min(1.6, this.transform.scale));
        ctx.font = `${size}px ui-sans-serif, system-ui, sans-serif`;
        ctx.textAlign = op.a === 'center' ? 'center' : op.a === 'right' ? 'right' : 'left';
        ctx.fillText(op.t || '', x, y);
        ctx.restore();
      } else if (op.k === 'cursor') {
        this.finalCursor = op;
      }
    }
  }

  drawCursor() {
    const c = this.finalCursor;
    if (!c || c.v === false || !this.transform) return;
    const [x, y] = this.pt(c.x, c.y);
    const ctx = this.ctx;
    const angle = -(c.h || 0) * Math.PI / 180;
    ctx.save();
    ctx.translate(x, y);
    ctx.rotate(angle);
    ctx.beginPath();
    ctx.moveTo(9, 0);
    ctx.lineTo(-5, 5);
    ctx.lineTo(-2, 0);
    ctx.lineTo(-5, -5);
    ctx.closePath();
    ctx.fillStyle = c.c || '#111';
    ctx.fill();
    ctx.restore();
  }
}
