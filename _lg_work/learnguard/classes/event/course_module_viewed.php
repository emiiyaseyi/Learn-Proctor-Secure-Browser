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
 * Course module viewed event for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Event fired whenever a user views the LearnGuard activity.
 */
class course_module_viewed extends \core\event\course_module_viewed {

    /**
     * Initialise the event.
     */
    protected function init(): void {
        $this->data['objecttable'] = 'learnguard';
        $this->data['crud']        = 'r';
        $this->data['edulevel']    = self::LEVEL_PARTICIPATING;
    }

    /**
     * Return the objectid mapping for backup/restore.
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'learnguard', 'restore' => 'learnguard'];
    }
}
