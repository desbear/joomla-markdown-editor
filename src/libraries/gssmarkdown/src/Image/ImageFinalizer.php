<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Image;

use Gss\Library\GssMarkdown\ContentCodec;
use Gss\Library\GssMarkdown\Context\ContextAdapter;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * After an item is saved: moves the temp images it references into its folder and
 * rewrites every stored copy of its content (HTML and Markdown source). Idempotent.
 */
final class ImageFinalizer
{
    public function __construct(private readonly ImageStore $images, private readonly ContentCodec $codec)
    {
    }

    public function finalize(ContextAdapter $adapter, int $id): void
    {
        $dir = $adapter->itemImageDir($id);

        $adapter->rewriteStoredContent($id, fn (string $content): string => $this->rewrite($content, $dir));
    }

    public function rewrite(string $content, string $targetRelDir): string
    {
        // Refs come from the HTML and from the Markdown source (base64 inside the carrier).
        $extracted = $this->codec->extract($content);
        $haystack  = $content . ($extracted ? "\n" . $extracted->markdown : '');

        if (!str_contains($haystack, 'gssmd_tmp=')) {
            return $content;
        }

        $map = $this->images->relocate($this->images->findTmpRefs($haystack), $targetRelDir);

        return $this->codec->rewrite($content, fn (string $text): string => $this->images->replaceTmpUrls($text, $map));
    }
}
