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
 * Tests for making, adopting and deleting embedded discussion threads.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embeddiscussion\api
 */
final class api_test extends \advanced_testcase {
    /**
     * Reset the per-request thread cache, which is keyed on ids PHPUnit reuses.
     */
    protected function setUp(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');

        parent::setUp();
        $this->resetAfterTest();
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
     * Give a thread a post, a reply, a reaction and an anonymous handle.
     *
     * @param \stdClass $thread The thread.
     * @param \stdClass $user The user who writes them.
     */
    protected function add_activity(\stdClass $thread, \stdClass $user): void {
        $post = $this->generator()->create_post(['threadid' => $thread->id, 'userid' => $user->id, 'content' => 'Hello']);
        $this->generator()->create_post([
            'threadid' => $thread->id,
            'userid' => $user->id,
            'content' => 'Reply',
            'parentid' => $post->id,
        ]);
        $this->generator()->create_reaction(['threadid' => $thread->id, 'userid' => $user->id, 'postid' => $post->id,
            'emoji' => 'heart']);
        handles::get_or_assign($thread, (int)$user->id);
    }

    /**
     * Assert that a thread, and everything that hangs off it, has gone - or has not.
     *
     * @param \stdClass $thread The thread.
     * @param bool $gone Whether it should have gone.
     */
    protected function assert_thread_gone(\stdClass $thread, bool $gone = true): void {
        global $DB;

        $this->assertSame(!$gone, $DB->record_exists(threads::TABLE, ['id' => $thread->id]));
        $this->assertSame(!$gone, $DB->record_exists('local_embeddiscussion_post', ['threadid' => $thread->id]));
        $this->assertSame(!$gone, $DB->record_exists('local_embeddiscussion_handle', ['threadid' => $thread->id]));
        $reactions = $DB->count_records_sql(
            'SELECT COUNT(1) FROM {local_embeddiscussion_reaction} r
               JOIN {local_embeddiscussion_post} p ON p.id = r.postid
              WHERE p.threadid = :threadid',
            ['threadid' => $thread->id]
        );
        if ($gone) {
            $this->assertSame(0, $reactions);
        }
    }

    /**
     * An activity's editor makes the activity's threads; a course's makes a section's, or a draft.
     */
    public function test_embed_target(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $section = get_fast_modinfo($course)->get_section_info(1);
        $coursecontext = \context_course::instance($course->id);

        $this->assertSame([(int)$course->id, (int)$page->cmid, 0], api::embed_target(\context_module::instance($page->cmid)));
        $this->assertSame([(int)$course->id, 0, (int)$section->id], api::embed_target($coursecontext, $section->id));
        $this->assertSame([(int)$course->id, 0, 0], api::embed_target($coursecontext));

        // A section that is not in the course is no section at all.
        try {
            api::embed_target($coursecontext, get_fast_modinfo($other)->get_section_info(1)->id);
            $this->fail('A section from another course was accepted.');
        } catch (\invalid_parameter_exception $e) {
            $this->assertStringContainsString('not in this course', $e->getMessage());
        }

        // Nowhere else: the front page, the site, a user.
        $elsewhere = [\context_course::instance(SITEID), \context_system::instance(), \context_user::instance(get_admin()->id)];
        foreach ($elsewhere as $ctx) {
            try {
                api::embed_target($ctx);
                $this->fail('A thread was allowed in ' . $ctx->get_context_name());
            } catch (\invalid_parameter_exception $e) {
                $this->assertStringContainsString('can only be embedded', $e->getMessage());
            }
        }
    }

    /**
     * Saving a new thread makes it where the editor is, with a fresh key, and logs it.
     */
    public function test_save_embed_creates(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);

        $sink = $this->redirectEvents();
        $key = api::save_embed($context, null, true);
        $events = $sink->get_events();
        $sink->close();

        $row = api::get_embed($context, $key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $key);
        $this->assertSame(1, (int)$row->anonymous);
        $this->assertSame((int)$page->cmid, (int)$row->cmid);
        $this->assertSame((int)$context->id, (int)$row->contextid);
        $this->assertSame(0, (int)$row->sectionid);

        $this->assertCount(1, $events);
        $this->assertInstanceOf(event\thread_created::class, $events[0]);
        $this->assertSame((int)$row->id, (int)$events[0]->objectid);
        $this->assertSame($context, $events[0]->get_context());
    }

