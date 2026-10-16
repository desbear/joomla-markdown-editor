<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Context;

use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * EasyBlog posts. Permission checks delegate to EasyBlog's own ACL so they match what
 * the composer allows. Read-only use of EasyBlog: no EasyBlog file is modified.
 */
final class EasyBlogAdapter implements ContextAdapter
{
    private const ENTRY = JPATH_ADMINISTRATOR . '/components/com_easyblog/includes/easyblog.php';

    public function __construct(private readonly DatabaseInterface $db, private readonly string $imageBaseDir)
    {
    }

    public static function isInstalled(): bool
    {
        return is_file(self::ENTRY);
    }

    public function option(): string
    {
        return 'com_easyblog';
    }

    public function saveContexts(): array
    {
        return ['easyblog.blog'];
    }

    public function canCreate(User $user): bool
    {
        if (!$this->boot() || $user->guest) {
            return false;
        }

        return (bool) \EB::acl((int) $user->id)->get('add_entry');
    }

    public function canEditItem(int $id, User $user): bool
    {
        if (!$this->boot() || $user->guest) {
            return false;
        }

        // EasyBlogPost::canEdit() checks the *current* user; uploads are always made by it.
        $post = \EB::post($id);

        if ((int) $post->id !== $id) {
            return false;
        }

        // A blank post (composer just opened) belongs to whoever created it.
        return $post->canEdit() || ($post->isBlank() && (int) $post->created_by === (int) $user->id);
    }

    public function isUnsaved(int $id): bool
    {
        return $this->boot() && \EB::post($id)->isBlank();
    }

    public function itemImageDir(int $id): string
    {
        return trim($this->imageBaseDir, '/') . '/' . $id;
    }

    public function samplePageUrl(?int $id): ?string
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__easyblog_post'))
            ->where($this->db->quoteName('published') . ' = 1')
            ->where($this->db->quoteName('state') . ' = 0')
            ->where($this->db->quoteName('blogpassword') . ' = ' . $this->db->quote(''))
            ->order($this->db->quoteName('id') . ' DESC');

        if ($id) {
            // Prefer the post itself, if it is public.
            $own = (clone $query)->where($this->db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);

            if ($found = (int) $this->db->setQuery($own, 0, 1)->loadResult()) {
                return 'index.php?option=com_easyblog&view=entry&id=' . $found;
            }
        }

        $found = (int) $this->db->setQuery($query, 0, 1)->loadResult();

        return $found ? 'index.php?option=com_easyblog&view=entry&id=' . $found : null;
    }

    public function bodySelector(): string
    {
        // components/com_easyblog/themes/wireframe/entry/default/default.php
        return '[data-blog-content], .eb-entry-article';
    }

    public function idFromSavedItem(object $item): ?int
    {
        return isset($item->id) && (int) $item->id > 0 ? (int) $item->id : null;
    }

    public function rewriteStoredContent(int $id, callable $rewrite): void
    {
        $this->rewritePost($id, $rewrite);
        $this->rewriteRevisions($id, $rewrite);
    }

    private function rewritePost(int $id, callable $rewrite): void
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['intro', 'content']))
            ->from($this->db->quoteName('#__easyblog_post'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $row = $this->db->setQuery($query)->loadObject();

        if (!$row) {
            return;
        }

        $intro   = $rewrite((string) $row->intro);
        $content = $rewrite((string) $row->content);

        if ($intro === $row->intro && $content === $row->content) {
            return;
        }

        $update = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__easyblog_post'))
            ->set($this->db->quoteName('intro') . ' = :intro')
            ->set($this->db->quoteName('content') . ' = :content')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':intro', $intro)
            ->bind(':content', $content)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($update)->execute();
    }

    /**
     * EasyBlog re-opens posts from #__easyblog_revisions (JSON of the post data), so the
     * revision must be rewritten too or the editor would load the temp URLs again.
     */
    private function rewriteRevisions(int $id, callable $rewrite): void
    {
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName(['id', 'content']))
            ->from($this->db->quoteName('#__easyblog_revisions'))
            ->where($this->db->quoteName('post_id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        foreach ($this->db->setQuery($query)->loadObjectList() as $revision) {
            $data = json_decode((string) $revision->content);

            if (!\is_object($data)) {
                continue;
            }

            $changed = false;

            foreach (['intro', 'content'] as $field) {
                if (isset($data->$field) && \is_string($data->$field)) {
                    $new = $rewrite($data->$field);

                    if ($new !== $data->$field) {
                        $data->$field = $new;
                        $changed      = true;
                    }
                }
            }

            if (!$changed) {
                continue;
            }

            // Same encoding EasyBlogTableRevision::setContent() uses.
            $json   = json_encode($data);
            $revId  = (int) $revision->id;
            $update = $this->db->getQuery(true)
                ->update($this->db->quoteName('#__easyblog_revisions'))
                ->set($this->db->quoteName('content') . ' = :content')
                ->where($this->db->quoteName('id') . ' = :id')
                ->bind(':content', $json)
                ->bind(':id', $revId, ParameterType::INTEGER);

            $this->db->setQuery($update)->execute();
        }
    }

    private function boot(): bool
    {
        if (class_exists('EB', false)) {
            return true;
        }

        if (!self::isInstalled()) {
            return false;
        }

        require_once self::ENTRY;

        return class_exists('EB', false);
    }
}
