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
 * External (web service) functions for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace mod_learnguard;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->libdir . '/filelib.php');

/**
 * External functions for LearnGuard SPA communication.
 */
class external extends \external_api {

    // -----------------------------------------------------------------------
    // start_attempt
    // -----------------------------------------------------------------------

    public static function start_attempt_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'cmid' => new \external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    public static function start_attempt(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::start_attempt_parameters(), ['cmid' => $cmid]);
        $cmid   = $params['cmid'];

        $cm       = get_coursemodule_from_id('learnguard', $cmid, 0, false, MUST_EXIST);
        $course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $attempt = attempt_manager::start_or_resume($instance, $cm, $USER);

        $nav = attempt_manager::get_navigation_summary($attempt);

        return [
            'attemptid'      => (int)$attempt->id,
            'currentslot'    => (int)($attempt->currentpage > 0 ? $attempt->currentpage : 1),
            'totalquestions' => count($nav),
            'timelimit'      => (int)$instance->timelimit,
            'timestart'      => (int)$attempt->timestart,
            'navigation'     => array_map(fn($n) => [
                'slot'     => (int)$n['slot'],
                'state'    => (string)$n['state'],
                'answered' => (bool)$n['answered'],
                'maxmark'  => (float)$n['maxmark'],
            ], $nav),
        ];
    }

    public static function start_attempt_returns(): \external_single_structure {
        return new \external_single_structure([
            'attemptid'      => new \external_value(PARAM_INT),
            'currentslot'    => new \external_value(PARAM_INT),
            'totalquestions' => new \external_value(PARAM_INT),
            'timelimit'      => new \external_value(PARAM_INT),
            'timestart'      => new \external_value(PARAM_INT),
            'navigation'     => new \external_multiple_structure(
                new \external_single_structure([
                    'slot'     => new \external_value(PARAM_INT),
                    'state'    => new \external_value(PARAM_TEXT),
                    'answered' => new \external_value(PARAM_BOOL),
                    'maxmark'  => new \external_value(PARAM_FLOAT),
                ])
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // get_question
    // -----------------------------------------------------------------------

    public static function get_question_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT, 'Attempt ID'),
            'slot'      => new \external_value(PARAM_INT, '1-based slot number'),
            'review'    => new \external_value(PARAM_BOOL, 'Show feedback (review mode)', VALUE_DEFAULT, false),
        ]);
    }

    public static function get_question(int $attemptid, int $slot, bool $review = false): array {
        global $DB;

        $params = self::validate_parameters(self::get_question_parameters(), [
            'attemptid' => $attemptid,
            'slot'      => $slot,
            'review'    => $review,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $result = attempt_manager::get_question_html($attempt, $params['slot'], $params['review']);

        return [
            'html'           => $result['html'],
            'slot'           => (int)$result['slot'],
            'questionnumber' => (int)$result['questionnumber'],
            'totalquestions' => (int)$result['totalquestions'],
            'isfinished'     => (bool)$result['isfinished'],
            'maxmark'        => (float)$result['maxmark'],
        ];
    }

    public static function get_question_returns(): \external_single_structure {
        return new \external_single_structure([
            'html'           => new \external_value(PARAM_RAW),
            'slot'           => new \external_value(PARAM_INT),
            'questionnumber' => new \external_value(PARAM_INT),
            'totalquestions' => new \external_value(PARAM_INT),
            'isfinished'     => new \external_value(PARAM_BOOL),
            'maxmark'        => new \external_value(PARAM_FLOAT),
        ]);
    }

    // -----------------------------------------------------------------------
    // submit_answer
    // -----------------------------------------------------------------------

    public static function submit_answer_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid'    => new \external_value(PARAM_INT, 'Attempt ID'),
            'slot'         => new \external_value(PARAM_INT, '1-based slot'),
            'responsedata' => new \external_multiple_structure(
                new \external_single_structure([
                    'name'  => new \external_value(PARAM_RAW),
                    'value' => new \external_value(PARAM_RAW),
                ])
            ),
        ]);
    }

    public static function submit_answer(int $attemptid, int $slot, array $responsedata): array {
        global $DB;

        $params = self::validate_parameters(self::submit_answer_parameters(), [
            'attemptid'    => $attemptid,
            'slot'         => $slot,
            'responsedata' => $responsedata,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        if ($attempt->state !== 'inprogress') {
            throw new \moodle_exception('error:attemptclosed', 'learnguard');
        }

        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        // Build responsedata as flat array.
        $response = [];
        foreach ($params['responsedata'] as $kv) {
            $response[$kv['name']] = $kv['value'];
        }

        $nextslot = attempt_manager::process_answer($attempt, $params['slot'], $response);

        return [
            'nextslot'  => (int)$nextslot,
            'finished'  => ($nextslot === 0),
        ];
    }

    public static function submit_answer_returns(): \external_single_structure {
        return new \external_single_structure([
            'nextslot' => new \external_value(PARAM_INT),
            'finished' => new \external_value(PARAM_BOOL),
        ]);
    }

    // -----------------------------------------------------------------------
    // autosave
    // -----------------------------------------------------------------------

    public static function autosave_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid'    => new \external_value(PARAM_INT),
            'slot'         => new \external_value(PARAM_INT),
            'responsedata' => new \external_multiple_structure(
                new \external_single_structure([
                    'name'  => new \external_value(PARAM_RAW),
                    'value' => new \external_value(PARAM_RAW),
                ])
            ),
        ]);
    }

    public static function autosave(int $attemptid, int $slot, array $responsedata): array {
        global $DB;

        $params = self::validate_parameters(self::autosave_parameters(), [
            'attemptid'    => $attemptid,
            'slot'         => $slot,
            'responsedata' => $responsedata,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        if ($attempt->state !== 'inprogress') {
            return ['status' => 'closed'];
        }

        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $response = [];
        foreach ($params['responsedata'] as $kv) {
            $response[$kv['name']] = $kv['value'];
        }

        attempt_manager::autosave($attempt, $params['slot'], $response);

        return ['status' => 'ok'];
    }

    public static function autosave_returns(): \external_single_structure {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_TEXT),
        ]);
    }

    // -----------------------------------------------------------------------
    // finish_attempt
    // -----------------------------------------------------------------------

    public static function finish_attempt_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
        ]);
    }

    public static function finish_attempt(int $attemptid): array {
        global $DB;

        $params  = self::validate_parameters(self::finish_attempt_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);

        if ($attempt->state === 'finished') {
            $cm       = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
            $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
            $context  = \context_module::instance($cm->id);
            self::validate_context($context);
            return [
                'grade'      => (float)($attempt->sumgrades ?? 0),
                'maxgrade'   => (float)($instance->grade ?? 100),
                'attemptno'  => (int)$attempt->attempt,
                'timefinish' => (int)($attempt->timefinish ?? time()),
            ];
        }

        $cm       = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
        $context  = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $grade = attempt_manager::finish($attempt, $instance);

        // Respect result visibility setting.
        $showgrade = (!isset($instance->show_results) || $instance->show_results == 1)
            || ($instance->results_released > 0);

        return [
            'grade'        => $showgrade ? (float)$grade : -1.0,
            'maxgrade'     => (float)($instance->grade ?? 100),
            'attemptno'    => (int)$attempt->attempt,
            'timefinish'   => (int)time(),
            'resultsheld'  => !$showgrade,
        ];
    }

    public static function finish_attempt_returns(): \external_single_structure {
        return new \external_single_structure([
            'grade'       => new \external_value(PARAM_FLOAT),
            'maxgrade'    => new \external_value(PARAM_FLOAT),
            'attemptno'   => new \external_value(PARAM_INT),
            'timefinish'  => new \external_value(PARAM_INT),
            'resultsheld' => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
        ]);
    }

    // -----------------------------------------------------------------------
    // get_attempt_state
    // -----------------------------------------------------------------------

    public static function get_attempt_state_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
        ]);
    }

    public static function get_attempt_state(int $attemptid): array {
        global $DB;

        $params  = self::validate_parameters(self::get_attempt_state_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);

        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $nav = ($attempt->state === 'inprogress') ? attempt_manager::get_navigation_summary($attempt) : [];

        return [
            'state'        => (string)$attempt->state,
            'currentslot'  => (int)($attempt->currentpage ?: 1),
            'risk_score'   => (int)$attempt->risk_score,
            'risk_level'   => (string)$attempt->risk_level,
            'suspended'    => (bool)($attempt->state === 'suspended'),
            'navigation'   => array_map(fn($n) => [
                'slot'     => (int)$n['slot'],
                'state'    => (string)$n['state'],
                'answered' => (bool)$n['answered'],
                'maxmark'  => (float)$n['maxmark'],
            ], $nav),
        ];
    }

    public static function get_attempt_state_returns(): \external_single_structure {
        return new \external_single_structure([
            'state'       => new \external_value(PARAM_TEXT),
            'currentslot' => new \external_value(PARAM_INT),
            'risk_score'  => new \external_value(PARAM_INT),
            'risk_level'  => new \external_value(PARAM_TEXT),
            'suspended'   => new \external_value(PARAM_BOOL),
            'navigation'  => new \external_multiple_structure(
                new \external_single_structure([
                    'slot'     => new \external_value(PARAM_INT),
                    'state'    => new \external_value(PARAM_TEXT),
                    'answered' => new \external_value(PARAM_BOOL),
                    'maxmark'  => new \external_value(PARAM_FLOAT),
                ])
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // send_snapshot
    // -----------------------------------------------------------------------

    public static function send_snapshot_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid'  => new \external_value(PARAM_INT),
            'imagedata'  => new \external_value(PARAM_RAW, 'Base64-encoded JPEG'),
            'event_type' => new \external_value(PARAM_ALPHA, 'Snapshot trigger type', VALUE_DEFAULT, 'periodic'),
        ]);
    }

    public static function send_snapshot(int $attemptid, string $imagedata, string $event_type = 'periodic'): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::send_snapshot_parameters(), [
            'attemptid'  => $attemptid,
            'imagedata'  => $imagedata,
            'event_type' => $event_type,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        // Strip the base64 header if present.
        $raw = $params['imagedata'];
        if (strpos($raw, ',') !== false) {
            $raw = substr($raw, strpos($raw, ',') + 1);
        }
        $binary = base64_decode($raw, true);
        if ($binary === false || strlen($binary) < 100) {
            return ['status' => 'invalid'];
        }

        $DB->insert_record('learnguard_snapshots', (object)[
            'attemptid'     => $attempt->id,
            'userid'        => $USER->id,
            'snapshot_url'  => base64_encode($binary),
            'snapshot_type' => $params['event_type'],
            'facefound'     => 0,
            'awsscore'      => 0,
            'timemodified'  => time(),
        ]);

        return ['status' => 'ok'];
    }

    public static function send_snapshot_returns(): \external_single_structure {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_TEXT),
        ]);
    }

    // -----------------------------------------------------------------------
    // log_event
    // -----------------------------------------------------------------------

    public static function log_event_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid'   => new \external_value(PARAM_INT),
            'eventtype'   => new \external_value(PARAM_ALPHANUMEXT),
            'eventdata'   => new \external_value(PARAM_RAW, 'JSON payload', VALUE_DEFAULT, '{}'),
            'severity'    => new \external_value(PARAM_ALPHA, 'low|medium|high', VALUE_DEFAULT, 'low'),
            'scoreimpact' => new \external_value(PARAM_INT, 'Score change', VALUE_DEFAULT, 0),
        ]);
    }

    public static function log_event(
        int $attemptid, string $eventtype, string $eventdata, string $severity, int $scoreimpact
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::log_event_parameters(), [
            'attemptid'   => $attemptid,
            'eventtype'   => $eventtype,
            'eventdata'   => $eventdata,
            'severity'    => $severity,
            'scoreimpact' => $scoreimpact,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        attempt_manager::log_event(
            $params['attemptid'],
            $USER->id,
            $params['eventtype'],
            $params['eventdata'],
            $params['severity'],
            $params['scoreimpact']
        );

        // Re-read for current risk state.
        $updated = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']]);

        return [
            'status'     => 'ok',
            'risk_score' => (int)($updated->risk_score ?? 0),
            'risk_level' => (string)($updated->risk_level ?? 'low'),
        ];
    }

    public static function log_event_returns(): \external_single_structure {
        return new \external_single_structure([
            'status'     => new \external_value(PARAM_TEXT),
            'risk_score' => new \external_value(PARAM_INT),
            'risk_level' => new \external_value(PARAM_TEXT),
        ]);
    }

    // -----------------------------------------------------------------------
    // get_messages
    // -----------------------------------------------------------------------

    public static function get_messages_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
            'since'     => new \external_value(PARAM_INT, 'Timestamp to fetch messages after', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_messages(int $attemptid, int $since = 0): array {
        global $DB;

        $params = self::validate_parameters(self::get_messages_parameters(), [
            'attemptid' => $attemptid,
            'since'     => $since,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $updated = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']]);

        return [
            'suspended'  => ($updated->state === 'suspended'),
            'risk_score' => (int)($updated->risk_score ?? 0),
            'risk_level' => (string)($updated->risk_level ?? 'low'),
            'messages'   => [],
        ];
    }

    public static function get_messages_returns(): \external_single_structure {
        return new \external_single_structure([
            'suspended'  => new \external_value(PARAM_BOOL),
            'risk_score' => new \external_value(PARAM_INT),
            'risk_level' => new \external_value(PARAM_TEXT),
            'messages'   => new \external_multiple_structure(
                new \external_single_structure([
                    'id'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                    'message' => new \external_value(PARAM_TEXT, '', VALUE_OPTIONAL),
                    'time'    => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
                ])
            ),
        ]);
    }

    // -----------------------------------------------------------------------
    // get_live_sessions
    // -----------------------------------------------------------------------

    public static function get_live_sessions_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'cmid' => new \external_value(PARAM_INT, 'Course module ID'),
        ]);
    }

    public static function get_live_sessions(int $cmid): array {
        global $DB;

        $params = self::validate_parameters(self::get_live_sessions_parameters(), ['cmid' => $cmid]);

        $cm      = get_coursemodule_from_id('learnguard', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:liveproctor', $context);

        $cutoff  = time() - 120; // Active in last 2 minutes.
        $records = $DB->get_records_sql(
            "SELECT a.id, a.userid, a.attempt, a.state, a.risk_score, a.risk_level,
                    a.timestart, a.timemodified, a.currentpage,
                    u.firstname, u.lastname, u.email
               FROM {learnguard_attempts} a
               JOIN {user} u ON u.id = a.userid
              WHERE a.learnguard = :lgid
                AND a.state = 'inprogress'
                AND a.timemodified >= :cutoff
           ORDER BY a.timemodified DESC",
            ['lgid' => $cm->instance, 'cutoff' => $cutoff]
        );

        $sessions = [];
        foreach ($records as $r) {
            $sessions[] = [
                'attemptid'   => (int)$r->id,
                'userid'      => (int)$r->userid,
                'fullname'    => fullname($r),
                'email'       => (string)$r->email,
                'attempt'     => (int)$r->attempt,
                'state'       => (string)$r->state,
                'risk_score'  => (int)$r->risk_score,
                'risk_level'  => (string)$r->risk_level,
                'currentpage' => (int)$r->currentpage,
                'timestart'   => (int)$r->timestart,
                'lastseen'    => (int)$r->timemodified,
            ];
        }

        return ['sessions' => $sessions];
    }

    public static function get_live_sessions_returns(): \external_single_structure {
        return new \external_single_structure([
            'sessions' => new \external_multiple_structure(
                new \external_single_structure([
                    'attemptid'   => new \external_value(PARAM_INT),
                    'userid'      => new \external_value(PARAM_INT),
                    'fullname'    => new \external_value(PARAM_TEXT),
                    'email'       => new \external_value(PARAM_EMAIL),
                    'attempt'     => new \external_value(PARAM_INT),
                    'state'       => new \external_value(PARAM_TEXT),
                    'risk_score'  => new \external_value(PARAM_INT),
                    'risk_level'  => new \external_value(PARAM_TEXT),
                    'currentpage' => new \external_value(PARAM_INT),
                    'timestart'   => new \external_value(PARAM_INT),
                    'lastseen'    => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }
}
