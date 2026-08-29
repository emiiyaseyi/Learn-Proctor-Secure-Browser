<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Backup steps for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class backup_learnguard_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        // Root element.
        $learnguard = new backup_nested_element('learnguard', ['id'], [
            'name', 'intro', 'introformat', 'instructions', 'proctoring',
            'proctor_fullscreen', 'proctor_webcam', 'proctor_audio',
            'proctor_tabdetect', 'proctor_keyblock', 'proctor_clipboard',
            'proctor_env_record', 'proctor_fcmethod', 'proctor_snapinterval',
            'block_mobile', 'timelimit', 'timeopen', 'timeclose',
            'maxattempts', 'grademethod', 'show_results', 'results_delay',
            'autosuspend_score', 'access_pin', 'notification_hours',
            'randomize', 'numquestions', 'timecreated', 'timemodified',
            'enable_chat', 'enable_break', 'enable_sos',
        ]);

        // Question slots — fixed assignments only (attemptid=0).
        // Pool-drawn slots (attemptid > 0) are per-attempt runtime records and must NOT
        // be carried over; doing so duplicates questions on every restore/duplicate.
        $slots = new backup_nested_element('slots');
        $slot  = new backup_nested_element('slot', ['id'], [
            'questionid', 'slot', 'maxmark', 'page',
        ]);

        // Random pool rules — preserved so the draw-N-from-category config survives.
        $pools = new backup_nested_element('pools');
        $pool  = new backup_nested_element('pool', ['id'], [
            'catid', 'drawcount', 'maxmark', 'sortorder', 'timelimit',
        ]);

        // Optionally include attempt data.
        $attempts = new backup_nested_element('attempts');
        $attempt  = new backup_nested_element('attempt', ['id'], [
            'userid', 'attempt', 'state', 'timestart', 'timefinish',
            'timemodified', 'score', 'maxscore', 'risk_score', 'risk_level',
        ]);

        // Build tree.
        $learnguard->add_child($slots);
        $slots->add_child($slot);
        $learnguard->add_child($pools);
        $pools->add_child($pool);

        if ($userinfo) {
            $learnguard->add_child($attempts);
            $attempts->add_child($attempt);
        }

        // Set sources.
        $learnguard->set_source_table('learnguard', ['id' => backup::VAR_ACTIVITYID]);
        // Only back up fixed slots (attemptid=0). Pool-drawn slots (attemptid>0) are
        // per-attempt runtime records that do not belong in the activity template.
        $slot->set_source_sql(
            'SELECT id, questionid, slot, maxmark, page
               FROM {learnguard_slots}
              WHERE learnguard = ? AND attemptid = 0',
            [backup::VAR_PARENTID]
        );
        $pool->set_source_table('learnguard_pools', ['learnguard' => backup::VAR_PARENTID]);

        if ($userinfo) {
            $attempt->set_source_table('learnguard_attempts', ['learnguard' => backup::VAR_PARENTID]);
            $attempt->annotate_ids('user', 'userid');
        }

        $learnguard->annotate_files('mod_learnguard', 'intro', null);

        return $this->prepare_activity_structure($learnguard);
    }
}
