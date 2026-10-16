<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Video;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Video markers -> players.
 *
 * Markdown has no video syntax. A video URL alone on its own line (or written as an image,
 * ![title](url)) becomes a video. The renderer stores only a marker:
 *
 *   <div class="gssmd-video" data-youtube="{id}" data-start="{s}" title="…"><a href="…">…</a></div>
 *   <div class="gssmd-video" data-video="{url}" title="…"><a href="…">…</a></div>   (other sites, files)
 *
 * and expand() turns it into the player where it is shown (editor preview, site pages).
 * Storing the marker instead of an <iframe> matters: EasyBlog and Joomla's text filters
 * remove iframes for most user groups when the article is saved; the marker survives them,
 * and without this plugin readers still get a plain link. On the site, plg_system_gsslightbox
 * (when installed) replaces the marker with a thumbnail that plays in a lightbox.
 */
final class Player
{
    /**
     * Replaces video markers with players. Idempotent; a marker whose URL is not a supported
     * video is left as it is (the fallback link stays).
     *
     * @param   string  $title    Accessible name used when the author gave none.
     * @param   string  $rootUrl  Site root path (Uri::root(true)) that relative file paths such
     *                            as images/videos/a.mp4 are resolved against.
     */
    public static function expand(string $html, string $title = 'Video', string $rootUrl = ''): string
    {
        if (!str_contains($html, 'gssmd-video')) {
            return $html;
        }

        return preg_replace_callback(
            '#(<div\b[^>]*\bclass="gssmd-video"[^>]*>)(.*?)</div>#is',
            static function (array $m) use ($title, $rootUrl): string {
                $open = $m[1];

                if (preg_match('#<(?:iframe|video)\b#i', $m[2])) {
                    return $m[0];
                }

                $video = self::fromMarker($open);

                if ($video === null) {
                    return $m[0];
                }

                $e     = static fn (string $v) => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $label = preg_match('/\btitle="([^"]*)"/', $open, $t) && $t[1] !== '' ? $t[1] : $e($title);

                if ($video['provider'] === 'file') {
                    return $open
                        . '<video src="' . $e(self::fileUrl($video['file'], $rootUrl)) . '" title="' . $label . '" controls preload="metadata" playsinline></video></div>';
                }

                return $open
                    . '<iframe src="' . $e($video['embed']) . '" title="' . $label . '" loading="lazy"'
                    . ' allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share; fullscreen"'
                    . ' referrerpolicy="strict-origin-when-cross-origin" allowfullscreen></iframe></div>';
            },
            $html
        ) ?? $html;
    }

    /**
     * The video a marker's opening tag stands for.
     *
     * @return ?array{provider: string, id: string, start: int, embed: string, watch: string, file: ?string}
     */
    public static function fromMarker(string $open): ?array
    {
        if (preg_match('/\bdata-youtube="([A-Za-z0-9_-]{11})"/', $open, $id)) {
            $start = preg_match('/\bdata-start="(\d{1,6})"/', $open, $s) ? (int) $s[1] : 0;

            return VideoUrl::parse('https://www.youtube.com/watch?v=' . $id[1] . ($start ? '&t=' . $start : ''));
        }

        if (preg_match('/\bdata-video="([^"]+)"/', $open, $url)) {
            return VideoUrl::parse($url[1]);
        }

        return null;
    }

    /** Relative file paths (images/…) are relative to the site root, not to the page URL. */
    public static function fileUrl(string $file, string $rootUrl): string
    {
        return preg_match('#^(?:https?://|/)#i', $file) ? $file : rtrim($rootUrl, '/') . '/' . $file;
    }
}
