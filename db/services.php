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
 * Web services for embedded discussions.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// All flagged ajax and put in no service: they are called by this plugin's own JS as the logged-in
// user, never by an external client with a token.
$functions = [
    'local_embeddiscussion_get_thread' => [
        'classname' => 'local_embeddiscussion\external\get_thread',
        'description' => 'Load an embedded discussion thread by id.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_embeddiscussion_create_post' => [
        'classname' => 'local_embeddiscussion\external\create_post',
        'description' => 'Create a post or reply in an embedded discussion thread.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/embeddiscussion:createpost',
    ],
    'local_embeddiscussion_edit_post' => [
        'classname' => 'local_embeddiscussion\external\edit_post',
        'description' => 'Edit an existing post.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/embeddiscussion:editownpost,local/embeddiscussion:manageposts',
    ],
    'local_embeddiscussion_delete_post' => [
        'classname' => 'local_embeddiscussion\external\delete_post',
        'description' => 'Delete a post.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/embeddiscussion:deleteownpost,local/embeddiscussion:deleteanypost',
    ],
    'local_embeddiscussion_react_post' => [
        'classname' => 'local_embeddiscussion\external\react_post',
        'description' => 'Toggle an emoji reaction on a post.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
        'capabilities' => 'local/embeddiscussion:createpost',
    ],
    'local_embeddiscussion_get_previews' => [
        'classname' => 'local_embeddiscussion\external\get_previews',
        'description' => 'Render embedded discussion tokens as the editor previews them.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
