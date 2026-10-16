<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\LegacyConverter;
use Gss\Library\GssMarkdown\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/** The toolbar's "convert HTML" dialog (ConvertController) uses LegacyConverter. */
final class HtmlConvertTest extends TestCase
{
    public function testPastedPageFragmentBecomesMarkdown(): void
    {
        // What Chrome puts on the clipboard when copying part of a web page.
        $html = "<meta charset='utf-8'><h2 style=\"color:red\">安裝步驟</h2><ol><li>下載 <a href=\"https://example.com/a.zip\">套件</a></li><li><strong>上傳</strong>到網站</li></ol>"
            . '<table><tr><th>欄位</th><th>說明</th></tr><tr><td>id</td><td>編號</td></tr></table>'
            . '<p><img src="https://example.com/s.png" alt="截圖"></p>';

        $md = (new LegacyConverter())->convert($html);

        $this->assertStringContainsString('## 安裝步驟', $md);
        $this->assertStringContainsString('1. 下載 [套件](https://example.com/a.zip)', $md);
        $this->assertStringContainsString('2. **上傳**到網站', $md);
        $this->assertStringContainsString('| 欄位 | 說明 |', $md);
        $this->assertStringContainsString('![截圖](https://example.com/s.png)', $md);
        $this->assertStringNotContainsString('color:red', $md);
        $this->assertSame([], LegacyConverter::droppedTags($html));
    }

    public function testReportsWhatIsRemoved(): void
    {
        $html = '<p>文</p><script>alert(1)</script><style>p{}</style>'
            . '<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>'
            . '<iframe src="https://maps.example/embed"></iframe><form><input></form>';

        $this->assertSame(['script', 'style', 'form', 'iframe'], LegacyConverter::droppedTags($html));

        $md = (new LegacyConverter())->convert($html);
        $this->assertStringNotContainsString('alert', $md);
        $this->assertStringContainsString('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $md, 'video players are kept');
    }

    public function testVideoIframesAloneAreNotReportedAsRemoved(): void
    {
        $this->assertSame([], LegacyConverter::droppedTags('<iframe width="560" src="https://player.vimeo.com/video/22439234"></iframe>'));
    }

    public function testConvertedMarkdownRendersWithoutRawHtml(): void
    {
        $md   = (new LegacyConverter())->convert('<p onclick="x()">你好<span style="x">世界</span></p><div><img src=x onerror=alert(1)></div>');
        $html = (new MarkdownRenderer())->render($md);

        $this->assertStringNotContainsString('onclick', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringContainsString('你好世界', $html);
    }
}
