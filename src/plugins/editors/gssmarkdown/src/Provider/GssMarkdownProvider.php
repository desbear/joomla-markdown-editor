<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Plugin\Editors\GssMarkdown\Provider;

use Gss\Library\GssMarkdown\ContentCodec;
use Gss\Library\GssMarkdown\Image\ImageStore;
use Gss\Library\GssMarkdown\LegacyConverter;
use Gss\Library\GssMarkdown\Services;
use Gss\Plugin\Editors\GssMarkdown\Controller\PrefsController;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Editor\AbstractEditorProvider;
use Joomla\CMS\Editor\Editor;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\DispatcherInterface;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Renders the editor.
 *
 * Which editor the author gets:
 *   - content has Markdown source            -> Markdown editor
 *   - content empty (new article)            -> Markdown editor
 *   - content is HTML without source (legacy) -> fallback editor (JCE) with an opt-in,
 *                                               warned, lossy "convert to Markdown" button;
 *                                               or, if configured, the Markdown editor behind
 *                                               the same warning
 *   - source exists but the HTML was edited elsewhere since (drift) -> Markdown editor
 *                                               locked until the author chooses what to keep
 *
 * Nothing is converted without an explicit click, and until the Markdown editor is
 * actually in use the field keeps its original value, so opening and saving an article
 * can never change it by itself.
 */
final class GssMarkdownProvider extends AbstractEditorProvider
{
    /** Request flag set by the editor's own buttons (reloads the page). */
    private const USE_FLAG = 'gssmd_use';

    public function __construct(
        private readonly Registry $params,
        private readonly CMSApplicationInterface $application,
        DispatcherInterface $dispatcher
    ) {
        $this->setDispatcher($dispatcher);
    }

    public function getName(): string
    {
        return 'gssmarkdown';
    }

    public function display(string $name, string $content = '', array $attributes = [], array $params = []): string
    {
        $id = (string) (($attributes['id'] ?? '') ?: $name);

        // Editors receive htmlspecialchars()-encoded content (EditorField, EasyBlog); a raw
        // "<" means the caller passed it unencoded (EasyBlog post templates).
        $raw = str_contains($content, '<') ? $content : htmlspecialchars_decode($content, ENT_QUOTES);

        // The server half renders every submission; without it the editor must not run.
        if (!PluginHelper::isEnabled('system', 'gssmarkdown') || !class_exists(ContentCodec::class)) {
            $this->application->enqueueMessage(Text::_('PLG_EDITORS_GSSMARKDOWN_SYSTEM_PLUGIN_MISSING'), 'warning');

            return $this->fallback($name, $raw, $attributes, $params, null);
        }

        $codec     = new ContentCodec();
        $extracted = $codec->extract($raw);
        $use       = $this->application->getInput()->getCmd(self::USE_FLAG, '');
        $isEmpty   = preg_replace('/[\s\x{00A0}]+/u', '', html_entity_decode(
            strip_tags($raw, '<img><iframe><video><object><embed><table><hr>'),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        )) === '';

        if ($use === 'fallback') {
            return $this->fallback($name, $raw, $attributes, $params, $extracted ? 'markdown' : 'legacy');
        }

        if ($extracted !== null) {
            return $this->markdownEditor($name, $id, $raw, $attributes, [
                'mode'      => $extracted->inSync ? 'markdown' : 'drift',
                'markdown'  => $extracted->markdown,
                // Drift: what "convert the current HTML" would give, offered only behind a warning.
                'converted' => $extracted->inSync ? '' : $this->convert($extracted->html),
            ]);
        }

        if ($isEmpty) {
            return $this->markdownEditor($name, $id, $raw, $attributes, ['mode' => 'markdown', 'markdown' => '', 'converted' => '']);
        }

        // Legacy article: the lossy conversion is prepared here but only applied after the
        // author confirmed the warning (before the reload, or in the editor's gate).
        if ($use === 'convert' || $this->params->get('legacy_mode', 'fallback') === 'convert') {
            return $this->markdownEditor($name, $id, $raw, $attributes, [
                'mode'      => 'convert',
                'confirmed' => $use === 'convert',
                'markdown'  => '',
                'converted' => $this->convert($codec->strip($raw)),
            ]);
        }

        return $this->fallback($name, $raw, $attributes, $params, 'legacy');
    }

