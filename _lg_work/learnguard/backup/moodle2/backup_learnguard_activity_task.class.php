<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Backup task for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/learnguard/backup/moodle2/backup_learnguard_stepslib.php');

class backup_learnguard_activity_task extends backup_activity_task {

    protected function define_my_settings() {
        // No custom settings.
    }

    protected function define_my_steps() {
        $this->add_step(new backup_learnguard_activity_structure_step(
            'learnguard_structure', 'learnguard.xml'
        ));
    }

    public static function encode_content_links($content) {
        global $CFG;
        $base = preg_quote($CFG->wwwroot, '/');

        // Link to view.php.
        $pattern     = "/($base\/mod\/learnguard\/view\.php\?id=)([0-9]+)/";
        $replacement = '$@LEARNGUARDVIEWBYID*$2@$';
        $content     = preg_replace($pattern, $replacement, $content);

        return $content;
    }
}
