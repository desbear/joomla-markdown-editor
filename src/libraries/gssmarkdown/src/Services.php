<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown;

use Gss\Library\GssMarkdown\Context\ArticleAdapter;
use Gss\Library\GssMarkdown\Context\ContextAdapter;
use Gss\Library\GssMarkdown\Context\EasyBlogAdapter;
use Gss\Library\GssMarkdown\Image\ImageFinalizer;
use Gss\Library\GssMarkdown\Image\ImageStore;
use Gss\Library\GssMarkdown\Preview\FrameBuilder;
use Gss\Library\GssMarkdown\Preview\FrameProvider;
use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Builds the shared services from the settings of plg_system_gssmarkdown, which holds
 * every server-side option so the editor, the save hook and the cleanup task agree.
 */
final class Services
{
    private ?ContentCodec $codec = null;

    private ?MarkdownRenderer $renderer = null;

    private ?ImageStore $images = null;

    /** @var ContextAdapter[]|null */
    private ?array $adapters = null;

    public function __construct(private readonly Registry $settings, private readonly DatabaseInterface $db)
    {
    }

    /**
     * Settings of plg_system_gssmarkdown (usable from any plugin).
     */
    public static function systemSettings(): Registry
    {
        $plugin = PluginHelper::getPlugin('system', 'gssmarkdown');

        return new Registry(\is_object($plugin) ? $plugin->params : '{}');
    }

    public function settings(): Registry
    {
        return $this->settings;
    }

    public function codec(): ContentCodec
    {
        return $this->codec ??= new ContentCodec();
    }

    public function renderer(): MarkdownRenderer
    {
        if ($this->renderer === null) {
            $tags = array_filter(array_map('trim', explode(',', (string) $this->settings->get(
                'raw_html_tags',
                implode(',', MarkdownRenderer::DEFAULT_RAW_TAGS)
            ))));

            $this->renderer = new MarkdownRenderer((string) $this->settings->get('raw_html', 'escape'), $tags);
        }

        return $this->renderer;
    }

    public function images(): ImageStore
    {
        return $this->images ??= new ImageStore(
            JPATH_ROOT,
            rtrim(Uri::root(true), '/'),
            $this->relDir('tmp_dir', 'images/easyblog_articles/.tmp'),
            max(1, (int) $this->settings->get('max_size_mb', 5)) * 1048576,
            max(1, (int) $this->settings->get('max_dir_mb', 200)) * 1048576,
            (bool) $this->settings->get('allow_svg', 0),
            null,
            static fn (string $key, ...$args) => $args ? Text::sprintf($key, ...$args) : Text::_($key)
        );
    }

    /**
     * The preview document (front-end layout of a published article, cached).
     */
    public function frameProvider(): FrameProvider
    {
        $media   = rtrim(Uri::root(true), '/') . '/media/plg_system_gssmarkdown/';
        $version = '?' . substr(md5((string) @filemtime(JPATH_ROOT . '/media/plg_system_gssmarkdown/js/vendor/prism.bundle.min.js')), 0, 8);
        $head    = [
            // The same files the site loads on article pages (plg_system_gssmarkdown::onAfterRender).
            '<link rel="stylesheet" href="' . $media . 'css/vendor/prism.min.css' . $version . '">',
            '<link rel="stylesheet" href="' . $media . 'css/content.css' . $version . '">',
            '<script src="' . $media . 'js/vendor/prism.bundle.min.js' . $version . '"></script>',
        ];

        $custom = trim((string) $this->settings->get('preview_custom_css', ''));

        if ($custom !== '') {
            $head[] = '<style>' . str_ireplace('</style', '<\/style', $custom) . '</style>';
        }

        $root     = Uri::root();
        $internal = trim((string) $this->settings->get('preview_fetch_base', ''));
        $internal = preg_match('#^https?://\S+$#i', $internal) ? rtrim($internal, '/') . '/' : '';

        return new FrameProvider(
            $this->settings,
            new FrameBuilder(),
            static function (string $url) use ($root, $internal): ?string {
                // In containers the server often cannot reach its own public address.
                if ($internal !== '' && str_starts_with($url, $root)) {
                    $url = $internal . substr($url, \strlen($root));
                }

                try {
                    $response = HttpFactory::getHttp()->get($url, ['User-Agent' => 'GssMarkdown-Preview/2 (Joomla)'], 15);

                    return $response->getStatusCode() === 200 ? (string) $response->getBody() : null;
                } catch (\Throwable) {
                    return null;
                }
            },
            $root,
            JPATH_CACHE . '/gssmarkdown',
            $head
        );
    }