    /**
     * @param   array  $state  mode, markdown, converted, confirmed
     */
    private function markdownEditor(string $name, string $id, string $raw, array $attributes, array $state): string
    {
        $this->loadAssets();

        $base     = rtrim(Uri::base(true), '/') . '/index.php?option=com_ajax&group=editors&plugin=';
        $settings = Services::systemSettings();
        $height   = $this->cssSize((string) $this->params->get('height', '70vh'), (string) ($attributes['height'] ?? ''));
        $options  = [
            'mode'        => $state['mode'],
            'confirmed'   => !empty($state['confirmed']),
            'context'     => $this->application->getInput()->getCmd('option', ''),
            'draftKey'    => ImageStore::newDraftKey(),
            'uploadUrl'   => $base . 'gssmarkdownupload&format=json',
            'previewUrl'  => $base . 'gssmarkdownpreview&format=json',
            'frameUrl'    => $base . 'gssmarkdownpreview&format=raw&frame=1',
            'prefsUrl'    => $base . 'gssmarkdownprefs&format=json',
            'convertUrl'  => $base . 'gssmarkdownconvert&format=json',
            'token'       => Session::getFormToken(),
            'maxSize'     => max(1, (int) $settings->get('max_size_mb', 5)) * 1048576,
            'allowSvg'    => (bool) $settings->get('allow_svg', 0),
            'previewMax'  => max(1, (int) $settings->get('preview_max_kb', 1024)) * 1024,
            'viewport'    => max(320, (int) $settings->get('preview_viewport', 1280)),
            'debounce'    => max(150, min(2000, (int) $this->params->get('preview_debounce', 300))),
            'height'      => $height,
            'prefs'       => PrefsController::forUser($this->application->getIdentity()),
            'fallbackLabel' => $this->fallbackLabel(),
        ];

        $e = static fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        // The named textarea keeps the ORIGINAL value until the editor has taken over, so a
        // failed script load (or a locked drift/legacy state) submits the article unchanged.
        return '<joomla-editor-gssmarkdown class="gssmd" data-options="' . $e(json_encode($options, JSON_UNESCAPED_SLASHES)) . '"'
            . ' style="--gssmd-height:' . $e($height) . '">'
            // Shown only if the editor script never starts (e.g. a stale cached file): the
            // author gets instructions instead of an empty white box.
            . '<div class="gssmd-loadfail" role="alert">' . Text::_('PLG_EDITORS_GSSMARKDOWN_LOAD_FAILED') . '</div>'
            . '<textarea class="gssmd-initial" hidden aria-hidden="true" tabindex="-1">' . $e($state['markdown']) . '</textarea>'
            . '<textarea class="gssmd-converted" hidden aria-hidden="true" tabindex="-1">' . $e($state['converted']) . '</textarea>'
            . '<textarea name="' . $e($name) . '" id="' . $e($id) . '" class="gssmd-field" hidden>' . $e($raw) . '</textarea>'
            . '</joomla-editor-gssmarkdown>';
    }

