<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Plugin\System\GssMarkdown\Extension;

use Gss\Library\GssMarkdown\DraftProcessor;
use Gss\Library\GssMarkdown\Services;
use Gss\Library\GssMarkdown\Video\Player;
use Joomla\CMS\Event\Model\AfterSaveEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Server half of the GSS Markdown editor.
 *
 * - onAfterRoute:        replaces what the editor submitted with server-rendered, filtered HTML
 * - onContentAfterSave:  moves images uploaded before the first save into the item's folder
 * - onAfterRender:       loads the code highlighter on public pages that contain code blocks
 *
 * It must stay enabled while the editor is used; the editor refuses to load without it.
 */
final class GssMarkdown extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    private ?Services $services = null;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute'       => 'onAfterRoute',
            'onContentAfterSave' => 'onContentAfterSave',
            'onAfterRender'      => 'onAfterRender',
        ];
    }

    public function onAfterRoute(): void
    {
        $app = $this->getApplication();

        if (!($app->isClient('site') || $app->isClient('administrator')) || empty($_POST)) {
            return;
        }

        // Authors may save articles of any size; a visitor who is not logged in gets the
        // preview limit, so anonymous requests cannot make the server render huge texts.
        $user  = $app->getIdentity();
        $limit = (!$user || $user->guest)
            ? max(1, (int) $this->services()->settings()->get('preview_max_kb', 1024)) * 1024
            : null;

        $processor = new DraftProcessor(
            $this->services()->codec(),
            fn (string $markdown) => $this->services()->renderForStorage($markdown),
            $limit
        );

        $changed = $processor->process($_POST);

        if (!$changed) {
            return;
        }

        // Joomla's Input objects hold copies of the superglobals, so update each of them.
        $input = $app->getInput();

        foreach ($changed as $key => $value) {
            $_POST[$key] = $value;

            if (\array_key_exists($key, $_REQUEST)) {
                $_REQUEST[$key] = $value;
            }

            $input->set($key, $value);
            $input->post->set($key, $value);
            $input->request->set($key, $value);
        }
    }

    public function onContentAfterSave(AfterSaveEvent $event): void
    {
        $adapter = $this->services()->adapterForSaveContext((string) $event->getContext());

        if ($adapter === null) {
            return;
        }

        $id = $adapter->idFromSavedItem($event->getItem());

        if (!$id) {
            return;
        }

        try {
            $this->services()->finalizeImages($adapter, $id);
        } catch (\Throwable $e) {
            // Never break a save over images; the temp files stay until cleanup and the
            // next save retries the move.
            Log::add('GSS Markdown: moving images of ' . $event->getContext() . ' #' . $id . ' failed: ' . $e->getMessage(), Log::WARNING, 'gssmarkdown');
        }
    }

    /**
     * Site pages only: turns video markers into players and loads the code highlighter /
     * content styles when the page contains content written with the editor.
     */
    public function onAfterRender(): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site') || $app->getDocument()?->getType() !== 'html') {
            return;
        }

        $body     = $app->getBody();
        $hasCode  = str_contains($body, 'class="gssmd-code"') && $this->params->get('frontend_highlight', 1);
        $hasVideo = str_contains($body, 'class="gssmd-video"');

        if ((!$hasCode && !$hasVideo) || !str_contains($body, '</head>')) {
            return;
        }

        if ($hasVideo) {
            $this->loadLanguage();
            $body = Player::expand($body, Text::_('PLG_SYSTEM_GSSMARKDOWN_VIDEO_TITLE'), Uri::root(true));
        }

        $base    = rtrim(Uri::root(true), '/') . '/media/plg_system_gssmarkdown/';
        $version = '?' . $app->getDocument()->getMediaVersion();
        $tags    = '<link rel="stylesheet" href="' . $base . 'css/content.css' . $version . '">';

        if ($hasCode) {
            $tags .= '<link rel="stylesheet" href="' . $base . 'css/vendor/prism.min.css' . $version . '">'
                . '<script src="' . $base . 'js/vendor/prism.bundle.min.js' . $version . '" defer></script>'
                . '<script src="' . $base . 'js/highlight.js' . $version . '" defer></script>';
        }

        $app->setBody(preg_replace('#</head>#i', $tags . "\n</head>", $body, 1));
    }

    private function services(): Services
    {
        return $this->services ??= new Services($this->params, $this->getDatabase());
    }
}
