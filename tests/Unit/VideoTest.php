<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\ContentCodec;
use Gss\Library\GssMarkdown\LegacyConverter;
use Gss\Library\GssMarkdown\MarkdownRenderer;
use Gss\Library\GssMarkdown\Video\YouTube;
use Joomla\Filter\InputFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoTest extends TestCase
{
    private const ID = 'dQw4w9WgXcQ';

    public static function urls(): array
    {
        return [
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 0],
            ['https://youtube.com/watch?v=dQw4w9WgXcQ&list=PL1&index=2', 0],
            ['https://m.youtube.com/watch?v=dQw4w9WgXcQ&t=90', 90],
            ['https://youtu.be/dQw4w9WgXcQ?t=1m30s', 90],
            ['youtu.be/dQw4w9WgXcQ', 0],
            ['https://www.youtube.com/shorts/dQw4w9WgXcQ', 0],
            ['https://www.youtube.com/live/dQw4w9WgXcQ?si=abc', 0],
            ['https://www.youtube.com/embed/dQw4w9WgXcQ?start=3723', 3723],
            ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', 0],
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ&amp;t=1h2m3s', 3723],
        ];
    }

    #[DataProvider('urls')]
    public function testParsesYouTubeUrls(string $url, int $start): void
    {
        $this->assertSame(['id' => self::ID, 'start' => $start], YouTube::parse($url));
    }

    public static function notVideos(): array
    {
        return [
            ['https://vimeo.com/123456'],
            ['https://www.youtube.com/'],
            ['https://www.youtube.com/channel/UC123'],
            ['https://www.youtube.com/watch?v=short'],
            ['https://www.youtube.com/embed/videoseries?list=PL123'],
            ['https://www.youtube.com/watch?v=dQw4w9WgXcQ"><script>'],
            ['https://evil.example/watch?v=dQw4w9WgXcQ'],
            ['https://youtube.com.evil.example/watch?v=dQw4w9WgXcQ'],
            ['javascript:alert(1)'],
        ];
    }

    #[DataProvider('notVideos')]
    public function testRejectsEverythingElse(string $url): void
    {
        $this->assertNull(YouTube::parse($url));
    }

    public function testOnlyAUrlOnItsOwnLineBecomesAVideo(): void
    {
        $html = (new MarkdownRenderer())->render(
            "https://www.youtube.com/watch?v=dQw4w9WgXcQ\n\n"
            . "<https://youtu.be/dQw4w9WgXcQ>\n\n"
            . "![示範影片](https://youtu.be/dQw4w9WgXcQ?t=90)\n\n"
            . "看這裡 https://youtu.be/dQw4w9WgXcQ 的說明\n\n"
            . "[連結文字](https://youtu.be/dQw4w9WgXcQ)\n\n"
            . "https://vimeo.com/123\n"
        );

        $this->assertSame(3, substr_count($html, 'class="gssmd-video"'));
        $this->assertStringContainsString('<div class="gssmd-video" data-youtube="dQw4w9WgXcQ" data-start="90" title="示範影片">', $html);
        $this->assertStringContainsString('<p>看這裡 <a href="https://youtu.be/dQw4w9WgXcQ">', $html, 'inside a sentence it stays a link');
        $this->assertStringContainsString('<p><a href="https://youtu.be/dQw4w9WgXcQ">連結文字</a></p>', $html, '[text](url) stays a link');
        $this->assertStringContainsString('<a href="https://vimeo.com/123">', $html);
        $this->assertStringNotContainsString('<iframe', $html, 'only a marker is stored');
    }

    public function testMarkerFallsBackToALinkWithoutThePlugin(): void
    {
        $html = (new MarkdownRenderer())->render('https://youtu.be/dQw4w9WgXcQ');

        $this->assertStringContainsString('<a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ">https://www.youtube.com/watch?v=dQw4w9WgXcQ</a></div>', $html);
    }

    public function testMarkerSurvivesComponentFilters(): void
    {
        $md       = "# 標題\n\nhttps://youtu.be/dQw4w9WgXcQ?t=5\n";
        $codec    = new ContentCodec();
        $stored   = $codec->encode((new MarkdownRenderer())->render($md), $md);
        // EasyBlog filterHtml / Joomla default blacklist: iframes would be removed, the marker is not.
        $filtered = (new InputFilter(['script', 'iframe', 'object', 'embed'], ['onclick'], 1, 1, 1))->clean($stored, 'html');

        $this->assertStringContainsString('data-youtube="dQw4w9WgXcQ"', $filtered);
        $this->assertStringContainsString('<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&amp;start=5"', YouTube::expand($filtered));
        $this->assertTrue($codec->extract($filtered)->inSync);
    }

    public function testExpandIsStrictAndIdempotent(): void
    {
        $marker = (new MarkdownRenderer())->render('https://youtu.be/dQw4w9WgXcQ');
        $once   = YouTube::expand($marker, 'YouTube 影片');

        $this->assertStringContainsString('title="YouTube 影片"', $once);
        $this->assertStringContainsString('loading="lazy"', $once);
        $this->assertStringContainsString('referrerpolicy="strict-origin-when-cross-origin"', $once);
        $this->assertSame($once, YouTube::expand($once));

        // Forged markers with anything but a valid id / numeric start produce no player.
        foreach ([
            '<div class="gssmd-video" data-youtube="x&quot;onload=alert(1)"><a>x</a></div>',
            '<div class="gssmd-video" data-youtube="javascript:1"></div>',
            '<div class="gssmd-video"><a href="https://youtu.be/dQw4w9WgXcQ">x</a></div>',
        ] as $forged) {
            $this->assertSame($forged, YouTube::expand($forged));
        }

        $this->assertStringContainsString(
            'src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0"',
            YouTube::expand('<div class="gssmd-video" data-youtube="dQw4w9WgXcQ" data-start="1;alert(1)"></div>'),
            'a non-numeric start is ignored'
        );
    }

    public function testPreviewAndSiteShowTheSamePlayer(): void
    {
        $renderer = new MarkdownRenderer();
        $md       = "文字\n\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ&t=42s\n\n更多文字\n";

        $site    = YouTube::expand($renderer->render($md));
        $preview = preg_replace('/ data-line="\d+"/', '', YouTube::expand($renderer->renderPreview($md)));

        $this->assertSame($site, $preview);
        $this->assertStringContainsString('data-line="3"', $renderer->renderPreview($md), 'videos are scroll-sync anchors');
    }

    public function testWhitelistModeCannotSmuggleOtherFrames(): void
    {
        $html = YouTube::expand((new MarkdownRenderer('whitelist'))->render('<iframe src="https://evil.example"></iframe>'));

        // The raw iframe is neutralised (escaped / removed); no frame element exists.
        $this->assertStringNotContainsString('<iframe', $html);
    }

    public function testLegacyYouTubeIframesComeBackAsVideos(): void
    {
        $md = (new LegacyConverter())->convert(
            '<p>介紹</p><p><iframe width="560" height="315" src="https://www.youtube.com/embed/dQw4w9WgXcQ?start=30" frameborder="0" allowfullscreen></iframe></p>'
            . '<iframe src="https://maps.example/embed"></iframe>'
        );

        $this->assertStringContainsString('https://www.youtube.com/watch?v=dQw4w9WgXcQ&t=30s', $md);
        $this->assertStringNotContainsString('maps.example', $md);
        $this->assertStringContainsString('data-youtube="dQw4w9WgXcQ"', (new MarkdownRenderer())->render($md));
    }
}
