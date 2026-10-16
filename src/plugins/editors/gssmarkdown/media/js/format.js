/**
 * GSS Markdown: toolbar / shortcut commands. Every command edits the Markdown source text
 * (the author always sees the syntax); none of them produces HTML.
 *
 * Each export takes an EditorView and dispatches one undoable transaction.
 *
 * @copyright  (C) 2026 Galaxy Software Services Corp.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
import { EditorSelection } from '@codemirror/state';

const HEADING = /^(\s{0,3})(#{1,6})[ \t]+/;
/** Any list / task marker, with its indentation in group 1. */
const LIST = /^(\s*)(?:[-*+][ \t]+\[[ xX]\][ \t]+|[-*+][ \t]+|\d{1,9}[.)][ \t]+)/;
const IS = {
  ul: /^(\s*)[-*+][ \t]+(?!\[[ xX]\][ \t])/,
  ol: /^(\s*)\d{1,9}[.)][ \t]+/,
  task: /^(\s*)[-*+][ \t]+\[[ xX]\][ \t]+/,
};

/** Line numbers touched by the selection (a range ending at a line start excludes that line). */
export function selectedLines(state) {
  const lines = new Set();
  for (const r of state.selection.ranges) {
    const first = state.doc.lineAt(r.from).number;
    let last = state.doc.lineAt(r.to).number;
    if (r.to > r.from && state.doc.lineAt(r.to).from === r.to && last > first) last -= 1;
    for (let n = first; n <= last; n += 1) lines.add(n);
  }
  return [...lines].sort((a, b) => a - b);
}

/** Heading level (1-6) of the line holding the main cursor, or 0 for a paragraph. */
export function headingLevel(state) {
  const m = HEADING.exec(state.doc.lineAt(state.selection.main.head).text);
  return m ? m[2].length : 0;
}

/**
 * @param {number} level 0 = paragraph, 1-6 = heading
 */
export function setHeading(view, level) {
  const { state } = view;
  const changes = selectedLines(state).map((n) => {
    const line = state.doc.line(n);
    const m = HEADING.exec(line.text);
    const indent = m ? m[1].length : 0;
    return {
      from: line.from + indent,
      to: line.from + (m ? m[0].length : 0),
      insert: level ? `${'#'.repeat(level)} ` : '',
    };
  });
  view.dispatch({ changes, scrollIntoView: true, userEvent: 'input.format' });
  view.focus();
}

/**
 * Wraps / unwraps the selection in an inline marker (** * ~~ `). With no selection it inserts
 * the marker pair around a selected placeholder, so the author just types over it.
 */
export function toggleInline(view, marker, placeholderText) {
  const len = marker.length;
  view.dispatch(view.state.changeByRange((range) => {
    const { doc } = view.state;
    const { from, to } = range;
    const before = doc.sliceString(Math.max(0, from - len), from);
    const after = doc.sliceString(to, to + len);

    // Selection just inside the markers: unwrap.
    if (before === marker && after === marker && !(marker === '*' && doc.sliceString(from - 2, from) === '**')) {
      return {
        changes: [{ from: from - len, to: from, insert: '' }, { from: to, to: to + len, insert: '' }],
        range: EditorSelection.range(from - len, to - len),
      };
    }

    const text = doc.sliceString(from, to);

    // Selection including the markers: unwrap.
    if (text.length >= 2 * len && text.startsWith(marker) && text.endsWith(marker)) {
      return {
        changes: { from, to, insert: text.slice(len, -len) },
        range: EditorSelection.range(from, to - 2 * len),
      };
    }

    if (range.empty) {
      return {
        changes: { from, insert: marker + placeholderText + marker },
        range: EditorSelection.range(from + len, from + len + placeholderText.length),
      };
    }

    return {
      changes: [{ from, insert: marker }, { from: to, insert: marker }],
      range: EditorSelection.range(from + len, to + len),
    };
  }), { scrollIntoView: true, userEvent: 'input.format' });
  view.focus();
}

