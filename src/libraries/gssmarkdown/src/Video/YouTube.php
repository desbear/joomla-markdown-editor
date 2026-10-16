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
 * YouTube URLs (kept for callers of 2.5; VideoUrl handles every supported site).
 */
final class YouTube
{
    private const ID = '[A-Za-z0-9_-]{11}';

    private const HOSTS = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'];

    /**
     * @return  ?array{id: string, start: int}  Null when the URL is not a YouTube video.
     */
    public static function parse(string $url): ?array
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        $host  = strtolower($parts['host'] ?? '');
        $path  = $parts['path'] ?? '';

        if (!\in_array($host, self::HOSTS, true)) {
            return null;
        }

        parse_str($parts['query'] ?? '', $query);

        if ($host === 'youtu.be') {
            $id = trim($path, '/');
        } elseif ($path === '/watch') {
            $id = (string) ($query['v'] ?? '');
        } elseif (preg_match('#^/(?:embed|shorts|live|v)/([^/]+)#', $path, $m)) {
            $id = $m[1];
        } else {
            return null;
        }

        // "videoseries" (playlist embeds) happens to be 11 characters long, like a video id.
        if (!preg_match('/^' . self::ID . '$/', $id) || $id === 'videoseries') {
            return null;
        }

        return ['id' => $id, 'start' => self::seconds((string) ($query['t'] ?? $query['start'] ?? ''))];
    }

    /** Canonical watch URL (used for the fallback link). */
    public static function watchUrl(string $id, int $start = 0): string
    {
        return 'https://www.youtube.com/watch?v=' . $id . ($start > 0 ? '&t=' . $start . 's' : '');
    }

    /** @deprecated 2.6  Use Player::expand(), which handles every supported video. */
    public static function expand(string $html, string $title = 'YouTube'): string
    {
        return Player::expand($html, $title);
    }

    /** "90", "90s", "1m30s", "1h2m3s" -> seconds. */
    private static function seconds(string $value): int
    {
        if ($value === '') {
            return 0;
        }

        if (ctype_digit($value)) {
            return min((int) $value, 999999);
        }

        if (!preg_match('/^(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $value, $m) || $m[0] === '') {
            return 0;
        }

        return min(((int) ($m[1] ?? 0)) * 3600 + ((int) ($m[2] ?? 0)) * 60 + (int) ($m[3] ?? 0), 999999);
    }
}
