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

namespace local_embeddiscussion\event;

/**
 * Event fired when a post is created.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class post_created extends \core\event\base {
    /**
     * Init.
     */
    protected function init() {
        $this->data['crud'] = 'c';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'local_embeddiscussion_post';
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event:post_created', 'local_embeddiscussion');
    }

    /**
     * Description.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '{$this->userid}' created post '{$this->objectid}' in thread '{$this->other['threadid']}'.";
    }

    /**
     * Factory.
     *
     * @param \stdClass $post
     * @param \stdClass $thread
     * @param \context $context
     * @return self
     */
    public static function create_for_post(\stdClass $post, \stdClass $thread, \context $context): self {
        return self::create([
            'objectid' => $post->id,
            'context' => $context,
            'other' => [
                'threadid' => (int)$thread->id,
                'parentid' => (int)$post->parentid,
            ],
        ]);
    }

    /**
     * How log restore maps the event's object: to the restored post.
     *
     * @return array
     */
    public static function get_objectid_mapping() {
        return ['db' => 'local_embeddiscussion_post', 'restore' => 'local_embeddiscussion_post'];
    }

    /**
     * How log restore maps the event's other data.
     *
     * @return array
     */
    public static function get_other_mapping() {
        return [
            'threadid' => ['db' => 'local_embeddiscussion_thread', 'restore' => 'local_embeddiscussion_thread'],
        ];
    }
}
