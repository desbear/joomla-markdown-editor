<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Plugin\Editors\GssMarkdown\Controller;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * POST ...&plugin=gssmarkdownprefs&format=json   prefs = JSON (+ CSRF token)
 *
 * Saves the author's personal editor preferences in their user parameters.
 * Only settings that affect the LEFT pane (and the layout) exist here; nothing personal can
 * change how the preview renders.
 */
final class PrefsController
{
    use RespondsJson;

    public const KEY = 'gssmarkdown';

    public const DEFAULTS = [
        'theme'       => 'auto',   // auto | light | dark
        'font'        => 'mono',   // mono | sans
        'fontSize'    => 14,
        'lineNumbers' => false,
        'ratio'       => 0,        // editor share of the split view; 0 = automatic
        'view'        => 'split',  // split | edit | preview
        'scrollSync'  => true,     // editor and preview scroll together
        'zoom'        => 'fit',    // fit = column follows the pane (reflow) | actual = site's real column width
    ];

    public function __construct(private readonly CMSApplicationInterface $app, private readonly DatabaseInterface $db)
    {
    }

    /**
     * Validated preferences; unknown keys and out-of-range values fall back to defaults.
     */
    public static function sanitize(array $input): array
    {
        $p = self::DEFAULTS;

        if (\in_array($input['theme'] ?? null, ['auto', 'light', 'dark'], true)) {
            $p['theme'] = $input['theme'];
        }

        if (\in_array($input['font'] ?? null, ['mono', 'sans'], true)) {
            $p['font'] = $input['font'];
        }

        if (isset($input['fontSize']) && is_numeric($input['fontSize'])) {
            $p['fontSize'] = max(11, min(24, (int) $input['fontSize']));
        }

        if (isset($input['lineNumbers'])) {
            $p['lineNumbers'] = (bool) $input['lineNumbers'];
        }

        if (isset($input['ratio']) && is_numeric($input['ratio']) && (float) $input['ratio'] > 0) {
            $p['ratio'] = round(max(0.2, min(0.8, (float) $input['ratio'])), 3);
        }

        if (\in_array($input['view'] ?? null, ['split', 'edit', 'preview'], true)) {
            $p['view'] = $input['view'];
        }

        if (isset($input['scrollSync'])) {
            $p['scrollSync'] = (bool) $input['scrollSync'];
        }

        if (\in_array($input['zoom'] ?? null, ['actual', 'fit'], true)) {
            $p['zoom'] = $input['zoom'];
        }

        return $p;
    }

    public static function forUser(?object $user): array
    {
        if (!$user || !empty($user->guest) || !method_exists($user, 'getParam')) {
            return self::DEFAULTS;
        }

        $stored = $user->getParam(self::KEY, null);

        return self::sanitize(\is_object($stored) ? (array) $stored : (\is_array($stored) ? $stored : []));
    }

    public function handle(): void
    {
        $user = $this->app->getIdentity();

        if (!$user || $user->guest) {
            $this->fail(403, 'NO_AUTH', Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_NO_AUTH'));

            return;
        }

        if ($this->app->getInput()->getMethod() !== 'POST' || !Session::checkToken()) {
            $this->fail(403, 'INVALID_TOKEN', Text::_('PLG_EDITORS_GSSMARKDOWN_ERR_INVALID_TOKEN'));

            return;
        }

        $prefs = self::sanitize((array) json_decode((string) $this->app->getInput()->post->get('prefs', '{}', 'raw'), true));
        $id    = (int) $user->id;

        // Written directly: User::save() would run every user plugin for a UI preference.
        $query = $this->db->getQuery(true)
            ->select($this->db->quoteName('params'))
            ->from($this->db->quoteName('#__users'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $id, ParameterType::INTEGER);

        $params = new Registry((string) $this->db->setQuery($query)->loadResult());
        $params->set(self::KEY, $prefs);
        $json = $params->toString();

        $update = $this->db->getQuery(true)
            ->update($this->db->quoteName('#__users'))
            ->set($this->db->quoteName('params') . ' = :params')
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':params', $json)
            ->bind(':id', $id, ParameterType::INTEGER);

        $this->db->setQuery($update)->execute();

        // Keep the session copy in step so the next page load sees it.
        $user->setParam(self::KEY, $prefs);

        $this->json(200, ['success' => true, 'data' => $prefs]);
    }
}
