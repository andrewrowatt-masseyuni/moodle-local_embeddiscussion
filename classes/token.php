<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_embeddiscussion;

/**
 * The opaque token that marks where an embedded discussion sits in rich text.
 *
 * The token is an empty element: <div class="embeddiscussion-embed" data-embeddiscussion="KEY"></div>.
 * It names a thread in local_embeddiscussion_thread and carries nothing else - not its name, not
 * whether it is anonymous. Those are the thread's, set from the editor, so a teacher changes them in
 * one place and copying the text can never quietly change them. Where the filter does not run, the
 * token renders as nothing (styles.css hides it, since TinyMCE pads an empty div with &nbsp;).
 *
 * The key is 16 hex characters, or the word "dashboard" for the course's discussion dashboard, which
 * has no thread and no settings of its own.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token {
    /** @var string The attribute carrying the key. Also the cheap pre-check before any regex runs. */
    public const ATTRIBUTE = 'data-embeddiscussion';

    /** @var string The key of the course dashboard's token. */
    public const DASHBOARD = 'dashboard';

    /**
     * @var string Matches one whole token element, capturing the key.
     *
     * Attributes may come in any order and the element may have content, because TinyMCE pads an
     * empty div with &nbsp; and may reorder attributes when it serialises. The content is matched
     * lazily up to the first closing div: a token never contains markup of its own.
     */
    public const PATTERN = '~<div\b[^>]*\bdata-embeddiscussion\s*=\s*["\']([0-9a-f]{16}|dashboard)["\'][^>]*>.*?</div>~is';

    /**
     * A fresh key.
     *
     * @return string 16 hex characters.
     */
    public static function new_key(): string {
        return bin2hex(random_bytes(8));
    }

    /**
     * The token element for a key.
     *
     * @param string $key The thread's key, or self::DASHBOARD.
     * @return string
     */
    public static function html(string $key): string {
        return '<div class="embeddiscussion-embed" ' . self::ATTRIBUTE . '="' . s($key) . '"></div>';
    }

    /**
     * Whether a piece of text might hold a token. Cheap enough to run on every piece of text.
     *
     * @param string|null $text The text.
     * @return bool
     */
    public static function might_contain(?string $text): bool {
        return $text !== null && $text !== '' && stripos($text, self::ATTRIBUTE) !== false;
    }

    /**
     * The thread keys in a piece of text, in the order they appear, each once. The dashboard is not
     * a thread, and is left out.
     *
     * @param string|null $text The text.
     * @return string[]
     */
    public static function keys_in(?string $text): array {
        if (!self::might_contain($text)) {
            return [];
        }

        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_diff(array_unique($matches[1]), [self::DASHBOARD]));
    }

    /**
     * Replace every token in a piece of text.
     *
     * @param string $text The text.
     * @param callable $replacement Called with the key; returns the HTML to put in the token's place.
     * @return string
     */
    public static function replace(string $text, callable $replacement): string {
        if (!self::might_contain($text)) {
            return $text;
        }

        return preg_replace_callback(self::PATTERN, fn(array $match) => $replacement($match[1]), $text);
    }

    /**
     * Point some tokens at different keys, leaving the rest of each token as the editor wrote it.
     *
     * @param string $text The text.
     * @param string[] $keys New keys, keyed by old.
     * @return string
     */
    public static function rekey(string $text, array $keys): string {
        if (!$keys || !self::might_contain($text)) {
            return $text;
        }

        return preg_replace_callback(self::PATTERN, function (array $match) use ($keys): string {
            if (!isset($keys[$match[1]])) {
                return $match[0];
            }

            return preg_replace(
                '~(\b' . self::ATTRIBUTE . '\s*=\s*["\'])' . $match[1] . '~i',
                '${1}' . $keys[$match[1]],
                $match[0]
            );
        }, $text);
    }
}
