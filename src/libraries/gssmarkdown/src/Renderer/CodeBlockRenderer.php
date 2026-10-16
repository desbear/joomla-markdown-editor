<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Renderer;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;
use League\CommonMark\Util\Xml;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Renders code blocks as <pre class="gssmd-code"><code class="language-xxx">.
 * The gssmd-code class scopes the front-end Prism highlighter to our blocks only.
 */
final class CodeBlockRenderer implements NodeRendererInterface
{
    /** Common spellings Prism does not know as aliases. */
    private const ALIASES = [
        'c#'      => 'csharp',
        'f#'      => 'fsharp',
        'vb'      => 'vbnet',
        'console' => 'shell-session',
        'ps1'     => 'powershell',
        'ps'      => 'powershell',
        'cmd'     => 'batch',
        'bat'     => 'batch',
        'conf'    => 'nginx',
        'vue'     => 'markup',
    ];

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): HtmlElement
    {
        $codeAttrs = [];

        if ($node instanceof FencedCode) {
            $language = $this->language($node->getInfoWords()[0] ?? '');

            if ($language !== '') {
                $codeAttrs['class'] = 'language-' . $language;
            }
        }

        return new HtmlElement(
            'pre',
            ['class' => 'gssmd-code'] + $node->data->get('attributes', []),
            new HtmlElement('code', $codeAttrs, Xml::escape($node->getLiteral()))
        );
    }

    private function language(string $info): string
    {
        $info = strtolower(trim($info));
        $info = self::ALIASES[$info] ?? $info;

        return preg_replace('/[^a-z0-9_+-]/', '', $info) ?? '';
    }
}
