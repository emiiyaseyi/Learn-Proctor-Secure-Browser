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
 * Web-service function definitions for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();

$functions = [

    // Send a webcam snapshot from a student during an attempt.
    'quizaccess_learnshield_send_camshot' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'send_camshot',
        'classpath'   => '',
        'description' => 'Send a webcam camshot during a proctored quiz attempt.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],

    // Log a proctoring event (tab switch, fullscreen exit, etc.).
    'quizaccess_learnshield_log_event' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'log_event',
        'classpath'   => '',
        'description' => 'Log a proctoring event (violation, info) for a session.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],

    // Get pending chat messages for the current user/session.
    'quizaccess_learnshield_get_messages' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'get_messages',
        'classpath'   => '',
        'description' => 'Retrieve pending chat messages for a session.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],

    // Send a chat message (proctor to learner or broadcast).
    'quizaccess_learnshield_send_message' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'send_message',
        'classpath'   => '',
        'description' => 'Send a chat message to a learner or broadcast to all.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:chat',
    ],

    // Get live session data for the proctor dashboard.
    'quizaccess_learnshield_get_live_sessions' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'get_live_sessions',
        'classpath'   => '',
        'description' => 'Get all active sessions for the live proctor dashboard.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:liveproctor',
    ],

    // Flag a learner session.
    'quizaccess_learnshield_flag_session' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'flag_session',
        'classpath'   => '',
        'description' => 'Manually flag a learner session as suspicious.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:flaglearner',
    ],

    // Get pending alerts for the proctor dashboard.
    'quizaccess_learnshield_get_alerts' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'get_alerts',
        'classpath'   => '',
        'description' => 'Get pending violation alerts for the proctor dashboard.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:liveproctor',
    ],

    // Validate face on quiz start using face-api.js result.
    'quizaccess_learnshield_validate_face' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'validate_face',
        'classpath'   => '',
        'description' => 'Validate face recognition result at quiz start.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],
];
