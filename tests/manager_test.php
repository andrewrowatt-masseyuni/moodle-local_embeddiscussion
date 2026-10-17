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
 * Tests for the manager.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_embeddiscussion\manager
 */
final class manager_test extends \advanced_testcase {
    /**
     * A thread in a new page, in a new course.
     *
     * @param array $thread Thread fields.
     * @return array [course, page's context, thread]
     */
    protected function page_thread(array $thread = []): array {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $thread = $this->getDataGenerator()->get_plugin_generator('local_embeddiscussion')
            ->create_thread(['cmid' => $page->cmid] + $thread);

        return [$course, \context_module::instance($page->cmid), $thread];
    }

    public function test_create_post_and_view(): void {
        $this->resetAfterTest();
        [$course, $context, $thread] = $this->page_thread();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($student);
        $post = manager::create_post(
            $thread,
            $context,
            0,
            '<p>Lorem ipsum <b>dolor</b></p><script>alert(1)</script>',
            $student->id
        );

        // Sanitisation strips the script.
        $this->assertStringNotContainsString('<script', $post->content);
        $this->assertStringContainsString('<b>dolor</b>', $post->content);

        $this->setUser($teacher);
        $view = manager::get_thread_view($thread, $context);
        $this->assertEquals(1, $view['postcount']);
        $this->assertEquals($post->id, $view['posts'][0]['id']);
        $this->assertTrue($view['canpost']);
    }

    public function test_create_and_edit_post_when_thread_allows_writes(): void {
        $this->resetAfterTest();
        [$course, $context, $thread] = $this->page_thread();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($student);
        $post = manager::create_post($thread, $context, 0, 'Hello', $student->id);
        $edited = manager::edit_post($post->id, $thread, $context, 'Hello edited', $student->id);
        $this->assertSame('Hello edited', strip_tags($edited->content));
    }

    public function test_reactions_counts_and_toggle(): void {
        $this->resetAfterTest();
        [$course, $context, $thread] = $this->page_thread();
        $author = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $reactor = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($author);
        $post = manager::create_post($thread, $context, 0, 'React to me', $author->id);

        $this->setUser($reactor);

        // Adding a reaction is reflected in the counts and the user's own reactions.
        $a = manager::toggle_reaction($post->id, $reactor->id, 'thumbsup');
        $this->assertSame('added', $a['action']);
        $summary = manager::get_reactions([$post->id], $reactor->id)[$post->id];
        $this->assertSame(['thumbsup' => 1], $summary['counts']);
        $this->assertSame(['thumbsup'], $summary['userreactions']);

        // A user may stack a second, different emoji on the same post.
        manager::toggle_reaction($post->id, $reactor->id, 'heart');
        $summary = manager::get_reactions([$post->id], $reactor->id)[$post->id];
        $this->assertSame(1, $summary['counts']['thumbsup']);
        $this->assertSame(1, $summary['counts']['heart']);
        $this->assertEqualsCanonicalizing(['thumbsup', 'heart'], $summary['userreactions']);

        // Toggling the same emoji again removes just that one.
        $b = manager::toggle_reaction($post->id, $reactor->id, 'thumbsup');
        $this->assertSame('removed', $b['action']);
        $summary = manager::get_reactions([$post->id], $reactor->id)[$post->id];
        $this->assertArrayNotHasKey('thumbsup', $summary['counts']);
        $this->assertSame(['heart'], $summary['userreactions']);

        // Reactions from a different user aggregate into the per-emoji counts.
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');
        manager::toggle_reaction($post->id, $other->id, 'heart');
        $summary = manager::get_reactions([$post->id], $reactor->id)[$post->id];
        $this->assertSame(2, $summary['counts']['heart']);
        $this->assertSame(['heart'], $summary['userreactions']);

        // An emoji outside the configured set is rejected.
        $this->expectException(\invalid_parameter_exception::class);
        manager::toggle_reaction($post->id, $reactor->id, 'notanemoji');
    }

    public function test_anonymous_handles_assigned_in_order(): void {
        $this->resetAfterTest();
        [$course, $context, $thread] = $this->page_thread(['anonymous' => 1]);
        $alice = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $bob = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // First poster -> handleindex 0.
        $this->setUser($alice);
        manager::create_post($thread, $context, 0, 'I am Alice', $alice->id);

        // Second poster -> handleindex 1.
        $this->setUser($bob);
        manager::create_post($thread, $context, 0, 'I am Bob', $bob->id);

        [$alicelabel, $aliceindex] = handles::get_or_assign($thread, (int)$alice->id);
        [$boblabel, $bobindex] = handles::get_or_assign($thread, (int)$bob->id);

        $this->assertSame(0, $aliceindex);
        $this->assertSame(1, $bobindex);
        $this->assertNotSame($alicelabel, $boblabel);
        $this->assertMatchesRegularExpression('/^[A-Z][a-z]+ [A-Z][a-z]+$/', $alicelabel);
    }

