# GSS Markdown for Joomla 5

A Markdown-first editor for Joomla 5.2+ and EasyBlog, for authors who already write Markdown.

**[⬇ Download the latest version](https://github.com/desbear/joomla-markdown-editor/releases/latest)** (`pkg_gssmarkdown-x.y.z.zip` under *Assets*) · [Install](#install)



- **Source on the left, preview on the right.** The left pane is CodeMirror 6 with Markdown highlighting; the preview is rendered on the server by the same code that renders the saved article.
- **Formatting toolbar and shortcuts** that write Markdown (see below), so nobody has to remember the syntax
- **Two-way scroll sync with visible links:** scrolling either pane moves the other; hovering a preview block highlights its Markdown; clicking it jumps to it
- **The preview looks like the published page.** It is rendered inside the site's own article layout (stylesheets, containers, column width), so line breaks match the site.
- **Videos:** a YouTube, Vimeo, Dailymotion or Bilibili URL, or a video file (mp4, webm, ogv, m4v, mov), on its own line becomes a video, in the preview and on the site
- **Images:** paste or drop them, clipboard screenshots included. Several at once are fine. No media manager involved.
- **Code highlighting** in the editor and on the site
- **Lossless round-trip:** the Markdown source is stored with the article
- **Existing HTML articles are never converted silently**

Nothing is loaded from a CDN. CodeMirror 6 is **not bundled**: it is the copy Joomla core ships for `plg_editors_codemirror` (import map entries `@codemirror/*`, `@lezer/*`), so it gets security fixes with Joomla updates.

## Requirements

- Joomla 5.2+ (editor-provider API `onEditorSetup`; core CodeMirror 6 assets). Developed against 5.3.3.
- PHP 8.1+ with `fileinfo`, `gd` and `dom`; `intl` recommended

## Download

Get the installable package from **[Releases](https://github.com/desbear/joomla-markdown-editor/releases/latest)**: under **Assets**, download `pkg_gssmarkdown-x.y.z.zip`.

Do not use GitHub's green **Code → Download ZIP** button or the *Source code* archives: those contain the repository (sources, tests, build tools), not an installable Joomla package.

## Install

1. Install `pkg_gssmarkdown-x.y.z.zip` via **System → Install → Extensions → Upload Package File**. The three plugins are enabled; the editor is not made anyone's default.
2. Choose **Editor - GSS Markdown** per author (**Edit profile → Basic settings → Editor**) or globally.
3. EasyBlog: set the composer to use the **Joomla editor**.
4. Open **System - GSS Markdown → Preview** and check that the preview shows the site layout (see below).

| Extension | Purpose |
|---|---|
| `plg_editors_gssmarkdown` | Editor, and the endpoints `gssmarkdownupload`, `gssmarkdownpreview`, `gssmarkdownprefs`, `gssmarkdownconvert` (com_ajax, group `editors`) |
| `plg_system_gssmarkdown` | Renders what the editor submits, moves images after the first save, loads the code highlighter on the site. **Must stay enabled**; without it the editor falls back to JCE. |
| `plg_task_gssmarkdown` | Optional scheduled cleanup of images of drafts that were never saved |
| `lib_gssmarkdown` | Shared code, `league/commonmark`, `league/html-to-markdown` |

## How the preview matches the site

1. **Same parser.** `MarkdownRenderer` is the only Markdown → HTML entry point. The preview endpoint calls `renderPreview()`, which is `render()` plus a `data-line` attribute on top-level blocks for scroll sync. The save path calls `render()`. Both use the same CommonMark + GFM configuration and the same `InputFilter` allow-list. `tests/Unit/ParserConsistencyTest.php` checks a full GFM document (`tests/Fixtures/gfm-full.md`) through the real save path.
2. **Same styles.** The preview frame is built from a real published article page, fetched by the server:
   - the article being edited if it is published, otherwise the latest public article
   - kept: every stylesheet and `<style>` of that page, and the container chain down to the article body (`[data-blog-content]` for EasyBlog)
   - emptied: header, sidebars, footer and modules become empty, zero-height boxes that keep their classes, so grid and flex columns keep their widths
   - dropped: scripts and media

   The Markdown HTML is written into the article body element. The frame is rendered at a desktop viewport width and shifted to the article column, shown at 100%.
3. **No personal influence.** Personal settings (theme, font, size, line numbers, pane ratio, view, scroll sync, preview size) are stored in the user's parameters. Only the preview size (100% or fit) touches the preview, and it magnifies without changing the layout. How the preview looks is set site-wide by administrators.

The layout is cached (default 6 h); authors can reload it. If the server cannot reach the site's public address (Docker, reverse proxy), set *Internal site URL for fetching the layout* (e.g. `http://localhost/`). If the layout still cannot be loaded, the preview keeps working with a plain built-in document and says so in the status bar. When the template changes, the next refresh picks it up with no plugin change. If no article page can be used, the preview falls back to configured stylesheets and says so.

**Not in the preview**, as the preview pane tells the author: template header, sidebars and footer; content made by plugins at display time (EasyBlog shortcodes, `{source}`, …); lazy loading and image processing.

## Editor layout

- **Toolbar** (one row of icons): heading menu · bold · italic · strikethrough | horizontal rule · quote | bulleted · numbered · task list · indent · outdent | table · image · link | inline code · code block menu (language list). On the right: the **Scroll** switch (sync scrolling) and the editor settings (theme, font, size, line numbers, preview size, reload of the site layout).
- **Markdown pane corner**: three small buttons — copy all Markdown, paste at the cursor (images are uploaded like a normal paste; if the browser blocks clipboard access, the editor says to use Ctrl/⌘+V), and save as `{article title}.md`.
- **Bottom bar**: the scope note and preview status on the left; **Edit / Side by side / Preview** tabs on the right.
- **Preview at 100%, never scaled.** Two widths (editor settings → *Preview width*):
  - *Fit the pane* (default): the site's article layout is laid out at the pane width and the text column follows it, so text reflows like in classic side-by-side editors.
  - *Site's column width*: the column keeps its real front-end width, so line breaks are identical to the site; the pane scrolls sideways if it is narrower, and the split sizes itself to give the preview that width.
- Side by side stays available down to about 600px editor width (e.g. the EasyBlog composer column); below that, one pane at a time. On narrow editors the toolbar compacts to one row.
- **The preview updates by itself** about 0.3 s after you stop typing; no page reload is needed. *Reload the site layout* in the settings is only needed after the site template changes.

| Control | Shortcut | Result |
|---|---|---|
| Heading menu: paragraph, heading 1–6 | Mod+Alt+0 … 6 | `## ` prefix on the selected lines; the icon shows the current level |
| Bold / Italic / Strikethrough / Inline code | Mod+B / Mod+I / Mod+Shift+X / Mod+E | wraps the selection, or inserts a selected placeholder; again = unwrap |
| Link | Mod+K | `[text](https://)`, URL selected; a selected URL becomes the link target |
| Bulleted / numbered / task list | Mod+Shift+8 / 7 / 9 | converts between list types, keeps nesting; again = remove |
| Indent / outdent | Tab / Shift+Tab | nests list items |
| Quote | Mod+Shift+. | `> ` on the selected lines; again = remove |
| Code block menu | Mod+Alt+C | fenced block with the chosen language, on its own lines, wrapping the selection |
| Video | | pop-up asking for the URL (sites or video files) and an optional title; inserts it on its own line |
| Table, horizontal rule, image | | table template / `---` / file picker (uploads like paste) |
| Convert HTML (last button, `HTML`) | Mod+Enter inserts | dialog: paste HTML source, or content copied from a web page / Word (the formatted version is taken from the clipboard), or choose / drop an `.html` file (also by dropping it on the editor, which inserts at the drop point). Files are read in the browser, not uploaded: the declared charset is honoured (Big5 Word pages work), only `<body>` is used, and images with local paths are counted in a warning; the server converts it with the legacy-article converter, the editable Markdown appears beside it, and *Insert* puts it at the cursor (one undo step). Removed elements (`<script>`, `<style>`, `<form>`, non-video `<iframe>`…) are listed. Pasting straight into the editor still pastes text as is, so HTML code examples in code blocks are unaffected |

Every control edits the Markdown text (`media/js/format.js`); none produces HTML. Mod = ⌘ on macOS, Ctrl elsewhere.

## Scroll sync and "what am I editing"

- **The block being edited is yellow on both sides:** its Markdown lines in the editor and the rendered block in the preview. While you type or move the cursor, if that block is out of view in the preview, the preview scrolls to show it at about the height of the cursor.
- **Two-way scroll sync** (the *Scroll* switch, a personal preference, on by default): scrolling either pane scrolls the other. One mapping serves both directions: pairs of positions where the same top-level block (`data-line`) is at the top of both panes, interpolated in between. It stays aligned around tall images and long code blocks, and a round trip returns to the same place. Scroll events caused by the sync itself are ignored, so the panes never bounce.
- **Hover** a preview block: dashed outline, and its Markdown lines are highlighted in blue. **Click** it: the cursor jumps to its first line.
- Highlights use background colour and outlines only, so they never change the layout.

## Videos

Markdown has no video syntax, and a display-time content plugin would not show up in the split preview. So videos are part of the renderer, the same code path as the preview and the saved article:

| Markdown | Result |
|---|---|
| `https://www.youtube.com/watch?v=ID` alone on its line (also `youtu.be/…`, `/shorts/…`, `/live/…`, `/embed/…`, `<URL>`) | YouTube video |
| `https://vimeo.com/ID` (also `/channels/…/ID`, unlisted `ID/hash`, `player.vimeo.com/video/ID`, `#t=1m30s`) | Vimeo video |
| `https://www.dailymotion.com/video/ID` (also `dai.ly/ID`, `?start=90`) | Dailymotion video |
| `https://www.bilibili.com/video/BV…` (also `av…`, `?p=2`, `?t=90`) | Bilibili video |
| `![Title](https://youtu.be/ID?t=90)` | video titled "Title", starting at 1:30 (any site) |
| `![Title](images/videos/intro.mp4)` / `![](</images/a b.mp4>)` / `https://cdn.example.com/a.webm` | video file (mp4, webm, ogv, m4v, mov); a path needs the image syntax |
| a video link inside a sentence, or `[text](url)` | normal link |

The toolbar's video button asks for the URL (or file path) and an optional title and writes the right form.

- **Stored as a marker, not an iframe:** `<div class="gssmd-video" data-youtube="ID" data-start="90">` for YouTube (unchanged since 2.5), `<div class="gssmd-video" data-video="{canonical URL}">` for the other sites and files, each with a fallback `<a href>`. EasyBlog's HTML filter and Joomla's text filters strip iframes for most user groups on save; the marker survives them. Without the plugin, readers see a plain link.
- **Player added where it is shown:** the editor preview endpoint and `plg_system_gssmarkdown` on site pages turn markers into the player (`youtube-nocookie.com`, Vimeo with `dnt=1`, Dailymotion, Bilibili; `<video controls>` for files): lazy loading, 16:9, strict referrer. The stored URL is parsed again on every expansion and the player URL is rebuilt from the id on a fixed host, so a forged marker cannot load anything else. Relative file paths are resolved from the site root.
- **Click to enlarge on the site:** with the companion GSS Lightbox system plugin installed, every video (markers, old iframes, `<video>` files) shows as a thumbnail and plays enlarged in a lightbox.
- **Legacy conversion keeps videos:** a supported player iframe or a `<video>` file in an old article becomes a URL line (files: `![](<path>)`), and so a video again.
- Video files are not uploaded through the editor (images only); put them in the media manager and insert the path.
- If the site sends a `Content-Security-Policy` with `frame-src`, allow `https://www.youtube-nocookie.com https://player.vimeo.com https://www.dailymotion.com https://player.bilibili.com`.

## Storage format

```html
<h1>Title</h1><p>…</p>
<div class="gssmd-source" hidden="hidden" data-gssmd="v1:{fingerprint}:{base64 markdown}"></div>
```

- The source is stored in a data attribute, not an HTML comment. Joomla's `InputFilter` removes comments, and EasyBlog runs it (`filterHtml`) after content plugins. The comment format `<!--GSSMD:v1:base64:…-->` is still read.
- The fingerprint is a hash of the HTML's visible text. When someone edits the article in JCE, the editor opens locked and asks: use the Markdown source, convert the current HTML (lossy, warned), or edit in JCE.
- The editor submits `<div hidden data-gssmd-draft="v1:{base64}">`. The system plugin replaces it with server-rendered HTML before the component sees the request. Saves from other editors are never touched.
- No database schema changes. With the plugins disabled, articles are plain HTML that any editor opens.


## Security

Reviewed 2026-10 (2.8.2). What protects what:

| Area | Protection |
|---|---|
| Stored HTML | Always rendered on the server from the submitted Markdown (`onAfterRoute`); HTML sent by the browser is discarded. Raw HTML in Markdown is escaped by default; in whitelist mode it passes Joomla's `InputFilter` with a fixed tag / attribute list (no `style`, no event handlers, no `iframe` / `script` / `form` ever). Links with `javascript:` and similar schemes are removed. The component's own text filters still run afterwards. |
| Videos | Only a marker is stored. The player URL is rebuilt from the parsed id on a fixed list of hosts each time it is shown, so a forged marker cannot load another site. |
| Uploads | Logged-in users with create / edit rights for the item only (EasyBlog's own ACL for posts), CSRF token, extension + `finfo` + `getimagesize` must agree, SVG off by default and sanitised when on, size and per-folder quotas, random file prefix, names reduced to `[A-Za-z0-9_-]` (no inner dots, so `x.php.png` cannot be created), `Path::check` on every path. Unsaved images are served only to their uploader, with `nosniff` and a sandbox CSP. |
| Preview, preview layout, HTML conversion | CSRF token (POST), and only users who may write content somewhere (`Services::canUseEditor()`): back-end users, create / edit rights, EasyBlog authors, or edit rights on the item being edited. Size limit `preview_max_kb`. The preview document is served with `script-src 'self'`. |
| Anonymous requests | A visitor who is not logged in can only trigger server rendering up to `preview_max_kb`; larger submissions are left untouched. |
| Database | Query builder with bound parameters everywhere; no EasyBlog or core table structure is changed. |

Deployment notes:

- **nginx / IIS without web.config support:** the temp folder is protected by `.htaccess` / `web.config`, which nginx ignores. File names are unguessable (random UUID folder + random prefix), but add a rule to be strict: `location ^~ /images/easyblog_articles/.tmp/ { deny all; }` (adjust to *Temporary folder*).
- **Set `$live_site` in `configuration.php`** (or *Preview fetch base URL* in the system plugin) on servers that answer for any `Host` header. The preview layout is fetched from the site's own address, which Joomla derives from the request's `Host` header when `$live_site` is empty; an author sending a forged header could otherwise make the server request `index.php` on another host.
- Uploaded images are stored as uploaded: EXIF data (camera, GPS position) is not removed.
- There is no rate limit on the endpoints; they are limited by login, permissions and size only.

## Images

| Situation | Stored in |
|---|---|
| EasyBlog post | `images/easyblog_articles/{post_id}/{hash8}_{name}.{ext}` |
| Joomla article | `images/gssmd/com_content/{id}/…` |
| Before the first save (incl. EasyBlog's blank post) | `images/easyblog_articles/.tmp/{draft_key}/`. Web access is denied; the uploader sees the images through the endpoint. They are moved after the save and every stored copy (EasyBlog revisions included) is rewritten. |
| Other components | `images/gssmd/{option}/{yyyy-mm}/` |

Each pasted or dropped image gets a `![上傳中 name](uploading:{id})` placeholder. The upload result replaces that placeholder, which is tracked through edits by position mapping, so parallel uploads and typing during uploads are safe. Failures remove the placeholder and show a message that stays until dismissed.

## Endpoints

| Plugin | Method | Request | Response |
|---|---|---|---|
| `gssmarkdownpreview&format=json` | POST | `markdown`, `seq`, token | `{"success":true,"data":{"html","seq"}}`; 413 above the size limit (1 MB) |
| `gssmarkdownpreview&format=raw&frame=1` | GET | `context`, `item`, `refresh` | preview document |
| `gssmarkdownupload&format=json` | POST | `file`, `context`, `post_id` or `draft_key`, token | `{"success":true,"data":{url,filename,width,height,size}}`; errors `{"success":false,"error":{code,message}}` |
| `gssmarkdownprefs&format=json` | POST | `prefs` (JSON), token | saved preferences |
| `gssmarkdownconvert&format=json` | POST | `html`, `seq`, token | `{markdown, dropped: [tag…], seq}` (same converter as legacy articles; size limit = preview limit) |

All require a logged-in user. POSTs need the Joomla token in the `X-CSRF-Token` header (or as the token form field). Upload error codes: `NO_AUTH`, `INVALID_TOKEN`, `FILE_TOO_LARGE`, `INVALID_TYPE`, `WRITE_FAILED`, `QUOTA_EXCEEDED`, `BAD_REQUEST`.

## Security

- CSRF on every POST; item-level permissions for uploads (EasyBlog `canEdit()` / `add_entry`, Joomla `core.edit` / `core.edit.own` / `core.create`)
- Extension, `finfo` MIME and `getimagesize` type must agree. SVG is off by default and always sanitised when on.
- Paths go through `Joomla\Filesystem\Path::check()`; draft keys are UUID v4 only
- Inline HTML in Markdown is escaped by default. The allow-list mode keeps only listed tags with safe attributes; `script`, `style`, `iframe`, `form`, `svg` etc. are never allowed.
- The preview server fetch only goes to the site's own host. The frame contains no page scripts and is served with `script-src 'self'`.

## Known limits

- The "Read more" separator and editor-xtd buttons are not supported. HTML snippets from EasyBlog's media manager (images, links) are converted to Markdown.
- There is no export of the Markdown sources to files.
- Fetching the layout page counts as one page view of that article per cache period.
- A Joomla text filter that removes `div` / `data-*` for a user group would drop that group's Markdown source.
- Joomla's version history keeps temp image URLs of versions saved before the first move.

## Troubleshooting

- **Editor area stays blank / shows "The Markdown editor did not start":** the browser ran an outdated cached script. Force-reload (Ctrl+Shift+R / ⌘+Shift+R). Since 2.5.1 every script of the editor is versioned through Joomla's import map, so an update can no longer mix old and new files.

## Development

```bash
composer install                                        # PHPUnit
(cd src/libraries/gssmarkdown && composer install --no-dev)
JOOMLA_ROOT=/path/to/joomla-5.3 vendor/bin/phpunit      # reuses Joomla's libraries/vendor
(cd tools/assets && npm ci && npm run build)            # Prism bundle for site + preview
tools/build.sh                                          # -> dist/pkg_gssmarkdown-x.y.z.zip
```

`tools/assets/package-lock.json` and `src/libraries/gssmarkdown/composer.lock` pin the bundled third-party code; the build output is committed.

## Third-party software

| Package | License | Where |
|---|---|---|
| CodeMirror 6 | MIT | **not bundled**; Joomla core `media/vendor/codemirror` |
| Prism 1.30.0 | MIT | `plugins/system/gssmarkdown/media/*/vendor` (license: `media/js/vendor/LICENSE-prism.txt`) |
| league/commonmark, league/config, league/html-to-markdown, nette/schema, nette/utils, dflydev/dot-access-data | BSD-3 / MIT | `libraries/gssmarkdown/vendor` (see `composer.lock`) |

## License

GPL-2.0-or-later. See `LICENSE.txt`.

## Support

Please report problems and suggestions in the [issue tracker](https://github.com/desbear/joomla-markdown-editor/issues). Responses are best effort.
