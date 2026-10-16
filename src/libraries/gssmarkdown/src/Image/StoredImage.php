<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Image;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * A successfully stored upload; serialised as the endpoint's "data" payload.
 */
final class StoredImage
{
    public function __construct(
        public readonly string $url,
        public readonly string $filename,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly int $size
    ) {
    }

    public function toArray(): array
    {
        return [
            'url'      => $this->url,
            'filename' => $this->filename,
            'width'    => $this->width,
            'height'   => $this->height,
            'size'     => $this->size,
        ];
    }
}