    public function test_get_dashboard_view_returns_latest_posts_and_unread_markers(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $coursecontext = \context_course::instance($course->id);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $generator = $this->getDataGenerator()->get_plugin_generator('local_embeddiscussion');
        $visiblelabel = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'visible' => 1]);
        $hiddenlabel = $this->getDataGenerator()->create_module('label', ['course' => $course->id, 'visible' => 0]);
        $visiblecontext = \context_module::instance($visiblelabel->cmid);
        $hiddencontext = \context_module::instance($hiddenlabel->cmid);
        $visiblethread = $generator->create_thread(['cmid' => $visiblelabel->cmid, 'name' => 'Visible label']);
        $hiddenthread = $generator->create_thread(['cmid' => $hiddenlabel->cmid, 'name' => 'Hidden label']);

        $modinfo = get_fast_modinfo($course);
        $sectionthread = $generator->create_thread([
            'sectionid' => $modinfo->get_section_info(1)->id,
            'name' => 'Section summary',
        ]);
        set_section_visible($course->id, 2, 0);
        $hiddensectionthread = $generator->create_thread([
            'sectionid' => $modinfo->get_section_info(2)->id,
            'name' => 'Hidden section summary',
        ]);
        // A draft is on no page, so it is no part of the dashboard, posts or not.
        $draft = $generator->create_thread(['courseid' => $course->id, 'name' => 'Draft']);

        $this->setUser($teacher);
        $oldpost = manager::create_post($visiblethread, $visiblecontext, 0, 'Old visible post', $teacher->id);
        $newpost = manager::create_post($visiblethread, $visiblecontext, 0, 'New visible post', $teacher->id);
        $sectionpost = manager::create_post($sectionthread, $coursecontext, 0, 'Section summary post', $teacher->id);
        manager::create_post($hiddenthread, $hiddencontext, 0, 'Hidden post', $teacher->id);
        manager::create_post($hiddensectionthread, $coursecontext, 0, 'Hidden section post', $teacher->id);
        manager::create_post($draft, $coursecontext, 0, 'Draft post', $teacher->id);

        $now = time();
        $lastaccess = $now - 100;
        $DB->set_field('local_embeddiscussion_post', 'timecreated', $lastaccess - 50, ['id' => $oldpost->id]);
        $DB->set_field('local_embeddiscussion_post', 'timecreated', $lastaccess + 50, ['id' => $newpost->id]);
        $DB->set_field('local_embeddiscussion_post', 'timecreated', $lastaccess + 25, ['id' => $sectionpost->id]);
        $DB->set_field('local_embeddiscussion_post', 'timemodified', $lastaccess - 50, ['id' => $oldpost->id]);
        $DB->set_field('local_embeddiscussion_post', 'timemodified', $lastaccess + 50, ['id' => $newpost->id]);
        $DB->set_field('local_embeddiscussion_post', 'timemodified', $lastaccess + 25, ['id' => $sectionpost->id]);
        $DB->insert_record('user_lastaccess', (object)[
            'userid' => $student->id,
            'courseid' => $course->id,
            'timeaccess' => $lastaccess,
        ]);

        $this->setUser($student);
        $view = manager::get_dashboard_view((int)$course->id, (int)$student->id);

        $this->assertSame(3, $view['postcount']);
        $this->assertCount(3, $view['posts']);
        $this->assertSame((int)$newpost->id, (int)$view['posts'][0]['id']);
        $this->assertSame((int)$sectionpost->id, (int)$view['posts'][1]['id']);
        $this->assertSame((int)$oldpost->id, (int)$view['posts'][2]['id']);
        $this->assertTrue($view['posts'][0]['isunread']);
        $this->assertTrue($view['posts'][1]['isunread']);
        $this->assertFalse($view['posts'][2]['isunread']);
        $this->assertSame('Visible label', $view['posts'][0]['threadname']);
        $this->assertSame('Section summary', $view['posts'][1]['threadname']);
        $this->assertStringContainsString('#embeddisc-post-' . (int)$newpost->id, $view['posts'][0]['posturl']);
        $this->assertStringContainsString('#embeddisc-post-' . (int)$sectionpost->id, $view['posts'][1]['posturl']);
        $this->assertStringNotContainsString('Hidden post', json_encode($view));
        $this->assertStringNotContainsString('Hidden section post', json_encode($view));
        $this->assertStringNotContainsString('Draft post', json_encode($view));
    }
}
