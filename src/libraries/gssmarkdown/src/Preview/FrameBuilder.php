<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown\Preview;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Turns a real front-end article page into the document the editor preview runs in
 * (the preview uses the same styles as the published article).
 *
 * Kept:    <html>/<body> attributes, every stylesheet <link> and <style> of the page, and
 *          the chain of containers from <body> down to the article body element, with
 *          their classes / ids / inline styles.
 * Emptied: every other element on that chain's levels (header, sidebars, footer, cover,
 *          modules) becomes an empty, invisible, zero-height shell. Shells keep their
 *          classes so grid / flex columns keep their width, which keeps the article column
 *          exactly as wide as on the site and therefore wraps lines at the same places.
 * Dropped: scripts, images and media of those other elements, event handlers.
 *
 * The article body element is emptied and marked data-gssmd-slot; the editor writes the
 * rendered Markdown into it.
 */
final class FrameBuilder
{
    /** Never output, anywhere. */
    private const DROP = ['script', 'noscript', 'template', 'iframe', 'object', 'embed', 'img', 'picture', 'video', 'audio',
        'canvas', 'svg', 'source', 'input', 'select', 'textarea', 'button', 'link', 'meta', 'style', 'title', 'base'];

    private const VOID = ['area', 'br', 'col', 'hr', 'wbr'];

    /** Attributes that would load or execute something. */
    private const UNSAFE_ATTR = '/^(on|src$|srcset$|href$|action$|formaction$|data-src|poster$|background$)/i';

    private const SHELL_CSS = '[data-gssmd-shell]{visibility:hidden!important;height:0!important;min-height:0!important;'
        . 'max-height:0!important;padding-top:0!important;padding-bottom:0!important;margin-top:0!important;'
        . 'margin-bottom:0!important;border-top:0!important;border-bottom:0!important;overflow:hidden!important;'
        . 'position:static!important}'
        . 'html,body{height:auto!important;min-height:0!important}';

    /**
     * @param   string    $pageHtml   HTML of a published article page.
     * @param   string    $pageUrl    Absolute URL of that page (relative URLs resolve against it).
     * @param   string    $selector   Article body, e.g. "[data-blog-content]" (see toXPath()).
     * @param   string[]  $extraHead  Extra trusted head markup (the plugin's own CSS / scripts).
     *
     * @return  ?string  Null when the selector matches nothing.
     */
    public function build(string $pageHtml, string $pageUrl, string $selector, array $extraHead = []): ?string
    {
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $pageHtml, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath  = new \DOMXPath($doc);
        $target = null;

        foreach ($this->toXPath($selector) as $expression) {
            $found = $xpath->query($expression);

            if ($found && $found->length) {
                $target = $found->item(0);
                break;
            }
        }

        $body = $doc->getElementsByTagName('body')->item(0);

        if (!$target instanceof \DOMElement || !$body instanceof \DOMElement) {
            return null;
        }

        $head = [];

        foreach ($xpath->query('//link[@href] | //style') as $node) {
            /** @var \DOMElement $node */
            if ($node->nodeName === 'style') {
                $head[] = '<style' . $this->attrs($node, ['media']) . '>' . str_ireplace('</style', '<\/style', $node->textContent) . '</style>';
            } elseif (preg_match('/(^|\s)stylesheet(\s|$)/i', $node->getAttribute('rel'))) {
                $head[] = '<link rel="stylesheet" href="' . $this->e($node->getAttribute('href')) . '"' . $this->attrs($node, ['media', 'crossorigin']) . '>';
            }
        }

        $html = $doc->getElementsByTagName('html')->item(0);

        return '<!DOCTYPE html>' . "\n"
            . '<html' . ($html instanceof \DOMElement ? $this->attrs($html) : '') . '>'
            . '<head><meta charset="utf-8"><meta name="gssmd-frame" content="page"><base href="' . $this->e($pageUrl) . '">'
            . implode('', $head)
            . '<style>' . self::SHELL_CSS . '</style>'
            . implode('', $extraHead)
            . '</head>'
            . '<body' . $this->attrs($body) . '>' . $this->children($body, $target) . '</body></html>';
    }

