<?php

/**
 * @package     GssMarkdown
 * @copyright   (C) 2026 Galaxy Software Services Corp.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */

namespace Gss\Library\GssMarkdown;

use Gss\Library\GssMarkdown\Video\VideoUrl;
use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Lossy HTML -> Markdown for articles that have no Markdown source. Only ever used
 * after the author confirmed the "this may change the layout" warning.
 *
 * Tags without a Markdown equivalent are dropped but their text is kept, so the author
 * sees what was lost in the preview instead of carrying invisible HTML along.
 * Joomla/EasyBlog shortcodes ({source}, {loadmodule} ...) stay as plain text.
 */
final class LegacyConverter
{
    private HtmlConverter $converter;

    public function __construct()
    {
        Bootstrap::vendor();

        $this->converter = new HtmlConverter([
            'header_style'    => 'atx',
            'strip_tags'      => true,
            'remove_nodes'    => 'script style iframe object embed form',
            'hard_break'      => true,
            'use_autolinks'   => true,
            'strip_placeholder_links' => true,
        ]);
        $this->converter->getEnvironment()->addConverter(new TableConverter());
    }

    public function convert(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Keep video embeds: an <iframe> / <video> is dropped by the conversion, a URL on its
        // own line comes back as a video (Video\\VideoExtension). A neutral token passes
        // through the converter (which would escape "&" in the URL) and is swapped afterwards.
        $videos = [];
        $keep   = static function (?array $video) use (&$videos): ?string {
            if (!$video) {
                return null;
            }

            $token          = 'GSSMDVIDEO' . \count($videos) . 'X';
            // A file path is not an autolink, so files use the image syntax.
            $videos[$token] = $video['provider'] === 'file' ? '![](<' . $video['file'] . '>)' : $video['watch'];

            return '<p>' . $token . '</p>';
        };

        $html = preg_replace_callback(
            '#<iframe\b[^>]*\bsrc=["\']([^"\']+)["\'][^>]*>.*?</iframe>#is',
            static fn (array $m): string => $keep(VideoUrl::parse($m[1])) ?? $m[0],
            $html
        ) ?? $html;

        $html = preg_replace_callback(
            '#<video\b([^>]*)>(.*?)</video>#is',
            static function (array $m) use ($keep): string {
                $src = preg_match('#\bsrc=["\']([^"\']+)["\']#i', $m[1], $a) ? $a[1]
                    : (preg_match('#<source\b[^>]*\bsrc=["\']([^"\']+)["\']#i', $m[2], $b) ? $b[1] : '');
                $src = html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                return $keep(VideoUrl::file($src) !== null ? VideoUrl::parse($src) : null) ?? $m[0];
            },
            $html
        ) ?? $html;

        return rtrim(strtr($this->converter->convert($html), $videos)) . "\n";
    }

    /**
     * Elements the conversion removes together with their content (video players excluded,
     * they come back as videos), so the author can be told what will be lost.
     *
     * @return  string[]  Tag names, e.g. ['script', 'iframe'].
     */
    public static function droppedTags(string $html): array
    {
        $dropped = [];

        foreach (['script', 'style', 'object', 'embed', 'form'] as $tag) {
            if (preg_match('#<' . $tag . '\b#i', $html)) {
                $dropped[] = $tag;
            }
        }

        preg_match_all('#<iframe\b[^>]*>#i', $html, $frames);

        foreach ($frames[0] as $tag) {
            $video = preg_match('#\bsrc=["\']([^"\']*)["\']#i', $tag, $src) ? VideoUrl::parse($src[1]) : null;

            if ($video === null || $video['provider'] === 'file') {
                $dropped[] = 'iframe';
                break;
            }
        }

        return $dropped;
    }
}
