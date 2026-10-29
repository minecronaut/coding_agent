import { esc } from './ui.js';

/** Minimal, safe markdown. Input is escaped before any tags are added. */

function inline(s) {
  return s
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>')
    .replace(/(^|[\s(])\*([^*\n]+)\*/g, '$1<em>$2</em>')
    .replace(/(^|[\s(])_([^_\n]+)_/g, '$1<em>$2</em>')
    .replace(/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/g, '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>');
}

export function md(src) {
  if (!src) return '';
  let text = esc(String(src)).replace(/\r\n/g, '\n');

  const fences = [];
  text = text.replace(/```[a-zA-Z0-9#+-]*\n([\s\S]*?)```/g, (_, code) => {
    fences.push(`<pre class="md-pre"><code>${code.replace(/\n$/, '')}</code></pre>`);
    return `\u0000F${fences.length - 1}\u0000`;
  });

  const lines = text.split('\n');
  const out = [];
  let para = [];
  let list = null; // 'ul' | 'ol'
  let quote = [];

  const flushPara = () => {
    if (para.length) {
      out.push(`<p>${inline(para.join(' '))}</p>`);
      para = [];
    }
  };
  const flushList = () => {
    if (list) {
      out.push(`</${list}>`);
      list = null;
    }
  };
  const flushQuote = () => {
    if (quote.length) {
      out.push(`<blockquote>${inline(quote.join(' '))}</blockquote>`);
      quote = [];
    }
  };
  const flushAll = () => {
    flushPara();
    flushList();
    flushQuote();
  };

  for (const rawLine of lines) {
    const line = rawLine.trimEnd();

    if (/^\u0000F\d+\u0000$/.test(line.trim())) {
      flushAll();
      out.push(line.trim());
      continue;
    }
    if (!line.trim()) {
      flushAll();
      continue;
    }
    const h = line.match(/^(#{1,4})\s+(.*)$/);
    if (h) {
      flushAll();
      const level = Math.min(4, h[1].length + 2);
      out.push(`<h${level}>${inline(h[2])}</h${level}>`);
      continue;
    }
    if (/^>\s?/.test(line)) {
      flushPara();
      flushList();
      quote.push(line.replace(/^>\s?/, ''));
      continue;
    }
    flushQuote();

    const ul = line.match(/^\s*[-*+]\s+(.*)$/);
    if (ul) {
      flushPara();
      if (list !== 'ul') {
        flushList();
        out.push('<ul>');
        list = 'ul';
      }
      out.push(`<li>${inline(ul[1])}</li>`);
      continue;
    }
    const ol = line.match(/^\s*\d+[.)]\s+(.*)$/);
    if (ol) {
      flushPara();
      if (list !== 'ol') {
        flushList();
        out.push('<ol>');
        list = 'ol';
      }
      out.push(`<li>${inline(ol[1])}</li>`);
      continue;
    }
    flushList();
    para.push(line.trim());
  }
  flushAll();

  return out
    .join('\n')
    .replace(/\u0000F(\d+)\u0000/g, (_, i) => fences[Number(i)] || '');
}

/** Turn a code span into plain text (for copy buttons and labels). */
export function mdText(src) {
  return String(src || '').replace(/```[\s\S]*?```/g, '').replace(/[*_`#>]/g, '').trim();
}
