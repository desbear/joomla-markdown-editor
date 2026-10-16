<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Plugin\Editors\GssMarkdown\Controller;

use Gss\Library\GssMarkdown\Image\ImageStore;
use Gss\Library\GssMarkdown\Image\UploadException;
use Gss\Library\GssMarkdown\Services;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * POST  ...&plugin=gssmarkdownupload&format=json    multipart: file, draft_key | post_id, context
 *       Header X-CSRF-Token (or the form token field) is required.
 * GET   ...&plugin=gssmarkdownupload&format=raw&gssmd_tmp={draft_key}/{file}
 *       Streams an unsaved image to its uploader (the temp folder is not web readable).
 */
final class UploadController
{
    use RespondsJson;

    private Services $services;

    public function __construct(private readonly CMSApplicationInterface $app, DatabaseInterface $db)
    {
        $this->services = new Services(Services::systemSettings(), $db);
    }

    public function handle(): void
    {
        $tmp = (string) $this->app->getInput()->getString('gssmd_tmp', '');

        if ($tmp !== '' && $this->app->getInput()->getMethod() === 'GET') {
            $this->serveTmp($tmp);

            return;
        }

        try {
            $image = $this->upload();
            $this->json(200, ['success' => true, 'data' => $image]);
        } catch (UploadException $e) {
            $this->json($e->httpStatus(), ['success' => false, 'error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]]);
        } catch (\Throwable $e) {
            $this->json(500, ['success' => false, 'error' => [
                'code'    => UploadException::WRITE_FAILED,
                'message' => Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_WRITE_FAILED'),
            ]]);
        }
    }

    private function upload(): array
    {
        $input = $this->app->getInput();
        $user  = $this->app->getIdentity();

        if ($input->getMethod() !== 'POST') {
            throw new UploadException(UploadException::BAD_REQUEST, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_FILE'));
        }

        if (!$user || $user->guest) {
            throw new UploadException(UploadException::NO_AUTH, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));
        }

        // Accepts the X-CSRF-Token header or the {token}=1 form field.
        if (!Session::checkToken()) {
            throw new UploadException(UploadException::INVALID_TOKEN, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_INVALID_TOKEN'));
        }

        $file = $input->files->get('file', null, 'raw');

        if (!\is_array($file) || !isset($file['tmp_name']) || \is_array($file['tmp_name'])) {
            throw new UploadException(UploadException::BAD_REQUEST, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_FILE'));
        }

        if ((int) $file['error'] === UPLOAD_ERR_INI_SIZE || (int) $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new UploadException(
                UploadException::FILE_TOO_LARGE,
                Text::sprintf('PLG_EDITORS_GSSMARKDOWN_ERR_FILE_TOO_LARGE', (int) $this->services->settings()->get('max_size_mb', 5))
            );
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
            throw new UploadException(UploadException::BAD_REQUEST, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_FILE'));
        }

        $images  = $this->services->images();
        $option  = $input->getCmd('context', '');
        $postId  = $input->getInt('post_id', 0);
        $adapter = $this->services->adapterForOption($option);

        if ($adapter !== null && $postId > 0 && !$adapter->canEditItem($postId, $user)) {
            throw new UploadException(UploadException::NO_AUTH, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));
        }

        if ($adapter !== null && $postId > 0 && !$adapter->isUnsaved($postId)) {
            // Saved item: stored straight into its folder.
            $dir      = $adapter->itemImageDir($postId);
            $urlBase  = $images->finalUrlPrefix($dir);
            $draftKey = null;
        } elseif ($adapter !== null) {
            // New (or never saved) item: waits in the protected temp folder until its save.
            if ($postId === 0 && !$adapter->canCreate($user)) {
                throw new UploadException(UploadException::NO_AUTH, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));
            }

            $draftKey = $input->getString('draft_key', '');

            if (!ImageStore::isDraftKey($draftKey)) {
                throw new UploadException(UploadException::BAD_REQUEST, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_BAD_DRAFT'));
            }

            $dir     = $images->tmpRelDir($draftKey);
            $urlBase = $images->tmpUrlPrefix(Uri::base(true), $draftKey);
        } else {
            // Components without a save event (modules, categories, ...): stored directly.
            if ($option === '' || !($user->authorise('core.create', $option) || $user->authorise('core.edit', $option))) {
                throw new UploadException(UploadException::NO_AUTH, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));
            }

            $dir      = $this->services->genericImageDir($option);
            $urlBase  = $images->finalUrlPrefix($dir);
            $draftKey = null;
        }

        $stored = $images->store(
            $file['tmp_name'],
            (string) $file['name'],
            (int) $file['size'],
            $dir,
            $urlBase,
            $draftKey,
            (int) $user->id
        );

        $images->maybeCleanup(max(1, (int) $this->services->settings()->get('tmp_days', 7)));

        return $stored->toArray();
    }

    private function serveTmp(string $ref): void
    {
        $user = $this->app->getIdentity();

        [$key, $file] = array_pad(explode('/', $ref, 2), 2, '');

        try {
            if (!$user || $user->guest) {
                throw new UploadException(UploadException::NO_AUTH, Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));
            }

            $found = $this->services->images()->resolveTmp($key, $file, (int) $user->id, $user->authorise('core.admin'));
        } catch (UploadException $e) {
            $this->plain($e->httpStatus());

            return;
        }

        if ($found['movedTo'] !== null) {
            $this->app->setHeader('Status', '302', true);
            $this->app->setHeader('Location', $found['movedTo'], true);
            $this->app->sendHeaders();
            $this->app->close();

            return;
        }

        if ($found['path'] === null) {
            $this->plain(404);

            return;
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($found['path']);

        $this->app->setHeader('Status', '200', true);
        $this->app->setHeader('Content-Type', $mime === 'text/xml' || $mime === 'application/xml' ? 'image/svg+xml' : $mime, true);
        $this->app->setHeader('Content-Length', (string) filesize($found['path']), true);
        $this->app->setHeader('Cache-Control', 'private, max-age=3600', true);
        $this->app->setHeader('X-Content-Type-Options', 'nosniff', true);
        $this->app->setHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox", true);
        $this->app->sendHeaders();
        readfile($found['path']);
        $this->app->close();
    }
}
