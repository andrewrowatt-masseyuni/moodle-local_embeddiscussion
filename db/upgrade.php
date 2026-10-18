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
 * Upgrade steps for embedded discussions.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade embedded discussions.
 *
 * @param int $oldversion The version being upgraded from.
 * @return bool
 */
function xmldb_local_embeddiscussion_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026101801) {
        // A discussion no longer has a name: the course's list of discussions, the one place other than
        // the editor that showed it, has gone.
        $table = new xmldb_table('local_embeddiscussion_thread');
        $field = new xmldb_field('name');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        // Viewing that list left a system report record behind, whose source class has gone with it.
        // A system report keeps nothing else: its columns and filters are its class's.
        $DB->delete_records('reportbuilder_report', [
            'type' => 1,
            'source' => 'local_embeddiscussion\\reportbuilder\\local\\systemreports\\threads',
        ]);

        upgrade_plugin_savepoint(true, 2026101801, 'local', 'embeddiscussion');
    }

    return true;
}
