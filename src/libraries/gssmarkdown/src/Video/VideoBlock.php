<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Video;

use League\CommonMark\Node\Block\AbstractBlock;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A paragraph that consisted of a single video link / image, promoted to a video block.
 */
final class VideoBlock extends AbstractBlock
{
    /**
     * @param  array{provider: string, id: string, start: int, embed: string, watch: string, file: ?string}  $video  VideoUrl::parse()
     */
    public function __construct(public readonly array $video, public readonly string $title)
    {
        parent::__construct();
    }
}
