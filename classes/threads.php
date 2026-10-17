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
 * Finds the threads a piece of text can show.
 *
 * Rows are read a whole course at a time - activities' and sections' threads together - and held for
 * the request. The filter asks about one activity or section summary at a time, and a course page
 * may run it over a dozen of each; paying one query per course keeps that flat.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class threads {
    /** @var string The thread table. */
    public const TABLE = 'local_embeddiscussion_thread';

    /**
     * Rows keyed by course id, then 'cms' => cm id => key, and 'sections' => key.
     *
     * @var array<int, array{cms: array<int, array<string, \stdClass>>, sections: array<string, \stdClass>}>
     */
    protected static $cache = [];

    /**
     * One activity's threads, keyed by key.
     *
     * @param int $courseid The activity's course. Passed in rather than looked up because every
     *     caller already has it, and a lookup here would cost the query this class exists to save.
     * @param int $cmid The course module.
     * @return array<string, \stdClass>
     */
    public static function for_cm(int $courseid, int $cmid): array {
        return self::load($courseid)['cms'][$cmid] ?? [];
    }

    /**
     * Every thread in a course's section summaries, keyed by key.
     *
     * Keyed by course rather than by section because that is all the filter knows: a section summary
     * is formatted in its course's context, which says nothing of which section it is.
     * api::claim_section_summary() is what keeps a section from showing another's thread.
     *
     * @param int $courseid The course.
     * @return array<string, \stdClass>
     */
    public static function for_sections(int $courseid): array {
        return self::load($courseid)['sections'];
    }

    /**
     * Read a course's threads, drafts aside, once per request.
     *
     * @param int $courseid The course.
     * @return array{cms: array<int, array<string, \stdClass>>, sections: array<string, \stdClass>}
     */
    protected static function load(int $courseid): array {
        global $DB;

        if (isset(self::$cache[$courseid])) {
            return self::$cache[$courseid];
        }

        $rows = $DB->get_records_select(
            self::TABLE,
            'courseid = :courseid AND (cmid > 0 OR sectionid > 0)',
            ['courseid' => $courseid],
            'id'
        );

        $threads = ['cms' => [], 'sections' => []];
        foreach ($rows as $row) {
            if ((int)$row->cmid > 0) {
                $threads['cms'][(int)$row->cmid][$row->embedkey] = $row;
            } else {
                $threads['sections'][$row->embedkey] = $row;
            }
        }

        return self::$cache[$courseid] = $threads;
    }

    /**
     * The context a thread belongs to: its activity's, or for a section's thread or a draft, its
     * course's.
     *
     * @param \stdClass $row A thread row.
     * @return \context
     */
    public static function context_for(\stdClass $row): \context {
        if ((int)$row->cmid > 0) {
            return \context_module::instance((int)$row->cmid);
        }

        return \context_course::instance((int)$row->courseid);
    }

    /**
     * A thread's name, formatted for output.
     *
     * @param \stdClass $row A thread row.
     * @return string
     */
    public static function display_name(\stdClass $row): string {
        $name = trim((string)($row->name ?? ''));
        if ($name === '') {
            return '';
        }

        $context = \context::instance_by_id((int)$row->contextid, IGNORE_MISSING) ?: \context_system::instance();

        return format_string($name, true, ['context' => $context]);
    }

    /**
     * Forget what was read for this request.
     */
    public static function reset_cache(): void {
        self::$cache = [];
    }
}
