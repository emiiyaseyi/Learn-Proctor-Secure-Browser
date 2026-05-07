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
 * Retention policy manager for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace quizaccess_learnshield;

defined('MOODLE_INTERNAL') || die();

/**
 * Handles automatic purging of expired proctoring evidence.
 */
class retention_manager {

    /**
     * Purge all expired evidence based on the configured retention policies.
     */
    public static function purge_expired(): void {
        self::purge_snapshots();
        self::purge_events();
    }

    /**
     * Delete expired webcam snapshots and their log records.
     */
    private static function purge_snapshots(): void {
        global $DB;

        $days      = (int)(get_config('quizaccess_learnshield', 'retention_snapshots') ?: 14);
        $threshold = time() - ($days * DAYSECS);

        $logs = $DB->get_records_select(
            'quizaccess_learnshield_logs',
            'timemodified < :threshold AND deletionprogress = 0',
            ['threshold' => $threshold],
            '', 'id, webcampicture', 0, 500
        );

        if (empty($logs)) {
            return;
        }

        $fs      = get_file_storage();
        $context = \context_system::instance();

        foreach ($logs as $log) {
            // Delete associated Moodle files.
            $fs->delete_area_files($context->id, 'quizaccess_learnshield', 'picture', $log->id);

            $DB->set_field('quizaccess_learnshield_logs', 'deletionprogress', 1, ['id' => $log->id]);
            $DB->set_field('quizaccess_learnshield_logs', 'webcampicture',   '', ['id' => $log->id]);
        }

        mtrace('Purged ' . count($logs) . ' expired snapshot records.');
    }

    /**
     * Delete old event log records.
     */
    private static function purge_events(): void {
        global $DB;

        $days      = (int)(get_config('quizaccess_learnshield', 'retention_logs') ?: 180);
        $threshold = time() - ($days * DAYSECS);

        $deleted = $DB->delete_records_select(
            'quizaccess_learnshield_events',
            'timemodified < :threshold',
            ['threshold' => $threshold]
        );

        mtrace("Purged old event records older than {$days} days.");
    }
}
