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
 * Recognises video URLs: YouTube, Vimeo, Dailymotion, Bilibili and video files.
 *
 * Embed and watch URLs are always rebuilt from the parsed id on a fixed host, never copied
 * from the input, so a video can only ever load one of these players. The watch URL keeps
 * the start time and parses back to the same video.
 *
 * (The same class ships in plg_system_gsslightbox; keep both copies in step.)
 */
final class VideoUrl
{
    public const FILE_EXTENSIONS = ['mp4', 'webm', 'ogv', 'm4v', 'mov'];

    /** Embed URL prefixes a stored marker may point to (checked again when it is expanded). */
    public const EMBED_PREFIXES = [
        'https://www.youtube-nocookie.com/embed/',
        'https://www.youtube.com/embed/',
        'https://player.vimeo.com/video/',
        'https://www.dailymotion.com/embed/video/',
        'https://player.bilibili.com/player.html?',
    ];

    /**
     * @return ?array{provider: string, id: string, start: int, embed: string, watch: string, file: ?string}
     */
    public static function parse(string $url): ?array
    {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($url === '' || preg_match('/[\s"\'<>`]/', $url)) {
            return null;
        }

        if ($file = self::file($url)) {
            return ['provider' => 'file', 'id' => '', 'start' => 0, 'embed' => '', 'watch' => $file, 'file' => $file];
        }

        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);

        if ($parts === false || empty($parts['host'])) {
            return null;
        }

        $host = strtolower($parts['host']);
        $path = $parts['path'] ?? '';
        parse_str($parts['query'] ?? '', $query);
        $fragment = $parts['fragment'] ?? '';

