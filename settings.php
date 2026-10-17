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
 * Site-wide settings for embedded discussions.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_embeddiscussion', new lang_string('pluginname', 'local_embeddiscussion'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_heading(
            'local_embeddiscussion/handles',
            get_string('settings_handles', 'local_embeddiscussion'),
            ''
        ));

        $settings->add(new admin_setting_configtextarea(
            'local_embeddiscussion/adjectives',
            get_string('setting_adjectives', 'local_embeddiscussion'),
            get_string('setting_adjectives_desc', 'local_embeddiscussion'),
            get_string('setting_adjectives_default', 'local_embeddiscussion'),
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configtextarea(
            'local_embeddiscussion/animals',
            get_string('setting_animals', 'local_embeddiscussion'),
            get_string('setting_animals_desc', 'local_embeddiscussion'),
            get_string('setting_animals_default', 'local_embeddiscussion'),
            PARAM_TEXT
        ));

        $settings->add(new admin_setting_configtextarea(
            'local_embeddiscussion/emojis',
            get_string('setting_emojis', 'local_embeddiscussion'),
            get_string('setting_emojis_desc', 'local_embeddiscussion'),
            \local_embeddiscussion\manager::DEFAULT_EMOJIS
        ));
    }
}
