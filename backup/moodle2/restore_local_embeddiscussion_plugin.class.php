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
 * Restore of embedded discussions, attached to every activity and every section.
 *
 * @package    local_embeddiscussion
 * @category   backup
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_embeddiscussion\api;
use local_embeddiscussion\threads;
use local_embeddiscussion\token;

/**
 * Restores an activity's threads from its module.xml, and a section's from its section.xml, with
 * their posts when the backup and the restore both include user data.
 *
 * Nothing here has files - post images are written into the post itself - so every mapping is made
 * as its element is processed.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class restore_local_embeddiscussion_plugin extends restore_local_plugin {
    /**
     * Paths at the module level.
     *
     * @return restore_path_element[]
     */
    protected function define_module_plugin_structure() {
        return $this->define_paths();
    }

    /**
     * Paths at the section level.
     *
     * Also holds off local_embeddiscussion\api::claim_section_summary() for the course until the
     * section is done. Merging into an existing section fires course_section_updated before this
     * plugin has seen the section's threads, and a claim then would copy whichever of the course's
     * threads already has the key, rather than the thread in the backup. This is the last moment
     * before that event; restore_section_thread() sorts the keys out.
     *
     * @return restore_path_element[]
     */
    protected function define_section_plugin_structure() {
        api::hold_claims($this->task->get_courseid(), true);

        return $this->define_paths();
    }

    /**
     * The paths for a thread and, with user data, everything hanging off it.
     *
     * A section's thread element has the same name as an activity's, because a post finds its
     * thread's new id by that name.
     *
     * @return restore_path_element[]
     */
    protected function define_paths(): array {
        $paths = [
            new restore_path_element($this->get_namefor('thread'), $this->get_pathfor('/threads/thread')),
        ];

        if ($this->get_setting_value('userinfo')) {
            $paths[] = new restore_path_element($this->get_namefor('post'), $this->get_pathfor('/threads/thread/posts/post'));
            $paths[] = new restore_path_element(
                $this->get_namefor('reaction'),
                $this->get_pathfor('/threads/thread/posts/post/reactions/reaction')
            );
            $paths[] = new restore_path_element($this->get_namefor('handle'), $this->get_pathfor('/threads/thread/handles/handle'));
        }

        return $paths;
    }

    /**
     * Restore one thread, against the activity or the section being restored.
     *
     * @param array|stdClass $data The thread as backed up.
     */
    public function process_local_embeddiscussion_thread($data) {
        $data = (object)$data;
        $oldid = $data->id;
        unset($data->id);

        $newid = basename($this->connectionpoint->get_path()) === 'section'
            ? $this->restore_section_thread($data)
            : $this->restore_module_thread($data);

        // Mapped even when skipped, to nothing: the step only remembers the latest thread mapped, and
        // a skipped thread's posts would otherwise go into the thread restored before it.
        $this->set_mapping('local_embeddiscussion_thread', $oldid, (int)$newid);
    }

    /**
     * Restore one thread against the new course module.
     *
     * @param stdClass $data The thread as backed up, without its id.
     * @return int|null The new thread's id, or null if it was skipped.
     */
    protected function restore_module_thread(stdClass $data): ?int {
        global $DB;

        $data->courseid = $this->task->get_courseid();
        $data->cmid = $this->task->get_moduleid();
        $data->sectionid = 0;
        $data->contextid = context_module::instance($data->cmid)->id;

        // A key already used in this module would break the unique index. It cannot happen with a
        // fresh module, but a restore merged into an existing one is not worth a fatal error: the
        // existing thread wins, and the token resolves to it. Its posts are not merged into it.
        if ($DB->record_exists(threads::TABLE, ['cmid' => $data->cmid, 'embedkey' => $data->embedkey])) {
            return null;
        }

        return $DB->insert_record(threads::TABLE, $data);
    }

    /**
     * Restore one thread against the new, or merged-into, section.
     *
     * The section's summary is already in place, so it decides:
     *
     * * a key the summary does not hold is skipped. That is a merge into an existing section that
     *   kept its own summary, where the thread would have no token to show it;
     * * a key already used by this section's own thread is skipped, and the token resolves to it;
     * * a key used anywhere else in the course - restoring a section back into the course it came
     *   from - would break the unique index and give two sections one key, which the filter cannot
     *   tell apart. The restored thread takes a new key, and the summary follows it.
     *
     * @param stdClass $data The thread as backed up, without its id.
     * @return int|null The new thread's id, or null if it was skipped.
     */
    protected function restore_section_thread(stdClass $data): ?int {
        global $DB;

        $data->courseid = $this->task->get_courseid();
        $data->cmid = 0;
        $data->sectionid = $this->task->get_sectionid();
        $data->contextid = context_course::instance($data->courseid)->id;

        $summary = (string)$DB->get_field('course_sections', 'summary', ['id' => $data->sectionid]);
        if (!in_array($data->embedkey, token::keys_in($summary), true)) {
            return null;
        }

        $taken = $DB->get_field(threads::TABLE, 'sectionid', [
            'courseid' => $data->courseid,
            'cmid' => 0,
            'embedkey' => $data->embedkey,
        ]);
        if ($taken !== false && (int)$taken === (int)$data->sectionid) {
            return null;
        }
        if ($taken !== false) {
            $newkey = token::new_key();
            $summary = token::rekey($summary, [$data->embedkey => $newkey]);
            $DB->set_field('course_sections', 'summary', $summary, ['id' => $data->sectionid]);
            $data->embedkey = $newkey;
        }

        return $DB->insert_record(threads::TABLE, $data);
    }

    /**
     * Restore one post into its restored thread.
     *
     * Posts arrive in id order, so a reply's parent is always mapped before it. A post whose author
     * is not in the restore is left out, and a reply to it becomes a post of its own.
     *
     * @param array|stdClass $data The post as backed up.
     */
    public function process_local_embeddiscussion_post($data) {
        global $DB;

        $data = (object)$data;
        $oldid = $data->id;
        unset($data->id);

        $data->threadid = $this->get_new_parentid('local_embeddiscussion_thread');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->threadid || !$data->userid) {
            // Mapped to nothing, as a skipped thread is, so that its reactions are skipped too.
            $this->set_mapping('local_embeddiscussion_post', $oldid, 0);
            return;
        }
        $data->parentid = $data->parentid ? (int)$this->get_mappingid('local_embeddiscussion_post', $data->parentid, 0) : 0;

        $this->set_mapping('local_embeddiscussion_post', $oldid, $DB->insert_record('local_embeddiscussion_post', $data));
    }

    /**
     * Restore one reaction onto its restored post.
     *
     * @param array|stdClass $data The reaction as backed up.
     */
    public function process_local_embeddiscussion_reaction($data) {
        global $DB;

        $data = (object)$data;
        unset($data->id);

        $data->postid = $this->get_new_parentid('local_embeddiscussion_post');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->postid || !$data->userid) {
            return;
        }

        $DB->insert_record('local_embeddiscussion_reaction', $data);
    }

    /**
     * Restore one anonymous handle into its restored thread, so that a student keeps their handle.
     *
     * @param array|stdClass $data The handle as backed up.
     */
    public function process_local_embeddiscussion_handle($data) {
        global $DB;

        $data = (object)$data;
        unset($data->id);

        $data->threadid = $this->get_new_parentid('local_embeddiscussion_thread');
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (!$data->threadid || !$data->userid) {
            return;
        }

        $DB->insert_record('local_embeddiscussion_handle', $data);
    }

    /**
     * The section is done: let claims through again.
     */
    public function after_execute_section() {
        api::hold_claims($this->task->get_courseid(), false);
    }

    /**
     * The activity is done: forget what was read for this request.
     */
    public function after_execute_module() {
        threads::reset_cache();
    }
}
