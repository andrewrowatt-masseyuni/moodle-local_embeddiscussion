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
 * Data generator for embedded discussions.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_embeddiscussion\threads;

/**
 * Seeds threads, posts and reactions for embedded discussion tests.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_embeddiscussion_generator extends component_generator_base {
    /** @var int How many threads this generator has made, for default names. */
    protected $threadcount = 0;

    /**
     * Reset the generator's counters between tests.
     */
    public function reset() {
        $this->threadcount = 0;
    }

    /**
     * Create a thread directly, without going through an editor.
     *
     * Put the matching token - local_embeddiscussion\token::html($thread->embedkey) - into the
     * activity's or section's text for it to be shown.
     *
     * @param array|stdClass $record Needs cmid, sectionid, or courseid for a draft. Optional: embedkey,
     *     anonymous, handleoffset.
     * @return stdClass The new row.
     */
    public function create_thread($record): stdClass {
        global $DB;

        $record = (array)$record;
        $now = time();

        if (!empty($record['cmid'])) {
            $record['courseid'] = (int)$DB->get_field('course_modules', 'course', ['id' => $record['cmid']], MUST_EXIST);
            $record['contextid'] = context_module::instance($record['cmid'])->id;
        } else {
            if (!empty($record['sectionid'])) {
                $record['courseid'] = (int)$DB->get_field('course_sections', 'course', ['id' => $record['sectionid']], MUST_EXIST);
            }
            if (empty($record['courseid'])) {
                throw new coding_exception('A thread needs a cmid, a sectionid or a courseid.');
            }
            $record['contextid'] = context_course::instance($record['courseid'])->id;
        }
        if (array_key_exists('anonymous', $record)) {
            $record['anonymous'] = (int)(bool)$record['anonymous'];
        }

        $this->threadcount++;
        $row = (object)array_merge([
            'cmid' => 0,
            'sectionid' => 0,
            'embedkey' => \local_embeddiscussion\token::new_key(),
            'anonymous' => 0,
            'handleoffset' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ], $record);

        $row->id = $DB->insert_record(threads::TABLE, $row);
        threads::reset_cache();

        return $row;
    }

    /**
     * Create a thread for a section, named by course and section number, as Behat does.
     *
     * Core has no generator for section summaries, so this can set one too.
     *
     * @param array|stdClass $record Needs courseid and section (the number). Optional: summary, the
     *     section's new summary, which should hold the token. Otherwise as create_thread().
     * @return stdClass The new row.
     */
    public function create_section_thread($record): stdClass {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        $record = (array)$record;
        $section = get_fast_modinfo($record['courseid'])->get_section_info($record['section'], MUST_EXIST);
        $summary = $record['summary'] ?? null;
        unset($record['section'], $record['summary']);

        $row = $this->create_thread(['sectionid' => $section->id] + $record);

        if ($summary !== null) {
            course_update_section($record['courseid'], $section, ['summary' => $summary, 'summaryformat' => FORMAT_HTML]);
        }

        return $row;
    }

    /**
     * Create a post in an existing thread.
     *
     * @param array|stdClass $record Needs threadid (or thread, its key), userid and content.
     *     Optional: courseid (to tell apart threads with the same key), parentid, timecreated.
     * @return stdClass The new row.
     */
    public function create_post($record): stdClass {
        global $DB;

        $record = (array)$record;
        foreach (['userid', 'content'] as $required) {
            if (empty($record[$required])) {
                throw new coding_exception("An embedded discussion post needs '$required'.");
            }
        }

        $thread = $this->find_thread($record);
        $now = (int)($record['timecreated'] ?? time());
        $post = (object)[
            'threadid' => (int)$thread->id,
            'parentid' => (int)($record['parentid'] ?? 0),
            'userid' => (int)$record['userid'],
            'content' => \local_embeddiscussion\manager::sanitise((string)$record['content']),
            'edited' => 0,
            'deleted' => 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $post->id = $DB->insert_record('local_embeddiscussion_post', $post);

        if ($thread->anonymous) {
            \local_embeddiscussion\handles::get_or_assign($thread, (int)$record['userid']);
        }

        return $post;
    }

    /**
     * Add an emoji reaction to a post. Adding the same emoji twice does nothing.
     *
     * @param array|stdClass $record Needs threadid (or thread, its key), userid, emoji, and postid
     *     or postcontent (part of the post's text). Optional: courseid.
     */
    public function create_reaction($record): void {
        global $DB;

        $record = (array)$record;
        foreach (['userid', 'emoji'] as $required) {
            if (!isset($record[$required])) {
                throw new coding_exception("An embedded discussion reaction needs '$required'.");
            }
        }

        $thread = $this->find_thread($record);
        $key = [
            'postid' => $this->find_post_id((int)$thread->id, $record),
            'userid' => (int)$record['userid'],
            'emoji' => (string)$record['emoji'],
        ];
        if ($DB->record_exists('local_embeddiscussion_reaction', $key)) {
            return;
        }

        $DB->insert_record('local_embeddiscussion_reaction', (object)($key + ['timecreated' => time()]));
    }

    /**
     * The thread a record is about: by threadid, or by thread (its key), optionally in courseid.
     *
     * A key is unique only within its activity, or its course for sections. A key used in more than
     * one place needs threadid instead, or courseid when the places are in different courses.
     *
     * @param array $record
     * @return stdClass
     */
    protected function find_thread(array $record): stdClass {
        global $DB;

        if (!empty($record['threadid'])) {
            return $DB->get_record(threads::TABLE, ['id' => $record['threadid']], '*', MUST_EXIST);
        }
        if (empty($record['thread'])) {
            throw new coding_exception('Name the thread, by threadid or thread.');
        }

        $params = ['embedkey' => (string)$record['thread']];
        if (!empty($record['courseid'])) {
            $params['courseid'] = (int)$record['courseid'];
        }
        $threads = $DB->get_records(threads::TABLE, $params, 'id');
        if (count($threads) !== 1) {
            throw new coding_exception('There is not exactly one thread with the key "' . $record['thread'] . '".');
        }

        return reset($threads);
    }

    /**
     * Find a post in a thread, by postid or by part of its text.
     *
     * @param int $threadid
     * @param array $record
     * @return int
     */
    protected function find_post_id(int $threadid, array $record): int {
        global $DB;

        if (!empty($record['postid'])) {
            return (int)$record['postid'];
        }
        if (empty($record['postcontent'])) {
            throw new coding_exception('A reaction needs postid or postcontent.');
        }

        $like = $DB->sql_like('content', ':needle');
        $rows = $DB->get_records_sql(
            "SELECT id FROM {local_embeddiscussion_post} WHERE threadid = :threadid AND $like ORDER BY id ASC",
            ['threadid' => $threadid, 'needle' => '%' . $DB->sql_like_escape($record['postcontent']) . '%'],
            0,
            1
        );
        if (!$rows) {
            throw new coding_exception('No post containing "' . $record['postcontent'] . '".');
        }

        return (int)reset($rows)->id;
    }
}
