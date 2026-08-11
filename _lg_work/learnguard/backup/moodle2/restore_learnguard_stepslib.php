<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Restore steps for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

class restore_learnguard_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths   = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('learnguard', '/activity/learnguard');
        $paths[] = new restore_path_element('learnguard_slot', '/activity/learnguard/slots/slot');
        $paths[] = new restore_path_element('learnguard_pool', '/activity/learnguard/pools/pool');

        if ($userinfo) {
            $paths[] = new restore_path_element('learnguard_attempt',
                '/activity/learnguard/attempts/attempt');
        }

        return $this->prepare_activity_structure($paths);
    }

    protected function process_learnguard($data) {
        global $DB;
        $data = (object)$data;
        $data->course      = $this->get_courseid();
        $data->timecreated = time();
        $data->timemodified= time();

        $newid = $DB->insert_record('learnguard', $data);
        $this->apply_activity_instance($newid);
    }

    protected function process_learnguard_slot($data) {
        global $DB;
        $data              = (object)$data;
        $data->learnguard  = $this->get_new_parentid('learnguard');
        $data->attemptid   = 0; // always restore as a fixed slot, never a pool-drawn one
        $DB->insert_record('learnguard_slots', $data);
    }

    protected function process_learnguard_pool($data) {
        global $DB;
        $data             = (object)$data;
        $data->learnguard = $this->get_new_parentid('learnguard');
        $data->timecreated = time();
        // catid references learnguard_qbank_cats which is site-wide (not activity-scoped),
        // so we preserve the original catid — the same question bank categories exist.
        $DB->insert_record('learnguard_pools', $data);
    }

    protected function process_learnguard_attempt($data) {
        global $DB;
        $data             = (object)$data;
        $data->learnguard = $this->get_new_parentid('learnguard');
        $data->userid     = $this->get_mappingid('user', $data->userid);
        $DB->insert_record('learnguard_attempts', $data);
    }

    protected function after_execute() {
        $this->add_related_files('mod_learnguard', 'intro', null);
    }
}
