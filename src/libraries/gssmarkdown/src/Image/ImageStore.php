<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Image;

use enshrined\svgSanitize\Sanitizer;
use Joomla\Filesystem\Path;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Stores uploaded images, keeps unsaved ones in a protected temporary folder and moves
 * them next to the article once it has an id.
 *
 * Layout (all relative to the site root):
 *
 *   {tmpDir}/{draft_key}/.owner                 who uploaded (user id)
 *   {tmpDir}/{draft_key}/{hash}_{name}.{ext}    images of an article not saved yet
 *   {tmpDir}/{draft_key}/.moved                 JSON: file => final URL, after the move
 *   {postDir}/{post_id}/{hash}_{name}.{ext}     final location
 *
 * Temporary images are never served directly (the folder is denied by .htaccess /
 * web.config); the editor shows them through the authenticated com_ajax endpoint, whose
 * URL is rewritten to the final path when the article is saved.
 */
final class ImageStore
{
    public const TMP_QUERY = 'index.php?option=com_ajax&group=editors&plugin=gssmarkdownupload&format=raw&gssmd_tmp=';

    private const DRAFT_KEY = '[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';
    private const FILE_NAME = '[0-9a-f]{8}_[A-Za-z0-9._-]{1,80}';

    private const MIME = [
        'png'  => ['image/png'],
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml', 'text/xml', 'application/xml'],
    ];

    private const IMAGETYPE = [
        'image/png'  => IMAGETYPE_PNG,
        'image/jpeg' => IMAGETYPE_JPEG,
        'image/gif'  => IMAGETYPE_GIF,
        'image/webp' => IMAGETYPE_WEBP,
    ];

    /** @var callable(string, string): bool */
    private $mover;

    /** @var callable(string, mixed...): string */
    private $translate;

    /**
     * @param   string     $siteRoot     Absolute site root (JPATH_ROOT).
     * @param   string     $rootUrl      Site root URL path without trailing slash (Uri::root(true)), e.g. '' or '/blog'.
     * @param   string     $tmpDir       Temp folder relative to the site root.
     * @param   int        $maxBytes     Per-file limit.
     * @param   int        $maxDirBytes  Limit for one draft / one article folder.
     * @param   bool       $allowSvg     SVG uploads (always sanitised when allowed).
     * @param   ?callable  $mover        fn(string $from, string $to): bool. Defaults to move_uploaded_file.
     * @param   ?callable  $translate    fn(string $key, ...$args): string for error messages.
     */
    public function __construct(
        private readonly string $siteRoot,
        private readonly string $rootUrl,
        private readonly string $tmpDir = 'images/easyblog_articles/.tmp',
        private readonly int $maxBytes = 5 * 1024 * 1024,
        private readonly int $maxDirBytes = 200 * 1024 * 1024,
        private readonly bool $allowSvg = false,
        ?callable $mover = null,
        ?callable $translate = null
    ) {
        $this->mover     = $mover ?? 'move_uploaded_file';
        $this->translate = $translate ?? static fn (string $key, ...$args) => $key . ($args ? ' ' . implode(' ', $args) : '');
    }

    public static function isDraftKey(string $key): bool
    {
        return (bool) preg_match('/^' . self::DRAFT_KEY . '$/', $key);
    }

