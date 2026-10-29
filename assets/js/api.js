import { S } from './state.js';

export class ApiError extends Error {
  constructor(message, code) {
    super(message);
    this.code = code;
  }
}

export async function api(path, body = {}) {
  let res;
  try {
    res = await fetch(path, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF': S.csrf,
      },
      body: JSON.stringify(body),
    });
  } catch {
    throw new ApiError('Could not reach the server. Check your connection.', 0);
  }

  let data;
  try {
    data = await res.json();
  } catch {
    throw new ApiError(`The server returned ${res.status}.`, res.status);
  }

  if (!res.ok || data.ok === false) {
    throw new ApiError(data.error || `Request failed (${res.status}).`, res.status);
  }
  return data;
}

/**
 * Stream a chat completion. Falls back to a plain request if the stream
 * breaks part-way (some shared hosts buffer responses).
 *
 * @param {{messages:array, context:object}} payload
 * @param {(text:string)=>void} onDelta
 * @returns {Promise<string>}
 */
export async function streamChat(payload, onDelta) {
  let res;
  try {
    res = await fetch('api/chat.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF': S.csrf,
        'Accept': 'text/event-stream',
      },
      body: JSON.stringify(payload),
    });
  } catch {
    throw new ApiError('Could not reach the server.', 0);
  }

  if (!res.ok || !res.body || (res.headers.get('content-type') || '').includes('application/json')) {
    let data = {};
    try {
      data = await res.json();
    } catch { /* ignore */ }
    if (data.ok && data.text) return data.text;
    throw new ApiError(data.error || `Request failed (${res.status}).`, res.status);
  }

  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buffer = '';
  let full = '';
  let failure = null;

  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buffer += decoder.decode(value, { stream: true });

    let split;
    while ((split = buffer.indexOf('\n\n')) !== -1) {
      const raw = buffer.slice(0, split);
      buffer = buffer.slice(split + 2);

      const eventLine = raw.split('\n').find((l) => l.startsWith('event:'));
      const dataLines = raw.split('\n').filter((l) => l.startsWith('data:'));
      if (!dataLines.length) continue;
      const event = eventLine ? eventLine.slice(6).trim() : 'message';
      let payloadData;
      try {
        payloadData = JSON.parse(dataLines.map((l) => l.slice(5).trim()).join('\n'));
      } catch {
        continue;
      }

      if (event === 'delta' && payloadData.t) {
        full += payloadData.t;
        onDelta(payloadData.t);
      } else if (event === 'error') {
        failure = payloadData.message || 'The model stopped responding.';
      } else if (event === 'done' && payloadData.text) {
        full = payloadData.text;
      }
    }
  }

  if (failure && !full) throw new ApiError(failure, 500);
  if (failure) throw new ApiError(failure, 500);
  if (!full) throw new ApiError('The model returned an empty reply.', 500);
  return full;
}
