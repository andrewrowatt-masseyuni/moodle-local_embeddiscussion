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
 * Handle assignment for anonymous students.
 *
 * @package    local_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class handles {
    /**
     * Return the configured adjective list.
     *
     * @return string[]
     */
    public static function adjectives(): array {
        $raw = get_config('local_embeddiscussion', 'adjectives');
        if ($raw === false || $raw === '') {
            $raw = get_string('setting_adjectives_default', 'local_embeddiscussion');
        }
        return self::split_list($raw);
    }

    /**
     * Return the configured animal list.
     *
     * @return string[]
     */
    public static function animals(): array {
        $raw = get_config('local_embeddiscussion', 'animals');
        if ($raw === false || $raw === '') {
            $raw = get_string('setting_animals_default', 'local_embeddiscussion');
        }
        return self::split_list($raw);
    }

    /**
     * Split a comma- or newline-separated list, trimming and filtering empties.
     *
     * @param string $raw
     * @return string[]
     */
    protected static function split_list(string $raw): array {
        $items = preg_split('/[,\n\r]+/', $raw);
        $out = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '') {
                $out[] = $item;
            }
        }
        return $out;
    }

    /**
     * Get the master list of handles (cartesian product). Order is stable.
     *
     * @return string[]
     */
    public static function master_list(): array {
        $adjectives = self::adjectives();
        $animals = self::animals();
        $list = [];
        foreach ($adjectives as $adj) {
            foreach ($animals as $animal) {
                $list[] = $adj . ' ' . $animal;
            }
        }
        return $list;
    }

    /**
     * Get or assign a handle index for a user in a thread.
     *
     * The first anonymous user to post is assigned index 0, the second 1, and
     * so on. The returned label uses (handleindex + thread.handleoffset) modulo
     * the master list size.
     *
     * @param \stdClass $thread
     * @param int $userid
     * @return array [string label, int handleindex]
     */
    public static function get_or_assign(\stdClass $thread, int $userid): array {
        global $DB;

        $existing = $DB->get_record('local_embeddiscussion_handle', [
            'threadid' => $thread->id,
            'userid' => $userid,
        ]);

        if ($existing) {
            $index = (int)$existing->handleindex;
        } else {
            // Sequential assignment: count existing rows for the thread.
            $count = $DB->count_records('local_embeddiscussion_handle', [
                'threadid' => $thread->id,
            ]);
            $index = $count;
            $record = (object)[
                'threadid' => $thread->id,
                'userid' => $userid,
                'handleindex' => $index,
                'timecreated' => time(),
            ];
            try {
                $DB->insert_record('local_embeddiscussion_handle', $record);
            } catch (\dml_write_exception $e) {
                // Race condition: another request inserted concurrently. Re-read.
                $existing = $DB->get_record('local_embeddiscussion_handle', [
                    'threadid' => $thread->id,
                    'userid' => $userid,
                ], '*', MUST_EXIST);
                $index = (int)$existing->handleindex;
            }
        }

        $list = self::master_list();
        if (empty($list)) {
            return ['Anonymous', $index];
        }
        $position = ($index + (int)$thread->handleoffset) % count($list);
        return [$list[$position], $index];
    }
}
