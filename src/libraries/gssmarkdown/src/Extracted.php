<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Result of ContentCodec::extract().
 */
final class Extracted
{
    /**
     * @param   string  $markdown  The Markdown source.
     * @param   string  $html      The stored HTML without the carrier.
     * @param   bool    $inSync    False when the HTML was changed after the Markdown was
     *                             rendered (someone edited the article in another editor).
     */
    public function __construct(
        public readonly string $markdown,
        public readonly string $html,
        public readonly bool $inSync
    ) {
    }
}
