<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Video;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Turns a paragraph that holds nothing but a video URL into a video marker (see Player):
 *
 *   https://www.youtube.com/watch?v=dQw4w9WgXcQ        (bare URL / <URL> on its own line)
 *   ![影片標題](https://youtu.be/dQw4w9WgXcQ?t=90)      (image syntax: alt = title)
 *   https://vimeo.com/76979871                         (Vimeo, Dailymotion, Bilibili)
 *   ![產品介紹](/images/videos/intro.mp4)               (video files: mp4, webm, ogv, m4v, mov)
 *
 * A video link inside a sentence, or [text](url), stays a normal link.
 */
final class VideoExtension implements ExtensionInterface, NodeRendererInterface
{
    public function register(EnvironmentBuilderInterface $environment): void
    {
        // Before the preview's data-line listener (priority 0), so videos get their line too.
        $environment->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed'], 10);
        $environment->addRenderer(VideoBlock::class, $this);
    }

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $paragraphs = [];

        foreach ($event->getDocument()->iterator() as $node) {
            if ($node instanceof Paragraph) {
                $paragraphs[] = $node;
            }
        }

        foreach ($paragraphs as $paragraph) {
            $video = $this->videoFrom($paragraph);

            if ($video === null) {
                continue;
            }

            $block = new VideoBlock($video['video'], $video['title']);
            $block->setStartLine($paragraph->getStartLine());
            $block->setEndLine($paragraph->getEndLine());
            $paragraph->replaceWith($block);
        }
    }

    /**
     * @return ?array{video: array, title: string}
     */
    private function videoFrom(Paragraph $paragraph): ?array
    {
        $children = array_values(array_filter(
            iterator_to_array($paragraph->children(), false),
            static fn (Node $n) => !($n instanceof Text && trim($n->getLiteral()) === '')
        ));

        if (\count($children) !== 1) {
            return null;
        }

        $node = $children[0];

        if ($node instanceof Image) {
            $video = VideoUrl::parse($node->getUrl());

            return $video ? ['video' => $video, 'title' => trim($this->text($node))] : null;
        }

        if ($node instanceof Link) {
            // Only a bare URL (autolink), not [some text](url): the author chose a link there.
            $text = trim($this->text($node));
            $url  = $node->getUrl();

            if ($text !== $url && 'http://' . $text !== $url && 'https://' . $text !== $url) {
                return null;
            }

            $video = VideoUrl::parse($url);

            return $video ? ['video' => $video, 'title' => ''] : null;
        }

        return null;
    }

    private function text(Node $node): string
    {
        $text = '';

        foreach ($node->iterator() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getLiteral();
            }
        }

        return $text;
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        VideoBlock::assertInstanceOf($node);
        \assert($node instanceof VideoBlock);

        $video = $node->video;
        $url   = $video['watch'];
        $attrs = ['class' => 'gssmd-video'];

        if ($video['provider'] === 'youtube') {
            // YouTube keeps its 2.5 marker, which older lightbox versions understand too.
            $attrs['data-youtube'] = $video['id'];

            if ($video['start'] > 0) {
                $attrs['data-start'] = (string) $video['start'];
            }
        } else {
            // Canonical URL (start time included); Player re-parses it, so only supported
            // videos ever become players.
            $attrs['data-video'] = $video['provider'] === 'file' ? $video['file'] : $video['watch'];
        }

        if ($node->title !== '') {
            $attrs['title'] = $node->title;
        }

        // Fallback content: a plain link (what readers see if the plugin is ever disabled).
        return new HtmlElement(
            'div',
            $attrs + $node->data->get('attributes', []),
            new HtmlElement('a', ['href' => $url], htmlspecialchars($node->title !== '' ? $node->title : $url, ENT_QUOTES, 'UTF-8'))
        );
    }
}
