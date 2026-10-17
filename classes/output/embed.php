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

namespace local_embeddiscussion\output;

use local_embeddiscussion\manager;
use local_embeddiscussion\threads;

/**
 * What an embedded discussion's token becomes: on the page, a placeholder the discussion loads into;
 * in the editor, a preview of it.
 *
 * The page's output never contains the token attribute, which is what makes a second filter pass over
 * already-filtered text a no-op. mod_lesson does exactly that to page contents shown as feedback.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class embed {
    /**
     * A thread's placeholder for the page, which the discussion JS module fills.
     *
     * Callers are responsible for having found the thread in the right place.
     *
     * @param \stdClass $row The thread row.
     * @return string HTML.
     */
    public static function render_thread(\stdClass $row): string {
        global $OUTPUT, $PAGE;

        if ($PAGE->requires->should_create_one_time_item_now('local_embeddiscussion/discussion')) {
            $PAGE->requires->js_call_amd('local_embeddiscussion/discussion', 'init');
        }

        return $OUTPUT->render_from_template('local_embeddiscussion/placeholder', [
            'uid' => manager::get_thread_uid($row->id, $row->contextid),
            'threadid' => (int)$row->id,
            'contextid' => (int)$row->contextid,
        ]);
    }

    /**
     * The course dashboard's placeholder for the page, which the dashboard JS module fills.
     *
     * @param int $courseid The course.
     * @return string HTML.
     */
    public static function render_dashboard(int $courseid): string {
        global $OUTPUT, $PAGE;

        if ($PAGE->requires->should_create_one_time_item_now('local_embeddiscussion/dashboard')) {
            $PAGE->requires->js_call_amd('local_embeddiscussion/dashboard', 'init');
        }

        return $OUTPUT->render_from_template('local_embeddiscussion/dashboard_placeholder', [
            'uid' => 'embeddisc_dashboard_' . $courseid,
            'courseid' => $courseid,
        ]);
    }

    /**
     * The notice shown, to someone who could fix it, in place of a token with no thread here.
     *
     * Tokens are scoped to their activity, or a section's to its course, so one pasted in from
     * elsewhere does not resolve.
     *
     * @return string HTML.
     */
    public static function render_not_found(): string {
        return \html_writer::div(
            get_string('notfound', 'local_embeddiscussion'),
            'alert alert-warning embeddisc-notfound',
            ['role' => 'note']
        );
    }

    /**
     * A token as the editor previews it: a header naming the discussion over its skeleton, the
     * dashboard's skeleton, or - for a token with no thread in the editor's context - the notice the
     * page will show there.
     *
     * Never live: nothing in the editor loads a discussion, for anyone.
     *
     * @param string $key The token's key.
     * @param \stdClass|null $row Its thread in the editor's context, if it has one.
     * @return string HTML.
     */
    public static function render_preview(string $key, ?\stdClass $row): string {
        global $OUTPUT;

        if ($key === \local_embeddiscussion\token::DASHBOARD) {
            return $OUTPUT->render_from_template('local_embeddiscussion/preview', [
                'dashboard' => true,
                'name' => '',
                'anonymous' => false,
            ]);
        }

        if (!$row) {
            return self::render_not_found();
        }

        return $OUTPUT->render_from_template('local_embeddiscussion/preview', [
            'dashboard' => false,
            // Unescaped: the template escapes it.
            'name' => format_string($row->name, true, ['context' => threads::context_for($row), 'escape' => false]),
            'anonymous' => (bool)$row->anonymous,
        ]);
    }
}
