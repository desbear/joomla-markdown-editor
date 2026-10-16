<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown;

use Gss\Library\GssMarkdown\Renderer\CodeBlockRenderer;
use Gss\Library\GssMarkdown\Video\VideoExtension;
use Joomla\Filter\InputFilter;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Node\Block\AbstractBlock;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The ONLY Markdown -> HTML entry point. The editor's live preview
 * and the stored article both come from here, with the same parser, the same options
 * and the same sanitiser, so the preview cannot disagree with the published page on
 * GFM details (tables, task lists, breaks, autolinks, raw HTML, list nesting).
 *
 * The only difference in preview mode: top-level blocks carry data-line="{n}" (their
 * first source line) so the preview can follow the editor's scroll position. It is an
 * attribute, not structure, and it is never stored.
 */
final class MarkdownRenderer
{
    /** Tags CommonMark + GFM can produce. */
    private const BASE_TAGS = [
        'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'del', 'code', 'pre',
        'blockquote', 'ul', 'ol', 'li', 'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'input',
        // Video marker (Video\VideoExtension). Inline HTML is escaped before this filter in
        // the default mode, so this admits only what the renderer itself produced.
        'div',
    ];

    /** Extra tags accepted as inline HTML when raw_html = whitelist. */
    public const DEFAULT_RAW_TAGS = ['kbd', 'sub', 'sup', 'mark', 'abbr', 'details', 'summary', 'span', 'div', 'dl', 'dt', 'dd', 'figure', 'figcaption'];

    /** Refused even if an administrator lists them. */
    private const NEVER_TAGS = [
        'script', 'style', 'iframe', 'frame', 'object', 'embed', 'applet', 'link', 'meta', 'base',
        'form', 'textarea', 'select', 'button', 'option', 'svg', 'math', 'template',
    ];

    private const ATTRIBUTES = [
        'href', 'title', 'src', 'alt', 'class', 'start', 'align', 'type', 'checked', 'disabled',
        'colspan', 'rowspan', 'width', 'height', 'open', 'loading', 'data-youtube', 'data-start', 'data-video',
    ];

    private MarkdownConverter $converter;

    private MarkdownConverter $previewConverter;

    private InputFilter $filter;

    private InputFilter $previewFilter;

    /**
     * @param   string    $rawHtml   'escape' (default: inline HTML is shown as text) or 'whitelist'.
     * @param   string[]  $rawTags   Tags allowed through when $rawHtml is 'whitelist'.
     */
    public function __construct(string $rawHtml = 'escape', array $rawTags = self::DEFAULT_RAW_TAGS)
    {
        Bootstrap::vendor();

        $allowRaw = $rawHtml === 'whitelist';

        $this->converter        = new MarkdownConverter($this->environment($allowRaw, false));
        $this->previewConverter = new MarkdownConverter($this->environment($allowRaw, true));

        $tags = self::BASE_TAGS;

        if ($allowRaw) {
            $tags = array_merge($tags, array_diff(array_map('strtolower', $rawTags), self::NEVER_TAGS));
        }

        $tags = array_values(array_unique($tags));

        $this->filter        = $this->filter($tags, self::ATTRIBUTES);
        $this->previewFilter = $this->filter($tags, [...self::ATTRIBUTES, 'data-line']);
    }

    /**
     * HTML to store (used by the save path).
     */
    public function render(string $markdown): string
    {
        // Normalise line endings; Windows clients paste \r\n.
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        $html = $this->converter->convert($markdown)->getContent();

        return trim($this->filter->clean($html, 'html'));
    }

    /**
     * HTML for the editor preview: identical to render() plus data-line attributes.
     */
    public function renderPreview(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        $html = $this->previewConverter->convert($markdown)->getContent();

        return trim($this->previewFilter->clean($html, 'html'));
    }

    private function environment(bool $allowRaw, bool $sourceLines): Environment
    {
        $environment = new Environment([
            'html_input'         => $allowRaw ? 'allow' : 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level'  => 50,
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addRenderer(FencedCode::class, new CodeBlockRenderer(), 10);
        $environment->addRenderer(IndentedCode::class, new CodeBlockRenderer(), 10);
        $environment->addExtension(new VideoExtension());

        if ($sourceLines) {
            $environment->addEventListener(DocumentParsedEvent::class, static function (DocumentParsedEvent $event): void {
                foreach ($event->getDocument()->children() as $block) {
                    if ($block instanceof AbstractBlock && $block->getStartLine() !== null) {
                        $block->data->set('attributes/data-line', (string) $block->getStartLine());
                    }
                }
            });
        }

        return $environment;
    }

    private function filter(array $tags, array $attributes): InputFilter
    {
        return new InputFilter(
            $tags,
            $attributes,
            InputFilter::ONLY_ALLOW_DEFINED_TAGS,
            InputFilter::ONLY_ALLOW_DEFINED_ATTRIBUTES,
            1
        );
    }
}
