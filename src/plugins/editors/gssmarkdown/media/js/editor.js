/**
 * GSS Markdown editor v2: CodeMirror 6 (from Joomla core's import map) on the left,
 * server-rendered preview in the site's own article layout on the right.
 *
 * @copyright  (C) 2026 Galaxy Software Services Corp.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 *
 * The named <textarea> is what the form submits:
 *   last preview HTML (informational only) + <div hidden data-gssmd-draft="v1:{base64 markdown}">
 * plg_system_gssmarkdown discards the HTML and renders the Markdown with the same
 * MarkdownRenderer that produced the preview. Until the editor has taken over (legacy /
 * drift states) the textarea keeps the article's original value untouched.
 */
import { JoomlaEditor, JoomlaEditorDecorator } from 'editor-api';
import {
  EditorView, Decoration, keymap, drawSelection, dropCursor, highlightActiveLine, highlightActiveLineGutter,
  highlightSpecialChars, lineNumbers, placeholder,
} from '@codemirror/view';
import { EditorState, Compartment, Prec, StateEffect, StateField } from '@codemirror/state';
import { defaultKeymap, history, historyKeymap, indentLess, indentMore, indentWithTab } from '@codemirror/commands';
import {
  syntaxHighlighting, defaultHighlightStyle, HighlightStyle, LanguageDescription, bracketMatching, indentOnInput,
} from '@codemirror/language';
import { markdown, markdownLanguage } from '@codemirror/lang-markdown';
import { searchKeymap, highlightSelectionMatches } from '@codemirror/search';
import { oneDark } from '@codemirror/theme-one-dark';
import { tags } from '@lezer/highlight';
import {
  headingLevel, insertBlock, insertCodeBlock, insertLink, insertRule, insertTable, setHeading, toggleInline, toggleList, toggleQuote,
  videoMarkdown,
} from 'plg_editors_gssmarkdown.format'; // import map entry, versioned by Joomla

const IMAGE_TYPES = {
  'image/png': 'png', 'image/jpeg': 'jpg', 'image/gif': 'gif', 'image/webp': 'webp', 'image/svg+xml': 'svg',
};
const NARROW = 600;
const IS_MAC = /Mac|iP(hone|ad|od)/.test(navigator.platform || navigator.userAgent);
const KEY = (k) => (IS_MAC ? k.replace('Mod-', '⌘').replace('Shift-', '⇧').replace('Alt-', '⌥') : k.replace('Mod-', 'Ctrl+').replace('Shift-', 'Shift+').replace('Alt-', 'Alt+'));

/** 20px line icons (trusted constants), drawn to match a light, even toolbar. */
const ICONS = {
  heading: '<path d="M5 4v12M15 4v12M5 10h10"/>',
  bold: '<path d="M6 4h5a3 3 0 010 6H6zM6 10h5.8a3 3 0 010 6H6z" stroke-width="2"/>',
  italic: '<path d="M12.5 4h-4M11.5 16h-4M11 4l-2 12"/>',
  strike: '<path d="M4 10h12M13.3 6.2C12.8 4.9 11.5 4 9.9 4 8 4 6.6 5.1 6.6 6.6c0 1 .5 1.8 1.6 2.3M6.7 13.6C7.2 15 8.6 16 10.4 16c2 0 3.5-1.1 3.5-2.8 0-.8-.3-1.5-1-2"/>',
  rule: '<path d="M4 10h12"/>',
  quote: '<path d="M4.5 6.5h4v4c0 2.3-1.3 3.8-3.6 4.4M11.5 6.5h4v4c0 2.3-1.3 3.8-3.6 4.4"/>',
  ul: '<path d="M8 5.5h8.5M8 10h8.5M8 14.5h8.5"/><circle cx="4.25" cy="5.5" r="1.1" fill="currentColor" stroke="none"/><circle cx="4.25" cy="10" r="1.1" fill="currentColor" stroke="none"/><circle cx="4.25" cy="14.5" r="1.1" fill="currentColor" stroke="none"/>',
  ol: '<path d="M8.5 5.5h8M8.5 10h8M8.5 14.5h8M3.5 4.2l1.2-.7v3.9M3.2 9c.4-.6 2.2-.7 2.2.5 0 1-2.2 1.6-2.2 2.6h2.3"/>',
  task: '<rect x="3.5" y="3.5" width="13" height="13" rx="2"/><path d="M6.8 10.2l2.2 2.2 4.3-4.6"/>',
  indent: '<path d="M9 5.5h7.5M9 10h7.5M9 14.5h7.5M3.5 7.5L6 10l-2.5 2.5"/>',
  outdent: '<path d="M9 5.5h7.5M9 10h7.5M9 14.5h7.5M6 7.5L3.5 10 6 12.5"/>',
  table: '<rect x="3" y="4" width="14" height="12" rx="1.5"/><path d="M3 8h14M3 12h14M10 4v12"/>',
  image: '<rect x="3" y="4" width="14" height="12" rx="1.5"/><circle cx="7.3" cy="8" r="1.4"/><path d="M17 13.2l-4.2-4.2L5 16"/>',
  video: '<rect x="2.5" y="4.5" width="15" height="11" rx="2.5"/><path d="M8.5 7.8v4.4l3.8-2.2z" fill="currentColor"/>',
  link: '<path d="M8.6 11.4a3 3 0 004.2 0l2.6-2.6a3 3 0 00-4.2-4.2l-1 1M11.4 8.6a3 3 0 00-4.2 0L4.6 11.2a3 3 0 004.2 4.2l1-1"/>',
  code: '<path d="M7 6l-4 4 4 4M13 6l4 4-4 4M11.3 4.5l-2.6 11"/>',
  html: '<text x="1.2" y="13.2" font-family="system-ui,sans-serif" font-size="7.4" font-weight="600" fill="currentColor" stroke="none" letter-spacing=".1">HTML</text>',
  codeblock: '<text x="2.6" y="14.3" font-family="system-ui,sans-serif" font-size="10.5" font-weight="500" fill="currentColor" stroke="none" letter-spacing=".2">CB</text>',
  copy: '<rect x="7" y="7" width="9.5" height="9.5" rx="1.5"/><path d="M13 7V5a1.5 1.5 0 00-1.5-1.5h-6A1.5 1.5 0 004 5v6.5A1.5 1.5 0 005.5 13H7"/>',
  paste: '<rect x="4" y="4" width="12" height="13" rx="1.5"/><path d="M7.5 4V3a1 1 0 011-1h3a1 1 0 011 1v1M7 9.5h6M7 12.5h4"/>',
  download: '<path d="M10 3.5v9M6.5 9L10 12.5 13.5 9M4 15.5h12"/>',
  upload: '<path d="M10 13V4M6.5 7.5L10 4l3.5 3.5M4 13.5v1A1.5 1.5 0 005.5 16h9a1.5 1.5 0 001.5-1.5v-1"/>',
  settings: '<circle cx="10" cy="10" r="2.5"/><path d="M10 2.8v2M10 15.2v2M2.8 10h2M15.2 10h2M4.9 4.9l1.4 1.4M13.7 13.7l1.4 1.4M4.9 15.1l1.4-1.4M13.7 6.3l1.4-1.4"/>',
};

const icon = (name) => {
  const span = document.createElement('span');
  span.className = 'gssmd-icon';
  span.setAttribute('aria-hidden', 'true');
  span.innerHTML = `<svg viewBox="0 0 20 20" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">${ICONS[name]}</svg>`;
  return span;
};

/** Fenced-code languages offered in the toolbar (names Prism knows on the site). */
const CODE_LANGUAGES = [
  ['', 'Plain text'], ['bash', 'Bash / Shell'], ['powershell', 'PowerShell'], ['csharp', 'C#'], ['java', 'Java'],
  ['js', 'JavaScript'], ['ts', 'TypeScript'], ['json', 'JSON'], ['html', 'HTML'], ['css', 'CSS'], ['sql', 'SQL'],
  ['python', 'Python'], ['php', 'PHP'], ['go', 'Go'], ['yaml', 'YAML'], ['xml', 'XML'], ['docker', 'Dockerfile'],
  ['nginx', 'nginx'], ['diff', 'Diff'], ['markdown', 'Markdown'],
];

/**
 * Line highlights in the editor, driven by the preview:
 *   current - Markdown of the block being edited (yellow, like the preview)
 *   hover   - Markdown of the preview block under the mouse (blue)
 */
const lineHighlight = (className) => {
  const effect = StateEffect.define();
  const deco = Decoration.line({ class: className });
  const field = StateField.define({
    create: () => Decoration.none,
    update(value, tr) {
      let next = value.map(tr.changes);
      tr.effects.forEach((e) => {
        if (!e.is(effect)) return;
        if (!e.value) {
          next = Decoration.none;
          return;
        }
        const ranges = [];
        for (let n = e.value.from; n <= Math.min(e.value.to, tr.state.doc.lines); n += 1) {
          ranges.push(deco.range(tr.state.doc.line(n).from));
        }
        next = Decoration.set(ranges);
      });
      return next;
    },
    provide: (f) => EditorView.decorations.from(f),
  });
  return { effect, field };
};
const currentLines = lineHighlight('cm-gssmd-current');
const hoverLines = lineHighlight('cm-gssmd-linked');
const linkedTheme = EditorView.baseTheme({
  '&light .cm-gssmd-current': { backgroundColor: 'rgba(255, 221, 87, 0.22)' },
  '&dark .cm-gssmd-current': { backgroundColor: 'rgba(229, 192, 123, 0.14)' },
  '&light .cm-gssmd-linked': { backgroundColor: 'rgba(43, 108, 176, 0.10)', boxShadow: 'inset 3px 0 0 rgba(43, 108, 176, 0.65)' },
  '&dark .cm-gssmd-linked': { backgroundColor: 'rgba(97, 175, 239, 0.14)', boxShadow: 'inset 3px 0 0 rgba(97, 175, 239, 0.8)' },
});

/**
 * Styles injected into the preview frame. Highlights use background + box-shadow, which never
 * change layout. "Reflow" lets the site's article column follow the pane width.
 */
