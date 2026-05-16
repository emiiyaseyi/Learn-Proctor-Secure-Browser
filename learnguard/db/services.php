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
 * Web service function definitions for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    // Start or resume an attempt (called when student opens the assessment).
    'mod_learnguard_start_attempt' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'start_attempt',
        'description'  => 'Start or resume a LearnGuard assessment attempt.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:attempt',
    ],

    // Get rendered HTML for a question slot.
    'mod_learnguard_get_question' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'get_question',
        'description'  => 'Get the rendered HTML for a specific question slot.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:attempt',
    ],

    // Submit an answer and move to the next slot.
    'mod_learnguard_submit_answer' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'submit_answer',
        'description'  => 'Submit the answer for a question slot and get the next question.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:attempt',
    ],

    // Autosave the current answer (no navigation).
    'mod_learnguard_autosave' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'autosave',
        'description'  => 'Autosave the current question response without navigation.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:attempt',
    ],

    // Finish the entire attempt.
    'mod_learnguard_finish_attempt' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'finish_attempt',
        'description'  => 'Submit all answers and finish the attempt.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:attempt',
    ],

    // Get current attempt state (for reconnection recovery).
    'mod_learnguard_get_attempt_state' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'get_attempt_state',
        'description'  => 'Get the current state of an attempt for reconnection recovery.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:attempt',
    ],

    // Send a webcam snapshot.
    'mod_learnguard_send_snapshot' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'send_snapshot',
        'description'  => 'Send a webcam snapshot for storage.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:attempt',
    ],

    // Log a proctoring event.
    'mod_learnguard_log_event' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'log_event',
        'description'  => 'Log a proctoring event.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:attempt',
    ],

    // Get proctor messages for the student.
    'mod_learnguard_get_messages' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'get_messages',
        'description'  => 'Get pending proctor messages for the current attempt.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:attempt',
    ],

    // Get all live sessions for the proctor dashboard.
    'mod_learnguard_get_live_sessions' => [
        'classname'    => 'mod_learnguard\external',
        'methodname'   => 'get_live_sessions',
        'description'  => 'Get all active sessions for the proctor dashboard.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/learnguard:liveproctor',
    ],
];
