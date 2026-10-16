<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Context;

use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * com_content articles. Also lets the plugin be used and tested without EasyBlog.
 */
final class ArticleAdapter implements ContextAdapter
{
    public function __construct(private readonly DatabaseInterface $db, private readonly string $imageBaseDir)
    {
    }

    public function option(): string
    {
        return 'com_content';
    }

    public function saveContexts(): array
    {
        return ['com_content.article', 'com_content.form'];
    }

    public function canCreate(User $user): bool
    {
        return $user->authorise('core.create', 'com_content')
            || \count($user->getAuthorisedCategories('com_content', 'core.create')) > 0;
    }

    public function canEditItem(int $id, User $user): bool
    {
        $asset = 'com_content.article.' . $id;

        if ($user->authorise('core.edit', $asset)) {
            return true;
        }

        if (!$user->authorise('core.edit.own', $asset)) {
            return false;
        }

        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('created_by'))
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult() === (int) $user->id && $user->id > 0;
    }

    public function isUnsaved(int $id): bool
    {
        return false;
    }

    public function itemImageDir(int $id): string
    {
        return trim($this->imageBaseDir, '/') . '/com_content/' . $id;
    }

    public function samplePageUrl(?int $id): ?string
    {
        $now   = Factory::getDate()->toSql();
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('state') . ' = 1')
            ->where($this->db->quoteName('access') . ' = 1')
            ->where('(' . $this->db->quoteName('publish_up') . ' IS NULL OR ' . $this->db->quoteName('publish_up') . ' <= :now)')
            ->bind(':now', $now)
            ->order($this->db->quoteName('id') . ' DESC');

        if ($id) {
            $own = (clone $query)->where($this->db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);

            if ($found = (int) $this->db->setQuery($own, 0, 1)->loadResult()) {
                return 'index.php?option=com_content&view=article&id=' . $found;
            }
        }

        $found = (int) $this->db->setQuery($query, 0, 1)->loadResult();

        return $found ? 'index.php?option=com_content&view=article&id=' . $found : null;
    }

    public function bodySelector(): string
    {
        // Cassiopeia and most templates: layouts of com_content article view.
        return '[itemprop="articleBody"], .com-content-article__body';
    }

    public function idFromSavedItem(object $item): ?int
    {
        return isset($item->id) ? (int) $item->id : null;
    }

    public function rewriteStoredContent(int $id, callable $rewrite): void
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['introtext', 'fulltext']))
            ->from($this->db->quoteName('#__content'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $this->db->setQuery($query)->loadObject();

        if (!$row) {
            return;
        }

        $intro = $rewrite((string) $row->introtext);
        $full  = $rewrite((string) $row->fulltext);

        if ($intro === $row->introtext && $full === $row->fulltext) {
            return;
        }

        $update = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__content'))
            ->set($this->db->quoteName('introtext') . ' = :intro')
            ->set($this->db->quoteName('fulltext') . ' = :full')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':intro', $intro)
            ->bind(':full', $full)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($update)->execute();
    }
}
