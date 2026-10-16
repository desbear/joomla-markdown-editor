<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\LegacyConverter;
use Gss\Library\GssMarkdown\MarkdownRenderer;
use Gss\Library\GssMarkdown\Video\Player;
use Gss\Library\GssMarkdown\Video\VideoUrl;
use Joomla\Filter\InputFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VideoUrlTest extends TestCase
{
    public static function videos(): array
    {
        return [
            // url, provider, id, start, embed
            ['https://vimeo.com/76979871', 'vimeo', '76979871', 0, 'https://player.vimeo.com/video/76979871?dnt=1'],
            ['https://vimeo.com/76979871#t=1m5s', 'vimeo', '76979871', 65, 'https://player.vimeo.com/video/76979871?dnt=1#t=65s'],
            ['https://vimeo.com/channels/staffpicks/76979871', 'vimeo', '76979871', 0, 'https://player.vimeo.com/video/76979871?dnt=1'],
            ['https://vimeo.com/76979871/abcdef1234', 'vimeo', '76979871', 0, 'https://player.vimeo.com/video/76979871?dnt=1&h=abcdef1234'],
            ['https://player.vimeo.com/video/76979871?h=abcdef1234&badge=0', 'vimeo', '76979871', 0, 'https://player.vimeo.com/video/76979871?dnt=1&h=abcdef1234'],
            ['https://www.dailymotion.com/video/x8abcd1', 'dailymotion', 'x8abcd1', 0, 'https://www.dailymotion.com/embed/video/x8abcd1'],
            ['https://www.dailymotion.com/video/x8abcd1_some-title?start=30', 'dailymotion', 'x8abcd1', 30, 'https://www.dailymotion.com/embed/video/x8abcd1?start=30'],
            ['https://dai.ly/x8abcd1', 'dailymotion', 'x8abcd1', 0, 'https://www.dailymotion.com/embed/video/x8abcd1'],
            ['https://www.bilibili.com/video/BV1GJ411x7h7', 'bilibili', 'BV1GJ411x7h7', 0, 'https://player.bilibili.com/player.html?bvid=BV1GJ411x7h7&page=1&high_quality=1&danmaku=0'],
            ['https://www.bilibili.com/video/BV1GJ411x7h7/?p=2&t=12', 'bilibili', 'BV1GJ411x7h7', 12, 'https://player.bilibili.com/player.html?bvid=BV1GJ411x7h7&page=2&high_quality=1&danmaku=0&t=12'],
            ['https://player.bilibili.com/player.html?aid=170001&page=1', 'bilibili', 'av170001', 0, 'https://player.bilibili.com/player.html?aid=170001&page=1&high_quality=1&danmaku=0'],
            ['https://youtu.be/dQw4w9WgXcQ?t=90', 'youtube', 'dQw4w9WgXcQ', 90, 'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0&start=90'],
        ];
    }

    #[DataProvider('videos')]
    public function testParsesVideoSites(string $url, string $provider, string $id, int $start, string $embed): void
    {
        $video = VideoUrl::parse($url);

        $this->assertNotNull($video);
        $this->assertSame([$provider, $id, $start, $embed], [$video['provider'], $video['id'], $video['start'], $video['embed']]);

        // The canonical watch URL parses back to the same video (it is what the marker stores).
        $this->assertSame($video, VideoUrl::parse($video['watch']));
    }

    public static function files(): array
    {
        return [
            ['https://cdn.example.com/media/intro.mp4'],
            ['https://example.com/v/clip.webm?v=3'],
            ['/images/videos/intro.MP4'],
            ['images/videos/產品介紹.mov'],
            ['http://example.com/a.ogv#t=5'],
        ];
    }

    #[DataProvider('files')]
    public function testRecognisesVideoFiles(string $url): void
    {
        $video = VideoUrl::parse($url);

        $this->assertSame('file', $video['provider'] ?? null);
        $this->assertSame($url, $video['file']);
    }

    public static function rejected(): array
    {
        return [
            ['javascript:alert(1)//.mp4'],
            ['data:video/mp4;base64,AAAA.mp4'],
            ['//evil.example/a.mp4'],
            ['/images/a.mp4" onerror="x'],
            ['https://vimeo.com/channels/staffpicks'],
            ['https://vimeo.example.com/76979871'],
            ['https://www.dailymotion.com/playlist/x6hynp'],
            ['https://www.bilibili.com/video/BV1GJ411x7h7x'],
            ['https://www.bilibili.com/bangumi/play/ep1'],
            ['https://example.com/page.html'],
            ['/images/photo.jpg'],
        ];
    }

    #[DataProvider('rejected')]
    public function testRejectsEverythingElse(string $url): void
    {
        $this->assertNull(VideoUrl::parse($url));
    }

    public function testMarkdownTurnsOtherSitesAndFilesIntoMarkers(): void
    {
        $html = (new MarkdownRenderer())->render(
            "https://vimeo.com/76979871#t=30s\n\n"
            . "![產品介紹](/images/videos/intro.mp4)\n\n"
            . "![](<images/videos/demo clip.webm>)\n\n"
            . "看這裡 https://vimeo.com/76979871 的影片\n"
        );

        $this->assertStringContainsString('<div class="gssmd-video" data-video="https://vimeo.com/76979871#t=30s">', $html);
        $this->assertStringContainsString('<div class="gssmd-video" data-video="/images/videos/intro.mp4" title="產品介紹">', $html);
        $this->assertStringNotContainsString('demo clip.webm"', $html, 'a path with a space is not a video file');
        $this->assertStringContainsString('看這裡 <a href="https://vimeo.com/76979871">', $html, 'a link inside a sentence stays a link');
    }

    public function testPlayerExpandsEveryKind(): void
    {
        $renderer = new MarkdownRenderer();
        $html     = Player::expand($renderer->render(
            "https://www.dailymotion.com/video/x8abcd1\n\n![Demo](/images/demo.webm)\n\nhttps://www.bilibili.com/video/BV1GJ411x7h7\n"
        ));

        $this->assertStringContainsString('<iframe src="https://www.dailymotion.com/embed/video/x8abcd1" title="Video"', $html);
        $this->assertStringContainsString('<video src="/images/demo.webm" title="Demo" controls preload="metadata" playsinline></video>', $html);
        $this->assertStringContainsString('src="https://player.bilibili.com/player.html?bvid=BV1GJ411x7h7&amp;page=1&amp;high_quality=1&amp;danmaku=0"', $html);
        $this->assertSame($html, Player::expand($html), 'idempotent');
    }

    public function testMarkersSurviveTheSaveFilter(): void
    {
        $html     = (new MarkdownRenderer())->render("https://vimeo.com/76979871\n");
        $filtered = (new InputFilter(['script', 'iframe', 'object', 'embed', 'video'], ['onclick'], 1, 1, 1))->clean($html, 'html');

        $this->assertStringContainsString('src="https://player.vimeo.com/video/76979871?dnt=1"', Player::expand($filtered));
    }

    public function testForgedMarkersAreNotExpanded(): void
    {
        foreach ([
            '<div class="gssmd-video" data-video="https://evil.example/embed/1"><a>x</a></div>',
            '<div class="gssmd-video" data-video="javascript:alert(1)//.mp4"><a>x</a></div>',
            '<div class="gssmd-video" data-video="//evil.example/a.mp4"><a>x</a></div>',
        ] as $forged) {
            $this->assertSame($forged, Player::expand($forged));
        }
    }

    public function testLegacyEmbedsAndVideoTagsComeBackAsVideos(): void
    {
        $md = (new LegacyConverter())->convert(
            '<p><iframe src="https://player.vimeo.com/video/76979871?h=abcdef1234" allowfullscreen></iframe></p>'
            . '<video controls width="640"><source src="/images/videos/intro.mp4" type="video/mp4"></video>'
            . '<video src="blob:https://x/1"></video>'
        );

        $this->assertStringContainsString("https://vimeo.com/76979871/abcdef1234\n", $md);
        $this->assertStringContainsString('![](</images/videos/intro.mp4>)', $md);

        $html = (new MarkdownRenderer())->render($md);
        $this->assertStringContainsString('data-video="https://vimeo.com/76979871/abcdef1234"', $html);
        $this->assertStringContainsString('data-video="/images/videos/intro.mp4"', $html);
    }
}