    /**
     * Saving an existing thread changes it in place, under the same key, and logs that.
     */
    public function test_save_embed_updates(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $thread = $this->generator()->create_thread(['cmid' => $page->cmid]);

        $sink = $this->redirectEvents();
        $key = api::save_embed($context, $thread->embedkey, true);
        $events = $sink->get_events();
        $sink->close();

        $this->assertSame($thread->embedkey, $key);
        $row = api::get_embed($context, $key);
        $this->assertSame((int)$thread->id, (int)$row->id);
        $this->assertSame(1, (int)$row->anonymous);
        $this->assertCount(1, $events);
        $this->assertInstanceOf(event\thread_updated::class, $events[0]);
    }

    /**
     * A key from another activity is not this activity's thread: saving it makes a new one, and
     * leaves the other alone.
     */
    public function test_save_embed_with_a_key_from_elsewhere(): void {
        $course = $this->getDataGenerator()->create_course();
        $page1 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $page2 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $theirs = $this->generator()->create_thread(['cmid' => $page1->cmid, 'anonymous' => 1]);
        $context = \context_module::instance($page2->cmid);

        $this->assertNull(api::get_embed($context, $theirs->embedkey));

        $key = api::save_embed($context, $theirs->embedkey, false);

        $this->assertNotSame($theirs->embedkey, $key);
        $this->assertSame(0, (int)api::get_embed($context, $key)->anonymous);
        $this->assertSame(1, (int)api::get_embed(\context_module::instance($page1->cmid), $theirs->embedkey)->anonymous);
    }

