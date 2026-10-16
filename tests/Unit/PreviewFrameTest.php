<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\Context\ContextAdapter;
use Gss\Library\GssMarkdown\Preview\FrameBuilder;
use Gss\Library\GssMarkdown\Preview\FrameProvider;
use Joomla\CMS\User\User;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The preview renders inside the site's own article layout.
 */
final class PreviewFrameTest extends TestCase
{
    private const PAGE = 'https://www.gss.com.tw/index.php?option=com_easyblog&view=entry&id=12';

    private static function page(): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/easyblog-entry.html');
    }

    private function frame(string $selector = '[data-blog-content]'): string
    {
        $html = (new FrameBuilder())->build(self::page(), self::PAGE, $selector, ['<link rel="stylesheet" href="/media/plg_system_gssmarkdown/css/content.css">']);
        $this->assertNotNull($html);

        return $html;
    }

    public function testKeepsTheSiteStylesheetsInOrder(): void
    {
        $frame = $this->frame();

        $basic   = strpos($frame, 'href="/templates/bevel/css/basic.css?1a2b3c"');
        $eb      = strpos($frame, 'href="/components/com_easyblog/themes/wireframe/styles/style-6.0.16.min.css" media="all"');
        $inline  = strpos($frame, '<style>.eb-entry-article{font-size:17px}</style>');
        $plugin  = strpos($frame, 'plg_system_gssmarkdown/css/content.css');

        $this->assertNotFalse($basic);
        $this->assertTrue($basic < $eb && $eb < $inline && $inline < $plugin, 'site CSS first, plugin CSS last');
        $this->assertStringContainsString('<base href="' . htmlspecialchars(self::PAGE) . '">', $frame);
        $this->assertStringNotContainsString('preload', $frame);
        $this->assertStringNotContainsString('favicon', $frame);
    }

    public function testKeepsTheContainerChainAndItsClasses(): void
    {
        $frame = $this->frame();

        $this->assertStringContainsString('<html lang="zh-tw" dir="ltr" class="no-js">', $frame);
        $this->assertStringContainsString('<body class="site blog-page">', $frame);
        $this->assertMatchesRegularExpression(
            '#<div class="inner inner--colRight" data-gssmd-chain><div class="main-content" data-gssmd-chain><div id="eb" class="eb-component" data-gssmd-chain><div class="eb-entry" data-blog-post="" data-gssmd-chain>'
            . '<h1 class="eb-entry-title" data-gssmd-shell aria-hidden="true"></h1>'
            . '<div class="eb-entry-body type-standard clearfix" data-gssmd-chain><div class="eb-entry-article clearfix" data-blog-content="" data-gssmd-slot></div>#',
            $frame
        );
    }

    public function testSidebarKeepsItsBoxSoTheColumnKeepsItsWidth(): void
    {
        $frame = $this->frame();

        $this->assertStringContainsString('<aside class="bevel-aside--right" style="width:300px" data-gssmd-shell aria-hidden="true"></aside>', $frame);
        $this->assertStringContainsString('<header class="bevel-header" data-gssmd-shell aria-hidden="true"></header>', $frame);
        $this->assertStringNotContainsString('側欄模組', $frame);
        $this->assertStringNotContainsString('首頁', $frame);
    }

    public function testNothingExecutesOrLoads(): void
    {
        $frame = $this->frame();

        $this->assertStringNotContainsString('<script', $frame);
        $this->assertStringNotContainsString('onload', $frame);
        $this->assertStringNotContainsString('<img', $frame);
        $this->assertStringNotContainsString('googletagmanager', $frame);
        $this->assertStringNotContainsString('舊的文章內文', $frame, 'the old article body is emptied');
    }

    public function testSelectorAlternativesAndMisses(): void
    {
        $builder = new FrameBuilder();

        $this->assertStringContainsString('data-gssmd-slot', (string) $builder->build(self::page(), self::PAGE, '.missing, div.eb-entry-article'));
        $this->assertStringContainsString('<div id="eb" class="eb-component" data-gssmd-slot>', (string) $builder->build(self::page(), self::PAGE, '#eb'));
        $this->assertNull($builder->build(self::page(), self::PAGE, '[itemprop="articleBody"]'));
        $this->assertNull($builder->build(self::page(), self::PAGE, 'div > p'), 'unsupported selector matches nothing');
    }

    public function testXPathTranslation(): void
    {
        $this->assertSame(
            ['//*[@data-blog-content]', '//div[contains(concat(" ", normalize-space(@class), " "), " a ") and @itemprop="articleBody"]'],
            (new FrameBuilder())->toXPath('[data-blog-content], div.a[itemprop="articleBody"]')
        );
    }

    private function provider(array $settings, ?callable $fetch, string $cache): FrameProvider
    {
        return new FrameProvider(
            new Registry($settings),
            new FrameBuilder(),
            $fetch ?? static fn () => null,
            'https://www.gss.com.tw/',
            $cache,
            []
        );
    }

    private function adapter(?string $sample): ContextAdapter
    {
        return new class ($sample) implements ContextAdapter {
            public array $asked = [];

            public function __construct(private ?string $sample)
            {
            }

            public function samplePageUrl(?int $id): ?string
            {
                $this->asked[] = $id;

                return $this->sample;
            }

            public function bodySelector(): string
            {
                return '[data-blog-content]';
            }

            public function option(): string
            {
                return 'com_easyblog';
            }

            public function saveContexts(): array
            {
                return [];
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
                return '';
            }

            public function idFromSavedItem(object $item): ?int
            {
                return null;
            }

            public function rewriteStoredContent(int $id, callable $rewrite): void
            {
            }
        };
    }

    public function testFetchesOnceThenServesFromCache(): void
    {
        $cache   = sys_get_temp_dir() . '/gssmd-frame-' . bin2hex(random_bytes(3));
        $fetched = [];
        $fetch   = function (string $url) use (&$fetched) {
            $fetched[] = $url;

            return self::page();
        };

        $provider = $this->provider(['preview_cache_hours' => 6], $fetch, $cache);
        $adapter  = $this->adapter('index.php?option=com_easyblog&view=entry&id=12');

        $first  = $provider->frame($adapter, 12);
        $second = $provider->frame($adapter, 12);
        $third  = $provider->frame($adapter, 12, true);

        $this->assertSame('page', $first['source']);
        $this->assertSame($first['html'], $second['html']);
        $this->assertSame([self::PAGE, self::PAGE], $fetched, 'second call cached, refresh fetches again');
        $this->assertSame([12, 12, 12], $adapter->asked);
        $this->assertSame('page', $third['source']);

        exec('rm -rf ' . escapeshellarg($cache));
    }

    public function testFallsBackToConfiguredStylesheets(): void
    {
        $provider = $this->provider([
            'preview_stylesheets'   => "templates/bevel/css/basic.css\nhttps://evil.example/x.css\n/templates/bevel/css/blog.css",
            'preview_max_width'     => '760px',
            'preview_wrapper_class' => 'blog-article',
        ], null, sys_get_temp_dir() . '/gssmd-none');

        $frame = $provider->frame($this->adapter(null), null);

        $this->assertSame('fallback', $frame['source']);
        $this->assertStringContainsString('href="https://www.gss.com.tw/templates/bevel/css/basic.css"', $frame['html']);
        $this->assertStringContainsString('href="https://www.gss.com.tw/templates/bevel/css/blog.css"', $frame['html']);
        $this->assertStringNotContainsString('evil.example', $frame['html'], 'only the site host is fetched or linked');
        $this->assertStringContainsString('<div class="blog-article" style="max-width:760px;margin:0 auto" data-gssmd-slot>', $frame['html']);
    }

    public function testNeverFetchesForeignHosts(): void
    {
        $fetched  = [];
        $provider = $this->provider(['preview_page' => 'https://attacker.example/page'], function ($url) use (&$fetched) {
            $fetched[] = $url;

            return self::page();
        }, sys_get_temp_dir() . '/gssmd-none');

        $this->assertSame('fallback', $provider->frame($this->adapter(null), null)['source']);
        $this->assertSame([], $fetched);
    }

    public function testPageThatLostItsArticleBodyFallsBack(): void
    {
        $provider = $this->provider([], static fn () => '<html><body><p>maintenance</p></body></html>', sys_get_temp_dir() . '/gssmd-none2');

        $this->assertSame('fallback', $provider->frame($this->adapter('index.php?x=1'), null)['source']);
    }
}
