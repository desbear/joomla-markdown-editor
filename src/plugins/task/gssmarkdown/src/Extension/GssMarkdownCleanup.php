<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Plugin\Task\GssMarkdown\Extension;

use Gss\Library\GssMarkdown\Services;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Scheduler\Administrator\Event\ExecuteTaskEvent;
use Joomla\Component\Scheduler\Administrator\Task\Status;
use Joomla\Component\Scheduler\Administrator\Traits\TaskPluginTrait;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Scheduled task: deletes temporary images of articles that were never saved.
 * (Uploads also run a throttled cleanup once a day, so this is optional.)
 */
final class GssMarkdownCleanup extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    use TaskPluginTrait;

    private const TASKS_MAP = [
        'gssmarkdown.cleanup' => [
            'langConstPrefix' => 'PLG_TASK_GSSMARKDOWN_CLEANUP',
            'method'          => 'cleanup',
            'form'            => 'cleanupForm',
        ],
    ];

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onTaskOptionsList'    => 'advertiseRoutines',
            'onExecuteTask'        => 'standardRoutineHandler',
            'onContentPrepareForm' => 'enhanceTaskItemForm',
        ];
    }

    private function cleanup(ExecuteTaskEvent $event): int
    {
        $settings = Services::systemSettings();
        $days     = (int) ($event->getArgument('params')->days ?? 0) ?: (int) $settings->get('tmp_days', 7);
        $removed  = (new Services($settings, $this->getDatabase()))->images()->cleanup(max(1, $days));

        $this->logTask(\sprintf('Removed %d expired draft image folder(s).', $removed));

        return Status::OK;
    }
}
