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

use core_external\external_api;
use local_embeddiscussion\external\create_post;
use local_embeddiscussion\external\get_previews;
use local_embeddiscussion\external\get_thread;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/tests/helpers.php');

/**
 * Tests for the embedded discussion web services.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embeddiscussion\external\get_previews
 * @covers     \local_embeddiscussion\external\get_thread
 * @covers     \local_embeddiscussion\external\create_post
 * @covers     \local_embeddiscussion\external\helper
 * @covers     \local_embeddiscussion\output\embed
 */
final class external_test extends \externallib_advanced_testcase {
    /**
     * Reset the per-request thread cache, which is keyed on ids PHPUnit reuses.
     */
    protected function setUp(): void {
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
     * Call get_previews and return its cleaned result, keyed by key.
     *
     * @param \context $context The editor's context.
     * @param string[] $keys The keys.
     * @param int $sectionid The section, or 0.
     * @return array<string, string> HTML by key.
     */
    protected function previews(\context $context, array $keys, int $sectionid = 0): array {
        $result = external_api::clean_returnvalue(
            get_previews::execute_returns(),
            get_previews::execute($context->id, $keys, $sectionid)
        );

        return array_column($result, 'html', 'key');
    }

    /**
     * Each token previews as its discussion's name over a skeleton, and never as anything live. A
     * token with no thread here previews as the page's notice.
     */
    public function test_get_previews(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $other = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $thread = $this->generator()->create_thread(['cmid' => $page->cmid, 'name' => 'Q&A "week 1"', 'anonymous' => 1]);
        $elsewhere = $this->generator()->create_thread(['cmid' => $other->cmid, 'name' => 'Elsewhere']);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $previews = $this->previews(\context_module::instance($page->cmid), [
            $thread->embedkey,
            $elsewhere->embedkey,
            $thread->embedkey,
        ]);

        $this->assertCount(2, $previews);
        $this->assertStringContainsString('Discussion: Q&amp;A &quot;week 1&quot;', $previews[$thread->embedkey]);
        $this->assertStringContainsString('Anonymous', $previews[$thread->embedkey]);
        $this->assertStringContainsString('embeddisc-skeleton', $previews[$thread->embedkey]);
        $this->assertStringNotContainsString('data-threadid', $previews[$thread->embedkey]);
        $this->assertStringNotContainsString('data-region="filter-embeddiscussion"', $previews[$thread->embedkey]);

        $this->assertStringContainsString('embeddisc-notfound', $previews[$elsewhere->embedkey]);
        $this->assertStringNotContainsString('Elsewhere', $previews[$elsewhere->embedkey]);
    }

    /**
     * A section's editor previews the course's section threads in its context.
     */
    public function test_get_previews_in_a_section(): void {
        $course = $this->getDataGenerator()->create_course();
        $thread = $this->generator()->create_section_thread(['courseid' => $course->id, 'section' => 1, 'name' => 'Week 1']);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $previews = $this->previews(\context_course::instance($course->id), [$thread->embedkey], (int)$thread->sectionid);

        $this->assertStringContainsString('Discussion: Week 1', $previews[$thread->embedkey]);
    }

    /**
     * A draft is only ever seen by whoever is writing it: previews on the "add an activity" form need
     * the embed capability.
     */
    public function test_get_previews_of_drafts_needs_embed(): void {
        $course = $this->getDataGenerator()->create_course();
        $draft = $this->generator()->create_thread(['courseid' => $course->id, 'name' => 'Draft']);
        $context = \context_course::instance($course->id);

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $this->assertStringContainsString('Discussion: Draft', $this->previews($context, [$draft->embedkey])[$draft->embedkey]);

        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));
        $this->expectException(\required_capability_exception::class);
        $this->previews($context, [$draft->embedkey]);
    }

    /**
     * A draft is on no page, so nothing can be read from it or posted to it.
     */
    public function test_drafts_cannot_be_read_or_posted_to(): void {
        $course = $this->getDataGenerator()->create_course();
        $draft = $this->generator()->create_thread(['courseid' => $course->id]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        try {
            get_thread::execute((int)$draft->id);
            $this->fail('A draft was read.');
        } catch (\moodle_exception $e) {
            $this->assertSame('error_invalidthread', $e->errorcode);
        }

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('error_invalidthread', 'local_embeddiscussion'));
        create_post::execute((int)$draft->id, 0, '<p>Hello</p>');
    }

    /**
     * A student reads an activity's discussion, and posts to it.
     */
    public function test_get_thread_and_create_post(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $thread = $this->generator()->create_thread(['cmid' => $page->cmid, 'name' => 'Week 1']);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $view = external_api::clean_returnvalue(get_thread::execute_returns(), get_thread::execute((int)$thread->id));
        $this->assertSame('Week 1', $view['name']);
        $this->assertSame(0, $view['postcount']);
        $this->assertTrue($view['canpost']);

        $view = external_api::clean_returnvalue(
            create_post::execute_returns(),
            create_post::execute((int)$thread->id, 0, '<p>Hello</p>')
        );
        $this->assertSame(1, $view['postcount']);
        $this->assertStringContainsString('Hello', $view['posts'][0]['content']);
    }
}
