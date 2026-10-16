<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Encodes / decodes the Markdown source that travels inside the content field.
 *
 * Two carriers exist:
 *
 * 1. Stored carrier (what lives in the database):
 *      <div class="gssmd-source" hidden="hidden" data-gssmd="v1:{fingerprint}:{base64}"></div>
 *    An empty element with a data attribute, NOT an HTML comment: Joomla's InputFilter
 *    (used by EasyBlog's filterHtml() and by Joomla's per-group text filters) strips
 *    HTML comments, which would silently drop the source. The fingerprint is a hash of
 *    the rendered HTML's visible text, used to detect edits made later in another editor.
 *
 * 2. Draft carrier (what the editor submits):
 *      <div hidden data-gssmd-draft="v1:{base64}"></div>
 *    Only this marker triggers server-side re-rendering. Content saved by JCE or the
 *    EasyBlog composer never carries it, so their edits are never overwritten.
 *
 * The original comment format (<!--GSSMD:v1:base64:...-->) is still read.
 */
final class ContentCodec
{
    private const STORED_PATTERN = '#\s*<div\b[^>]*\bdata-gssmd="v1:([0-9a-f]{16}):([A-Za-z0-9+/=]*)"[^>]*>\s*</div>#i';
    private const DRAFT_PATTERN  = '#<div\b[^>]*\bdata-gssmd-draft="v1:([A-Za-z0-9+/=]*)"[^>]*>\s*</div>#i';
    private const COMMENT_PATTERN = '#\s*<!--GSSMD:v1:base64:([A-Za-z0-9+/=]*)-->#';

    /**
     * Looks for Markdown source in stored content.
     *
     * @param   string  $content  Raw (not HTML-escaped) content from the database.
     *
     * @return  ?Extracted  Null when the content has no Markdown source (legacy article).
     */
    public function extract(string $content): ?Extracted
    {
        if (preg_match(self::STORED_PATTERN, $content, $m)) {
            $markdown = $this->decode($m[2]);

            if ($markdown !== null) {
                $html = $this->strip($content);

                return new Extracted($markdown, $html, hash_equals($m[1], $this->fingerprint($html)));
            }
        }

        if (preg_match(self::COMMENT_PATTERN, $content, $m)) {
            $markdown = $this->decode($m[1]);

            if ($markdown !== null) {
                // The legacy format carries no fingerprint, so drift cannot be detected.
                return new Extracted($markdown, $this->strip($content), true);
            }
        }

        return null;
    }

    /**
     * Builds the value stored in the content field: rendered HTML followed by the carrier.
     */
    public function encode(string $html, string $markdown, ?string $fingerprint = null): string
    {
        $html = rtrim($html);

        return $html . "\n" . '<div class="gssmd-source" hidden="hidden" data-gssmd="v1:'
            . ($fingerprint ?? $this->fingerprint($html)) . ':' . base64_encode($markdown) . '"></div>';
    }

    /**
     * Returns the Markdown inside a draft carrier submitted by the editor, or null.
     */
    public function extractDraft(string $value): ?string
    {
        if (!preg_match(self::DRAFT_PATTERN, $value, $m)) {
            return null;
        }

        return $this->decode($m[1]);
    }

    /**
     * Quick check used to skip request values that cannot contain a draft.
     */
    public function hasDraft(string $value): bool
    {
        return str_contains($value, 'data-gssmd-draft=');
    }

    /**
     * Builds the draft carrier. Mirrors what media/.../editor.js produces; used by tests.
     */
    public function encodeDraft(string $markdown): string
    {
        return '<div hidden data-gssmd-draft="v1:' . base64_encode($markdown) . '"></div>';
    }

    /**
     * Removes every carrier, leaving only the HTML.
     */
    public function strip(string $content): string
    {
        $content = preg_replace([self::STORED_PATTERN, self::COMMENT_PATTERN, self::DRAFT_PATTERN], '', $content);

        return rtrim($content);
    }

    /**
     * Applies a text transformation to both the HTML and the Markdown source, then
     * rebuilds the carrier. Used to rewrite temporary image URLs after a move.
     *
     * @param   string    $content  Stored content.
     * @param   callable  $rewrite  fn(string $text): string
     */
    public function rewrite(string $content, callable $rewrite): string
    {
        $extracted = $this->extract($content);

        if ($extracted === null) {
            return $rewrite($content);
        }

        $html = $rewrite($extracted->html);

        // Keep a drifted article drifted: a URL rewrite must not make stale Markdown look current.
        $fingerprint = $extracted->inSync ? null : str_repeat('0', 16);

        return $this->encode($html, $rewrite($extracted->markdown), $fingerprint);
    }

    /**
     * Hash of the visible text of an HTML fragment.
     *
     * Only text is hashed because component filters (EasyBlog filterHtml, Joomla text
     * filters, jQuery re-serialisation in the composer) may legitimately reorder
     * attributes or change whitespace without the author changing anything.
     */
    public function fingerprint(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $text) ?? $text;

        return substr(hash('sha256', $text), 0, 16);
    }

    private function decode(string $base64): ?string
    {
        $decoded = base64_decode($base64, true);

        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }

        return $decoded;
    }
}
