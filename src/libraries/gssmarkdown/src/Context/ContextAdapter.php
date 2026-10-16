<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Context;

use Joomla\CMS\User\User;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Component-specific knowledge: permissions, image folder and where content is stored.
 *
 * The editor plugin itself never needs the item id (Joomla does not give editors one);
 * only the upload endpoint (id sent by the browser, verified here) and the after-save
 * hook (id from the save event) use these adapters.
 */
interface ContextAdapter
{
    /** Component option, e.g. com_content. */
    public function option(): string;

    /** Contexts of onContentAfterSave this adapter handles. */
    public function saveContexts(): array;

    public function canCreate(User $user): bool;

    public function canEditItem(int $id, User $user): bool;

    /**
     * True when the item exists but was never saved by its author (EasyBlog creates a
     * blank post when the composer opens). Its images go to the temp folder like a new item.
     */
    public function isUnsaved(int $id): bool;

    /** Final image folder of an item, relative to the site root. */
    public function itemImageDir(int $id): string;

    /**
     * Site-relative URL of a published, public article page to borrow the front-end
     * layout from for the preview: the item itself when it is published, otherwise the
     * most recent public one. Null when there is none.
     */
    public function samplePageUrl(?int $id): ?string;

    /** Selector of the element that holds the article body on that page. */
    public function bodySelector(): string;

    /** Item id from the subject of onContentAfterSave. */
    public function idFromSavedItem(object $item): ?int;

    /**
     * Applies $rewrite to every stored copy of the item's content (including revisions),
     * writing back only what changed.
     *
     * @param   callable  $rewrite  fn(string $content): string
     */
    public function rewriteStoredContent(int $id, callable $rewrite): void;
}
