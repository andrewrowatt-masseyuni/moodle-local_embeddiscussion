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
 * Course-level page listing all embedded discussion threads.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/course/lib.php');

use core_reportbuilder\system_report_factory;
use local_embeddiscussion\reportbuilder\local\systemreports\threads;

$courseid = optional_param('courseid', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$threadid = optional_param('threadid', 0, PARAM_INT);

if ($courseid) {
    $course = get_course($courseid);
    require_login($course);
    $context = context_course::instance($course->id);
} else {
    require_login();
    $context = context_system::instance();
}

if ($action === 'view' && $threadid) {
    $thread = $DB->get_record(\local_embeddiscussion\threads::TABLE, ['id' => $threadid], '*', MUST_EXIST);
    require_capability('local/embeddiscussion:managethreads', context_course::instance($thread->courseid));

    // Where this thread lives: its activity, or its section of the course page.
    if ($thread->cmid) {
        redirect(context_module::instance($thread->cmid)->get_url());
    }
    $section = get_fast_modinfo($thread->courseid)->get_section_info_by_id($thread->sectionid);
    redirect(course_get_url($thread->courseid, $section ? $section->section : null));
}

require_capability('local/embeddiscussion:managethreads', $context);

$indexurl = new moodle_url('/local/embeddiscussion/index.php', ['courseid' => $courseid]);
$PAGE->set_url($indexurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout($courseid ? 'incourse' : 'admin');
$PAGE->set_title(get_string('threadsincourse', 'local_embeddiscussion'));
$PAGE->set_heading(get_string('threadsincourse', 'local_embeddiscussion'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('threadsincourse', 'local_embeddiscussion'));

$report = system_report_factory::create(
    threads::class,
    $context,
    '',
    '',
    0,
    ['courseid' => $courseid]
);
echo $report->output();

echo $OUTPUT->footer();
