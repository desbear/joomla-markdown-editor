<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Plugin\Editors\GssMarkdown\Controller;

use Gss\Library\GssMarkdown\Preview\FrameBuilder;
use Gss\Library\GssMarkdown\Services;
use Gss\Library\GssMarkdown\Video\Player;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * POST ...&plugin=gssmarkdownpreview&format=json   markdown, seq (+ CSRF token)
 *      -> {"success":true,"data":{"html":"…","seq":42}}
 *      Rendered by MarkdownRenderer, the same object and settings the save path uses.
 *
 * GET  ...&plugin=gssmarkdownpreview&format=raw&frame=1&context=com_easyblog&item=12[&refresh=1]
 *      -> the preview document: front-end article layout with an empty article body.
 *
 * Both need a logged-in user who may write content (Services::canUseEditor()), so the
 * site does not become a public Markdown service.
 */
final class PreviewController
{
    use RespondsJson;

    private Services $services;

    public function __construct(private readonly CMSApplicationInterface $app, DatabaseInterface $db)
    {
        $this->services = new Services(Services::systemSettings(), $db);
    }

    public function handle(): void
    {
        $input = $this->app->getInput();
        $user  = $this->app->getIdentity();

        $isFrame = (bool) $input->getBool('frame', false);
        $itemId  = $isFrame ? $input->getInt('item', 0) : $input->getInt('post_id', 0);

        if (!$user || $user->guest || !$this->services->canUseEditor($user, $input->getCmd('context', ''), $itemId)) {
            $isFrame ? $this->plain(403) : $this->fail(403, 'NO_AUTH', Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));

            return;
        }

        if ($input->getBool('frame', false) && $input->getMethod() === 'GET') {
            $this->frame();

            return;
        }

        if ($input->getMethod() !== 'POST' || !Session::checkToken()) {
            $this->fail(403, 'INVALID_TOKEN', Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_INVALID_TOKEN'));

            return;
        }

        $markdown = (string) $input->post->get('markdown', '', 'raw');
        $limit    = max(1, (int) $this->services->settings()->get('preview_max_kb', 1024)) * 1024;

        if (\strlen($markdown) > $limit) {
            $this->fail(413, 'TOO_LARGE', Text::sprintf('PLG_EDITORS_GSSMARKDOWN_ERR_PREVIEW_TOO_LARGE', (int) ($limit / 1024)));

            return;
        }

        try {
            // Same player as on the site (plg_system_gssmarkdown::onAfterRender).
            $html = Player::expand($this->services->renderer()->renderPreview($markdown), Text::_('PLG_EDITORS_GSSMARKDOWN_VIDEO_TITLE'), Uri::root(true));
        } catch (\Throwable) {
            $this->fail(500, 'RENDER_FAILED', Text::_('PLG_EDITORS_GSSMARKDOWN_PREVIEW_FAILED'));

            return;
        }

        $this->json(200, ['success' => true, 'data' => ['html' => $html, 'seq' => $input->post->getInt('seq', 0)]]);
    }

    private function frame(): void
    {
        $input   = $this->app->getInput();
        $adapter = $this->services->adapterForOption($input->getCmd('context', ''));
        $item    = $input->getInt('item', 0) ?: null;

        // Joomla's Input returns null (not false) for a missing boolean: always pass a default.
        try {
            $frame = $this->services->frameProvider()->frame($adapter, $item, (bool) $input->getBool('refresh', false));
        } catch (\Throwable $e) {
            // Never leave the author without a preview: fall back to a plain document.
            Log::add('GSS Markdown: preview layout failed: ' . $e->getMessage(), Log::WARNING, 'gssmarkdown');
            $frame = ['html' => (new FrameBuilder())->fallback([], 'gssmd-preview', '760px', Uri::root()), 'source' => 'fallback'];
        }

        $this->app->setHeader('Status', '200', true);
        $this->app->setHeader('Content-Type', 'text/html; charset=utf-8', true);
        $this->app->setHeader('Cache-Control', 'private, no-store', true);
        $this->app->setHeader('X-GssMarkdown-Frame', $frame['source'], true);
        // Only same-origin scripts (the bundled highlighter); the page's own scripts were removed.
        $this->app->setHeader('Content-Security-Policy', "script-src 'self'; object-src 'none'; frame-ancestors 'self'", true);
        $this->app->sendHeaders();
        echo $frame['html'];
        $this->app->close();
    }
}
