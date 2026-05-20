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

    // Auto-suspend a session when risk score is critically high.
    'quizaccess_learnshield_auto_suspend' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'auto_suspend',
        'classpath'   => '',
        'description' => 'Auto-suspend a session due to high risk score.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],

    // Request invigilator face approval.
    'quizaccess_learnshield_request_face_approval' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'request_face_approval',
        'classpath'   => '',
        'description' => 'Student requests invigilator face-match approval.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],

    // Poll face approval status and messages.
    'quizaccess_learnshield_check_face_approval' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'check_face_approval',
        'classpath'   => '',
        'description' => 'Poll face approval status and fetch new chat messages.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],

    // Proctor: approve, deny, or message on a face approval.
    'quizaccess_learnshield_action_face_approval' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'action_face_approval',
        'classpath'   => '',
        'description' => 'Proctor approves, denies, or sends a message on a face approval request.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:liveproctor',
    ],

    // Student sends a message in face approval chat.
    'quizaccess_learnshield_face_approval_student_message' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'face_approval_student_message',
        'classpath'   => '',
        'description' => 'Student sends a message in the face approval chat.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],

    // Report pre-exam wizard step progress.
    'quizaccess_learnshield_report_precheck' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'report_precheck',
        'classpath'   => '',
        'description' => 'Report current wizard step so proctors can track pre-exam progress.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],

    // Upload a recorded audio clip (triggered by high noise).
    'quizaccess_learnshield_upload_audio_clip' => [
        'classname'   => 'quizaccess_learnshield\external',
        'methodname'  => 'upload_audio_clip',
        'classpath'   => '',
        'description' => 'Upload a short audio clip captured during the exam.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'quizaccess/learnshield:sendcamshot',
    ],
];