    /**
     * Minimal document for when the article page cannot be used.
     *
     * @param   string[]  $stylesheets  URLs
     */
    public function fallback(array $stylesheets, string $wrapperClass, string $maxWidth, string $baseUrl, array $extraHead = []): string
    {
        $links = '';

        foreach ($stylesheets as $url) {
            $links .= '<link rel="stylesheet" href="' . $this->e($url) . '">';
        }

        $style = $maxWidth !== '' ? ' style="max-width:' . $this->e($maxWidth) . ';margin:0 auto"' : '';

        return '<!DOCTYPE html>' . "\n" . '<html><head><meta charset="utf-8"><meta name="gssmd-frame" content="fallback"><base href="' . $this->e($baseUrl) . '">'
            . $links . implode('', $extraHead) . '</head><body>'
            . '<div class="' . $this->e($wrapperClass) . '"' . $style . ' data-gssmd-slot></div></body></html>';
    }

    /**
     * Supported selectors: comma separated alternatives of tag, #id, .class (several),
     * [attr], [attr=value], [attr="value"], e.g. "[data-blog-content], div.article-body".
     *
     * @return  string[]  One XPath expression per alternative.
     */
    public function toXPath(string $selector): array
    {
        $expressions = [];

        foreach (explode(',', $selector) as $part) {
            $part = trim($part);

            if (!preg_match('/^([a-z][a-z0-9-]*)?((?:#[\w-]+|\.[\w-]+|\[[\w-]+(?:=(?:"[^"]*"|\'[^\']*\'|[\w-]+))?\])*)$/i', $part, $m) || $part === '') {
                continue;
            }

            $conditions = [];

            preg_match_all('/#([\w-]+)|\.([\w-]+)|\[([\w-]+)(?:=(?:"([^"]*)"|\'([^\']*)\'|([\w-]+)))?\]/', $m[2], $tokens, PREG_SET_ORDER);

            foreach ($tokens as $t) {
                if (($t[1] ?? '') !== '') {
                    $conditions[] = '@id=' . $this->literal($t[1]);
                } elseif (($t[2] ?? '') !== '') {
                    $conditions[] = 'contains(concat(" ", normalize-space(@class), " "), ' . $this->literal(' ' . $t[2] . ' ') . ')';
                } else {
                    $value = ($t[4] ?? '') . ($t[5] ?? '') . ($t[6] ?? '');
                    $conditions[] = isset($t[4]) || isset($t[5]) || isset($t[6])
                        ? '@' . $t[3] . '=' . $this->literal($value)
                        : '@' . $t[3];
                }
            }

            $expressions[] = '//' . (($m[1] ?? '') !== '' ? strtolower($m[1]) : '*') . ($conditions ? '[' . implode(' and ', $conditions) . ']' : '');
        }

        return $expressions;
    }

    private function children(\DOMElement $parent, \DOMElement $target): string
    {
        $out = '';

        foreach ($parent->childNodes as $child) {
            if (!$child instanceof \DOMElement || \in_array($child->nodeName, self::DROP, true)) {
                continue;
            }

            if ($child->isSameNode($target)) {
                $out .= '<' . $child->nodeName . $this->attrs($child) . ' data-gssmd-slot></' . $child->nodeName . '>';
            } elseif ($this->contains($child, $target)) {
                // data-gssmd-chain: lets the editor make the column follow the pane width ("reflow").
                $out .= '<' . $child->nodeName . $this->attrs($child) . ' data-gssmd-chain>' . $this->children($child, $target) . '</' . $child->nodeName . '>';
            } elseif (\in_array($child->nodeName, self::VOID, true)) {
                continue;
            } else {
                $out .= '<' . $child->nodeName . $this->attrs($child) . ' data-gssmd-shell aria-hidden="true"></' . $child->nodeName . '>';
            }
        }

        return $out;
    }

    private function contains(\DOMNode $ancestor, \DOMNode $node): bool
    {
        for ($n = $node->parentNode; $n; $n = $n->parentNode) {
            if ($n->isSameNode($ancestor)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param   ?string[]  $only  Restrict to these attribute names.
     */
    private function attrs(\DOMElement $el, ?array $only = null): string
    {
        $out = '';

        foreach ($el->attributes as $attr) {
            $name = strtolower($attr->name);

            if (($only !== null && !\in_array($name, $only, true)) || preg_match(self::UNSAFE_ATTR, $name) || !preg_match('/^[a-z_:][\w:.-]*$/', $name)) {
                continue;
            }

            $out .= ' ' . $name . '="' . $this->e($attr->value) . '"';
        }

        return $out;
    }

    private function literal(string $value): string
    {
        if (!str_contains($value, '"')) {
            return '"' . $value . '"';
        }

        if (!str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        return 'concat("' . str_replace('"', '", \'"\', "', $value) . '")';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
