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
 * Creating, adopting and deleting embedded discussion threads.
 *
 * A thread is made from the editor, never by viewing a page: the token in the text names a thread
 * that already exists. That is what lets the thread's settings live with the thread rather than in
 * the text, and lets backup, restore and duplication carry it with the activity or section it is in.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /** @var int How long a draft may wait for its activity to be saved before it is purged. */
    public const DRAFT_LIFETIME = DAYSECS;

    /** @var array<int, true> Courses whose section summaries are not to be claimed for now. */
    protected static $heldclaims = [];

    /**
     * Where a thread created from an editor in this context belongs.
     *
     * An editor in a module context - a page, a book chapter, a lesson page, an existing activity's
     * description - makes a thread for that module. An editor in a course context is either a
     * section summary, named by $sectionid, which makes a thread for that section; or a field on the
     * "add an activity" form, where the module does not exist yet, which makes a draft (cm id and
     * section id both 0) that the activity adopts when it is saved.
     *
     * The section id comes from the client, which is why it is checked against the course here.
     *
     * @param \context $context The editor's context.
     * @param int $sectionid The section whose summary is being edited, or 0.
     * @return int[] [course id, cm id or 0, section id or 0]
     * @throws \invalid_parameter_exception For any other context, or a section not in the course.
     */
    public static function embed_target(\context $context, int $sectionid = 0): array {
        global $DB;

        if ($context instanceof \context_module && !$sectionid) {
            $cm = get_coursemodule_from_id('', $context->instanceid, 0, false, MUST_EXIST);
            return [(int)$cm->course, (int)$cm->id, 0];
        }

        if ($context instanceof \context_course && (int)$context->instanceid !== (int)SITEID) {
            $courseid = (int)$context->instanceid;
            if ($sectionid && !$DB->record_exists('course_sections', ['id' => $sectionid, 'course' => $courseid])) {
                throw new \invalid_parameter_exception('That section is not in this course.');
            }
            return [$courseid, 0, $sectionid];
        }

        throw new \invalid_parameter_exception('Discussions can only be embedded in an activity or a section.');
    }

    /**
     * A thread by key, within the context an editor is working in.
     *
     * Always scoped to that context, so a key copied from another activity or section cannot be used
     * to read or change a thread that belongs somewhere else.
     *
     * @param \context $context The editor's context.
     * @param string $key The thread's key.
     * @param int $sectionid The section whose summary is being edited, or 0.
     * @return \stdClass|null
     */
    public static function get_embed(\context $context, string $key, int $sectionid = 0): ?\stdClass {
        return self::get_embeds($context, [$key], $sectionid)[$key] ?? null;
    }

    /**
     * Threads by key, within the context an editor is working in: get_embed() for every token in a
     * text at once.
     *
     * @param \context $context The editor's context.
     * @param string[] $keys The threads' keys.
     * @param int $sectionid The section whose summary is being edited, or 0.
     * @return array<string, \stdClass> Keyed by key. A key with no thread in this context is left out.
     */
    public static function get_embeds(\context $context, array $keys, int $sectionid = 0): array {
        global $DB;

        // Before anything else, so that a context no thread can belong to throws even with no keys.
        [$courseid, $cmid, $sectionid] = self::embed_target($context, $sectionid);
        if (!$keys) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);
        $params += ['courseid' => $courseid, 'cmid' => $cmid, 'sectionid' => $sectionid];
        $rows = $DB->get_records_select(
            threads::TABLE,
            "courseid = :courseid AND cmid = :cmid AND sectionid = :sectionid AND embedkey $insql",
            $params
        );

        $bykey = [];
        foreach ($rows as $row) {
            $bykey[$row->embedkey] = $row;
        }

        return $bykey;
    }

    /**
     * Create or update a thread.
     *
     * A key that does not match a thread in this context - a token pasted in from another activity,
     * say - gets a new thread with a new key rather than an error. The caller rewrites the token with
     * whatever key comes back.
     *
     * Logs thread_created for a new thread and thread_updated for an existing one.
     *
     * Callers are responsible for the capability check.
     *
     * @param \context $context The editor's context.
     * @param string|null $key The thread to update, or null for a new one.
     * @param bool $anonymous Whether students see each other under anonymous handles.
     * @param int $sectionid The section whose summary is being edited, or 0.
     * @return string The thread's key.
     */
    public static function save_embed(\context $context, ?string $key, bool $anonymous, int $sectionid = 0): string {
        global $DB;

        [$courseid, $cmid, $sectionid] = self::embed_target($context, $sectionid);
        $now = time();

        $row = ($key !== null && $key !== '') ? self::get_embed($context, $key, $sectionid) : null;
        if (!$row) {
            $row = (object)[
                'courseid' => $courseid,
                'cmid' => $cmid,
                'sectionid' => $sectionid,
                'contextid' => $context->id,
                'embedkey' => token::new_key(),
                'anonymous' => (int)$anonymous,
                'handleoffset' => random_int(0, max(count(handles::master_list()), 1) - 1),
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $row->id = $DB->insert_record(threads::TABLE, $row);
            threads::reset_cache();
            event\thread_created::create_for_thread($row, $context)->trigger();

            return $row->embedkey;
        }

        $row->anonymous = (int)$anonymous;
        $row->timemodified = $now;
        $DB->update_record(threads::TABLE, $row);
        threads::reset_cache();
        event\thread_updated::create_for_thread($row, $context)->trigger();

        return $row->embedkey;
    }

    /**
     * Adopt the drafts whose tokens are in an activity's text.
     *
     * Called whenever an activity is saved through its settings form. A thread made while the
     * activity was being added could not belong to it yet; now it can. Every text field of the
     * activity's own record is read - its description, and a page's content, say - since the form
     * may carry editors other than the description's.
     *
     * @param int $cmid The course module.
     */
    public static function adopt_module(int $cmid): void {
        global $DB;

        $cm = get_coursemodule_from_id('', $cmid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return;
        }

        $instance = (array)$DB->get_record($cm->modname, ['id' => $cm->instance]);
        $text = implode("\n", array_filter($instance, fn($value) => is_string($value) && token::might_contain($value)));
        $keys = token::keys_in($text);
        if (!$keys) {
            return;
        }

        // Section id 0: a section's thread also has cm id 0, and must not be carried off by an
        // activity whose text its token was pasted into.
        [$insql, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);
        $params['courseid'] = $cm->course;
        $drafts = $DB->get_records_select(
            threads::TABLE,
            "courseid = :courseid AND cmid = 0 AND sectionid = 0 AND embedkey $insql",
            $params
        );

        $taken = $DB->get_records_menu(threads::TABLE, ['cmid' => $cm->id], '', 'embedkey, id');
        $context = \context_module::instance($cm->id);
        foreach ($drafts as $draft) {
            if (isset($taken[$draft->embedkey])) {
                continue;
            }
            $DB->update_record(threads::TABLE, (object)[
                'id' => $draft->id,
                'cmid' => $cm->id,
                'contextid' => $context->id,
                'timemodified' => time(),
            ]);
        }

        threads::reset_cache();
    }

    /**
     * Give a section its own copy of any thread whose token was copied into its summary from another
     * section.
     *
     * Called whenever a section is updated. Core duplicates a section by copying its summary verbatim
     * (course_format::duplicate_section() - there is no hook), and a teacher can paste one section's
     * token into another. Either way two summaries would share one thread: the filter cannot tell them
     * apart, and deleting either section would take the other's discussion with it. So the copy gets
     * a thread of its own - same anonymity, no posts - under a new key, and its summary is
     * rewritten to match, as duplicating an activity would.
     *
     * Tokens whose thread is not a section's in this course are left alone: they resolve to nothing,
     * as a token copied between activities does.
     *
     * @param int $sectionid The course section.
     */
    public static function claim_section_summary(int $sectionid): void {
        global $DB;

        $section = $DB->get_record('course_sections', ['id' => $sectionid], 'id, course, summary');
        if (!$section || isset(self::$heldclaims[(int)$section->course])) {
            return;
        }

        $keys = token::keys_in($section->summary);
        if (!$keys) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($keys, SQL_PARAMS_NAMED);
        $params['courseid'] = $section->course;
        $params['sectionid'] = $sectionid;
        $others = $DB->get_records_select(
            threads::TABLE,
            "courseid = :courseid AND cmid = 0 AND sectionid > 0 AND sectionid <> :sectionid AND embedkey $insql",
            $params
        );
        if (!$others) {
            return;
        }

        $rekeyed = [];
        foreach ($others as $row) {
            $rekeyed[$row->embedkey] = self::copy_to_section($row, $sectionid);
        }

        // Straight to the table rather than through course_update_section(), which would fire the
        // event that called this. The cache purge is what that would have done.
        $DB->set_field('course_sections', 'summary', token::rekey($section->summary, $rekeyed), ['id' => $sectionid]);
        \course_modinfo::purge_course_section_cache_by_id((int)$section->course, $sectionid);
        rebuild_course_cache((int)$section->course, false, true);
        threads::reset_cache();
    }

    /**
     * Hold off, or resume, claiming section summaries in a course.
     *
     * For restore, which fires the same event while it is still sorting out a section's keys. See
     * restore_local_embeddiscussion_plugin::define_section_plugin_structure().
     *
     * @param int $courseid The course.
     * @param bool $hold True to hold off, false to resume.
     */
    public static function hold_claims(int $courseid, bool $hold): void {
        if ($hold) {
            self::$heldclaims[$courseid] = true;
        } else {
            unset(self::$heldclaims[$courseid]);
        }
    }

    /**
     * Copy a section's thread - its settings, not its posts - into another section of the same course.
     *
     * @param \stdClass $row The thread to copy.
     * @param int $sectionid The section the copy belongs to.
     * @return string The copy's key.
     */
    protected static function copy_to_section(\stdClass $row, int $sectionid): string {
        global $DB;

        $now = time();
        $copy = clone $row;
        unset($copy->id);
        $copy->sectionid = $sectionid;
        $copy->embedkey = token::new_key();
        $copy->handleoffset = random_int(0, max(count(handles::master_list()), 1) - 1);
        $copy->timecreated = $now;
        $copy->timemodified = $now;
        $copy->id = $DB->insert_record(threads::TABLE, $copy);

        event\thread_created::create_for_thread($copy, \context::instance_by_id((int)$copy->contextid))->trigger();

        return $copy->embedkey;
    }

    /**
     * Delete every thread belonging to a course module, with its posts.
     *
     * @param int $cmid The course module.
     */
    public static function delete_for_cm(int $cmid): void {
        global $DB;

        self::delete_threads($DB->get_records(threads::TABLE, ['cmid' => $cmid]));
    }

    /**
     * Delete a section's threads, or every section's threads in a course, with their posts.
     *
     * Needed when a section is deleted, and when a course's contents are (a restore that deletes the
     * existing content first, say): core deletes those sections without asking anyone.
     *
     * @param int $courseid The course.
     * @param int $sectionid The section, or 0 for every section in the course.
     */
    public static function delete_for_sections(int $courseid, int $sectionid = 0): void {
        global $DB;

        $select = 'courseid = :courseid AND cmid = 0 AND sectionid > 0';
        $params = ['courseid' => $courseid];
        if ($sectionid) {
            $select .= ' AND sectionid = :sectionid';
            $params['sectionid'] = $sectionid;
        }

        self::delete_threads($DB->get_records_select(threads::TABLE, $select, $params));
    }

    /**
     * Delete every thread in a course, drafts included, with their posts.
     *
     * Needed because deleting a course removes its modules directly, without the per-module
     * callbacks that delete_for_cm() hangs off.
     *
     * @param int $courseid The course.
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;

        self::delete_threads($DB->get_records(threads::TABLE, ['courseid' => $courseid]));
    }

    /**
     * Delete drafts whose activity was never saved.
     *
     * A draft never has posts: the filter shows only activities' and sections' threads.
     *
     * @param int $before Delete drafts last touched before this time.
     * @return int How many were deleted.
     */
    public static function purge_drafts(int $before): int {
        global $DB;

        $drafts = $DB->get_records_select(
            threads::TABLE,
            'cmid = 0 AND sectionid = 0 AND timemodified < :before',
            ['before' => $before]
        );
        self::delete_threads($drafts);

        return count($drafts);
    }

    /**
     * Delete threads, and the posts, reactions and anonymous handles that hang off them.
     *
     * @param \stdClass[] $threads Thread rows.
     */
    protected static function delete_threads(array $threads): void {
        if (!$threads) {
            return;
        }

        foreach ($threads as $thread) {
            self::delete_thread($thread);
        }
        threads::reset_cache();
    }

    /**
     * Delete a single thread and all data hanging off it, logging post_deleted for each post and
     * thread_deleted for the thread.
     *
     * Events are triggered while the rows still exist so observers can read the records being removed.
     *
     * @param \stdClass $thread A thread row.
     */
    public static function delete_thread(\stdClass $thread): void {
        global $DB;

        $context = \context::instance_by_id((int)$thread->contextid, IGNORE_MISSING);
        $posts = $DB->get_records('local_embeddiscussion_post', ['threadid' => $thread->id]);

        if ($context) {
            foreach ($posts as $post) {
                event\post_deleted::create_for_post($post, $thread, $context)->trigger();
            }
        }

        if ($posts) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($posts), SQL_PARAMS_NAMED, 'p');
            $DB->delete_records_select('local_embeddiscussion_reaction', "postid $insql", $params);
        }
        $DB->delete_records('local_embeddiscussion_handle', ['threadid' => $thread->id]);
        $DB->delete_records('local_embeddiscussion_post', ['threadid' => $thread->id]);

        if ($context) {
            event\thread_deleted::create_for_thread($thread, $context)->trigger();
        }

        $DB->delete_records(threads::TABLE, ['id' => $thread->id]);
    }
}
