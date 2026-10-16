/**
 * GSS Markdown: buttons of the banner shown above the fallback editor (JCE).
 * Switching editor reloads the page with ?gssmd_use=...; the conversion itself happens
 * only after the author confirmed the lossy-conversion warning.
 *
 * @copyright  (C) 2026 Galaxy Software Services Corp.
 * @license    GNU General Public License version 2 or later; see LICENSE.txt
 */
const t = (key) => Joomla.Text._(`PLG_EDITORS_GSSMARKDOWN_${key}`);

document.addEventListener('click', (event) => {
  const button = event.target.closest('[data-gssmd-banner] [data-gssmd-action]');

  if (!button) {
    return;
  }

  const action = button.dataset.gssmdAction;

  if (action === 'convert' && !window.confirm(t('CONVERT_CONFIRM'))) {
    return;
  }

  if (!window.confirm(t('RELOAD_CONFIRM'))) {
    return;
  }

  const url = new URL(window.location.href);

  if (action === 'convert') {
    url.searchParams.set('gssmd_use', 'convert');
  } else {
    url.searchParams.delete('gssmd_use');
  }

  window.location.href = url.toString();
});
