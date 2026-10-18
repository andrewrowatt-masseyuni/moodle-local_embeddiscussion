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
 * Backup of embedded discussions, attached to every activity and every section.
 *
 * @package    local_embeddiscussion
 * @category   backup
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds an activity's threads to its module.xml, and a section's to its section.xml.
 *
 * This is why the discussions live in a local plugin rather than in the filter: core only lets
 * format, report, plagiarism, local and tool plugins add data to another module's backup. Riding
 * along here covers course backup and restore, import, course copy, duplicating an activity and the
 * recycle bin, with nothing else to wire up. Duplicating a section is not a backup; see
 * api::claim_section_summary().
 *
 * A thread's settings are always backed up, so that the token in the restored text has a thread to
 * show. Its posts, reactions and anonymous handles are user data, backed up only with user data.
 * embedkey is carried verbatim: it is what the token in the restored text matches on.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class backup_local_embeddiscussion_plugin extends backup_local_plugin {
    /**
     * Threads at the module level.
     *
     * @return backup_plugin_element
     */
    protected function define_module_plugin_structure() {
        return $this->define_threads(['cmid' => backup::VAR_MODID]);
    }

    /**
     * Threads at the section level.
     *
     * @return backup_plugin_element
     */
    protected function define_section_plugin_structure() {
        return $this->define_threads(['courseid' => backup::VAR_COURSEID, 'sectionid' => backup::VAR_SECTIONID]);
    }

    /**
     * The threads matching a source, with their posts when user data is included.
     *
     * @param array $source The thread table's conditions.
     * @return backup_plugin_element
     */
    protected function define_threads(array $source) {
        $plugin = $this->get_plugin_element();
        $userinfo = $this->get_setting_value('userinfo');

        $wrapper = new backup_nested_element($this->get_recommended_name());
        $threads = new backup_nested_element('threads');
        $thread = new backup_nested_element('thread', ['id'], [
            'embedkey',
            'anonymous',
            'handleoffset',
            'timecreated',
            'timemodified',
        ]);
        $posts = new backup_nested_element('posts');
        $post = new backup_nested_element('post', ['id'], [
            'parentid',
            'userid',
            'content',
            'edited',
            'deleted',
            'timecreated',
            'timemodified',
        ]);
        $reactions = new backup_nested_element('reactions');
        $reaction = new backup_nested_element('reaction', ['id'], [
            'userid',
            'emoji',
            'timecreated',
        ]);
        $handles = new backup_nested_element('handles');
        $handle = new backup_nested_element('handle', ['id'], [
            'userid',
            'handleindex',
            'timecreated',
        ]);

        $plugin->add_child($wrapper);
        $wrapper->add_child($threads);
        $threads->add_child($thread);
        $thread->add_child($posts);
        $posts->add_child($post);
        $post->add_child($reactions);
        $reactions->add_child($reaction);
        $thread->add_child($handles);
        $handles->add_child($handle);

        $thread->set_source_table('local_embeddiscussion_thread', $source);

        if ($userinfo) {
            // By id, so that a reply always follows the post it replies to, and restore can map it.
            $post->set_source_table('local_embeddiscussion_post', ['threadid' => backup::VAR_PARENTID], 'id ASC');
            $reaction->set_source_table('local_embeddiscussion_reaction', ['postid' => backup::VAR_PARENTID]);
            $handle->set_source_table('local_embeddiscussion_handle', ['threadid' => backup::VAR_PARENTID]);
        }

        $post->annotate_ids('user', 'userid');
        $reaction->annotate_ids('user', 'userid');
        $handle->annotate_ids('user', 'userid');

        return $plugin;
    }
}
