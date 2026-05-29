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
 * Web-service external functions for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace quizaccess_learnshield;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');

/**
 * External API class for Learn Shield V1 AJAX calls.
 */
class external extends \external_api {

    // -----------------------------------------------------------------------
    // send_camshot
    // -----------------------------------------------------------------------

    public static function send_camshot_parameters() {
        return new \external_function_parameters([
            'courseid'     => new \external_value(PARAM_INT,  'Course ID'),
            'screenshotid' => new \external_value(PARAM_INT,  'Log record ID'),
            'quizid'       => new \external_value(PARAM_INT,  'Quiz (cm) ID'),
            'webcampicture'=> new \external_value(PARAM_RAW,  'Base64 webcam image'),
            'imagetype'    => new \external_value(PARAM_INT,  'Image type (1 = webcam)', VALUE_DEFAULT, 1),
            'parenttype'   => new \external_value(PARAM_TEXT, 'Parent type for face image', VALUE_DEFAULT, 'camshot_image'),
            'faceimage'    => new \external_value(PARAM_RAW,  'Base64 face image',  VALUE_DEFAULT, ''),
            'facefound'    => new \external_value(PARAM_INT,  'Face found flag',     VALUE_DEFAULT, 0),
        ]);
    }

    public static function send_camshot(
        int $courseid, int $screenshotid, int $quizid,
        string $webcampicture, int $imagetype = 1,
        string $parenttype = 'camshot_image', string $faceimage = '', int $facefound = 0
    ) {
        global $USER, $DB;

        $params = self::validate_parameters(self::send_camshot_parameters(), [
            'courseid' => $courseid, 'screenshotid' => $screenshotid, 'quizid' => $quizid,
            'webcampicture' => $webcampicture, 'imagetype' => $imagetype,
            'parenttype' => $parenttype, 'faceimage' => $faceimage, 'facefound' => $facefound,
        ]);

        // Students call this — only require login, not a module-level capability at system context.
        $context = \context_system::instance();
        self::validate_context($context);

        $warnings = [];

        // Always INSERT a new log record for each snapshot so all snapshots appear in the report.
        $newlogid = 0;
        try {
            $newlogid = $DB->insert_record('quizaccess_learnshield_logs', (object)[
                'courseid'         => (int)$params['courseid'],
                'quizid'           => (int)$params['quizid'],
                'userid'           => (int)$USER->id,
                'webcampicture'    => '',
                'status'           => 0,
                'awsscore'         => 0,
                'awsflag'          => 0,
                'deletionprogress' => 0,
                'timemodified'     => time(),
            ]);
        } catch (\Throwable $e) {
            // Fall back to screenshotid if insert fails.
            $newlogid = $params['screenshotid'];
        }

        $imageurl = screenshot::save_webcam_image(
            $params['courseid'], $params['quizid'], $USER->id,
            $newlogid > 0 ? $newlogid : $params['screenshotid'], $params['webcampicture']
        );

        if (!$imageurl) {
            $warnings[] = ['item' => 'webcampicture', 'warningcode' => 'invalidpicture', 'message' => 'Could not save image.'];
        }

        // Save face image fragment if provided.
        if (!empty($params['faceimage'])) {
            screenshot::save_face_image(
                $params['faceimage'], $USER->id, $newlogid > 0 ? $newlogid : $params['screenshotid'],
                $params['parenttype'], $params['facefound']
            );
        }

        // Log the snapshot event.
        $sessionId = $params['screenshotid']; // screenshotid field doubles as sessionid for event tracker.
        event_tracker::log($sessionId, $params['courseid'], $params['quizid'],
            $USER->id, 0, 'webcam_snapshot', json_encode(['url' => $imageurl ? $imageurl->out(false) : '']),
            'info', 0);

        return ['warnings' => $warnings];
    }

    public static function send_camshot_returns() {
        return new \external_single_structure([
            'warnings' => new \external_warnings(),
        ]);
    }

    // -----------------------------------------------------------------------
    // log_event
    // -----------------------------------------------------------------------