    public static function newDraftKey(): string
    {
        $b    = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0f) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    /**
     * Validates and stores one uploaded file.
     *
     * @param   string   $source        Path of the uploaded file (PHP's tmp_name).
     * @param   string   $originalName  Client file name, used for the extension and readability.
     * @param   string   $targetRelDir  Destination folder relative to the site root: a post folder,
     *                                  or tmpRelDir($draftKey) for unsaved articles.
     * @param   string   $publicUrlBase URL prefix the file will be reachable at (without the file name).
     * @param   ?string  $draftKey      Set when storing into the temp folder.
     * @param   int      $userId        Uploader, recorded as the draft owner.
     */
    public function store(
        string $source,
        string $originalName,
        int $size,
        string $targetRelDir,
        string $publicUrlBase,
        ?string $draftKey,
        int $userId
    ): StoredImage {
        if ($size <= 0 || !is_file($source)) {
            throw new UploadException(UploadException::BAD_REQUEST, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_NO_FILE'));
        }

        if ($size > $this->maxBytes || filesize($source) > $this->maxBytes) {
            throw new UploadException(
                UploadException::FILE_TOO_LARGE,
                $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_FILE_TOO_LARGE', round($this->maxBytes / 1048576, 1))
            );
        }

        $ext  = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $mime = $this->detectMime($source);

        if (!isset(self::MIME[$ext]) || !\in_array($mime, self::MIME[$ext], true) || ($ext === 'svg' && !$this->allowSvg)) {
            throw new UploadException(UploadException::INVALID_TYPE, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_INVALID_TYPE'));
        }

        $width = $height = null;

        if ($ext === 'svg') {
            $this->sanitizeSvg($source);
        } else {
            $info = @getimagesize($source);

            // The bytes must decode as the same image type finfo detected.
            if ($info === false || $info[2] !== self::IMAGETYPE[$mime]) {
                throw new UploadException(UploadException::INVALID_TYPE, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_INVALID_TYPE'));
            }

            [$width, $height] = $info;
        }

        $dir = $this->absolute($targetRelDir);

        if ($draftKey !== null) {
            $this->claimDraft($draftKey, $userId);
        } elseif (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UploadException(UploadException::WRITE_FAILED, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_WRITE_FAILED'));
        }

        if ($this->dirSize($dir) + $size > $this->maxDirBytes) {
            throw new UploadException(UploadException::QUOTA_EXCEEDED, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_QUOTA_EXCEEDED'));
        }

        $filename = bin2hex(random_bytes(4)) . '_' . self::safeName(pathinfo($originalName, PATHINFO_FILENAME)) . '.' . $ext;
        $target   = Path::check($dir . '/' . $filename, $this->siteRoot);

        if (!($this->mover)($source, $target)) {
            throw new UploadException(UploadException::WRITE_FAILED, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_WRITE_FAILED'));
        }

        @chmod($target, 0644);

        return new StoredImage($publicUrlBase . $filename, $filename, $width, $height, (int) filesize($target));
    }

    public function tmpRelDir(string $draftKey): string
    {
        $this->assertDraftKey($draftKey);

        return trim($this->tmpDir, '/') . '/' . $draftKey;
    }

    /**
     * URL prefix of a temp image, served by the upload endpoint.
     *
     * @param   string  $appBase  Uri::base(true) of the application the editor runs in
     *                            ('' on the site, '/administrator' in the back end).
     */
    public function tmpUrlPrefix(string $appBase, string $draftKey): string
    {
        $this->assertDraftKey($draftKey);

        return rtrim($appBase, '/') . '/' . self::TMP_QUERY . $draftKey . '/';
    }

    public function finalUrlPrefix(string $relDir): string
    {
        return $this->rootUrl . '/' . trim($relDir, '/') . '/';
    }

    /**
     * Resolves a temp image for the preview endpoint. Returns the absolute path, or the
     * final URL when the image was already moved (the endpoint then redirects).
     *
     * @return  array{path: ?string, movedTo: ?string}
     */
    public function resolveTmp(string $draftKey, string $file, int $userId, bool $isSuperUser): array
    {
        if (!self::isDraftKey($draftKey) || !preg_match('/^' . self::FILE_NAME . '$/', $file)) {
            throw new UploadException(UploadException::BAD_REQUEST, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_NO_FILE'));
        }

        $owner = $this->owner($draftKey);

        if ($owner === null || ($owner !== $userId && !$isSuperUser)) {
            throw new UploadException(UploadException::NO_AUTH, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));
        }

        $dir   = $this->absolute($this->tmpRelDir($draftKey));
        $moved = $this->readMoved($dir);

        if (isset($moved[$file])) {
            return ['path' => null, 'movedTo' => $moved[$file]];
        }

        $path = Path::check($dir . '/' . $file, $this->siteRoot);

        return ['path' => is_file($path) ? $path : null, 'movedTo' => null];
    }

    /**
     * Finds every temp image URL in a text.
     *
     * @return  array<string, array{key: string, file: string}>  Keyed by "key/file".
     */
    public function findTmpRefs(string $text): array
    {
        preg_match_all($this->tmpUrlPattern(), $text, $matches, PREG_SET_ORDER);

        $refs = [];

        foreach ($matches as $m) {
            $refs[$m['key'] . '/' . $m['file']] = ['key' => $m['key'], 'file' => $m['file']];
        }

        return $refs;
    }

    /**
     * Moves temp images into their final folder.
     *
     * Files already moved by an earlier save (EasyBlog autosave) are looked up in the
     * draft's .moved map, so a stale temp URL in the editor still resolves.
     *
     * @param   array   $refs          Output of findTmpRefs().
     * @param   string  $targetRelDir  e.g. images/easyblog_articles/123
     *
     * @return  array<string, string>  "key/file" => final URL. Refs that cannot be resolved are omitted.
     */
    public function relocate(array $refs, string $targetRelDir): array
    {
        $map = [];

        if (!$refs) {
            return $map;
        }

        $targetDir = $this->absolute($targetRelDir);

        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return $map;
        }

        $finalPrefix = $this->finalUrlPrefix($targetRelDir);
        $byDraft     = [];

        foreach ($refs as $id => $ref) {
            $byDraft[$ref['key']][$id] = $ref['file'];
        }

        foreach ($byDraft as $key => $files) {
            $draftDir = $this->absolute($this->tmpRelDir($key));

            if (!is_dir($draftDir)) {
                continue;
            }

            $moved = $this->readMoved($draftDir);

            foreach ($files as $id => $file) {
                if (isset($moved[$file])) {
                    $map[$id] = $moved[$file];

                    continue;
                }

                $from = Path::check($draftDir . '/' . $file, $this->siteRoot);
                $to   = Path::check($targetDir . '/' . $file, $this->siteRoot);

                if (is_file($from) && @rename($from, $to)) {
                    $moved[$file] = $finalPrefix . $file;
                    $map[$id]     = $moved[$file];
                }
            }

            file_put_contents($draftDir . '/.moved', json_encode($moved, JSON_UNESCAPED_SLASHES));
        }

        return $map;
    }

    /**
     * Replaces temp image URLs using a map from relocate(). Handles both the raw form
     * (Markdown) and the &amp;-escaped form (rendered HTML).
     */
    public function replaceTmpUrls(string $text, array $map): string
    {
        if (!$map) {
            return $text;
        }

        return preg_replace_callback(
            $this->tmpUrlPattern(),
            static fn (array $m) => $map[$m['key'] . '/' . $m['file']] ?? $m[0],
            $text
        );
    }

    /**
     * Deletes temp drafts not touched for $days days.
     *
     * @return  int  Number of drafts removed.
     */
    public function cleanup(int $days = 7): int
    {
        $root = $this->absolute($this->tmpDir);

        if (!is_dir($root)) {
            return 0;
        }

        $limit   = time() - $days * 86400;
        $removed = 0;

        foreach (new \DirectoryIterator($root) as $entry) {
            if ($entry->isDot() || !$entry->isDir() || !self::isDraftKey($entry->getFilename())) {
                continue;
            }

            if ($this->lastActivity($entry->getPathname()) < $limit) {
                $this->removeDir($entry->getPathname());
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * cleanup() at most once a day; cheap enough to call on every upload.
     */
    public function maybeCleanup(int $days = 7): void
    {
        $stamp = $this->absolute($this->tmpDir) . '/.last-cleanup';

        if (is_file($stamp) && filemtime($stamp) > time() - 86400) {
            return;
        }

        $this->ensureTmpRoot();
        touch($stamp);
        $this->cleanup($days);
    }

    /**
     * File name part: ASCII letters, digits, dash and underscore only, so URLs need no
     * encoding and the files survive moves between servers / file systems. No dots: a name
     * like "x.php.png" would be run as PHP by servers that map handlers on any extension
     * (Apache AddHandler), and a valid image can carry code in its metadata.
     */
    public static function safeName(string $name): string
    {
        if (\function_exists('transliterator_transliterate')) {
            $name = transliterator_transliterate('Any-Latin; Latin-ASCII', $name) ?: $name;
        } else {
            $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        }

        $name = preg_replace('/[^A-Za-z0-9_-]+/', '-', $name) ?? '';
        $name = trim(preg_replace('/-{2,}/', '-', $name) ?? '', '-._');

        return substr($name, 0, 60) ?: 'image';
    }

    private function claimDraft(string $draftKey, int $userId): void
    {
        $this->ensureTmpRoot();

        $dir = $this->absolute($this->tmpRelDir($draftKey));

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new UploadException(UploadException::WRITE_FAILED, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_WRITE_FAILED'));
        }

        $owner = $this->owner($draftKey);

        if ($owner === null) {
            file_put_contents($dir . '/.owner', (string) $userId);
        } elseif ($owner !== $userId) {
            throw new UploadException(UploadException::NO_AUTH, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));
        }

        touch($dir);
    }

    private function owner(string $draftKey): ?int
    {
        $file = $this->absolute($this->tmpRelDir($draftKey)) . '/.owner';

        return is_file($file) ? (int) file_get_contents($file) : null;
    }

    /**
     * The temp root is never readable over HTTP; images are streamed by the endpoint.
     */
    private function ensureTmpRoot(): void
    {
        $root = $this->absolute($this->tmpDir);

        if (!is_dir($root) && !@mkdir($root, 0755, true) && !is_dir($root)) {
            throw new UploadException(UploadException::WRITE_FAILED, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_WRITE_FAILED'));
        }

        if (!is_file($root . '/.htaccess')) {
            file_put_contents(
                $root . '/.htaccess',
                "# GSS Markdown: unsaved drafts are private.\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
            );
        }

        if (!is_file($root . '/web.config')) {
            file_put_contents(
                $root . '/web.config',
                "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
                . "    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n"
                . "  </system.webServer>\n</configuration>\n"
            );
        }

        if (!is_file($root . '/index.html')) {
            file_put_contents($root . '/index.html', '<!DOCTYPE html><title></title>');
        }
    }

    private function sanitizeSvg(string $path): void
    {
        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(true);

        $clean = $sanitizer->sanitize((string) file_get_contents($path));

        if (!\is_string($clean) || stripos($clean, '<svg') === false) {
            throw new UploadException(UploadException::INVALID_TYPE, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_INVALID_TYPE'));
        }

        file_put_contents($path, $clean);
    }

    private function detectMime(string $path): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);

        return (string) $finfo->file($path);
    }

    private function tmpUrlPattern(): string
    {
        $amp = '(?:&amp;|&)';

        return '#[^\s"\'()<>\[\]]*index\.php\?option=com_ajax' . $amp . 'group=editors' . $amp
            . 'plugin=gssmarkdownupload' . $amp . 'format=raw' . $amp
            . 'gssmd_tmp=(?<key>' . self::DRAFT_KEY . ')(?:/|%2F)(?<file>' . self::FILE_NAME . ')#i';
    }

    private function readMoved(string $draftDir): array
    {
        $file = $draftDir . '/.moved';

        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);

        return \is_array($data) ? $data : [];
    }

    private function lastActivity(string $dir): int
    {
        $latest = (int) filemtime($dir);

        foreach (new \DirectoryIterator($dir) as $f) {
            if (!$f->isDot()) {
                $latest = max($latest, (int) $f->getMTime());
            }
        }

        return $latest;
    }

    private function dirSize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $total = 0;

        foreach (new \DirectoryIterator($dir) as $f) {
            if ($f->isFile()) {
                $total += $f->getSize();
            }
        }

        return $total;
    }

    private function removeDir(string $dir): void
    {
        $dir = Path::check($dir, $this->absolute($this->tmpDir));

        foreach (new \DirectoryIterator($dir) as $f) {
            if ($f->isFile() || $f->isLink()) {
                @unlink($f->getPathname());
            }
        }

        @rmdir($dir);
    }

    private function absolute(string $relDir): string
    {
        return Path::check(rtrim($this->siteRoot, '/\\') . '/' . trim($relDir, '/'), $this->siteRoot);
    }

    private function assertDraftKey(string $key): void
    {
        if (!self::isDraftKey($key)) {
            throw new UploadException(UploadException::BAD_REQUEST, $this->t('PLG_EDITORS_GSSMARKDOWN_ERR_BAD_DRAFT'));
        }
    }

    private function t(string $key, ...$args): string
    {
        return ($this->translate)($key, ...$args);
    }
}