/**
 * Bullet / numbered / task list. Applying the same kind again removes it; another kind
 * replaces the existing marker. Indentation (nesting) is kept.
 *
 * @param {'ul'|'ol'|'task'} kind
 */
export function toggleList(view, kind) {
  const { state } = view;
  let lines = selectedLines(state).map((n) => state.doc.line(n));
  const filled = lines.filter((l) => l.text.trim() !== '');
  if (filled.length) lines = filled;

  const remove = lines.every((l) => IS[kind].test(l.text));
  let number = 0;

  const changes = lines.map((line) => {
    const m = LIST.exec(line.text);
    const indent = m ? m[1].length : (/^\s*/.exec(line.text)[0].length);
    const end = m ? m[0].length : indent;
    number += 1;
    const marker = { ul: '- ', ol: `${number}. `, task: '- [ ] ' }[kind];
    return { from: line.from + indent, to: line.from + end, insert: remove ? '' : marker };
  });

  view.dispatch({ changes, scrollIntoView: true, userEvent: 'input.format' });
  view.focus();
}

/** Block quote on / off for the selected lines (nested quotes are left alone). */
export function toggleQuote(view) {
  const { state } = view;
  const lines = selectedLines(state).map((n) => state.doc.line(n));
  const remove = lines.every((l) => /^\s{0,3}>/.test(l.text));
  const changes = lines.map((line) => {
    if (remove) {
      const m = /^(\s{0,3})> ?/.exec(line.text);
      return { from: line.from + m[1].length, to: line.from + m[0].length, insert: '' };
    }
    return { from: line.from, insert: '> ' };
  });
  view.dispatch({ changes, scrollIntoView: true, userEvent: 'input.format' });
  view.focus();
}

/**
 * When blockPadding() adds nothing after a block: the next line is already blank (add
 * nothing) or the block ends the document (add a blank line to keep typing after it).
 */
function tail(state, to) {
  return state.doc.lineAt(to).number === state.doc.lines ? '\n\n' : '';
}

/** Text to insert so that `insert` starts on a line of its own, after a blank line. */
function blockPadding(state, from, to) {
  const lineBefore = state.doc.lineAt(from);
  const lineAfter = state.doc.lineAt(to);
  let pre = '';
  if (from > lineBefore.from) pre = '\n\n';
  else if (lineBefore.number > 1 && state.doc.line(lineBefore.number - 1).text.trim() !== '') pre = '\n';
  let post = '';
  if (to < lineAfter.to) post = '\n\n';
  else if (lineAfter.number < state.doc.lines && state.doc.line(lineAfter.number + 1).text.trim() !== '') post = '\n';
  return { pre, post };
}

/**
 * Fenced code block. Wraps the selection, or inserts an empty block with the cursor inside.
 *
 * @param {string} language e.g. "js", "" for none
 */
export function insertCodeBlock(view, language) {
  const { state } = view;
  const r = state.selection.main;
  const from = r.empty ? r.from : state.doc.lineAt(r.from).from;
  const to = r.empty ? r.to : state.doc.lineAt(r.to).to;
  const body = state.sliceDoc(from, to);
  const fence = body.includes('```') ? '~~~~' : '```';
  const { pre, post } = blockPadding(state, from, to);
  const open = `${pre}${fence}${language}\n`;
  const insert = `${open}${body}\n${fence}${post}`;
  const start = from + open.length;

  view.dispatch({
    changes: { from, to, insert },
    selection: body ? EditorSelection.range(start, start + body.length) : EditorSelection.cursor(start),
    scrollIntoView: true,
    userEvent: 'input.format',
  });
  view.focus();
}