    /**
     * Renders submitted Markdown into the value stored in the content field.
     */
    public function renderForStorage(string $markdown): string
    {
        return $this->codec()->encode($this->renderer()->render($markdown), $markdown);
    }

    /**
     * @return ContextAdapter[]
     */
    public function adapters(): array
    {
        if ($this->adapters === null) {
            $this->adapters = [new ArticleAdapter($this->db, $this->relDir('generic_dir', 'images/gssmd'))];

            if (EasyBlogAdapter::isInstalled()) {
                $this->adapters[] = new EasyBlogAdapter($this->db, $this->relDir('easyblog_dir', 'images/easyblog_articles'));
            }
        }

        return $this->adapters;
    }

    public function adapterForOption(string $option): ?ContextAdapter
    {
        foreach ($this->adapters() as $adapter) {
            if ($adapter->option() === $option) {
                return $adapter;
            }
        }

        return null;
    }

    public function adapterForSaveContext(string $context): ?ContextAdapter
    {
        foreach ($this->adapters() as $adapter) {
            if (\in_array($context, $adapter->saveContexts(), true)) {
                return $adapter;
            }
        }

        return null;
    }

    /**
     * May this user call the editor's helper endpoints (preview, preview layout, HTML
     * conversion)? Only people who can write content somewhere: without this, any
     * registered account could use the site as a rendering / conversion service and make
     * the server fetch pages.
     *
     * @param   string  $option  Component the editor runs in (sent by the editor).
     * @param   int     $itemId  Item being edited, 0 when new.
     */
    public function canUseEditor(User $user, string $option = '', int $itemId = 0): bool
    {
        if ($user->guest || !$user->id) {
            return false;
        }

        // Back-end users, and anyone who may create or edit content in general.
        foreach (['core.login.admin', 'core.create', 'core.edit', 'core.edit.own'] as $action) {
            if ($user->authorise($action) || $user->authorise($action, 'com_content')) {
                return true;
            }
        }

        $option = preg_match('/^com_[a-z0-9_]+$/', $option) ? $option : '';

        if ($option !== '' && ($user->authorise('core.create', $option) || $user->authorise('core.edit', $option) || $user->authorise('core.edit.own', $option))) {
            return true;
        }

        // Component rules (category-level rights, EasyBlog's own ACL, a single editable item).
        foreach ($this->adapters() as $adapter) {
            try {
                if ($adapter->canCreate($user) || ($itemId > 0 && $adapter->option() === $option && $adapter->canEditItem($itemId, $user))) {
                    return true;
                }
            } catch (\Throwable) {
                // An adapter that cannot answer does not grant access.
            }
        }

        return false;
    }

    /**
     * Image folder for components without an adapter (modules, categories, ...). Their
     * uploads are stored directly, since there is no save event to move them from.
     */
    public function genericImageDir(string $option): string
    {
        $option = preg_replace('/[^a-z0-9_]/', '', strtolower($option)) ?: 'misc';

        return $this->relDir('generic_dir', 'images/gssmd') . '/' . $option . '/' . date('Y-m');
    }

    /**
     * Moves the temp images referenced by an item into its folder and rewrites every
     * stored copy of its content. Safe to call repeatedly.
     */
    public function finalizeImages(ContextAdapter $adapter, int $id): void
    {
        (new ImageFinalizer($this->images(), $this->codec()))->finalize($adapter, $id);
    }

    private function relDir(string $key, string $default): string
    {
        $dir = trim(str_replace('\\', '/', (string) $this->settings->get($key, $default)), '/');

        // Relative to the site root, never outside it.
        if ($dir === '' || str_contains($dir, '..') || !preg_match('#^[A-Za-z0-9._/-]+$#', $dir)) {
            return $default;
        }

        return $dir;
    }
}