const FRAME_HINT_CSS = '[data-gssmd-slot]>[data-gssmd-linked]{background-color:rgba(255,221,87,.28)!important;box-shadow:0 0 0 8px rgba(255,221,87,.28);border-radius:1px}'
  + '[data-gssmd-slot]>[data-gssmd-hover]{outline:2px dashed rgba(43,108,176,.45);outline-offset:6px;cursor:pointer}'
  + 'html.gssmd-reflow,html.gssmd-reflow body{overflow-x:hidden}'
  + 'html.gssmd-reflow [data-gssmd-chain],html.gssmd-reflow [data-gssmd-slot]{box-sizing:border-box!important;width:auto!important;min-width:0!important;max-width:100%!important;flex:1 1 auto!important;float:none!important;margin-left:0!important;margin-right:0!important}'
  + 'html.gssmd-reflow [data-gssmd-chain]>[data-gssmd-shell]{display:none!important}'
  + 'html.gssmd-reflow [data-gssmd-slot]{padding-left:20px!important;padding-right:20px!important}'
  + 'html.gssmd-reflow [data-gssmd-slot] img{max-width:100%;height:auto}'
  + 'html.gssmd-reflow [data-gssmd-slot] pre{overflow-x:auto}';

/** Used when the site layout cannot be loaded at all, so the preview still works. */
const BUILTIN_FRAME = '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="gssmd-frame" content="builtin">'
  + '<style>body{margin:0;font:16px/1.75 system-ui,-apple-system,"Segoe UI","Noto Sans TC","PingFang TC","Microsoft JhengHei",sans-serif;color:#222}'
  + '[data-gssmd-slot]{max-width:760px;margin:0 auto;padding:24px}img{max-width:100%}pre{overflow:auto;padding:1em;background:#f5f7fa;border-radius:6px}'
  + '.gssmd-video{position:relative;aspect-ratio:16/9;margin:1.5em 0;overflow:hidden;border-radius:6px;background:#000}.gssmd-video>iframe,.gssmd-video>video{position:absolute;inset:0;width:100%;height:100%;border:0;background:#000;object-fit:contain}'
  + 'table{border-collapse:collapse}th,td{border:1px solid #d6d9de;padding:.4em .7em}blockquote{margin-left:0;padding-left:1em;border-left:4px solid #d6d9de;color:#555}'
  + '</style></head><body><div data-gssmd-slot></div></body></html>';

const FONTS = {
  mono: 'ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", "Noto Sans Mono CJK TC", monospace',
  sans: 'system-ui, -apple-system, "Segoe UI", "Noto Sans TC", "PingFang TC", "Microsoft JhengHei", sans-serif',
};

/** Joomla.Text with sprintf-style %s / %d substitution. */
const t = (key, ...args) => {
  let text = window.Joomla && Joomla.Text ? Joomla.Text._(`PLG_EDITORS_GSSMARKDOWN_${key}`) : key;
  args.forEach((arg) => { text = text.replace(/%(?:s|d)/, String(arg)); });
  return text;
};

const toBase64 = (text) => {
  const bytes = new TextEncoder().encode(text);
  let binary = '';
  for (let i = 0; i < bytes.length; i += 0x8000) {
    binary += String.fromCharCode.apply(null, bytes.subarray(i, i + 0x8000));
  }
  return btoa(binary);
};

const fromBase64 = (b64) => new TextDecoder().decode(Uint8Array.from(atob(b64), (c) => c.charCodeAt(0)));

const el = (tag, attrs = {}, ...children) => {
  const node = document.createElement(tag);
  Object.entries(attrs).forEach(([k, v]) => {
    if (k === 'class') node.className = v;
    else if (k.startsWith('on')) node.addEventListener(k.slice(2), v);
    else if (v !== false && v !== null && v !== undefined) node.setAttribute(k, v === true ? '' : v);
  });
  node.append(...children.filter((c) => c !== null && c !== undefined));
  return node;
};

const isHtmlFile = (file) => /\.x?html?$/i.test(file.name) || file.type === 'text/html';

/**
 * Reads a saved web page / Word "Web page" file in the browser (nothing is uploaded).
 * Honours the declared encoding (old pages and Word files are often Big5) and keeps only
 * the <body>, so the page title and head do not end up in the article. DOMParser never
 * runs the file's scripts.
 *
 * @return {Promise<{html: string, localImages: number}>}
 */
