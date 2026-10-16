<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Preview;

use Gss\Library\GssMarkdown\Context\ContextAdapter;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Supplies the preview document: a real front-end article page reduced by FrameBuilder,
 * cached on disk so opening the editor does not request (and count a hit on) an article
 * page every time. When the front-end template changes, the next refresh picks it up
 * without touching the plugin.
 */
final class FrameProvider
{
    /** @var callable(string): ?string */
    private $fetch;

    /**
     * @param   Registry  $settings   plg_system_gssmarkdown params
     * @param   callable  $fetch      fn(string $absoluteUrl): ?string — page HTML or null
     * @param   string    $siteUrl    Absolute site root URL with trailing slash (Uri::root())
     * @param   string    $cacheDir   Writable folder for cached frames
     * @param   string[]  $extraHead  The plugin's own head markup (highlighter, content CSS, custom CSS)
     */
    public function __construct(
        private readonly Registry $settings,
        private readonly FrameBuilder $builder,
        callable $fetch,
        private readonly string $siteUrl,
        private readonly string $cacheDir,
        private readonly array $extraHead
    ) {
        $this->fetch = $fetch;
    }

    /**
     * @return  array{html: string, source: string, page: ?string}
     *          source: 'page' (front-end layout) or 'fallback' (configured stylesheets)
     */
    public function frame(?ContextAdapter $adapter, ?int $itemId, bool $refresh = false): array
    {
        $page     = $this->pageUrl($adapter, $itemId);
        $selector = trim((string) $this->settings->get('preview_selector', '')) ?: ($adapter?->bodySelector() ?? '');

        if ($page !== null && $selector !== '') {
            $cache = $this->cacheDir . '/frame-' . md5($page . '|' . $selector . '|' . implode('', $this->extraHead)) . '.html';
            $ttl   = max(0, (int) $this->settings->get('preview_cache_hours', 6)) * 3600;

            if (!$refresh && is_file($cache) && filemtime($cache) > time() - $ttl) {
                return ['html' => (string) file_get_contents($cache), 'source' => 'page', 'page' => $page];
            }

            $html  = ($this->fetch)($page);
            $frame = $html !== null ? $this->builder->build($html, $page, $selector, $this->extraHead) : null;

            if ($frame !== null) {
                if (is_dir($this->cacheDir) || @mkdir($this->cacheDir, 0755, true)) {
                    @file_put_contents($cache, $frame);
                }

                return ['html' => $frame, 'source' => 'page', 'page' => $page];
            }

            // Fetch failed: an older cached frame beats the generic fallback.
            if (is_file($cache)) {
                return ['html' => (string) file_get_contents($cache), 'source' => 'page', 'page' => $page];
            }
        }

        $stylesheets = array_values(array_filter(array_map(
            fn (string $line) => $this->absolute(trim($line)),
            preg_split('/\R/', (string) $this->settings->get('preview_stylesheets', '')) ?: []
        )));

        return [
            'html'   => $this->builder->fallback(
                $stylesheets,
                (string) $this->settings->get('preview_wrapper_class', 'gssmd-preview'),
                (string) $this->settings->get('preview_max_width', ''),
                $this->siteUrl,
                $this->extraHead
            ),
            'source' => 'fallback',
            'page'   => $page,
        ];
    }

    private function pageUrl(?ContextAdapter $adapter, ?int $itemId): ?string
    {
        $configured = trim((string) $this->settings->get('preview_page', ''));
        $relative   = $configured !== '' ? $configured : $adapter?->samplePageUrl($itemId);

        return $relative ? $this->absolute($relative) : null;
    }

    /**
     * Site-relative -> absolute. Absolute URLs are only accepted on the site's own host,
     * so the server never fetches arbitrary addresses.
     */
    private function absolute(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        if (preg_match('#^https?://#i', $url)) {
            return strcasecmp((string) parse_url($url, PHP_URL_HOST), (string) parse_url($this->siteUrl, PHP_URL_HOST)) === 0 ? $url : null;
        }

        if (str_starts_with($url, '//')) {
            return null;
        }

        // "/blog/x.css" on a site installed in /blog, or "x.css" / "index.php?..." relative to the root.
        if (str_starts_with($url, '/')) {
            $origin = preg_replace('#^(https?://[^/]+).*$#i', '$1', $this->siteUrl);

            return $origin . $url;
        }

        return rtrim($this->siteUrl, '/') . '/' . ltrim($url, '/');
    }
}
