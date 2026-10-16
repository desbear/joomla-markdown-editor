<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\Image\ImageStore;
use Gss\Library\GssMarkdown\Image\UploadException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageStoreTest extends TestCase
{
    private const TMP = 'images/easyblog_articles/.tmp';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/gssmd-test-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/images', 0755, true);
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }

        rmdir($this->root);
    }

    private function store(array $overrides = []): ImageStore
    {
        $o = $overrides + ['maxBytes' => 5 * 1048576, 'maxDir' => 200 * 1048576, 'svg' => false];

        // copy() instead of move_uploaded_file(): these are not real uploads.
        return new ImageStore($this->root, '/site', self::TMP, $o['maxBytes'], $o['maxDir'], $o['svg'], 'copy');
    }

    private function fixture(string $type, int $w = 40, int $h = 30): string
    {
        $img  = imagecreatetruecolor($w, $h);
        $path = tempnam(sys_get_temp_dir(), 'gssmd');

        match ($type) {
            'png'  => imagepng($img, $path),
            'jpg'  => imagejpeg($img, $path),
            'gif'  => imagegif($img, $path),
            'webp' => imagewebp($img, $path),
        };

        return $path;
    }

    private function upload(ImageStore $store, string $path, string $name, ?string $key, int $user = 42, ?string $dir = null): \Gss\Library\GssMarkdown\Image\StoredImage
    {
        $dir ??= $store->tmpRelDir($key);
        $url   = $key ? $store->tmpUrlPrefix('/administrator', $key) : $store->finalUrlPrefix($dir);

        return $store->store($path, $name, filesize($path), $dir, $url, $key, $user);
    }

    private function expectError(string $code, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected $code");
        } catch (UploadException $e) {
            $this->assertSame($code, $e->errorCode);
        }
    }

    public static function imageTypes(): array
    {
        return [['png'], ['jpg'], ['gif'], ['webp']];
    }

    #[DataProvider('imageTypes')]
    public function testStoresAllowedImagesInTheDraftFolder(string $type): void
    {
        $store = $this->store();
        $key   = ImageStore::newDraftKey();
        $image = $this->upload($store, $this->fixture($type), "螢幕 截圖 (1).$type", $key);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}_[A-Za-z0-9._-]+\.' . $type . '$/', $image->filename);
        $this->assertSame(40, $image->width);
        $this->assertSame(30, $image->height);
        $this->assertSame(
            '/administrator/index.php?option=com_ajax&group=editors&plugin=gssmarkdownupload&format=raw&gssmd_tmp=' . $key . '/' . $image->filename,
            $image->url
        );
        $this->assertFileExists($this->root . '/' . self::TMP . "/$key/" . $image->filename);
        $this->assertSame('42', file_get_contents($this->root . '/' . self::TMP . "/$key/.owner"));
    }

    public function testTempFolderIsNotWebReadable(): void
    {
        $store = $this->store();
        $this->upload($store, $this->fixture('png'), 'a.png', ImageStore::newDraftKey());

        $this->assertStringContainsString('Require all denied', file_get_contents($this->root . '/' . self::TMP . '/.htaccess'));
        $this->assertStringContainsString('<deny users="*" />', file_get_contents($this->root . '/' . self::TMP . '/web.config'));
    }

    public function testStoresDirectlyIntoAPostFolder(): void
    {
        $store = $this->store();
        $image = $this->upload($store, $this->fixture('png'), 'shot.png', null, 42, 'images/easyblog_articles/123');

        $this->assertSame('/site/images/easyblog_articles/123/' . $image->filename, $image->url);
        $this->assertFileExists($this->root . '/images/easyblog_articles/123/' . $image->filename);
    }

    public function testRejectsPhpRenamedToPng(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gssmd');
        // Harmless PHP: a real web-shell sample gets deleted by endpoint security on dev machines.
        file_put_contents($path, "<?php echo 'not an image'; ?>");

        $this->expectError(UploadException::INVALID_TYPE, fn () => $this->upload($this->store(), $path, 'shell.png', ImageStore::newDraftKey()));
    }

    public function testRejectsPolyglotWithImageHeaderButPhpExtension(): void
    {
        $path = $this->fixture('gif');
        file_put_contents($path, '<?php echo 1; ?>', FILE_APPEND);

        $this->expectError(UploadException::INVALID_TYPE, fn () => $this->upload($this->store(), $path, 'x.php', ImageStore::newDraftKey()));
        $this->expectError(UploadException::INVALID_TYPE, fn () => $this->upload($this->store(), $path, 'x.png.php', ImageStore::newDraftKey()));
    }

    public function testRejectsExtensionThatDoesNotMatchContent(): void
    {
        $this->expectError(UploadException::INVALID_TYPE, fn () => $this->upload($this->store(), $this->fixture('png'), 'photo.jpg', ImageStore::newDraftKey()));
    }

    public function testSvgIsRejectedByDefault(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gssmd');
        file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');

        $this->expectError(UploadException::INVALID_TYPE, fn () => $this->upload($this->store(), $path, 'a.svg', ImageStore::newDraftKey()));
    }

    public function testSvgIsSanitisedWhenAllowed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'gssmd');
        file_put_contents($path, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)">'
            . '<script>alert(2)</script><a href="javascript:alert(3)"><rect width="1" height="1"/></a></svg>');

        $key   = ImageStore::newDraftKey();
        $image = $this->upload($this->store(['svg' => true]), $path, 'a.svg', $key);
        $saved = file_get_contents($this->root . '/' . self::TMP . "/$key/" . $image->filename);

        $this->assertStringContainsString('<rect', $saved);
        $this->assertStringNotContainsString('script', $saved);
        $this->assertStringNotContainsString('onload', $saved);
        $this->assertStringNotContainsString('javascript:', $saved);
    }

    public function testFileTooLarge(): void
    {
        $this->expectError(UploadException::FILE_TOO_LARGE, fn () => $this->upload($this->store(['maxBytes' => 100]), $this->fixture('png', 200, 200), 'big.png', ImageStore::newDraftKey()));
    }

    public function testQuotaPerFolder(): void
    {
        $file  = $this->fixture('png', 100, 100);
        $store = $this->store(['maxDir' => (int) (filesize($file) * 1.5)]);
        $key   = ImageStore::newDraftKey();

        $this->upload($store, $file, 'a.png', $key);
        $this->expectError(UploadException::QUOTA_EXCEEDED, fn () => $this->upload($store, $file, 'b.png', $key));
    }

    public function testCannotUploadIntoAnotherUsersDraft(): void
    {
        $store = $this->store();
        $key   = ImageStore::newDraftKey();

        $this->upload($store, $this->fixture('png'), 'a.png', $key, 42);
        $this->expectError(UploadException::NO_AUTH, fn () => $this->upload($store, $this->fixture('png'), 'b.png', $key, 7));
    }

    public static function badKeys(): array
    {
        return [['../../../etc'], ['..'], [''], ['not-a-uuid'], ['00000000-0000-0000-0000-000000000000/..']];
    }

    #[DataProvider('badKeys')]
    public function testRejectsMalformedDraftKeys(string $key): void
    {
        $this->expectError(UploadException::BAD_REQUEST, fn () => $this->store()->tmpRelDir($key));
    }

    public function testTmpPreviewOnlyForTheOwner(): void
    {
        $store = $this->store();
        $key   = ImageStore::newDraftKey();
        $image = $this->upload($store, $this->fixture('png'), 'a.png', $key, 42);

        $this->assertNotNull($store->resolveTmp($key, $image->filename, 42, false)['path']);
        $this->assertNotNull($store->resolveTmp($key, $image->filename, 7, true)['path'], 'super users may view');
        $this->expectError(UploadException::NO_AUTH, fn () => $store->resolveTmp($key, $image->filename, 7, false));
        $this->expectError(UploadException::BAD_REQUEST, fn () => $store->resolveTmp($key, '../../configuration.php', 42, false));
        $this->expectError(UploadException::BAD_REQUEST, fn () => $store->resolveTmp($key, '.owner', 42, false));
    }

    public function testFindsTempUrlsInMarkdownAndHtml(): void
    {
        $store = $this->store();
        $key   = ImageStore::newDraftKey();
        $url   = $store->tmpUrlPrefix('/administrator', $key) . 'abcd1234_a.png';
        $html  = str_replace('&', '&amp;', $url);

        $text = "![a]($url)\n<p><img src=\"$html\" alt=\"a\"></p>\n![b](" . $store->tmpUrlPrefix('', $key) . 'ffff0000_b.png)';
        $refs = $store->findTmpRefs($text);

        $this->assertSame(["$key/abcd1234_a.png", "$key/ffff0000_b.png"], array_keys($refs));
    }

    public function testRelocateMovesFilesAndRewritesEveryForm(): void
    {
        $store = $this->store();
        $key   = ImageStore::newDraftKey();
        $image = $this->upload($store, $this->fixture('png'), 'shot.png', $key);
        $html  = str_replace('&', '&amp;', $image->url);
        $text  = "![shot]({$image->url})\n<img src=\"$html\">";

        $map = $store->relocate($store->findTmpRefs($text), 'images/easyblog_articles/77');
        $out = $store->replaceTmpUrls($text, $map);

        $final = '/site/images/easyblog_articles/77/' . $image->filename;
        $this->assertSame("![shot]($final)\n<img src=\"$final\">", $out);
        $this->assertFileExists($this->root . '/images/easyblog_articles/77/' . $image->filename);
        $this->assertFileDoesNotExist($this->root . '/' . self::TMP . "/$key/" . $image->filename);
    }

    public function testStaleTempUrlStillResolvesAfterTheMove(): void
    {
        // EasyBlog autosave moves the images while the editor still holds the temp URL.
        $store = $this->store();
        $key   = ImageStore::newDraftKey();
        $image = $this->upload($store, $this->fixture('png'), 'shot.png', $key);
        $refs  = $store->findTmpRefs($image->url);

        $first  = $store->relocate($refs, 'images/easyblog_articles/77');
        $second = $store->relocate($refs, 'images/easyblog_articles/77');

        $this->assertSame($first, $second, 'relocate is idempotent');
        $this->assertSame($first["$key/{$image->filename}"], $store->resolveTmp($key, $image->filename, 42, false)['movedTo']);
    }

    public function testUnknownRefsAreLeftUntouched(): void
    {
        $store = $this->store();
        $key   = ImageStore::newDraftKey();
        $text  = '![x](' . $store->tmpUrlPrefix('', $key) . 'deadbeef_missing.png)';

        $map = $store->relocate($store->findTmpRefs($text), 'images/easyblog_articles/77');

        $this->assertSame([], $map);
        $this->assertSame($text, $store->replaceTmpUrls($text, $map));
    }

    public function testCleanupRemovesOnlyExpiredDrafts(): void
    {
        $store = $this->store();
        $old   = ImageStore::newDraftKey();
        $new   = ImageStore::newDraftKey();

        $this->upload($store, $this->fixture('png'), 'a.png', $old);
        $this->upload($store, $this->fixture('png'), 'b.png', $new);

        $oldDir = $this->root . '/' . self::TMP . "/$old";
        foreach (glob("$oldDir/{,.}*", GLOB_BRACE) as $f) {
            if (is_file($f)) {
                touch($f, time() - 8 * 86400);
            }
        }
        touch($oldDir, time() - 8 * 86400);

        $this->assertSame(1, $store->cleanup(7));
        $this->assertDirectoryDoesNotExist($oldDir);
        $this->assertDirectoryExists($this->root . '/' . self::TMP . "/$new");
        $this->assertFileExists($this->root . '/' . self::TMP . '/.htaccess');
    }

    public static function names(): array
    {
        return [
            // Pinyin spelling depends on the ICU version, so only the character set is checked.
            ['螢幕擷取畫面 2026-09-23', null],
            ['../../etc/passwd', 'etc-passwd'],
            ["a\0b\nc", 'a-b-c'],
            ['Café résumé', 'Cafe-resume'],
            ['', 'image'],
            ['...', 'image'],
            // No dots survive: "shell.php.png" must never look like a PHP file to the web server.
            ['shell.php', 'shell-php'],
            ['a.phtml.b', 'a-phtml-b'],
        ];
    }

    #[DataProvider('names')]
    public function testSafeName(string $input, ?string $expected): void
    {
        $name = ImageStore::safeName($input);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $name);

        if ($expected !== null && \function_exists('transliterator_transliterate')) {
            $this->assertSame($expected, $name);
        }
    }
}