const readHtmlFile = async (file) => {
  const bytes = new Uint8Array(await file.arrayBuffer());
  let charset = 'utf-8';
  if (!(bytes[0] === 0xef && bytes[1] === 0xbb && bytes[2] === 0xbf)) {
    const head = new TextDecoder('latin1').decode(bytes.subarray(0, 4096));
    const m = /<meta[^>]+charset\s*=\s*["']?\s*([\w-]+)/i.exec(head);
    if (m) charset = m[1].toLowerCase();
  }
  let text;
  try {
    text = new TextDecoder(charset).decode(bytes);
  } catch (e) {
    text = new TextDecoder('utf-8').decode(bytes);
  }

  if (!/<body[\s>]/i.test(text)) return { html: text.trim(), localImages: 0 };

  const doc = new DOMParser().parseFromString(text, 'text/html');
  // Word wraps conditional markup in comments; drop comments and invisible head-like parts.
  doc.body.querySelectorAll('script, style, link, meta, title').forEach((node) => node.remove());
  const localImages = [...doc.body.querySelectorAll('img[src]')]
    .filter((img) => !/^(?:https?:)?\/\//i.test(img.getAttribute('src')) && !/^data:/i.test(img.getAttribute('src'))).length;
  const walker = doc.createTreeWalker(doc.body, NodeFilter.SHOW_COMMENT);
  const comments = [];
  while (walker.nextNode()) comments.push(walker.currentNode);
  comments.forEach((node) => node.remove());
  return { html: doc.body.innerHTML.trim(), localImages };
};

/** Code languages inside fenced blocks: the ones Joomla core ships with CodeMirror. */
const codeLanguages = [
  LanguageDescription.of({ name: 'JavaScript', alias: ['js', 'javascript', 'mjs', 'cjs', 'node'], load: async () => (await import('@codemirror/lang-javascript')).javascript() }),
  LanguageDescription.of({ name: 'TypeScript', alias: ['ts', 'typescript'], load: async () => (await import('@codemirror/lang-javascript')).javascript({ typescript: true }) }),
  LanguageDescription.of({ name: 'JSX', alias: ['jsx', 'tsx'], load: async () => (await import('@codemirror/lang-javascript')).javascript({ jsx: true, typescript: true }) }),
  LanguageDescription.of({ name: 'CSS', alias: ['css', 'scss', 'less'], load: async () => (await import('@codemirror/lang-css')).css() }),
  LanguageDescription.of({ name: 'HTML', alias: ['html', 'htm', 'vue'], load: async () => (await import('@codemirror/lang-html')).html() }),
  LanguageDescription.of({ name: 'JSON', alias: ['json', 'jsonc'], load: async () => (await import('@codemirror/lang-json')).json() }),
  LanguageDescription.of({ name: 'PHP', alias: ['php'], load: async () => (await import('@codemirror/lang-php')).php() }),
  LanguageDescription.of({ name: 'XML', alias: ['xml', 'svg', 'xaml', 'csproj'], load: async () => (await import('@codemirror/lang-xml')).xml() }),
];

/** Markdown structure made visible in the light theme (dark uses One Dark). */
const markdownStyle = HighlightStyle.define([
  { tag: tags.heading1, fontWeight: '700', fontSize: '1.3em' },
  { tag: tags.heading2, fontWeight: '700', fontSize: '1.15em' },
  { tag: [tags.heading3, tags.heading4, tags.heading5, tags.heading6], fontWeight: '700' },
  { tag: tags.strong, fontWeight: '700' },
  { tag: tags.emphasis, fontStyle: 'italic' },
  { tag: tags.strikethrough, textDecoration: 'line-through' },
  { tag: tags.link, color: '#0b5cad', textDecoration: 'underline' },
  { tag: tags.url, color: '#0b5cad' },
  { tag: tags.monospace, fontFamily: FONTS.mono, color: '#a3155f' },
  { tag: tags.quote, color: '#5b6470', fontStyle: 'italic' },
  { tag: [tags.processingInstruction, tags.contentSeparator], color: '#8a939e' },
]);

/**
 * Other code (EasyBlog's media manager, editor-xtd buttons) inserts HTML snippets.
 * Turn the common ones into Markdown so they survive server-side escaping.
 */
const htmlSnippetToMarkdown = (value) => {
  if (!/^\s*</.test(value)) {
    return value;
  }
  const doc = new DOMParser().parseFromString(value, 'text/html');
  const nodes = [...doc.body.childNodes].filter((n) => n.nodeType !== 3 || n.textContent.trim() !== '');
  if (nodes.length !== 1 || nodes[0].nodeType !== 1) {
    return value;
  }
  const node = nodes[0];
  const img = node.tagName === 'IMG' ? node : node.querySelector(':scope > img');
  const clean = (s) => (s || '').replace(/[[\]]/g, '');
  if (img && (node === img || node.children.length === 1)) {
    const md = `![${clean(img.getAttribute('alt'))}](${img.getAttribute('src')})`;
    return node.tagName === 'A' ? `[${md}](${node.getAttribute('href')})` : md;
  }
  if (node.tagName === 'A' && node.children.length === 0) {
    return `[${clean(node.textContent)}](${node.getAttribute('href')})`;
  }
  return value;
};

class GssMarkdownDecorator extends JoomlaEditorDecorator {
  getValue() { return this.instance.getValue(); }

  setValue(value) { this.instance.setValue(value); return this; }

  getSelection() { return this.instance.getSelection(); }

  replaceSelection(value) { this.instance.replaceSelection(value); return this; }

  disable(enable) { this.instance.setDisabled(!enable); return this; }

  /** Legacy API still called by EasyBlog's LegacyEditor.save(). */
  onSave() { this.instance.sync(); return this; }
}

class JoomlaEditorGssMarkdown extends HTMLElement {
  connectedCallback() {
    if (this.view) {
      return;
    }

    const loadFail = this.querySelector('.gssmd-loadfail');
    if (loadFail) loadFail.remove();

    this.options = JSON.parse(this.dataset.options || '{}');
    this.prefs = { ...this.options.prefs };
    this.field = this.querySelector('.gssmd-field');
    this.original = this.field.value;
    this.active = false;
    this.pending = new Map();
    this.uploadSeq = 0;
    this.previewSeq = 0;
    this.appliedSeq = 0;
    this.lastHtml = '';
    this.quiet = { editor: 0, preview: 0 };
    this.compartments = { theme: new Compartment(), font: new Compartment(), gutter: new Compartment(), editable: new Compartment() };

    this.buildLayout();
    this.buildEditor();

    JoomlaEditor.register(new GssMarkdownDecorator(this, 'gssmarkdown', this.field.id));

    const form = this.closest('form');
    if (form) {
      // form.submit() does not fire "submit"; change/blur syncing covers that case.
      form.addEventListener('submit', () => this.sync(), true);
    }

    this.resizeObserver = new ResizeObserver(() => { this.applyLayout(); this.fitFrame(); });
    this.resizeObserver.observe(this);
    this.colorScheme = window.matchMedia('(prefers-color-scheme: dark)');
    this.colorScheme.addEventListener('change', () => this.applyTheme());

    this.loadFrame(false);
    this.start();
  }

  disconnectedCallback() {
    if (this.field) {
      JoomlaEditor.unregister(this.field.id);
    }
    if (this.resizeObserver) {
      this.resizeObserver.disconnect();
    }
  }

  /* ---- Layout ------------------------------------------------------------ */

  buildLayout() {
    this.fileInput = el('input', {
      type: 'file', class: 'gssmd-file', accept: 'image/png,image/jpeg,image/gif,image/webp' + (this.options.allowSvg ? ',image/svg+xml' : ''), multiple: true, hidden: true,
      onchange: () => { this.uploadFiles([...this.fileInput.files], null); this.fileInput.value = ''; },
    });

    this.settingsPanel = this.buildSettings();

    // Scroll sync as a switch, like the classic Markdown editors.
    this.syncButton = el('button', {
      type: 'button', class: 'gssmd-switch', role: 'switch', 'aria-checked': String(this.prefs.scrollSync), title: t('SCROLL_SYNC_TIP'),
      onclick: () => this.setScrollSync(!this.prefs.scrollSync),
    }, el('span', { class: 'gssmd-switch-label' }, t('SCROLL_SYNC')), el('span', { class: 'gssmd-switch-track', 'aria-hidden': 'true' }, el('span', { class: 'gssmd-switch-thumb' })));

    this.settingsButton = el('button', {
      type: 'button', class: 'gssmd-tool', title: t('SETTINGS'), 'aria-label': t('SETTINGS'), 'aria-expanded': 'false', 'aria-haspopup': 'true',
      onclick: (e) => { e.stopPropagation(); this.togglePopup(this.settingsPanel, e.currentTarget); },
    }, icon('settings'));

    this.toolbar = el('div', { class: 'gssmd-toolbar', role: 'toolbar', 'aria-label': t('TOOLBAR') },
      this.buildFormatBar(),
      el('span', { class: 'gssmd-spacer' }),
      el('div', { class: 'gssmd-toolbar-end' }, this.syncButton, this.settingsButton),
      this.settingsPanel,
      this.fileInput);

    this.noticeArea = el('div', { class: 'gssmd-notice', role: 'status', 'aria-live': 'polite' });
    this.editorPane = el('div', { class: 'gssmd-pane gssmd-pane--editor', 'aria-label': t('EDITOR_LABEL') });

    // Small floating actions on the Markdown pane: copy all / paste / save as .md
    const action = (name, label, run, extra = {}) => el('button', {
      type: 'button', class: 'gssmd-action', title: label, 'aria-label': label,
      onmousedown: (e) => e.preventDefault(), onclick: run, ...extra,
    }, icon(name));
    this.editorPane.append(el('div', { class: 'gssmd-pane-actions', role: 'group', 'aria-label': t('MD_ACTIONS') },
      action('copy', t('MD_COPY'), () => this.copyMarkdown()),
      action('paste', t('MD_PASTE'), () => this.pasteClipboard(), { 'data-format': 'paste' }),
      action('download', t('MD_SAVE'), () => this.saveMarkdown())));
    this.divider = el('div', {
      class: 'gssmd-divider', role: 'separator', tabindex: '0', 'aria-orientation': 'vertical', 'aria-label': t('RESIZE'),
      'aria-valuemin': '20', 'aria-valuemax': '80',
    });

    this.frame = el('iframe', { class: 'gssmd-frame', title: t('PREVIEW_LABEL'), tabindex: '-1' });
    this.frameSizer = el('div', { class: 'gssmd-frame-sizer' });
    this.frameBox = el('div', { class: 'gssmd-frame-box' }, this.frameSizer, this.frame);
    this.previewPane = el('div', { class: 'gssmd-pane gssmd-pane--preview', 'aria-label': t('PREVIEW_LABEL') }, this.frameBox);

    this.panes = el('div', { class: 'gssmd-panes', 'data-sync': this.prefs.scrollSync ? 'on' : 'off' }, this.editorPane, this.divider, this.previewPane);

    // Bottom bar: what the preview covers + its state on the left, view tabs on the right.
    this.previewState = el('span', { class: 'gssmd-preview-state', role: 'status', 'aria-live': 'polite' });
    const tab = (view, label) => el('button', {
      type: 'button', class: 'gssmd-tab', role: 'tab', 'data-view': view, 'aria-selected': 'false', onclick: () => this.setView(view, true),
    }, label);
    this.statusbar = el('div', { class: 'gssmd-statusbar' },
      el('span', { class: 'gssmd-scope', title: t('PREVIEW_SCOPE') }, t('PREVIEW_SCOPE_SHORT')),
      this.previewState,
      el('span', { class: 'gssmd-spacer' }),
      el('div', { class: 'gssmd-tabs', role: 'tablist', 'aria-label': t('VIEW') },
        tab('edit', t('VIEW_EDIT')), tab('split', t('VIEW_SPLIT')), tab('preview', t('VIEW_PREVIEW'))));

    this.prepend(this.toolbar, this.noticeArea, this.panes, this.statusbar);

    document.addEventListener('click', (e) => { if (!e.target.closest('.gssmd-popup, .gssmd-tool')) this.closePopups(); });
    this.addEventListener('keydown', (e) => { if (e.key === 'Escape') this.closePopups(); });

    this.bindDivider();
    this.applyLayout();
  }

  /** Markdown formatting controls, grouped like the classic editors. Each edits source text (format.js). */
  buildFormatBar() {
    const v = () => this.view;
    const tool = (name, label, key, run) => el('button', {
      type: 'button', class: 'gssmd-tool', 'data-format': name,
      title: key ? `${label} (${KEY(key)})` : label, 'aria-label': label,
      onmousedown: (e) => e.preventDefault(), // keep the editor selection
      onclick: run,
    }, icon(name));
    const sep = () => el('span', { class: 'gssmd-sep', 'aria-hidden': 'true' });

    const headingMenu = this.buildMenu('heading', [
      { label: t('FMT_PARAGRAPH'), className: 'is-p', run: () => setHeading(v(), 0) },
      ...[1, 2, 3, 4, 5, 6].map((n) => ({ label: t('FMT_HEADING', n), className: `is-h${n}`, run: () => setHeading(v(), n) })),
    ]);
    const codeMenu = this.buildMenu('codeblock', CODE_LANGUAGES.map(([lang, name]) => ({
      label: lang ? name : t('FMT_PLAIN'), run: () => insertCodeBlock(v(), lang),
    })));

    const menuTool = (name, label, key, menu) => el('span', { class: 'gssmd-menu-anchor' },
      el('button', {
        type: 'button', class: 'gssmd-tool', 'data-format': name, 'aria-haspopup': 'menu', 'aria-expanded': 'false',
        title: key ? `${label} (${KEY(key)})` : label, 'aria-label': label,
        onmousedown: (e) => e.preventDefault(),
        onclick: (e) => { e.stopPropagation(); this.togglePopup(menu, e.currentTarget); },
      }, icon(name)),
      menu);

    this.headingButton = menuTool('heading', t('FMT_BLOCK'), 'Mod-Alt-1', headingMenu);

    return el('div', { class: 'gssmd-format', role: 'group', 'aria-label': t('FMT_GROUP') },
      this.headingButton,
      tool('bold', t('FMT_BOLD'), 'Mod-B', () => toggleInline(v(), '**', t('PH_BOLD'))),
      tool('italic', t('FMT_ITALIC'), 'Mod-I', () => toggleInline(v(), '*', t('PH_ITALIC'))),
      tool('strike', t('FMT_STRIKE'), 'Mod-Shift-X', () => toggleInline(v(), '~~', t('PH_STRIKE'))),
      sep(),
      tool('rule', t('FMT_RULE'), null, () => insertRule(v())),
      tool('quote', t('FMT_QUOTE'), 'Mod-Shift-.', () => toggleQuote(v())),
      sep(),
      tool('ul', t('FMT_UL'), 'Mod-Shift-8', () => toggleList(v(), 'ul')),
      tool('ol', t('FMT_OL'), 'Mod-Shift-7', () => toggleList(v(), 'ol')),
      tool('task', t('FMT_TASK'), 'Mod-Shift-9', () => toggleList(v(), 'task')),
      tool('indent', t('FMT_INDENT'), 'Tab', () => { indentMore(v()); v().focus(); }),
      tool('outdent', t('FMT_OUTDENT'), 'Shift-Tab', () => { indentLess(v()); v().focus(); }),
      sep(),
      tool('table', t('FMT_TABLE'), null, () => insertTable(v(), t('PH_HEADER'), t('PH_CELL'))),
      tool('image', t('INSERT_IMAGE'), null, () => this.fileInput.click()),
      this.buildVideoTool(),
      tool('link', t('FMT_LINK'), 'Mod-K', () => insertLink(v(), t('PH_LINK'))),
      sep(),
      tool('code', t('FMT_CODE'), 'Mod-E', () => toggleInline(v(), '`', t('PH_CODE'))),
      menuTool('codeblock', t('FMT_CODEBLOCK'), 'Mod-Alt-C', codeMenu),
      sep(),
      tool('html', t('FMT_HTML'), null, () => this.openHtmlDialog()));
  }

  /**
   * "Convert HTML" dialog: HTML source (or content copied from a web page / Word) on the
   * left, the Markdown the server makes of it on the right (editable), then one click
   * inserts it at the cursor. Uses the converter of legacy articles, so the result is the
   * same as converting an old article.
   */
  openHtmlDialog(file = null) {
    this.closePopups();
    if (!this.htmlDialog) this.htmlDialog = this.buildHtmlDialog();
    this.htmlDialog.open(file);
  }

  buildHtmlDialog() {
    const id = `gssmd-html-${Math.random().toString(36).slice(2, 8)}`;
    const fileInput = el('input', { type: 'file', accept: '.html,.htm,.xhtml,text/html', class: 'gssmd-file', hidden: true, tabindex: '-1' });
    const fileButton = el('button', {
      type: 'button', class: 'gssmd-html-file', title: t('HTML_FILE'), onclick: () => fileInput.click(),
    }, icon('upload'), el('span', {}, t('HTML_FILE')));
    const mask = el('div', { class: 'gssmd-html-mask', 'aria-hidden': 'true', hidden: true },
      el('div', { class: 'gssmd-html-mask-inner' }, icon('upload'), el('span', {}, t('HTML_FILE_DROP'))));
    const source = el('textarea', {
      class: 'gssmd-html-text', spellcheck: 'false', placeholder: t('HTML_SOURCE_HINT'), 'aria-labelledby': `${id}-src`,
    });
    const result = el('textarea', {
      class: 'gssmd-html-text', spellcheck: 'false', placeholder: t('HTML_RESULT_HINT'), 'aria-labelledby': `${id}-md`,
    });
    const status = el('div', { class: 'gssmd-html-status', role: 'status', 'aria-live': 'polite' });
    const insertButton = el('button', { type: 'button', class: 'btn btn-primary', disabled: true }, t('HTML_INSERT'));
    const cancelButton = el('button', { type: 'button', class: 'btn btn-secondary' }, t('HTML_CANCEL'));
    const dialog = el('dialog', { class: 'gssmd-html-dialog', 'aria-labelledby': `${id}-title` },
      el('div', { class: 'gssmd-html-head' },
        el('h2', { id: `${id}-title`, class: 'gssmd-html-title' }, t('FMT_HTML')),
        el('p', { class: 'gssmd-html-help' }, t('HTML_HELP'))),
      el('div', { class: 'gssmd-html-panes' },
        el('div', { class: 'gssmd-html-pane gssmd-html-drop' },
          el('div', { id: `${id}-src`, class: 'gssmd-html-label' }, t('HTML_SOURCE')),
          el('div', { class: 'gssmd-html-box' }, source, fileButton, fileInput, mask)),
        el('div', { class: 'gssmd-html-pane' }, el('div', { id: `${id}-md`, class: 'gssmd-html-label' }, t('HTML_RESULT')), result)),
      el('div', { class: 'gssmd-html-foot' }, status, el('div', { class: 'gssmd-html-actions' }, cancelButton, insertButton)));
    this.append(dialog);

    let timer = null;
    let seq = 0;
    let pending = null;
    let abort = null;

    const setStatus = (message, kind = '') => {
      status.textContent = message;
      status.dataset.kind = kind;
    };
    const updateButton = () => {
      insertButton.disabled = result.value.trim() === '' || pending !== null;
    };

    const convert = async () => {
      clearTimeout(timer);
      const html = source.value;
      seq += 1;
      const mine = seq;
      if (abort) abort.abort();

      if (html.trim() === '') {
        pending = null;
        result.value = '';
        setStatus('');
        updateButton();
        return;
      }
      if (new TextEncoder().encode(html).length > this.options.previewMax) {
        pending = null;
        setStatus(t('ERR_PREVIEW_TOO_LARGE', Math.round(this.options.previewMax / 1024)), 'error');
        updateButton();
        return;
      }

      abort = new AbortController();
      setStatus(t('HTML_CONVERTING'), 'busy');
      const body = new FormData();
      body.append('html', html);
      body.append('seq', String(mine));
      this.appendContext(body);
      body.append(this.options.token, '1');
      pending = (async () => {
        try {
          const response = await fetch(this.options.convertUrl, {
            method: 'POST', body, credentials: 'same-origin', signal: abort.signal,
            headers: { 'X-CSRF-Token': this.options.token, 'X-Requested-With': 'XMLHttpRequest' },
          });
          const json = await response.json().catch(() => null);
          if (!json || !json.success) throw new Error((json && json.error && json.error.message) || t('HTML_FAILED'));
          if (mine !== seq) return;
          result.value = json.data.markdown.replace(/\s+$/, '');
          setStatus(json.data.dropped.length ? t('HTML_DROPPED', json.data.dropped.map((tag) => `<${tag}>`).join('、')) : '', json.data.dropped.length ? 'warning' : '');
        } catch (error) {
          if (error.name !== 'AbortError' && mine === seq) setStatus(error.message || t('HTML_FAILED'), 'error');
        }
      })();
      const running = pending;
      updateButton();
      await running;
      if (pending === running) {
        pending = null;
        updateButton();
      }
    };

    source.addEventListener('input', () => {
      clearTimeout(timer);
      timer = setTimeout(convert, 400);
    });
    result.addEventListener('input', updateButton);

    // Content copied from a web page or Word arrives as formatted HTML: take that, not the
    // plain-text version. HTML source code copied as text is kept as it is.
    source.addEventListener('paste', (event) => {
      const data = event.clipboardData;
      const html = data ? data.getData('text/html') : '';
      const plain = data ? data.getData('text/plain') : '';
      if (!html || /<[a-z][^>]*>/i.test(plain)) return;
      event.preventDefault();
      const fragment = html.replace(/^[\s\S]*?<!--StartFragment-->|<!--EndFragment-->[\s\S]*$/g, '');
      source.setRangeText(fragment, source.selectionStart, source.selectionEnd, 'end');
      source.dispatchEvent(new Event('input'));
    });

    /** A dropped or chosen .html file goes into the HTML box and is converted at once. */
    let fileNote = '';
    const loadFile = async (file) => {
      if (!file) return;
      if (!isHtmlFile(file)) {
        setStatus(t('HTML_FILE_INVALID'), 'error');
        return;
      }
      try {
        const { html, localImages } = await readHtmlFile(file);
        source.value = html;
        fileNote = localImages ? t('HTML_FILE_LOCAL_IMAGES', localImages) : '';
        await convert();
        if (status.dataset.kind !== 'error') {
          const notes = [t('HTML_FILE_LOADED', file.name), fileNote, status.dataset.kind === 'warning' ? status.textContent : ''].filter(Boolean);
          setStatus(notes.join(' '), fileNote || status.dataset.kind === 'warning' ? 'warning' : '');
        }
      } catch (error) {
        setStatus(t('HTML_FILE_INVALID'), 'error');
      }
    };
    fileInput.addEventListener('change', () => {
      loadFile(fileInput.files[0]);
      fileInput.value = '';
    });
    /*
     * Dragging a file over the left (HTML) side shows a mask; releasing loads it. Elsewhere in
     * the dialog a dropped file is refused, so the browser does not open it instead.
     */
    const dropZone = dialog.querySelector('.gssmd-html-drop');
    const hasFiles = (event) => event.dataTransfer && [...event.dataTransfer.types].includes('Files');
    let depth = 0;
    const showMask = (on) => {
      mask.hidden = !on;
      dropZone.classList.toggle('is-dragover', on);
    };
    dropZone.addEventListener('dragenter', (event) => {
      if (!hasFiles(event)) return;
      depth += 1;
      showMask(true);
    });
    dropZone.addEventListener('dragleave', (event) => {
      if (!hasFiles(event)) return;
      depth = Math.max(0, depth - 1);
      if (depth === 0) showMask(false);
    });
    dialog.addEventListener('dragover', (event) => {
      if (!hasFiles(event)) return;
      event.preventDefault();
      event.dataTransfer.dropEffect = dropZone.contains(event.target) ? 'copy' : 'none';
    });
    dialog.addEventListener('drop', (event) => {
      if (!hasFiles(event)) return;
      event.preventDefault();
      const inside = dropZone.contains(event.target);
      depth = 0;
      showMask(false);
      if (inside && event.dataTransfer.files.length) loadFile(event.dataTransfer.files[0]);
    });

    const insert = async () => {
      if (timer || pending) await convert(); // the source changed since the last conversion
      const markdown = result.value.trim();
      if (!markdown) return;
      dialog.close();
      insertBlock(this.view, markdown);
    };
    insertButton.addEventListener('click', insert);
    cancelButton.addEventListener('click', () => dialog.close());
    dialog.addEventListener('keydown', (event) => {
      if (event.key === 'Enter' && (event.metaKey || event.ctrlKey) && !insertButton.disabled) {
        event.preventDefault();
        insert();
      }
    });
    dialog.addEventListener('close', () => {
      clearTimeout(timer);
      timer = null;
      if (abort) abort.abort();
      this.view.focus();
    });

    return {
      open: (file = null) => {
        source.value = '';
        result.value = '';
        setStatus('');
        pending = null;
        updateButton();
        dialog.showModal();
        source.focus();
        if (file) loadFile(file);
      },
    };
  }

  /** Video button: a small pop-up asking for the video URL (sites or files) and a title. */
  buildVideoTool() {
    const input = el('input', {
      type: 'text', inputmode: 'url', class: 'gssmd-video-input', placeholder: 'https://www.youtube.com/watch?v=…',
      'aria-label': t('VIDEO_URL'), autocomplete: 'off', spellcheck: 'false',
    });
    const title = el('input', {
      type: 'text', class: 'gssmd-video-input gssmd-video-title', placeholder: t('VIDEO_TITLE_HINT'),
      'aria-label': t('VIDEO_TITLE_LABEL'), autocomplete: 'off',
    });
    const error = el('div', { class: 'gssmd-video-error', role: 'alert', hidden: true });
    const insert = () => {
      const markdown = videoMarkdown(input.value, title.value);
      if (!markdown) {
        error.textContent = t('VIDEO_INVALID');
        error.hidden = false;
        input.focus();
        return;
      }
      this.closePopups();
      input.value = '';
      title.value = '';
      error.hidden = true;
      insertBlock(this.view, markdown);
    };
    [input, title].forEach((field) => field.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        insert();
      }
    }));

    const popup = el('div', { class: 'gssmd-popup gssmd-video-popup', role: 'dialog', 'aria-label': t('FMT_VIDEO'), hidden: true, onclick: (e) => e.stopPropagation() },
      el('label', { class: 'gssmd-video-label' }, t('VIDEO_URL')),
      input,
      el('div', { class: 'gssmd-video-row' }, title,
        el('button', { type: 'button', class: 'gssmd-video-insert', onclick: insert }, t('VIDEO_INSERT'))),
      error,
      el('div', { class: 'gssmd-video-help' }, t('VIDEO_HELP')));

    return el('span', { class: 'gssmd-menu-anchor' },
      el('button', {
        type: 'button', class: 'gssmd-tool', 'data-format': 'video', 'aria-haspopup': 'dialog', 'aria-expanded': 'false',
        title: t('FMT_VIDEO'), 'aria-label': t('FMT_VIDEO'),
        onmousedown: (e) => e.preventDefault(),
        onclick: (e) => { e.stopPropagation(); error.hidden = true; this.togglePopup(popup, e.currentTarget); },
      }, icon('video')),
      popup);
  }

  /** A small pop-up menu (heading levels, code languages). */
  buildMenu(name, items) {
    const menu = el('div', { class: `gssmd-popup gssmd-menu gssmd-menu--${name}`, role: 'menu', hidden: true },
      ...items.map((item) => el('button', {
        type: 'button', role: 'menuitem', class: `gssmd-menu-item ${item.className || ''}`, tabindex: '-1',
        onmousedown: (e) => e.preventDefault(),
        onclick: () => { this.closePopups(); item.run(); },
      }, item.label)));

    menu.addEventListener('keydown', (e) => {
      const entries = [...menu.querySelectorAll('[role=menuitem]')];
      const i = entries.indexOf(document.activeElement);
      if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
        e.preventDefault();
        entries[(i + (e.key === 'ArrowDown' ? 1 : -1) + entries.length) % entries.length].focus();
      }
    });
    return menu;
  }

  togglePopup(popup, button) {
    const open = popup.hidden;
    this.closePopups();
    if (!open) return;
    popup.hidden = false;
    button.setAttribute('aria-expanded', 'true');
    this.openButton = button;
    const first = popup.querySelector('[role=menuitem], select, input');
    if (first) first.focus();
  }

  closePopups() {
    this.querySelectorAll('.gssmd-popup').forEach((p) => { p.hidden = true; });
    if (this.openButton) {
      this.openButton.setAttribute('aria-expanded', 'false');
      this.openButton = null;
    }
  }

  formatKeymap() {
    const run = (fn) => (view) => { fn(view); return true; };
    const headings = [0, 1, 2, 3, 4, 5, 6].map((n) => ({ key: `Mod-Alt-${n}`, run: run((view) => setHeading(view, n)) }));
    return Prec.high(keymap.of([
      { key: 'Mod-b', run: run((view) => toggleInline(view, '**', t('PH_BOLD'))) },
      { key: 'Mod-i', run: run((view) => toggleInline(view, '*', t('PH_ITALIC'))) },
      { key: 'Mod-Shift-x', run: run((view) => toggleInline(view, '~~', t('PH_STRIKE'))) },
      { key: 'Mod-e', run: run((view) => toggleInline(view, '`', t('PH_CODE'))) },
      { key: 'Mod-k', run: run((view) => insertLink(view, t('PH_LINK'))) },
      { key: 'Mod-Shift-8', run: run((view) => toggleList(view, 'ul')) },
      { key: 'Mod-Shift-7', run: run((view) => toggleList(view, 'ol')) },
      { key: 'Mod-Shift-9', run: run((view) => toggleList(view, 'task')) },
      { key: 'Mod-Shift-.', run: run(toggleQuote) },
      { key: 'Mod-Alt-c', run: run((view) => insertCodeBlock(view, '')) },
      ...headings,
    ]));
  }

  buildSettings() {
    const save = () => { this.savePrefs(); };
    const select = (label, key, choices, onchange) => el('label', {},
      el('span', {}, label),
      el('select', { onchange: (e) => { this.prefs[key] = e.target.value; onchange(); save(); } },
        ...choices.map(([value, text]) => el('option', { value, selected: this.prefs[key] === value }, text))));

    return el('div', { class: 'gssmd-popup gssmd-settings', hidden: true, onclick: (e) => e.stopPropagation() },
      select(t('THEME'), 'theme', [['auto', t('THEME_AUTO')], ['light', t('THEME_LIGHT')], ['dark', t('THEME_DARK')]], () => this.applyTheme()),
      select(t('FONT'), 'font', [['mono', t('FONT_MONO')], ['sans', t('FONT_SANS')]], () => this.applyFont()),
      el('label', {}, el('span', {}, t('FONT_SIZE')),
        el('input', {
          type: 'number', min: '11', max: '24', value: String(this.prefs.fontSize),
          onchange: (e) => { this.prefs.fontSize = Math.max(11, Math.min(24, parseInt(e.target.value, 10) || 14)); this.applyFont(); save(); },
        })),
      el('label', { class: 'gssmd-check' },
        el('input', {
          type: 'checkbox', checked: this.prefs.lineNumbers,
          onchange: (e) => { this.prefs.lineNumbers = e.target.checked; this.applyGutter(); save(); },
        }),
        el('span', {}, t('LINE_NUMBERS'))),
      // Zoom only magnifies; the page is laid out at the site's width either way.
      select(t('PREVIEW_WIDTH'), 'zoom', [['fit', t('PREVIEW_WIDTH_FIT')], ['actual', t('PREVIEW_WIDTH_SITE')]], () => { this.applyLayout(); this.fitFrame(); }),
      el('button', { type: 'button', class: 'gssmd-link', onclick: () => { this.closePopups(); this.loadFrame(true); } }, t('PREVIEW_REFRESH')));
  }

  /** Which panes are visible. Narrow screens get one pane at a time. */
  applyLayout() {
    this.narrow = this.clientWidth > 0 && this.clientWidth < NARROW;
    let view = this.prefs.view;

    if (this.narrow && view === 'split') {
      view = this.narrowView || 'edit';
    }

    this.currentView = view;
    this.panes.dataset.view = view;
    const ratio = this.effectiveRatio();
    this.panes.style.setProperty('--gssmd-ratio', String(ratio));
    this.divider.setAttribute('aria-valuenow', String(Math.round(ratio * 100)));
    this.statusbar.querySelectorAll('[data-view]').forEach((b) => {
      b.setAttribute('aria-selected', String(b.dataset.view === view));
      b.hidden = this.narrow && b.dataset.view === 'split';
    });
  }

  /**
   * Editor share of the split. ratio 0 = automatic: give the preview the site's text column
   * width at 100% (known once the layout frame is loaded), but never more than 65%.
   */
  effectiveRatio() {
    if (this.prefs.ratio) return this.prefs.ratio;
    if (this.prefs.zoom !== 'actual') return 0.5;
    const total = this.panes.clientWidth;
    if (!this.columnWidth || !total) return 0.5;
    const previewNeeds = this.columnWidth + 48 + 5;
    return Math.round(Math.min(0.65, Math.max(0.35, 1 - previewNeeds / total)) * 1000) / 1000;
  }

  setView(view, remember) {
    if (this.narrow) {
      this.narrowView = view === 'split' ? 'edit' : view;
    } else {
      this.prefs.view = view;
      if (remember) this.savePrefs();
    }
    this.applyLayout();
    requestAnimationFrame(() => { this.fitFrame(); this.requestScrollSync('editor'); if (this.currentView !== 'preview') this.view.requestMeasure(); });
  }

  bindDivider() {
    const setRatio = (ratio) => {
      this.prefs.ratio = Math.round(Math.max(0.2, Math.min(0.8, ratio)) * 1000) / 1000;
      this.applyLayout();
      this.fitFrame();
    };

    this.divider.addEventListener('pointerdown', (event) => {
      event.preventDefault();
      this.divider.setPointerCapture(event.pointerId);
      this.panes.classList.add('is-resizing');
      const rect = this.panes.getBoundingClientRect();
      const move = (e) => setRatio((e.clientX - rect.left) / rect.width);
      const up = () => {
        this.divider.removeEventListener('pointermove', move);
        this.divider.removeEventListener('pointerup', up);
        this.panes.classList.remove('is-resizing');
        this.savePrefs();
      };
      this.divider.addEventListener('pointermove', move);
      this.divider.addEventListener('pointerup', up);
    });

    this.divider.addEventListener('keydown', (event) => {
      const step = { ArrowLeft: -0.02, ArrowRight: 0.02 }[event.key];
      if (step) {
        event.preventDefault();
        setRatio(this.effectiveRatio() + step);
        this.savePrefs();
      }
    });
  }

  /* ---- Editor ------------------------------------------------------------ */

  buildEditor() {
    const c = this.compartments;

    this.view = new EditorView({
      parent: this.editorPane,
      state: EditorState.create({
        doc: '',
        extensions: [
          c.gutter.of(this.gutterExtension()),
          highlightSpecialChars(),
          history(),
          drawSelection(),
          dropCursor(),
          indentOnInput(),
          bracketMatching(),
          highlightActiveLine(),
          highlightSelectionMatches(),
          EditorView.lineWrapping,
          markdown({ base: markdownLanguage, codeLanguages }),
          this.formatKeymap(),
          keymap.of([...defaultKeymap, ...historyKeymap, ...searchKeymap, indentWithTab]),
          currentLines.field,
          hoverLines.field,
          linkedTheme,
          placeholder(t('EDITOR_PLACEHOLDER')),
          c.theme.of(this.themeExtension()),
          c.font.of(this.fontExtension()),
          c.editable.of(EditorView.editable.of(true)),
          EditorView.contentAttributes.of({ 'aria-label': t('EDITOR_LABEL'), spellcheck: 'false' }),
          EditorView.updateListener.of((update) => this.onUpdate(update)),
          EditorView.domEventHandlers({
            paste: (event) => this.onPaste(event),
            drop: (event, view) => this.onDrop(event, view),
            focus: () => JoomlaEditor.setActive(this.field.id),
            blur: () => this.sync(),
          }),
        ],
      }),
    });

    const scroller = this.view.scrollDOM;
    scroller.addEventListener('scroll', () => this.onPaneScroll('editor'), { passive: true });
    // Real user input on a pane always wins over the echo of a synced scroll.
    ['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach((type) => {
      scroller.addEventListener(type, () => { this.quiet.editor = 0; }, { passive: true });
    });
  }

  themeExtension() {
    const dark = this.isDark();
    this.classList.toggle('is-dark', dark);
    return dark ? oneDark : [syntaxHighlighting(markdownStyle), syntaxHighlighting(defaultHighlightStyle, { fallback: true })];
  }

  fontExtension() {
    return EditorView.theme({
      '&': { fontSize: `${this.prefs.fontSize}px`, height: '100%' },
      '.cm-content, .cm-gutters': { fontFamily: FONTS[this.prefs.font] || FONTS.mono },
      '.cm-scroller': { lineHeight: '1.6' },
    });
  }

  gutterExtension() {
    return this.prefs.lineNumbers ? [lineNumbers(), highlightActiveLineGutter()] : [];
  }

  isDark() {
    if (this.prefs.theme !== 'auto') return this.prefs.theme === 'dark';
    const scheme = document.documentElement.dataset.colorScheme || document.documentElement.dataset.bsTheme;
    return scheme ? scheme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  applyTheme() { this.view.dispatch({ effects: this.compartments.theme.reconfigure(this.themeExtension()) }); }

  applyFont() { this.view.dispatch({ effects: this.compartments.font.reconfigure(this.fontExtension()) }); }

  applyGutter() { this.view.dispatch({ effects: this.compartments.gutter.reconfigure(this.gutterExtension()) }); }

  savePrefs() {
    clearTimeout(this.prefsTimer);
    this.prefsTimer = setTimeout(() => {
      const body = new FormData();
      body.append('prefs', JSON.stringify(this.prefs));
      body.append(this.options.token, '1');
      fetch(this.options.prefsUrl, { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-Token': this.options.token } })
        .catch(() => { /* Preferences are a convenience; the editor works without them. */ });
    }, 600);
  }

  onUpdate(update) {
    if (update.selectionSet || update.docChanged) {
      const level = headingLevel(update.state);
      this.headingButton.firstChild.classList.toggle('is-active', level > 0);
      this.headingButton.firstChild.dataset.level = level ? `H${level}` : '';
      // Reveal the edited block in the preview only for the author's own moves / typing.
      const byUser = update.transactions.some((tr) => ['input', 'delete', 'select', 'move', 'undo', 'redo'].some((e) => tr.isUserEvent(e)));
      this.scheduleCursorLink(byUser);
    }

    if (!update.docChanged) {
      return;
    }

    // Keep each upload placeholder's position current (located by id, not by text).
    this.pending.forEach((p) => {
      p.from = update.changes.mapPos(p.from, 1);
      p.to = update.changes.mapPos(p.to, -1);
    });

    if (this.active) {
      this.scheduleSync();
      this.schedulePreview();
    }
  }

  start() {
    const markdownText = this.querySelector('.gssmd-initial').value;
    const converted = this.querySelector('.gssmd-converted').value;

    switch (this.options.mode) {
      case 'drift':
        this.setDoc(markdownText);
        this.requestPreview();
        this.lock(t('DRIFT_GATE'), [
          { label: t('USE_SOURCE'), confirm: t('USE_SOURCE_CONFIRM'), run: () => this.activate() },
          { label: t('CONVERT_CURRENT'), confirm: t('CONVERT_CONFIRM'), run: () => this.convert(converted) },
          { label: t('USE_FALLBACK', this.options.fallbackLabel), run: () => this.reloadWith('fallback') },
        ]);
        break;

      case 'convert':
        if (this.options.confirmed) {
          this.convert(converted);
        } else {
          this.setDoc(converted);
          this.requestPreview();
          this.lock(t('LEGACY_GATE'), [
            { label: t('CONVERT_BUTTON'), confirm: t('CONVERT_CONFIRM'), run: () => this.convert(converted) },
            { label: t('USE_FALLBACK', this.options.fallbackLabel), run: () => this.reloadWith('fallback') },
          ]);
        }
        break;

      default:
        this.setDoc(markdownText);
        this.activate();
    }
  }

  setDoc(text) {
    this.view.dispatch({ changes: { from: 0, to: this.view.state.doc.length, insert: text } });
  }

  /** From now on the field carries this editor's content. */
  activate() {
    this.unlock();
    this.active = true;
    this.sync();
    this.requestPreview();
  }

  convert(markdownText) {
    this.setDoc(markdownText);
    this.activate();
    this.notify(t('CONVERTED_NOTICE'), 'warning', { sticky: true });
  }

  /* ---- Joomla editor API ------------------------------------------------ */

  getValue() {
    if (!this.active) {
      return this.original;
    }
    const html = this.lastHtml.replace(/ data-line="\d+"/g, '');
    return `${html}\n<div hidden data-gssmd-draft="v1:${toBase64(this.view.state.doc.toString())}"></div>`;
  }

  setValue(value) {
    const match = /data-gssmd(?:-draft)?="v1:(?:[0-9a-f]{16}:)?([A-Za-z0-9+/=]*)"/.exec(value || '');
    this.setDoc(match ? fromBase64(match[1]) : String(value || ''));
    this.activate();
  }

  getSelection() {
    const { from, to } = this.view.state.selection.main;
    return this.view.state.sliceDoc(from, to);
  }

  replaceSelection(value) {
    this.view.dispatch(this.view.state.replaceSelection(htmlSnippetToMarkdown(String(value))));
    this.sync();
  }

  setDisabled(disabled) {
    this.view.dispatch({ effects: this.compartments.editable.reconfigure(EditorView.editable.of(!disabled)) });
    this.editorPane.toggleAttribute('inert', disabled);
    this.classList.toggle('is-disabled', disabled);
    this.querySelectorAll('[data-format]').forEach((control) => { control.disabled = disabled; });
    if (disabled) this.closePopups();
  }

  sync() {
    clearTimeout(this.syncTimer);
    this.field.value = this.getValue();
  }

  scheduleSync() {
    clearTimeout(this.syncTimer);
    this.syncTimer = setTimeout(() => this.sync(), 300);
  }

  /* ---- Preview ------------------------------------------------------------ */

  /** Loads the front-end article layout the preview is rendered into. */
  loadFrame(refresh) {
    const url = new URL(this.options.frameUrl, window.location.href);
    url.searchParams.set('context', this.options.context || '');
    const item = this.postId();
    if (item) url.searchParams.set('item', String(item));
    if (refresh) url.searchParams.set('refresh', '1');

    this.previewSlot = null;
    this.usingBuiltin = false;
    this.frameNotice = '';
    this.setPreviewState('');
    this.frame.style.width = `${this.options.viewport}px`;
    this.frame.onload = () => this.onFrameLoad();
    this.frame.src = url.toString();
  }

  onFrameLoad() {
    let doc;
    try {
      doc = this.frame.contentDocument;
    } catch (e) {
      doc = null;
    }

    // A freshly inserted iframe first reports its initial about:blank document.
    if (doc && doc.URL === 'about:blank') {
      return;
    }

    // Not "slot": that name is a built-in HTMLElement property.
    this.previewSlot = doc ? doc.querySelector('[data-gssmd-slot]') : null;

    if (!this.previewSlot) {
      // The layout document is unusable (server error page, blocked request...). Keep the
      // live preview and the scroll sync working with a plain built-in document.
      if (!this.usingBuiltin) {
        this.usingBuiltin = true;
        this.frame.removeAttribute('src');
        this.frame.srcdoc = BUILTIN_FRAME;
      } else {
        this.setPreviewState(t('PREVIEW_FAILED'), 'error');
      }
      return;
    }

    const source = doc.querySelector('meta[name="gssmd-frame"]');
    this.frameNotice = source && source.content !== 'page' ? t('PREVIEW_FALLBACK') : '';
    this.setPreviewState('');

    const win = doc.defaultView;
    doc.head.append(Object.assign(doc.createElement('style'), { textContent: FRAME_HINT_CSS }));

    // The preview is not a browser: links must not navigate the frame away. A click on a
    // block jumps to its Markdown source instead.
    doc.addEventListener('click', (e) => {
      if (e.target.closest('a')) e.preventDefault();
      const block = this.blockAt(e.target);
      if (block) this.revealSource(block);
    }, true);
    doc.addEventListener('mouseover', (e) => this.hoverBlock(this.blockAt(e.target)));
    doc.addEventListener('mouseleave', () => this.hoverBlock(null));
    win.addEventListener('resize', () => this.fitFrame());
    win.addEventListener('scroll', () => this.onPaneScroll('preview'), { passive: true });
    ['wheel', 'touchstart', 'keydown', 'pointerdown'].forEach((type) => {
      win.addEventListener(type, () => { this.quiet.preview = 0; }, { passive: true });
    });
    // Images change block heights; keep the scroll mapping fresh.
    doc.addEventListener('load', () => this.requestScrollSync('editor'), true);

    this.paint();
  }

  schedulePreview() {
    clearTimeout(this.previewTimer);
    this.previewTimer = setTimeout(() => this.requestPreview(), this.options.debounce || 400);
  }

  async requestPreview() {
    const text = this.view.state.doc.toString();

    if (new TextEncoder().encode(text).length > this.options.previewMax) {
      this.setPreviewState(t('ERR_PREVIEW_TOO_LARGE', Math.round(this.options.previewMax / 1024)), 'error');
      return;
    }

    this.previewSeq += 1;
    const seq = this.previewSeq;

    if (this.previewAbort) this.previewAbort.abort();
    this.previewAbort = new AbortController();

    // Only show "updating" when it is slow, so typing does not make the pane flicker.
    clearTimeout(this.busyTimer);
    this.busyTimer = setTimeout(() => this.setPreviewState(t('PREVIEW_UPDATING'), 'busy'), 700);

    const body = new FormData();
    body.append('markdown', text);
    body.append('seq', String(seq));
    this.appendContext(body);
    body.append(this.options.token, '1');

    try {
      const response = await fetch(this.options.previewUrl, {
        method: 'POST', body, credentials: 'same-origin', signal: this.previewAbort.signal,
        headers: { 'X-CSRF-Token': this.options.token, 'X-Requested-With': 'XMLHttpRequest' },
      });
      const json = await response.json().catch(() => null);

      if (!json || !json.success) {
        throw new Error((json && json.error && json.error.message) || `HTTP ${response.status}`);
      }

      // A late answer to an older request must not overwrite a newer preview.
      if (json.data.seq < this.appliedSeq || seq !== this.previewSeq) {
        return;
      }

      this.appliedSeq = json.data.seq;
      this.lastHtml = json.data.html;
      clearTimeout(this.busyTimer);
      this.setPreviewState('');
      this.paint();
      this.sync();
    } catch (error) {
      if (error.name === 'AbortError') {
        return;
      }
      clearTimeout(this.busyTimer);
      // Keep the last good preview on screen; say it is stale.
      this.setPreviewState(t('PREVIEW_FAILED'), 'error');
    }
  }

  /** Writes the last rendered HTML into the article slot of the front-end layout. */
  paint() {
    if (!this.previewSlot) {
      return;
    }

    this.previewSlot.innerHTML = this.lastHtml;

    const win = this.frame.contentWindow;
    if (win && win.Prism) {
      this.previewSlot.querySelectorAll('pre.gssmd-code > code[class*="language-"]').forEach((code) => win.Prism.highlightElement(code));
    }

    this.hovered = null;
    this.fitFrame();
    this.requestScrollSync('editor');
    this.scheduleCursorLink();
  }

  /**
   * Two ways to show the site's article layout, both at 100% (never scaled):
   *
   *   fit (default)  the page is laid out at the pane width and the article column follows it,
   *                  like the classic side-by-side editors; text reflows.
   *   actual         the page is laid out at the site's desktop width, so the column has its
   *                  real width and lines break exactly as on the site; wider than the pane =
   *                  scroll sideways.
   */
  fitFrame() {
    if (!this.previewSlot || this.currentView === 'edit') {
      return;
    }

    const box = this.frameBox;
    const root = this.previewSlot.ownerDocument.documentElement;
    const reflow = this.prefs.zoom !== 'actual';

    root.classList.toggle('gssmd-reflow', reflow);
    this.frameScale = 1;

    if (reflow) {
      this.frameSizer.style.width = '';
      this.frame.style.width = `${box.clientWidth}px`;
      this.frame.style.height = `${box.clientHeight}px`;
      this.frame.style.transform = '';
      box.classList.remove('is-scrollable');
      this.setPreviewHint('');
      return;
    }

    const gutter = 24;
    const rect = this.previewSlot.getBoundingClientRect();
    const win = this.frame.contentWindow;
    const left = rect.left + (win ? win.scrollX : 0);
    const width = Math.max(1, rect.width);

    if (!this.prefs.ratio && Math.abs((this.columnWidth || 0) - width) > 1) {
      this.columnWidth = width;
      this.applyLayout(); // automatic split now knows the column width
    }

    const available = box.clientWidth;
    const offset = Math.max(gutter, (available - width) / 2);
    const tooNarrow = width + gutter * 2 > available;

    this.frameSizer.style.width = `${Math.ceil(offset * 2 + width)}px`;
    this.frame.style.width = `${this.options.viewport}px`;
    this.frame.style.height = `${box.clientHeight}px`;
    this.frame.style.transform = `translateX(${offset - left}px)`;
    box.classList.toggle('is-scrollable', tooNarrow);
    this.setPreviewHint(tooNarrow ? t('PREVIEW_TOO_NARROW', Math.round(width)) : '');
  }

  /* ---- Scroll sync and linking hints ------------------------------------ */

  setScrollSync(on) {
    this.prefs.scrollSync = on;
    this.syncButton.setAttribute('aria-checked', String(on));
    this.panes.dataset.sync = on ? 'on' : 'off';
    this.savePrefs();
    if (on) this.requestScrollSync('editor');
  }

  onPaneScroll(source) {
    // Ignore the scroll event our own sync caused on this pane (no ping-pong).
    if (performance.now() < this.quiet[source]) return;
    this.requestScrollSync(source);
  }

  requestScrollSync(source) {
    if (!this.prefs.scrollSync || !this.previewSlot || this.currentView !== 'split') return;
    this.syncSource = source;
    if (this.scrollFrame) return;
    // Next frame, or 32 ms at the latest (browsers throttle animation frames in some panes).
    const run = () => {
      if (!this.scrollFrame) return;
      cancelAnimationFrame(this.scrollFrame.raf);
      clearTimeout(this.scrollFrame.timer);
      this.scrollFrame = null;
      this.applyScrollSync(this.syncSource);
    };
    this.scrollFrame = { raf: requestAnimationFrame(run), timer: setTimeout(run, 32) };
  }

  /**
   * Pairs of [editor scrollTop, preview scrollY] that show the same top-level block at the
   * top of both panes, plus both ends. Scrolling either way interpolates between them, so
   * the two directions use exactly the same mapping and tall images / long code blocks
   * stay aligned (unlike a percentage).
   */
  scrollPairs() {
    const win = this.frame.contentWindow;
    const { doc } = this.view.state;
    const scroller = this.view.scrollDOM;
    const eMax = Math.max(0, scroller.scrollHeight - scroller.clientHeight);
    const pMax = Math.max(0, win.document.documentElement.scrollHeight - win.innerHeight);
    const pairs = [[0, 0]];

    this.previewSlot.querySelectorAll(':scope > [data-line]').forEach((node) => {
      const line = parseInt(node.dataset.line, 10);
      if (!line || line > doc.lines) return;
      const e = this.view.lineBlockAt(doc.line(line).from).top;
      const p = node.getBoundingClientRect().top + win.scrollY - 16;
      const last = pairs[pairs.length - 1];
      if (e > last[0] && p > last[1] && e < eMax && p < pMax) pairs.push([e, p]);
    });

    pairs.push([Math.max(eMax, pairs[pairs.length - 1][0] + 1), Math.max(pMax, pairs[pairs.length - 1][1])]);
    return pairs;
  }

  static interpolate(pairs, x, from, to) {
    for (let i = 1; i < pairs.length; i += 1) {
      const a = pairs[i - 1];
      const b = pairs[i];
      if (x <= b[from] || i === pairs.length - 1) {
        const span = b[from] - a[from];
        const ratio = span > 0 ? Math.min(1, Math.max(0, (x - a[from]) / span)) : 0;
        return a[to] + (b[to] - a[to]) * ratio;
      }
    }
    return 0;
  }

  applyScrollSync(source) {
    const win = this.frame.contentWindow;
    if (!win || !this.previewSlot) return;

    const pairs = this.scrollPairs();
    const until = performance.now() + 150;

    if (source === 'editor') {
      const y = JoomlaEditorGssMarkdown.interpolate(pairs, this.view.scrollDOM.scrollTop, 0, 1);
      if (Math.abs(win.scrollY - y) < 1) return;
      this.quiet.preview = until;
      win.scrollTo(0, y);
    } else {
      const y = JoomlaEditorGssMarkdown.interpolate(pairs, win.scrollY, 1, 0);
      if (Math.abs(this.view.scrollDOM.scrollTop - y) < 1) return;
      this.quiet.editor = until;
      this.view.scrollDOM.scrollTop = y;
    }
  }

  /** The top-level preview block containing a node, if any. */
  blockAt(node) {
    const element = node && node.nodeType === 1 ? node : node && node.parentElement;
    const block = element ? element.closest('[data-gssmd-slot] > [data-line]') : null;
    return block && block.parentElement === this.previewSlot ? block : null;
  }

  /** Source lines [from, to] of a preview block: up to the next block, without trailing blank lines. */
  sourceRange(block) {
    const { doc } = this.view.state;
    const from = Math.min(parseInt(block.dataset.line, 10), doc.lines);
    let next = block.nextElementSibling;
    while (next && !next.dataset.line) next = next.nextElementSibling;
    let to = next ? Math.min(parseInt(next.dataset.line, 10) - 1, doc.lines) : doc.lines;
    while (to > from && doc.line(to).text.trim() === '') to -= 1;
    return { from, to };
  }

  /** Hovering a preview block highlights its Markdown lines. */
  hoverBlock(block) {
    if (block === this.hovered) return;
    if (this.hovered) this.hovered.removeAttribute('data-gssmd-hover');
    this.hovered = block;
    if (block) block.setAttribute('data-gssmd-hover', '');
    this.view.dispatch({ effects: hoverLines.effect.of(block ? this.sourceRange(block) : null) });
  }

  /** Clicking a preview block puts the cursor on its first source line. */
  revealSource(block) {
    const { from } = this.sourceRange(block);
    const pos = this.view.state.doc.line(from).from;
    this.quiet.preview = performance.now() + 400; // keep the preview where the author clicked
    this.view.dispatch({ selection: { anchor: pos }, effects: EditorView.scrollIntoView(pos, { y: 'start', yMargin: 24 }) });
    this.view.focus();
  }

  /**
   * Marks the block being edited on both sides (yellow): its Markdown lines in the editor and
   * the rendered block in the preview. While typing, the preview brings that block into view
   * at the same height as the cursor, so it is always clear what is being edited.
   */
  scheduleCursorLink(reveal = false) {
    clearTimeout(this.cursorTimer);
    // A later call (e.g. after the preview re-render) must not cancel a pending reveal.
    this.pendingReveal = this.pendingReveal || reveal;
    reveal = this.pendingReveal;
    this.cursorTimer = setTimeout(() => {
      if (!this.previewSlot) return;
      const { state } = this.view;
      const head = state.selection.main.head;
      const line = state.doc.lineAt(head).number;
      let current = null;
      this.previewSlot.querySelectorAll(':scope > [data-line]').forEach((node) => {
        if (parseInt(node.dataset.line, 10) <= line) current = node;
      });

      const previous = this.previewSlot.querySelector(':scope > [data-gssmd-linked]');
      if (previous && previous !== current) previous.removeAttribute('data-gssmd-linked');
      if (current) current.setAttribute('data-gssmd-linked', '');

      const range = current ? this.sourceRange(current) : null;
      const key = range ? `${range.from}-${range.to}` : '';
      if (key !== this.currentKey) {
        this.currentKey = key;
        this.view.dispatch({ effects: currentLines.effect.of(range) });
      }

      this.pendingReveal = false;
      if (reveal && current && this.prefs.scrollSync && this.currentView === 'split') {
        this.revealInPreview(current, head);
      }
    }, 80);
  }

  /** Scrolls the preview so the edited block sits where the cursor sits in the editor. */
  revealInPreview(block, head) {
    const win = this.frame.contentWindow;
    if (!win) return;
    const rect = block.getBoundingClientRect();
    const viewport = win.innerHeight;
    if (rect.top >= 0 && rect.bottom <= viewport) return; // already fully visible

    const scroller = this.view.scrollDOM;
    const cursorY = this.view.lineBlockAt(head).top - scroller.scrollTop;
    const ratio = Math.min(0.8, Math.max(0.1, cursorY / Math.max(1, scroller.clientHeight)));
    const target = rect.top + win.scrollY - Math.min(ratio * viewport, Math.max(16, viewport - rect.height - 16));

    this.quiet.preview = performance.now() + 250;
    win.scrollTo(0, Math.max(0, target));
  }

  setPreviewState(message, kind = '') {
    // A layout problem stays visible until the frame is reloaded; routine states return to it.
    if (!message && this.frameNotice) {
      message = this.frameNotice;
      kind = 'warning';
    }
    this.previewState.textContent = message;
    this.previewState.dataset.kind = kind;
  }

  /** Low-priority hint, shown only when there is no state message. */
  setPreviewHint(message) {
    if (this.previewState.dataset.kind && this.previewState.dataset.kind !== 'hint') return;
    this.setPreviewState(message, message ? 'hint' : '');
  }

  /* ---- Copy / paste / save as .md -------------------------------------- */

  async copyMarkdown() {
    const text = this.view.state.doc.toString();
    try {
      await navigator.clipboard.writeText(text);
    } catch (e) {
      // Older browsers / insecure origins: copy through a temporary textarea.
      const area = el('textarea', { style: 'position:fixed;opacity:0;top:0;left:0', 'aria-hidden': 'true' });
      area.value = text;
      document.body.append(area);
      area.select();
      const ok = document.execCommand('copy');
      area.remove();
      if (!ok) {
        this.notify(t('MD_COPY_FAILED'), 'danger');
        return;
      }
    }
    this.notify(t('MD_COPIED'), 'success');
  }

  /**
   * Pastes the clipboard at the cursor: images are uploaded like a normal paste, text is
   * inserted as is (converted when it is an HTML snippet).
   */
  async pasteClipboard() {
    if (!this.active) return;
    try {
      if (navigator.clipboard.read) {
        const items = await navigator.clipboard.read();
        const images = [];
        for (const item of items) {
          const type = item.types.find((ty) => ty.startsWith('image/'));
          if (type) {
            const blob = await item.getType(type);
            images.push(new File([blob], `image.${IMAGE_TYPES[type] || 'png'}`, { type }));
          }
        }
        if (images.length) {
          this.uploadFiles(images, null);
          return;
        }
      }
      const text = await navigator.clipboard.readText();
      if (text) {
        this.view.dispatch(this.view.state.replaceSelection(htmlSnippetToMarkdown(text)), { scrollIntoView: true });
        this.view.focus();
      }
    } catch (e) {
      // Clipboard access denied (browser setting or permission prompt dismissed).
      this.notify(t('MD_PASTE_DENIED', IS_MAC ? '⌘V' : 'Ctrl+V'), 'info');
      this.view.focus();
    }
  }

  /** Downloads the Markdown source, named after the article title when there is one. */
  saveMarkdown() {
    const form = this.closest('form') || document;
    const titleField = form.querySelector('[name="jform[title]"], [name="title"], [data-composer-field-title], input#title');
    const title = (titleField && titleField.value ? titleField.value : 'article').trim();
    const name = `${title.replace(/[\\/:*?"<>|\u0000-\u001f]+/g, '-').replace(/\s+/g, ' ').slice(0, 80) || 'article'}.md`;

    const blob = new Blob([this.view.state.doc.toString()], { type: 'text/markdown;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = el('a', { href: url, download: name, style: 'display:none' });
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  /* ---- Images ------------------------------------------------------------ */

  onPaste(event) {
    const files = [...(event.clipboardData ? event.clipboardData.files : [])].filter((f) => f.type.startsWith('image/'));
    if (!files.length) return false;
    event.preventDefault();
    this.uploadFiles(files, null);
    return true;
  }

  onDrop(event, view) {
    const all = [...(event.dataTransfer ? event.dataTransfer.files : [])];
    const htmlFile = all.find(isHtmlFile);
    if (htmlFile && !all.some((f) => f.type.startsWith('image/'))) {
      // A web page file dropped on the editor: convert it in the dialog, insert where it was dropped.
      event.preventDefault();
      const pos = view.posAtCoords({ x: event.clientX, y: event.clientY });
      if (pos !== null) view.dispatch({ selection: { anchor: pos } });
      this.openHtmlDialog(htmlFile);
      return true;
    }
    const files = all.filter((f) => f.type.startsWith('image/'));
    if (!files.length) return false;
    event.preventDefault();
    const pos = view.posAtCoords({ x: event.clientX, y: event.clientY });
    this.uploadFiles(files, pos);
    return true;
  }

  /**
   * Inserts one placeholder per image at once, then uploads them in parallel. Each result
   * replaces its own placeholder, found by id through position mapping.
   */
  uploadFiles(files, pos) {
    if (!files.length || !this.active) return;

    const at = pos ?? this.view.state.selection.main.head;
    const items = files.map((file) => {
      this.uploadSeq += 1;
      const id = `${Date.now().toString(36)}-${this.uploadSeq}`;
      const name = this.fileName(file);
      return { id, file, name, text: `![${t('UPLOADING')} ${name.replace(/[[\]()]/g, '')}](uploading:${id})` };
    });

    let insert = items.map((i) => i.text).join('\n\n');
    const line = this.view.state.doc.lineAt(at);
    const before = at > line.from ? '\n\n' : '';
    const after = at < line.to ? '\n\n' : '';
    insert = before + insert + after;

    this.view.dispatch({ changes: { from: at, insert }, selection: { anchor: at + insert.length }, scrollIntoView: true });
    this.view.focus();

    // Registered after the insert, so onUpdate() only maps them through LATER edits.
    let offset = at + before.length;
    items.forEach((item) => {
      this.pending.set(item.id, { from: offset, to: offset + item.text.length, text: item.text });
      offset += item.text.length + 2;
    });

    items.forEach((item) => this.uploadOne(item));
  }

  async uploadOne(item) {
    const label = item.name.replace(/[[\]()]/g, '');

    if (item.file.size > this.options.maxSize) {
      this.replacePlaceholder(item.id, '');
      this.notify(t('UPLOAD_FAILED', label) + t('ERR_FILE_TOO_LARGE', Math.round(this.options.maxSize / 1048576)), 'danger', { sticky: true });
      return;
    }

    try {
      const data = await this.send(item.file, item.name);
      const alt = label.replace(/\.[^.]+$/, '');
      if (!this.replacePlaceholder(item.id, `![${alt}](${data.url})`)) {
        this.view.dispatch(this.view.state.replaceSelection(`![${alt}](${data.url})`));
        this.notify(t('PLACEHOLDER_GONE'), 'info');
      }
    } catch (error) {
      this.replacePlaceholder(item.id, '');
      // Never fail silently: the author must know the image is not in the article.
      this.notify(`${t('UPLOAD_FAILED', label)}${error.message}`, 'danger', { sticky: true });
    }
  }

  /**
   * @returns {boolean} false when the placeholder no longer exists
   */
  replacePlaceholder(id, replacement) {
    const p = this.pending.get(id);
    this.pending.delete(id);
    if (!p) return false;

    const doc = this.view.state.doc;
    let { from, to } = p;

    if (to <= from || doc.sliceString(from, to) !== p.text) {
      // The author edited around it: fall back to the unique id marker.
      const index = doc.toString().indexOf(`(uploading:${id})`);
      if (index === -1) return false;
      const start = doc.toString().lastIndexOf('![', index);
      if (start === -1) return false;
      from = start;
      to = index + `(uploading:${id})`.length;
    }

    // A removed placeholder should not leave an empty paragraph gap behind.
    if (replacement === '' && doc.sliceString(to, to + 2) === '\n\n') {
      to += 2;
    }

    this.view.dispatch({ changes: { from, to, insert: replacement } });
    return true;
  }

  async send(file, name) {
    const body = new FormData();
    body.append('file', file, name);
    body.append('context', this.options.context || '');
    body.append(this.options.token, '1');
    body.append('draft_key', this.options.draftKey);
    const postId = this.postId();
    if (postId > 0) body.append('post_id', String(postId));

    let response;
    try {
      response = await fetch(this.options.uploadUrl, {
        method: 'POST', body, credentials: 'same-origin',
        headers: { 'X-CSRF-Token': this.options.token, 'X-Requested-With': 'XMLHttpRequest' },
      });
    } catch (e) {
      throw new Error(t('ERR_NETWORK'));
    }

    const json = await response.json().catch(() => null);
    if (!json || !json.success) {
      throw new Error((json && json.error && json.error.message) || t('ERR_RESPONSE', response.status));
    }
    return json.data;
  }

  /** Item id when the page knows it; otherwise uploads go to the temp folder. */
  /** Where the editor runs: lets the server check the author's rights for this item. */
  appendContext(body) {
    body.append('context', this.options.context || '');
    const postId = this.postId();
    if (postId > 0) body.append('post_id', String(postId));
  }

  postId() {
    const form = this.closest('form') || document;
    let value = '';
    if (this.options.context === 'com_content') {
      value = (form.querySelector('[name="jform[id]"]') || {}).value || '';
    } else if (this.options.context === 'com_easyblog') {
      const uid = document.querySelector('[data-eb-composer-post-uid]') || form.querySelector('[name="uid"]');
      value = uid ? String(uid.value).split('.')[0] : '';
    }
    return parseInt(value, 10) || 0;
  }

  fileName(file) {
    const ext = IMAGE_TYPES[file.type] || 'png';
    const name = file.name && file.name !== 'blob' ? file.name : `image.${ext}`;
    return /\.(png|jpe?g|gif|webp|svg)$/i.test(name) ? name : `${name}.${ext}`;
  }

  /* ---- Notices / gates ---------------------------------------------------- */

  notify(message, type = 'info', { sticky = false } = {}) {
    const close = () => item.remove();
    const item = el('div', { class: `gssmd-message alert alert-${type}`, role: type === 'danger' ? 'alert' : 'status' },
      el('span', {}, message),
      sticky ? el('button', { type: 'button', class: 'gssmd-close', 'aria-label': t('DISMISS'), title: t('DISMISS'), onclick: close }, '×') : null);
    if (!sticky) setTimeout(close, 5000);
    this.noticeArea.append(item);
    return { close };
  }

  lock(message, actions) {
    this.active = false;
    this.setDisabled(true);
    this.gate = el('div', { class: 'gssmd-gate alert alert-warning', role: 'alertdialog' },
      el('p', {}, message),
      ...actions.map(({ label, confirm, run }) => el('button', {
        type: 'button', class: 'btn btn-sm btn-secondary', onclick: () => { if (!confirm || window.confirm(confirm)) run(); },
      }, label)));
    this.noticeArea.after(this.gate);
  }

  unlock() {
    if (this.gate) {
      this.gate.remove();
      this.gate = null;
    }
    this.setDisabled(false);
  }

  reloadWith(use) {
    if (!window.confirm(t('RELOAD_CONFIRM'))) return;
    const url = new URL(window.location.href);
    url.searchParams.set('gssmd_use', use);
    window.location.href = url.toString();
  }
}

customElements.define('joomla-editor-gssmarkdown', JoomlaEditorGssMarkdown);
