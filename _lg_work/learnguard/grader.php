<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnGuard Essay Grading Interface — faithful clone of edwiser_grader layout.
 *
 * Single-page: a hidden "question details" panel slides in from the top when
 * a question pill is clicked. Grading is submitted via AJAX (fetch) without a
 * page reload. Student rows are grouped with per-attempt tabs matching the
 * edwiser_grader UX exactly (no licence feature).
 *
 * URL:  /mod/learnguard/grader.php?id=CMID
 * AJAX: /mod/learnguard/grader.php?id=CMID&lgajax=1&action=get_question|save_grade|void_attempt
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id     = required_param('id',     PARAM_INT);
$lgajax = optional_param('lgajax', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHANUMEXT);

$cm      = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$lg      = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context   = context_module::instance($cm->id);
$isManager = has_capability('mod/learnguard:manage', $context);
$myGrader  = $DB->get_record('learnguard_graders', ['learnguard' => $cm->instance, 'userid' => $USER->id]);
$isGrader  = $isManager || !empty($myGrader);

if (!$isGrader) {
    throw new moodle_exception('accessdenied', 'error', '', 'You are not assigned as a grader for this assessment.');
}

$anonymous  = !empty($myGrader->anonymous) && !$isManager;
$gradeLayer = $myGrader ? (int)$myGrader->gradelayer : 1;

// ── Manually gradable slots (essays, MCQ, short answer) ──────────────────────
$essaySlots = array_values($DB->get_records_sql(
    "SELECT s.id AS slotid, s.slot, q.id AS qid, q.qtype, q.questiontext, q.markingguidance, q.markingguidanceformat, s.maxmark
       FROM {learnguard_slots} s
       JOIN {learnguard_qbank} q ON q.id = s.questionid
      WHERE s.learnguard = :lgid AND q.qtype IN ('essay', 'multichoice', 'multicheck', 'shortanswer', 'truefalse')
      ORDER BY s.slot",
    ['lgid' => $cm->instance]
));
$totalSlots = count($essaySlots);
$slotIds    = array_column($essaySlots, 'slotid');

