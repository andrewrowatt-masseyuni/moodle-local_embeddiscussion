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
 * Library callbacks for embedded discussions.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_embeddiscussion\api;

/**
 * After any activity is saved through its settings form, adopt the drafts embedded in its text.
 *
 * A draft is made on the "add an activity" form, before the activity had an id. This is where the
 * activity that now carries its token takes it over.
 *
 * Failures are reported rather than thrown: the activity has already been saved, and a problem with
 * its discussions must not turn a successful save into an error page.
 *
 * @param stdClass $data The module info, with ->coursemodule set.
 * @param stdClass $course The course.
 * @return stdClass The module info, unchanged.
 */
function local_embeddiscussion_coursemodule_edit_post_actions($data, $course) {
    if (empty($data->coursemodule)) {
        return $data;
    }

    try {
        api::adopt_module((int)$data->coursemodule);
    } catch (\moodle_exception $e) {
        debugging('Could not adopt embedded discussions for course module ' . $data->coursemodule . ': ' .
            $e->getMessage(), DEBUG_DEVELOPER);
    }

    return $data;
}

/**
 * Delete a course module's threads, with their posts, before the module goes.
 *
 * tool_recyclebin's own callback runs first (tool before local), so a recycled activity is backed
 * up with its discussions before this deletes them.
 *
 * @param stdClass $cm The course_modules record.
 */
function local_embeddiscussion_pre_course_module_delete($cm) {
    api::delete_for_cm((int)$cm->id);
}
