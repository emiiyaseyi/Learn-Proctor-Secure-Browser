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
// lib.php's bare learnguard_*() helper functions (learnguard_is_available_for_user(),
// learnguard_attempt_timelimit_expired(), etc.) are NOT autoloaded the way this class's
// own namespaced methods are — a web-service/AJAX request only instantiates this class
// file, so without this, any method that calls one of those functions before something
// else in the same request happens to have required lib.php fatals with "Call to
// undefined function". This silently broke submit_answer()/autosave() for every student
// clicking Save & Next once those started calling learnguard_is_available_for_user()
// directly (v2.0.127) — their timer kept running with every save rejected and no way to
// progress. Requiring it here once, for the whole class, closes this off for good instead
// of relying on each method to remember its own require_once (several already did, for
// other functions, which is what let this go unnoticed until a function only used by the
// newer time-limit/window checks exposed it).
require_once($CFG->dirroot . '/mod/learnguard/lib.php');

/**
 * External functions for LearnGuard SPA communication.
 */
class external extends \external_api {

    // Verify the logged-in user owns this attempt. Used in all mid-exam WS calls
    // instead of validate_context() which fails when CM availability changes mid-exam.
    private static function validate_attempt_owner(\stdClass $attempt): void {
        global $USER;
        if (!isloggedin() || isguestuser() || (int)$attempt->userid !== (int)$USER->id) {
            throw new \moodle_exception('error:attemptclosed', 'learnguard');
        }
    }

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
                'catid'    => (int)($n['catid']   ?? 0),
                'catname'  => (string)($n['catname'] ?? ''),
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
                    'catid'    => new \external_value(PARAM_INT,  '', VALUE_DEFAULT, 0),
                    'catname'  => new \external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
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
        self::validate_attempt_owner($attempt);

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
    // get_all_questions — batch variant of get_question, called once right after a
    // question is preloaded so the client can cache every question's HTML up front
    // instead of fetching one per navigation click. Cuts "Failed to load question"
    // down to just this one call instead of up to N (one per question) — reported as
    // a real, sustained problem under server load in a formal student complaint.
    // -----------------------------------------------------------------------

