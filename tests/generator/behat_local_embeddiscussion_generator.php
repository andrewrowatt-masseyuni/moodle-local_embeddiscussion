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

/**
 * Behat data generator for embedded discussions.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Behat data generator for embedded discussions.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_embeddiscussion_generator extends behat_generator_base {
    /**
     * Entities this generator can create.
     *
     * "local_embeddiscussion > threads": a discussion for the activity with the given idnumber. Put
     * the matching token - <div class="embeddiscussion-embed" data-embeddiscussion="KEY"></div> - into
     * the activity's text (a page's content, a chapter, a description) for it to be shown.
     *
     * "local_embeddiscussion > section threads": a discussion for the section with the given number
     * in the course with the given shortname. Its summary should hold the token; give "summary" to
     * set it.
     *
     * "local_embeddiscussion > posts" and "reactions" name their thread by its name.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'threads' => [
                'singular' => 'thread',
                'datagenerator' => 'thread',
                'required' => ['activity', 'name', 'embedkey'],
                'switchids' => ['activity' => 'cmid'],
            ],
            'section threads' => [
                'singular' => 'section thread',
                'datagenerator' => 'section_thread',
                'required' => ['course', 'section', 'name', 'embedkey'],
                'switchids' => ['course' => 'courseid'],
            ],
            'posts' => [
                'singular' => 'post',
                'datagenerator' => 'post',
                'required' => ['thread', 'user', 'content'],
                'switchids' => ['user' => 'userid'],
            ],
            'reactions' => [
                'singular' => 'reaction',
                'datagenerator' => 'reaction',
                'required' => ['thread', 'user', 'postcontent', 'emoji'],
                'switchids' => ['user' => 'userid'],
            ],
        ];
    }
}