    private function convert(string $html): string
    {
        try {
            return (new LegacyConverter())->convert($html);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Renders the configured fallback editor (JCE by default) with the same arguments.
     *
     * @param   ?string  $reason  'legacy' | 'markdown' | null (no banner)
     */
    private function fallback(string $name, string $raw, array $attributes, array $params, ?string $reason): string
    {
        $editor = Editor::getInstance($this->fallbackName());
        $html   = $editor->display(
            $name,
            htmlspecialchars($raw, ENT_COMPAT, 'UTF-8'),
            $attributes['width'] ?? '100%',
            $attributes['height'] ?? '500',
            $attributes['col'] ?? 70,
            $attributes['row'] ?? 15,
            $params['buttons'] ?? true,
            $attributes['id'] ?? null,
            $params['asset'] ?? null,
            $params['author'] ?? null,
            $params
        );

        if ($reason === null) {
            return $html;
        }

        $this->loadAssets(bannerOnly: true);

        $message = $reason === 'legacy'
            ? Text::sprintf('PLG_EDITORS_GSSMARKDOWN_LEGACY_BANNER', $this->fallbackLabel())
            : Text::_('PLG_EDITORS_GSSMARKDOWN_FALLBACK_BANNER');

        $button = $reason === 'legacy'
            ? '<button type="button" class="btn btn-sm btn-outline-secondary" data-gssmd-action="convert">'
                . Text::_('PLG_EDITORS_GSSMARKDOWN_CONVERT_BUTTON') . '</button>'
            : '<button type="button" class="btn btn-sm btn-outline-secondary" data-gssmd-action="markdown">'
                . Text::_('PLG_EDITORS_GSSMARKDOWN_BACK_TO_MARKDOWN') . '</button>';

        return '<div class="gssmd-banner alert alert-info" data-gssmd-banner>' . $message . ' ' . $button . '</div>' . $html;
    }

    private function loadAssets(bool $bannerOnly = false): void
    {
        /** @var \Joomla\CMS\WebAsset\WebAssetManager $wa */
        $wa       = $this->application->getDocument()->getWebAssetManager();
        $registry = $wa->getRegistry();

        // CodeMirror 6 comes from Joomla core (import map entries of plg_editors_codemirror),
        // so it is patched with Joomla updates and not bundled here.
        $registry->addExtensionRegistryFile('plg_editors_codemirror');
        $registry->addExtensionRegistryFile('plg_editors_gssmarkdown');

        foreach ([
            'PLG_EDITORS_GSSMARKDOWN_UPLOADING', 'PLG_EDITORS_GSSMARKDOWN_UPLOAD_FAILED', 'PLG_EDITORS_GSSMARKDOWN_UPLOAD_DONE',
            'PLG_EDITORS_GSSMARKDOWN_ERR_FILE_TOO_LARGE', 'PLG_EDITORS_GSSMARKDOWN_ERR_INVALID_TYPE', 'PLG_EDITORS_GSSMARKDOWN_ERR_NETWORK',
            'PLG_EDITORS_GSSMARKDOWN_ERR_RESPONSE', 'PLG_EDITORS_GSSMARKDOWN_CONVERT_CONFIRM', 'PLG_EDITORS_GSSMARKDOWN_CONVERT_BUTTON',
            'PLG_EDITORS_GSSMARKDOWN_CONVERTED_NOTICE', 'PLG_EDITORS_GSSMARKDOWN_LEGACY_GATE', 'PLG_EDITORS_GSSMARKDOWN_DRIFT_GATE',
            'PLG_EDITORS_GSSMARKDOWN_USE_SOURCE', 'PLG_EDITORS_GSSMARKDOWN_USE_SOURCE_CONFIRM', 'PLG_EDITORS_GSSMARKDOWN_CONVERT_CURRENT',
            'PLG_EDITORS_GSSMARKDOWN_USE_FALLBACK', 'PLG_EDITORS_GSSMARKDOWN_RELOAD_CONFIRM', 'PLG_EDITORS_GSSMARKDOWN_DISMISS',
            'PLG_EDITORS_GSSMARKDOWN_PLACEHOLDER_GONE', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_SCOPE', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_FAILED',
            'PLG_EDITORS_GSSMARKDOWN_PREVIEW_FALLBACK', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_UPDATING', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_REFRESH',
            'PLG_EDITORS_GSSMARKDOWN_VIEW_EDIT', 'PLG_EDITORS_GSSMARKDOWN_VIEW_SPLIT', 'PLG_EDITORS_GSSMARKDOWN_VIEW_PREVIEW',
            'PLG_EDITORS_GSSMARKDOWN_INSERT_IMAGE', 'PLG_EDITORS_GSSMARKDOWN_SETTINGS', 'PLG_EDITORS_GSSMARKDOWN_THEME',
            'PLG_EDITORS_GSSMARKDOWN_THEME_AUTO', 'PLG_EDITORS_GSSMARKDOWN_THEME_LIGHT', 'PLG_EDITORS_GSSMARKDOWN_THEME_DARK',
            'PLG_EDITORS_GSSMARKDOWN_FONT', 'PLG_EDITORS_GSSMARKDOWN_FONT_MONO', 'PLG_EDITORS_GSSMARKDOWN_FONT_SANS',
            'PLG_EDITORS_GSSMARKDOWN_FONT_SIZE', 'PLG_EDITORS_GSSMARKDOWN_LINE_NUMBERS', 'PLG_EDITORS_GSSMARKDOWN_RESIZE',
            'PLG_EDITORS_GSSMARKDOWN_ERR_PREVIEW_TOO_LARGE', 'PLG_EDITORS_GSSMARKDOWN_EDITOR_LABEL', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_LABEL',
            'PLG_EDITORS_GSSMARKDOWN_EDITOR_PLACEHOLDER', 'PLG_EDITORS_GSSMARKDOWN_FMT_VIDEO', 'PLG_EDITORS_GSSMARKDOWN_VIDEO_URL', 'PLG_EDITORS_GSSMARKDOWN_VIDEO_INSERT', 'PLG_EDITORS_GSSMARKDOWN_VIDEO_INVALID', 'PLG_EDITORS_GSSMARKDOWN_VIDEO_HELP', 'PLG_EDITORS_GSSMARKDOWN_VIDEO_TITLE', 'PLG_EDITORS_GSSMARKDOWN_VIDEO_TITLE_LABEL', 'PLG_EDITORS_GSSMARKDOWN_VIDEO_TITLE_HINT', 'PLG_EDITORS_GSSMARKDOWN_MD_ACTIONS', 'PLG_EDITORS_GSSMARKDOWN_MD_COPY', 'PLG_EDITORS_GSSMARKDOWN_MD_PASTE', 'PLG_EDITORS_GSSMARKDOWN_MD_SAVE', 'PLG_EDITORS_GSSMARKDOWN_MD_COPIED', 'PLG_EDITORS_GSSMARKDOWN_MD_COPY_FAILED', 'PLG_EDITORS_GSSMARKDOWN_MD_PASTE_DENIED', 'PLG_EDITORS_GSSMARKDOWN_VIEW', 'PLG_EDITORS_GSSMARKDOWN_FMT_INDENT', 'PLG_EDITORS_GSSMARKDOWN_FMT_OUTDENT', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_SCOPE_SHORT', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_WIDTH', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_WIDTH_FIT', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_WIDTH_SITE', 'PLG_EDITORS_GSSMARKDOWN_PREVIEW_TOO_NARROW',
            'PLG_EDITORS_GSSMARKDOWN_TOOLBAR', 'PLG_EDITORS_GSSMARKDOWN_FMT_HTML', 'PLG_EDITORS_GSSMARKDOWN_HTML_HELP', 'PLG_EDITORS_GSSMARKDOWN_HTML_SOURCE', 'PLG_EDITORS_GSSMARKDOWN_HTML_SOURCE_HINT', 'PLG_EDITORS_GSSMARKDOWN_HTML_RESULT', 'PLG_EDITORS_GSSMARKDOWN_HTML_RESULT_HINT', 'PLG_EDITORS_GSSMARKDOWN_HTML_INSERT', 'PLG_EDITORS_GSSMARKDOWN_HTML_CANCEL', 'PLG_EDITORS_GSSMARKDOWN_HTML_CONVERTING', 'PLG_EDITORS_GSSMARKDOWN_HTML_FAILED', 'PLG_EDITORS_GSSMARKDOWN_HTML_DROPPED', 'PLG_EDITORS_GSSMARKDOWN_HTML_FILE', 'PLG_EDITORS_GSSMARKDOWN_HTML_FILE_DROP', 'PLG_EDITORS_GSSMARKDOWN_HTML_FILE_INVALID', 'PLG_EDITORS_GSSMARKDOWN_HTML_FILE_LOADED', 'PLG_EDITORS_GSSMARKDOWN_HTML_FILE_LOCAL_IMAGES', 'PLG_EDITORS_GSSMARKDOWN_FMT_GROUP', 'PLG_EDITORS_GSSMARKDOWN_FMT_BLOCK', 'PLG_EDITORS_GSSMARKDOWN_FMT_PARAGRAPH', 'PLG_EDITORS_GSSMARKDOWN_FMT_HEADING', 'PLG_EDITORS_GSSMARKDOWN_FMT_BOLD', 'PLG_EDITORS_GSSMARKDOWN_FMT_ITALIC', 'PLG_EDITORS_GSSMARKDOWN_FMT_STRIKE', 'PLG_EDITORS_GSSMARKDOWN_FMT_CODE', 'PLG_EDITORS_GSSMARKDOWN_FMT_LINK', 'PLG_EDITORS_GSSMARKDOWN_FMT_UL', 'PLG_EDITORS_GSSMARKDOWN_FMT_OL', 'PLG_EDITORS_GSSMARKDOWN_FMT_TASK', 'PLG_EDITORS_GSSMARKDOWN_FMT_QUOTE', 'PLG_EDITORS_GSSMARKDOWN_FMT_CODEBLOCK', 'PLG_EDITORS_GSSMARKDOWN_FMT_PLAIN', 'PLG_EDITORS_GSSMARKDOWN_FMT_TABLE', 'PLG_EDITORS_GSSMARKDOWN_FMT_RULE', 'PLG_EDITORS_GSSMARKDOWN_PH_BOLD', 'PLG_EDITORS_GSSMARKDOWN_PH_ITALIC', 'PLG_EDITORS_GSSMARKDOWN_PH_STRIKE', 'PLG_EDITORS_GSSMARKDOWN_PH_CODE', 'PLG_EDITORS_GSSMARKDOWN_PH_LINK', 'PLG_EDITORS_GSSMARKDOWN_PH_HEADER', 'PLG_EDITORS_GSSMARKDOWN_PH_CELL', 'PLG_EDITORS_GSSMARKDOWN_SCROLL_SYNC', 'PLG_EDITORS_GSSMARKDOWN_SCROLL_SYNC_TIP',
        ] as $key) {
            Text::script($key);
        }

        $wa->useScript('plg_editors_gssmarkdown.banner');

        if (!$bannerOnly) {
            $wa->useStyle('plg_editors_gssmarkdown.editor')
                ->useScript('plg_editors_gssmarkdown.editor');
        }
    }

    private function fallbackName(): string
    {
        foreach ([(string) $this->params->get('fallback_editor', 'jce'), 'jce', 'tinymce', 'none'] as $candidate) {
            if ($candidate !== '' && $candidate !== 'gssmarkdown' && PluginHelper::isEnabled('editors', $candidate)) {
                return $candidate;
            }
        }

        return 'none';
    }

    private function fallbackLabel(): string
    {
        return match ($this->fallbackName()) {
            'jce'     => 'JCE',
            'tinymce' => 'TinyMCE',
            'codemirror' => 'CodeMirror',
            default   => Text::_('PLG_EDITORS_GSSMARKDOWN_PLAIN_EDITOR'),
        };
    }

    /**
     * The larger of the configured height and what the caller asked for.
     */
    private function cssSize(string $configured, string $requested): string
    {
        $px = static fn (string $v) => preg_match('/^\d+(px)?$/', trim($v)) ? (int) $v : 0;

        if (!preg_match('/^\d+(\.\d+)?(px|vh|em|rem)$/', $configured)) {
            $configured = '70vh';
        }

        return $px($requested) > $px($configured) && str_ends_with($configured, 'px') ? $px($requested) . 'px' : $configured;
    }
}
