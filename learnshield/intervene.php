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
 * Proctor intervention endpoint (suspend / force-submit / warn) — Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

use quizaccess_learnshield\session_manager;
use quizaccess_learnshield\event_tracker;

require_login();
require_sesskey();

$action    = required_param('action',    PARAM_ALPHA);
$sessionid = required_param('sessionid', PARAM_INT);

$context = context_system::instance();

header('Content-Type: application/json');

switch ($action) {
    case 'suspend':
        require_capability('quizaccess/learnshield:suspendlearner', $context);
        session_manager::suspend($sessionid, $USER->id);
        echo json_encode(['success' => true, 'message' => get_string('session_suspended', 'quizaccess_learnshield')]);
        break;

    case 'warn':
        require_capability('quizaccess/learnshield:flaglearner', $context);
        $message = optional_param('message', 'Warning from proctor.', PARAM_TEXT);
        $session = $DB->get_record('quizaccess_learnshield_sessions', ['id' => $sessionid], '*', MUST_EXIST);
        $DB->insert_record('quizaccess_learnshield_chat', (object)[
            'sessionid'    => $sessionid,
            'sender_id'    => $USER->id,
            'recipient_id' => $session->userid,
            'courseid'     => $session->courseid,
            'quizid'       => $session->quizid,
            'message'      => $message,
            'is_broadcast' => 0,
            'is_read'      => 0,
            'message_type' => 'warning',
            'timemodified' => time(),
        ]);
        event_tracker::log($sessionid, $session->courseid, $session->quizid, $session->userid,
            $session->attemptid, event_tracker::TYPE_WARNING_SENT,
            json_encode(['proctor' => $USER->id, 'message' => $message]),
            event_tracker::SEVERITY_WARNING, 0
        );
        echo json_encode(['success' => true, 'message' => get_string('warning_sent', 'quizaccess_learnshield')]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Unknown action.']);
        break;
}