/** [text](url) with the URL (or the text, when nothing was selected) selected. */
export function insertLink(view, placeholderText) {
  const { state } = view;
  const r = state.selection.main;
  const selected = state.sliceDoc(r.from, r.to);
  const isUrl = /^https?:\/\/\S+$/.test(selected);
  const text = isUrl || !selected ? placeholderText : selected;
  const url = isUrl ? selected : 'https://';
  const insert = `[${text}](${url})`;
  const selection = selected && !isUrl
    ? EditorSelection.range(r.from + text.length + 3, r.from + text.length + 3 + url.length)
    : EditorSelection.range(r.from + 1, r.from + 1 + text.length);

  view.dispatch({ changes: { from: r.from, to: r.to, insert }, selection, scrollIntoView: true, userEvent: 'input.format' });
  view.focus();
}

/** 3-column table with the first header cell selected. */
export function insertTable(view, headerText, cellText) {
  const { state } = view;
  const r = state.selection.main;
  const { pre, post } = blockPadding(state, r.from, r.to);
  const h = [1, 2, 3].map((i) => `${headerText} ${i}`);
  const table = `| ${h.join(' | ')} |\n| --- | --- | --- |\n| ${cellText} | ${cellText} | ${cellText} |`;
  const start = r.from + pre.length + 2;

  view.dispatch({
    changes: { from: r.from, to: r.to, insert: pre + table + post },
    selection: EditorSelection.range(start, start + h[0].length),
    scrollIntoView: true,
    userEvent: 'input.format',
  });
  view.focus();
}

export function insertRule(view) {
  const { state } = view;
  const r = state.selection.main;
  const { pre, post } = blockPadding(state, r.from, r.to);
  const insert = `${pre}---${post || tail(state, r.to)}`;
  view.dispatch({
    changes: { from: r.from, to: r.to, insert },
    selection: EditorSelection.cursor(r.from + insert.length),
    scrollIntoView: true,
    userEvent: 'input.format',
  });
  view.focus();
}

/** Inserts a block (e.g. a video URL) on its own paragraph at the cursor. */
export function insertBlock(view, text) {
  const { state } = view;
  const r = state.selection.main;
  const { pre, post } = blockPadding(state, r.from, r.to);
  const insert = `${pre}${text}${post || tail(state, r.to)}`;
  view.dispatch({
    changes: { from: r.from, to: r.to, insert },
    selection: EditorSelection.cursor(r.from + insert.length),
    scrollIntoView: true,
    userEvent: 'input.format',
  });
  view.focus();
}

