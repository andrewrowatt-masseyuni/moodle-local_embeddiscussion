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

namespace local_embeddiscussion\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Tests for the embedded discussion privacy provider.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embeddiscussion\privacy\provider
 */
final class provider_test extends provider_testcase {
    /** @var \stdClass The course. */
    protected $course;

    /** @var \context_module The page's context. */
    protected $context;

    /** @var \stdClass The student who posts and reacts. */
    protected $student;

    /** @var \stdClass The student who only reacts. */
    protected $reactor;

    /** @var \stdClass The thread. */
    protected $thread;

    /**
     * An anonymous discussion in a page: the student posts, and both students react.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $generator = $this->getDataGenerator()->get_plugin_generator('local_embeddiscussion');
        $this->course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        $this->context = \context_module::instance($page->cmid);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->reactor = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->thread = $generator->create_thread(['cmid' => $page->cmid, 'name' => 'Week 1', 'anonymous' => 1]);
        $post = $generator->create_post(['threadid' => $this->thread->id, 'userid' => $this->student->id,
            'content' => 'My thoughts']);
        $generator->create_reaction(['threadid' => $this->thread->id, 'userid' => $this->reactor->id,
            'postid' => $post->id, 'emoji' => 'heart']);
    }

    /**
     * A poster's and a reactor's data are both in the page's context, and nowhere else.
     */
    public function test_get_contexts_and_users(): void {
        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($this->student->id)->get_contextids());
        $this->assertEquals([$this->context->id], provider::get_contexts_for_userid($this->reactor->id)->get_contextids());
        $this->assertEmpty(provider::get_contexts_for_userid($this->getDataGenerator()->create_user()->id)->get_contextids());

        $userlist = new userlist($this->context, 'local_embeddiscussion');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing([$this->student->id, $this->reactor->id], $userlist->get_userids());
    }

    /**
     * A poster's posts are exported under the discussion's name.
     */
    public function test_export_user_data(): void {
        $this->export_context_data_for_user($this->student->id, $this->context, 'local_embeddiscussion');

        $data = writer::with_context($this->context)->get_data([get_string('pluginname', 'local_embeddiscussion'), 'Week 1']);
        $this->assertCount(1, $data->posts);
        $this->assertStringContainsString('My thoughts', $data->posts[0]['content']);
    }

    /**
     * Deleting a user's data blanks their posts, and removes their reactions and handles, leaving
     * everyone else's.
     */
    public function test_delete_data_for_user(): void {
        global $DB;

        provider::delete_data_for_user(new approved_contextlist($this->student, 'local_embeddiscussion', [$this->context->id]));

        $post = $DB->get_record('local_embeddiscussion_post', ['threadid' => $this->thread->id], '*', MUST_EXIST);
        $this->assertSame('', $post->content);
        $this->assertSame(1, (int)$post->deleted);
        $this->assertFalse($DB->record_exists('local_embeddiscussion_handle', ['userid' => $this->student->id]));
        $this->assertTrue($DB->record_exists('local_embeddiscussion_reaction', ['userid' => $this->reactor->id]));

        provider::delete_data_for_users(new approved_userlist($this->context, 'local_embeddiscussion', [$this->reactor->id]));
        $this->assertFalse($DB->record_exists('local_embeddiscussion_reaction', ['userid' => $this->reactor->id]));
    }

    /**
     * Deleting everyone's data in a context empties its discussions, and keeps them: they are the
     * course's content, and name nobody.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;

        provider::delete_data_for_all_users_in_context($this->context);

        $this->assertTrue($DB->record_exists('local_embeddiscussion_thread', ['id' => $this->thread->id]));
        $this->assertFalse($DB->record_exists('local_embeddiscussion_handle', ['threadid' => $this->thread->id]));
        $this->assertFalse($DB->record_exists('local_embeddiscussion_post', ['threadid' => $this->thread->id]));
        $this->assertFalse($DB->record_exists('local_embeddiscussion_reaction', ['userid' => $this->reactor->id]));
    }
}
