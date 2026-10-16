<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Plugin\Editors\GssMarkdown\Controller;

use Gss\Library\GssMarkdown\LegacyConverter;
use Gss\Library\GssMarkdown\Services;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * POST ...&plugin=gssmarkdownconvert&format=json   html, seq (+ CSRF token)
 *      -> {"success":true,"data":{"markdown":"…","dropped":["script"],"seq":3}}
 *
 * The toolbar's "convert HTML" dialog. Same converter as legacy articles, so pasted HTML
 * comes out exactly like a converted old article (videos kept, scripts and forms removed).
 * Needs a logged-in user who may write content, and the form token, like the preview.
 */
final class ConvertController
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

        if (!$user || $user->guest || !$this->services->canUseEditor($user, $input->getCmd('context', ''), $input->getInt('post_id', 0))) {
            $this->fail(403, 'NO_AUTH', Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));

            return;
        }

        if ($input->getMethod() !== 'POST' || !Session::checkToken()) {
            $this->fail(403, 'INVALID_TOKEN', Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_INVALID_TOKEN'));

            return;
        }

        $html  = (string) $input->post->get('html', '', 'raw');
        $limit = max(1, (int) $this->services->settings()->get('preview_max_kb', 1024)) * 1024;

        if (\strlen($html) > $limit) {
            $this->fail(413, 'TOO_LARGE', Text::sprintf('PLG_EDITORS_GSSMARKDOWN_ERR_PREVIEW_TOO_LARGE', (int) ($limit / 1024)));

            return;
        }

        try {
            $markdown = (new LegacyConverter())->convert($html);
        } catch (\Throwable) {
            $this->fail(500, 'CONVERT_FAILED', Text::_('PLG_EDITORS_GSSMARKDOWN_HTML_FAILED'));

            return;
        }

        $this->json(200, ['success' => true, 'data' => [
            'markdown' => $markdown,
            'dropped'  => LegacyConverter::droppedTags($html),
            'seq'      => $input->post->getInt('seq', 0),
        ]]);
    }
}