const VIDEO_FILE = /^(?:https?:\/\/[^/\s?#"'<>`]+\/|\/(?!\/)|(?![a-z][a-z0-9+.-]*:)(?!\/))[^\s"'<>`\\]*\.(?:mp4|webm|ogv|m4v|mov)(?:[?#][^\s"'<>`\\]*)?$/i;

/** "90", "90s", "1m30s", "1h2m3s" -> seconds. */
const seconds = (value) => {
  if (!value) return 0;
  if (/^\d+$/.test(value)) return Math.min(parseInt(value, 10), 999999);
  const m = /^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/.exec(value);
  return m && m[0] ? Math.min((+(m[1] || 0)) * 3600 + (+(m[2] || 0)) * 60 + (+(m[3] || 0)), 999999) : 0;
};

/**
 * Video URL -> {provider, url} with the canonical URL to write into the Markdown, or null.
 * Mirrors Video\\VideoUrl::parse() on the server, which decides what becomes a video:
 * YouTube, Vimeo, Dailymotion, Bilibili and video files (mp4, webm, ogv, m4v, mov).
 */
export function parseVideo(input) {
  const raw = String(input || '').trim();
  if (!raw || /[\s"'<>`]/.test(raw)) return null;
  if (VIDEO_FILE.test(raw)) return { provider: 'file', url: raw };

  let url;
  try {
    url = new URL(/^https?:\/\//i.test(raw) ? raw : `https://${raw.replace(/^\/\//, '')}`);
  } catch (e) {
    return null;
  }
  const host = url.hostname.toLowerCase();
  const path = url.pathname;
  const q = url.searchParams;
  let m;

  if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'].includes(host)) {
    let id = '';
    if (host === 'youtu.be') id = path.replace(/^\/|\/$/g, '');
    else if (path === '/watch') id = q.get('v') || '';
    else if ((m = /^\/(?:embed|shorts|live|v)\/([^/]+)/.exec(path))) id = m[1];
    if (!/^[A-Za-z0-9_-]{11}$/.test(id) || id === 'videoseries') return null;
    const t = seconds(q.get('t') || q.get('start') || '');
    return { provider: 'youtube', url: `https://www.youtube.com/watch?v=${id}${t ? `&t=${t}s` : ''}` };
  }

  if (['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'].includes(host)) {
    let id = '';
    let hash = '';
    if (host === 'player.vimeo.com') {
      if ((m = /^\/video\/(\d{5,12})$/.exec(path))) [, id] = m;
      hash = q.get('h') || '';
    } else if ((m = /^\/(?:channels\/[\w-]+\/|groups\/[\w-]+\/videos\/|video\/)?(\d{5,12})(?:\/([0-9a-f]{6,20}))?\/?$/.exec(path))) {
      [, id] = m;
      hash = m[2] || q.get('h') || '';
    }
    if (!id) return null;
    if (!/^[0-9a-f]{6,20}$/.test(hash)) hash = '';
    const tm = /^#t=(\w+)$/.exec(url.hash);
    const t = seconds(tm ? tm[1] : q.get('t') || '');
    return { provider: 'vimeo', url: `https://vimeo.com/${id}${hash ? `/${hash}` : ''}${t ? `#t=${t}s` : ''}` };
  }

  if (['dailymotion.com', 'www.dailymotion.com', 'dai.ly', 'geo.dailymotion.com'].includes(host)) {
    let id = '';
    if (host === 'dai.ly') id = path.replace(/^\/|\/$/g, '');
    else if (host === 'geo.dailymotion.com') id = q.get('video') || '';
    else if ((m = /^\/(?:embed\/)?video\/([^/_]+)/.exec(path))) [, id] = m;
    if (!/^x[a-z0-9]{4,12}$/i.test(id)) return null;
    const t = seconds(q.get('start') || '');
    return { provider: 'dailymotion', url: `https://www.dailymotion.com/video/${id}${t ? `?start=${t}` : ''}` };
  }

  if (['bilibili.com', 'www.bilibili.com', 'm.bilibili.com', 'player.bilibili.com'].includes(host)) {
    let key = '';
    if (host === 'player.bilibili.com') {
      const bvid = q.get('bvid') || '';
      const aid = q.get('aid') || '';
      if (bvid) key = /^BV[0-9A-Za-z]{10}$/.test(bvid) ? bvid : '';
      else key = /^\d{1,12}$/.test(aid) ? `av${aid}` : '';
    } else if ((m = /^\/video\/(BV[0-9A-Za-z]{10}|av\d{1,12})\/?$/.exec(path))) {
      [, key] = m;
    }
    if (!key) return null;
    const page = Math.max(1, parseInt(q.get('p') || q.get('page') || '1', 10) || 1);
    const t = seconds(q.get('t') || '');
    const query = [page > 1 ? `p=${page}` : '', t ? `t=${t}` : ''].filter(Boolean).join('&');
    return { provider: 'bilibili', url: `https://www.bilibili.com/video/${key}${query ? `?${query}` : ''}` };
  }

  return null;
}

/**
 * The Markdown for a video: the URL on its own line, or image syntax when there is a title
 * or it is a file (a file path is not an autolink).
 */
export function videoMarkdown(input, title = '') {
  const video = parseVideo(input);
  if (!video) return null;
  const alt = String(title || '').replace(/[[\]\\]/g, '').replace(/\s+/g, ' ').trim();
  if (video.provider === 'file' || alt) return `![${alt}](<${video.url}>)`;
  return video.url;
}

/** @deprecated 2.6: YouTube only; use videoMarkdown(). */
export function youtubeUrl(input) {
  const video = parseVideo(input);
  return video && video.provider === 'youtube' ? video.url : null;
}
