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

use core_form\external\dynamic_form;
use local_embeddiscussion\form\embed_form;

/**
 * Tests for the form that sets up an embedded discussion.
 *
 * @package    local_embeddiscussion
 * @category   test
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_embeddiscussion\form\embed_form
 */
final class embed_form_test extends \advanced_testcase {
    /**
     * Reset the per-request thread cache, which is keyed on ids PHPUnit reuses.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        threads::reset_cache();
    }

    /**
     * Submit the form as the modal does, through core_form's own dynamic form web service, which
     * validates the context and checks access before the form is built.
     *
     * @param array $data The form's data.
     * @return array The web service's result.
     */
    protected function submit(array $data): array {
        // The mock adds the submission marker and puts the sesskey where confirm_sesskey() looks.
        return dynamic_form::execute(embed_form::class, $this->query(embed_form::mock_ajax_submit($data)));
    }

    /**
     * Open the form without submitting it, as the modal does first.
     *
     * @param array $args The modal's arguments.
     * @return string The form's HTML.
     */
    protected function open(array $args): string {
        return dynamic_form::execute(embed_form::class, $this->query($args))['html'];
    }

    /**
     * Encode form data the way the modal does.
     *
     * The separator is explicit because Moodle sets arg_separator.output to &amp;, which parse_str()
     * on the other side would read as part of the next key.
     *
     * @param array $data The form data.
     * @return string
     */
    protected function query(array $data): string {
        return http_build_query($data, '', '&');
    }

    /**
     * A new discussion is offered as not anonymous, with the help written out, and saving makes it.
     */
    public function test_embedding_a_new_discussion(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $html = $this->open(['contextid' => $context->id]);
        $this->assertMatchesRegularExpression('~<select[^>]*name="anonymous"~', $html);
        $this->assertMatchesRegularExpression('~<option value="0" selected~', $html);
        $this->assertStringContainsString(get_string('anonymous_inlinehelp', 'local_embeddiscussion'), $html);
        $this->assertStringNotContainsString('name="name"', $html);

        $result = $this->submit(['contextid' => $context->id, 'sectionid' => 0, 'key' => '', 'anonymous' => 1]);

        $this->assertTrue($result['submitted']);
        $key = json_decode($result['data'])->key;
        $row = api::get_embed($context, $key);
        $this->assertSame(1, (int)$row->anonymous);
    }

    /**
     * Changing a discussion keeps its key.
     */
    public function test_changing_a_discussion(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $thread = $this->getDataGenerator()->get_plugin_generator('local_embeddiscussion')
            ->create_thread(['cmid' => $page->cmid, 'anonymous' => 1]);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $html = $this->open(['contextid' => $context->id, 'key' => $thread->embedkey]);
        $this->assertMatchesRegularExpression('~<option value="1" selected~', $html);

        $result = $this->submit(['contextid' => $context->id, 'sectionid' => 0, 'key' => $thread->embedkey,
            'anonymous' => 0]);

        $this->assertSame($thread->embedkey, json_decode($result['data'])->key);
        $row = api::get_embed($context, $thread->embedkey);
        $this->assertSame(0, (int)$row->anonymous);
    }

    /**
     * Only someone who may embed discussions may set one up.
     */
    public function test_needs_embed(): void {
        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $context = \context_module::instance($page->cmid);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'teacher'));

        $this->expectException(\required_capability_exception::class);
        $this->submit(['contextid' => $context->id, 'sectionid' => 0, 'key' => '', 'anonymous' => 0]);
    }
}
