<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\ContentCodec;
use Gss\Library\GssMarkdown\MarkdownRenderer;
use Joomla\Filter\InputFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentCodecTest extends TestCase
{
    private ContentCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new ContentCodec();
    }

    public static function markdownSamples(): array
    {
        return [
            'cjk + comment terminators' => ["# 標題 --> 注意\n\n內文 -- 還有 <!-- 註解 -->\n"],
            'code with html'            => ["```html\n<script>alert('x')</script>\n```\n"],
            'emoji and symbols'         => ["😀 ~~刪除~~ **粗體** `a && b` \$x\$\n"],
            'crlf and trailing spaces'  => ["line one  \r\nline two\r\n\r\n- item\r\n"],
            'attribute breakers'        => ["\" ' > < & \\\" data-gssmd=\"v1:x\"\n"],
            'empty'                     => [''],
            'large'                     => [str_repeat("段落文字 paragraph with `code` and [link](https://example.com)\n\n", 3000)],
        ];
    }

    #[DataProvider('markdownSamples')]
    public function testRoundTripIsLossless(string $markdown): void
    {
        $stored    = $this->codec->encode('<p>html</p>', $markdown);
        $extracted = $this->codec->extract($stored);

        $this->assertNotNull($extracted);
        $this->assertSame($markdown, $extracted->markdown);
        $this->assertSame('<p>html</p>', $extracted->html);
        $this->assertTrue($extracted->inSync);
    }

    #[DataProvider('markdownSamples')]
    public function testCarrierCannotLeakIntoPage(string $markdown): void
    {
        $stored  = $this->codec->encode('<p>html</p>', $markdown);
        $carrier = substr($stored, \strlen('<p>html</p>'));

        // Only base64 inside the attribute: no quote, angle bracket or comment terminator.
        $this->assertMatchesRegularExpression('#^\s*<div class="gssmd-source" hidden="hidden" data-gssmd="v1:[0-9a-f]{16}:[A-Za-z0-9+/=]*"></div>$#', $carrier);
    }

    /**
     * EasyBlog runs Joomla's InputFilter (filterHtml) after content plugins; Joomla's own
     * per-group text filters use it too. HTML comments do not survive it; our carrier must.
     */
    public static function filters(): array
    {
        return [
            'EasyBlog / Joomla default blacklist' => [new InputFilter(['script', 'iframe', 'object', 'embed', 'style'], ['onclick', 'onerror', 'style'], 1, 1, 1)],
            'blacklist without lists'             => [new InputFilter([], [], 1, 1, 1)],
            'whitelist including div + attrs'     => [new InputFilter(['p', 'div', 'h1', 'ul', 'li', 'pre', 'code'], ['class', 'hidden', 'data-gssmd'], 0, 0, 1)],
        ];
    }

    #[DataProvider('filters')]
    public function testCarrierSurvivesJoomlaInputFilter(InputFilter $filter): void
    {
        $markdown = "# 標題\n\n- a\n- b\n\n```js\nconst a = '<b>';\n```\n";
        $html     = (new MarkdownRenderer())->render($markdown);
        $stored   = $this->codec->encode($html, $markdown);

        $filtered  = $filter->clean($stored, 'html');
        $extracted = $this->codec->extract($filtered);

        $this->assertNotNull($extracted, 'carrier stripped by InputFilter');
        $this->assertSame($markdown, $extracted->markdown);
        $this->assertTrue($extracted->inSync, 'filtering alone must not look like an external edit');
    }

    public function testHtmlCommentCarrierIsStrippedByInputFilter(): void
    {
        // Documents why the original <!--GSSMD--> format is not used for writing.
        $filter = new InputFilter(['script'], [], 1, 1, 1);
        $out    = $filter->clean('<p>x</p><!--GSSMD:v1:base64:' . base64_encode('# a') . '-->', 'html');

        $this->assertStringNotContainsString('GSSMD', $out);
    }

    public function testReadsTheSpecCommentFormat(): void
    {
        $content   = "<h1>標題</h1>\n<!--GSSMD:v1:base64:" . base64_encode("# 標題\n\n內容...") . '-->';
        $extracted = $this->codec->extract($content);

        $this->assertSame("# 標題\n\n內容...", $extracted->markdown);
        $this->assertSame('<h1>標題</h1>', $extracted->html);
    }

    public function testLegacyHtmlHasNoSource(): void
    {
        $this->assertNull($this->codec->extract('<p>舊文 {source}code{/source}</p>'));
        $this->assertNull($this->codec->extract(''));
    }

    public function testInvalidPayloadIsIgnored(): void
    {
        $this->assertNull($this->codec->extract('<div data-gssmd="v1:0123456789abcdef:%%%"></div>'));
        // Valid base64 of invalid UTF-8.
        $this->assertNull($this->codec->extract('<div data-gssmd="v1:0123456789abcdef:' . base64_encode("\xff\xfe") . '"></div>'));
    }

    public function testDetectsTextEditedInAnotherEditor(): void
    {
        $stored = $this->codec->encode('<p>第一段</p><p>second</p>', "第一段\n\nsecond");

        $edited = str_replace('second', 'second, edited in JCE', $stored);
        $this->assertFalse($this->codec->extract($edited)->inSync);

        // Formatting-only changes from filters / jQuery re-serialisation are not drift.
        $reformatted = str_replace('<p>second</p>', "\n<p class=\"x\">second</p>\n", $stored);
        $this->assertTrue($this->codec->extract($reformatted)->inSync);
    }

    public function testDraftCarrier(): void
    {
        $draft = '<p>preview</p>' . "\n" . $this->codec->encodeDraft("# 草稿\n");

        $this->assertTrue($this->codec->hasDraft($draft));
        $this->assertSame("# 草稿\n", $this->codec->extractDraft($draft));

        // jQuery (EasyBlog sanitizeHTML) serialises the boolean attribute as hidden="".
        $jquery = str_replace('<div hidden ', '<div hidden="" ', $draft);
        $this->assertSame("# 草稿\n", $this->codec->extractDraft($jquery));

        // A stored carrier is not a draft: content saved by other editors is never re-rendered.
        $this->assertFalse($this->codec->hasDraft($this->codec->encode('<p>x</p>', 'x')));
    }

    public function testRewriteChangesHtmlAndSource(): void
    {
        $stored    = $this->codec->encode('<p><img src="/old/a.png"></p>', '![a](/old/a.png)');
        $rewritten = $this->codec->rewrite($stored, static fn (string $t) => str_replace('/old/', '/new/', $t));
        $extracted = $this->codec->extract($rewritten);

        $this->assertSame('![a](/new/a.png)', $extracted->markdown);
        $this->assertStringContainsString('/new/a.png', $extracted->html);
        $this->assertTrue($extracted->inSync);
    }

    public function testRewriteKeepsDriftVisible(): void
    {
        $stored  = $this->codec->encode('<p>text</p>', 'text');
        $drifted = str_replace('<p>text</p>', '<p>text changed elsewhere</p>', $stored);

        $rewritten = $this->codec->rewrite($drifted, static fn (string $t) => $t . ' ');

        $this->assertFalse($this->codec->extract($rewritten)->inSync);
    }

    public function testStripRemovesAllCarriers(): void
    {
        $content = '<p>a</p>' . $this->codec->encode('', 'x') . '<!--GSSMD:v1:base64:eA==-->' . $this->codec->encodeDraft('y');

        $this->assertSame('<p>a</p>', $this->codec->strip($content));
    }
}
