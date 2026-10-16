<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\InstallerAdapter;
use Joomla\CMS\Installer\InstallerScriptInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

return new class () implements InstallerScriptInterface {
    private const MIN_JOOMLA = '5.2.0';
    private const MIN_PHP    = '8.1.0';

    public function install(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function update(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function uninstall(InstallerAdapter $adapter): bool
    {
        return true;
    }

    public function preflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        // The editor provider API (onEditorSetup) exists since Joomla 5.2.
        if (version_compare(JVERSION, self::MIN_JOOMLA, '<') || version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            Factory::getApplication()->enqueueMessage(
                sprintf('GSS Markdown needs Joomla %s+ and PHP %s+.', self::MIN_JOOMLA, self::MIN_PHP),
                'error'
            );

            return false;
        }

        return true;
    }

    public function postflight(string $type, InstallerAdapter $adapter): bool
    {
        if ($type !== 'install') {
            return true;
        }

        // The system plugin must run whenever the editor is used; the task plugin only
        // offers a routine. The editor is enabled but not made anyone's default editor.
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('gssmarkdown'))
            ->whereIn($db->quoteName('folder'), ['system', 'editors', 'task'], ParameterType::STRING);

        $db->setQuery($query)->execute();

        return true;
    }
};