// ── AJAX handlers ────────────────────────────────────────────────────────────
if ($lgajax) {
    require_sesskey();
    header('Content-Type: application/json');

    if ($action === 'get_question') {
        try {
            $attemptid = required_param('attemptid', PARAM_INT);
            $slotid    = required_param('slotid',    PARAM_INT);

            $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $cm->instance], '*', MUST_EXIST);
            $slot    = $DB->get_record_sql(
                "SELECT s.id AS slotid, s.slot, q.id AS qid, q.qtype, q.questiontext, q.markingguidance, q.markingguidanceformat, s.maxmark
                   FROM {learnguard_slots} s
                   JOIN {learnguard_qbank} q ON q.id = s.questionid
                  WHERE s.id = :sid AND s.learnguard = :lgid",
                ['sid' => $slotid, 'lgid' => $cm->instance]
            );
            // Slot may have been deleted from the activity after the attempt was taken.
            if (!$slot) {
                $slot = (object)[
                    'slotid'              => $slotid,
                    'slot'                => 0,
                    'qid'                 => 0,
                    'qtype'               => 'essay',
                    'questiontext'        => '<em>[This question has been removed from the activity. The student\'s original response is shown below.]</em>',
                    'markingguidance'     => '',
                    'markingguidanceformat' => FORMAT_HTML,
                    'maxmark'             => 1.0,
                ];
            }
        $resp    = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slotid]);
        $decoded = ($resp && $resp->responsedata) ? (json_decode($resp->responsedata, true) ?? []) : [];
        // Use the attempt-sequential slotnum as the response key (matches how
        // render_question names the textarea: q1_answer, q2_answer…).
        // For non-randomised assessments slotnum = slot->slot, so this is safe
        // in both cases.
        $aqLookup = $DB->get_record('learnguard_attempt_questions', ['attemptid' => $attemptid, 'slotid' => $slotid]);
        $slotnum  = $aqLookup ? (int)$aqLookup->slotnum : (int)$slot->slot;
        $answer   = $decoded["q{$slotnum}_answer"] ?? '';
        if ($answer === '' && $resp) {
            // Fallback: first non-empty value in responsedata for deleted-slot attempts.
            foreach ($decoded as $v) { if (trim((string)$v) !== '') { $answer = (string)$v; break; } }
        }

        $hasAnswer = \mod_learnguard\question_engine::has_response($slotnum, $slot->qtype, $decoded);
        $autogradedTypes = ['multichoice', 'multicheck', 'shortanswer', 'truefalse'];
        $autoGradeValue = null;
        if ($resp && $hasAnswer && in_array($slot->qtype, $autogradedTypes, true)) {
            $autoGradeValue = (float)$resp->fraction * (float)$slot->maxmark;
        }

        // Format the answer based on question type
        $answerHtml = '';
        if ($slot->qtype === 'multichoice') {
            // MCQ review: show all answer options and mark correctness on each.
            $selectedId = (int)$answer;
            $allAnswers = $DB->get_records('learnguard_qbank_answers', ['questionid' => $slot->qid], 'sortorder ASC');
            $saved = ["q{$slotnum}_answer" => $selectedId];
            $answerHtml = \mod_learnguard\question_engine::render_multichoice($allAnswers, $slotnum, $saved, true);
        } elseif ($slot->qtype === 'multicheck') {
            // Multi-select review: show all options, selected state, and correctness.
            $selected = is_array($answer) ? $answer : (array)json_decode($answer ?? '[]');
            $allAnswers = $DB->get_records('learnguard_qbank_answers', ['questionid' => $slot->qid], 'sortorder ASC');
            $saved = ["q{$slotnum}_answer" => $selected];
            $answerHtml = \mod_learnguard\question_engine::render_multicheck($allAnswers, $slotnum, $saved, true);
        } elseif ($slot->qtype === 'shortanswer') {
            // Short answer review: show the student text plus the correct answer.
            $allAnswers = $DB->get_records('learnguard_qbank_answers', ['questionid' => $slot->qid], 'sortorder ASC');
            $saved = ["q{$slotnum}_answer" => $answer];
            $answerHtml = \mod_learnguard\question_engine::render_shortanswer($allAnswers, $slotnum, $saved, true);
        } elseif ($slot->qtype === 'truefalse') {
            $saved = ["q{$slotnum}_answer" => $answer];
            $answerHtml = \mod_learnguard\question_engine::render_truefalse($slot->qid, $slotnum, $saved, true);
        } else {
            // Essay: show response text as entered.
            $answerHtml = $answer !== '' ? nl2br(s($answer)) : '<em style="color:#6c757d;">No response submitted.</em>';
        }

        // Get ANY grader's existing grade (not just current user)
        $anyGrade = $DB->get_record_sql(
            "SELECT id, grade, feedback, graderid FROM {learnguard_essay_grades}
             WHERE attemptid = :aid AND slotid = :sid
             ORDER BY timegraded DESC LIMIT 1",
            ['aid' => $attemptid, 'sid' => $slotid]
        );
        // Check if current user has graded this slot
        $myGrade = $DB->get_record('learnguard_essay_grades', ['attemptid' => $attemptid, 'slotid' => $slotid, 'graderid' => $USER->id]);

        if ($myGrade) {
            $existingGrade = (float)$myGrade->grade;
            $existingFeedback = $myGrade->feedback;
            $gradedBy = 'You';
        } elseif ($anyGrade) {
            $existingGrade = (float)$anyGrade->grade;
            $existingFeedback = $anyGrade->feedback;
            $gradedBy = 'Another grader';
        } elseif ($autoGradeValue !== null && trim((string)$answer) !== '') {
            $existingGrade = $autoGradeValue;
            $existingFeedback = '';
            $gradedBy = 'Auto';
        } else {
            $existingGrade = null;
            $existingFeedback = '';
            $gradedBy = null;
        }

        $student = $DB->get_record('user', ['id' => $attempt->userid], 'firstname,lastname,email');

        echo json_encode([
            'ok'                => true,
            'qtype'             => $slot->qtype,
            'questiontext'      => format_text($slot->questiontext, FORMAT_HTML),
            'answer'            => $answerHtml,
            'markingguidance'   => $slot->markingguidance ? format_text($slot->markingguidance, $slot->markingguidanceformat ?: FORMAT_HTML) : '',
            'maxmark'           => (float)$slot->maxmark,
            'existing_grade'    => $existingGrade,
            'existing_feedback' => $existingFeedback,
            'graded_by'         => $gradedBy,
            'has_answer'        => $hasAnswer,
            'student_name'      => $anonymous ? 'Candidate #' . $attempt->id : fullname($student),
            'risk_score'        => (int)$attempt->risk_score,
            'risk_level'        => $attempt->risk_level ?? 'low',
        ]);
        exit;
    } catch (\Throwable $e) {
        echo json_encode(['ok' => false, 'error' => 'Server error: ' . $e->getMessage()]);
        exit;
    }
}

    if ($action === 'save_grade') {
        $attemptid = required_param('attemptid', PARAM_INT);
        $slotid    = required_param('slotid',    PARAM_INT);
        $grade     = required_param('grade',     PARAM_FLOAT);
        $feedback  = optional_param('feedback',  '', PARAM_RAW);

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $cm->instance], '*', MUST_EXIST);
        $slot    = $DB->get_record_sql(
            "SELECT s.id AS id, s.slot, s.maxmark
               FROM {learnguard_slots} s WHERE s.id = :sid AND s.learnguard = :lgid",
            ['sid' => $slotid, 'lgid' => $cm->instance]
        );
        // Slot may have been deleted after grading — infer maxmark from stored fraction.
        if (!$slot) {
            $savedResp = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slotid]);
            $existGrade = $DB->get_record('learnguard_essay_grades', ['attemptid' => $attemptid, 'slotid' => $slotid, 'graderid' => $USER->id]);
            $savedFrac  = ($savedResp && $savedResp->fraction > 0.0001) ? (float)$savedResp->fraction : 0.0;
            $savedGrade = $existGrade ? (float)$existGrade->grade : (float)required_param('grade', PARAM_FLOAT);
            $inferredMax = ($savedFrac > 0.0001 && $savedGrade > 0) ? round($savedGrade / $savedFrac, 4) : 1.0;
            $slot = (object)['id' => $slotid, 'slot' => 0, 'maxmark' => $inferredMax];
        }
        $maxmark = (float)$slot->maxmark;

        // Server-side cap: grade cannot exceed maxmark.
        if ($grade > $maxmark) {
            echo json_encode(['ok' => false, 'error' => 'Grade cannot exceed the maximum mark of ' . $maxmark]);
            exit;
        }

        $existing = $DB->get_record('learnguard_essay_grades', ['attemptid' => $attemptid, 'slotid' => $slotid, 'graderid' => $USER->id]);
        if ($existing) {
            $DB->update_record('learnguard_essay_grades', (object)['id' => $existing->id, 'grade' => $grade, 'feedback' => $feedback, 'timegraded' => time()]);
        } else {
            $DB->insert_record('learnguard_essay_grades', (object)['attemptid' => $attemptid, 'slotid' => $slotid, 'graderid' => $USER->id, 'grade' => $grade, 'feedback' => $feedback, 'timegraded' => time()]);
        }
        $fraction  = ($maxmark > 0) ? min(1.0, max(0.0, $grade / $maxmark)) : 0.0;
        $existResp = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slotid]);
        if ($existResp) {
            $DB->set_field('learnguard_responses', 'fraction',     $fraction, ['attemptid' => $attemptid, 'slotid' => $slotid]);
            $DB->set_field('learnguard_responses', 'state',        'graded',  ['attemptid' => $attemptid, 'slotid' => $slotid]);
            $DB->set_field('learnguard_responses', 'timemodified', time(),    ['attemptid' => $attemptid, 'slotid' => $slotid]);
        } else {
            // No autosave row exists (student left blank without visiting the slot).
            // Create one so the fraction/state is stored and calculate_total_grade works.
            $DB->insert_record('learnguard_responses', (object)[
                'attemptid'    => $attemptid,
                'slotid'       => $slotid,
                'responsedata' => json_encode([]),
                'fraction'     => $fraction,
                'state'        => 'graded',
                'timemodified' => time(),
            ]);
        }

        if ($attempt->state === 'finished') {
            require_once($CFG->libdir . '/gradelib.php');
            // Only publish score once ALL essay questions for this attempt are graded.
            if (!\mod_learnguard\question_engine::has_ungraded_essays($attemptid, $cm->instance)) {
                $newgrade = \mod_learnguard\question_engine::calculate_total_grade($attemptid, $lg);
                $DB->set_field('learnguard_attempts', 'sumgrades', $newgrade, ['id' => $attemptid]);
                grade_update('mod/learnguard', $lg->course, 'mod', 'learnguard', $lg->id, 0,
                    [(object)['userid' => $attempt->userid, 'rawgrade' => $newgrade]]);
            }
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'void_attempt' && $isManager) {
        $vaid     = required_param('vaid', PARAM_INT);
        $vattempt = $DB->get_record('learnguard_attempts', ['id' => $vaid, 'learnguard' => $cm->instance]);
        if ($vattempt && in_array($vattempt->state, ['finished', 'suspended'])) {
            $DB->set_field('learnguard_attempts', 'state', 'voided', ['id' => $vaid]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'recalc_all' && $isManager) {
        require_once($CFG->libdir . '/gradelib.php');
        $finishedAttempts = $DB->get_records('learnguard_attempts',
            ['learnguard' => $cm->instance, 'state' => 'finished']);
        $count = 0;
        foreach ($finishedAttempts as $att) {
            if (\mod_learnguard\question_engine::has_ungraded_essays((int)$att->id, $cm->instance)) {
                // Clear any stale partial score for this attempt.
                $DB->set_field('learnguard_attempts', 'sumgrades', null, ['id' => $att->id]);
                continue;
            }
            $newgrade = \mod_learnguard\question_engine::calculate_total_grade((int)$att->id, $lg);
            $DB->set_field('learnguard_attempts', 'sumgrades', $newgrade, ['id' => $att->id]);
            grade_update('mod/learnguard', $lg->course, 'mod', 'learnguard', $lg->id, 0,
                [(object)['userid' => $att->userid, 'rawgrade' => $newgrade]]);
            $count++;
        }
        echo json_encode(['ok' => true, 'count' => $count]);
        exit;
    }

    if ($action === 'reset_essay_grades' && $isManager) {
        require_once($CFG->libdir . '/gradelib.php');
        $essaySlotIds = $DB->get_fieldset_sql(
            "SELECT s.id FROM {learnguard_slots} s
               JOIN {learnguard_qbank} q ON q.id = s.questionid AND q.qtype = 'essay'
              WHERE s.learnguard = :lgid",
            ['lgid' => $cm->instance]
        );
        if (!empty($essaySlotIds)) {
            $attemptIds = $DB->get_fieldset_sql(
                "SELECT id FROM {learnguard_attempts} WHERE learnguard = :lgid",
                ['lgid' => $cm->instance]
            );
            if (!empty($attemptIds)) {
                [$slotSql, $slotParams] = $DB->get_in_or_equal($essaySlotIds, SQL_PARAMS_NAMED, 'sl');
                [$attSql, $attParams]   = $DB->get_in_or_equal($attemptIds, SQL_PARAMS_NAMED, 'at');
                $DB->delete_records_select('learnguard_essay_grades',
                    "slotid $slotSql AND attemptid $attSql",
                    array_merge($slotParams, $attParams)
                );
                $DB->execute(
                    "UPDATE {learnguard_responses} SET state = 'needsgrading', fraction = 0
                      WHERE slotid $slotSql AND attemptid $attSql",
                    array_merge($slotParams, $attParams)
                );
            }
        }
        $DB->set_field_select('learnguard_attempts', 'sumgrades', null,
            "learnguard = :lgid AND state = 'finished'", ['lgid' => $cm->instance]
        );
        echo json_encode(['ok' => true]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

// ── Auto-recalculate all finished scores on page load ────────────────────────
// Only recalculates attempts where all essays have been graded; clears stale
// partial scores for attempts still awaiting essay grading.
if ($isManager) {
    require_once($CFG->libdir . '/gradelib.php');
    $finishedAtts = $DB->get_records('learnguard_attempts',
        ['learnguard' => $cm->instance, 'state' => 'finished'], '', 'id,userid');
    foreach ($finishedAtts as $fatt) {
        if (\mod_learnguard\question_engine::has_ungraded_essays((int)$fatt->id, $cm->instance)) {
            $DB->set_field('learnguard_attempts', 'sumgrades', null, ['id' => $fatt->id]);
            continue;
        }
        $newg = \mod_learnguard\question_engine::calculate_total_grade((int)$fatt->id, $lg);
        $DB->set_field('learnguard_attempts', 'sumgrades', $newg, ['id' => $fatt->id]);
        grade_update('mod/learnguard', $lg->course, 'mod', 'learnguard', $lg->id, 0,
            [(object)['userid' => $fatt->userid, 'rawgrade' => $newg]]);
    }
}

// ── Page setup ───────────────────────────────────────────────────────────────
$PAGE->set_url('/mod/learnguard/grader.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($lg->name) . ' — Grader');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');
$brand = learnguard_brand();

// ── Distribution ─────────────────────────────────────────────────────────────
$gconfig  = null;
try { $gconfig = $DB->get_record('learnguard_grading_config', ['learnguard' => $cm->instance]); } catch (Exception $e) {}
$gradingMode = $gconfig->gradingmode ?? 'all';
$distributed = ($gradingMode !== 'all' && $gradeLayer == 1 && !$isManager);

// ── Load finished attempts, grouped by student ───────────────────────────────
$distJoin   = $distributed
    ? "JOIN {learnguard_grader_assignments} ga ON ga.attemptid = a.id AND ga.learnguard = :lgid2 AND ga.graderid = :myid"
    : "";
$distParams = $distributed ? ['lgid2' => $cm->instance, 'myid' => $USER->id] : [];

$rows = $DB->get_records_sql(
    "SELECT a.id, a.userid, a.attempt, a.state, a.timestart, a.timefinish, a.sumgrades,
            a.risk_score, a.risk_level,
            u.firstname, u.lastname, u.email
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
       $distJoin
      WHERE a.learnguard = :lgid AND a.state = 'finished'
      ORDER BY u.lastname ASC, u.firstname ASC, a.timefinish DESC",
    array_merge(['lgid' => $cm->instance], $distParams)
);

// Group by student.
$students = [];
foreach ($rows as $r) {
    if (!isset($students[$r->userid])) {
        $students[$r->userid] = ['userid' => $r->userid, 'firstname' => $r->firstname, 'lastname' => $r->lastname, 'email' => $r->email, 'attempts' => []];
    }
    $students[$r->userid]['attempts'][] = $r;
}

// Batch-fetch slot statuses for all attempts.
$attemptIds         = array_map('intval', array_column(array_values($rows), 'id'));
$slotStatusMap      = [];
$perAttemptSlotData = []; // [aid][slotid] => slot object — actual slots each attempt received
$essayGradeMap      = []; // sum of grades this grader gave per attempt
if (!empty($attemptIds)) {
    [$aidSql, $aidP] = $DB->get_in_or_equal($attemptIds, SQL_PARAMS_NAMED, 'aid');

    // Query 1: AQ-based slot discovery — LEFT JOIN so old slot IDs deleted by
    // admin after the attempt was taken are still included (with fallback values).
    // Condition keeps only manually gradable questions (essay, MCQ, multicheck, shortanswer) OR deleted slots (s.id IS NULL).
    $aqRs = $DB->get_recordset_sql(
        "SELECT aq.id AS aqid, aq.attemptid, aq.slotid AS slotid,
                COALESCE(s.slot, aq.slotnum) AS slot,
                COALESCE(q.questiontext, '') AS questiontext,
                COALESCE(q.markingguidance, '') AS markingguidance,
                COALESCE(q.markingguidanceformat, 1) AS markingguidanceformat,
                COALESCE(s.maxmark, 1.0) AS maxmark
           FROM {learnguard_attempt_questions} aq
           LEFT JOIN {learnguard_slots} s ON s.id = aq.slotid
           LEFT JOIN {learnguard_qbank} q ON q.id = s.questionid
          WHERE aq.attemptid $aidSql
            AND (q.qtype IN ('essay', 'multichoice', 'multicheck', 'shortanswer', 'truefalse') OR q.id IS NULL)
          ORDER BY aq.attemptid, COALESCE(s.slot, aq.slotnum)",
        $aidP
    );
    foreach ($aqRs as $r) {
        $aid = (int)$r->attemptid;
        $sid = (int)$r->slotid;
        if (!isset($perAttemptSlotData[$aid][$sid])) {
            $perAttemptSlotData[$aid][$sid] = $r;
        }
    }
    $aqRs->close();

    // Query 2: Response-based fallback for attempts that have no AQ records.
    // Also uses LEFT JOIN so deleted-slot responses remain visible.
    $attemptsWithoutAq = array_values(array_diff($attemptIds, array_keys($perAttemptSlotData)));
    if (!empty($attemptsWithoutAq)) {
        [$nawSql, $nawP] = $DB->get_in_or_equal($attemptsWithoutAq, SQL_PARAMS_NAMED, 'naw');
        $rspRs = $DB->get_recordset_sql(
            "SELECT r.id AS rid, r.attemptid, r.slotid AS slotid,
                    COALESCE(s.slot, 0) AS slot,
                    COALESCE(q.questiontext, '') AS questiontext,
                    COALESCE(q.markingguidance, '') AS markingguidance,
                    COALESCE(q.markingguidanceformat, 1) AS markingguidanceformat,
                    COALESCE(s.maxmark, 1.0) AS maxmark,
                    COALESCE(q.qtype, 'essay') AS qtype
               FROM {learnguard_responses} r
               LEFT JOIN {learnguard_slots} s ON s.id = r.slotid
               LEFT JOIN {learnguard_qbank} q ON q.id = s.questionid
              WHERE r.attemptid $nawSql
                AND (q.qtype IN ('essay', 'multichoice', 'multicheck', 'shortanswer', 'truefalse') OR q.id IS NULL)
              ORDER BY r.attemptid, COALESCE(s.slot, 0)",
            $nawP
        );
        foreach ($rspRs as $r) {
            $aid = (int)$r->attemptid;
            $sid = (int)$r->slotid;
            if (!isset($perAttemptSlotData[$aid][$sid])) {
                $perAttemptSlotData[$aid][$sid] = $r;
            }
        }
        $rspRs->close();
    }

    // Collect all unique essay slot IDs across every attempt for batch queries.
    $allEssaySlotIds = [];
    foreach ($perAttemptSlotData as $attSlots) {
        foreach (array_keys($attSlots) as $sid) {
            $allEssaySlotIds[$sid] = $sid;
        }
    }
    $allEssaySlotIdsList = array_values($allEssaySlotIds);

    if (!empty($allEssaySlotIdsList)) {
        [$sidSql, $sidP] = $DB->get_in_or_equal($allEssaySlotIdsList, SQL_PARAMS_NAMED, 'sid');

        // Responses: determine if each slot has a non-blank answer.
        $respRs  = $DB->get_recordset_sql(
            "SELECT r.id, r.attemptid, r.slotid, r.responsedata
               FROM {learnguard_responses} r
              WHERE r.attemptid $aidSql AND r.slotid $sidSql",
            array_merge($aidP, $sidP)
        );
        $respSet = [];
        foreach ($respRs as $rr) {
            $d   = json_decode($rr->responsedata, true) ?? [];
            $has = false;
            foreach ($d as $v) { if (trim((string)$v) !== '') { $has = true; break; } }
            $respSet[$rr->attemptid . '_' . $rr->slotid] = $has;
        }
        $respRs->close();

        // Grades submitted by ANY grader (not just the current user).
        $gradeRs  = $DB->get_recordset_sql(
            "SELECT eg.id, eg.attemptid, eg.slotid
               FROM {learnguard_essay_grades} eg
              WHERE eg.attemptid $aidSql AND eg.slotid $sidSql",
            array_merge($aidP, $sidP)
        );
        $gradedSet = [];
        foreach ($gradeRs as $gr) { $gradedSet[$gr->attemptid . '_' . $gr->slotid] = true; }
        $gradeRs->close();

        // Build per-attempt, per-slot status map.
        $autoGradedTypes = ['multichoice', 'multicheck', 'shortanswer', 'truefalse'];
        foreach ($perAttemptSlotData as $aid => $slots) {
            foreach ($slots as $sid => $slot) {
                $k = $aid . '_' . $sid;
                if (!empty($gradedSet[$k])) {
                    $slotStatusMap[$aid][$sid] = 'graded';
                } elseif (!empty($respSet[$k]) && in_array($slot->qtype, $autoGradedTypes, true)) {
                    // Auto-graded question with a response should be treated as graded.
                    $slotStatusMap[$aid][$sid] = 'graded';
                } elseif (!empty($respSet[$k])) {
                    $slotStatusMap[$aid][$sid] = 'rg';
                } else {
                    $slotStatusMap[$aid][$sid] = 'na';
                }
            }
        }
    }

    // Manually gradable question grade totals (from ANY grader).
    $egrRows = $DB->get_records_sql(
        "SELECT attemptid, SUM(grade) AS essay_total FROM {learnguard_essay_grades}
          WHERE attemptid $aidSql GROUP BY attemptid",
        $aidP
    );
    foreach ($egrRows as $r) { $essayGradeMap[(int)$r->attemptid] = (float)$r->essay_total; }
}

// Per-attempt essay max grade: sum of actual slot maxmarks for all assigned essay questions.
// All essay slots are shown to the grader (including blank/unanswered ones).
$perAttemptMaxGrade = [];
foreach ($perAttemptSlotData as $aid => $slots) {
    $maxSum = 0.0;
    foreach ($slots as $slot) {
        $maxSum += (float)$slot->maxmark;
    }
    $perAttemptMaxGrade[$aid] = $maxSum;
}

// Fallback global max used when per-attempt denominator is unavailable.
$maxGrade = array_sum(array_column($essaySlots, 'maxmark'));

// Per-attempt essay slot list for JS (answered/graded slots only).
$perAttemptSlots = [];
foreach ($perAttemptSlotData as $aid => $slots) {
    $perAttemptSlots[$aid] = array_map(function($s) {
        return ['slotid' => (int)$s->slotid, 'slot' => (int)$s->slot, 'maxmark' => (float)$s->maxmark];
    }, array_values($slots));
}

// Separate students into not-yet-graded / fully-graded buckets.
$notGradedStudents = [];
$gradedStudents    = [];
foreach ($students as $uid => $s) {
    $allGraded = true;
    foreach ($s['attempts'] as $att) {
        $aid     = (int)$att->id;
        $attSids = array_keys($perAttemptSlotData[$aid] ?? []);
        if (empty($attSids)) { continue; } // no answered essays — nothing to grade
        foreach ($attSids as $sid) {
            if (($slotStatusMap[$aid][$sid] ?? 'na') !== 'graded') { $allGraded = false; break 2; }
        }
    }
    if ($allGraded && !empty($s['attempts'])) { $gradedStudents[$uid]    = $s; }
    else                                      { $notGradedStudents[$uid] = $s; }
}

$totalAttempts = count($rows);

echo $OUTPUT->header();
echo learnguard_activity_tabs($id, 'grader');
?>
<style>
/* ── edwiser_grader faithful clone ───────────────────────────────────────── */
.edg-wrap           { font-family:"Segoe UI",system-ui,sans-serif; }

/* Sliding detail panel — fixed at top of page, full-width */
.edg-detail-panel   { display:none; background:#212529; color:#fff;
                      position:fixed; top:0; left:0; right:0; z-index:1050;
                      box-shadow:0 4px 20px rgba(0,0,0,.4); }
.edg-detail-panel.open { display:block; }
.edg-detail-header  { background:#1a1f23; padding:10px 16px;
                      display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
.edg-hq-pill        { background:#495057; color:#fff; border:none; border-radius:4px;
                      padding:5px 12px; font-size:13px; font-weight:700; cursor:pointer; }
.edg-hq-pill.active { background:#0f6cbf; }
.edg-hq-pill.ok     { background:#28a745; }
.edg-hq-pill.rg     { background:#0f6cbf; }
.edg-hq-pill.na     { background:#dc3545; }
.edg-detail-status  { background:#2c3036; padding:8px 16px; font-size:12px;
                      display:flex; gap:16px; flex-wrap:wrap; align-items:center; }
.edg-state-badge    { background:#ffc107; color:#212529; padding:2px 8px;
                      border-radius:4px; font-weight:700; font-size:11px; }
.edg-state-badge.graded { background:#28a745; color:#fff; }
.edg-state-badge.na     { background:#6c757d; color:#fff; }
.edg-detail-content { display:grid; grid-template-columns:1fr 340px;
                      height:calc(100vh - 140px); overflow:hidden; }
.edg-qa-panel       { overflow-y:auto; padding:16px 20px;
                      border-right:1px solid #343a40; }
.edg-qa-panel h5    { font-size:14px; font-weight:700; color:#adb5bd; margin:0 0 6px; }
.edg-question-text  { font-size:14px; line-height:1.7; color:#e9ecef; margin-bottom:16px; }
.edg-answer-box     { background:#2c3036; border-radius:6px; padding:14px;
                      font-size:13px; line-height:1.8; color:#dee2e6;
                      white-space:pre-wrap; min-height:80px; }
.edg-guide-box      { background:#332900; border:1px solid #664d00; border-radius:6px;
                      padding:12px 14px; font-size:13px; color:#ffd966; margin-top:12px; }
.edg-cm-panel       { background:#1e2328; overflow-y:auto; padding:16px;
                      display:flex; flex-direction:column; }
.edg-cm-title       { font-size:15px; font-weight:700; color:#f8f9fa; margin-bottom:12px; }
.edg-comment-ta     { width:100%; background:#2c3036; border:1px solid #495057;
                      border-radius:6px; color:#dee2e6; padding:10px 12px;
                      font-size:13px; resize:vertical; min-height:100px; box-sizing:border-box; }
.edg-comment-ta:focus { border-color:#0f6cbf; outline:none; }
.edg-marks-row      { display:flex; align-items:center; gap:10px; margin-top:14px; flex-wrap:wrap; }
.edg-mark-in        { width:80px; background:#2c3036; border:2px solid #495057;
                      border-radius:6px; color:#fff; padding:8px 12px;
                      font-size:18px; font-weight:700; text-align:center; }
.edg-mark-in:focus  { border-color:#0f6cbf; outline:none; }
.edg-mark-total     { font-size:14px; color:#adb5bd; }
.edg-submit-btn     { width:100%; background:#0f6cbf; color:#fff; border:none;
                      border-radius:6px; padding:11px; font-size:15px;
                      font-weight:700; cursor:pointer; margin-top:14px; }
.edg-submit-btn:hover { background:#0a52a2; }
.edg-pct-btns       { display:flex; gap:4px; flex-wrap:wrap; margin-top:8px; }
.edg-pct-btn        { padding:3px 9px; border:1px solid #495057; border-radius:10px;
                      background:#2c3036; color:#adb5bd; font-size:11px; cursor:pointer; }
.edg-saved-badge    { background:#d4edda; color:#155724; border-radius:4px;
                      padding:6px 10px; font-size:12px; margin-top:10px; display:none; }

/* Page header */
.edg-page-header    { background:<?php echo htmlspecialchars($brand->primary); ?>;
                      color:<?php echo htmlspecialchars($brand->textcolor); ?>;
                      padding:12px 20px; display:flex; align-items:center;
                      justify-content:space-between; flex-wrap:wrap; gap:8px; }
.edg-page-header h5 { margin:0; font-size:16px; font-weight:700; }
.edg-page-header small { font-size:12px; opacity:.8; display:block; }
.edg-back-btn       { background:rgba(255,255,255,.2); color:inherit;
                      border:1px solid rgba(255,255,255,.4); border-radius:6px;
                      padding:7px 14px; font-size:13px; font-weight:700;
                      text-decoration:none; }
.edg-stats-bar      { background:#fff; border-bottom:1px solid #f0f0f0;
                      padding:9px 20px; font-size:13px; color:#495057;
                      display:flex; gap:20px; flex-wrap:wrap; }
.edg-tabs           { display:flex; border-bottom:2px solid #dee2e6;
                      padding:0 20px; background:#fff; }
.edg-tab            { padding:10px 18px; font-size:13px; font-weight:700;
                      color:#6c757d; border-bottom:3px solid transparent;
                      margin-bottom:-2px; cursor:pointer; background:none;
                      border-top:none; border-left:none; border-right:none; }
.edg-tab.active     { color:#0f6cbf; border-bottom-color:#0f6cbf; }
.edg-tab-pane       { display:none; }
.edg-tab-pane.show  { display:block; }
.edg-legend         { padding:8px 20px; background:#fff; display:flex; gap:14px;
                      font-size:12px; color:#495057; border-bottom:1px solid #f4f4f4; }
.edg-rg             { background:#0f6cbf; color:#fff; border-radius:3px; padding:1px 6px; font-weight:700; }
.edg-na             { background:#dc3545; color:#fff; border-radius:3px; padding:1px 6px; font-weight:700; }

/* Student rows */
.edg-student-row    { border-bottom:1px solid #f0f0f0; background:#fff; }
.edg-row-top        { display:flex; padding:12px 16px; gap:16px; flex-wrap:wrap; align-items:flex-start; }
.edg-stinfo         { display:flex; align-items:center; gap:10px; min-width:200px; }
.edg-avatar         { width:40px; height:40px; border-radius:50%;
                      background:#0f6cbf; color:#fff; font-weight:700;
                      font-size:15px; display:flex; align-items:center;
                      justify-content:center; flex-shrink:0; }
.edg-stname         { font-weight:600; color:#212529; font-size:14px; }
.edg-stemail        { font-size:11px; color:#6c757d; }
.edg-grade-section  { margin-left:8px; }
.edg-grade-label    { font-size:11px; color:#6c757d; margin-bottom:2px; }
.edg-grade-val      { font-size:15px; font-weight:700; color:#212529; }
.edg-att-area       { margin-left:auto; }
.edg-attempt-tabs   { display:flex; gap:4px; align-items:center; margin-bottom:6px; }
.edg-att-tab        { background:#f8f9fa; border:1px solid #dee2e6; border-radius:4px;
                      padding:4px 10px; font-size:12px; font-weight:700;
                      cursor:pointer; color:#495057; }
.edg-att-tab.active { background:#0f6cbf; color:#fff; border-color:#0f6cbf; }
.edg-att-panel      { display:none; }
.edg-att-panel.active { display:block; }
.edg-att-meta       { font-size:11px; color:#6c757d; display:flex; gap:16px; flex-wrap:wrap; align-items:center; }
.edg-row-bottom     { padding:8px 16px 14px; background:#fafafa; border-top:1px solid #f4f4f4; }
.edg-q-pills        { display:flex; gap:5px; flex-wrap:wrap; align-items:center; }
.edg-qpill          { display:inline-block; padding:3px 10px; border-radius:4px;
                      font-size:12px; font-weight:700; color:#fff; cursor:pointer; }
.edg-qpill.rg       { background:#0f6cbf; }
.edg-qpill.na       { background:#dc3545; }
.edg-qpill.ok       { background:#28a745; }
.edg-qpill:hover    { opacity:.85; }
.edg-empty          { padding:40px 20px; text-align:center; color:#6c757d; font-size:14px; background:#fff; }

@media(max-width:768px){ .edg-detail-content{ grid-template-columns:1fr; } }

/* ── Light mode overrides ─────────────────────────────────────────────── */
.edg-wrap.edg-light .edg-detail-panel  { background:#fff; color:#212529; box-shadow:0 4px 20px rgba(0,0,0,.15); }
.edg-wrap.edg-light .edg-detail-header { background:#f1f3f5; border-bottom:1px solid #dee2e6; }
.edg-wrap.edg-light .edg-hq-pill       { background:#dee2e6; color:#212529; }
.edg-wrap.edg-light .edg-hq-pill.active{ background:#0f6cbf; color:#fff; }
.edg-wrap.edg-light .edg-hq-pill.ok    { background:#28a745; color:#fff; }
.edg-wrap.edg-light .edg-hq-pill.rg    { background:#0f6cbf; color:#fff; }
.edg-wrap.edg-light #edg-theme-btn     { background:#0f6cbf; color:#fff; }
.edg-wrap.edg-light #edg-history-btn   { color:#fff; }
.edg-wrap.edg-light .edg-detail-status { background:#e9ecef; }
.edg-wrap.edg-light #edg-stat-student  { color:#212529; }
.edg-wrap.edg-light .edg-qa-panel      { background:#fff; border-right-color:#dee2e6; }
.edg-wrap.edg-light .edg-qa-panel h5   { color:#495057; }
.edg-wrap.edg-light .edg-question-text { color:#212529; }
.edg-wrap.edg-light .edg-answer-box    { background:#f8f9fa; color:#212529; border:1px solid #dee2e6; }
.edg-wrap.edg-light .edg-guide-box     { background:#fffbea; color:#664d00; border-color:#ffe066; }
.edg-wrap.edg-light .edg-cm-panel      { background:#f8f9fa; }
.edg-wrap.edg-light .edg-cm-title      { color:#212529; }
.edg-wrap.edg-light .edg-comment-ta    { background:#fff; border-color:#ced4da; color:#212529; }
.edg-wrap.edg-light .edg-comment-ta:focus { border-color:#0f6cbf; }
.edg-wrap.edg-light .edg-mark-in       { background:#fff; border-color:#ced4da; color:#212529; }
.edg-wrap.edg-light .edg-mark-total    { color:#495057; }
.edg-wrap.edg-light .edg-pct-btn       { background:#fff; color:#495057; border-color:#ced4da; }
.edg-wrap.edg-light .edg-saved-badge   { background:#d4edda; color:#155724; }
</style>

<div class="edg-wrap">

<!-- ── Sliding question detail panel ────────────────────────────────────── -->
<div id="edg-detail-panel" class="edg-detail-panel">

  <!-- Header: question nav pills + close -->
  <div class="edg-detail-header">
    <div id="edg-q-nav" style="display:flex;gap:4px;flex-wrap:wrap;flex:1;align-items:center;">
      <!-- Filled by JS when a pill is clicked -->
    </div>
    <div style="display:flex;gap:8px;align-items:center;margin-left:auto;">
      <button class="edg-hq-pill" id="edg-theme-btn" onclick="edgToggleTheme()">☀ Light</button>
      <button class="edg-hq-pill" id="edg-fs-btn" onclick="edgToggleFullscreen()" title="Toggle fullscreen">⛶ Fullscreen</button>
      <button class="edg-hq-pill" id="edg-history-btn" onclick="edgResponseHistory()" style="background:#6c757d;">📜 History</button>
      <button class="edg-hq-pill" onclick="edgClosePanel()" style="background:#dc3545;">✕ Close</button>
    </div>
  </div>

  <!-- Status bar -->
  <div class="edg-detail-status">
    <span id="edg-state-badge" class="edg-state-badge">Requires Grading</span>
    <span id="edg-stat-student" style="font-weight:700;color:#fff;"></span>
    <span>Q#: <strong id="edg-stat-qnum">—</strong></span>
    <span>Type: <strong id="edg-stat-qtype">Question</strong></span>
    <span>Status: <strong id="edg-stat-status">—</strong></span>
    <span>Marks: <strong id="edg-stat-marks">—</strong></span>
  </div>

  <!-- Left: question + answer + guide | Right: comment + mark + submit -->
  <div class="edg-detail-content">
    <div class="edg-qa-panel">
      <h5>Question</h5>
      <div id="edg-question-text" class="edg-question-text"></div>
      <h5>Student Response</h5>
      <div id="edg-answer-box" class="edg-answer-box"></div>
      <div id="edg-guide-wrap" style="display:none;">
        <h5 style="margin-top:14px;">📋 Marking Guide</h5>
        <div id="edg-guide-box" class="edg-guide-box"></div>
      </div>
    </div>

    <div class="edg-cm-panel">
      <div class="edg-cm-title">Comment and Grade</div>
      <textarea id="edg-feedback-ta" class="edg-comment-ta" rows="5"
                placeholder="Enter feedback for the student (optional)…"></textarea>

      <div class="edg-marks-row">
        <span style="font-size:14px;font-weight:700;color:#adb5bd;">Mark</span>
        <input type="number" id="edg-mark-in" class="edg-mark-in" min="0" step="any" placeholder="0">
        <span id="edg-mark-total" class="edg-mark-total">/ —</span>
      </div>
      <div class="edg-pct-btns" id="edg-pct-btns"></div>

      <button class="edg-submit-btn" onclick="edgSaveGrade()">
        ✅ Submit &nbsp;<span id="edg-submit-spin" style="display:none;">⏳</span>
      </button>
      <div id="edg-saved-badge" class="edg-saved-badge">✅ Grade saved successfully.</div>
    </div>
  </div>
</div>

<!-- ── Main grader page ──────────────────────────────────────────────────── -->
<div id="edg-grader-page">

  <!-- Page header -->
  <div class="edg-page-header">
    <div>
      <small><?php echo format_string($course->fullname); ?></small>
      <h5><?php echo format_string($lg->name); ?></h5>
    </div>
    <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
      <?php if ($anonymous): ?>
        <span style="background:rgba(255,255,255,.2);border-radius:10px;padding:3px 10px;font-size:12px;">
          👤 Anonymous Marking
        </span>
      <?php endif; ?>
      <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']); ?>"
         class="edg-back-btn">← Go Back</a>
    </div>
  </div>

  <!-- Stats bar -->
  <div class="edg-stats-bar">
    <span>Attempts: <strong><?php echo $totalAttempts; ?></strong></span>
    <span>Students: <strong><?php echo count($students); ?></strong></span>
    <span>Grading method: <strong>Essay / Manual</strong></span>
    <?php if ($anonymous): ?>
      <span>Mode: <strong>Anonymous Layer <?php echo $gradeLayer; ?></strong></span>
    <?php endif; ?>
    <?php if ($isManager): ?>
      <span style="margin-left:auto;">
        <button class="btn btn-sm btn-outline-warning" id="edg-recalc-btn"
                onclick="edgRecalcAll()" title="Recompute sumgrades for all finished attempts using the current scoring formula">
          🔄 Recalculate All Scores
        </button>
        <button class="btn btn-sm btn-outline-danger" id="edg-reset-btn"
                onclick="edgResetEssayGrades()" title="Delete ALL essay grades and reset scores to pending so grading can start fresh">
          🗑 Reset Essay Grades
        </button>
      </span>
    <?php endif; ?>
  </div>

  <!-- Tabs: Not Yet Graded | Graded -->
  <div class="edg-tabs">
    <button class="edg-tab active" id="edg-tab-ng" onclick="edgTab('ng')">
      Not Yet Graded &nbsp;<span style="background:#dee2e6;border-radius:10px;padding:1px 8px;font-size:11px;"><?php echo count($notGradedStudents); ?></span>
    </button>
    <button class="edg-tab" id="edg-tab-g" onclick="edgTab('g')">
      Graded &nbsp;<span style="background:#dee2e6;border-radius:10px;padding:1px 8px;font-size:11px;"><?php echo count($gradedStudents); ?></span>
    </button>
  </div>

  <!-- Legend -->
  <div class="edg-legend">
    <span><strong><span class="edg-rg">RG</span></strong> Requires Grading</span>
    <span><strong><span class="edg-na">NA</span></strong> Not Answered</span>
    <span><strong style="color:#28a745;">✓</strong> Graded by me</span>
  </div>

  <!-- Not Yet Graded -->
  <div id="edg-pane-ng" class="edg-tab-pane show">
    <?php if (empty($notGradedStudents)): ?>
      <div class="edg-empty">🎉 All students have been fully graded!</div>
    <?php else: ?>
      <?php foreach ($notGradedStudents as $s):
            echo edg_render_student_row($s, $perAttemptSlotData, $slotStatusMap, $essayGradeMap, $perAttemptMaxGrade, $anonymous, $isManager, $id);
      endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Graded -->
  <div id="edg-pane-g" class="edg-tab-pane">
    <?php if (empty($gradedStudents)): ?>
      <div class="edg-empty">No students fully graded yet.</div>
    <?php else: ?>
      <?php foreach ($gradedStudents as $s):
            echo edg_render_student_row($s, $perAttemptSlotData, $slotStatusMap, $essayGradeMap, $perAttemptMaxGrade, $anonymous, $isManager, $id);
      endforeach; ?>
    <?php endif; ?>
  </div>

</div><!-- /edg-grader-page -->
</div><!-- /edg-wrap -->

<script>
var edgAjaxUrl         = '<?php echo (new moodle_url('/mod/learnguard/grader.php'))->out(false); ?>';
var edgId              = <?php echo (int)$id; ?>;
var edgSesskey         = '<?php echo sesskey(); ?>';
var edgEssaySlots      = <?php echo json_encode(array_map(function($s){ return ['slotid' => (int)$s->slotid, 'slot' => (int)$s->slot, 'maxmark' => (float)$s->maxmark]; }, $essaySlots)); ?>;
var edgPerAttemptSlots = <?php echo json_encode($perAttemptSlots); ?>;

var edgCurrentAttemptId     = 0;
var edgCurrentSlotId        = 0;
var edgCurrentSlotIdx       = 0;
var edgCurrentAttemptSlots  = []; // filtered slot list for the active attempt

function edgTab(t) {
    document.querySelectorAll('.edg-tab').forEach(function(b){ b.classList.remove('active'); });
    document.querySelectorAll('.edg-tab-pane').forEach(function(p){ p.classList.remove('show'); });
    document.getElementById('edg-tab-'  + t).classList.add('active');
    document.getElementById('edg-pane-' + t).classList.add('show');
    edgClosePanel();
}

function edgToggleAttempt(userid, attemptid) {
    document.querySelectorAll('.edg-att-panel[data-user="' + userid + '"]').forEach(function(p){ p.classList.remove('active'); });
    document.querySelectorAll('.edg-att-tab[data-user="'   + userid + '"]').forEach(function(t){ t.classList.remove('active'); });
    var panel = document.querySelector('.edg-att-panel[data-attempt="' + attemptid + '"]');
    var tab   = document.querySelector('.edg-att-tab[data-attempt="'   + attemptid + '"]');
    if (panel) panel.classList.add('active');
    if (tab)   tab.classList.add('active');
}

function edgOpenQuestion(attemptid, slotid, slotidx) {
    edgCurrentAttemptId    = attemptid;
    edgCurrentSlotId       = slotid;
    edgCurrentSlotIdx      = slotidx;
    // Use the per-attempt filtered slot list; fall back to all essay slots.
    edgCurrentAttemptSlots = edgPerAttemptSlots[attemptid] || edgEssaySlots;

    // Build the question nav bar from the attempt's actual slots.
    var nav = document.getElementById('edg-q-nav');
    nav.innerHTML = '';
    edgCurrentAttemptSlots.forEach(function(slot, i) {
        var pill = document.createElement('button');
        pill.className = 'edg-hq-pill' + (i === slotidx ? ' active' : '');
        pill.textContent = 'Q' + (i + 1);
        pill.onclick = (function(s, idx){ return function(){ edgOpenQuestion(attemptid, s.slotid, idx); }; })(slot, i);
        nav.appendChild(pill);
    });

    // Show panel immediately with loading state.
    document.getElementById('edg-detail-panel').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('edg-question-text').innerHTML = '<em style="color:#adb5bd;">Loading…</em>';
    document.getElementById('edg-answer-box').innerHTML    = '';
    document.getElementById('edg-feedback-ta').value       = '';
    document.getElementById('edg-mark-in').value           = '';
    document.getElementById('edg-stat-qnum').textContent   = slotidx + 1;
    document.getElementById('edg-saved-badge').style.display = 'none';

    // Fetch question data.
    var url = edgAjaxUrl + '?id=' + edgId + '&lgajax=1&action=get_question&sesskey=' + edgSesskey
            + '&attemptid=' + attemptid + '&slotid=' + slotid;
    fetch(url).then(function(r){ return r.json(); }).then(function(d) {
        if (!d.ok) {
            document.getElementById('edg-question-text').innerHTML =
                '<em style="color:#dc3545;">⚠ ' + (d.error || 'Failed to load question') + '</em>';
            document.getElementById('edg-answer-box').innerHTML = '';
            return;
        }
        document.getElementById('edg-question-text').innerHTML = d.questiontext;
        document.getElementById('edg-answer-box').innerHTML = d.answer;

        if (d.markingguidance) {
            document.getElementById('edg-guide-wrap').style.display = 'block';
            document.getElementById('edg-guide-box').innerHTML = d.markingguidance;
        } else {
            document.getElementById('edg-guide-wrap').style.display = 'none';
        }

        var qTypeLabel = {
            'essay':'Essay',
            'multichoice':'MCQ',
            'multicheck':'Multi-Select',
            'shortanswer':'Short Answer',
            'truefalse':'True / False'
        }[d.qtype] || 'Question';
        document.getElementById('edg-mark-total').textContent  = '/ ' + d.maxmark;
        document.getElementById('edg-stat-marks').textContent  = d.existing_grade !== null ? d.existing_grade + '/' + d.maxmark : 'RG';
        document.getElementById('edg-stat-status').textContent = d.has_answer ? 'Complete' : 'Not answered';
        document.getElementById('edg-stat-student').textContent = d.student_name;
        document.getElementById('edg-stat-qtype').textContent = qTypeLabel;
        
        var badge = document.getElementById('edg-state-badge');
        if (!d.has_answer) {
            badge.textContent = 'Not Answered'; badge.className = 'edg-state-badge na';
        } else if (d.existing_grade !== null) {
            if (d.graded_by === 'You') {
                badge.textContent = 'Graded'; badge.className = 'edg-state-badge graded';
            } else if (d.graded_by) {
                var byText = d.graded_by === 'Auto' ? 'Auto-graded' : 'Graded by ' + d.graded_by;
                badge.innerHTML = byText + ' <em style="font-size:11px;opacity:0.8;">(you can override)</em>';
                badge.className = 'edg-state-badge graded';
            }
        } else {
            badge.textContent = 'Requires Grading'; badge.className = 'edg-state-badge';
        }

        if (d.existing_grade !== null)   { document.getElementById('edg-mark-in').value     = d.existing_grade; }
        if (d.existing_feedback)         { document.getElementById('edg-feedback-ta').value  = d.existing_feedback; }
        document.getElementById('edg-mark-in').max = d.maxmark;

        // Quick-percent buttons.
        var pctContainer = document.getElementById('edg-pct-btns');
        pctContainer.innerHTML = '';
        [0, 25, 50, 75, 100].forEach(function(pct) {
            var val = Math.round(d.maxmark * pct / 100 * 10) / 10;
            var btn = document.createElement('button');
            btn.className = 'edg-pct-btn';
            btn.textContent = pct + '%';
            btn.onclick = function(){ document.getElementById('edg-mark-in').value = val; };
            pctContainer.appendChild(btn);
        });
    }).catch(function(){
        document.getElementById('edg-question-text').innerHTML = '<em style="color:#dc3545;">Failed to load. Please try again.</em>';
    });
}

function edgSaveGrade() {
    var grade    = document.getElementById('edg-mark-in').value;
    var feedback = document.getElementById('edg-feedback-ta').value;
    if (grade === '' || grade === null) { alert('Please enter a mark.'); return; }
    var maxAllowed = parseFloat(document.getElementById('edg-mark-in').max);
    if (!isNaN(maxAllowed) && parseFloat(grade) > maxAllowed) {
        alert('Grade cannot exceed the maximum mark of ' + maxAllowed + '.');
        return;
    }
    var btn = document.querySelector('.edg-submit-btn');
    btn.disabled = true;
    document.getElementById('edg-submit-spin').style.display = 'inline';

    fetch(edgAjaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + edgId + '&lgajax=1&action=save_grade&sesskey=' + edgSesskey
            + '&attemptid=' + edgCurrentAttemptId + '&slotid=' + edgCurrentSlotId
            + '&grade=' + encodeURIComponent(grade) + '&feedback=' + encodeURIComponent(feedback),
    }).then(function(r){ return r.json(); }).then(function(d) {
        btn.disabled = false;
        document.getElementById('edg-submit-spin').style.display = 'none';
        if (d.ok) {
            document.getElementById('edg-saved-badge').style.display = 'block';
            // Update the pill in the student row.
            var pill = document.querySelector('.edg-qpill[data-attempt="' + edgCurrentAttemptId + '"][data-slotid="' + edgCurrentSlotId + '"]');
            if (pill) { pill.className = 'edg-qpill ok'; pill.textContent = 'Q' + (edgCurrentSlotIdx + 1) + ' ✓'; }
            // Update nav pill.
            var navPills = document.querySelectorAll('#edg-q-nav .edg-hq-pill');
            if (navPills[edgCurrentSlotIdx]) { navPills[edgCurrentSlotIdx].className = 'edg-hq-pill active ok'; }
            // Advance to next question in this attempt's slot list.
            setTimeout(function() {
                var nextIdx = edgCurrentSlotIdx + 1;
                if (nextIdx < edgCurrentAttemptSlots.length) {
                    edgOpenQuestion(edgCurrentAttemptId, edgCurrentAttemptSlots[nextIdx].slotid, nextIdx);
                }
            }, 800);
        }
    }).catch(function() {
        btn.disabled = false;
        document.getElementById('edg-submit-spin').style.display = 'none';
        alert('Failed to save grade. Please try again.');
    });
}

function edgClosePanel() {
    document.getElementById('edg-detail-panel').classList.remove('open');
    document.body.style.overflow = '';
}

function edgResponseHistory() {
    if (!edgCurrentAttemptId) { return; }
    window.open('<?php echo (new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id]))->out(false); ?>&attempt=' + edgCurrentAttemptId, '_blank');
}

// ── Light / dark mode toggle ─────────────────────────────────────────────────
(function() {
    var wrap = document.querySelector('.edg-wrap');
    var btn  = document.getElementById('edg-theme-btn');
    var light = localStorage.getItem('edgTheme') === 'light';
    function applyTheme() {
        if (light) {
            wrap.classList.add('edg-light');
            btn.textContent = '🌙 Dark';
        } else {
            wrap.classList.remove('edg-light');
            btn.textContent = '☀ Light';
        }
    }
    applyTheme();
    window.edgToggleTheme = function() {
        light = !light;
        localStorage.setItem('edgTheme', light ? 'light' : 'dark');
        applyTheme();
    };
})();

function edgVoidAttempt(attemptid, btn) {
    if (!confirm('Void this attempt and allow the student to retake?')) { return; }
    btn.disabled = true;
    fetch(edgAjaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + edgId + '&lgajax=1&action=void_attempt&sesskey=' + edgSesskey + '&vaid=' + attemptid,
    }).then(function(r){ return r.json(); }).then(function(d) {
        if (d.ok) {
            var row = document.querySelector('[data-void-attempt="' + attemptid + '"]');
            if (row) { row.style.opacity = '.4'; }
            btn.textContent = 'Voided';
            btn.disabled = true;
        }
    });
}

function edgToggleFullscreen() {
    var panel = document.getElementById('edg-detail-panel');
    var isFs  = !!(document.fullscreenElement || document.webkitFullscreenElement);
    if (isFs) {
        if (document.exitFullscreen) { document.exitFullscreen(); }
        else if (document.webkitExitFullscreen) { document.webkitExitFullscreen(); }
    } else if (panel) {
        if (panel.requestFullscreen) { panel.requestFullscreen(); }
        else if (panel.webkitRequestFullscreen) { panel.webkitRequestFullscreen(); }
    }
}
function edgUpdateFsBtn() {
    var btn  = document.getElementById('edg-fs-btn');
    var isFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
    if (btn) { btn.textContent = isFs ? '⛶ Exit FS' : '⛶ Fullscreen'; }
}
document.addEventListener('fullscreenchange', edgUpdateFsBtn);
document.addEventListener('webkitfullscreenchange', edgUpdateFsBtn);

function edgRecalcAll() {
    var btn = document.getElementById('edg-recalc-btn');
    if (!btn || !confirm('Recalculate scores for all finished attempts using the current formula?\n\nThis updates both the activity score and the Moodle gradebook.')) { return; }
    btn.disabled = true;
    btn.textContent = '⏳ Recalculating…';
    fetch(edgAjaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + edgId + '&lgajax=1&action=recalc_all&sesskey=' + edgSesskey,
    }).then(function(r){ return r.json(); }).then(function(d) {
        if (d.ok) {
            btn.textContent = '✅ Done (' + d.count + ' attempt' + (d.count !== 1 ? 's' : '') + ' updated)';
            btn.classList.remove('btn-outline-warning');
            btn.classList.add('btn-outline-success');
        } else {
            btn.textContent = '❌ Error';
            btn.disabled = false;
        }
    }).catch(function() {
        btn.textContent = '❌ Error';
        btn.disabled = false;
    });
}
function edgResetEssayGrades() {
    var btn = document.getElementById('edg-reset-btn');
    if (!btn || !confirm('⚠ WARNING: This will delete ALL essay grades for this activity and reset all attempt scores to pending.\n\nStudents must be re-graded from scratch. This cannot be undone.\n\nProceed?')) { return; }
    btn.disabled = true;
    btn.textContent = '⏳ Resetting…';
    fetch(edgAjaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'id=' + edgId + '&lgajax=1&action=reset_essay_grades&sesskey=' + edgSesskey,
    }).then(function(r){ return r.json(); }).then(function(d) {
        if (d.ok) {
            btn.textContent = '✅ Done — reloading…';
            btn.classList.remove('btn-outline-danger');
            btn.classList.add('btn-outline-success');
            setTimeout(function(){ location.reload(); }, 1500);
        } else {
            btn.textContent = '❌ Error';
            btn.disabled = false;
        }
    }).catch(function() {
        btn.textContent = '❌ Error';
        btn.disabled = false;
    });
}
</script>

<?php
echo $OUTPUT->footer();

// ── Helper: format duration as "Xh Ym Zs" ────────────────────────────────────
function edg_format_duration(int $secs): string {
    if ($secs <= 0) { return '0s'; }
    $h = intdiv($secs, 3600); $m = intdiv($secs % 3600, 60); $s = $secs % 60;
    $parts = [];
    if ($h > 0) { $parts[] = $h . 'h'; }
    if ($m > 0) { $parts[] = $m . 'm'; }
    if ($s > 0 || empty($parts)) { $parts[] = $s . 's'; }
    return implode(' ', $parts);
}

// ── Helper: render one student row ───────────────────────────────────────────
function edg_render_student_row($s, $perAttemptSlotData, $slotStatusMap, $essayGradeMap, $perAttemptMaxGrade, $anonymous, $isManager, $cmid) {
    $initials    = strtoupper(substr($s['firstname'] ?? '?', 0, 1) . substr($s['lastname'] ?? '?', 0, 1));
    $displayName = $anonymous ? 'Candidate #' . array_values($s['attempts'])[0]->id : fullname((object)$s);
    $uid         = (int)$s['userid'];

    $latestAtt    = array_values($s['attempts'])[0];
    $latestAid    = (int)$latestAtt->id;
    // Denominator: sum of maxmarks for essay questions the student actually answered.
    $attMaxGrade  = $perAttemptMaxGrade[$latestAid] ?? 0;
    $gradeDisplay = isset($essayGradeMap[$latestAid]) && $attMaxGrade > 0
        ? number_format($essayGradeMap[$latestAid], 2) . ' / ' . number_format($attMaxGrade, 2)
        : ($attMaxGrade > 0 ? '— / ' . number_format($attMaxGrade, 2) : '—');

    $html = '<div class="edg-student-row">';
    // Top section: avatar + name/email + grade + attempt tabs
    $html .= '<div class="edg-row-top">';
    $html .= '<div class="edg-stinfo">';
    $html .= '<div class="edg-avatar">' . $initials . '</div>';
    $html .= '<div>';
    $html .= '<div class="edg-stname">' . s($displayName) . '</div>';
    if (!$anonymous) { $html .= '<div class="edg-stemail">' . s($s['email']) . '</div>'; }
    $html .= '</div>';
    $html .= '</div>'; // /edg-stinfo
    $html .= '<div class="edg-grade-section"><div class="edg-grade-label">Grade</div><div class="edg-grade-val">' . s($gradeDisplay) . '</div></div>';

    // Attempt tabs + meta (right side)
    $html .= '<div class="edg-att-area">';
    $html .= '<div class="edg-attempt-tabs">';
    foreach ($s['attempts'] as $i => $att) {
        $html .= '<button class="edg-att-tab' . ($i === 0 ? ' active' : '') . '"'
               . ' data-user="' . $uid . '" data-attempt="' . (int)$att->id . '"'
               . ' onclick="edgToggleAttempt(' . $uid . ',' . (int)$att->id . ')">'
               . (int)$att->attempt . '</button>';
    }
    $html .= '</div>'; // /edg-attempt-tabs
    foreach ($s['attempts'] as $i => $att) {
        $started  = $att->timestart  ? userdate((int)$att->timestart,  '%d %b %y %H:%M') : '—';
        $finished = $att->timefinish ? userdate((int)$att->timefinish, '%d %b %y %H:%M') : '—';
        $taken    = ($att->timestart && $att->timefinish)
                  ? edg_format_duration(max(0, (int)$att->timefinish - (int)$att->timestart)) : '—';
        $html .= '<div class="edg-att-panel' . ($i === 0 ? ' active' : '') . '"'
               . ' data-attempt="' . (int)$att->id . '" data-user="' . $uid . '"'
               . ' data-void-attempt="' . (int)$att->id . '">';
        $html .= '<div class="edg-att-meta">';
        $html .= '<span>Started: <strong>' . $started . '</strong></span>';
        $html .= '<span>Completed: <strong>' . $finished . '</strong></span>';
        $html .= '<span>Time taken: <strong>' . $taken . '</strong></span>';
        if ($isManager) {
            $html .= '<button class="btn btn-sm btn-outline-warning" style="font-size:11px;"'
                   . ' onclick="edgVoidAttempt(' . (int)$att->id . ',this)">🔄 Reattempt</button>';
        }
        $html .= '</div>';
        $html .= '</div>'; // /edg-att-panel
    }
    $html .= '</div>'; // /edg-att-area
    $html .= '</div>'; // /edg-row-top

    // Bottom: question pills per attempt — only slots the student actually received.
    $html .= '<div class="edg-row-bottom">';
    foreach ($s['attempts'] as $i => $att) {
        $aid           = (int)$att->id;
        $attEssaySlots = array_values($perAttemptSlotData[$aid] ?? []);
        $html .= '<div class="edg-att-panel' . ($i === 0 ? ' active' : '') . ' edg-q-pills"'
               . ' data-attempt="' . $aid . '" data-user="' . $uid . '">';
        foreach ($attEssaySlots as $qi => $slot) {
            $status = $slotStatusMap[$aid][(int)$slot->slotid] ?? 'na';
            $cls    = $status === 'graded' ? 'ok' : ($status === 'rg' ? 'rg' : 'na');
            $label  = $status === 'graded' ? 'Q' . ($qi+1) . ' ✓' : ($status === 'rg' ? 'Q' . ($qi+1) . ' RG' : 'Q' . ($qi+1) . ' NA');
            $html  .= '<span class="edg-qpill ' . $cls . '"'
                    . ' data-attempt="' . $aid . '" data-slotid="' . (int)$slot->slotid . '"'
                    . ' onclick="edgOpenQuestion(' . $aid . ',' . (int)$slot->slotid . ',' . $qi . ')">'
                    . s($label) . '</span>';
        }
        if (empty($attEssaySlots)) {
            $html .= '<em style="color:#6c757d;font-size:12px;padding:4px 0;">No essay questions answered in this attempt.</em>';
        }
        $html .= '</div>';
    }
    $html .= '</div>'; // /edg-row-bottom

    $html .= '</div>'; // /edg-student-row
    return $html;
}
