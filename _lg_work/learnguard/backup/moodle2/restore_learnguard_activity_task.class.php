<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Restore task for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/learnguard/backup/moodle2/restore_learnguard_stepslib.php');

class restore_learnguard_activity_task extends restore_activity_task {

    protected function define_my_settings() {
        // No custom settings.
    }

    protected function define_my_steps() {
        $this->add_step(new restore_learnguard_activity_structure_step(
            'learnguard_structure', 'learnguard.xml'
        ));
    }

    public static function define_decode_contents() {
        $contents   = [];
        $contents[] = new restore_decode_content('learnguard', ['intro'], 'learnguard');
        return $contents;
    }

    public static function define_decode_rules() {
        $rules   = [];
        $rules[] = new restore_decode_rule(
            'LEARNGUARDVIEWBYID', '/mod/learnguard/view.php?id=$1', 'course_module'
        );
        return $rules;
    }

    public static function define_restore_log_rules() {
        return [];
    }

    public static function define_restore_log_rules_for_course() {
        return [];
    }
}
