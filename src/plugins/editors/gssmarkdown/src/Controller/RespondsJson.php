<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Plugin\Editors\GssMarkdown\Controller;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * The endpoints answer and close the application themselves, so the JSON has exactly the
 * documented shape instead of com_ajax's wrapper.
 *
 * @property-read \Joomla\CMS\Application\CMSApplicationInterface $app
 */
trait RespondsJson
{
    private function json(int $status, array $payload): void
    {
        $this->app->setHeader('Status', (string) $status, true);
        $this->app->setHeader('Content-Type', 'application/json; charset=utf-8', true);
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->sendHeaders();
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->app->close();
    }

    private function fail(int $status, string $code, string $message): void
    {
        $this->json($status, ['success' => false, 'error' => ['code' => $code, 'message' => $message]]);
    }

    private function plain(int $status): void
    {
        $this->app->setHeader('Status', (string) $status, true);
        $this->app->setHeader('Content-Type', 'text/plain; charset=utf-8', true);
        $this->app->sendHeaders();
        $this->app->close();
    }
}