        return match (true) {
            \in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtube-nocookie.com', 'youtube-nocookie.com'], true)
                => self::youtube($host, $path, $query),
            \in_array($host, ['vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)
                => self::vimeo($host, $path, $query, $fragment),
            \in_array($host, ['dailymotion.com', 'www.dailymotion.com', 'dai.ly', 'geo.dailymotion.com'], true)
                => self::dailymotion($host, $path, $query),
            \in_array($host, ['bilibili.com', 'www.bilibili.com', 'm.bilibili.com', 'player.bilibili.com'], true)
                => self::bilibili($host, $path, $query),
            default => null,
        };
    }

    /** "90", "90s", "1m30s", "1h2m3s" -> seconds. */
    public static function seconds(string $value): int
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

    /**
     * A video file URL, or null: https://host/…/clip.mp4, /images/clip.mp4 or images/clip.mp4.
     * Never another scheme (javascript:, data:) and never protocol-relative.
     */
    public static function file(string $url): ?string
    {
        $ext = implode('|', self::FILE_EXTENSIONS);

        if (!preg_match('#^(?:https?://[^/\s?\#"\'<>`]+/|/(?!/)|(?![a-z][a-z0-9+.-]*:)(?!/))[^\s"\'<>`\\\\]*\.(?:' . $ext . ')(?:[?\#][^\s"\'<>`\\\\]*)?$#i', $url)) {
            return null;
        }

        return $url;
    }

    /** A URL that may be used as the src of a <video> (http(s), site-relative or relative). */
    public static function safeSource(string $url): bool
    {
        return $url !== ''
            && !preg_match('/[\s"\'<>`\\\\]/', $url)
            && (preg_match('#^https?://[^/\s]#i', $url) || preg_match('#^/(?!/)#', $url) || !preg_match('#^(?:[a-z][a-z0-9+.-]*:|/)#i', $url));
    }

    private static function youtube(string $host, string $path, array $query): ?array
    {
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
        if (!preg_match('/^[A-Za-z0-9_-]{11}$/', $id) || $id === 'videoseries') {
            return null;
        }

        $start = self::seconds((string) ($query['t'] ?? $query['start'] ?? ''));

        return self::result(
            'youtube',
            $id,
            $start,
            'https://www.youtube-nocookie.com/embed/' . $id . '?rel=0' . ($start ? '&start=' . $start : ''),
            'https://www.youtube.com/watch?v=' . $id . ($start ? '&t=' . $start . 's' : '')
        );
    }

    private static function vimeo(string $host, string $path, array $query, string $fragment): ?array
    {
        $hash = '';

        if ($host === 'player.vimeo.com') {
            if (!preg_match('#^/video/(\d{5,12})$#', $path, $m)) {
                return null;
            }
            $id   = $m[1];
            $hash = (string) ($query['h'] ?? '');
        } elseif (preg_match('#^/(?:channels/[\w-]+/|groups/[\w-]+/videos/|video/)?(\d{5,12})(?:/([0-9a-f]{6,20}))?/?$#', $path, $m)) {
            $id   = $m[1];
            $hash = $m[2] ?? (string) ($query['h'] ?? '');
        } else {
            return null;
        }

        if ($hash !== '' && !preg_match('/^[0-9a-f]{6,20}$/', $hash)) {
            $hash = '';
        }

        $start = self::seconds(preg_match('/^t=(\w+)$/', $fragment, $t) ? $t[1] : (string) ($query['t'] ?? ''));

        return self::result(
            'vimeo',
            $id,
            $start,
            'https://player.vimeo.com/video/' . $id . '?dnt=1' . ($hash !== '' ? '&h=' . $hash : '') . ($start ? '#t=' . $start . 's' : ''),
            'https://vimeo.com/' . $id . ($hash !== '' ? '/' . $hash : '') . ($start ? '#t=' . $start . 's' : '')
        );
    }

    private static function dailymotion(string $host, string $path, array $query): ?array
    {
        if ($host === 'dai.ly') {
            $id = trim($path, '/');
        } elseif ($host === 'geo.dailymotion.com') {
            $id = (string) ($query['video'] ?? '');
        } elseif (preg_match('#^/(?:embed/)?video/([^/_]+)#', $path, $m)) {
            $id = $m[1];
        } else {
            return null;
        }

        if (!preg_match('/^x[a-z0-9]{4,12}$/i', $id)) {
            return null;
        }

        $start = self::seconds((string) ($query['start'] ?? ''));

        return self::result(
            'dailymotion',
            $id,
            $start,
            'https://www.dailymotion.com/embed/video/' . $id . ($start ? '?start=' . $start : ''),
            'https://www.dailymotion.com/video/' . $id . ($start ? '?start=' . $start : '')
        );
    }

    private static function bilibili(string $host, string $path, array $query): ?array
    {
        if ($host === 'player.bilibili.com') {
            $bvid = (string) ($query['bvid'] ?? '');
            $aid  = (string) ($query['aid'] ?? '');
        } elseif (preg_match('#^/video/(BV[0-9A-Za-z]{10}|av\d{1,12})/?$#', $path, $m)) {
            $bvid = str_starts_with($m[1], 'BV') ? $m[1] : '';
            $aid  = str_starts_with($m[1], 'av') ? substr($m[1], 2) : '';
        } else {
            return null;
        }

        if ($bvid !== '' && !preg_match('/^BV[0-9A-Za-z]{10}$/', $bvid)) {
            return null;
        }

        if ($bvid === '' && !preg_match('/^\d{1,12}$/', $aid)) {
            return null;
        }

        $page  = max(1, (int) ($query['p'] ?? $query['page'] ?? 1));
        $start = self::seconds((string) ($query['t'] ?? ''));
        $key   = $bvid !== '' ? 'bvid=' . $bvid : 'aid=' . $aid;

        return self::result(
            'bilibili',
            $bvid !== '' ? $bvid : 'av' . $aid,
            $start,
            'https://player.bilibili.com/player.html?' . $key . '&page=' . $page . '&high_quality=1&danmaku=0' . ($start ? '&t=' . $start : ''),
            'https://www.bilibili.com/video/' . ($bvid !== '' ? $bvid : 'av' . $aid)
                . ($page > 1 || $start ? '?' . ltrim(($page > 1 ? '&p=' . $page : '') . ($start ? '&t=' . $start : ''), '&') : '')
        );
    }

    private static function result(string $provider, string $id, int $start, string $embed, string $watch): array
    {
        return ['provider' => $provider, 'id' => $id, 'start' => $start, 'embed' => $embed, 'watch' => $watch, 'file' => null];
    }
}
