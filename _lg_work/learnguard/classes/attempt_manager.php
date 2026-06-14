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
 * Attempt management for mod_learnguard.
 *
 * Supports randomization: each student can receive a different subset
 * of questions in a different order from the question pool.
 * Per-attempt ordering is stored in learnguard_attempt_questions.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard;

defined('MOODLE_INTERNAL') || die();

class attempt_manager {

    // -----------------------------------------------------------------------
    // Start / Resume
    // -----------------------------------------------------------------------

    public static function start_or_resume(
        \stdClass $instance, \stdClass $cm, \stdClass $user
    ): \stdClass {
        global $DB;

        // Return existing in-progress attempt (resume seamlessly).
        $attempt = $DB->get_record('learnguard_attempts', [
            'learnguard' => $instance->id,
            'userid'     => $user->id,
            'state'      => 'inprogress',
        ]);
        if ($attempt) { return $attempt; }

        // Enforce max attempts — only count COMPLETED or SUSPENDED attempts,
        // NOT orphaned inprogress ones (those happen if a network error or
        // browser close interrupted a previous wizard launch).
        $completedCount = $DB->count_records_select(
            'learnguard_attempts',
            "learnguard = :lgid AND userid = :uid AND state IN ('finished', 'suspended')",
            ['lgid' => $instance->id, 'uid' => $user->id]
        );
        $attemptno = $completedCount + 1;
        $maxattempts = (int)($instance->attempts ?? 0);
        if ($maxattempts > 0 && $completedCount >= $maxattempts) {
            throw new \moodle_exception('error:nopermission', 'learnguard');
        }

        // Clean up any orphaned inprogress attempts (no questions served yet).
        // These are ghost attempts left by failed wizard sessions.
        $orphans = $DB->get_records('learnguard_attempts', [
            'learnguard' => $instance->id,
            'userid'     => $user->id,
            'state'      => 'inprogress',
        ]);
        foreach ($orphans as $orphan) {
            if (!$DB->record_exists('learnguard_attempt_questions', ['attemptid' => $orphan->id])) {
                $DB->delete_records('learnguard_events',   ['attemptid' => $orphan->id]);
                $DB->delete_records('learnguard_snapshots',['attemptid' => $orphan->id]);
                $DB->delete_records('learnguard_attempts', ['id'        => $orphan->id]);
            }
        }

        // Get question pool from slots.
        $pool = array_values($DB->get_records('learnguard_slots',
            ['learnguard' => $instance->id], 'slot ASC'));
        if (empty($pool)) {
            throw new \moodle_exception('error:noquestions', 'learnguard');
        }

        // Verify own question bank has these questions.
        foreach ($pool as $slot) {
            if (!$DB->record_exists('learnguard_qbank', ['id' => $slot->questionid])) {
                throw new \moodle_exception('error:noquestions', 'learnguard');
            }
        }

        // Randomisation: shuffle and/or pick a subset.
        $randomize    = !empty($instance->randomize);
        $num_questions = (int)($instance->num_questions ?? 0);

        if ($randomize) {
            shuffle($pool);
            if ($num_questions > 0 && $num_questions < count($pool)) {
                $pool = array_slice($pool, 0, $num_questions);
            }
        }

        // Create attempt record.
        $attempt = (object)[
            'learnguard'   => $instance->id,
            'userid'       => $user->id,
            'attempt'      => $attemptno,
            'uniqueid'     => 0,
            'currentpage'  => 1,
            'state'        => 'inprogress',
            'timestart'    => time(),
            'timefinish'   => 0,
            'timemodified' => time(),
            'ip_address'   => getremoteaddr(),
            'browser_info' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            'risk_score'   => 0,
            'risk_level'   => 'low',
        ];
        $attempt->id = $DB->insert_record('learnguard_attempts', $attempt);

        // Store per-attempt question order.
        foreach ($pool as $i => $slot) {
            $DB->insert_record('learnguard_attempt_questions', (object)[
                'attemptid'   => $attempt->id,
                'slotid'      => $slot->id,
                'questionid'  => $slot->questionid,
                'slotnum'     => $i + 1,
                'timecreated' => time(),
            ]);
        }

        return $attempt;
    }

    // -----------------------------------------------------------------------
    // Question HTML
    // -----------------------------------------------------------------------

    public static function get_question_html(
        \stdClass $attempt, int $slot, bool $review = false
    ): array {
        global $DB;

        $total = $DB->count_records('learnguard_attempt_questions', ['attemptid' => $attempt->id]);
        if ($slot < 1 || $slot > $total) {
            throw new \moodle_exception('error:noattempt', 'learnguard');
        }

        $aq = $DB->get_record('learnguard_attempt_questions', [
            'attemptid' => $attempt->id,
            'slotnum'   => $slot,
        ], '*', MUST_EXIST);

        // Get the maxmark from the original slot.
        $slotrow = $DB->get_record('learnguard_slots', ['id' => $aq->slotid]);
        $maxmark = $slotrow ? (float)$slotrow->maxmark : 1.0;

        $saved = question_engine::get_saved_response($attempt->id, $aq->slotid);
        $html  = question_engine::render_question(
            (int)$aq->questionid, $slot, $saved, $review
        );

        return [
            'html'           => $html,
            'slot'           => $slot,
            'questionnumber' => $slot,
            'totalquestions' => $total,
            'isfinished'     => ($attempt->state === 'finished'),
            'maxmark'        => $maxmark,
        ];
    }

    // -----------------------------------------------------------------------
    // Process answer
    // -----------------------------------------------------------------------

