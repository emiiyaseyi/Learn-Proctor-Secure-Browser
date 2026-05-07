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
 * Scheduled task: delete proctoring images.
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
 * Deletes proctoring images that have been scheduled for removal (deletionprogress = 1).
 */
class delete_images_task extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('delete_images_task', 'quizaccess_learnshield');
    }

    public function execute() {
        global $DB;

        $records = $DB->get_records('quizaccess_learnshield_logs',
            ['deletionprogress' => 1], '', 'id', 0, 200);

        if (empty($records)) {
            mtrace('No images pending deletion.');
            return;
        }

        $fs      = get_file_storage();
        $context = \context_system::instance();
        $count   = 0;

        foreach ($records as $record) {
            $fs->delete_area_files($context->id, 'quizaccess_learnshield', 'picture', $record->id);
            $DB->delete_records('quizaccess_learnshield_logs', ['id' => $record->id]);
            $count++;
        }

        mtrace("Deleted {$count} proctoring image records.");
    }
}
