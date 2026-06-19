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

        // Check availability window — takes any personal schedule
        // (learnguard_schedules) into account, falling back to the instance window.
        if (!\learnguard_is_available_for_user($instance, $USER->id)) {
            throw new \moodle_exception('error:notavailable', 'learnguard');
        }

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
            'targetslot'   => new \external_value(PARAM_INT,
                'Slot the student is navigating to (0 = default sequential next)',
                VALUE_DEFAULT, 0),
        ]);
    }

    public static function submit_answer(int $attemptid, int $slot, array $responsedata, int $targetslot = 0): array {
        global $DB;

        $params = self::validate_parameters(self::submit_answer_parameters(), [
            'attemptid'    => $attemptid,
            'slot'         => $slot,
            'responsedata' => $responsedata,
            'targetslot'   => $targetslot,
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

        $nextslot = attempt_manager::process_answer($attempt, $params['slot'], $response, $params['targetslot']);

        // Detect whether the student actually gave an answer (not just navigated).
        // Reads the saved response state: 'noresponse'/'todo' means unanswered.
        $aq      = $DB->get_record('learnguard_attempt_questions', ['attemptid' => $attempt->id, 'slotnum' => $params['slot']]);
        $answered = false;
        if ($aq) {
            $savedResp = $DB->get_record('learnguard_responses', ['attemptid' => $attempt->id, 'slotid' => $aq->slotid]);
            $answered  = $savedResp && !in_array($savedResp->state, ['todo', 'noresponse']);
        }

        return [
            'nextslot'  => (int)$nextslot,
            'finished'  => ($nextslot === 0),
            'answered'  => (bool)$answered,
        ];
    }

    public static function submit_answer_returns(): \external_single_structure {
        return new \external_single_structure([
            'nextslot' => new \external_value(PARAM_INT),
            'finished' => new \external_value(PARAM_BOOL),
            'answered' => new \external_value(PARAM_BOOL, 'Whether the student actually submitted an answer'),
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
            'state'         => (string)$attempt->state,
            'currentslot'   => (int)($attempt->currentpage ?: 1),
            'risk_score'    => (int)$attempt->risk_score,
            'risk_level'    => (string)$attempt->risk_level,
            'suspended'     => (bool)($attempt->state === 'suspended'),
            'timestart'     => (int)($attempt->timestart ?? 0),
            'timesuspended' => (int)($attempt->timesuspended ?? 0),
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
            'state'         => new \external_value(PARAM_TEXT),
            'currentslot'   => new \external_value(PARAM_INT),
            'risk_score'    => new \external_value(PARAM_INT),
            'risk_level'    => new \external_value(PARAM_TEXT),
            'suspended'     => new \external_value(PARAM_BOOL),
            'timestart'     => new \external_value(PARAM_INT),
            'timesuspended' => new \external_value(PARAM_INT, '', VALUE_DEFAULT, 0),
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
    // extend_attempt_time
    // Push learnguard_attempts.timestart forward so the timer does not charge
    // the student for time spent suspended / in reinstatement precheck.
    // -----------------------------------------------------------------------

    public static function extend_attempt_time_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid'    => new \external_value(PARAM_INT, 'Attempt ID'),
            'extra_seconds' => new \external_value(PARAM_INT, 'Seconds to add to timestart'),
        ]);
    }

    public static function extend_attempt_time(int $attemptid, int $extra_seconds): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::extend_attempt_time_parameters(), [
            'attemptid'    => $attemptid,
            'extra_seconds' => $extra_seconds,
        ]);
        $attempt = $DB->get_record('learnguard_attempts',
            ['id' => $params['attemptid'], 'userid' => $USER->id], '*', MUST_EXIST);
        if (in_array($attempt->state, ['inprogress', 'suspended'])) {
            // If caller passes 0, compute from the stored suspension timestamp.
            $extra = ($params['extra_seconds'] > 0)
                ? $params['extra_seconds']
                : (!empty($attempt->timesuspended) ? (time() - (int)$attempt->timesuspended) : 0);
            if ($extra > 0) {
                $DB->set_field('learnguard_attempts', 'timestart',
                    $attempt->timestart + $extra, ['id' => $attempt->id]);
            }
            // Always clear timesuspended once the extension is applied so it
            // cannot be double-counted if the WS is called more than once.
            $DB->set_field('learnguard_attempts', 'timesuspended', 0, ['id' => $attempt->id]);
        }
        return ['success' => true];
    }

    public static function extend_attempt_time_returns(): \external_single_structure {
        return new \external_single_structure(['success' => new \external_value(PARAM_BOOL)]);
    }

    // -----------------------------------------------------------------------
    // send_snapshot
    // -----------------------------------------------------------------------

    public static function send_snapshot_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid'  => new \external_value(PARAM_INT),
            'imagedata'  => new \external_value(PARAM_RAW, 'Base64-encoded JPEG'),
            'event_type' => new \external_value(PARAM_ALPHANUMEXT, 'Snapshot trigger type', VALUE_DEFAULT, 'periodic'),
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
    // upload_360 — store 360° environment recording video
    // -----------------------------------------------------------------------

    public static function upload_360_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
            'videodata' => new \external_value(PARAM_RAW, 'Base64-encoded WebM video'),
            'duration'  => new \external_value(PARAM_INT, 'Recording duration in seconds', VALUE_DEFAULT, 0),
        ]);
    }

    public static function upload_360(int $attemptid, string $videodata, int $duration = 0): array {
        global $DB, $USER;

        $params  = self::validate_parameters(self::upload_360_parameters(),
            ['attemptid' => $attemptid, 'videodata' => $videodata, 'duration' => $duration]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        // Decode base64 video data.
        $raw = $params['videodata'];
        if (strpos($raw, ',') !== false) { $raw = substr($raw, strpos($raw, ',') + 1); }
        $binary = base64_decode($raw, true);
        if ($binary === false || strlen($binary) < 100) {
            return ['status' => 'invalid', 'size' => 0];
        }

        // Store the video in learnguard_snapshots using snapshot_type = '360_video'.
        // snapshot_url stores the base64-encoded video binary (same pattern as webcam snapshots).
        $DB->insert_record('learnguard_snapshots', (object)[
            'attemptid'     => $attempt->id,
            'userid'        => $USER->id,
            'snapshot_url'  => base64_encode($binary),
            'snapshot_type' => '360_video',
            'facefound'     => $params['duration'],  // Repurpose facefound to store duration.
            'awsscore'      => strlen($binary),       // Store file size in bytes.
            'timemodified'  => time(),
        ]);

        return ['status' => 'ok', 'size' => strlen($binary)];
    }

    public static function upload_360_returns(): \external_single_structure {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_TEXT),
            'size'   => new \external_value(PARAM_INT),
        ]);
    }

    // -----------------------------------------------------------------------
    // log_event
    // -----------------------------------------------------------------------

    // =======================================================================
    // Auto-suspend (called from student browser when risk threshold exceeded)
    // =======================================================================

    public static function auto_suspend_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
            'reason'    => new \external_value(PARAM_TEXT, 'Reason', VALUE_DEFAULT, 'Risk threshold exceeded'),
        ]);
    }

    public static function auto_suspend(int $attemptid, string $reason = 'Risk threshold exceeded'): array {
        global $DB;
        $params  = self::validate_parameters(self::auto_suspend_parameters(), ['attemptid' => $attemptid, 'reason' => $reason]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        // Only suspend if still in progress.
        if ($attempt->state === 'inprogress') {
            $DB->set_field('learnguard_attempts', 'state',         'suspended', ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'timesuspended', time(),      ['id' => $attempt->id]);
            // Log the suspension event.
            $DB->insert_record('learnguard_events', (object)[
                'attemptid'   => $attempt->id,
                'userid'      => $attempt->userid,
                'event_type'  => 'auto_suspended',
                'severity'    => 'high',
                'score_impact'=> 0,
                'event_data'  => json_encode(['reason' => $params['reason']]),
                'timecreated' => time(),
                'timemodified'=> time(),
            ]);
            // Email all assigned proctors about the auto-suspension.
            require_once($CFG->dirroot . '/mod/learnguard/lib.php');
            learnguard_notify_proctors_event(
                $attempt->id,
                'auto_suspended',
                $params['reason']
            );
        }
        return ['success' => true];
    }

    public static function auto_suspend_returns(): \external_single_structure {
        return new \external_single_structure(['success' => new \external_value(PARAM_BOOL)]);
    }

    // =======================================================================
    // PRECHECK REPORTING — wizard step tracking for proctor view
    // =======================================================================

    public static function report_precheck_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'cmid'        => new \external_value(PARAM_INT),
            'step'        => new \external_value(PARAM_ALPHANUMEXT, 'Current step ID'),
            'stepnumber'  => new \external_value(PARAM_INT, 'Step index (0-based)', VALUE_DEFAULT, 0),
            'totalsteps'  => new \external_value(PARAM_INT, 'Total steps', VALUE_DEFAULT, 14),
        ]);
    }

    public static function report_precheck(int $cmid, string $step, int $stepnumber = 0, int $totalsteps = 14): array {
        global $DB, $USER;
        $params = self::validate_parameters(self::report_precheck_parameters(),
            ['cmid' => $cmid, 'step' => $step, 'stepnumber' => $stepnumber, 'totalsteps' => $totalsteps]);

        $cm      = get_coursemodule_from_id('learnguard', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        try {
            $existing = $DB->get_record('learnguard_precheck_sessions',
                ['cmid' => $cm->id, 'userid' => $USER->id]);

            $record = (object)[
                'cmid'         => $cm->id,
                'userid'       => $USER->id,
                'current_step' => $params['step'],
                'step_number'  => $params['stepnumber'],
                'total_steps'  => $params['totalsteps'],
                'timemodified' => time(),
            ];

            if ($existing) {
                $record->id = $existing->id;
                $DB->update_record('learnguard_precheck_sessions', $record);
            } else {
                $record->timecreated = time();
                $DB->insert_record('learnguard_precheck_sessions', $record);
            }
        } catch (\Exception $e) { /* Table may not exist yet */ }

        return ['success' => true];
    }

    public static function report_precheck_returns(): \external_single_structure {
        return new \external_single_structure(['success' => new \external_value(PARAM_BOOL)]);
    }

    // =======================================================================
    // FACE APPROVAL — pre-exam identity verification approval flow
    // No attemptid needed — keyed by cmid + current user.
    // =======================================================================

    public static function request_face_approval_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'cmid'        => new \external_value(PARAM_INT),
            'passportdata'=> new \external_value(PARAM_RAW,  'Base64 passport photo', VALUE_DEFAULT, ''),
            'matchpct'    => new \external_value(PARAM_INT,  'Best match % achieved', VALUE_DEFAULT, 0),
        ]);
    }

    public static function request_face_approval(int $cmid, string $passportdata = '', int $matchpct = 0): array {
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::request_face_approval_parameters(), [
            'cmid' => $cmid, 'passportdata' => $passportdata, 'matchpct' => $matchpct,
        ]);

        $cm      = get_coursemodule_from_id('learnguard', $params['cmid'], 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        // Upsert: one pending request per cmid+userid (replace any existing record).
        $existing = $DB->get_record('learnguard_face_approvals',
            ['cmid' => $cm->id, 'userid' => $USER->id]);

        $record = (object)[
            'cmid'         => $cm->id,
            'userid'       => $USER->id,
            'status'       => 'pending',
            'passportdata' => $params['passportdata'],
            'matchpct'     => min(100, max(0, (int)$params['matchpct'])),
            'reviewedby'   => null,
            'reviewnote'   => '',
            'timecreated'  => $existing ? (int)$existing->timecreated : time(),
            'timemodified' => time(),
        ];

        if ($existing) {
            $record->id = $existing->id;
            $DB->update_record('learnguard_face_approvals', $record);
            $approvalId = $existing->id;
        } else {
            $approvalId = $DB->insert_record('learnguard_face_approvals', $record);
        }

        // Email all proctors assigned to this assessment.
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');
        $instance = $DB->get_record('learnguard', ['id' => $cm->instance]);
        $proctors = learnguard_get_all_proctors($cm->instance, $cm->id);
        $student  = $USER;
        $dashUrl  = (new \moodle_url('/mod/learnguard/face_approvals.php', ['id' => $cm->id]))->out(false);

        $emailSubject = '⚠️ Face Match Approval Required — ' . fullname($student);
        $emailBody =
            "A student requires invigilator approval before starting an exam.\n\n" .
            "Student    : " . fullname($student) . " <" . $student->email . ">\n" .
            "Assessment : " . format_string($instance->name) . "\n" .
            "Best match : " . $params['matchpct'] . "%  (threshold: 70%)\n" .
            "Time       : " . userdate(time()) . "\n\n" .
            "Please review and approve or deny at:\n" . $dashUrl . "\n\n" .
            "The student is waiting and cannot start the exam until you act.\n\n" .
            "LearnGuard — Secure Assessment Platform";

        foreach ($proctors as $p) {
            learnguard_send_email($p, $emailSubject, $emailBody);
        }

        return ['approvalid' => (int)$approvalId, 'status' => 'pending'];
    }

    public static function request_face_approval_returns(): \external_single_structure {
        return new \external_single_structure([
            'approvalid' => new \external_value(PARAM_INT),
            'status'     => new \external_value(PARAM_TEXT),
        ]);
    }

    // ── Check approval status (student polls this every 8s) ────────────────

    public static function check_face_approval_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'cmid' => new \external_value(PARAM_INT),
        ]);
    }

    public static function check_face_approval(int $cmid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::check_face_approval_parameters(), ['cmid' => $cmid]);
        $cm     = get_coursemodule_from_id('learnguard', $params['cmid'], 0, false, MUST_EXIST);
        $context= \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $record = $DB->get_record('learnguard_face_approvals',
            ['cmid' => $cm->id, 'userid' => $USER->id]);

        // Also fetch any proctor messages sent to this approval request.
        $messages = [];
        if ($record) {
            $msgRows = $DB->get_records_sql(
                "SELECT m.id, m.message, m.sendertype, m.timecreated,
                        u.firstname, u.lastname
                   FROM {learnguard_fa_messages} m
                   JOIN {user} u ON u.id = m.userid
                  WHERE m.approvalid = :aid
               ORDER BY m.timecreated ASC",
                ['aid' => $record->id]
            );
            foreach ($msgRows as $m) {
                $messages[] = [
                    'message'    => (string)$m->message,
                    'sendertype' => (string)$m->sendertype,
                    'sendername' => fullname($m),
                    'timecreated'=> (int)$m->timecreated,
                ];
            }
        }

        return [
            'status'     => $record ? (string)$record->status : 'not_requested',
            'reviewnote' => $record ? (string)($record->reviewnote ?? '') : '',
            'messages'   => $messages,
        ];
    }

    public static function check_face_approval_returns(): \external_single_structure {
        return new \external_single_structure([
            'status'     => new \external_value(PARAM_TEXT),
            'reviewnote' => new \external_value(PARAM_TEXT),
            'messages'   => new \external_multiple_structure(
                new \external_single_structure([
                    'message'    => new \external_value(PARAM_TEXT),
                    'sendertype' => new \external_value(PARAM_TEXT),
                    'sendername' => new \external_value(PARAM_TEXT),
                    'timecreated'=> new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    // ── Proctor action: approve / deny / send message ──────────────────────

    public static function action_face_approval_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'approvalid' => new \external_value(PARAM_INT),
            'action'     => new \external_value(PARAM_ALPHA, 'approve|deny|message'),
            'note'       => new \external_value(PARAM_TEXT, 'Note / message text', VALUE_DEFAULT, ''),
        ]);
    }

    public static function action_face_approval(int $approvalid, string $action, string $note = ''): array {
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::action_face_approval_parameters(), [
            'approvalid' => $approvalid, 'action' => $action, 'note' => $note,
        ]);

        $record = $DB->get_record('learnguard_face_approvals', ['id' => $params['approvalid']], '*', MUST_EXIST);
        $cm     = get_coursemodule_from_id('learnguard', $record->cmid, 0, false, MUST_EXIST);
        $context= \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:liveproctor', $context);

        if ($params['action'] === 'approve' || $params['action'] === 'deny') {
            $DB->update_record('learnguard_face_approvals', (object)[
                'id'          => $record->id,
                'status'      => $params['action'] === 'approve' ? 'approved' : 'denied',
                'reviewedby'  => $USER->id,
                'reviewnote'  => $params['note'],
                'timemodified'=> time(),
            ]);

            // Notify the student via email.
            require_once($CFG->dirroot . '/mod/learnguard/lib.php');
            $student  = $DB->get_record('user', ['id' => $record->userid]);
            $instance = $DB->get_record('learnguard', ['id' => $cm->instance]);
            if ($student && $instance) {
                $viewUrl = (new \moodle_url('/mod/learnguard/view.php', ['id' => $cm->id]))->out(false);
                $approved = $params['action'] === 'approve';
                learnguard_send_email(
                    $student,
                    ($approved ? '✅' : '❌') . ' Face Verification ' . ($approved ? 'Approved' : 'Denied') .
                        ' — ' . format_string($instance->name),
                    "Dear " . fullname($student) . ",\n\n" .
                    ($approved
                        ? "Your identity verification has been approved by the invigilator.\nYou may now start your exam:\n" . $viewUrl
                        : "Your identity verification could not be approved.\nReason: " . ($params['note'] ?: 'Not specified') .
                          "\nPlease contact your instructor for assistance.") .
                    "\n\nLearnGuard — Secure Assessment Platform"
                );
            }
        }

        if ($params['action'] === 'message') {
            // Proctor sends a message to the waiting student.
            // Check table exists (created by install.xml or upgrade).
            $DB->insert_record('learnguard_fa_messages', (object)[
                'approvalid'  => $record->id,
                'userid'      => $USER->id,
                'sendertype'  => 'proctor',
                'message'     => $params['note'],
                'timecreated' => time(),
            ]);
        }

        return ['success' => true, 'status' => $params['action'] === 'message'
            ? (string)$record->status
            : ($params['action'] === 'approve' ? 'approved' : 'denied')];
    }

    // Student sends a message to proctor while waiting.
    public static function action_face_approval_returns(): \external_single_structure {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL),
            'status'  => new \external_value(PARAM_TEXT),
        ]);
    }

    // ── Student sends message to proctor while waiting ─────────────────────

    public static function face_approval_student_message_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'cmid'    => new \external_value(PARAM_INT),
            'message' => new \external_value(PARAM_TEXT),
        ]);
    }

    public static function face_approval_student_message(int $cmid, string $message): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::face_approval_student_message_parameters(),
            ['cmid' => $cmid, 'message' => $message]);

        $cm     = get_coursemodule_from_id('learnguard', $params['cmid'], 0, false, MUST_EXIST);
        $context= \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $record = $DB->get_record('learnguard_face_approvals',
            ['cmid' => $cm->id, 'userid' => $USER->id], '*', MUST_EXIST);

        $DB->insert_record('learnguard_fa_messages', (object)[
            'approvalid'  => $record->id,
            'userid'      => $USER->id,
            'sendertype'  => 'student',
            'message'     => $params['message'],
            'timecreated' => time(),
        ]);

        return ['success' => true];
    }

    public static function face_approval_student_message_returns(): \external_single_structure {
        return new \external_single_structure(['success' => new \external_value(PARAM_BOOL)]);
    }

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

        // Touch attempt timemodified so live monitor knows learner is still active.
        $DB->set_field('learnguard_attempts', 'timemodified', time(), ['id' => $params['attemptid']]);

        // Re-read for current risk state.
        $updated = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']]);

        // Email proctors on high-severity events, face mismatch, SOS, or chat requests.
        $notifyEvents = ['face_mismatch_approval_pending', 'auto_suspended', 'screen_share_stopped', 'tech_difficulty'];
        $shouldNotify = (get_config('learnguard', 'notify_proctors_enabled') &&
            ($params['severity'] === 'high' || in_array($params['eventtype'], $notifyEvents)));
        if ($shouldNotify) {
            require_once($CFG->dirroot . '/mod/learnguard/lib.php');
            learnguard_notify_proctors_event($params['attemptid'], $params['eventtype']);
        }

        // Read current admin settings so the client can apply any live changes
        // (e.g. admin adjusted autosuspend_score during an ongoing attempt).
        $autosuspendScore = (int)(get_config('learnguard', 'autosuspend_score') ?: 80);
        $scoreConfig = json_encode([
            'facemissing'  => (int)(get_config('learnguard', 'score_facemissing') ?: 10),
            'faceanomaly'  => (int)(get_config('learnguard', 'score_faceanomaly') ?: 10),
            'audioclip'    => (int)(get_config('learnguard', 'score_audio_clip')  ?: 5),
        ]);

        return [
            'status'            => 'ok',
            'risk_score'        => (int)($updated->risk_score ?? 0),
            'risk_level'        => (string)($updated->risk_level ?? 'low'),
            'autosuspend_score' => $autosuspendScore,
            'scoreconfig'       => $scoreConfig,
        ];
    }

    public static function log_event_returns(): \external_single_structure {
        return new \external_single_structure([
            'status'            => new \external_value(PARAM_TEXT),
            'risk_score'        => new \external_value(PARAM_INT),
            'risk_level'        => new \external_value(PARAM_TEXT),
            'autosuspend_score' => new \external_value(PARAM_INT,  '', VALUE_OPTIONAL),
            'scoreconfig'       => new \external_value(PARAM_RAW,  '', VALUE_OPTIONAL),
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

        // Check emergency pause (per-assessment or global).
        $paused = (bool)get_config('learnguard', 'paused_' . $attempt->learnguard)
               || (bool)get_config('learnguard', 'global_pause');

        // Phase 3: include break/extra_time status and new chat messages.
        $breakApproved  = !empty($updated->break_approved)  ? (int)$updated->break_approved  : 0;
        $breakRequested = !empty($updated->break_requested) ? (int)$updated->break_requested : 0;
        $extraTime      = !empty($updated->extra_time)      ? (int)$updated->extra_time      : 0;

        // New chat messages for the student (since last poll).
        $chatRows = [];
        if ($DB->get_manager()->table_exists('learnguard_messages')) {
            $rows = $DB->get_records_sql(
                "SELECT m.id, m.sendertype, m.message, m.timecreated
                   FROM {learnguard_messages} m
                  WHERE m.attemptid = :aid AND m.timecreated > :since AND m.sendertype = 'proctor'
               ORDER BY m.timecreated ASC",
                ['aid' => $attempt->id, 'since' => $params['since']]
            );
            foreach ($rows as $r) {
                $chatRows[] = ['id' => (int)$r->id, 'message' => (string)$r->message, 'time' => (int)$r->timecreated];
            }
        }

        return [
            'suspended'       => ($updated->state === 'suspended') || $paused,
            'paused'          => $paused,
            'risk_score'      => (int)($updated->risk_score ?? 0),
            'risk_level'      => (string)($updated->risk_level ?? 'low'),
            'break_approved'  => $breakApproved,
            'break_requested' => $breakRequested,
            'extra_time'      => $extraTime,
            'messages'        => $chatRows,
        ];
    }

    public static function get_messages_returns(): \external_single_structure {
        return new \external_single_structure([
            'suspended'       => new \external_value(PARAM_BOOL),
            'paused'          => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
            'risk_score'      => new \external_value(PARAM_INT),
            'risk_level'      => new \external_value(PARAM_TEXT),
            'break_approved'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            'break_requested' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            'extra_time'      => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
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

        // Active in last 10 minutes — matches LearnShield's get_live_sessions()
        // window. The client sends a session_heartbeat every 60s, but a
        // 120s cutoff left almost no margin for background-tab throttling
        // or network delay, so live sessions would flicker out of the grid
        // ("No active sessions") between heartbeats.
        $cutoff  = time() - 600;
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

        // Pre-fetch latest webcam snapshot per attempt.
        $attemptids = array_keys($records);
        $webcamSnaps = [];
        $screenSnaps = [];
        $alertCounts = [];
        if (!empty($attemptids)) {
            list($insql, $inparams) = $DB->get_in_or_equal($attemptids, SQL_PARAMS_NAMED);
            // Latest webcam snapshot.
            $wrows = $DB->get_records_sql(
                "SELECT s.attemptid, s.snapshot_url FROM {learnguard_snapshots} s
                  WHERE s.attemptid $insql AND s.snapshot_type IN ('periodic','random_screenshot')
               ORDER BY s.timemodified DESC", $inparams
            );
            foreach ($wrows as $w) {
                if (!isset($webcamSnaps[$w->attemptid])) { $webcamSnaps[$w->attemptid] = $w->snapshot_url; }
            }
            // Latest screen capture snapshot.
            $srows = $DB->get_records_sql(
                "SELECT s.attemptid, s.snapshot_url FROM {learnguard_snapshots} s
                  WHERE s.attemptid $insql AND s.snapshot_type = 'screen_capture'
               ORDER BY s.timemodified DESC", $inparams
            );
            foreach ($srows as $s) {
                if (!isset($screenSnaps[$s->attemptid])) { $screenSnaps[$s->attemptid] = $s->snapshot_url; }
            }
            // Alert counts (high/medium severity, last hour).
            $since = time() - 3600;
            $arows = $DB->get_records_sql(
                "SELECT e.attemptid, COUNT(*) AS cnt FROM {learnguard_events} e
                  WHERE e.attemptid $insql AND e.severity IN ('high','medium') AND e.timemodified >= :since
               GROUP BY e.attemptid",
                array_merge($inparams, ['since' => $since])
            );
            foreach ($arows as $a) { $alertCounts[$a->attemptid] = (int)$a->cnt; }
        }

        $suspendedCount = $DB->count_records('learnguard_attempts',
            ['learnguard' => $cm->instance, 'state' => 'suspended']);

        // High-risk count for ALL in-progress attempts, independent of the
        // 10-minute heartbeat cutoff above — a high-risk learner whose
        // heartbeat has lapsed should still be counted, not silently dropped
        // from the "high risk" stat tile.
        $highriskCount = (int)$DB->count_records_select('learnguard_attempts',
            "learnguard = :lgid AND state = 'inprogress' AND risk_level = 'high'",
            ['lgid' => $cm->instance]);

        $sessions = [];
        foreach ($records as $r) {
            $sessions[] = [
                'attemptid'      => (int)$r->id,
                'userid'         => (int)$r->userid,
                'fullname'       => fullname($r),
                'email'          => (string)$r->email,
                'attempt'        => (int)$r->attempt,
                'state'          => (string)$r->state,
                'risk_score'     => (int)$r->risk_score,
                'risk_level'     => (string)$r->risk_level,
                'currentpage'    => (int)$r->currentpage,
                'timestart'      => (int)$r->timestart,
                'lastseen'       => (int)$r->timemodified,
                'webcam_snap'    => (string)($webcamSnaps[$r->id] ?? ''),
                'screen_snap'    => (string)($screenSnaps[$r->id] ?? ''),
                'alert_count'    => (int)($alertCounts[$r->id] ?? 0),
                'precheck_step'  => 0,
                'precheck_total' => 0,
            ];
        }

        // Also include students who are currently in the precheck wizard
        // (active in last 10 minutes). Use negative IDs to avoid clashing
        // with real attempt IDs; JS checks s.attemptid < 0 for precheck cards.
        $prows = $DB->get_records_sql(
            "SELECT ps.id, ps.userid, ps.step_number, ps.total_steps, ps.timemodified,
                    u.firstname, u.lastname, u.email
               FROM {learnguard_precheck_sessions} ps
               JOIN {user} u ON u.id = ps.userid
              WHERE ps.cmid = :cmid AND ps.timemodified >= :cutoff
           ORDER BY ps.timemodified DESC",
            ['cmid' => $cm->id, 'cutoff' => $cutoff]
        );
        // Skip precheck rows for users who already have an active attempt row
        // (they have finished precheck and are in the assessment).
        $activeUserIds = array_column(array_values($records), 'userid');
        foreach ($prows as $pr) {
            if (in_array($pr->userid, $activeUserIds)) { continue; }
            $sessions[] = [
                'attemptid'      => -(int)$pr->id,
                'userid'         => (int)$pr->userid,
                'fullname'       => fullname($pr),
                'email'          => (string)$pr->email,
                'attempt'        => 0,
                'state'          => 'precheck',
                'risk_score'     => 0,
                'risk_level'     => 'low',
                'currentpage'    => 0,
                'timestart'      => (int)$pr->timemodified,
                'lastseen'       => (int)$pr->timemodified,
                'webcam_snap'    => '',
                'screen_snap'    => '',
                'alert_count'    => 0,
                'precheck_step'  => (int)$pr->step_number,
                'precheck_total' => (int)$pr->total_steps,
            ];
        }

        return ['sessions' => $sessions, 'suspended_count' => (int)$suspendedCount, 'highrisk_count' => $highriskCount];
    }

    public static function get_live_sessions_returns(): \external_single_structure {
        return new \external_single_structure([
            'sessions' => new \external_multiple_structure(
                new \external_single_structure([
                    'attemptid'      => new \external_value(PARAM_INT),
                    'userid'         => new \external_value(PARAM_INT),
                    'fullname'       => new \external_value(PARAM_TEXT),
                    'email'          => new \external_value(PARAM_EMAIL),
                    'attempt'        => new \external_value(PARAM_INT),
                    'state'          => new \external_value(PARAM_TEXT),
                    'risk_score'     => new \external_value(PARAM_INT),
                    'risk_level'     => new \external_value(PARAM_TEXT),
                    'currentpage'    => new \external_value(PARAM_INT),
                    'timestart'      => new \external_value(PARAM_INT),
                    'lastseen'       => new \external_value(PARAM_INT),
                    'webcam_snap'    => new \external_value(PARAM_RAW,    '', VALUE_DEFAULT, ''),
                    'screen_snap'    => new \external_value(PARAM_RAW,    '', VALUE_DEFAULT, ''),
                    'alert_count'    => new \external_value(PARAM_INT,    '', VALUE_DEFAULT, 0),
                    'precheck_step'  => new \external_value(PARAM_INT,    '', VALUE_DEFAULT, 0),
                    'precheck_total' => new \external_value(PARAM_INT,    '', VALUE_DEFAULT, 0),
                ])
            ),
            'suspended_count' => new \external_value(PARAM_INT, '', VALUE_DEFAULT, 0),
            'highrisk_count'  => new \external_value(PARAM_INT, '', VALUE_DEFAULT, 0),
        ]);
    }

    // =======================================================================
    // Audio clip upload (noise-triggered 5-10s recordings)
    // =======================================================================

    public static function upload_audio_clip_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid'   => new \external_value(PARAM_INT),
            'audiodata'   => new \external_value(PARAM_RAW, 'Base64-encoded WebM audio'),
            'duration'    => new \external_value(PARAM_INT, 'Recording duration in seconds', VALUE_DEFAULT, 8),
            'noise_level' => new \external_value(PARAM_INT, 'RMS level that triggered recording', VALUE_DEFAULT, 0),
        ]);
    }

    public static function upload_audio_clip(int $attemptid, string $audiodata, int $duration = 8, int $noise_level = 0): array {
        global $DB, $USER;
        $params  = self::validate_parameters(self::upload_audio_clip_parameters(),
            ['attemptid' => $attemptid, 'audiodata' => $audiodata, 'duration' => $duration, 'noise_level' => $noise_level]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $raw = $params['audiodata'];
        if (strpos($raw, ',') !== false) { $raw = substr($raw, strpos($raw, ',') + 1); }
        if (strlen($raw) < 10) { return ['status' => 'invalid', 'id' => 0]; }

        $id = $DB->insert_record('learnguard_audio_clips', (object)[
            'attemptid'   => $attempt->id,
            'userid'      => $USER->id,
            'audiodata'   => $raw,
            'duration'    => max(0, (int)$params['duration']),
            'noise_level' => max(0, (int)$params['noise_level']),
            'timecreated' => time(),
        ]);

        return ['status' => 'ok', 'id' => (int)$id];
    }

    public static function upload_audio_clip_returns(): \external_single_structure {
        return new \external_single_structure([
            'status' => new \external_value(PARAM_TEXT),
            'id'     => new \external_value(PARAM_INT),
        ]);
    }

    public static function get_audio_clips_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
        ]);
    }

    public static function get_audio_clips(int $attemptid): array {
        global $DB;
        $params  = self::validate_parameters(self::get_audio_clips_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:viewreports', $context);

        $clips = $DB->get_records('learnguard_audio_clips', ['attemptid' => $attempt->id], 'timecreated ASC');
        $out   = [];
        foreach ($clips as $c) {
            $out[] = [
                'id'          => (int)$c->id,
                'duration'    => (int)$c->duration,
                'noise_level' => (int)$c->noise_level,
                'timecreated' => (int)$c->timecreated,
                'audiodata'   => (string)$c->audiodata,
            ];
        }
        return ['clips' => $out];
    }

    public static function get_audio_clips_returns(): \external_single_structure {
        return new \external_single_structure([
            'clips' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'          => new \external_value(PARAM_INT),
                    'duration'    => new \external_value(PARAM_INT),
                    'noise_level' => new \external_value(PARAM_INT),
                    'timecreated' => new \external_value(PARAM_INT),
                    'audiodata'   => new \external_value(PARAM_RAW),
                ])
            ),
        ]);
    }

    // =======================================================================
    // PHASE 3: Chat (proctor ↔ student)
    // =======================================================================

    public static function send_chat_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid'  => new \external_value(PARAM_INT),
            'message'    => new \external_value(PARAM_TEXT),
            'sendertype' => new \external_value(PARAM_ALPHA, 'student or proctor', VALUE_DEFAULT, 'student'),
        ]);
    }

    public static function send_chat(int $attemptid, string $message, string $sendertype = 'student'): array {
        global $DB, $USER;
        $params  = self::validate_parameters(self::send_chat_parameters(),
            ['attemptid' => $attemptid, 'message' => $message, 'sendertype' => $sendertype]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        $msg = trim($params['message']);
        if (strlen($msg) === 0 || strlen($msg) > 2000) {
            throw new \moodle_exception('invalidparameter', 'error');
        }

        $DB->insert_record('learnguard_messages', (object)[
            'learnguard'  => $attempt->learnguard,
            'attemptid'   => $attempt->id,
            'senderid'    => $USER->id,
            'sendertype'  => in_array($params['sendertype'], ['student', 'proctor']) ? $params['sendertype'] : 'student',
            'message'     => $msg,
            'timecreated' => time(),
            'isseen'      => 0,
        ]);

        // Notify assigned proctors when a student sends a chat message.
        if ($params['sendertype'] === 'student' && get_config('learnguard', 'notify_proctors_enabled')) {
            global $CFG;
            require_once($CFG->dirroot . '/mod/learnguard/lib.php');
            learnguard_notify_proctors_event($attempt->id, 'student_chat_message',
                'Student sent: "' . substr($msg, 0, 200) . '"');
        }

        return ['status' => 'ok'];
    }

    public static function send_chat_returns(): \external_single_structure {
        return new \external_single_structure(['status' => new \external_value(PARAM_TEXT)]);
    }

    public static function get_chat_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
            'since'     => new \external_value(PARAM_INT, 'Return messages after this timestamp', VALUE_DEFAULT, 0),
        ]);
    }

    public static function get_chat(int $attemptid, int $since = 0): array {
        global $DB, $USER;
        $params  = self::validate_parameters(self::get_chat_parameters(),
            ['attemptid' => $attemptid, 'since' => $since]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);

        $rows = $DB->get_records_sql(
            "SELECT m.*, u.firstname, u.lastname
               FROM {learnguard_messages} m
               JOIN {user} u ON u.id = m.senderid
              WHERE m.attemptid = :aid AND m.timecreated > :since
           ORDER BY m.timecreated ASC",
            ['aid' => $attempt->id, 'since' => $params['since']]
        );

        // Mark unread messages sent to this user as seen.
        foreach ($rows as $r) {
            if (!$r->isseen && $r->senderid !== $USER->id) {
                $DB->set_field('learnguard_messages', 'isseen', 1, ['id' => $r->id]);
            }
        }

        $messages = [];
        foreach ($rows as $r) {
            $messages[] = [
                'id'          => (int)$r->id,
                'sendertype'  => (string)$r->sendertype,
                'sendername'  => fullname($r),
                'message'     => (string)$r->message,
                'timecreated' => (int)$r->timecreated,
                'isseen'      => (bool)$r->isseen,
                'ismine'      => ($r->senderid === $USER->id),
            ];
        }
        return ['messages' => $messages];
    }

    public static function get_chat_returns(): \external_single_structure {
        return new \external_single_structure([
            'messages' => new \external_multiple_structure(
                new \external_single_structure([
                    'id'          => new \external_value(PARAM_INT),
                    'sendertype'  => new \external_value(PARAM_TEXT),
                    'sendername'  => new \external_value(PARAM_TEXT),
                    'message'     => new \external_value(PARAM_TEXT),
                    'timecreated' => new \external_value(PARAM_INT),
                    'isseen'      => new \external_value(PARAM_BOOL),
                    'ismine'      => new \external_value(PARAM_BOOL),
                ])
            ),
        ]);
    }

    // =======================================================================
    // PHASE 3: Break management
    // =======================================================================

    public static function request_break_parameters(): \external_function_parameters {
        return new \external_function_parameters(['attemptid' => new \external_value(PARAM_INT)]);
    }

    public static function request_break(int $attemptid): array {
        global $DB;
        $params  = self::validate_parameters(self::request_break_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        if ($attempt->break_approved > 0) {
            return ['status' => 'already_approved'];
        }
        $DB->set_field('learnguard_attempts', 'break_requested', time(), ['id' => $attempt->id]);

        // Notify assigned proctors of the break request.
        if (get_config('learnguard', 'notify_proctors_enabled')) {
            global $CFG;
            require_once($CFG->dirroot . '/mod/learnguard/lib.php');
            learnguard_notify_proctors_event($attempt->id, 'break_requested',
                'Student has requested a break. Approve or deny from the live dashboard.');
        }

        return ['status' => 'requested'];
    }

    public static function request_break_returns(): \external_single_structure {
        return new \external_single_structure(['status' => new \external_value(PARAM_TEXT)]);
    }

    public static function approve_break_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
            'approve'   => new \external_value(PARAM_BOOL, 'true=approve, false=deny'),
        ]);
    }

    public static function approve_break(int $attemptid, bool $approve): array {
        global $DB;
        $params  = self::validate_parameters(self::approve_break_parameters(),
            ['attemptid' => $attemptid, 'approve' => $approve]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:liveproctor', $context);

        if ($params['approve']) {
            $DB->set_field('learnguard_attempts', 'break_approved', time(), ['id' => $attempt->id]);
        } else {
            $DB->set_field('learnguard_attempts', 'break_requested', 0, ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'break_approved',  0, ['id' => $attempt->id]);
        }
        return ['status' => $params['approve'] ? 'approved' : 'denied'];
    }

    public static function approve_break_returns(): \external_single_structure {
        return new \external_single_structure(['status' => new \external_value(PARAM_TEXT)]);
    }

    public static function end_break_parameters(): \external_function_parameters {
        return new \external_function_parameters(['attemptid' => new \external_value(PARAM_INT)]);
    }

    public static function end_break(int $attemptid): array {
        global $DB;
        $params  = self::validate_parameters(self::end_break_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $DB->set_field('learnguard_attempts', 'break_end',      time(), ['id' => $attempt->id]);
        $DB->set_field('learnguard_attempts', 'break_requested', 0,     ['id' => $attempt->id]);
        $DB->set_field('learnguard_attempts', 'break_approved',  0,     ['id' => $attempt->id]);
        return ['status' => 'ok'];
    }

    public static function end_break_returns(): \external_single_structure {
        return new \external_single_structure(['status' => new \external_value(PARAM_TEXT)]);
    }

    // =======================================================================
    // PHASE 3: Extended time
    // =======================================================================

    public static function grant_extra_time_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid'  => new \external_value(PARAM_INT),
            'extrasecs'  => new \external_value(PARAM_INT, 'Extra seconds to add'),
        ]);
    }

    public static function grant_extra_time(int $attemptid, int $extrasecs): array {
        global $DB;
        $params  = self::validate_parameters(self::grant_extra_time_parameters(),
            ['attemptid' => $attemptid, 'extrasecs' => $extrasecs]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:liveproctor', $context);

        $extra = max(0, (int)$params['extrasecs']);
        $DB->set_field('learnguard_attempts', 'extra_time', $extra, ['id' => $attempt->id]);
        return ['status' => 'ok', 'extra_time' => $extra];
    }

    public static function grant_extra_time_returns(): \external_single_structure {
        return new \external_single_structure([
            'status'     => new \external_value(PARAM_TEXT),
            'extra_time' => new \external_value(PARAM_INT),
        ]);
    }

    // =======================================================================
    // PHASE 3: PIN validation
    // =======================================================================

    public static function validate_pin_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'cmid' => new \external_value(PARAM_INT),
            'pin'  => new \external_value(PARAM_TEXT),
        ]);
    }

    public static function validate_pin(int $cmid, string $pin): array {
        global $DB;
        $params   = self::validate_parameters(self::validate_pin_parameters(),
            ['cmid' => $cmid, 'pin' => $pin]);
        $cm       = get_coursemodule_from_id('learnguard', $params['cmid'], 0, false, MUST_EXIST);
        $instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);
        $context  = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:attempt', $context);

        $storedPin = trim($instance->access_pin ?? '');
        if ($storedPin === '') {
            return ['valid' => true];  // No PIN required.
        }
        return ['valid' => (trim($params['pin']) === $storedPin)];
    }

    public static function validate_pin_returns(): \external_single_structure {
        return new \external_single_structure(['valid' => new \external_value(PARAM_BOOL)]);
    }

    // -----------------------------------------------------------------------
    // override_event — proctor removes a flagged event and deducts its score
    // -----------------------------------------------------------------------

    public static function override_event_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'eventid' => new \external_value(PARAM_INT, 'ID of the learnguard_events row to override'),
        ]);
    }

    public static function override_event(int $eventid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::override_event_parameters(), ['eventid' => $eventid]);

        $event   = $DB->get_record('learnguard_events',   ['id' => $params['eventid']], '*', MUST_EXIST);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $event->attemptid],  '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:liveproctor', $context);

        if (!empty($event->overridden)) {
            return ['status' => 'already_overridden', 'new_risk_score' => (int)$attempt->risk_score];
        }

        // Mark event as overridden.
        $DB->set_field('learnguard_events', 'overridden', 1, ['id' => $event->id]);

        // Deduct the score_impact from the attempt's risk score (floor at 0).
        $newScore = max(0, (int)$attempt->risk_score - (int)$event->score_impact);
        $newLevel = $newScore >= 60 ? 'high' : ($newScore >= 30 ? 'medium' : 'low');
        $DB->update_record('learnguard_attempts', (object)[
            'id'           => $attempt->id,
            'risk_score'   => $newScore,
            'risk_level'   => $newLevel,
            'timemodified' => time(),
        ]);

        return ['status' => 'ok', 'new_risk_score' => $newScore];
    }

    public static function override_event_returns(): \external_single_structure {
        return new \external_single_structure([
            'status'         => new \external_value(PARAM_TEXT),
            'new_risk_score' => new \external_value(PARAM_INT),
        ]);
    }

    // -----------------------------------------------------------------------
    // toggle_violations_suspended — proctor pauses/resumes violation scoring
    // -----------------------------------------------------------------------

    public static function toggle_violations_suspended_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT, 'ID of the attempt'),
            'suspended'  => new \external_value(PARAM_BOOL, '1 = pause scoring, 0 = resume'),
        ]);
    }

    public static function toggle_violations_suspended(int $attemptid, bool $suspended): array {
        global $DB;

        $params = self::validate_parameters(self::toggle_violations_suspended_parameters(), [
            'attemptid' => $attemptid,
            'suspended'  => $suspended,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        $cm      = get_coursemodule_from_instance('learnguard', $attempt->learnguard, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/learnguard:liveproctor', $context);

        $DB->set_field('learnguard_attempts', 'violations_suspended', $params['suspended'] ? 1 : 0,
            ['id' => $params['attemptid']]);

        return ['status' => 'ok', 'violations_suspended' => (int)$params['suspended']];
    }

    public static function toggle_violations_suspended_returns(): \external_single_structure {
        return new \external_single_structure([
            'status'               => new \external_value(PARAM_TEXT),
            'violations_suspended' => new \external_value(PARAM_INT),
        ]);
    }
}
