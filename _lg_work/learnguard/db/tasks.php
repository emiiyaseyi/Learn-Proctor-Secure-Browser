<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled task definitions for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    // Pre-exam notification emails — runs every hour.
    [
        'classname'   => 'mod_learnguard\task\send_preexam_notifications',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '*',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],

    // GDPR / data retention cleanup — runs daily at 02:00.
    [
        'classname'   => 'mod_learnguard\task\cleanup_proctoring_data',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '2',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],

    // Grading deadline reminders — runs daily at 08:00.
    [
        'classname'   => 'mod_learnguard\task\send_grader_reminders',
        'blocking'    => 0,
        'minute'      => '0',
        'hour'        => '8',
        'day'         => '*',
        'month'       => '*',
        'dayofweek'   => '*',
        'disabled'    => 0,
    ],
];