    /**
     * A draft made while an activity was being added is adopted by the activity whose text holds its
     * token - any of the activity's text, not only its description. A section's thread with the same
     * key is not carried off, and nor is a draft whose token is not there.
     */
    public function test_adopt_module(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $coursecontext = \context_course::instance($course->id);
        $draft = $this->generator()->create_thread(['courseid' => $course->id]);
        $other = $this->generator()->create_thread(['courseid' => $course->id]);
        $section = $this->generator()->create_thread([
            'sectionid' => get_fast_modinfo($course)->get_section_info(1)->id,
        ]);
        $this->assertSame((int)$coursecontext->id, (int)$draft->contextid);

        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>Read this.</p>' . token::html($draft->embedkey) . token::html($section->embedkey),
        ]);
        api::adopt_module($page->cmid);

        $adopted = $DB->get_record(threads::TABLE, ['id' => $draft->id]);
        $this->assertSame((int)$page->cmid, (int)$adopted->cmid);
        $this->assertSame((int)\context_module::instance($page->cmid)->id, (int)$adopted->contextid);
        $this->assertSame(0, (int)$DB->get_field(threads::TABLE, 'cmid', ['id' => $section->id]));
        $this->assertSame(0, (int)$DB->get_field(threads::TABLE, 'cmid', ['id' => $other->id]));
        $this->assertArrayHasKey($draft->embedkey, threads::for_cm($course->id, $page->cmid));
    }

    /**
     * Saving an activity through its settings form adopts its drafts.
     */
    public function test_adopt_on_saving_the_activity(): void {
        global $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $draft = $this->generator()->create_thread(['courseid' => $course->id]);

        // The generator saves through add_moduleinfo(), as the form does.
        $label = $this->getDataGenerator()->create_module('label', [
            'course' => $course->id,
            'intro' => token::html($draft->embedkey),
        ]);

        $this->assertSame((int)$label->cmid, (int)$DB->get_field(threads::TABLE, 'cmid', ['id' => $draft->id]));
    }

    /**
     * Duplicating a section gives the copy a thread of its own - same anonymity, no posts -
     * and its summary the new key.
     */
    public function test_duplicating_a_section(): void {
        global $DB;

        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 1]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $original = $this->generator()->create_section_thread([
            'courseid' => $course->id,
            'section' => 1,
            'anonymous' => 1,
        ]);
        $sectioninfo = get_fast_modinfo($course)->get_section_info(1);
        course_update_section($course, $sectioninfo, ['summary' => '<p>Talk.</p>' . token::html($original->embedkey)]);
        $this->add_activity($original, $user);

        $copy = course_get_format($course)->duplicate_section(get_fast_modinfo($course)->get_section_info(1));

        $summary = $DB->get_field('course_sections', 'summary', ['id' => $copy->id]);
        $keys = token::keys_in($summary);
        $this->assertCount(1, $keys);
        $this->assertNotSame($original->embedkey, $keys[0]);
        $this->assertStringContainsString('<p>Talk.</p>', $summary);

        $claimed = $DB->get_record(threads::TABLE, ['embedkey' => $keys[0]]);
        $this->assertSame((int)$copy->id, (int)$claimed->sectionid);
        $this->assertSame(1, (int)$claimed->anonymous);
        $this->assertFalse($DB->record_exists('local_embeddiscussion_post', ['threadid' => $claimed->id]));

        // The original keeps its own thread, posts and all.
        $this->assert_thread_gone($original, false);
        $this->assertArrayHasKey($original->embedkey, threads::for_sections($course->id));
    }

    /**
     * Deleting an activity deletes its threads and their posts, reactions and handles - and logs it.
     */
    public function test_deleting_an_activity(): void {
        $this->setAdminUser();
        // Without the recycle bin's backup, which is no part of this.
        set_config('coursebinenable', 0, 'tool_recyclebin');
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $page1 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $page2 = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $doomed = $this->generator()->create_thread(['cmid' => $page1->cmid]);
        $kept = $this->generator()->create_thread(['cmid' => $page2->cmid]);
        $this->add_activity($doomed, $user);
        $this->add_activity($kept, $user);

        $sink = $this->redirectEvents();
        course_delete_module($page1->cmid);
        $events = $sink->get_events();
        $sink->close();

        $this->assert_thread_gone($doomed);
        $this->assert_thread_gone($kept, false);

        $classes = array_map(fn($event) => get_class($event), $events);
        $this->assertCount(2, array_keys($classes, event\post_deleted::class));
        $this->assertContains(event\thread_deleted::class, $classes);
    }

    /**
     * Deleting a section deletes its threads, and no other section's.
     */
    public function test_deleting_a_section(): void {
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $user = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $doomed = $this->generator()->create_section_thread(['courseid' => $course->id, 'section' => 1]);
        $kept = $this->generator()->create_section_thread(['courseid' => $course->id, 'section' => 2]);
        $this->add_activity($doomed, $user);
        $this->add_activity($kept, $user);

        course_delete_section($course, 1);

        $this->assert_thread_gone($doomed);
        $this->assert_thread_gone($kept, false);
    }

    /**
     * Deleting a course deletes every thread in it - activities', sections' and drafts - and no
     * other course's.
     */
    public function test_deleting_a_course(): void {
        $course = $this->getDataGenerator()->create_course();
        $other = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $threads = [
            $this->generator()->create_thread(['cmid' => $page->cmid]),
            $this->generator()->create_section_thread(['courseid' => $course->id, 'section' => 1]),
            $this->generator()->create_thread(['courseid' => $course->id]),
        ];
        $kept = $this->generator()->create_thread(['courseid' => $other->id]);
        foreach ($threads as $thread) {
            $this->add_activity($thread, $user);
        }
        $this->add_activity($kept, $user);

        delete_course($course, false);

        foreach ($threads as $thread) {
            $this->assert_thread_gone($thread);
        }
        $this->assert_thread_gone($kept, false);
    }

    /**
     * Deleting a course's contents - as a restore over it does - deletes its activities' threads
     * too, though core skips the per-activity callbacks: their posts must not outlive their contexts.
     */
    public function test_deleting_a_courses_contents(): void {
        $course = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $activity = $this->generator()->create_thread(['cmid' => $page->cmid]);
        $section = $this->generator()->create_section_thread(['courseid' => $course->id, 'section' => 1]);
        $this->add_activity($activity, $user);
        $this->add_activity($section, $user);

        remove_course_contents($course->id, false);

        $this->assert_thread_gone($activity);
        $this->assert_thread_gone($section);
    }

    /**
     * Only drafts are purged, and only once they are old enough: a section's thread also has no
     * activity, and must never go with them.
     */
    public function test_purge_drafts(): void {
        $course = $this->getDataGenerator()->create_course();
        $old = time() - api::DRAFT_LIFETIME - 10;
        $stale = $this->generator()->create_thread(['courseid' => $course->id, 'timemodified' => $old]);
        $fresh = $this->generator()->create_thread(['courseid' => $course->id]);
        $section = $this->generator()->create_section_thread(['courseid' => $course->id, 'section' => 1,
            'timemodified' => $old]);

        $this->assertSame(1, api::purge_drafts(time() - api::DRAFT_LIFETIME));

        $this->assert_thread_gone($stale);
        $this->assertNotEmpty(api::get_embed(\context_course::instance($course->id), $fresh->embedkey));
        $this->assertArrayHasKey($section->embedkey, threads::for_sections($course->id));
    }
}
