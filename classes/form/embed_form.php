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

namespace local_embeddiscussion\form;

use core_form\dynamic_form;
use local_embeddiscussion\api;

/**
 * Set up one embedded discussion: its name, and whether it is anonymous.
 *
 * Opened by tiny_embeddiscussion in a modal, to embed a new discussion or change one already in the
 * text. Saving makes or updates the thread straight away; the editor then puts its token in the text.
 *
 * Arguments: contextid (the editor's context), and optionally sectionid (the section whose summary is
 * being edited, in a course context) and key (the discussion being changed).
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class embed_form extends dynamic_form {
    /** @var \stdClass|null|false The thread being changed, null for none, or false before it is looked up. */
    protected $row = false;

    /**
     * The editor's context: an activity, or the course for a section summary or while an activity is
     * being added.
     *
     * @return \context
     */
    protected function get_context_for_dynamic_submission(): \context {
        $context = \context::instance_by_id($this->optional_param('contextid', 0, PARAM_INT));
        // Throws for any context a thread cannot belong to, or a section not in the course.
        api::embed_target($context, $this->get_sectionid());

        return $context;
    }

    /**
     * The section whose summary is being edited, or 0.
     *
     * @return int
     */
    protected function get_sectionid(): int {
        return $this->optional_param('sectionid', 0, PARAM_INT);
    }

    /**
     * The thread being changed, if there is one here.
     *
     * @return \stdClass|null
     */
    protected function get_row(): ?\stdClass {
        if ($this->row === false) {
            $key = $this->optional_param('key', '', PARAM_ALPHANUM);
            $this->row = $key !== ''
                ? api::get_embed($this->get_context_for_dynamic_submission(), $key, $this->get_sectionid())
                : null;
        }

        return $this->row;
    }

    /**
     * Only people who may embed discussions may set them up.
     */
    protected function check_access_for_dynamic_submission(): void {
        require_capability('local/embeddiscussion:embed', $this->get_context_for_dynamic_submission());
    }

    /**
     * The page this form is notionally on.
     *
     * @return \moodle_url
     */
    protected function get_page_url_for_dynamic_submission(): \moodle_url {
        return $this->get_context_for_dynamic_submission()->get_url();
    }

    /**
     * The form.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'contextid');
        $mform->setType('contextid', PARAM_INT);

        $mform->addElement('hidden', 'sectionid');
        $mform->setType('sectionid', PARAM_INT);

        $mform->addElement('hidden', 'key');
        $mform->setType('key', PARAM_ALPHANUM);

        $mform->addElement('text', 'name', get_string('name', 'local_embeddiscussion'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'name', 'local_embeddiscussion');

        $mform->addElement(
            'advcheckbox',
            'anonymous',
            get_string('anonymous', 'local_embeddiscussion'),
            get_string('anonymous_label', 'local_embeddiscussion')
        );
        $mform->addHelpButton('anonymous', 'anonymous', 'local_embeddiscussion');
    }

    /**
     * Validation.
     *
     * @param array $data The submitted data.
     * @param array $files The submitted files.
     * @return array Errors, keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (trim((string)($data['name'] ?? '')) === '') {
            $errors['name'] = get_string('required');
        }

        return $errors;
    }

    /**
     * Load the thread being changed, or offer a name for a new one.
     */
    public function set_data_for_dynamic_submission(): void {
        $context = $this->get_context_for_dynamic_submission();
        $row = $this->get_row();

        $this->set_data([
            'contextid' => $context->id,
            'sectionid' => $this->get_sectionid(),
            'key' => $row ? $row->embedkey : '',
            'name' => $row ? $row->name : api::default_name($context, $this->get_sectionid()),
            'anonymous' => $row ? (int)$row->anonymous : 0,
        ]);
    }

    /**
     * Save the thread.
     *
     * @return array ['key' => the thread's key, for the editor to put in the token]
     */
    public function process_dynamic_submission() {
        $data = $this->get_data();

        $key = api::save_embed(
            $this->get_context_for_dynamic_submission(),
            $data->key !== '' ? $data->key : null,
            (string)$data->name,
            !empty($data->anonymous),
            $this->get_sectionid()
        );

        return ['key' => $key];
    }
}
