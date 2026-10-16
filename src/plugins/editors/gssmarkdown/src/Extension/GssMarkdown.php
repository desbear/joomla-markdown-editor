<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Plugin\Editors\GssMarkdown\Extension;

use Gss\Plugin\Editors\GssMarkdown\Controller\ConvertController;
use Gss\Plugin\Editors\GssMarkdown\Controller\PrefsController;
use Gss\Plugin\Editors\GssMarkdown\Controller\PreviewController;
use Gss\Plugin\Editors\GssMarkdown\Controller\UploadController;
use Gss\Plugin\Editors\GssMarkdown\Provider\GssMarkdownProvider;
use Joomla\CMS\Event\Editor\EditorSetupEvent;
use Joomla\CMS\Event\Plugin\AjaxEvent;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\DispatcherAwareInterface;
use Joomla\Event\DispatcherAwareTrait;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * GSS Markdown editor plugin.
 *
 * Joomla 5.2+ editor API: the plugin only registers a provider on onEditorSetup
 * (same pattern as core plg_editors_codemirror); the provider renders.
 * Endpoints go through com_ajax, group=editors: plugin=gssmarkdownupload | gssmarkdownpreview | gssmarkdownprefs | gssmarkdownconvert.
 */
final class GssMarkdown extends CMSPlugin implements SubscriberInterface, DispatcherAwareInterface
{
    use DispatcherAwareTrait;
    use DatabaseAwareTrait;

    public static function getSubscribedEvents(): array
    {
        return [
            'onEditorSetup'            => 'onEditorSetup',
            'onAjaxGssmarkdownupload'  => 'onAjaxUpload',
            'onAjaxGssmarkdownpreview' => 'onAjaxPreview',
            'onAjaxGssmarkdownprefs'   => 'onAjaxPrefs',
            'onAjaxGssmarkdownconvert' => 'onAjaxConvert',
        ];
    }

    public function onEditorSetup(EditorSetupEvent $event): void
    {
        $this->loadLanguage();

        $event->getEditorsRegistry()
            ->add(new GssMarkdownProvider($this->params, $this->getApplication(), $this->getDispatcher()));
    }

    /**
     * Handles uploads (POST) and serves not-yet-saved images to their owner (GET).
     * Responds and closes the application itself so the JSON shape is exactly the
     * documented one instead of com_ajax's wrapper.
     */
    public function onAjaxUpload(AjaxEvent $event): void
    {
        $this->loadLanguage();

        (new UploadController($this->getApplication(), $this->getDatabase()))->handle();
    }

    /**
     * Live preview (POST) and the preview document (GET frame=1).
     */
    public function onAjaxPreview(AjaxEvent $event): void
    {
        $this->loadLanguage();

        (new PreviewController($this->getApplication(), $this->getDatabase()))->handle();
    }

    /**
     * Saves the author's personal editor preferences.
     */
    public function onAjaxPrefs(AjaxEvent $event): void
    {
        $this->loadLanguage();

        (new PrefsController($this->getApplication(), $this->getDatabase()))->handle();
    }

    /**
     * HTML -> Markdown for the toolbar's "convert HTML" dialog.
     */
    public function onAjaxConvert(AjaxEvent $event): void
    {
        $this->loadLanguage();

        (new ConvertController($this->getApplication(), $this->getDatabase()))->handle();
    }
}
