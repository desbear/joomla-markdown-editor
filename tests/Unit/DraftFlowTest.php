<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\ContentCodec;
use Gss\Library\GssMarkdown\Context\ContextAdapter;
use Gss\Library\GssMarkdown\DraftProcessor;
use Gss\Library\GssMarkdown\Image\ImageFinalizer;
use Gss\Library\GssMarkdown\Image\ImageStore;
use Gss\Library\GssMarkdown\MarkdownRenderer;
use Joomla\CMS\User\User;
use Joomla\Filter\InputFilter;
use PHPUnit\Framework\TestCase;

/**
 * End-to-end on the server side, without Joomla booted:
 * editor submission -> server render -> component filter -> after-save image move -> re-open.
 */
final class DraftFlowTest extends TestCase
{
    private string $root;

    private ContentCodec $codec;

    private MarkdownRenderer $renderer;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/gssmd-flow-' . bin2hex(random_bytes(4));
        mkdir($this->root . '/images', 0755, true);
        $this->codec    = new ContentCodec();
        $this->renderer = new MarkdownRenderer();
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->root));
    }

    private function processor(): DraftProcessor
    {
        return new DraftProcessor($this->codec, fn (string $md) => $this->codec->encode($this->renderer->render($md), $md));
    }

    public function testReplacesBrowserHtmlWithServerRendering(): void
    {
        $post = [
            'jform' => [
                'title'       => 'x',
                // The browser claims harmless HTML but sends a script: it must be discarded.
                'articletext' => '<p>ok</p><script>steal()</script>' . "\n" . $this->codec->encodeDraft("# 標題\n\n內文"),
                'attribs'     => ['show_title' => '1'],
            ],
            'task' => 'article.apply',
        ];

        $changed = $this->processor()->process($post);

        $this->assertSame(['jform'], array_keys($changed));
        $this->assertSame('x', $changed['jform']['title']);
        $this->assertSame(['show_title' => '1'], $changed['jform']['attribs']);

        $stored = $changed['jform']['articletext'];
        $this->assertStringNotContainsString('<script', $stored);
        $this->assertStringNotContainsString('data-gssmd-draft', $stored);
        $this->assertStringStartsWith("<h1>標題</h1>\n<p>內文</p>", $stored);
        $this->assertSame("# 標題\n\n內文", $this->codec->extract($stored)->markdown);
    }

    public function testLeavesOtherEditorsSubmissionsAlone(): void
    {
        // JCE saving an article that still carries a stored Markdown carrier.
        $jce = '<p>edited in JCE</p>' . $this->codec->encode('<p>old</p>', 'old');

        $this->assertSame([], $this->processor()->process(['content' => $jce, 'other' => ['a' => 'b']]));
    }

    /**
     * The riskiest area: images pasted before the first save.
     */
    public function testNewArticleImagesEndUpInThePostFolderWithNoTempLeft(): void
    {
        $store = new ImageStore($this->root, '', 'images/easyblog_articles/.tmp', 5 << 20, 200 << 20, false, 'copy');
        $key   = ImageStore::newDraftKey();

        // 1. Author pastes two screenshots into a brand-new post (no id yet).
        $images = [];
        foreach (['first', 'second'] as $name) {
            $png = tempnam(sys_get_temp_dir(), 'gssmd');
            imagepng(imagecreatetruecolor(10, 10), $png);
            $images[] = $store->store($png, "$name.png", filesize($png), $store->tmpRelDir($key), $store->tmpUrlPrefix('/administrator', $key), $key, 5);
        }

        $markdown = "# 文章\n\n![first]({$images[0]->url})\n\n文字\n\n![second]({$images[1]->url})\n";

        // 2. Submit: browser draft -> server rendering (onAfterRoute).
        $changed = $this->processor()->process(['content' => $this->codec->encodeDraft($markdown)]);

        // 3. EasyBlog normalises/filters the HTML after content plugins.
        $filter = new InputFilter(['script', 'iframe'], ['onclick', 'style'], 1, 1, 1);
        $db     = ['post' => $filter->clean($changed['content'], 'html'), 'revision' => null];
        $db['revision'] = json_encode(['title' => 'x', 'intro' => $db['post'], 'content' => '']);

        // 4. onContentAfterSave: the post now has id 314.
        $adapter = new class ($db) implements ContextAdapter {
            public function __construct(public array $db)
            {
            }

            public function option(): string
            {
                return 'com_easyblog';
            }

            public function saveContexts(): array
            {
                return ['easyblog.blog'];
            }

            public function canCreate(User $user): bool
            {
                return true;
            }

            public function canEditItem(int $id, User $user): bool
            {
                return true;
            }

            public function isUnsaved(int $id): bool
            {
                return false;
            }

            public function itemImageDir(int $id): string
            {
                return 'images/easyblog_articles/' . $id;
            }

            public function samplePageUrl(?int $id): ?string
            {
                return null;
            }

            public function bodySelector(): string
            {
                return '[data-blog-content]';
            }

            public function idFromSavedItem(object $item): ?int
            {
                return $item->id ?? null;
            }

            public function rewriteStoredContent(int $id, callable $rewrite): void
            {
                $this->db['post'] = $rewrite($this->db['post']);
                $revision         = json_decode($this->db['revision']);
                $revision->intro  = $rewrite($revision->intro);
                $this->db['revision'] = json_encode($revision);
            }
        };

        $finalizer = new ImageFinalizer($store, $this->codec);
        $finalizer->finalize($adapter, 314);

        // Files moved.
        foreach ($images as $image) {
            $this->assertFileExists($this->root . '/images/easyblog_articles/314/' . $image->filename);
            $this->assertFileDoesNotExist($this->root . "/images/easyblog_articles/.tmp/$key/" . $image->filename);
        }

        // No temp URL anywhere: HTML, Markdown source, revision.
        $post      = $adapter->db['post'];
        $extracted = $this->codec->extract($post);
        $revision  = json_decode($adapter->db['revision'])->intro;

        foreach ([$post, $extracted->markdown, $revision, $this->codec->extract($revision)->markdown] as $text) {
            $this->assertStringNotContainsString('gssmd_tmp', $text);
            $this->assertStringNotContainsString('.tmp', $text);
        }

        $this->assertStringContainsString('<img src="/images/easyblog_articles/314/' . $images[0]->filename . '"', $post);
        $this->assertStringContainsString('![second](/images/easyblog_articles/314/' . $images[1]->filename . ')', $extracted->markdown);
        $this->assertTrue($extracted->inSync);

        // 5. Saving again (or an autosave) changes nothing.
        $before = $adapter->db;
        $finalizer->finalize($adapter, 314);
        $this->assertSame($before, $adapter->db);

        // 6. The editor tab still holds the old temp URLs (autosave case): the next submit
        //    renders them again and the after-save pass maps them via the .moved record.
        $again = $this->processor()->process(['content' => $this->codec->encodeDraft($markdown)])['content'];
        $fixed = $finalizer->rewrite($again, 'images/easyblog_articles/314');

        $this->assertStringNotContainsString('gssmd_tmp', $fixed);
        $this->assertSame($extracted->markdown, $this->codec->extract($fixed)->markdown);
    }

    public function testAnonymousSubmissionsAreBounded(): void
    {
        $render  = fn (string $md) => $this->codec->encode($this->renderer->render($md), $md);
        $limited = new DraftProcessor($this->codec, $render, 1024);

        $small = ['text' => $this->codec->encodeDraft('# 小')];
        $large = ['text' => $this->codec->encodeDraft(str_repeat('*a* ', 600))];

        $this->assertArrayHasKey('text', $limited->process($small), 'within the limit: rendered as usual');
        $this->assertSame([], $limited->process($large), 'over the limit: left exactly as submitted, nothing is rendered');
        $this->assertArrayHasKey('text', $this->processor()->process($large), 'logged-in authors have no limit');
    }
}
