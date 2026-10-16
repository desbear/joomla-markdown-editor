<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MarkdownRendererTest extends TestCase
{
    public function testTechnicalArticleElements(): void
    {
        $markdown = <<<MD
        # 標題

        ## 小節

        一般段落，含 **粗體**、*斜體*、~~刪除線~~、`inline code` 與 [連結](https://www.gss.com.tw)。

        - 項目一
          - 巢狀項目
        - 項目二

        1. 第一
        2. 第二

        - [x] 已完成
        - [ ] 待辦

        > 引用文字

        | 欄位 | 數值 |
        |:-----|-----:|
        | A    | 1    |

        ```csharp
        var x = new List<int>();
        ```

        ![截圖](/images/easyblog_articles/12/abcd1234_shot.png)
        MD;

        $html = (new MarkdownRenderer())->render($markdown);

        $this->assertStringContainsString('<h1>標題</h1>', $html);
        $this->assertStringContainsString('<h2>小節</h2>', $html);
        $this->assertStringContainsString('<strong>粗體</strong>', $html);
        $this->assertStringContainsString('<del>刪除線</del>', $html);
        $this->assertStringContainsString('<a href="https://www.gss.com.tw">連結</a>', $html);
        $this->assertMatchesRegularExpression('#<ul>\s*<li>項目一\s*<ul>\s*<li>巢狀項目</li>#', $html);
        $this->assertStringContainsString('<ol>', $html);
        $this->assertMatchesRegularExpression('#<input checked="[^"]*" disabled="[^"]*" type="checkbox" ?/?> 已完成#', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<th align="left">欄位</th>', $html);
        $this->assertStringContainsString('<td align="right">1</td>', $html);
        $this->assertStringContainsString('<pre class="gssmd-code"><code class="language-csharp">var x = new List&lt;int&gt;();', $html);
        $this->assertStringContainsString('<img src="/images/easyblog_articles/12/abcd1234_shot.png" alt="截圖"', $html);
    }

    public static function languages(): array
    {
        return [
            ['js', 'js'], ['C#', 'csharp'], ['vb', 'vbnet'], ['console', 'shell-session'],
            ['ts {linenos}', 'ts'], ['"><script>', 'script'],
        ];
    }

    #[DataProvider('languages')]
    public function testCodeLanguageClass(string $info, string $class): void
    {
        $html = (new MarkdownRenderer())->render("```{$info}\ncode\n```");

        $this->assertStringContainsString('<code class="language-' . $class . '">', $html);
    }

    public function testCodeWithoutLanguage(): void
    {
        $html = (new MarkdownRenderer())->render("    indented\n");

        $this->assertStringContainsString('<pre class="gssmd-code"><code>indented', $html);
    }

    public static function attacks(): array
    {
        return [
            'script tag'          => ['<script>alert(1)</script>'],
            'inline handler'      => ['<img src=x onerror=alert(1)>'],
            'javascript link'     => ['[x](javascript:alert(1))'],
            'javascript entity'   => ['[x](&#106;avascript:alert(1))'],
            'data link'           => ['[x](data:text/html;base64,PHNjcmlwdD4=)'],
            'vbscript image'      => ['![x](vbscript:msgbox)'],
            'iframe'              => ['<iframe src="https://evil"></iframe>'],
            'style attribute'     => ['<p style="position:fixed">x</p>'],
            'svg onload'          => ['<svg onload=alert(1)><circle/></svg>'],
            'closing our carrier' => ['</div><div data-gssmd="v1:0000000000000000:eA==">x'],
        ];
    }

    #[DataProvider('attacks')]
    public function testEscapeModeOutputIsSafe(string $markdown): void
    {
        $this->assertSafeHtml((new MarkdownRenderer())->render($markdown));
    }

    #[DataProvider('attacks')]
    public function testWhitelistModeOutputIsSafe(string $markdown): void
    {
        $this->assertSafeHtml((new MarkdownRenderer('whitelist'))->render($markdown));
    }

    /**
     * Parses the output as a browser would and checks what actually becomes markup.
     */
    private function assertSafeHtml(string $html): void
    {
        $this->assertStringNotContainsStringIgnoringCase('<script', $html);

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>', LIBXML_NOERROR);

        foreach ((new \DOMXPath($doc))->query('//body//*') as $el) {
            $this->assertNotContains(strtolower($el->nodeName), ['script', 'iframe', 'style', 'svg', 'object', 'embed'], $html);

            foreach ($el->attributes as $attr) {
                $name = strtolower($attr->name);
                $this->assertStringStartsNotWith('on', $name, $html);
                $this->assertNotSame('style', $name, $html);
                $this->assertStringStartsNotWith('data-gssmd', $name, 'user content must not forge a carrier: ' . $html);

                if (\in_array($name, ['href', 'src'], true)) {
                    $this->assertDoesNotMatchRegularExpression('/^\s*(javascript|vbscript|data):/i', $attr->value, $html);
                }
            }
        }
    }

    public function testWhitelistKeepsListedTagsOnly(): void
    {
        $html = (new MarkdownRenderer('whitelist'))->render('<kbd>Ctrl</kbd> <span onclick="x()" style="color:red">s</span> <marquee>m</marquee>');

        $this->assertStringContainsString('<kbd>Ctrl</kbd>', $html);
        $this->assertStringContainsString('<span>s</span>', $html);
        $this->assertStringNotContainsString('<marquee', $html);
    }

    public function testDangerousTagsCannotBeWhitelisted(): void
    {
        $html = (new MarkdownRenderer('whitelist', ['script', 'iframe', 'form', 'style', 'kbd']))
            ->render("<script>alert(1)</script>\n\n<form action=/x><input name=a></form>\n\n<style>*{}</style>");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('<form', $html);
        $this->assertStringNotContainsString('<style', $html);
    }

    public function testEscapeModeShowsHtmlAsText(): void
    {
        $html = (new MarkdownRenderer())->render('Press <kbd>Ctrl</kbd>');

        $this->assertStringContainsString('&lt;kbd&gt;Ctrl&lt;/kbd&gt;', $html);
    }

    public function testWindowsLineEndings(): void
    {
        $renderer = new MarkdownRenderer();

        $this->assertSame($renderer->render("# a\n\n- b\n- c\n"), $renderer->render("# a\r\n\r\n- b\r\n- c\r\n"));
    }
}
