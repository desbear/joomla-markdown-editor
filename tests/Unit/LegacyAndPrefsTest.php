<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\LegacyConverter;
use Gss\Plugin\Editors\GssMarkdown\Controller\PrefsController;
use PHPUnit\Framework\TestCase;

final class LegacyAndPrefsTest extends TestCase
{
    public function testConvertsCommonHtmlAndDropsWhatMarkdownCannotHold(): void
    {
        $html = '<h2>舊文</h2><p>有 <strong>粗體</strong> 與 <a href="https://x.tw">連結</a></p>'
            . '<ul><li>a</li><li>b</li></ul>'
            . '<pre><code class="language-js">const a = 1;</code></pre>'
            . '<table><tr><th>h</th></tr><tr><td>v</td></tr></table>'
            . '<div class="accordion" onclick="x()"><span style="color:red">手風琴內容</span></div>'
            . '<script>alert(1)</script><p>{source}code{/source}</p>';

        $md = (new LegacyConverter())->convert($html);

        $this->assertStringContainsString('## 舊文', $md);
        $this->assertStringContainsString('**粗體**', $md);
        $this->assertStringContainsString('[連結](https://x.tw)', $md);
        $this->assertMatchesRegularExpression('/^- a$/m', $md);
        $this->assertStringContainsString("```js\nconst a = 1;\n```", $md);
        $this->assertStringContainsString('| h |', $md);
        $this->assertStringContainsString('手風琴內容', $md, 'text of unsupported blocks is kept');
        $this->assertStringNotContainsString('<div', $md);
        $this->assertStringNotContainsString('alert(1)', $md);
        $this->assertStringContainsString('{source}code{/source}', $md, 'shortcodes stay visible as text');
    }

    public function testEmpty(): void
    {
        $this->assertSame('', (new LegacyConverter())->convert("  \n"));
    }

    public function testPreferencesAreValidated(): void
    {
        $this->assertSame(PrefsController::DEFAULTS, PrefsController::sanitize([]));
        $this->assertSame(PrefsController::DEFAULTS, PrefsController::sanitize([
            'theme' => 'neon', 'font' => 'comic', 'fontSize' => 'big', 'view' => 'wysiwyg', 'extra' => '<script>',
        ]));

        $p = PrefsController::sanitize(['theme' => 'dark', 'font' => 'sans', 'fontSize' => 99, 'lineNumbers' => 1, 'ratio' => 0.95, 'view' => 'preview', 'scrollSync' => 0, 'zoom' => 'actual']);
        $this->assertSame(['theme' => 'dark', 'font' => 'sans', 'fontSize' => 24, 'lineNumbers' => true, 'ratio' => 0.8, 'view' => 'preview', 'scrollSync' => false, 'zoom' => 'actual'], $p);
    }

    public function testAutomaticSplitIsTheDefault(): void
    {
        $this->assertSame(0, PrefsController::sanitize([])['ratio']);
        $this->assertSame(0, PrefsController::sanitize(['ratio' => 0])['ratio']);
        $this->assertSame(0.2, PrefsController::sanitize(['ratio' => 0.01])['ratio']);
    }

    public function testNoPreferenceCanAffectThePreview(): void
    {
        // Personal settings only touch the editor pane and the layout.
        // "zoom" chooses the preview width (reflow to the pane or the site's column); never the styles.
        $this->assertSame(['theme', 'font', 'fontSize', 'lineNumbers', 'ratio', 'view', 'scrollSync', 'zoom'], array_keys(PrefsController::DEFAULTS));
    }
}
