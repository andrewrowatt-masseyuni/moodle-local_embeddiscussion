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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Tests for discussions riding along with activity and section backup and restore.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \backup_local_embeddiscussion_plugin
 * @covers     \restore_local_embeddiscussion_plugin
 */
final class backup_test extends \advanced_testcase {
    /**
     * Start each test with nothing cached.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        threads::reset_cache();
    }

    /**
     * The generator.
     *
     * @return \local_embeddiscussion_generator
     */
    protected function generator(): \local_embeddiscussion_generator {
        return $this->getDataGenerator()->get_plugin_generator('local_embeddiscussion');
    }

    /**
     * Back a course up and restore it into a new course, or add it to an existing one.
     *
     * @param int $courseid The course.
     * @param int $targetid A course to add it to, or 0 for a new course.
     * @param bool $users Whether to include user data.
     * @return int The course restored into.
     */
    private function backup_and_restore(int $courseid, int $targetid = 0, bool $users = true): int {
        global $CFG, $USER;

        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $backup = new \backup_controller(
            \backup::TYPE_1COURSE,
            $courseid,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id
        );
        $backup->get_plan()->get_setting('users')->set_value($users);
        $backup->execute_plan();
        $results = $backup->get_results();
        $backupid = $backup->get_backupid();
        $backup->destroy();

        $path = make_backup_temp_directory($backupid);
        $results['backup_destination']->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);

        if ($targetid) {
            $newcourseid = $targetid;
        } else {
            $source = get_course($courseid);
            $newcourseid = \restore_dbops::create_new_course($source->fullname, $source->shortname . '_copy', $source->category);
        }