    public static function log_event_parameters() {
        return new \external_function_parameters([
            'sessionid'  => new \external_value(PARAM_INT,  'Session ID',     VALUE_DEFAULT, 0),
            'courseid'   => new \external_value(PARAM_INT,  'Course ID'),
            'quizid'     => new \external_value(PARAM_INT,  'Quiz (cm) ID'),
            'attemptid'  => new \external_value(PARAM_INT,  'Attempt ID',     VALUE_DEFAULT, 0),
            'event_type' => new \external_value(PARAM_ALPHANUMEXT, 'Event type'),
            'event_data' => new \external_value(PARAM_RAW,  'JSON event data', VALUE_DEFAULT, '{}'),
            'severity'   => new \external_value(PARAM_ALPHA,'Severity: info|warning|danger', VALUE_DEFAULT, 'info'),
        ]);
    }

    public static function log_event(
        int $sessionid, int $courseid, int $quizid, int $attemptid,
        string $event_type, string $event_data = '{}', string $severity = 'info'
    ) {
        global $USER, $DB;

        $params = self::validate_parameters(self::log_event_parameters(), [
            'sessionid' => $sessionid, 'courseid' => $courseid, 'quizid' => $quizid,
            'attemptid' => $attemptid, 'event_type' => $event_type,
            'event_data' => $event_data, 'severity' => $severity,
        ]);

        // Students call this — only require login.
        $context = \context_system::instance();
        self::validate_context($context);

        // Map event type to score impact.
        $scoreimpact = suspicion_scorer::get_score_for_event($params['event_type']);

        event_tracker::log(
            $params['sessionid'], $params['courseid'], $params['quizid'],
            $USER->id, $params['attemptid'], $params['event_type'],
            $params['event_data'], $params['severity'], $scoreimpact
        );

        // Update session risk score.
        if ($scoreimpact > 0 && $params['sessionid'] > 0) {
            suspicion_scorer::add_score($params['sessionid'], $scoreimpact, $params['event_type']);
        }

        return ['success' => true, 'scoreimpact' => $scoreimpact];
    }

    public static function log_event_returns() {
        return new \external_single_structure([
            'success'     => new \external_value(PARAM_BOOL, 'Success flag'),
            'scoreimpact' => new \external_value(PARAM_INT,  'Score points added'),
        ]);
    }

    // -----------------------------------------------------------------------
    // get_messages
    // -----------------------------------------------------------------------

