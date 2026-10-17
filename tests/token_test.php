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
 * Tests for the embedded discussion token.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embeddiscussion\token
 */
final class token_test extends \advanced_testcase {
    /**
     * A token is an empty element, and its key is 16 hex characters.
     */
    public function test_html_and_new_key(): void {
        $key = token::new_key();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key);
        $this->assertNotSame($key, token::new_key());
        $this->assertSame(
            '<div class="embeddiscussion-embed" data-embeddiscussion="' . $key . '"></div>',
            token::html($key)
        );
    }

    /**
     * Keys are found however TinyMCE wrote the token - padded, attributes reordered, single quotes -
     * in order and once each. The dashboard is not a thread, and is left out.
     */
    public function test_keys_in(): void {
        $text = '<p>Intro</p>' .
            '<div class="embeddiscussion-embed" data-embeddiscussion="0123456789abcdef">&nbsp;</div>' .
            "<div data-embeddiscussion='fedcba9876543210' contenteditable=\"false\" class=\"embeddiscussion-embed\"></div>" .
            token::html(token::DASHBOARD) .
            token::html('0123456789abcdef');

        $this->assertSame(['0123456789abcdef', 'fedcba9876543210'], token::keys_in($text));
        $this->assertSame([], token::keys_in('<p>Nothing to see</p>'));
        $this->assertSame([], token::keys_in(null));
        // Not a key: wrong length, or not hex.
        $this->assertSame([], token::keys_in('<div data-embeddiscussion="0123"></div>'));
        $this->assertSame([], token::keys_in('<div data-embeddiscussion="zzzzzzzzzzzzzzzz"></div>'));
    }

    /**
     * Each whole token is replaced - the dashboard's too - and the text around it is left alone.
     */
    public function test_replace(): void {
        $text = '<p>Before</p>' . token::html('0123456789abcdef') . '<p>Middle</p>' .
            token::html(token::DASHBOARD) . '<p>After</p>';

        $replaced = token::replace($text, fn(string $key) => "[{$key}]");

        $this->assertSame('<p>Before</p>[0123456789abcdef]<p>Middle</p>[dashboard]<p>After</p>', $replaced);
        $this->assertSame('<p>No tokens</p>', token::replace('<p>No tokens</p>', fn() => 'X'));
    }

    /**
     * Rekeying changes only the keys asked for, and leaves the rest of each token as it was.
     */
    public function test_rekey(): void {
        $text = '<div class="embeddiscussion-embed" contenteditable="false" data-embeddiscussion="0123456789abcdef"></div>' .
            token::html('fedcba9876543210');

        $rekeyed = token::rekey($text, ['0123456789abcdef' => 'aaaaaaaaaaaaaaaa']);

        $this->assertSame(
            '<div class="embeddiscussion-embed" contenteditable="false" data-embeddiscussion="aaaaaaaaaaaaaaaa"></div>' .
                token::html('fedcba9876543210'),
            $rekeyed
        );
    }
}