    public static function process_answer(
        \stdClass $attempt, int $slot, array $responsedata, int $targetslot = 0
    ): int {
        global $DB;

        $aq = $DB->get_record('learnguard_attempt_questions', [
            'attemptid' => $attempt->id,
            'slotnum'   => $slot,
        ], '*', MUST_EXIST);

        // Pass $slot (the ATTEMPT slot number, 1-based position in student's attempt)
        // so save_response grades using the correct field name (q1_answer, q2_answer…).
        // Without this, it falls back to the original bank slot number which may differ,
        // causing grade_response to look for the wrong key → state = 'noresponse' → Answered=0.
        question_engine::save_response(
            $attempt->id, $aq->slotid, (int)$aq->questionid, $responsedata, true, $slot
        );

        $total    = $DB->count_records('learnguard_attempt_questions', ['attemptid' => $attempt->id]);
        $nextslot = ($slot < $total) ? $slot + 1 : 0;

        // currentpage must reflect where the student is NAVIGATING TO (so a
        // resume after suspension/disconnect lands back on the in-progress
        // question), not the slot they're leaving. $targetslot is the actual
        // destination (Prev/jump-to-question); falls back to $nextslot for
        // sequential "Next".
        $currentpage = ($targetslot > 0 && $targetslot <= $total) ? $targetslot : $nextslot;
        $DB->set_field('learnguard_attempts', 'currentpage', $currentpage, ['id' => $attempt->id]);
        $DB->set_field('learnguard_attempts', 'timemodified', time(), ['id' => $attempt->id]);

        return $nextslot;
    }

    // -----------------------------------------------------------------------
    // Autosave
    // -----------------------------------------------------------------------

    public static function autosave(\stdClass $attempt, int $slot, array $responsedata): void {
        global $DB;

        $aq = $DB->get_record('learnguard_attempt_questions', [
            'attemptid' => $attempt->id,
            'slotnum'   => $slot,
        ]);
        if (!$aq) { return; }

        question_engine::save_response(
            $attempt->id, $aq->slotid, (int)$aq->questionid, $responsedata, false, $slot
        );
        $DB->set_field('learnguard_attempts', 'timemodified', time(), ['id' => $attempt->id]);
    }

    // -----------------------------------------------------------------------
    // Finish
    // -----------------------------------------------------------------------

    public static function finish(\stdClass $attempt, \stdClass $instance): float {
        global $DB, $CFG;

        // Grade all unanswered questions as 0.
        $aqs = $DB->get_records('learnguard_attempt_questions',
            ['attemptid' => $attempt->id], 'slotnum ASC');
        $totalraw = 0.0;
        $totalmax = 0.0;

        foreach ($aqs as $aq) {
            $slotrow = $DB->get_record('learnguard_slots', ['id' => $aq->slotid]);
            $maxmark = $slotrow ? (float)$slotrow->maxmark : 1.0;
            $totalmax += $maxmark;

            $response = $DB->get_record('learnguard_responses',
                ['attemptid' => $attempt->id, 'slotid' => $aq->slotid]);
            if ($response) {
                $totalraw += (float)$response->fraction * $maxmark;
            }
        }

        $grademax = (float)($instance->grade ?? 100);
        $grade    = $totalmax > 0 ? round(($totalraw / $totalmax) * $grademax, 5) : 0.0;

        $DB->update_record('learnguard_attempts', (object)[
            'id'           => $attempt->id,
            'state'        => 'finished',
            'timefinish'   => time(),
            'timemodified' => time(),
            'sumgrades'    => $grade,
        ]);

        require_once($CFG->libdir . '/gradelib.php');
        grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
            $instance->id, 0, [(object)['userid' => $attempt->userid, 'rawgrade' => $grade]]);

        return $grade;
    }

    // -----------------------------------------------------------------------
    // Navigation summary
    // -----------------------------------------------------------------------

    public static function get_navigation_summary(\stdClass $attempt): array {
        global $DB;

        $aqs = $DB->get_records('learnguard_attempt_questions',
            ['attemptid' => $attempt->id], 'slotnum ASC');
        $summary = [];

        foreach ($aqs as $aq) {
            $slotrow  = $DB->get_record('learnguard_slots', ['id' => $aq->slotid]);
            $maxmark  = $slotrow ? (float)$slotrow->maxmark : 1.0;
            $response = $DB->get_record('learnguard_responses',
                ['attemptid' => $attempt->id, 'slotid' => $aq->slotid]);

            $state    = 'todo';
            $answered = false;
            if ($response) {
                $state    = $response->state;
                $answered = !in_array($state, ['todo', 'noresponse']);
            }

            $summary[] = [
                'slot'     => (int)$aq->slotnum,
                'slotid'   => (int)$aq->slotid,
                'state'    => $state,
                'answered' => $answered,
                'maxmark'  => $maxmark,
            ];
        }
        return $summary;
    }

    // -----------------------------------------------------------------------
    // Proctoring log
    // -----------------------------------------------------------------------

    public static function log_event(
        int $attemptid, int $userid, string $eventtype,
        string $eventdata, string $severity, int $scoreimpact
    ): void {
        global $DB;

        $DB->insert_record('learnguard_events', (object)[
            'attemptid'    => $attemptid,
            'userid'       => $userid,
            'event_type'   => $eventtype,
            'event_data'   => $eventdata,
            'severity'     => $severity,
            'score_impact' => $scoreimpact,
            'timemodified' => time(),
        ]);

        if ($scoreimpact > 0) {
            $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid]);
            if ($attempt) {
                $newscore = (int)$attempt->risk_score + $scoreimpact;
                $level    = $newscore >= 60 ? 'high' : ($newscore >= 30 ? 'medium' : 'low');
                $DB->update_record('learnguard_attempts', (object)[
                    'id'           => $attemptid,
                    'risk_score'   => $newscore,
                    'risk_level'   => $level,
                    'timemodified' => time(),
                ]);
            }
        }
    }
}