    public static function get_messages_parameters() {
        return new \external_function_parameters([
            'sessionid' => new \external_value(PARAM_INT, 'Session ID'),
            'since'     => new \external_value(PARAM_INT, 'Return messages after this timestamp', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_messages(int $sessionid, int $since = 0) {
        global $USER, $DB;

        $params = self::validate_parameters(self::get_messages_parameters(), [
            'sessionid' => $sessionid, 'since' => $since,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        $sql = "SELECT * FROM {quizaccess_learnshield_chat}
                 WHERE sessionid = :sessionid
                   AND (recipient_id = :userid OR recipient_id = 0 OR sender_id = :userid2)
                   AND timemodified > :since
                 ORDER BY timemodified ASC";

        $rows = $DB->get_records_sql($sql, [
            'sessionid' => $params['sessionid'],
            'userid'    => $USER->id,
            'userid2'   => $USER->id,
            'since'     => $params['since'],
        ]);

        $messages = [];
        foreach ($rows as $row) {
            $sender = $DB->get_record('user', ['id' => $row->sender_id], 'id,firstname,lastname');
            $messages[] = [
                'id'           => $row->id,
                'sender_id'    => $row->sender_id,
                'sender_name'  => fullname($sender),
                'message'      => $row->message,
                'is_broadcast' => (bool)$row->is_broadcast,
                'message_type' => $row->message_type,
                'timemodified' => $row->timemodified,
            ];
            // Mark as read.
            if ($row->recipient_id == $USER->id && !$row->is_read) {
                $DB->set_field('quizaccess_learnshield_chat', 'is_read', 1, ['id' => $row->id]);
            }
        }

        return ['messages' => $messages];
    }

    public static function get_messages_returns() {
        return new \external_single_structure([
            'messages' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'           => new \external_value(PARAM_INT,  'Message ID'),
                    'sender_id'    => new \external_value(PARAM_INT,  'Sender user ID'),
                    'sender_name'  => new \external_value(PARAM_TEXT, 'Sender display name'),
                    'message'      => new \external_value(PARAM_RAW,  'Message text'),
                    'is_broadcast' => new \external_value(PARAM_BOOL, 'Is broadcast'),
                    'message_type' => new \external_value(PARAM_TEXT, 'Message type'),
                    'timemodified' => new \external_value(PARAM_INT,  'Timestamp'),
                ])
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // send_message
    // -----------------------------------------------------------------------

    public static function send_message_parameters() {
        return new \external_function_parameters([
            'sessionid'    => new \external_value(PARAM_INT,  'Session ID'),
            'courseid'     => new \external_value(PARAM_INT,  'Course ID'),
            'quizid'       => new \external_value(PARAM_INT,  'Quiz (cm) ID'),
            'recipient_id' => new \external_value(PARAM_INT,  'Recipient user ID (0 = broadcast)'),
            'message'      => new \external_value(PARAM_RAW,  'Message text'),
            'message_type' => new \external_value(PARAM_TEXT, 'Message type', VALUE_DEFAULT, 'chat'),
        ]);
    }

    public static function send_message(
        int $sessionid, int $courseid, int $quizid,
        int $recipient_id, string $message, string $message_type = 'chat'
    ) {
        global $USER, $DB;

        $params = self::validate_parameters(self::send_message_parameters(), [
            'sessionid' => $sessionid, 'courseid' => $courseid, 'quizid' => $quizid,
            'recipient_id' => $recipient_id, 'message' => $message, 'message_type' => $message_type,
        ]);

        // Both proctors and students send messages — require login only.
        $context = \context_system::instance();
        self::validate_context($context);

        $msgid = $DB->insert_record('quizaccess_learnshield_chat', (object)[
            'sessionid'    => $params['sessionid'],
            'sender_id'    => $USER->id,
            'recipient_id' => $params['recipient_id'],
            'courseid'     => $params['courseid'],
            'quizid'       => $params['quizid'],
            'message'      => $params['message'],
            'is_broadcast' => ($params['recipient_id'] === 0) ? 1 : 0,
            'is_read'      => 0,
            'message_type' => $params['message_type'],
            'timemodified' => time(),
        ]);

        return ['success' => true, 'messageid' => $msgid];
    }

    public static function send_message_returns() {
        return new \external_single_structure([
            'success'   => new \external_value(PARAM_BOOL, 'Success'),
            'messageid' => new \external_value(PARAM_INT,  'New message ID'),
        ]);
    }

    // -----------------------------------------------------------------------
    // get_live_sessions
    // -----------------------------------------------------------------------

    public static function get_live_sessions_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID', VALUE_DEFAULT, 0),
            'quizid'   => new \external_value(PARAM_INT, 'Quiz (cm) ID', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_live_sessions(int $courseid = 0, int $quizid = 0) {
        global $DB;

        $params = self::validate_parameters(self::get_live_sessions_parameters(), [
            'courseid' => $courseid, 'quizid' => $quizid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('quizaccess/learnshield:liveproctor', $context);

        $where  = "s.status = 'active'";
        $qparams = [];

        if ($params['courseid']) {
            $where .= ' AND s.courseid = :courseid';
            $qparams['courseid'] = $params['courseid'];
        }
        if ($params['quizid']) {
            $where .= ' AND s.quizid = :quizid';
            $qparams['quizid'] = $params['quizid'];
        }

        $sql = "SELECT s.id, s.courseid, s.quizid, s.userid, s.attemptid, s.risk_score,
                       s.risk_level, s.is_flagged, s.start_time, s.fullscreen_exits,
                       s.tab_switches, s.face_missing_count, s.timemodified,
                       u.firstname, u.lastname, u.email,
                       c.fullname as coursename, q.name as quizname,
                       (SELECT l.webcampicture FROM {quizaccess_learnshield_logs} l
                         WHERE l.userid = s.userid AND l.quizid = s.quizid
                         ORDER BY l.timemodified DESC LIMIT 1) as latest_snapshot
                  FROM {quizaccess_learnshield_sessions} s
                 INNER JOIN {user}   u ON u.id = s.userid
                 INNER JOIN {course} c ON c.id = s.courseid
                 INNER JOIN {course_modules} cm ON cm.id = s.quizid
                 INNER JOIN {quiz}   q ON q.id  = cm.instance
                 WHERE {$where}
                 ORDER BY s.risk_score DESC";

        $rows     = $DB->get_records_sql($sql, $qparams);
        $sessions = [];
        foreach ($rows as $row) {
            $sessions[] = [
                'id'               => $row->id,
                'userid'           => $row->userid,
                'fullname'         => fullname($row),
                'email'            => $row->email,
                'coursename'       => $row->coursename,
                'quizname'         => $row->quizname,
                'risk_score'       => $row->risk_score,
                'risk_level'       => $row->risk_level,
                'is_flagged'       => (bool)$row->is_flagged,
                'tab_switches'     => $row->tab_switches,
                'fullscreen_exits' => $row->fullscreen_exits,
                'face_missing'     => $row->face_missing_count,
                'latest_snapshot'  => $row->latest_snapshot ?? '',
                'duration_minutes' => $row->start_time ? (int)((time() - $row->start_time) / 60) : 0,
            ];
        }

        return ['sessions' => $sessions, 'total' => count($sessions)];
    }

    public static function get_live_sessions_returns() {
        return new \external_single_structure([
            'sessions' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'               => new \external_value(PARAM_INT,  'Session ID'),
                    'userid'           => new \external_value(PARAM_INT,  'User ID'),
                    'fullname'         => new \external_value(PARAM_TEXT, 'Full name'),
                    'email'            => new \external_value(PARAM_TEXT, 'Email'),
                    'coursename'       => new \external_value(PARAM_TEXT, 'Course name'),
                    'quizname'         => new \external_value(PARAM_TEXT, 'Quiz name'),
                    'risk_score'       => new \external_value(PARAM_INT,  'Risk score'),
                    'risk_level'       => new \external_value(PARAM_TEXT, 'Risk level'),
                    'is_flagged'       => new \external_value(PARAM_BOOL, 'Is flagged'),
                    'tab_switches'     => new \external_value(PARAM_INT,  'Tab switch count'),
                    'fullscreen_exits' => new \external_value(PARAM_INT,  'Fullscreen exit count'),
                    'face_missing'     => new \external_value(PARAM_INT,  'Face missing count'),
                    'latest_snapshot'  => new \external_value(PARAM_URL,  'Latest snapshot URL', VALUE_OPTIONAL),
                    'duration_minutes' => new \external_value(PARAM_INT,  'Session duration (minutes)'),
                ])
            ),
            'total' => new \external_value(PARAM_INT, 'Total active sessions'),
        ]);
    }

    // -----------------------------------------------------------------------
    // flag_session
    // -----------------------------------------------------------------------

    public static function flag_session_parameters() {
        return new \external_function_parameters([
            'sessionid' => new \external_value(PARAM_INT,  'Session ID'),
            'flagged'   => new \external_value(PARAM_BOOL, 'Flag state (true = flag, false = unflag)'),
            'note'      => new \external_value(PARAM_TEXT, 'Optional proctor note', VALUE_DEFAULT, ''),
        ]);
    }

    public static function flag_session(int $sessionid, bool $flagged, string $note = '') {
        global $USER, $DB;

        $params = self::validate_parameters(self::flag_session_parameters(), [
            'sessionid' => $sessionid, 'flagged' => $flagged, 'note' => $note,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('quizaccess/learnshield:flaglearner', $context);

        $session = $DB->get_record('quizaccess_learnshield_sessions', ['id' => $params['sessionid']], '*', MUST_EXIST);

        $DB->set_field('quizaccess_learnshield_sessions', 'is_flagged',
            $params['flagged'] ? 1 : 0, ['id' => $params['sessionid']]);

        // Log the flag action.
        event_tracker::log($params['sessionid'], $session->courseid, $session->quizid,
            $session->userid, $session->attemptid, 'proctor_flag',
            json_encode(['flagged' => $params['flagged'], 'proctor' => $USER->id]),
            'warning', 0
        );

        // Save proctor note if provided.
        if (!empty($params['note'])) {
            $DB->insert_record('quizaccess_learnshield_proctor_notes', (object)[
                'sessionid'    => $params['sessionid'],
                'proctor_id'   => $USER->id,
                'courseid'     => $session->courseid,
                'quizid'       => $session->quizid,
                'userid'       => $session->userid,
                'note'         => $params['note'],
                'is_flagged'   => 1,
                'timemodified' => time(),
            ]);
        }

        return ['success' => true];
    }

    public static function flag_session_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Success'),
        ]);
    }

    // -----------------------------------------------------------------------
    // get_alerts
    // -----------------------------------------------------------------------

    public static function get_alerts_parameters() {
        return new \external_function_parameters([
            'courseid' => new \external_value(PARAM_INT, 'Course ID'),
            'since'    => new \external_value(PARAM_INT, 'Timestamp — return alerts after this', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_alerts(int $courseid, int $since = 0) {
        global $DB;

        $params = self::validate_parameters(self::get_alerts_parameters(), [
            'courseid' => $courseid, 'since' => $since,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('quizaccess/learnshield:liveproctor', $context);

        $sql = "SELECT e.id, e.sessionid, e.userid, e.event_type, e.severity,
                       e.timemodified, u.firstname, u.lastname
                  FROM {quizaccess_learnshield_events} e
                 INNER JOIN {user} u ON u.id = e.userid
                 WHERE e.courseid = :courseid
                   AND e.severity IN ('warning','danger')
                   AND e.timemodified > :since
                 ORDER BY e.timemodified DESC
                 LIMIT 50";

        $rows   = $DB->get_records_sql($sql, ['courseid' => $params['courseid'], 'since' => $params['since']]);
        $alerts = [];
        foreach ($rows as $row) {
            $alerts[] = [
                'id'           => $row->id,
                'sessionid'    => $row->sessionid,
                'userid'       => $row->userid,
                'fullname'     => fullname($row),
                'event_type'   => $row->event_type,
                'severity'     => $row->severity,
                'timemodified' => $row->timemodified,
            ];
        }

        return ['alerts' => $alerts];
    }

    public static function get_alerts_returns() {
        return new \external_single_structure([
            'alerts' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'           => new \external_value(PARAM_INT,  'Alert ID'),
                    'sessionid'    => new \external_value(PARAM_INT,  'Session ID'),
                    'userid'       => new \external_value(PARAM_INT,  'User ID'),
                    'fullname'     => new \external_value(PARAM_TEXT, 'Full name'),
                    'event_type'   => new \external_value(PARAM_TEXT, 'Event type'),
                    'severity'     => new \external_value(PARAM_TEXT, 'Severity'),
                    'timemodified' => new \external_value(PARAM_INT,  'Timestamp'),
                ])
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // validate_face
    // -----------------------------------------------------------------------

    public static function validate_face_parameters() {
        return new \external_function_parameters([
            'courseid'   => new \external_value(PARAM_INT,  'Course ID'),
            'quizid'     => new \external_value(PARAM_INT,  'Quiz (cm) ID'),
            'webcamdata' => new \external_value(PARAM_RAW,  'Base64 webcam snapshot'),
            'faceimage'  => new \external_value(PARAM_RAW,  'Base64 face fragment',  VALUE_DEFAULT, ''),
            'facefound'  => new \external_value(PARAM_INT,  'Face found flag',        VALUE_DEFAULT, 0),
        ]);
    }

    public static function validate_face(
        int $courseid, int $quizid, string $webcamdata,
        string $faceimage = '', int $facefound = 0
    ) {
        global $USER, $DB;

        $params = self::validate_parameters(self::validate_face_parameters(), [
            'courseid' => $courseid, 'quizid' => $quizid, 'webcamdata' => $webcamdata,
            'faceimage' => $faceimage, 'facefound' => $facefound,
        ]);

        // Students call this during precheck face validation — only require login.
        $context = \context_system::instance();
        self::validate_context($context);

        // Insert a log record.
        $logrecord = (object)[
            'courseid'     => $params['courseid'],
            'quizid'       => $params['quizid'],
            'userid'       => $USER->id,
            'webcampicture'=> '',
            'status'       => 0,
            'timemodified' => time(),
        ];
        $logrecord->id = $DB->insert_record('quizaccess_learnshield_logs', $logrecord);

        // Store images.
        screenshot::save_webcam_image($params['courseid'], $params['quizid'], $USER->id,
            $logrecord->id, $params['webcamdata']);

        if (!empty($params['faceimage'])) {
            screenshot::save_face_image($params['faceimage'], $USER->id, $logrecord->id,
                'camshot_image', $params['facefound']);
        }

        // Trigger BS analysis if configured.
        $fcmethod = get_config('quizaccess_learnshield', 'fcmethod');
        if ($fcmethod === 'BS') {
            quizaccess_learnshield_bs_analyze_specific_image_from_validate($logrecord->id);
        }

        $result = $DB->get_record('quizaccess_learnshield_logs', ['id' => $logrecord->id], 'awsflag,awsscore');

        return [
            'validated' => ($result && $result->awsscore >= 68),
            'score'     => $result ? (int)$result->awsscore : 0,
        ];
    }

    public static function validate_face_returns() {
        return new \external_single_structure([
            'validated' => new \external_value(PARAM_BOOL, 'Face validated successfully'),
            'score'     => new \external_value(PARAM_INT,  'Match score'),
        ]);
    }

    // -----------------------------------------------------------------------
    // auto_suspend
    // -----------------------------------------------------------------------

    public static function auto_suspend_parameters() {
        return new \external_function_parameters([
            'sessionid' => new \external_value(PARAM_INT,  'Session ID'),
            'courseid'  => new \external_value(PARAM_INT,  'Course ID'),
            'quizid'    => new \external_value(PARAM_INT,  'Quiz (cm) ID'),
            'reason'    => new \external_value(PARAM_TEXT, 'Suspension reason', VALUE_DEFAULT, 'high_risk_score'),
        ]);
    }

    public static function auto_suspend(int $sessionid, int $courseid, int $quizid, string $reason = 'high_risk_score') {
        global $USER, $DB;

        $params = self::validate_parameters(self::auto_suspend_parameters(), [
            'sessionid' => $sessionid, 'courseid' => $courseid,
            'quizid' => $quizid, 'reason' => $reason,
        ]);

        // Students call this (auto-suspend triggered by JS when score ≥ 80) — only require login.
        $context = \context_system::instance();
        self::validate_context($context);

        $suspended = false;
        if ($params['sessionid'] > 0) {
            $DB->set_field('quizaccess_learnshield_sessions', 'status', 'suspended',
                ['id' => $params['sessionid']]);
            event_tracker::log($params['sessionid'], $params['courseid'], $params['quizid'],
                $USER->id, 0, 'auto_suspended',
                json_encode(['reason' => $params['reason']]), 'danger', 0);
            $suspended = true;
        }

        return ['suspended' => $suspended];
    }

    public static function auto_suspend_returns() {
        return new \external_single_structure([
            'suspended' => new \external_value(PARAM_BOOL, 'Whether session was suspended'),
        ]);
    }

    // -----------------------------------------------------------------------
    // request_face_approval
    // -----------------------------------------------------------------------

    public static function request_face_approval_parameters() {
        return new \external_function_parameters([
            'cmid'         => new \external_value(PARAM_INT, 'Course-module ID'),
            'matchpct'     => new \external_value(PARAM_INT, 'Best face-match percent', VALUE_DEFAULT, 0),
            'passportdata' => new \external_value(PARAM_RAW, 'Base64 webcam snapshot',  VALUE_DEFAULT, ''),
        ]);
    }

    public static function request_face_approval(int $cmid, int $matchpct = 0, string $passportdata = '') {
        global $USER, $DB;

        $params = self::validate_parameters(self::request_face_approval_parameters(), [
            'cmid' => $cmid, 'matchpct' => $matchpct, 'passportdata' => $passportdata,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        // Upsert — one pending request per user per assessment.
        $existing = $DB->get_record('quizaccess_learnshield_face_approvals',
            ['cmid' => $params['cmid'], 'userid' => $USER->id, 'status' => 'pending']);

        if ($existing) {
            $approvalid = $existing->id;
        } else {
            $approvalid = $DB->insert_record('quizaccess_learnshield_face_approvals', (object)[
                'cmid'         => $params['cmid'],
                'userid'       => $USER->id,
                'status'       => 'pending',
                'matchpct'     => $params['matchpct'],
                'passportdata' => $params['passportdata'],
                'reviewedby'   => null,
                'reviewnote'   => null,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }

        return ['approvalid' => $approvalid];
    }

    public static function request_face_approval_returns() {
        return new \external_single_structure([
            'approvalid' => new \external_value(PARAM_INT, 'Face approval record ID'),
        ]);
    }

    // -----------------------------------------------------------------------
    // check_face_approval
    // -----------------------------------------------------------------------

    public static function check_face_approval_parameters() {
        return new \external_function_parameters([
            'approvalid' => new \external_value(PARAM_INT, 'Face approval record ID'),
            'since'      => new \external_value(PARAM_INT, 'Return messages after this timestamp', VALUE_DEFAULT, 0),
        ]);
    }

    public static function check_face_approval(int $approvalid, int $since = 0) {
        global $USER, $DB;

        $params = self::validate_parameters(self::check_face_approval_parameters(), [
            'approvalid' => $approvalid, 'since' => $since,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        $record = $DB->get_record('quizaccess_learnshield_face_approvals',
            ['id' => $params['approvalid'], 'userid' => $USER->id]);

        if (!$record) {
            return ['status' => 'pending', 'reviewnote' => '', 'messages' => []];
        }

        $msgs = [];
        try {
            $rows = $DB->get_records_sql(
                "SELECT m.*, u.firstname, u.lastname FROM {quizaccess_learnshield_fa_messages} m
                  JOIN {user} u ON u.id = m.userid
                 WHERE m.approvalid = :aid AND m.timecreated > :since ORDER BY m.timecreated ASC",
                ['aid' => $params['approvalid'], 'since' => $params['since']]
            );
            foreach ($rows as $row) {
                $msgs[] = [
                    'id'         => $row->id,
                    'sendertype' => $row->sendertype,
                    'message'    => $row->message,
                    'timecreated'=> $row->timecreated,
                ];
            }
        } catch (\Exception $e) { $msgs = []; }

        return [
            'status'     => $record->status,
            'reviewnote' => $record->reviewnote ?? '',
            'messages'   => $msgs,
        ];
    }

    public static function check_face_approval_returns() {
        return new \external_single_structure([
            'status'     => new \external_value(PARAM_TEXT, 'Status: pending|approved|denied'),
            'reviewnote' => new \external_value(PARAM_TEXT, 'Review note from invigilator', VALUE_OPTIONAL),
            'messages'   => new \external_multiple_structure(
                new \external_single_structure([
                    'id'          => new \external_value(PARAM_INT,  'Message ID'),
                    'sendertype'  => new \external_value(PARAM_TEXT, 'proctor|student'),
                    'message'     => new \external_value(PARAM_RAW,  'Message text'),
                    'timecreated' => new \external_value(PARAM_INT,  'Timestamp'),
                ])
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // action_face_approval  (proctor: approve / deny / message)
    // -----------------------------------------------------------------------

    public static function action_face_approval_parameters() {
        return new \external_function_parameters([
            'approvalid' => new \external_value(PARAM_INT,  'Face approval record ID'),
            'action'     => new \external_value(PARAM_ALPHA,'Action: approve|deny|message'),
            'note'       => new \external_value(PARAM_TEXT, 'Note / message text', VALUE_DEFAULT, ''),
        ]);
    }

    public static function action_face_approval(int $approvalid, string $action, string $note = '') {
        global $USER, $DB;

        $params = self::validate_parameters(self::action_face_approval_parameters(), [
            'approvalid' => $approvalid, 'action' => $action, 'note' => $note,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('quizaccess/learnshield:liveproctor', $context);

        $record = $DB->get_record('quizaccess_learnshield_face_approvals',
            ['id' => $params['approvalid']], '*', MUST_EXIST);

        if ($params['action'] === 'approve' || $params['action'] === 'deny') {
            $DB->update_record('quizaccess_learnshield_face_approvals', (object)[
                'id'           => $record->id,
                'status'       => $params['action'] === 'approve' ? 'approved' : 'denied',
                'reviewedby'   => $USER->id,
                'reviewnote'   => $params['note'],
                'timemodified' => time(),
            ]);
        }

        if ($params['action'] === 'message' && !empty($params['note'])) {
            try {
                $DB->insert_record('quizaccess_learnshield_fa_messages', (object)[
                    'approvalid'  => $record->id,
                    'userid'      => $USER->id,
                    'sendertype'  => 'proctor',
                    'message'     => $params['note'],
                    'timecreated' => time(),
                ]);
            } catch (\Exception $e) {}
        }

        return ['success' => true];
    }

    public static function action_face_approval_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Success'),
        ]);
    }

    // -----------------------------------------------------------------------
    // face_approval_student_message
    // -----------------------------------------------------------------------

    public static function face_approval_student_message_parameters() {
        return new \external_function_parameters([
            'approvalid' => new \external_value(PARAM_INT, 'Face approval record ID'),
            'message'    => new \external_value(PARAM_TEXT,'Message text'),
        ]);
    }

    public static function face_approval_student_message(int $approvalid, string $message) {
        global $USER, $DB;

        $params = self::validate_parameters(self::face_approval_student_message_parameters(), [
            'approvalid' => $approvalid, 'message' => $message,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        $record = $DB->get_record('quizaccess_learnshield_face_approvals',
            ['id' => $params['approvalid'], 'userid' => $USER->id], '*', MUST_EXIST);

        try {
            $DB->insert_record('quizaccess_learnshield_fa_messages', (object)[
                'approvalid'  => $record->id,
                'userid'      => $USER->id,
                'sendertype'  => 'student',
                'message'     => $params['message'],
                'timecreated' => time(),
            ]);
        } catch (\Exception $e) {
            return ['success' => false];
        }

        return ['success' => true];
    }

    public static function face_approval_student_message_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Success'),
        ]);
    }

    // -----------------------------------------------------------------------
    // report_precheck
    // -----------------------------------------------------------------------

    public static function report_precheck_parameters() {
        return new \external_function_parameters([
            'cmid'       => new \external_value(PARAM_INT,  'Course-module ID'),
            'step'       => new \external_value(PARAM_TEXT, 'Step identifier'),
            'stepnumber' => new \external_value(PARAM_INT,  'Step number (1-based)'),
            'totalsteps' => new \external_value(PARAM_INT,  'Total wizard steps'),
        ]);
    }

    public static function report_precheck(int $cmid, string $step, int $stepnumber, int $totalsteps) {
        global $USER, $DB;

        $params = self::validate_parameters(self::report_precheck_parameters(), [
            'cmid' => $cmid, 'step' => $step,
            'stepnumber' => $stepnumber, 'totalsteps' => $totalsteps,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        $now     = time();
        $existing = $DB->get_record('quizaccess_learnshield_precheck_sessions',
            ['cmid' => $params['cmid'], 'userid' => $USER->id]);

        if ($existing) {
            $DB->update_record('quizaccess_learnshield_precheck_sessions', (object)[
                'id'           => $existing->id,
                'current_step' => $params['step'],
                'step_number'  => $params['stepnumber'],
                'total_steps'  => $params['totalsteps'],
                'timemodified' => $now,
            ]);
        } else {
            $DB->insert_record('quizaccess_learnshield_precheck_sessions', (object)[
                'cmid'         => $params['cmid'],
                'userid'       => $USER->id,
                'current_step' => $params['step'],
                'step_number'  => $params['stepnumber'],
                'total_steps'  => $params['totalsteps'],
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }

        return ['success' => true];
    }

    public static function report_precheck_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Success'),
        ]);
    }

    // -----------------------------------------------------------------------
    // upload_audio_clip
    // -----------------------------------------------------------------------

    public static function upload_audio_clip_parameters() {
        return new \external_function_parameters([
            'sessionid'   => new \external_value(PARAM_INT, 'Session ID'),
            'audiodata'   => new \external_value(PARAM_RAW, 'Base64 audio data (data: URI)'),
            'duration'    => new \external_value(PARAM_INT, 'Duration in seconds', VALUE_DEFAULT, 8),
            'noise_level' => new \external_value(PARAM_INT, 'Noise level that triggered recording', VALUE_DEFAULT, 0),
        ]);
    }

    public static function upload_audio_clip(int $sessionid, string $audiodata, int $duration = 8, int $noise_level = 0) {
        global $USER, $DB;

        $params = self::validate_parameters(self::upload_audio_clip_parameters(), [
            'sessionid' => $sessionid, 'audiodata' => $audiodata,
            'duration' => $duration, 'noise_level' => $noise_level,
        ]);

        // Students call this — only require login.
        $context = \context_system::instance();
        self::validate_context($context);

        try {
            $DB->insert_record('quizaccess_learnshield_audio_clips', (object)[
                'sessionid'   => $params['sessionid'],
                'userid'      => $USER->id,
                'audiodata'   => $params['audiodata'],
                'duration'    => $params['duration'],
                'noise_level' => $params['noise_level'],
                'timecreated' => time(),
            ]);
        } catch (\Exception $e) {
            return ['success' => false];
        }

        return ['success' => true];
    }

    public static function upload_audio_clip_returns() {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL, 'Success'),
        ]);
    }
}
