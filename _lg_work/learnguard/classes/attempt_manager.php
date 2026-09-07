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
    // Config
    // -----------------------------------------------------------------------

    // Effective auto-suspend threshold for a given attempt. Precedence:
    // 1. reopened_for_review=1 (an Assessment Manager "Continue & Review" session) always
    //    uses the dedicated learnguard/review_autosuspend_score site setting, regardless of
    //    the activity's own threshold — that setting exists specifically to be a separate,
    //    typically stricter, dial for review sessions.
    // 2. Otherwise, a per-activity autosuspend_score override (NULL = inherit the global
    //    learnguard/autosuspend_score setting) — same rule view.php applies when building
    //    spaconfig for the initial page load.
    // Any server-side code that decides/reports the suspend threshold must go through this
    // helper — reading get_config() alone here ignores both overrides above.
    public static function get_effective_autosuspend_score(\stdClass $attempt): int {
        global $DB;
        if (!empty($attempt->reopened_for_review)) {
            return (int)(get_config('learnguard', 'review_autosuspend_score') ?: 50);
        }
        $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], 'autosuspend_score');
        if ($instance && $instance->autosuspend_score !== null) {
            return (int)$instance->autosuspend_score;
        }
        return (int)(get_config('learnguard', 'autosuspend_score') ?: 80);
    }

    // -----------------------------------------------------------------------
    // Start / Resume
    // -----------------------------------------------------------------------

    public static function start_or_resume(
        \stdClass $instance, \stdClass $cm, \stdClass $user
    ): \stdClass {
        global $DB;

        // Serialize concurrent start attempts for the same user+activity. Without this,
        // two near-simultaneous calls (double-clicked Begin button, two open tabs, a
        // retried request) can both pass the maxattempts/existing-attempt checks below
        // before either has committed its insert — a classic check-then-act race that let
        // students end up with more than one attempt despite maxattempts=1.
        $lockfactory = \core\lock\lock_config::get_lock_factory('mod_learnguard');
        $lockkey     = 'start_attempt_' . $instance->id . '_' . $user->id;
        $lock        = $lockfactory->get_lock($lockkey, 15);
        if (!$lock) {
            throw new \moodle_exception('error:nopermission', 'learnguard');
        }
        try {
            return self::start_or_resume_locked($instance, $cm, $user);
        } finally {
            $lock->release();
        }
    }

    private static function start_or_resume_locked(
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

        // Attempt number: include voided so the number always increases even after admin
        // voids an attempt and grants a reattempt (voided attempt stays in DB with its slot).
        $attemptno = $DB->count_records_select(
            'learnguard_attempts',
            "learnguard = :lgid AND userid = :uid AND state != 'inprogress'",
            ['lgid' => $instance->id, 'uid' => $user->id]
        ) + 1;

        // Max-attempts enforcement: voided attempts do NOT count against the limit —
        // admin intentionally voided them to grant another try.
        // An attempt reopened for Continue & Review flips back to state='inprogress'
        // while the review is pending (see start_review()), which would otherwise drop
        // it out of this count entirely — silently freeing up a slot and letting the
        // student start a genuinely NEW, unauthorized attempt while their granted review
        // was still sitting unfinished. reopened_for_review stays 1 permanently once set
        // (audit marker), so counting it here also correctly still counts it after the
        // review is resubmitted back to 'finished', with no double-count risk.
        $completedCount = $DB->count_records_select(
            'learnguard_attempts',
            "learnguard = :lgid AND userid = :uid AND (state IN ('finished', 'suspended') OR reopened_for_review = 1)",
            ['lgid' => $instance->id, 'uid' => $user->id]
        );
        $maxattempts = (int)($instance->attempts ?? 0);
        // Apply per-user extra_attempts from learnguard_schedules (mirrors view.php logic).
        if ($maxattempts > 0) {
            $userSchedule = $DB->get_record('learnguard_schedules',
                ['learnguard' => $instance->id, 'userid' => $user->id]);
            if ($userSchedule && !empty($userSchedule->extra_attempts)) {
                $maxattempts += (int)$userSchedule->extra_attempts;
            }
        }
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
            // Always clean up pool slots created for this orphan attempt.
            $DB->delete_records('learnguard_slots', ['attemptid' => $orphan->id]);
        }

        // Get question pool from fixed slots only (attemptid=0 excludes pool-drawn slots).
        $pool = array_values($DB->get_records_select('learnguard_slots',
            'learnguard = :lgid AND attemptid = 0', ['lgid' => $instance->id], 'slot ASC'));
        $haspoolrules = $DB->record_exists('learnguard_pools', ['learnguard' => $instance->id]);
        if (empty($pool) && !$haspoolrules) {
            throw new \moodle_exception('error:noquestions', 'learnguard');
        }

        // Verify own question bank has these questions.
        foreach ($pool as $slot) {
            if (!$DB->record_exists('learnguard_qbank', ['id' => $slot->questionid])) {
                throw new \moodle_exception('error:noquestions', 'learnguard');
            }
        }

        // Randomisation: shuffle and/or pick a subset.
        // Mandatory questions (is_mandatory=1 in learnguard_qbank) are always included.
        $randomize     = !empty($instance->randomize);
        $num_questions = (int)($instance->num_questions ?? 0);

        if ($randomize) {
            // Separate mandatory from optional slots.
            $mandatory = [];
            $optional  = [];
            foreach ($pool as $slot) {
                $qrec = $DB->get_record('learnguard_qbank', ['id' => $slot->questionid], 'is_mandatory');
                if ($qrec && !empty($qrec->is_mandatory)) {
                    $mandatory[] = $slot;
                } else {
                    $optional[] = $slot;
                }
            }

            // Fair distribution: prefer questions that have been issued least often.
            // Count how many times each optional slot has been assigned across all attempts
            // in this activity; sort ascending so under-used questions are picked first.
            // Ties are broken by a prior shuffle so equal-count questions are still random.
            if (!empty($optional)) {
                $optSlotIds = array_column($optional, 'id');
                [$insql, $inparams] = $DB->get_in_or_equal($optSlotIds, SQL_PARAMS_NAMED, 'oslot');
                $usageRows = $DB->get_records_sql(
                    "SELECT aq.slotid, COUNT(*) AS usecount
                       FROM {learnguard_attempt_questions} aq
                       JOIN {learnguard_attempts} att ON att.id = aq.attemptid
                      WHERE aq.slotid $insql
                        AND att.learnguard = :lgid
                        AND att.state IN ('inprogress','finished','suspended')
                     GROUP BY aq.slotid",
                    array_merge($inparams, ['lgid' => $instance->id])
                );
                $countMap = [];
                foreach ($usageRows as $row) {
                    $countMap[(int)$row->slotid] = (int)$row->usecount;
                }
                // Shuffle first so equal-count slots are in random order within their tier.
                shuffle($optional);
                usort($optional, function($a, $b) use ($countMap) {
                    return ($countMap[$a->id] ?? 0) - ($countMap[$b->id] ?? 0);
                });
            }

            if ($num_questions > 0) {
                $optionalSlots = max(0, $num_questions - count($mandatory));
                $optional = array_slice($optional, 0, $optionalSlots);
            }
            // Merge: mandatory (original order) + selected optional, then shuffle combined.
            $pool = array_merge($mandatory, $optional);
            shuffle($pool); // so mandatory questions aren't always at the front.
        }

        // Snapshot the most recent scored attempt's grade, if any — lets scores.php show
        // a rise/fall arrow once this new attempt is finished (e.g. after a Bulk Reattempt
        // grant), without needing to look up other attempt rows.
        $priorScored = $DB->get_records_select('learnguard_attempts',
            "learnguard = :lgid AND userid = :uid AND state = 'finished' AND sumgrades IS NOT NULL",
            ['lgid' => $instance->id, 'uid' => $user->id], 'timestart DESC', 'sumgrades', 0, 1);
        $prevSumgrades = $priorScored ? (float)reset($priorScored)->sumgrades : null;

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
            'prev_sumgrades' => $prevSumgrades,
        ];
        $attempt->id = $DB->insert_record('learnguard_attempts', $attempt);

        // Store per-attempt question order for fixed slots.
        foreach ($pool as $i => $slot) {
            $DB->insert_record('learnguard_attempt_questions', (object)[
                'attemptid'   => $attempt->id,
                'slotid'      => $slot->id,
                'questionid'  => $slot->questionid,
                'slotnum'     => $i + 1,
                'timecreated' => time(),
            ]);
        }

        // Draw from multi-bank pools only when NO fixed slots are defined.
        // Fixed-slot assessments are self-contained; pool rules are a separate mode.
        if (!empty($pool)) {
            return $attempt;
        }
        $poolrules = $DB->get_records('learnguard_pools',
            ['learnguard' => $instance->id], 'sortorder ASC, id ASC');
        $nextslot  = count($pool) + 1;
        foreach ($poolrules as $poolrule) {
            $bankqs = array_values($DB->get_records('learnguard_qbank',
                ['categoryid' => (int)$poolrule->catid], 'id ASC', 'id, name, qtype'));
            if (empty($bankqs)) {
                continue;
            }
            // Fair distribution, same reasoning as the fixed-slot randomize path above:
            // a plain shuffle()+slice on every attempt draws purely at random with no
            // memory of what's already been asked, so over many attempts some bank
            // questions get drawn far more often than others just by chance — worse the
            // smaller the pool is relative to drawcount. Each pool draw creates a fresh
            // learnguard_slots row every time (slot=0, never reused), so "how often has
            // this bank question been asked" has to be counted by questionid, not slotid.
            $bankqids = array_column($bankqs, 'id');
            [$bqinsql, $bqinparams] = $DB->get_in_or_equal($bankqids, SQL_PARAMS_NAMED, 'bq');
            $usageRows = $DB->get_records_sql(
                "SELECT aq.questionid, COUNT(*) AS usecount
                   FROM {learnguard_attempt_questions} aq
                   JOIN {learnguard_attempts} att ON att.id = aq.attemptid
                  WHERE aq.questionid $bqinsql
                    AND att.learnguard = :lgid
                    AND att.state IN ('inprogress','finished','suspended')
               GROUP BY aq.questionid",
                array_merge($bqinparams, ['lgid' => $instance->id])
            );
            $bqCountMap = [];
            foreach ($usageRows as $row) {
                $bqCountMap[(int)$row->questionid] = (int)$row->usecount;
            }
            // Shuffle first so equally-used questions are still drawn in random order.
            shuffle($bankqs);
            usort($bankqs, function($a, $b) use ($bqCountMap) {
                return ($bqCountMap[$a->id] ?? 0) - ($bqCountMap[$b->id] ?? 0);
            });
            $drawn = array_slice($bankqs, 0, min((int)$poolrule->drawcount, count($bankqs)));
            foreach ($drawn as $dq) {
                // Create a pool slot row (slot=0, attemptid=this attempt) so all existing
                // response/grading code that looks up slotid continues to work unchanged.
                $poolslotid = $DB->insert_record('learnguard_slots', (object)[
                    'learnguard'  => $instance->id,
                    'slot'        => 0,
                    'questionid'  => (int)$dq->id,
                    'page'        => 1,
                    'maxmark'     => (float)$poolrule->maxmark,
                    'attemptid'   => $attempt->id,
                ]);
                $DB->insert_record('learnguard_attempt_questions', (object)[
                    'attemptid'   => $attempt->id,
                    'slotid'      => $poolslotid,
                    'questionid'  => (int)$dq->id,
                    'slotnum'     => $nextslot,
                    'timecreated' => time(),
                ]);
                $nextslot++;
            }
        }

        return $attempt;
    }

    // -----------------------------------------------------------------------
    // Question HTML
    // -----------------------------------------------------------------------

    // Review mode renders correct-answer markup (right/wrong borders, "Correct answer"
    // boxes, accepted short-answer variants). $attempt->state === 'finished' alone
    // stops a student calling the WS directly with review=true mid-exam to see answers
    // for the question they're currently attempting — but it does NOT stop a student
    // from deliberately finishing a THROWAWAY attempt early (with maxattempts > 1),
    // then calling the review endpoint directly on that finished attempt to learn
    // every correct answer before starting a REAL subsequent attempt on the same
    // activity — very plausibly the actual explanation for a wave of suspicious 100%
    // scores. Review is now only ever revealed once no further attempt is possible for
    // this user on this activity (mirrors the exact "attempts remaining" calculation
    // in start_or_resume_locked()), and additionally respects manual result release —
    // a teacher who hasn't released results yet shouldn't have per-question correctness
    // leak through this WS even on a genuinely-final attempt.
    private static function can_reveal_review(\stdClass $attempt): bool {
        global $DB;
        if ($attempt->state !== 'finished') {
            return false;
        }
        $instance = $DB->get_record('learnguard', ['id' => $attempt->learnguard], '*', MUST_EXIST);
        if (empty($instance->show_results) && empty($instance->results_released)) {
            return false;
        }
        $maxattempts = (int)($instance->attempts ?? 0);
        if ($maxattempts > 0) {
            // Mirrors start_or_resume_locked()'s completedCount exactly, including the
            // reopened_for_review inclusion — otherwise the two calculations disagree
            // about whether a further attempt is possible.
            $completedCount = $DB->count_records_select(
                'learnguard_attempts',
                "learnguard = :lgid AND userid = :uid AND (state IN ('finished', 'suspended') OR reopened_for_review = 1)",
                ['lgid' => $instance->id, 'uid' => $attempt->userid]
            );
            $userSchedule = $DB->get_record('learnguard_schedules',
                ['learnguard' => $instance->id, 'userid' => $attempt->userid]);
            if ($userSchedule && !empty($userSchedule->extra_attempts)) {
                $maxattempts += (int)$userSchedule->extra_attempts;
            }
            if ($completedCount < $maxattempts) {
                return false;
            }
        }
        // maxattempts == 0 (unlimited) falls through to true here: an admin who has
        // configured unlimited attempts WITH immediate results has already accepted
        // that a student can learn from any given attempt before trying again — that
        // combination is a deliberate "practice mode" configuration, not this exploit.
        return true;
    }

    /**
     * For an attempt that was reopened for Continue & Review, find which of its
     * answers actually changed during the review, and what they were before.
     *
     * question_engine::save_response() already logs a response's previous content to
     * learnguard_response_history every time it's overwritten (this predates and is
     * independent of the review feature) — this just reads that trail and splits it
     * against $reviewgrantedtime to tell "an edit from the ORIGINAL attempt" apart from
     * "an edit made during the review session".
     *
     * @param int $attemptid
     * @param int $reviewgrantedtime unix timestamp review was granted (0 = unknown —
     *        this attempt was reviewed before review_granted_time existed; falls back
     *        to a best-effort guess using only the single most recent history entry)
     * @return array keyed by slotid, each a ['beforeraw' => string|null json,
     *         'beforefraction' => float|null, 'beforestate' => string|null] entry —
     *         ONLY for slots whose answer actually differs from before to now.
     */
    public static function get_review_changes(int $attemptid, int $reviewgrantedtime): array {
        global $DB;

        $changes   = [];
        $responses = $DB->get_records('learnguard_responses', ['attemptid' => $attemptid]);

        foreach ($responses as $resp) {
            $history = $DB->get_records('learnguard_response_history',
                ['responseid' => $resp->id], 'timecreated DESC');

            $before = null;
            foreach ($history as $h) {
                if ($reviewgrantedtime > 0) {
                    // Proper boundary: the most recent edit that happened AT OR BEFORE
                    // the review was granted is the true pre-review answer.
                    if ((int)$h->timecreated <= $reviewgrantedtime) {
                        $before = $h;
                        break;
                    }
                } else {
                    // Legacy review (granted before this column existed) — best-effort:
                    // assume the single most recent history entry is the pre-review
                    // answer. Can be wrong if the student re-edited the same question
                    // more than once during the ORIGINAL attempt, but is the closest
                    // available approximation with no recorded boundary to work from.
                    $before = $h;
                    break;
                }
            }

            if ($before) {
                if ((string)$before->responsedata !== (string)$resp->responsedata) {
                    $changes[(int)$resp->slotid] = [
                        'beforeraw'      => $before->responsedata,
                        'beforefraction' => (float)$before->fraction,
                        'beforestate'    => (string)$before->state,
                    ];
                }
            } elseif ($reviewgrantedtime > 0 && (int)$resp->timecreated > $reviewgrantedtime) {
                // No history at all, and this response was first created AFTER the
                // review was granted — it was blank/unanswered during the original
                // attempt and only answered for the first time during the review.
                $changes[(int)$resp->slotid] = [
                    'beforeraw'      => null,
                    'beforefraction' => null,
                    'beforestate'    => null,
                ];
            }
        }

        return $changes;
    }

    public static function get_question_html(
        \stdClass $attempt, int $slot, bool $review = false
    ): array {
        global $DB;

        $review = $review && self::can_reveal_review($attempt);

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
            (int)$aq->questionid, $slot, $saved, $review, (int)$attempt->id
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

    // Batch variant of get_question_html() — renders every question in the attempt in
    // one pass, so the client can preload the whole exam instead of fetching one
    // question per navigation click. Reuses the exact same per-slot rendering
    // (question_engine::render_question()/get_saved_response()) as the single-question
    // path above, just looped, so behaviour (shuffled-answer seeding, saved-response
    // rehydration, review-mode gating) is identical either way.
    public static function get_all_questions_html(\stdClass $attempt, bool $review = false): array {
        global $DB;

        $review = $review && self::can_reveal_review($attempt);

        $aqs = $DB->get_records('learnguard_attempt_questions', ['attemptid' => $attempt->id], 'slotnum ASC');
        if (!$aqs) {
            return [];
        }
        $slotids  = array_values(array_unique(array_map(function($aq) { return (int)$aq->slotid; }, $aqs)));
        $slotrows = $DB->get_records_list('learnguard_slots', 'id', $slotids);

        $out = [];
        foreach ($aqs as $aq) {
            $slot    = (int)$aq->slotnum;
            $maxmark = isset($slotrows[$aq->slotid]) ? (float)$slotrows[$aq->slotid]->maxmark : 1.0;
            $saved   = question_engine::get_saved_response($attempt->id, $aq->slotid);
            $html    = question_engine::render_question(
                (int)$aq->questionid, $slot, $saved, $review, (int)$attempt->id
            );
            $out[] = ['slot' => $slot, 'html' => $html, 'maxmark' => $maxmark];
        }
        return $out;
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

        // If the activity has essay questions that require manual grading, do not
        // publish a partial score yet — leave sumgrades as null until grading is done.
        $hasEssays = $DB->record_exists_sql(
            "SELECT 1 FROM {learnguard_slots} s JOIN {learnguard_qbank} q ON q.id = s.questionid
              WHERE s.learnguard = :lgid AND q.qtype = 'essay'",
            ['lgid' => $instance->id]
        );

        $DB->update_record('learnguard_attempts', (object)[
            'id'           => $attempt->id,
            'state'        => 'finished',
            'timefinish'   => time(),
            'timemodified' => time(),
            'sumgrades'    => $hasEssays ? null : $grade,
        ]);

        require_once($CFG->libdir . '/gradelib.php');
        if (!$hasEssays) {
            grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
                $instance->id, 0, [(object)['userid' => $attempt->userid, 'rawgrade' => $grade]]);
        }

        return $hasEssays ? 0.0 : $grade;
    }

    // -----------------------------------------------------------------------
    // Navigation summary
    // -----------------------------------------------------------------------

    public static function get_navigation_summary(\stdClass $attempt): array {
        global $DB;

        $aqs = $DB->get_records('learnguard_attempt_questions',
            ['attemptid' => $attempt->id], 'slotnum ASC');
        $summary = [];

        // Batch-fetch category info for all question IDs in this attempt.
        $qids = array_column(array_values($aqs), 'questionid');
        $catmap = [];
        if ($qids) {
            list($insql, $inparams) = $DB->get_in_or_equal(array_map('intval', $qids));
            $rows = $DB->get_records_sql(
                'SELECT q.id AS questionid, c.id AS catid, c.name AS catname
                 FROM {learnguard_qbank} q
                 LEFT JOIN {learnguard_qbank_cats} c ON c.id = q.categoryid
                 WHERE q.id ' . $insql,
                $inparams
            );
            foreach ($rows as $row) {
                $catmap[(int)$row->questionid] = [
                    'catid'   => (int)$row->catid,
                    'catname' => (string)$row->catname,
                ];
            }
        }

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

            $catinfo  = $catmap[(int)$aq->questionid] ?? ['catid' => 0, 'catname' => ''];
            $summary[] = [
                'slot'     => (int)$aq->slotnum,
                'slotid'   => (int)$aq->slotid,
                'state'    => $state,
                'answered' => $answered,
                'maxmark'  => $maxmark,
                'catid'    => $catinfo['catid'],
                'catname'  => $catinfo['catname'],
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
            // Freeze risk-score accumulation while suspended OR while proctor has
            // paused violation scoring (violations_suspended = 1).
            $vioSuspended = !empty($attempt->violations_suspended);
            if ($attempt && $attempt->state !== 'suspended' && !$vioSuspended) {
                $newscore    = (int)$attempt->risk_score + $scoreimpact;
                $autosuspend = self::get_effective_autosuspend_score($attempt);
                $notifyPct   = (int)(get_config('learnguard', 'score_notify_pct')  ?: 50);
                $highAt      = max(1, (int)($autosuspend * $notifyPct / 100));
                $level       = $newscore >= $highAt ? 'high' : ($newscore >= 30 ? 'medium' : 'low');
                $DB->update_record('learnguard_attempts', (object)[
                    'id'           => $attemptid,
                    'risk_score'   => $newscore,
                    'risk_level'   => $level,
                    'timemodified' => time(),
                ]);

                // First crossing into 'high' risk: auto-create an incident
                // (parity with LearnShield's incident_manager::auto_create_incident())
                // and email proctors (parity with email_notifier::notify_high_risk()).
                if ($level === 'high' && $attempt->risk_level !== 'high') {
                    $DB->insert_record('learnguard_incidents', (object)[
                        'learnguard'   => $attempt->learnguard,
                        'attemptid'    => $attemptid,
                        'proctoriid'   => 0,
                        'severity'     => 'high',
                        'title'        => 'High Risk Score: ' . $newscore,
                        'description'  => 'Automatically generated: attempt reached a high risk score of ' . $newscore . '.',
                        'status'       => 'open',
                        'resolution'   => null,
                        'timecreated'  => time(),
                        'timeresolved' => 0,
                    ]);

                    if (get_config('learnguard', 'notify_proctors_enabled')
                        && get_config('learnguard', 'notify_high_risk_enabled')) {
                        global $CFG;
                        require_once($CFG->dirroot . '/mod/learnguard/lib.php');
                        learnguard_notify_proctors_event($attemptid, 'high_risk_score',
                            'Risk score reached ' . $newscore . ' (notification threshold: ' . $highAt . ').');
                    }
                }

                // Server-side auto-suspend enforcement — independent of the client's own
                // lgCheckAutoSuspend()/mod_learnguard_auto_suspend call. Previously
                // suspension ONLY happened if the STUDENT'S OWN BROWSER decided to call
                // that WS — a student with DevTools open can pause/step around that
                // client-side check, override it in the console, or block the WS call
                // outright, while individual scored events (this function) keep reaching
                // the server normally. Without this, risk_score can climb arbitrarily far
                // past the configured threshold with the attempt never actually suspended
                // — confirmed live: an attempt reached 760 against a threshold in the
                // low hundreds while the student was repeatedly triggering devtools_f12.
                // Mirrors the same client+server backstop pattern already used for
                // time-limit expiry (learnguard_attempt_timelimit_expired() /
                // auto_finish_expired_attempts task). Uses the base effective threshold
                // (not the elevated post-reinstatement one — that's a narrower, opt-in
                // grace window for a specific redo-precheck reinstatement flow); the only
                // practical effect on that narrower path is a reinstated student could be
                // re-suspended slightly earlier than their client-side grace threshold,
                // which fails toward extra caution, not away from it.
                if ($newscore >= $autosuspend) {
                    $DB->set_field('learnguard_attempts', 'state', 'suspended', ['id' => $attemptid]);
                    $DB->set_field('learnguard_attempts', 'timesuspended', time(), ['id' => $attemptid]);
                    $DB->set_field('learnguard_attempts', 'risk_score_at_suspend', $newscore, ['id' => $attemptid]);
                    $DB->set_field('learnguard_attempts', 'time_last_suspended', time(), ['id' => $attemptid]);
                    $DB->insert_record('learnguard_events', (object)[
                        'attemptid'    => $attemptid,
                        'userid'       => $userid,
                        'event_type'   => 'auto_suspended',
                        'severity'     => 'high',
                        'score_impact' => 0,
                        'event_data'   => json_encode([
                            'reason' => 'Risk score ' . $newscore . ' reached suspend threshold of ' .
                                $autosuspend . ' (server-enforced — client-side check did not fire)',
                        ]),
                        'timecreated'  => time(),
                        'timemodified' => time(),
                    ]);
                    global $CFG;
                    require_once($CFG->dirroot . '/mod/learnguard/lib.php');
                    if (get_config('learnguard', 'notify_proctors_enabled')) {
                        learnguard_notify_proctors_event($attemptid, 'auto_suspended',
                            'Risk score ' . $newscore . ' reached suspend threshold of ' . $autosuspend .
                            ' (server-enforced).');
                    }
                }
            }
        }
    }
}