        $restore = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            $targetid ? \backup::TARGET_EXISTING_ADDING : \backup::TARGET_NEW_COURSE
        );
        $restore->execute_precheck();
        $restore->execute_plan();
        $restore->destroy();

        return $newcourseid;
    }

    /**
     * A course with a page holding an anonymous discussion, with a post, a reply to it, a reaction and
     * two students' handles.
     *
     * @return array [course, page, thread, [student 1, student 2]]
     */
    private function make_page(): array {
        $course = $this->getDataGenerator()->create_course();
        $students = [
            $this->getDataGenerator()->create_and_enrol($course, 'student'),
            $this->getDataGenerator()->create_and_enrol($course, 'student'),
        ];
        $key = token::new_key();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>Read this.</p>' . token::html($key),
        ]);
        $thread = $this->generator()->create_thread([
            'cmid' => $page->cmid,
            'embedkey' => $key,
            'anonymous' => 1,
            'handleoffset' => 3,
        ]);
        $post = $this->generator()->create_post(['threadid' => $thread->id, 'userid' => $students[0]->id,
            'content' => 'First thoughts']);
        $this->generator()->create_post(['threadid' => $thread->id, 'userid' => $students[1]->id,
            'content' => 'A reply', 'parentid' => $post->id]);
        $this->generator()->create_reaction(['threadid' => $thread->id, 'userid' => $students[1]->id,
            'postid' => $post->id, 'emoji' => 'heart']);

        return [$course, $page, $thread, $students];
    }

    /**
     * A course restored with its users brings its discussions with it: settings, posts with their
     * replies, reactions and anonymous handles.
     */
    public function test_discussions_survive_a_course_restore_with_users(): void {
        global $DB;

        [$course, , $thread, $students] = $this->make_page();

        $newcourseid = $this->backup_and_restore((int)$course->id);

        $newcm = array_values(get_fast_modinfo($newcourseid)->get_instances_of('page'))[0];
        $restored = $DB->get_record(threads::TABLE, ['cmid' => $newcm->id], '*', MUST_EXIST);
        $this->assertSame($thread->embedkey, $restored->embedkey);
        $this->assertSame(1, (int)$restored->anonymous);
        $this->assertSame(3, (int)$restored->handleoffset);
        $this->assertSame((int)$newcourseid, (int)$restored->courseid);
        $this->assertSame((int)\context_module::instance($newcm->id)->id, (int)$restored->contextid);
        $this->assertArrayHasKey($thread->embedkey, threads::for_cm($newcourseid, $newcm->id));

        $posts = array_values($DB->get_records('local_embeddiscussion_post', ['threadid' => $restored->id], 'id'));
        $this->assertCount(2, $posts);
        $this->assertSame('First thoughts', strip_tags($posts[0]->content));
        $this->assertSame((int)$students[0]->id, (int)$posts[0]->userid);
        $this->assertSame(0, (int)$posts[0]->parentid);
        $this->assertSame((int)$posts[0]->id, (int)$posts[1]->parentid);

        $reaction = $DB->get_record('local_embeddiscussion_reaction', ['postid' => $posts[0]->id], '*', MUST_EXIST);
        $this->assertSame((int)$students[1]->id, (int)$reaction->userid);
        $this->assertSame('heart', $reaction->emoji);

        $handles = $DB->get_records_menu('local_embeddiscussion_handle', ['threadid' => $restored->id], '', 'userid, handleindex');
        $this->assertEquals([$students[0]->id => 0, $students[1]->id => 1], $handles);

        // The original is untouched.
        $this->assertSame(2, $DB->count_records('local_embeddiscussion_post', ['threadid' => $thread->id]));
    }

    /**
     * Without users, a restored discussion is the discussion's settings alone, ready to start again.
     */
    public function test_discussions_survive_a_course_restore_without_users(): void {
        global $DB;

        [$course, , $thread] = $this->make_page();

        $newcourseid = $this->backup_and_restore((int)$course->id, 0, false);

        $newcm = array_values(get_fast_modinfo($newcourseid)->get_instances_of('page'))[0];
        $restored = $DB->get_record(threads::TABLE, ['cmid' => $newcm->id], '*', MUST_EXIST);
        $this->assertSame($thread->embedkey, $restored->embedkey);
        $this->assertFalse($DB->record_exists('local_embeddiscussion_post', ['threadid' => $restored->id]));
        $this->assertFalse($DB->record_exists('local_embeddiscussion_handle', ['threadid' => $restored->id]));
    }

    /**
     * Duplicating an activity copies its discussion's settings - not its posts, which are user data -
     * under the same key, which the copied text carries.
     */
    public function test_duplicating_an_activity(): void {
        global $DB;

        [$course, $page, $thread] = $this->make_page();

        $newcm = duplicate_module($course, get_fast_modinfo($course->id)->get_cm($page->cmid));

        $copy = $DB->get_record(threads::TABLE, ['cmid' => $newcm->id], '*', MUST_EXIST);
        $this->assertSame($thread->embedkey, $copy->embedkey);
        $this->assertFalse($DB->record_exists('local_embeddiscussion_post', ['threadid' => $copy->id]));
        $this->assertSame([$thread->embedkey], token::keys_in($DB->get_field('page', 'content', ['id' => $newcm->instance])));
    }

    /**
     * A section's discussion restores with its section, posts and all.
     */
    public function test_section_discussions_survive_a_course_restore(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $key = token::new_key();
        $thread = $this->generator()->create_section_thread([
            'courseid' => $course->id,
            'section' => 1,
            'embedkey' => $key,
            'summary' => token::html($key),
        ]);
        $this->generator()->create_post(['threadid' => $thread->id, 'userid' => $student->id, 'content' => 'Hello']);

        $newcourseid = $this->backup_and_restore((int)$course->id);

        $section = get_fast_modinfo($newcourseid)->get_section_info(1);
        $this->assertSame([$key], token::keys_in($section->summary));
        $restored = $DB->get_record(threads::TABLE, ['courseid' => $newcourseid, 'embedkey' => $key], '*', MUST_EXIST);
        $this->assertSame((int)$section->id, (int)$restored->sectionid);
        $this->assertSame(0, (int)$restored->cmid);
        $this->assertSame((int)\context_course::instance($newcourseid)->id, (int)$restored->contextid);
        $this->assertSame(1, $DB->count_records('local_embeddiscussion_post', ['threadid' => $restored->id]));
    }

    /**
     * Restoring a section into a course where another section already uses its key gives the
     * restored discussion a new key, and its summary follows. A section that keeps its own summary
     * gets nothing, and a skipped discussion's posts go nowhere - not into the discussion restored
     * before it.
     */
    public function test_section_discussions_merged_into_a_course(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $one = $this->generator()->create_section_thread(['courseid' => $course->id, 'section' => 1]);
        $two = $this->generator()->create_section_thread(['courseid' => $course->id, 'section' => 2]);
        $modinfo = get_fast_modinfo($course);
        course_update_section($course, $modinfo->get_section_info(1), ['summary' => token::html($one->embedkey)]);
        course_update_section($course, $modinfo->get_section_info(2), ['summary' => token::html($two->embedkey)]);
        $this->generator()->create_post(['threadid' => $one->id, 'userid' => $student->id, 'content' => 'In one']);
        $this->generator()->create_post(['threadid' => $two->id, 'userid' => $student->id, 'content' => 'In two']);

        // The target: section 1 empty, so it takes the backup's summary; section 2 keeps its own;
        // section 3 already holds a thread with the key the backup's section 1 uses.
        $target = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $targetinfo = get_fast_modinfo($target);
        course_update_section($target, $targetinfo->get_section_info(2), ['summary' => '<p>Our own week two.</p>']);
        $theirs = $this->generator()->create_thread([
            'sectionid' => $targetinfo->get_section_info(3)->id,
            'embedkey' => $one->embedkey,
        ]);
        course_update_section($target, $targetinfo->get_section_info(3), ['summary' => token::html($one->embedkey)]);

        $this->backup_and_restore((int)$course->id, (int)$target->id);

        $targetinfo = get_fast_modinfo($target->id);
        $keys = token::keys_in($targetinfo->get_section_info(1)->summary);
        $this->assertCount(1, $keys);
        $this->assertNotSame($one->embedkey, $keys[0]);
        $restored = $DB->get_record(threads::TABLE, ['courseid' => $target->id, 'embedkey' => $keys[0]], '*', MUST_EXIST);
        $this->assertSame((int)$targetinfo->get_section_info(1)->id, (int)$restored->sectionid);
        $contents = $DB->get_fieldset_select('local_embeddiscussion_post', 'content', 'threadid = ?', [$restored->id]);
        $this->assertSame(['In one'], array_map('strip_tags', $contents));

        // Section 3's thread is untouched, and section 2's discussion was not restored, posts and all.
        $this->assertSame([$one->embedkey], token::keys_in($targetinfo->get_section_info(3)->summary));
        $this->assertFalse($DB->record_exists('local_embeddiscussion_post', ['threadid' => $theirs->id]));
        $this->assertFalse($DB->record_exists(threads::TABLE, ['courseid' => $target->id, 'embedkey' => $two->embedkey]));
        $select = 'threadid IN (SELECT id FROM {local_embeddiscussion_thread} WHERE courseid = :courseid)';
        $this->assertSame(1, $DB->count_records_select('local_embeddiscussion_post', $select, ['courseid' => $target->id]));
    }
}
