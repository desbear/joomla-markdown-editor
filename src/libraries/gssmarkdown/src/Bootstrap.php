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
 * Loads the bundled Composer dependencies (league/commonmark) on demand, so pages
 * that never render Markdown pay nothing.
 */
final class Bootstrap
{
    private static bool $loaded = false;

    public static function vendor(): void
    {
        if (self::$loaded) {
            return;
        }

        // Packages Joomla core already ships (psr/event-dispatcher, symfony/deprecation-contracts)
        // are "replace"d in composer.json, so this autoloader never shadows core classes.
        require_once \dirname(__DIR__) . '/vendor/autoload.php';

        self::$loaded = true;
    }
}
