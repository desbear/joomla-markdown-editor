<?php

namespace Gss\Tests\Unit;

use Gss\Library\GssMarkdown\ContentCodec;
use Gss\Library\GssMarkdown\DraftProcessor;
use Gss\Library\GssMarkdown\MarkdownRenderer;
use Joomla\Filter\InputFilter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The editor preview and the published article must come from the
 * same parser with the same settings, so their HTML structure is identical.
 */
final class ParserConsistencyTest extends TestCase
{
    private static function document(): string
    {
        return (string) file_get_contents(\dirname(__DIR__) . '/Fixtures/gfm-full.md');
    }

    public static function modes(): array
    {
        return [['escape'], ['whitelist']];
    }

    #[DataProvider('modes')]
    public function testPreviewEqualsStoredHtml(string $rawHtml): void
    {
        $renderer = new MarkdownRenderer($rawHtml);
        $markdown = self::document();

        $preview = $renderer->renderPreview($markdown);
        $stored  = $renderer->render($markdown);

        $this->assertStringContainsString('data-line="1"', $preview, 'preview carries scroll anchors');
        $this->assertSame($stored, preg_replace('/ data-line="\d+"/', '', $preview));
    }

    /**
     * Through the real save path: editor draft -> onAfterRoute rendering -> the component's
     * InputFilter (EasyBlog filterHtml) -> what the site outputs.
     */
    public function testPreviewEqualsWhatTheSiteShows(): void
    {
        $renderer = new MarkdownRenderer();
        $codec    = new ContentCodec();
        $markdown = self::document();

        $stored = (new DraftProcessor($codec, fn (string $md) => $codec->encode($renderer->render($md), $md)))
            ->process(['content' => $codec->encodeDraft($markdown)])['content'];

        $published = (new InputFilter(['script', 'iframe', 'object', 'embed'], ['onclick', 'onerror'], 1, 1, 1))->clean($stored, 'html');
        $site      = $codec->strip($published);
        $preview   = preg_replace('/ data-line="\d+"/', '', $renderer->renderPreview($markdown));

        $this->assertSame($this->structure($preview), $this->structure($site));
    }

    public function testAnchorsAreTopLevelBlocksOnly(): void
    {
        $html = (new MarkdownRenderer())->renderPreview("# a\n\n- b\n  - c\n\n> d\n\n```\ne\n```\n\n| x |\n|---|\n| y |\n");

        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        $body = $doc->getElementsByTagName('body')->item(0);

        $lines = [];
        foreach ($body->childNodes as $node) {
            if ($node instanceof \DOMElement) {
                $lines[$node->nodeName] = $node->getAttribute('data-line');
            }
        }

        $this->assertSame(['h1' => '1', 'ul' => '3', 'blockquote' => '6', 'pre' => '8', 'table' => '12'], $lines);
        $this->assertSame(5, substr_count($html, 'data-line='), 'nested blocks carry no anchors');
    }

    /**
     * Tag sequence with attributes, ignoring whitespace between tags.
     */
    private function structure(string $html): string
    {
        $doc = new \DOMDocument();
        @$doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');

        $out  = [];
        $walk = function (\DOMNode $node) use (&$walk, &$out): void {
            foreach ($node->childNodes as $child) {
                if ($child instanceof \DOMElement) {
                    $attrs = [];
                    foreach ($child->attributes as $a) {
                        $attrs[] = $a->name . '=' . $a->value;
                    }
                    sort($attrs);
                    $out[] = '<' . $child->nodeName . ' ' . implode(' ', $attrs) . '>';
                    $walk($child);
                    $out[] = '</' . $child->nodeName . '>';
                } elseif ($child instanceof \DOMText && trim($child->data) !== '') {
                    $out[] = trim($child->data);
                }
            }
        };
        $walk($doc->getElementsByTagName('body')->item(0));

        return implode("\n", $out);
    }
}
