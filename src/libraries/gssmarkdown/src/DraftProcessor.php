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
 * Finds editor submissions in request data and replaces them with server-rendered HTML.
 *
 * Working on the request (instead of one component's save event) guarantees that no
 * component ever stores browser-rendered HTML from this editor, whatever it is used in:
 * com_content, EasyBlog (form submit and AJAX autosave), categories, modules, fields.
 */
final class DraftProcessor
{
    /** @var callable(string): string */
    private $render;

    /**
     * @param   ContentCodec  $codec
     * @param   callable      $render    fn(string $markdown): string — value to store.
     * @param   ?int          $maxBytes  Larger Markdown is not rendered (the value is left as
     *                                   submitted). Used for visitors who are not logged in, so
     *                                   an anonymous request cannot make the server render
     *                                   megabytes of Markdown.
     */
    public function __construct(private readonly ContentCodec $codec, callable $render, private readonly ?int $maxBytes = null)
    {
        $this->render = $render;
    }

    /**
     * @param   array  $data  e.g. $_POST
     *
     * @return  array  Only the top-level keys whose value changed, with the new values.
     */
    public function process(array $data): array
    {
        $changed = [];

        foreach ($data as $key => $value) {
            $new = $this->walk($value, 0);

            if ($new !== $value) {
                $changed[$key] = $new;
            }
        }

        return $changed;
    }

    private function walk(mixed $value, int $depth): mixed
    {
        if (\is_string($value)) {
            if (!$this->codec->hasDraft($value)) {
                return $value;
            }

            $markdown = $this->codec->extractDraft($value);

            if ($markdown === null || ($this->maxBytes !== null && \strlen($markdown) > $this->maxBytes)) {
                return $value;
            }

            return ($this->render)($markdown);
        }

        // Joomla forms nest at most a few levels (jform[attribs][...]).
        if (\is_array($value) && $depth < 8) {
            foreach ($value as $k => $v) {
                $value[$k] = $this->walk($v, $depth + 1);
            }
        }

        return $value;
    }
}