    public static function get_all_questions_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT, 'Attempt ID'),
        ]);
    }

    public static function get_all_questions(int $attemptid): array {
        $params = self::validate_parameters(self::get_all_questions_parameters(), [
            'attemptid' => $attemptid,
        ]);

        global $DB;
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        self::validate_attempt_owner($attempt);

        $questions = attempt_manager::get_all_questions_html($attempt, false);

        return [
            'questions' => array_map(function(array $q): array {
                return ['slot' => $q['slot'], 'html' => $q['html']];
            }, $questions),
            'totalquestions' => count($questions),
        ];
    }

    public static function get_all_questions_returns(): \external_single_structure {
        return new \external_single_structure([
            'questions' => new \external_multiple_structure(
                new \external_single_structure([
                    'slot' => new \external_value(PARAM_INT),
                    'html' => new \external_value(PARAM_RAW),
                ])
            ),
            'totalquestions' => new \external_value(PARAM_INT),
        ]);
    }

    // -----------------------------------------------------------------------
    // begin_section
    // Records that a student has started a timed section and stores the
    // server-authoritative deadline. Idempotent: if the student reconnects and
    // clicks "Begin Section" again, the same deadline is returned unchanged.
    // -----------------------------------------------------------------------

    public static function begin_section_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT, 'Attempt ID'),
            'catid'     => new \external_value(PARAM_INT, 'Question bank category ID for this section'),
        ]);
    }

    public static function begin_section(int $attemptid, int $catid): array {
        global $DB, $USER;

        $params  = self::validate_parameters(self::begin_section_parameters(),
            ['attemptid' => $attemptid, 'catid' => $catid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        if ((int)$attempt->userid !== (int)$USER->id || $attempt->state !== 'inprogress') {
            throw new \moodle_exception('error:attemptclosed', 'learnguard');
        }

        // Idempotent: return the existing deadline if the section was already started.
        $existing = $DB->get_record('learnguard_attempt_sections', [
            'attemptid' => $params['attemptid'],
            'catid'     => $params['catid'],
        ]);
        if ($existing) {
            return ['deadline' => (int)$existing->deadline];
        }

        // Look up the pool rule to get the timelimit for this category.
        $pool = $DB->get_record('learnguard_pools', [
            'learnguard' => $attempt->learnguard,
            'catid'      => $params['catid'],
        ]);
        $timelimit = $pool ? (int)$pool->timelimit : 0;
        $deadline  = $timelimit > 0 ? time() + $timelimit : 0;

        $DB->insert_record('learnguard_attempt_sections', (object)[
            'attemptid'   => (int)$params['attemptid'],
            'catid'       => (int)$params['catid'],
            'deadline'    => $deadline,
            'timecreated' => time(),
        ]);

        return ['deadline' => $deadline];
    }

    public static function begin_section_returns(): \external_single_structure {
        return new \external_single_structure([
            'deadline' => new \external_value(PARAM_INT, 'Unix timestamp when section expires; 0 = no limit'),
        ]);
    }

    // -----------------------------------------------------------------------
    // Helper: get the server-side deadline for the section the given slot
    // belongs to. Returns 0 when no per-section timer applies.
    // -----------------------------------------------------------------------

    private static function get_section_deadline_for_slot(\stdClass $attempt, int $slot): int {
        global $DB;
        $aq = $DB->get_record('learnguard_attempt_questions', [
            'attemptid' => $attempt->id, 'slotnum' => $slot,
        ]);
        if (!$aq) { return 0; }
        $q = $DB->get_record('learnguard_qbank', ['id' => $aq->questionid], 'id, categoryid');
        if (!$q || !$q->categoryid) { return 0; }
        $row = $DB->get_record('learnguard_attempt_sections', [
            'attemptid' => $attempt->id, 'catid' => $q->categoryid,
        ]);
        return ($row && $row->deadline > 0) ? (int)$row->deadline : 0;
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
        global $DB, $USER;

        $params = self::validate_parameters(self::submit_answer_parameters(), [
            'attemptid'    => $attemptid,
            'slot'         => $slot,
            'responsedata' => $responsedata,
            'targetslot'   => $targetslot,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        if ((int)$attempt->userid !== (int)$USER->id) {
            throw new \moodle_exception('error:attemptclosed', 'learnguard');
        }
        if ($attempt->state !== 'inprogress') {
            throw new \moodle_exception('error:attemptclosed', 'learnguard');
        }

        // Server-side backstop for the personal/activity window closing, AND for this
        // attempt's own time limit running out. The client already auto-submits on its
        // own countdown for both, but that relies on the student's browser tab staying
        // open and running JS — a closed tab, crashed script, backgrounded/throttled
        // tab, or lost connection would otherwise leave the attempt sitting 'inprogress'
        // indefinitely, since nothing here previously checked either deadline at all.
        // Auto-finish here mirrors what the client would have done, instead of just
        // rejecting — the student still gets a proper, scored result. The window check
        // does NOT apply to a reopened-for-review attempt: its own review deadline is
        // governed by extra_time (granted review minutes), not the original activity's
        // timeopen/timeclose — those have normally already passed by the time a review
        // is granted, which was wrongly auto-finishing the attempt (with nothing saved)
        // on the very first flushed answer at final submit. The time-limit check DOES
        // apply to review attempts too — learnguard_effective_timelimit_seconds() already
        // accounts for that by using extra_time as the whole review budget in that case.
        $windowInstance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
        $windowClosed   = empty($attempt->reopened_for_review)
                           && !\learnguard_is_available_for_user($windowInstance, $USER->id);
        $timelimitOver  = \learnguard_attempt_timelimit_expired($windowInstance, $attempt);
        if ($windowClosed || $timelimitOver) {
            attempt_manager::finish($attempt, $windowInstance);
            return [
                'nextslot'      => 0,
                'finished'      => true,
                'answered'      => false,
                'window_closed' => true,
            ];
        }

        // Reject the save if the section's server-side deadline has passed.
        $sectionDeadline = self::get_section_deadline_for_slot($attempt, $params['slot']);
        if ($sectionDeadline > 0 && time() > $sectionDeadline) {
            return [
                'nextslot'       => (int)$params['slot'],
                'finished'       => false,
                'answered'       => false,
                'section_expired' => true,
            ];
        }

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
            'nextslot'        => new \external_value(PARAM_INT),
            'finished'        => new \external_value(PARAM_BOOL),
            'answered'        => new \external_value(PARAM_BOOL, 'Whether the student actually submitted an answer'),
            'section_expired' => new \external_value(PARAM_BOOL,
                'True when the section timer has expired and the save was rejected', VALUE_DEFAULT, false),
            'window_closed'   => new \external_value(PARAM_BOOL,
                'True when the personal/activity window closed and the attempt was auto-finished server-side', VALUE_DEFAULT, false),
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
        global $DB, $USER;

        $params = self::validate_parameters(self::autosave_parameters(), [
            'attemptid'    => $attemptid,
            'slot'         => $slot,
            'responsedata' => $responsedata,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        if ((int)$attempt->userid !== (int)$USER->id || $attempt->state !== 'inprogress') {
            return ['status' => 'closed'];
        }

        // Same window-close + time-limit backstop as submit_answer() — auto-finish
        // server-side so a lingering autosave interval can't keep an attempt
        // "inprogress" past the personal/activity window closing or this attempt's own
        // time limit running out. Window check skipped for reopened-for-review attempts
        // — see the matching comment in submit_answer().
        $windowInstance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
        $windowClosed   = empty($attempt->reopened_for_review)
                           && !\learnguard_is_available_for_user($windowInstance, $USER->id);
        $timelimitOver  = \learnguard_attempt_timelimit_expired($windowInstance, $attempt);
        if ($windowClosed || $timelimitOver) {
            attempt_manager::finish($attempt, $windowInstance);
            return ['status' => 'closed'];
        }

        // Silently drop autosaves after the section's server-side deadline.
        $sectionDeadline = self::get_section_deadline_for_slot($attempt, $params['slot']);
        if ($sectionDeadline > 0 && time() > $sectionDeadline) {
            return ['status' => 'section_expired'];
        }

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

        self::validate_attempt_owner($attempt);

        $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);

        // Respect result visibility setting.
        $showgrade = (!isset($instance->show_results) || $instance->show_results == 1)
            || ($instance->results_released > 0);

        if ($attempt->state === 'finished') {
            $grade = (float)($attempt->sumgrades ?? 0);
            return [
                'grade'       => $showgrade ? $grade : -1.0,
                'maxgrade'    => (float)($instance->grade ?? 100),
                'attemptno'   => (int)$attempt->attempt,
                'timefinish'  => (int)($attempt->timefinish ?? time()),
                'resultsheld' => !$showgrade,
            ];
        }

        $grade = attempt_manager::finish($attempt, $instance);

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

        self::validate_attempt_owner($attempt);

        // Self-heal: timesuspended should only ever be nonzero while state is actually
        // 'suspended'. If a reinstate ever ran without clearing it (a bug fixed elsewhere,
        // but this repairs attempts already affected), the client would keep treating this
        // as "just reinstated" on every resume and apply the much lower post-reinstate
        // threshold (risk_score + delta) instead of the real autosuspend_score — causing
        // false suspensions well below the configured threshold.
        if ($attempt->state !== 'suspended' && !empty($attempt->timesuspended)) {
            $DB->set_field('learnguard_attempts', 'timesuspended', 0, ['id' => $attempt->id]);
            $attempt->timesuspended = 0;
        }

        $nav = ($attempt->state === 'inprogress') ? attempt_manager::get_navigation_summary($attempt) : [];

        // Return any existing per-section deadlines so the client can restore countdowns on reconnect.
        $sectionDeadlines = [];
        $secRows = $DB->get_records('learnguard_attempt_sections', ['attemptid' => $params['attemptid']]);
        foreach ($secRows as $row) {
            $sectionDeadlines[] = ['catid' => (int)$row->catid, 'deadline' => (int)$row->deadline];
        }

        return [
            'state'            => (string)$attempt->state,
            'currentslot'      => (int)($attempt->currentpage ?: 1),
            'risk_score'       => (int)$attempt->risk_score,
            'risk_level'       => (string)$attempt->risk_level,
            'suspended'        => (bool)($attempt->state === 'suspended'),
            'self_paused'      => (bool)!empty($attempt->self_paused),
            'reopened_for_review' => (bool)!empty($attempt->reopened_for_review),
            'is_flagged'       => (bool)!empty($attempt->is_flagged),
            'timestart'        => (int)($attempt->timestart ?? 0),
            'timesuspended'    => (int)($attempt->timesuspended ?? 0),
            'extra_time'       => (int)($attempt->extra_time ?? 0),
            'navigation'       => array_map(fn($n) => [
                'slot'     => (int)$n['slot'],
                'state'    => (string)$n['state'],
                'answered' => (bool)$n['answered'],
                'maxmark'  => (float)$n['maxmark'],
                'catid'    => (int)($n['catid']   ?? 0),
                'catname'  => (string)($n['catname'] ?? ''),
            ], $nav),
            'section_deadlines' => $sectionDeadlines,
        ];
    }

    public static function get_attempt_state_returns(): \external_single_structure {
        return new \external_single_structure([
            'state'         => new \external_value(PARAM_TEXT),
            'currentslot'   => new \external_value(PARAM_INT),
            'risk_score'    => new \external_value(PARAM_INT),
            'risk_level'    => new \external_value(PARAM_TEXT),
            'suspended'     => new \external_value(PARAM_BOOL),
            'self_paused'   => new \external_value(PARAM_BOOL, '', VALUE_DEFAULT, false),
            'reopened_for_review' => new \external_value(PARAM_BOOL, '', VALUE_DEFAULT, false),
            'timestart'     => new \external_value(PARAM_INT),
            'timesuspended' => new \external_value(PARAM_INT, '', VALUE_DEFAULT, 0),
            'extra_time'    => new \external_value(PARAM_INT, '', VALUE_DEFAULT, 0),
            'navigation'  => new \external_multiple_structure(
                new \external_single_structure([
                    'slot'     => new \external_value(PARAM_INT),
                    'state'    => new \external_value(PARAM_TEXT),
                    'answered' => new \external_value(PARAM_BOOL),
                    'maxmark'  => new \external_value(PARAM_FLOAT),
                    'catid'    => new \external_value(PARAM_INT,  '', VALUE_DEFAULT, 0),
                    'catname'  => new \external_value(PARAM_TEXT, '', VALUE_DEFAULT, ''),
                ])
            ),
            'section_deadlines' => new \external_multiple_structure(
                new \external_single_structure([
                    'catid'    => new \external_value(PARAM_INT),
                    'deadline' => new \external_value(PARAM_INT),
                ]),
                'Per-section server deadlines for reconnect countdown restoration',
                VALUE_DEFAULT, []
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
            'is_screen'  => new \external_value(PARAM_BOOL, 'True if captured from the screen stream', VALUE_DEFAULT, false),
        ]);
    }

    public static function send_snapshot(int $attemptid, string $imagedata, string $event_type = 'periodic', bool $is_screen = false): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::send_snapshot_parameters(), [
            'attemptid'  => $attemptid,
            'imagedata'  => $imagedata,
            'event_type' => $event_type,
            'is_screen'  => $is_screen,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        self::validate_attempt_owner($attempt);

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
            'is_screen'     => $params['is_screen'] ? 1 : 0,
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
        self::validate_attempt_owner($attempt);

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
        self::validate_attempt_owner($attempt);

        // Only suspend if still in progress.
        if ($attempt->state === 'inprogress') {
            $DB->set_field('learnguard_attempts', 'state',                  'suspended',            ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'timesuspended',          time(),                 ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'risk_score_at_suspend',  $attempt->risk_score,    ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'time_last_suspended',    time(),                 ['id' => $attempt->id]);
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
    // Self-pause (learner-initiated pause from the check-in dialog)
    // =======================================================================

    public static function self_pause_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
        ]);
    }

    public static function self_pause(int $attemptid): array {
        global $DB;
        $params  = self::validate_parameters(self::self_pause_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        self::validate_attempt_owner($attempt);

        if ($attempt->state === 'inprogress') {
            $DB->set_field('learnguard_attempts', 'state',                 'suspended',          ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'timesuspended',         time(),               ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'risk_score_at_suspend', $attempt->risk_score, ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'time_last_suspended',   time(),               ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'self_paused',           1,                    ['id' => $attempt->id]);
            $DB->insert_record('learnguard_events', (object)[
                'attemptid'   => $attempt->id,
                'userid'      => $attempt->userid,
                'event_type'  => 'self_paused',
                'severity'    => 'medium',
                'score_impact'=> 0,
                'event_data'  => json_encode(['reason' => 'Student self-paused via check-in dialog']),
                'timecreated' => time(),
                'timemodified'=> time(),
            ]);
        }
        return ['success' => true];
    }

    public static function self_pause_returns(): \external_single_structure {
        return new \external_single_structure(['success' => new \external_value(PARAM_BOOL)]);
    }

    // =======================================================================
    // Self-resume (learner-initiated resume of their own self-pause, after
    // redoing the security precheck). Only allowed when the attempt was
    // suspended via self_pause (self_paused=1) — a genuine proctor/violation
    // suspension still requires proctor reinstatement and is untouched here.
    // =======================================================================

    public static function self_resume_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
        ]);
    }

    public static function self_resume(int $attemptid): array {
        global $DB;
        $params  = self::validate_parameters(self::self_resume_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        self::validate_attempt_owner($attempt);

        if ($attempt->state === 'suspended' && !empty($attempt->self_paused)) {
            $DB->set_field('learnguard_attempts', 'state',          'inprogress', ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'timesuspended',  0,            ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'self_paused',    0,            ['id' => $attempt->id]);
            $DB->insert_record('learnguard_events', (object)[
                'attemptid'   => $attempt->id,
                'userid'      => $attempt->userid,
                'event_type'  => 'self_resumed',
                'severity'    => 'low',
                'score_impact'=> 0,
                'event_data'  => json_encode(['reason' => 'Student resumed their own self-paused attempt']),
                'timecreated' => time(),
                'timemodified'=> time(),
            ]);
            return ['success' => true];
        }
        return ['success' => false];
    }

    public static function self_resume_returns(): \external_single_structure {
        return new \external_single_structure(['success' => new \external_value(PARAM_BOOL)]);
    }

    // =======================================================================
    // Start review (learner-initiated activation of a "Continue & Review"
    // session, called right after they complete the required precheck).
    // Sets timestart=now so the granted review window (stored in extra_time by
    // the Assessment Manager override) counts from when they actually begin
    // reviewing, not from whenever the proctor happened to grant it.
    // =======================================================================

    public static function start_review_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
        ]);
    }

    public static function start_review(int $attemptid): array {
        global $DB;
        $params  = self::validate_parameters(self::start_review_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        self::validate_attempt_owner($attempt);

        if ($attempt->state === 'inprogress' && !empty($attempt->reopened_for_review)) {
            $DB->set_field('learnguard_attempts', 'timestart', time(), ['id' => $attempt->id]);
            // currentpage still holds wherever the ORIGINAL attempt was left when it was
            // finished (often the last question, since that's usually where "Finish" was
            // clicked from) — reopening for review must start the learner back at the
            // first question, not land them on the last one showing a Finish button with
            // nothing yet reviewed, which looked to students like the attempt had already
            // been submitted before they could answer anything.
            $nav = attempt_manager::get_navigation_summary($attempt);
            if (!empty($nav)) {
                $DB->set_field('learnguard_attempts', 'currentpage', (int)$nav[0]['slot'], ['id' => $attempt->id]);
            }
            return ['success' => true, 'timestart' => time()];
        }
        return ['success' => false, 'timestart' => (int)$attempt->timestart];
    }

    public static function start_review_returns(): \external_single_structure {
        return new \external_single_structure([
            'success'   => new \external_value(PARAM_BOOL),
            'timestart' => new \external_value(PARAM_INT),
        ]);
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
        global $DB, $USER, $CFG;

        $params = self::validate_parameters(self::log_event_parameters(), [
            'attemptid'   => $attemptid,
            'eventtype'   => $eventtype,
            'eventdata'   => $eventdata,
            'severity'    => $severity,
            'scoreimpact' => $scoreimpact,
        ]);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        self::validate_attempt_owner($attempt);

        // SOS ('tech_difficulty') was previously only gated client-side by hiding the
        // button when cfg.enablesos is off — the WS itself accepted the event
        // regardless. Tri-state check matches the client's enablesos config in view.php
        // (learnguard_feature_enabled() in lib.php) — same pattern already used for
        // send_chat()/request_break().
        if ($params['eventtype'] === 'tech_difficulty') {
            $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
            require_once($CFG->dirroot . '/mod/learnguard/lib.php');
            if (!learnguard_feature_enabled($instance->enable_sos ?? null, 'enable_sos', true)) {
                throw new \moodle_exception('nopermissions', 'error', '', 'report a technical difficulty on this assessment');
            }
        }

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
        // face_missing fires routinely (roughly every 30s while a face is out of frame)
        // and is ALWAYS logged at 'high' severity — the blanket "any high-severity event
        // emails a proctor" rule below made it the single biggest source of proctor-email
        // volume, easily several emails per minute per student. Excluded from that
        // blanket rule by default; learnguard/notify_facemissing_enabled (default OFF)
        // opts back in for anyone who does want an email on every occurrence.
        $notifyEvents = ['face_mismatch_approval_pending', 'auto_suspended', 'screen_share_stopped', 'tech_difficulty'];
        $isRoutineFaceMissing = ($params['eventtype'] === 'face_missing')
            && !get_config('learnguard', 'notify_facemissing_enabled');
        $shouldNotify = (get_config('learnguard', 'notify_proctors_enabled') && !$isRoutineFaceMissing &&
            ($params['severity'] === 'high' || in_array($params['eventtype'], $notifyEvents)));
        if ($shouldNotify) {
            require_once($CFG->dirroot . '/mod/learnguard/lib.php');
            learnguard_notify_proctors_event($params['attemptid'], $params['eventtype']);
        }

        // Read current admin settings so the client can apply any live changes
        // (e.g. admin adjusted autosuspend_score during an ongoing attempt).
        // Must go through the per-activity-aware helper — reading the global
        // setting alone here silently overwrote a custom per-activity
        // autosuspend_score on the client on the very first scored event of
        // every attempt (cfg.autosuspend_score gets reassigned from this
        // response), even though view.php had already sent the correct
        // per-activity value on page load.
        $autosuspendScore = attempt_manager::get_effective_autosuspend_score($updated);
        // This response REPLACES the client's cfg.scoreconfig wholesale on every single
        // scored event (cfg.scoreconfig = JSON.parse(resp.scoreconfig) client-side) — so
        // it must mirror view.php's $spaconfig['scoreconfig'] key-for-key, exhaustively.
        // A wrong OR missing key here doesn't just fail to apply an admin's custom score,
        // it actively CORRUPTS the correct value view.php already sent on page load,
        // starting with the very next event after the first. This is exactly how
        // 'score_facemissing'/'score_faceanomaly' (missing the underscore before
        // "missing"/"anomaly" — not real setting names) silently reset every attempt to
        // hardcoded defaults after its first scored event, and how every OTHER event type
        // not listed here at all (tab switch, fullscreen exit, key violations, copy/paste,
        // printscreen, background change, camera covered) never had a live-reconfigure
        // path in the first place. No hardcoded score values — every one of these reads
        // the real admin setting, matching view.php's $lgscoreconfig() calls exactly.
        $lgconf = function(string $key, int $default): int {
            $v = get_config('learnguard', $key);
            return ($v === false || $v === '') ? $default : (int)$v;
        };
        $scoreConfig = json_encode([
            'multiface'      => $lgconf('score_2nd_person',      25),
            'phone'          => $lgconf('score_phone_detected',  25),
            'faceanomaly'    => $lgconf('score_face_anomaly',    10),
            'facemissing'    => $lgconf('score_face_missing',    10),
            'audioclip'      => $lgconf('score_audio_clip',       5),
            'headdown'       => $lgconf('score_head_down',        5),
            'noblink'        => $lgconf('score_no_blink',        10),
            'tabswitch'      => $lgconf('score_tab_switch',       5),
            'fullscreenexit' => $lgconf('score_fullscreen_exit', 10),
            'bgchange'       => $lgconf('score_bg_change',       10),
            'cameracovered'  => $lgconf('score_camera_covered',  20),
            'keyviolation'   => $lgconf('score_key_violation',    5),
            'copypaste'      => $lgconf('score_copy_paste',       5),
            'printscreen'    => $lgconf('score_printscreen',     10),
        ]);

        return [
            'status'            => 'ok',
            'risk_score'        => (int)($updated->risk_score ?? 0),
            'risk_level'        => (string)($updated->risk_level ?? 'low'),
            'autosuspend_score' => $autosuspendScore,
            'scoreconfig'       => $scoreConfig,
            // True when attempt_manager::log_event() just server-side-enforced the
            // suspend threshold (see there) — lets the client react immediately instead
            // of waiting up to 15s for the next get_messages poll to notice the state
            // flip, during which the exam would otherwise still look normal to the
            // student even though it's already suspended server-side.
            'suspended'         => ($updated->state === 'suspended'),
        ];
    }

    public static function log_event_returns(): \external_single_structure {
        return new \external_single_structure([
            'status'            => new \external_value(PARAM_TEXT),
            'risk_score'        => new \external_value(PARAM_INT),
            'risk_level'        => new \external_value(PARAM_TEXT),
            'autosuspend_score' => new \external_value(PARAM_INT,  '', VALUE_OPTIONAL),
            'scoreconfig'       => new \external_value(PARAM_RAW,  '', VALUE_OPTIONAL),
            'suspended'         => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
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
        self::validate_attempt_owner($attempt);

        // Self-heal a break whose deadline passed without this browser calling end_break
        // (e.g. the tab was backgrounded/throttled and the client-side countdown never fired).
        global $CFG;
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');
        learnguard_expire_breaks([$attempt->learnguard]);

        $updated = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']]);

        // Check emergency pause (per-assessment or global).
        $paused = (bool)get_config('learnguard', 'paused_' . $attempt->learnguard)
               || (bool)get_config('learnguard', 'global_pause');

        // Phase 3: include break/extra_time status and new chat messages.
        $breakApproved  = !empty($updated->break_approved)  ? (int)$updated->break_approved  : 0;
        $breakRequested = !empty($updated->break_requested) ? (int)$updated->break_requested : 0;
        $breakDeadline  = !empty($updated->break_deadline)  ? (int)$updated->break_deadline  : 0;
        $breakDuration  = !empty($updated->break_duration)  ? (int)$updated->break_duration  : 5;
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
            'is_flagged'      => (bool)!empty($updated->is_flagged),
            'paused'          => $paused,
            'risk_score'      => (int)($updated->risk_score ?? 0),
            'risk_level'      => (string)($updated->risk_level ?? 'low'),
            'break_approved'  => $breakApproved,
            'break_requested' => $breakRequested,
            'break_deadline'  => $breakDeadline,
            'break_duration'  => $breakDuration,
            'extra_time'      => $extraTime,
            'messages'        => $chatRows,
        ];
    }

    public static function get_messages_returns(): \external_single_structure {
        return new \external_single_structure([
            'suspended'       => new \external_value(PARAM_BOOL),
            'is_flagged'      => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL, false),
            'paused'          => new \external_value(PARAM_BOOL, '', VALUE_OPTIONAL),
            'risk_score'      => new \external_value(PARAM_INT),
            'risk_level'      => new \external_value(PARAM_TEXT),
            'break_approved'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            'break_requested' => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            'break_deadline'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
            'break_duration'  => new \external_value(PARAM_INT, '', VALUE_OPTIONAL),
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
        self::validate_attempt_owner($attempt);

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
        global $DB, $USER, $CFG;
        $params  = self::validate_parameters(self::send_chat_parameters(),
            ['attemptid' => $attemptid, 'message' => $message, 'sendertype' => $sendertype]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        self::validate_attempt_owner($attempt);

        $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
        // Tri-state: this activity's own explicit choice wins over the site default in
        // either direction. Matches the same check used to build the client's config in
        // view.php (enablechat) — see learnguard_feature_enabled() in lib.php.
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');
        if (!learnguard_feature_enabled($instance->enable_chat ?? null, 'enable_chat', true)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'send chat messages on this assessment');
        }

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
        self::validate_attempt_owner($attempt);

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
    // Proctor-side chat (site-wide proctorview.php side panel)
    // =======================================================================

    public static function get_chat_thread_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
        ]);
    }

    public static function get_chat_thread(int $attemptid): array {
        global $DB, $USER, $CFG;
        $params  = self::validate_parameters(self::get_chat_thread_parameters(), ['attemptid' => $attemptid]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);

        require_once($CFG->dirroot . '/mod/learnguard/lib.php');
        if (!learnguard_is_proctor_for((int)$USER->id, (int)$attempt->learnguard)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'view this chat');
        }

        $student = $DB->get_record('user', ['id' => $attempt->userid]);
        $lg      = $DB->get_record('learnguard', ['id' => $attempt->learnguard]);
        $rows    = $DB->get_records('learnguard_messages', ['attemptid' => $attempt->id], 'timecreated ASC');

        // Mark student-sent messages as seen now that a proctor has opened the thread.
        foreach ($rows as $r) {
            if (!$r->isseen && $r->sendertype === 'student') {
                $DB->set_field('learnguard_messages', 'isseen', 1, ['id' => $r->id]);
            }
        }

        $stateLabels = ['inprogress' => '🟢 Active', 'finished' => '✅ Completed', 'suspended' => '🚫 Suspended'];
        $messages = [];
        foreach ($rows as $r) {
            $messages[] = [
                'message'    => (string)$r->message,
                'sendertype' => (string)$r->sendertype,
                'time'       => (int)$r->timecreated,
            ];
        }

        return [
            'studentname'    => fullname($student),
            'assessmentname' => format_string($lg->name ?? ''),
            'statelabel'     => $stateLabels[$attempt->state] ?? ucfirst($attempt->state),
            'online'         => ($attempt->state === 'inprogress' && (int)$attempt->timemodified >= time() - 600),
            'messages'       => $messages,
        ];
    }

    public static function get_chat_thread_returns(): \external_single_structure {
        return new \external_single_structure([
            'studentname'    => new \external_value(PARAM_TEXT),
            'assessmentname' => new \external_value(PARAM_TEXT),
            'statelabel'     => new \external_value(PARAM_TEXT),
            'online'         => new \external_value(PARAM_BOOL),
            'messages'       => new \external_multiple_structure(
                new \external_single_structure([
                    'message'    => new \external_value(PARAM_TEXT),
                    'sendertype' => new \external_value(PARAM_TEXT),
                    'time'       => new \external_value(PARAM_INT),
                ])
            ),
        ]);
    }

    public static function send_chat_reply_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
            'message'   => new \external_value(PARAM_TEXT),
        ]);
    }

    public static function send_chat_reply(int $attemptid, string $message): array {
        global $DB, $USER, $CFG;
        $params  = self::validate_parameters(self::send_chat_reply_parameters(),
            ['attemptid' => $attemptid, 'message' => $message]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);

        require_once($CFG->dirroot . '/mod/learnguard/lib.php');
        if (!learnguard_is_proctor_for((int)$USER->id, (int)$attempt->learnguard)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'reply to this chat');
        }

        $msg = trim($params['message']);
        if (strlen($msg) === 0 || strlen($msg) > 2000) {
            throw new \moodle_exception('invalidparameter', 'error');
        }

        // Always stored/delivered regardless of online status — the student's exam
        // screen picks it up on its next get_messages poll whenever they reconnect.
        $DB->insert_record('learnguard_messages', (object)[
            'learnguard'  => $attempt->learnguard,
            'attemptid'   => $attempt->id,
            'senderid'    => $USER->id,
            'sendertype'  => 'proctor',
            'message'     => $msg,
            'timecreated' => time(),
            'isseen'      => 0,
        ]);

        $online = ($attempt->state === 'inprogress' && (int)$attempt->timemodified >= time() - 600);
        return ['success' => true, 'online' => $online];
    }

    public static function send_chat_reply_returns(): \external_single_structure {
        return new \external_single_structure([
            'success' => new \external_value(PARAM_BOOL),
            'online'  => new \external_value(PARAM_BOOL),
        ]);
    }

    // =======================================================================
    // PHASE 3: Break management
    // =======================================================================

    public static function request_break_parameters(): \external_function_parameters {
        return new \external_function_parameters([
            'attemptid' => new \external_value(PARAM_INT),
            'duration'  => new \external_value(PARAM_INT, 'Requested break duration in minutes (5-10)', VALUE_DEFAULT, 5),
        ]);
    }

    public static function request_break(int $attemptid, int $duration = 5): array {
        global $DB, $CFG;
        $params  = self::validate_parameters(self::request_break_parameters(),
            ['attemptid' => $attemptid, 'duration' => $duration]);
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $params['attemptid']], '*', MUST_EXIST);
        self::validate_attempt_owner($attempt);

        $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
        // Tri-state: this activity's own explicit choice wins over the site default in
        // either direction — same check as send_chat() and the client's enablebreaks
        // config in view.php (see learnguard_feature_enabled() in lib.php).
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');
        if (!learnguard_feature_enabled($instance->enable_break ?? null, 'enable_break', false)) {
            throw new \moodle_exception('nopermissions', 'error', '', 'request a break on this assessment');
        }

        if ($attempt->break_approved > 0) {
            return ['status' => 'already_approved'];
        }

        // Clamp duration to admin-configured min/max (default 5-10 min).
        $minBreak = (int)(get_config('learnguard', 'break_min_minutes') ?: 5);
        $maxBreak = (int)(get_config('learnguard', 'break_max_minutes') ?: 10);
        $safeDur  = max($minBreak, min($maxBreak, (int)$params['duration']));

        $DB->set_field('learnguard_attempts', 'break_requested', time(),    ['id' => $attempt->id]);
        $DB->set_field('learnguard_attempts', 'break_duration',  $safeDur,  ['id' => $attempt->id]);
        $DB->set_field('learnguard_attempts', 'break_deadline',  0,         ['id' => $attempt->id]);

        // Notify assigned proctors of the break request.
        if (get_config('learnguard', 'notify_proctors_enabled')) {
            global $CFG;
            require_once($CFG->dirroot . '/mod/learnguard/lib.php');
            learnguard_notify_proctors_event($attempt->id, 'break_requested',
                'Student has requested a ' . $safeDur . '-minute break. Approve or deny from the live dashboard.');
        }

        return ['status' => 'requested', 'duration' => $safeDur];
    }

    public static function request_break_returns(): \external_single_structure {
        return new \external_single_structure([
            'status'   => new \external_value(PARAM_TEXT),
            'duration' => new \external_value(PARAM_INT, 'Approved duration in minutes', VALUE_OPTIONAL),
        ]);
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
            $now      = time();
            $duration = (int)($attempt->break_duration ?: 5);
            $deadline = $now + ($duration * 60);
            $DB->set_field('learnguard_attempts', 'break_approved',  $now,      ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'break_deadline',  $deadline, ['id' => $attempt->id]);
        } else {
            $DB->set_field('learnguard_attempts', 'break_requested', 0, ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'break_approved',  0, ['id' => $attempt->id]);
            $DB->set_field('learnguard_attempts', 'break_deadline',  0, ['id' => $attempt->id]);
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
        self::validate_attempt_owner($attempt);

        $DB->set_field('learnguard_attempts', 'break_end',       time(), ['id' => $attempt->id]);
        $DB->set_field('learnguard_attempts', 'break_requested',  0,    ['id' => $attempt->id]);
        $DB->set_field('learnguard_attempts', 'break_approved',   0,    ['id' => $attempt->id]);
        $DB->set_field('learnguard_attempts', 'break_deadline',   0,    ['id' => $attempt->id]);
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

        $extra    = max(0, (int)$params['extrasecs']);
        $newTotal = (int)($attempt->extra_time ?? 0) + $extra;
        $DB->set_field('learnguard_attempts', 'extra_time', $newTotal, ['id' => $attempt->id]);
        return ['status' => 'ok', 'extra_time' => $newTotal];
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
