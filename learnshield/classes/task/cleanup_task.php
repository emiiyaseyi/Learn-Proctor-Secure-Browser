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
 * Scheduled task: general cleanup.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace quizaccess_learnshield\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Cleans up stale session records and orphan data.
 */
class cleanup_task extends \core\task\scheduled_task {

    public function get_name() {
        return 'Learn Shield: general cleanup';
    }

    public function execute() {
        global $DB;

        // Mark sessions older than 24 hours with no end_time as terminated.
        $threshold = time() - (24 * HOURSECS);
        $DB->execute(
            "UPDATE {quizaccess_learnshield_sessions}
                SET status = 'terminated', end_time = :now, timemodified = :now2
              WHERE status = 'active' AND start_time < :threshold",
            ['now' => time(), 'now2' => time(), 'threshold' => $threshold]
        );

        // Remove orphan face-match tasks older than 7 days.
        $weeksago = time() - (7 * DAYSECS);
        $DB->delete_records_select('quizaccess_learnshield_facematch_task',
            'timemodified < :threshold', ['threshold' => $weeksago]);

        mtrace('Learn Shield cleanup task complete.');
    }
}
