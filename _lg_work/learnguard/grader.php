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
$gview  = optional_param('gview',  '', PARAM_ALPHA); // '' | 'voided' | 'noattempt'

$cm      = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$lg      = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context   = context_module::instance($cm->id);
$isManager = has_capability('mod/learnguard:manage', $context);
$myGrader  = $DB->get_record('learnguard_graders', ['learnguard' => $cm->instance, 'userid' => $USER->id]);
// Also check for global grader (is_global=1) — can grade all activities site-wide.
if (!$myGrader) {
    try {
        $myGrader = $DB->get_record_sql(
            "SELECT * FROM {learnguard_graders}
              WHERE userid = :uid AND is_global = 1
           ORDER BY id LIMIT 1",
            ['uid' => $USER->id]
        ) ?: null;
    } catch (Exception $e) { $myGrader = null; }
}
$isGrader  = $isManager || !empty($myGrader);

if (!$isGrader) {
    throw new moodle_exception('accessdenied', 'error', '', 'You are not assigned as a grader for this assessment.');
}

// Choice Essay grading rubric (activity-wide) — loaded once, reused by the get_question/
// save_rubric_grade AJAX handlers below. Empty when the admin hasn't enabled/built one.
// Wrapped in try/catch the same way learnguard_grader_units/learnguard_grading_units
// already are elsewhere in this file — rubric_enabled and this table are added by the
// same upgrade step, but a table lookup (unlike a missing column, which degrades safely
// to null) throws outright if the step never completed, and that must never take down
// the whole grading page for every activity, not just a misconfigured one.
$rubricEnabled = !empty($lg->rubric_enabled);
$rubricCriteriaRows = [];
if ($rubricEnabled) {
    try {
        $rubricCriteriaRows = array_values($DB->get_records('learnguard_rubric_criteria',
            ['learnguard' => $cm->instance], 'sortorder ASC'));
    } catch (Exception $e) { $rubricCriteriaRows = []; }
}
$rubricCriteriaMap = [];
foreach ($rubricCriteriaRows as $rc) { $rubricCriteriaMap[(int)$rc->id] = $rc; }

// Delete this entire assessment (course module) — same effect as deleting it from the
// course page via Moodle's own course_delete_module(), so gradebook/calendar/context
// cleanup happens the normal, safe way. Destructive and irreversible: removes every
// attempt, score, chat message, snapshot, audio clip, and incident tied to this
// activity. It also disappears from the Score Matrix automatically afterward, since
// that page reads the live learnguard/learnguard_attempts tables.
if (data_submitted() && confirm_sesskey() && optional_param('gaction', '', PARAM_ALPHA) === 'deleteassessment') {
    require_capability('mod/learnguard:manage', $context);
    require_capability('moodle/course:manageactivities', $context);
    require_once($CFG->dirroot . '/course/lib.php');
    $deletedName = format_string($lg->name);
    course_delete_module($cm->id);
    redirect(new moodle_url('/course/view.php', ['id' => $course->id]),
        'Assessment "' . $deletedName . '" and all its data have been permanently deleted.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// Void every finished/suspended attempt under this assessment — same as siteadmin.php's
// per-row Void button, offered here too since grading is where this is most often needed.
// The assessment and its history stay intact; only voided (not currently in-progress) attempts.
if (data_submitted() && confirm_sesskey() && optional_param('gaction', '', PARAM_ALPHA) === 'voidassessment') {
    require_capability('mod/learnguard:manage', $context);
    $voidedCount = learnguard_void_assessment_attempts((int)$cm->instance);
    redirect(new moodle_url('/mod/learnguard/grader.php', ['id' => $id]),
        $voidedCount . ' attempt(s) voided for this assessment.',
        null, \core\output\notification::NOTIFY_SUCCESS);
}

// Reverse a mistaken void — restores each ticked attempt to whatever it was before
// (its recorded prevstate, or a best-effort guess for an attempt voided before that
// column existed — see learnguard_reverse_void_attempt()'s own docblock).
if (data_submitted() && confirm_sesskey() && optional_param('gaction', '', PARAM_ALPHA) === 'reversevoid') {
    require_capability('mod/learnguard:manage', $context);
    $rvIds = optional_param_array('rvids', [], PARAM_INT);
    $rvCount = 0;
    foreach (array_filter(array_map('intval', $rvIds)) as $rvid) {
        $rvatt = $DB->get_record('learnguard_attempts', ['id' => $rvid, 'learnguard' => $cm->instance], '*', IGNORE_MISSING);
        if ($rvatt && learnguard_reverse_void_attempt($rvid)) { $rvCount++; }
    }
    redirect(new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'gview' => 'voided']),
        $rvCount . ' attempt(s) restored.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Email every enrolled student who currently has NO counted attempt for this
// assessment (genuinely never attempted, or their only attempt is now voided) — a
// plain "you haven't attempted this yet" nudge with no mention of voiding at all,
// reusing the exact same reminder Score Matrix's own N/A-cell button already sends.
if (data_submitted() && confirm_sesskey() && optional_param('gaction', '', PARAM_ALPHA) === 'sendreminders') {
    require_capability('mod/learnguard:manage', $context);
    $rmUids = optional_param_array('remindids', [], PARAM_INT);
    $rmSent = 0;
    foreach (array_filter(array_map('intval', $rmUids)) as $rmuid) {
        if (learnguard_notify_student_attempt_reminder((int)$cm->instance, $rmuid)) { $rmSent++; }
    }
    redirect(new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'gview' => 'noattempt']),
        $rmSent . ' reminder email(s) sent.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// Bulk void/delete individual attempts ticked in the Not Yet Graded / Graded lists below
// — same underlying actions as the single-attempt void_attempt AJAX handler and
// scorematrix.php's bulk delete, applied to whichever attempts the grader selected here.
if (data_submitted() && confirm_sesskey() && optional_param('gaction', '', PARAM_ALPHA) === 'bulkvoidattempts') {
    require_capability('mod/learnguard:manage', $context);
    $attids = optional_param_array('attids', [], PARAM_INT);
    $voided = 0;
    foreach (array_filter(array_map('intval', $attids)) as $baid) {
        $battempt = $DB->get_record('learnguard_attempts', ['id' => $baid, 'learnguard' => $cm->instance], '*', IGNORE_MISSING);
        if ($battempt && in_array($battempt->state, ['finished', 'suspended'])) {
            learnguard_void_attempt($battempt->id, $battempt->state);
            $voided++;
        }
    }
    redirect(new moodle_url('/mod/learnguard/grader.php', ['id' => $id]),
        $voided . ' attempt(s) voided.', null, \core\output\notification::NOTIFY_SUCCESS);
}
if (data_submitted() && confirm_sesskey() && optional_param('gaction', '', PARAM_ALPHA) === 'bulkdeleteattempts') {
    require_capability('mod/learnguard:manage', $context);
    $attids = optional_param_array('attids', [], PARAM_INT);
    $deleted = 0;
    foreach (array_filter(array_map('intval', $attids)) as $daid) {
        $dattempt = $DB->get_record('learnguard_attempts', ['id' => $daid, 'learnguard' => $cm->instance], '*', IGNORE_MISSING);
        if ($dattempt) {
            learnguard_delete_single_attempt($daid);
            $deleted++;
        }
    }
    redirect(new moodle_url('/mod/learnguard/grader.php', ['id' => $id]),
        $deleted . ' attempt(s) permanently deleted.', null, \core\output\notification::NOTIFY_SUCCESS);
}

$anonymous  = !empty($myGrader->anonymous) && !$isManager;
$gradeLayer = $myGrader ? (int)$myGrader->gradelayer : 1;

// Defense-in-depth check for a grading-unit-restricted grader (AJAX handlers only —
// the main page's rendering enforces this by pruning $perAttemptSlotData/etc. before any
// HTML is built, further down; this stops a restricted grader from reaching a slot's
// grading UI or saving a grade for it directly by slotid/attemptid, bypassing the UI).
// Keyed by userid, mirrors the restriction applied to the main page below. Returns true
// when the current user is allowed to grade this specific attempt+slot.
function lg_grader_unit_allowed(int $attemptid, int $slotid, bool $isManager): bool {
    global $DB, $USER, $cm;
    if ($isManager) { return true; }
    $myUnits = [];
    try {
        $myUnits = array_map('intval', $DB->get_fieldset_select(
            'learnguard_grader_units', 'unitid', 'userid = :uid', ['uid' => $USER->id]));
    } catch (Exception $e) { return true; }
    if (empty($myUnits)) { return true; }

    $slotInfo = $DB->get_record_sql(
        "SELECT q.qtype, COALESCE(q.unitid, 0) AS unitid
           FROM {learnguard_slots} s JOIN {learnguard_qbank} q ON q.id = s.questionid
          WHERE s.id = :sid AND s.learnguard = :lgid",
        ['sid' => $slotid, 'lgid' => $cm->instance]
    );
    if (!$slotInfo) { return true; } // deleted slot — nothing left to restrict.

    $resolvedUnit = (int)$slotInfo->unitid;
    if ($slotInfo->qtype === 'choiceessay') {
        $resp = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slotid]);
        $resolvedUnit = 0;
        if ($resp && $resp->responsedata) {
            $dec = json_decode($resp->responsedata, true) ?: [];
            foreach ($dec as $k => $v) {
                if (strpos((string)$k, '_answer') === false) { continue; }
                $ceDec = json_decode((string)$v, true);
                if (is_array($ceDec) && (int)($ceDec['promptid'] ?? 0) > 0) {
                    $resolvedUnit = (int)($DB->get_field('learnguard_ce_prompts', 'unitid', ['id' => (int)$ceDec['promptid']]) ?: 0);
                }
            }
        }
    }
    return $resolvedUnit === 0 || in_array($resolvedUnit, $myUnits, true);
}

// ── Manually gradable slots (essays, MCQ, short answer) ──────────────────────
$essaySlots = array_values($DB->get_records_sql(
    "SELECT s.id AS slotid, s.slot, q.id AS qid, q.qtype, q.questiontext, q.markingguidance, q.markingguidanceformat, s.maxmark
       FROM {learnguard_slots} s
       JOIN {learnguard_qbank} q ON q.id = s.questionid
      WHERE s.learnguard = :lgid AND q.qtype IN ('essay', 'choiceessay', 'multichoice', 'multicheck', 'shortanswer', 'truefalse')
      ORDER BY s.slot",
    ['lgid' => $cm->instance]
));
$totalSlots = count($essaySlots);
$slotIds    = array_column($essaySlots, 'slotid');

// ── AJAX handlers ────────────────────────────────────────────────────────────
if ($lgajax) {
    // Prevent PHP notices/warnings from being injected into JSON output.
    // Moodle may turn on display_errors in developer mode, which corrupts AJAX responses.
    @ini_set('display_errors', '0');
    require_sesskey();
    header('Content-Type: application/json');

    if ($action === 'get_question') {
        try {
            $attemptid = required_param('attemptid', PARAM_INT);
            $slotid    = required_param('slotid',    PARAM_INT);

            $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $cm->instance], '*', MUST_EXIST);
            $slot    = $DB->get_record_sql(
                "SELECT s.id AS slotid, s.slot, q.id AS qid, q.qtype, q.questiontext, q.markingguidance, q.markingguidanceformat, q.casesensitive, s.maxmark, COALESCE(q.unitid, 0) AS unitid, COALESCE(q.word_limit, 0) AS word_limit
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
                    'unitid'              => 0,
                    'word_limit'          => 0,
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

        // Choice Essay: the unit lives on the individual PROMPT the student chose, not
        // on the parent question — resolve it here from the response's own promptid.
        $ceChosenPromptId = 0;
        $ceAnswerText     = '';
        if ($slot->qtype === 'choiceessay') {
            $ceData = json_decode((string)$answer, true);
            if (is_array($ceData)) {
                $ceChosenPromptId = (int)($ceData['promptid'] ?? 0);
                $ceAnswerText     = (string)($ceData['text'] ?? '');
            }
        }

        // Server-side enforcement of a grader's unit restriction (defense in depth — the
        // same restriction already hides this slot from the student-row/pill lists below,
        // but a grader could otherwise still reach this AJAX endpoint directly with a
        // slotid they weren't shown).
        if (!lg_grader_unit_allowed($attemptid, $slotid, $isManager)) {
            echo json_encode(['ok' => false, 'error' => 'You are restricted to a different grading unit for this question.']);
            exit;
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
            $answerHtml = \mod_learnguard\question_engine::render_shortanswer($allAnswers, $slotnum, $saved, true, !empty($slot->casesensitive));
        } elseif ($slot->qtype === 'truefalse') {
            $saved = ["q{$slotnum}_answer" => $answer];
            $answerHtml = \mod_learnguard\question_engine::render_truefalse($slot->qid, $slotnum, $saved, true);
        } elseif ($slot->qtype === 'choiceessay') {
            // Show which prompt the student picked, then their written answer —
            // render_essay_answer_html() safely handles both an old plain-text answer
            // and a new rich-text HTML one, same as the plain Essay branch below.
            $chosenPrompt = $ceChosenPromptId > 0
                ? $DB->get_record('learnguard_ce_prompts', ['id' => $ceChosenPromptId], 'prompttext, prompttextformat', IGNORE_MISSING)
                : null;
            $chosenPromptHtml = $chosenPrompt
                ? format_text($chosenPrompt->prompttext, $chosenPrompt->prompttextformat ?? FORMAT_HTML)
                : '<em style="color:#6c757d;">[Original prompt no longer available.]</em>';
            $answerHtml  = '<div style="background:#332900;border:1px solid #664d00;border-radius:6px;padding:10px 12px;margin-bottom:10px;">'
                . '<strong style="color:#ffd966;font-size:12px;">Prompt chosen by student:</strong>'
                . '<div style="color:#ffe9a8;font-size:13px;margin-top:4px;">' . $chosenPromptHtml . '</div></div>';
            $ceWordCount = \mod_learnguard\question_engine::count_words($ceAnswerText);
            $ceWordLimit = (int)($slot->word_limit ?? 0);
            if ($ceWordLimit > 0 && $ceWordCount > $ceWordLimit) {
                $answerHtml .= '<div style="background:#4a1414;border:1px solid #dc3545;border-radius:6px;' .
                    'padding:8px 12px;margin-bottom:10px;font-size:12px;color:#ff8a8a;font-weight:600;">' .
                    '⚠️ Exceeds the ' . $ceWordLimit . '-word limit — submitted ' . $ceWordCount . ' words.</div>';
            }
            $answerHtml .= !\mod_learnguard\question_engine::is_essay_text_blank($ceAnswerText)
                ? \mod_learnguard\question_engine::render_essay_answer_html($ceAnswerText)
                : '<em style="color:#6c757d;">No response submitted.</em>';
        } else {
            // Essay: show response text as entered. render_essay_answer_html() safely
            // handles both an OLD plain-text answer (escapes + newline-to-<br>) and a NEW
            // rich-text HTML answer (passed through as real formatting) — a bare nl2br(s())
            // here would show literal "&lt;b&gt;" tag text for any newly-formatted answer.
            $answerHtml = !\mod_learnguard\question_engine::is_essay_text_blank((string)$answer)
                ? \mod_learnguard\question_engine::render_essay_answer_html((string)$answer)
                : '<em style="color:#6c757d;">No response submitted.</em>';
        }

        // Load all grade history for this slot (any grader), newest first.
        $allGradeRows = $DB->get_records_sql(
            "SELECT eg.id, eg.grade, eg.feedback, eg.graderid, eg.timegraded,
                    u.firstname, u.lastname
               FROM {learnguard_essay_grades} eg
               JOIN {user} u ON u.id = eg.graderid
              WHERE eg.attemptid = :aid AND eg.slotid = :sid
              ORDER BY eg.timegraded DESC",
            ['aid' => $attemptid, 'sid' => $slotid]
        );
        $myGrade  = null;
        $anyGrade = null; // most recent grade by anyone
        $graderHistory = [];
        foreach ($allGradeRows as $gr) {
            if ($anyGrade === null) { $anyGrade = $gr; }
            if ($gr->graderid == $USER->id && $myGrade === null) { $myGrade = $gr; }
            $graderHistory[] = [
                'name'    => ($gr->graderid == $USER->id) ? 'You (' . fullname($gr) . ')' : fullname($gr),
                'grade'   => (float)$gr->grade,
                'time'    => userdate((int)$gr->timegraded, '%d %b %Y %H:%M'),
                'is_me'   => ($gr->graderid == $USER->id),
                'type'    => 'manual',
            ];
        }
        // Append auto-grade entry when the question was graded automatically (MCQ/SA/TF).
        if ($autoGradeValue !== null && $hasAnswer && $resp) {
            $graderHistory[] = [
                'name'  => 'System (auto-graded)',
                'grade' => round($autoGradeValue, 5),
                'time'  => userdate((int)$resp->timemodified, '%d %b %Y %H:%M'),
                'is_me' => false,
                'type'  => 'auto',
            ];
        }

        // True chronological change history (First Grade vs Regraded, with the previous
        // score) — distinct from $graderHistory above, which is only ever "one row per
        // distinct grader who has ever touched this slot," not a real timeline. Reads from
        // learnguard_grade_history (v2.0.210+); attempts graded before that table existed
        // have no rows here and simply show nothing in this section.
        $gradeChangeRows = $DB->get_records_sql(
            "SELECT gh.id, gh.oldgrade, gh.newgrade, gh.timegraded, gh.graderid,
                    u.firstname, u.lastname
               FROM {learnguard_grade_history} gh
               JOIN {user} u ON u.id = gh.graderid
              WHERE gh.attemptid = :aid AND gh.slotid = :sid
              ORDER BY gh.timegraded ASC",
            ['aid' => $attemptid, 'sid' => $slotid]
        );
        $gradeChangeHistory = [];
        foreach ($gradeChangeRows as $gh) {
            $gradeChangeHistory[] = [
                'name'      => ($gh->graderid == $USER->id) ? 'You (' . fullname($gh) . ')' : fullname($gh),
                'status'    => ($gh->oldgrade === null) ? 'First Grade' : 'Regraded',
                'oldgrade'  => ($gh->oldgrade === null) ? null : round((float)$gh->oldgrade, 5),
                'newgrade'  => round((float)$gh->newgrade, 5),
                'time'      => userdate((int)$gh->timegraded, '%d %b %Y %H:%M'),
            ];
        }

        if ($myGrade) {
            $existingGrade    = (float)$myGrade->grade;
            $existingFeedback = $myGrade->feedback;
            $gradedBy         = 'You';
        } elseif ($anyGrade) {
            $existingGrade    = (float)$anyGrade->grade;
            $existingFeedback = $anyGrade->feedback;
            $graderUser       = $DB->get_record('user', ['id' => $anyGrade->graderid], 'id,firstname,lastname', IGNORE_MISSING);
            $gradedBy         = $graderUser ? fullname($graderUser) : 'Another grader';
        } elseif ($autoGradeValue !== null && trim((string)$answer) !== '') {
            $existingGrade    = $autoGradeValue;
            $existingFeedback = '';
            $gradedBy         = 'Auto';
        } else {
            $existingGrade    = null;
            $existingFeedback = '';
            $gradedBy         = null;
        }

        $student = $DB->get_record('user', ['id' => $attempt->userid], 'firstname,lastname,email');

        // Choice Essay's own questiontext is always just an internal Moodle-compatibility
        // placeholder ("Choose one of the following questions to answer.") — never real
        // content, matching the student-facing renderer (render_question() skips it
        // entirely for this qtype since v2.0.198/201). This "Question" heading was never
        // updated to match, so it kept showing the placeholder verbatim for every Choice
        // Essay slot in the grader — the real content is the chosen prompt shown inside
        // the "Question answered:" card below (render_choiceessay_review()).
        $questionText = ($slot->qtype === 'choiceessay') ? '' : format_text($slot->questiontext, FORMAT_HTML);
        // If marking guide was saved as plain text (no HTML tags, has newlines), auto-convert for display.
        $mgRaw  = $slot->markingguidance ?? '';
        $mgFmt  = $slot->markingguidanceformat ?: FORMAT_HTML;
        if ($mgRaw !== '' && !preg_match('/<[^>]+>/', $mgRaw) && strpos($mgRaw, "\n") !== false) {
            $mgRaw = '<p>' . str_replace("\n\n", '</p><p>', nl2br(s($mgRaw))) . '</p>';
            $mgFmt = FORMAT_HTML;
        }
        $markingguidance = $mgRaw !== '' ? format_text($mgRaw, $mgFmt) : '';
        $studentName     = $anonymous ? 'Candidate #' . $attempt->id : fullname($student);

        // Rubric grading data — Choice Essay only, and only when this activity actually
        // has one configured. Existing per-criterion scores prefer this grader's own; if
        // someone else already graded it, show THEIR complete set (not a per-criterion mix
        // of whichever grader's row a DB result happened to return last for each one —
        // that would show a Frankenstein breakdown mixing different graders' judgements on
        // different criteria), mirroring the plain single-mark logic above by picking one
        // coherent, most-recently-graded "other grader" rather than merging arbitrarily.
        $rubricPayload = null;
        if ($rubricEnabled && $slot->qtype === 'choiceessay' && !empty($rubricCriteriaRows)) {
            $existingScoreRows = $DB->get_records('learnguard_rubric_scores',
                ['attemptid' => $attemptid, 'slotid' => $slotid], 'timegraded DESC');
            // First pass (DESC order): identify the most recently active OTHER grader, if
            // any — the single grader whose complete set we'll show as "anyScores" below.
            $latestOtherGraderId = null;
            foreach ($existingScoreRows as $sr) {
                if ((int)$sr->graderid !== (int)$USER->id) { $latestOtherGraderId = (int)$sr->graderid; break; }
            }
            // Second pass: my own latest value per criterion (first-seen wins, since rows
            // are DESC by time), and — only from that one identified grader — theirs.
            $myScores  = [];
            $anyScores = [];
            foreach ($existingScoreRows as $sr) {
                $cid = (int)$sr->criteriaid;
                if ((int)$sr->graderid === (int)$USER->id && !isset($myScores[$cid])) {
                    $myScores[$cid] = (float)$sr->score;
                } elseif ($latestOtherGraderId !== null && (int)$sr->graderid === $latestOtherGraderId && !isset($anyScores[$cid])) {
                    $anyScores[$cid] = (float)$sr->score;
                }
            }
            $useScores = !empty($myScores) ? $myScores : $anyScores;
            $rubricPayload = [
                'showtotal' => !empty($lg->rubric_show_total_grader),
                // name/focus are HTML-escaped here, not left raw — the JS grading panel
                // concatenates these straight into innerHTML with no escaping of its own.
                'criteria'  => array_map(function($rc) use ($useScores) {
                    return [
                        'id'     => (int)$rc->id,
                        'name'   => s($rc->name),
                        'focus'  => s($rc->focus ?? ''),
                        'weight' => (int)$rc->weight,
                        'score'  => $useScores[(int)$rc->id] ?? null,
                    ];
                }, $rubricCriteriaRows),
            ];
        }

        // Discard any stray output from format_text filter calls before writing JSON.
        if (ob_get_level()) { ob_clean(); }
        echo json_encode([
            'ok'                => true,
            'qtype'             => $slot->qtype,
            'questiontext'      => $questionText,
            'answer'            => $answerHtml,
            'markingguidance'   => $markingguidance,
            'maxmark'           => (float)$slot->maxmark,
            'existing_grade'    => $existingGrade,
            'existing_feedback' => $existingFeedback,
            'graded_by'         => $gradedBy,
            'grader_history'    => $graderHistory,
            'grade_change_history' => $gradeChangeHistory,
            'has_answer'        => $hasAnswer,
            'student_name'      => $studentName,
            'risk_score'        => (int)$attempt->risk_score,
            'risk_level'        => $attempt->risk_level ?? 'low',
            'rubric'            => $rubricPayload,
        ], JSON_INVALID_UTF8_SUBSTITUTE);
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

        if (!lg_grader_unit_allowed($attemptid, $slotid, $isManager)) {
            echo json_encode(['ok' => false, 'error' => 'You are restricted to a different grading unit for this question.']);
            exit;
        }

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

        // The "was this a regrade" question for history purposes is "did ANYONE already
        // grade this slot", not "did THIS grader already grade it" — a second grader
        // regrading a slot Grader A already scored is still a regrade of the question,
        // even though it's this grader's own first-ever save on it. Read the prior value
        // BEFORE touching essay_grades below, from any grader, most-recent first.
        $priorAny = $DB->get_record_sql(
            "SELECT grade FROM {learnguard_essay_grades}
              WHERE attemptid = :aid AND slotid = :sid
           ORDER BY timegraded DESC",
            ['aid' => $attemptid, 'sid' => $slotid], IGNORE_MULTIPLE
        );

        $existing = $DB->get_record('learnguard_essay_grades', ['attemptid' => $attemptid, 'slotid' => $slotid, 'graderid' => $USER->id]);
        if ($existing) {
            $DB->update_record('learnguard_essay_grades', (object)['id' => $existing->id, 'grade' => $grade, 'feedback' => $feedback, 'timegraded' => time()]);
        } else {
            $DB->insert_record('learnguard_essay_grades', (object)['attemptid' => $attemptid, 'slotid' => $slotid, 'graderid' => $USER->id, 'grade' => $grade, 'feedback' => $feedback, 'timegraded' => time()]);
        }
        lg_log_grade_history($attemptid, $slotid, $USER->id, $priorAny ? (float)$priorAny->grade : null, $grade);
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

        $returnedSumgrades = null;
        if ($attempt->state === 'finished') {
            require_once($CFG->libdir . '/gradelib.php');
            // Only publish score once ALL essay questions for this attempt are graded.
            if (!\mod_learnguard\question_engine::has_ungraded_essays($attemptid, $cm->instance)) {
                $newgrade = \mod_learnguard\question_engine::calculate_total_grade($attemptid, $lg);
                $DB->set_field('learnguard_attempts', 'sumgrades', $newgrade, ['id' => $attemptid]);
                grade_update('mod/learnguard', $lg->course, 'mod', 'learnguard', $lg->id, 0,
                    [(object)['userid' => $attempt->userid, 'rawgrade' => $newgrade]]);
                $returnedSumgrades = $newgrade;
            }
        }
        // Return sumgrades so the JS can update the grade display without a page reload.
        echo json_encode(['ok' => true, 'sumgrades' => $returnedSumgrades]);
        exit;
    }

    // Choice Essay rubric grading — one score per criterion, capped at that criterion's
    // own weight; the total (scaled to the question's real maxmark, since weights sum to
    // 100) is what actually lands in learnguard_essay_grades.grade, so every existing
    // downstream calculation (calculate_total_grade, has_ungraded_essays, gradebook push)
    // keeps working completely unchanged — this only adds a different way to ARRIVE at
    // that one grade value, not a parallel scoring system.
    if ($action === 'save_rubric_grade') {
        $attemptid = required_param('attemptid', PARAM_INT);
        $slotid    = required_param('slotid',    PARAM_INT);
        $feedback  = optional_param('feedback',  '', PARAM_RAW);
        $scoresRaw = optional_param_array('scores', [], PARAM_RAW); // {criteriaid: score}

        if (!$rubricEnabled || empty($rubricCriteriaRows)) {
            echo json_encode(['ok' => false, 'error' => 'This assessment has no grading rubric configured.']);
            exit;
        }
        if (!lg_grader_unit_allowed($attemptid, $slotid, $isManager)) {
            echo json_encode(['ok' => false, 'error' => 'You are restricted to a different grading unit for this question.']);
            exit;
        }

        $attempt = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $cm->instance], '*', MUST_EXIST);
        $slotRow = $DB->get_record_sql(
            "SELECT s.id, s.maxmark, q.qtype
               FROM {learnguard_slots} s JOIN {learnguard_qbank} q ON q.id = s.questionid
              WHERE s.id = :sid AND s.learnguard = :lgid",
            ['sid' => $slotid, 'lgid' => $cm->instance]
        );
        if (!$slotRow || $slotRow->qtype !== 'choiceessay') {
            echo json_encode(['ok' => false, 'error' => 'The rubric only applies to Choice Essay questions.']);
            exit;
        }
        $maxmark = (float)$slotRow->maxmark;

        // Validate and cap every submitted score against its own criterion's weight —
        // never trust the client to have enforced this itself.
        $weightSum = 0;
        $rawTotal  = 0.0;
        $toSave    = [];
        foreach ($rubricCriteriaRows as $rc) {
            $cid    = (int)$rc->id;
            $weight = (int)$rc->weight;
            $weightSum += $weight;
            $score = isset($scoresRaw[$cid]) ? (float)$scoresRaw[$cid] : 0.0;
            $score = max(0.0, min($weight, $score));
            $rawTotal += $score;
            $toSave[$cid] = $score;
        }
        // Scale the 0-100-ish rubric total to this question's actual maxmark. If an admin's
        // weights don't sum to exactly 100 (shouldn't happen — mod_form.php validates this
        // at save time) this still degrades sensibly rather than silently over/under-scoring.
        $grade = ($weightSum > 0) ? round(($rawTotal / $weightSum) * $maxmark, 5) : 0.0;

        foreach ($toSave as $cid => $score) {
            $existingScore = $DB->get_record('learnguard_rubric_scores',
                ['attemptid' => $attemptid, 'slotid' => $slotid, 'criteriaid' => $cid, 'graderid' => $USER->id]);
            if ($existingScore) {
                $DB->update_record('learnguard_rubric_scores',
                    (object)['id' => $existingScore->id, 'score' => $score, 'timegraded' => time()]);
            } else {
                $DB->insert_record('learnguard_rubric_scores', (object)[
                    'attemptid' => $attemptid, 'slotid' => $slotid, 'criteriaid' => $cid,
                    'graderid' => $USER->id, 'score' => $score, 'timegraded' => time(),
                ]);
            }
        }

        // Same "regraded by anyone, not just by me" check as the plain single-mark path —
        // read the prior value from any grader before this save overwrites it.
        $priorAnyRubric = $DB->get_record_sql(
            "SELECT grade FROM {learnguard_essay_grades}
              WHERE attemptid = :aid AND slotid = :sid
           ORDER BY timegraded DESC",
            ['aid' => $attemptid, 'sid' => $slotid], IGNORE_MULTIPLE
        );

        $existingGradeRow = $DB->get_record('learnguard_essay_grades',
            ['attemptid' => $attemptid, 'slotid' => $slotid, 'graderid' => $USER->id]);
        if ($existingGradeRow) {
            $DB->update_record('learnguard_essay_grades',
                (object)['id' => $existingGradeRow->id, 'grade' => $grade, 'feedback' => $feedback, 'timegraded' => time()]);
        } else {
            $DB->insert_record('learnguard_essay_grades', (object)[
                'attemptid' => $attemptid, 'slotid' => $slotid, 'graderid' => $USER->id,
                'grade' => $grade, 'feedback' => $feedback, 'timegraded' => time(),
            ]);
        }
        lg_log_grade_history($attemptid, $slotid, $USER->id, $priorAnyRubric ? (float)$priorAnyRubric->grade : null, $grade);
        $fraction = ($maxmark > 0) ? min(1.0, max(0.0, $grade / $maxmark)) : 0.0;
        $existResp = $DB->get_record('learnguard_responses', ['attemptid' => $attemptid, 'slotid' => $slotid]);
        if ($existResp) {
            $DB->set_field('learnguard_responses', 'fraction',     $fraction, ['attemptid' => $attemptid, 'slotid' => $slotid]);
            $DB->set_field('learnguard_responses', 'state',        'graded',  ['attemptid' => $attemptid, 'slotid' => $slotid]);
            $DB->set_field('learnguard_responses', 'timemodified', time(),    ['attemptid' => $attemptid, 'slotid' => $slotid]);
        } else {
            $DB->insert_record('learnguard_responses', (object)[
                'attemptid' => $attemptid, 'slotid' => $slotid, 'responsedata' => json_encode([]),
                'fraction' => $fraction, 'state' => 'graded', 'timemodified' => time(),
            ]);
        }

        $returnedSumgrades = null;
        if ($attempt->state === 'finished') {
            require_once($CFG->libdir . '/gradelib.php');
            if (!\mod_learnguard\question_engine::has_ungraded_essays($attemptid, $cm->instance)) {
                $newgrade = \mod_learnguard\question_engine::calculate_total_grade($attemptid, $lg);
                $DB->set_field('learnguard_attempts', 'sumgrades', $newgrade, ['id' => $attemptid]);
                grade_update('mod/learnguard', $lg->course, 'mod', 'learnguard', $lg->id, 0,
                    [(object)['userid' => $attempt->userid, 'rawgrade' => $newgrade]]);
                $returnedSumgrades = $newgrade;
            }
        }
        echo json_encode(['ok' => true, 'grade' => $grade, 'sumgrades' => $returnedSumgrades]);
        exit;
    }

    if ($action === 'void_attempt' && $isManager) {
        $vaid     = required_param('vaid', PARAM_INT);
        $vattempt = $DB->get_record('learnguard_attempts', ['id' => $vaid, 'learnguard' => $cm->instance]);
        if ($vattempt && in_array($vattempt->state, ['finished', 'suspended'])) {
            learnguard_void_attempt($vaid, $vattempt->state);
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
               JOIN {learnguard_qbank} q ON q.id = s.questionid AND q.qtype IN ('essay', 'choiceessay')
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
                $DB->delete_records_select('learnguard_rubric_scores',
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

// ── Auto-recalculate finished scores + partial in-progress scores on every page load ──
// Finished: recalculates every attempt so scores are always up-to-date.
// In-progress: computes partial score from answered questions so graders can see
//              progress before the student submits (not pushed to gradebook).
// Attempts with pending essay questions are set to null.
require_once($CFG->libdir . '/gradelib.php');
$activeAtts = $DB->get_records_select('learnguard_attempts',
    "learnguard = :lgid AND state IN ('finished','inprogress')",
    ['lgid' => $cm->instance], '', 'id,userid,state');
foreach ($activeAtts as $fatt) {
    if (\mod_learnguard\question_engine::has_ungraded_essays((int)$fatt->id, $cm->instance)) {
        $DB->set_field('learnguard_attempts', 'sumgrades', null, ['id' => $fatt->id]);
        continue;
    }
    $newg = \mod_learnguard\question_engine::calculate_total_grade((int)$fatt->id, $lg);
    $DB->set_field('learnguard_attempts', 'sumgrades', $newg, ['id' => $fatt->id]);
    if ($fatt->state === 'finished') {
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
// Restrict to assigned attempts only when the admin has explicitly assigned this grader's
// attempts via learnguard_grader_assignments (e.g. via admin.php's Grading Distribution,
// or siteadmin.php's site-wide distribution tool). Layer 2 graders default to seeing all
// finished attempts (a full second-pass reviewer) UNLESS the admin has explicitly opted
// Layer 2 into its own exclusive split too (learnguard_grading_config.include_layer2).
// Managers always see all finished attempts regardless.
// If no assignments exist yet → fall back to showing all finished attempts.
$includeLayer2Dist = !empty($gconfig->include_layer2 ?? 0);
$distributed = (($gradeLayer == 1) || ($gradeLayer == 2 && $includeLayer2Dist)) && !$isManager
    && $DB->record_exists('learnguard_grader_assignments', ['learnguard' => $cm->instance, 'graderid' => $USER->id]);

// ── Load finished attempts, grouped by student ───────────────────────────────
$distJoin   = $distributed
    ? "JOIN {learnguard_grader_assignments} ga ON ga.attemptid = a.id AND ga.learnguard = :lgid2 AND ga.graderid = :myid"
    : "";
$distParams = $distributed ? ['lgid2' => $cm->instance, 'myid' => $USER->id] : [];

// A "Continue & Review" attempt is put back to state='inprogress' the moment review is
// GRANTED, before the learner has done anything — under the old WHERE clause (finished/
// voided only) that made the student's row, grade, and answers vanish from the grader
// entirely for the whole review window, even though nothing about the prior submission
// has actually changed yet (review saves nothing until final resubmit). Also include
// inprogress attempts that are reopened_for_review with a snapshot of their prior score
// (prev_sumgrades) — everything below treats these exactly like the finished attempt they
// still represent, using prev_sumgrades in place of sumgrades, until the learner resubmits
// and finish() flips state back to 'finished' with a fresh sumgrades.
$rows = $DB->get_records_sql(
    "SELECT a.id, a.userid, a.attempt, a.state, a.timestart, a.timefinish, a.sumgrades,
            a.risk_score, a.risk_level, a.reopened_for_review, a.prev_sumgrades,
            u.firstname, u.lastname, u.email
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
       $distJoin
      WHERE a.learnguard = :lgid
        AND (a.state IN ('finished', 'voided')
             OR (a.state = 'inprogress' AND a.reopened_for_review = 1 AND a.prev_sumgrades IS NOT NULL))
      ORDER BY u.lastname ASC, u.firstname ASC, a.id ASC",
    array_merge(['lgid' => $cm->instance], $distParams)
);

// True for a 'finished' attempt, or an 'inprogress' Continue & Review attempt whose
// learner hasn't resubmitted yet — for grading/display purposes both represent the same
// still-current prior submission (see the query comment above).
function edg_effectively_finished(\stdClass $att): bool {
    if ($att->state === 'finished') { return true; }
    return $att->state === 'inprogress' && !empty($att->reopened_for_review) && $att->prev_sumgrades !== null;
}

// The effective sumgrades to display/use for grading purposes: the real sumgrades once
// finished, or the pre-review snapshot while a review is still pending resubmission.
function edg_effective_sumgrades(\stdClass $att) {
    if ($att->state === 'finished') { return $att->sumgrades; }
    return $att->prev_sumgrades;
}

// learnguard_essay_grades stores at most one row per (attemptid, slotid, graderid) and is
// updated IN PLACE on a regrade — the previous value is otherwise lost with no trace. This
// logs every save into learnguard_grade_history so the Grade History popup can show
// "First Grade" vs "Regraded" plus the previous score. $oldgrade is null for a genuine
// first-time save; a regrade is only logged when the value actually changed, so re-saving
// the same mark (e.g. just editing feedback text) doesn't manufacture a fake regrade entry.
function lg_log_grade_history(int $attemptid, int $slotid, int $graderid, ?float $oldgrade, float $newgrade): void {
    global $DB;
    if ($oldgrade !== null && abs($oldgrade - $newgrade) < 0.00001) {
        return;
    }
    $DB->insert_record('learnguard_grade_history', (object)[
        'attemptid'  => $attemptid,
        'slotid'     => $slotid,
        'graderid'   => $graderid,
        'oldgrade'   => $oldgrade,
        'newgrade'   => $newgrade,
        'timegraded' => time(),
    ]);
}

// Group by student.
$students = [];
foreach ($rows as $r) {
    if (!isset($students[$r->userid])) {
        $students[$r->userid] = ['userid' => $r->userid, 'firstname' => $r->firstname, 'lastname' => $r->lastname, 'email' => $r->email, 'attempts' => []];
    }
    $students[$r->userid]['attempts'][] = $r;
}

// Batch-fetch slot statuses for all attempts.
$attemptIds         = array_map('intval', array_column(array_values($rows ?? []), 'id'));
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
                COALESCE(s.maxmark, 1.0) AS maxmark,
                COALESCE(q.qtype, 'essay') AS qtype,
                COALESCE(q.id, 0) AS qid,
                COALESCE(q.unitid, 0) AS unitid
           FROM {learnguard_attempt_questions} aq
           LEFT JOIN {learnguard_slots} s ON s.id = aq.slotid
           LEFT JOIN {learnguard_qbank} q ON q.id = s.questionid
          WHERE aq.attemptid $aidSql
            AND (q.qtype IN ('essay', 'choiceessay', 'multichoice', 'multicheck', 'shortanswer', 'truefalse') OR q.id IS NULL)
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
                    COALESCE(q.qtype, 'essay') AS qtype,
                    COALESCE(q.unitid, 0) AS unitid
               FROM {learnguard_responses} r
               LEFT JOIN {learnguard_slots} s ON s.id = r.slotid
               LEFT JOIN {learnguard_qbank} q ON q.id = s.questionid
              WHERE r.attemptid $nawSql
                AND (q.qtype IN ('essay', 'choiceessay', 'multichoice', 'multicheck', 'shortanswer', 'truefalse') OR q.id IS NULL)
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

    $lastGraderMap = []; // aid => sid => last grader fullname (essay pills display)
    if (!empty($allEssaySlotIdsList)) {
        [$sidSql, $sidP] = $DB->get_in_or_equal($allEssaySlotIdsList, SQL_PARAMS_NAMED, 'sid');

        // Responses: determine if each slot has a non-blank answer.
        $respRs  = $DB->get_recordset_sql(
            "SELECT r.id, r.attemptid, r.slotid, r.responsedata, COALESCE(r.fraction, 0) AS fraction, COALESCE(r.state, '') AS state
               FROM {learnguard_responses} r
              WHERE r.attemptid $aidSql AND r.slotid $sidSql",
            array_merge($aidP, $sidP)
        );
        $respSet = []; // map attemptid_slotid => ['has'=>bool,'fraction'=>float,'state'=>string]
        // Choice Essay: the response's own JSON blob ({promptid,text,shown}) is never a
        // literally-empty string even when the student answered nothing (it still has a
        // promptid/shown array) — a plain "any non-empty value" check like the one below
        // would misreport every unanswered Choice Essay slot as "answered". Also captures
        // which prompt was chosen, needed to resolve that slot's Grading Unit further down.
        $ceChosenPromptMap = []; // attemptid_slotid => promptid actually chosen
        foreach ($respRs as $rr) {
            $slotQtypeForRow = $perAttemptSlotData[$rr->attemptid][$rr->slotid]->qtype ?? '';
            $d   = json_decode($rr->responsedata, true) ?? [];
            $has = false;
            if ($slotQtypeForRow === 'choiceessay') {
                foreach ($d as $k => $v) {
                    if (strpos((string)$k, '_answer') === false) { continue; }
                    $ceDec = json_decode((string)$v, true);
                    if (is_array($ceDec)) {
                        if ((int)($ceDec['promptid'] ?? 0) > 0) {
                            $ceChosenPromptMap[$rr->attemptid . '_' . $rr->slotid] = (int)$ceDec['promptid'];
                        }
                        if (!\mod_learnguard\question_engine::is_essay_text_blank((string)($ceDec['text'] ?? ''))) {
                            $has = true;
                        }
                    }
                }
            } else {
                foreach ($d as $v) { if (trim((string)$v) !== '') { $has = true; break; } }
            }
            // Treat explicit fraction > 0 or state='graded' as having an answer.
            $has = $has || ((float)$rr->fraction > 0.000001) || ($rr->state === 'graded');
            $respSet[$rr->attemptid . '_' . $rr->slotid] = ['has' => $has, 'fraction' => (float)$rr->fraction, 'state' => $rr->state];
        }
        $respRs->close();

        // Batch-resolve each chosen prompt's Grading Unit (Choice Essay only — a plain
        // Essay's unit lives directly on q.unitid, already present in $perAttemptSlotData).
        $ceUnitByPromptId = [];
        if (!empty($ceChosenPromptMap)) {
            $ceUniquePromptIds = array_values(array_unique(array_values($ceChosenPromptMap)));
            $cePromptRows = $DB->get_records_list('learnguard_ce_prompts', 'id', $ceUniquePromptIds, '', 'id, unitid');
            foreach ($cePromptRows as $cpr) { $ceUnitByPromptId[(int)$cpr->id] = (int)($cpr->unitid ?? 0); }
        }
        // Resolved Grading Unit per attempt+slot, regardless of question type.
        $resolvedUnitMap = [];
        foreach ($perAttemptSlotData as $aid => $slots) {
            foreach ($slots as $sid => $slot) {
                $k = $aid . '_' . $sid;
                if (($slot->qtype ?? '') === 'choiceessay' && isset($ceChosenPromptMap[$k])) {
                    $resolvedUnitMap[$aid][$sid] = $ceUnitByPromptId[$ceChosenPromptMap[$k]] ?? 0;
                } else {
                    $resolvedUnitMap[$aid][$sid] = (int)($slot->unitid ?? 0);
                }
            }
        }

        // Grades submitted by ANY grader (not just the current user).
        $gradeRs  = $DB->get_recordset_sql(
            "SELECT eg.id, eg.attemptid, eg.slotid, eg.grade, eg.graderid, eg.timegraded
               FROM {learnguard_essay_grades} eg
              WHERE eg.attemptid $aidSql AND eg.slotid $sidSql
           ORDER BY eg.timegraded ASC",
            array_merge($aidP, $sidP)
        );
        $gradedSet = [];
        $gradedValues = []; // latest grade value per attempt_slot
        foreach ($gradeRs as $gr) {
            $k = $gr->attemptid . '_' . $gr->slotid;
            $gradedSet[$k] = true;
            $gradedValues[$k] = (float)$gr->grade; // later rows overwrite earlier ones
        }
        $gradeRs->close();

        // Build per-attempt, per-slot status map.
        $autoGradedTypes = ['multichoice', 'multicheck', 'shortanswer', 'truefalse'];
        // Also compute per-slot score/fraction so the UI can colour pills by correctness.
        $slotScoreMap = []; // map aid => sid => fraction (0.0-1.0)
        foreach ($perAttemptSlotData as $aid => $slots) {
            foreach ($slots as $sid => $slot) {
                $k = $aid . '_' . $sid;
                $slotQid = (int)($slot->qid ?? -1);
                if ($slotQid === 0) {
                    // Slot was deleted by admin after this attempt — qtype defaulted to 'essay'
                    // but we can't manually grade a question that no longer exists; treat as satisfied.
                    $slotStatusMap[$aid][$sid] = 'graded';
                    $slotScoreMap[$aid][$sid]  = 0.0;
                    continue;
                }
                if (!empty($gradedSet[$k])) {
                    // Manual grade exists (essay_grades table) — graded by a human.
                    $slotStatusMap[$aid][$sid] = 'graded';
                } elseif (isset($respSet[$k]) && in_array($slot->qtype, $autoGradedTypes, true)) {
                    // Auto-graded type with a found response row.
                    $slotStatusMap[$aid][$sid] = 'graded';
                } elseif (in_array($slot->qtype, $autoGradedTypes, true)) {
                    // Auto-graded type even when no response row found: the system always evaluates
                    // these and scores them (0 for unanswered). Show as graded (red/zero pill)
                    // rather than NA so the interface doesn't mislead graders.
                    $slotStatusMap[$aid][$sid] = 'graded';
                } elseif (!empty($respSet[$k]['has'])) {
                    // Essay with a non-blank answer awaiting manual grading.
                    $slotStatusMap[$aid][$sid] = 'rg';
                } else {
                    $slotStatusMap[$aid][$sid] = 'na';
                }

                // Determine fraction: prefer explicit grader value, else response fraction.
                if (isset($gradedValues[$k])) {
                    $slotScoreMap[$aid][$sid] = ($slot->maxmark > 0) ? min(1.0, max(0.0, $gradedValues[$k] / (float)$slot->maxmark)) : 0.0;
                } elseif (isset($respSet[$k]) && array_key_exists('fraction', $respSet[$k])) {
                    $slotScoreMap[$aid][$sid] = (float)$respSet[$k]['fraction'];
                } elseif (in_array($slot->qtype, $autoGradedTypes, true)) {
                    // Auto-graded with no response found: score is 0 (unanswered = wrong).
                    // Pill will show as 'zero' (red), not 'ok' (green).
                    $slotScoreMap[$aid][$sid] = 0.0;
                } else {
                    $slotScoreMap[$aid][$sid] = null;
                }
            }
        }
    }

    // ── Grading-unit restriction for this grader (by userid) ────────────────
    // Keyed by userid, not a specific learnguard_graders row, so it applies
    // consistently across every activity/global assignment this grader holds.
    // Managers are never restricted. A slot with no Grading Unit assigned
    // (unitid=0) is always visible — only explicitly-tagged questions/prompts
    // are ever filtered, so nothing changes for anyone until units are actually
    // set up and a grader is actually restricted to some of them. Pruning here
    // (before maxmark totals/bucketing below are computed) means every other
    // downstream use of these maps — pills, "Not Yet Graded" bucketing, the
    // per-attempt total shown to this grader — automatically respects it too.
    $myUnitRestrictions = [];
    if (!$isManager) {
        try {
            $myUnitRestrictions = array_map('intval', $DB->get_fieldset_select(
                'learnguard_grader_units', 'unitid', 'userid = :uid', ['uid' => $USER->id]));
        } catch (Exception $e) { $myUnitRestrictions = []; }
    }
    if (!empty($myUnitRestrictions)) {
        foreach ($perAttemptSlotData as $aid => $slots) {
            foreach (array_keys($slots) as $sid) {
                $resolvedUnit = $resolvedUnitMap[$aid][$sid] ?? 0;
                if ($resolvedUnit > 0 && !in_array($resolvedUnit, $myUnitRestrictions, true)) {
                    unset($perAttemptSlotData[$aid][$sid]);
                    unset($slotStatusMap[$aid][$sid]);
                    unset($slotScoreMap[$aid][$sid]);
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

    // Last grader name per attempt+slot (essay pills display).
    // Ordered newest-first; first row seen per attempt_slot = most recent.
    $lastGraderRs = $DB->get_recordset_sql(
        "SELECT eg.attemptid, eg.slotid, eg.graderid, eg.timegraded
           FROM {learnguard_essay_grades} eg
          WHERE eg.attemptid $aidSql AND eg.slotid $sidSql
          ORDER BY eg.timegraded DESC",
        array_merge($aidP, $sidP)
    );
    $lastGraderIds = []; // aid_sid => graderid
    foreach ($lastGraderRs as $lgr) {
        $k = $lgr->attemptid . '_' . $lgr->slotid;
        if (!isset($lastGraderIds[$k])) { $lastGraderIds[$k] = (int)$lgr->graderid; }
    }
    $lastGraderRs->close();
    $graderUsers = [];
    if (!empty($lastGraderIds)) {
        $uniqueGraderIds = array_unique(array_values($lastGraderIds));
        [$guSql, $guP] = $DB->get_in_or_equal($uniqueGraderIds, SQL_PARAMS_NAMED, 'gu');
        $graderUsers = $DB->get_records_sql("SELECT id, firstname, lastname FROM {user} WHERE id $guSql", $guP);
    }
    $lastGraderMap = [];
    foreach ($lastGraderIds as $k => $uid) {
        [$aidStr, $sidStr] = explode('_', $k, 2);
        $u = $graderUsers[$uid] ?? null;
        $lastGraderMap[(int)$aidStr][(int)$sidStr] = $u ? fullname($u) : '';
    }
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
    }, array_values($slots ?? []));
}

// Separate students into not-yet-graded / fully-graded buckets.
// Voided attempts are excluded from grading checks — they never block "Graded" tab.
// Only finished attempts with at least one slot need to be fully graded.
$notGradedStudents = [];
$gradedStudents    = [];
foreach ($students as $uid => $s) {
    $allGraded       = true;
    $hasFinishedAtts = false;
    foreach ($s['attempts'] as $att) {
        if (!edg_effectively_finished($att)) { continue; } // only finished (or pending-review) attempts count for graded status
        $aid     = (int)$att->id;
        $attSids = array_keys($perAttemptSlotData[$aid] ?? []);
        if (empty($attSids)) { continue; } // no gradable questions — nothing to grade
        $hasFinishedAtts = true;
        foreach ($attSids as $sid) {
            $status = $slotStatusMap[$aid][$sid] ?? 'na';
            if ($status === 'graded') { continue; }
            // For auto-graded types, unanswered (na) = 0 automatically — no human grader needed.
            if ($status === 'na') {
                $slotQtype = $perAttemptSlotData[$aid][$sid]->qtype ?? 'essay';
                if (in_array($slotQtype, ['multichoice', 'multicheck', 'shortanswer', 'truefalse'], true)) {
                    continue;
                }
            }
            $allGraded = false;
            break 2;
        }
    }
    // Student has no finished attempts with gradable questions: put in graded (nothing to do).
    if (!$hasFinishedAtts) { $allGraded = true; }
    if ($allGraded && !empty($s['attempts'])) { $gradedStudents[$uid]    = $s; }
    else                                      { $notGradedStudents[$uid] = $s; }
}

$totalAttempts = count($rows);
// Ensure slot score map exists (may have been computed above).
$slotScoreMap = isset($slotScoreMap) ? $slotScoreMap : [];

echo $OUTPUT->header();
echo learnguard_activity_tabs($id, 'grader');
?>
<style>
/* ── edwiser_grader faithful clone ───────────────────────────────────────── */
.edg-wrap           { font-family:"Segoe UI",system-ui,sans-serif; }

/* Sliding detail panel — fixed at top of page, full-height */
.edg-detail-panel   { display:none; background:#212529; color:#fff;
                      position:fixed; top:0; left:0; right:0; bottom:0; z-index:1050;
                      box-shadow:0 4px 20px rgba(0,0,0,.4);
                      display:none; flex-direction:column; overflow:hidden; }
.edg-detail-panel.open { display:flex; }
.edg-detail-header  { background:#1a1f23; padding:10px 16px;
                      display:flex; align-items:flex-start; gap:8px; flex-wrap:wrap;
                      flex-shrink:0; max-height:40vh; overflow-y:auto; }
.edg-hq-pill        { background:#495057; color:#fff; border:none; border-radius:4px;
                      padding:5px 12px; font-size:13px; font-weight:700; cursor:pointer; }
.edg-hq-pill.active  { background:#0f6cbf; }
.edg-hq-pill.ok      { background:#28a745; }
.edg-hq-pill.full    { background:#28a745; color:#fff; }
.edg-hq-pill.rg      { background:#0f6cbf; }
.edg-hq-pill.na      { background:#dc3545; }
.edg-hq-pill.partial { background:#ffc107; color:#212529; }
.edg-hq-pill.zero    { background:#dc3545; color:#fff; }
.edg-detail-status  { background:#2c3036; padding:8px 16px; font-size:12px;
                      display:flex; gap:16px; flex-wrap:wrap; align-items:center;
                      flex-shrink:0; }
.edg-state-badge    { background:#ffc107; color:#212529; padding:2px 8px;
                      border-radius:4px; font-weight:700; font-size:11px; }
.edg-state-badge.graded { background:#28a745; color:#fff; }
.edg-state-badge.na     { background:#6c757d; color:#fff; }
.edg-detail-content { display:grid; grid-template-columns:1fr 560px;
                      flex:1; min-height:0; overflow:hidden; }
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
.edg-qpill.full    { background:#28a745; color:#fff; padding:6px 10px; border-radius:6px; display:inline-block; margin:4px; }
.edg-qpill.partial { background:#ffc107; color:#212529; padding:6px 10px; border-radius:6px; display:inline-block; margin:4px; }
.edg-qpill.zero    { background:#dc3545; color:#fff; padding:6px 10px; border-radius:6px; display:inline-block; margin:4px; }
.edg-qpill.rg      { background:#0f6cbf; color:#fff; padding:6px 10px; border-radius:6px; display:inline-block; margin:4px; }
.edg-qpill.na      { background:#6c757d; color:#fff; padding:6px 10px; border-radius:6px; display:inline-block; margin:4px; }
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
.edg-rubric-row         { border-bottom:1px solid #343a40; }
.edg-rubric-crit-name   { color:#e9ecef; }
.edg-rubric-crit-weight { color:#adb5bd; }
.edg-rubric-crit-focus  { color:#adb5bd; }

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
.edg-att-meta       { font-size:11px; color:#6c757d; display:flex; gap:16px; flex-wrap:wrap; align-items:center; }
.edg-row-bottom     { padding:8px 16px 14px; background:#fafafa; border-top:1px solid #f4f4f4; }
.edg-q-pills        { display:flex; gap:5px; flex-wrap:wrap; align-items:center; }
.edg-qpill          { display:inline-block; padding:3px 10px; border-radius:4px;
                      font-size:12px; font-weight:700; color:#fff; cursor:pointer; }
.edg-qpill.full     { background:#28a745; }
.edg-qpill.partial  { background:#ffc107; color:#212529; }
.edg-qpill.zero     { background:#dc3545; }
.edg-qpill.rg       { background:#0f6cbf; }
.edg-qpill.na       { background:#dc3545; }
.edg-qpill.ok       { background:#28a745; }
.edg-qpill:hover    { opacity:.85; }
.edg-empty          { padding:40px 20px; text-align:center; color:#6c757d; font-size:14px; background:#fff; }

/* Filter / sort bar */
.edg-filter-bar { padding:8px 16px; background:#f8f9fa; border-bottom:1px solid #e9ecef;
                  display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.edg-filter-input { flex:1; min-width:180px; padding:6px 10px; border:1px solid #ced4da;
                    border-radius:6px; font-size:13px; outline:none; }
.edg-filter-input:focus { border-color:#0f6cbf; box-shadow:0 0 0 2px rgba(15,108,191,.15); }
.edg-sort-select { padding:6px 10px; border:1px solid #ced4da; border-radius:6px;
                   font-size:13px; background:#fff; cursor:pointer; }
.edg-filter-count { font-size:12px; color:#6c757d; white-space:nowrap; }

@media(max-width:768px){ .edg-detail-content{ grid-template-columns:1fr; } }

/* ── Light mode overrides ─────────────────────────────────────────────── */
.edg-wrap.edg-light .edg-detail-panel  { background:#fff; color:#212529; box-shadow:0 4px 20px rgba(0,0,0,.15); }
.edg-wrap.edg-light .edg-detail-header { background:#f1f3f5; border-bottom:1px solid #dee2e6; }
.edg-wrap.edg-light .edg-hq-pill         { background:#dee2e6; color:#212529; }
.edg-wrap.edg-light .edg-hq-pill.active  { background:#0f6cbf; color:#fff; }
.edg-wrap.edg-light .edg-hq-pill.ok      { background:#28a745; color:#fff; }
.edg-wrap.edg-light .edg-hq-pill.full    { background:#28a745; color:#fff; }
.edg-wrap.edg-light .edg-hq-pill.partial { background:#ffc107; color:#212529; }
.edg-wrap.edg-light .edg-hq-pill.zero    { background:#dc3545; color:#fff; }
.edg-wrap.edg-light .edg-hq-pill.rg      { background:#0f6cbf; color:#fff; }
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
.edg-wrap.edg-light .edg-rubric-row        { border-bottom:1px solid #dee2e6; }
.edg-wrap.edg-light .edg-rubric-crit-name  { color:#212529; }
.edg-wrap.edg-light .edg-rubric-crit-weight { color:#495057; }
.edg-wrap.edg-light .edg-rubric-crit-focus { color:#495057; }
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

      <div id="edg-rubric-wrap" style="display:none;margin-top:10px;">
        <div style="font-size:14px;font-weight:700;color:#adb5bd;margin-bottom:8px;">📋 Grading Rubric</div>
        <div id="edg-rubric-rows"></div>
        <div id="edg-rubric-total-row" style="display:none;font-size:13px;color:#adb5bd;margin-top:8px;
             padding-top:8px;border-top:1px solid #495057;">Running total: <strong id="edg-rubric-total">0</strong></div>
      </div>
      <div class="edg-marks-row" id="edg-marks-row">
        <span style="font-size:14px;font-weight:700;color:#adb5bd;">Mark</span>
        <input type="number" id="edg-mark-in" class="edg-mark-in" min="0" step="any" placeholder="0">
        <span id="edg-mark-total" class="edg-mark-total">/ —</span>
      </div>
      <div class="edg-pct-btns" id="edg-pct-btns"></div>

      <button class="edg-submit-btn" onclick="edgSubmitGrade()">
        ✅ Submit &nbsp;<span id="edg-submit-spin" style="display:none;">⏳</span>
      </button>
      <div id="edg-saved-badge" class="edg-saved-badge">✅ Grade saved successfully.</div>
    </div>
  </div>
</div>

<!-- ── Grader history popup modal ──────────────────────────────────────── -->
<div id="edg-gh-modal" style="display:none;position:fixed;inset:0;z-index:100000;
     background:rgba(0,0,0,.55);align-items:center;justify-content:center;"
     onclick="if(event.target===this)this.style.display='none';">
  <div style="background:#fff;border-radius:8px;padding:24px;max-width:540px;width:92%;
              max-height:82vh;overflow-y:auto;position:relative;box-shadow:0 8px 32px rgba(0,0,0,.25);">
    <button onclick="document.getElementById('edg-gh-modal').style.display='none';"
            style="position:absolute;top:10px;right:14px;background:none;border:none;
                   font-size:20px;cursor:pointer;line-height:1;color:#495057;">✕</button>
    <h5 style="margin:0 0 14px;font-size:15px;color:#212529;">📜 Grade History</h5>
    <div id="edg-gh-body"></div>
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
      <a href="<?php echo new moodle_url('/mod/learnguard/exportsubmissions.php', ['id' => $id]); ?>"
         class="edg-back-btn" style="margin-left:8px;" title="Email or bulk-download a student's submitted question(s)+answer(s) as a plain PDF — no score included">📄 Export Submissions</a>
      <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'graders']); ?>"
         class="edg-back-btn">← Go Back</a>
    </div>
  </div>

  <!-- Stats bar -->
  <div class="edg-stats-bar">
    <span>Attempts: <strong><?php echo $totalAttempts; ?></strong></span>
    <span>Students: <strong><?php echo count($students); ?></strong></span>
    <span>Graded: <strong style="color:#28a745;"><?php echo count($gradedStudents); ?></strong></span>
    <span>Pending: <strong style="color:#dc3545;"><?php echo count($notGradedStudents); ?></strong></span>
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

  <?php if ($isManager && has_capability('moodle/course:manageactivities', $context)): ?>
  <div style="margin:10px 0;padding:6px 12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:12px;color:#6c757d;">
    <span>⚠️ Void or permanently delete this whole assessment — also available for every assessment, one row at a time or in bulk, from
      <a href="<?php echo (new moodle_url('/mod/learnguard/siteadmin.php', ['tab' => 'assessments']))->out(false); ?>">Site Admin → Assessments</a>.</span>
    <span style="margin-left:auto;white-space:nowrap;display:flex;gap:8px;">
      <form method="post" action="" id="edg-void-assessment-form" style="display:inline;">
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'value' => 'voidassessment']); ?>
        <button type="button" class="btn btn-sm btn-outline-warning" onclick="edgVoidAssessment()">🚫 Void This Assessment</button>
      </form>
      <form method="post" action="" id="edg-delete-assessment-form" style="display:inline;">
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'value' => 'deleteassessment']); ?>
        <button type="button" class="btn btn-sm btn-outline-danger"
                onclick="edgDeleteAssessment(<?php echo json_encode(format_string($lg->name)); ?>)">
          🗑 Delete This Assessment
        </button>
      </form>
    </span>
  </div>
  <script>
  function edgVoidAssessment() {
      if (!confirm('Void every finished attempt for this assessment?\n\nThe assessment and its history stay intact — voided attempts are just excluded from grading/scores, and can be reversed individually from "Show Voided Attempts" below.')) { return; }
      document.getElementById('edg-void-assessment-form').submit();
  }
  function edgDeleteAssessment(assessmentName) {
      var typed = prompt('This permanently deletes "' + assessmentName + '" and ALL its data ' +
          '(every attempt, score, chat, snapshot, audio clip, incident). This cannot be undone.\n\n' +
          'Type the assessment name exactly to confirm:');
      if (typed === null) { return; }
      if (typed !== assessmentName) {
          alert('Name did not match — nothing was deleted.');
          return;
      }
      document.getElementById('edg-delete-assessment-form').submit();
  }
  </script>
  <?php endif; ?>

  <?php if ($isManager): ?>
  <?php
  $voidedTotal = $DB->count_records('learnguard_attempts', ['learnguard' => $cm->instance, 'state' => 'voided']);
  ?>
  <div style="margin:6px 0 16px;display:flex;gap:8px;flex-wrap:wrap;">
    <a href="<?php echo (new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'gview' => 'voided']))->out(false); ?>"
       class="btn btn-sm <?php echo $gview === 'voided' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
      👁 Show Voided Attempts<?php echo $voidedTotal ? ' (' . $voidedTotal . ')' : ''; ?>
    </a>
    <a href="<?php echo (new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'gview' => 'noattempt']))->out(false); ?>"
       class="btn btn-sm <?php echo $gview === 'noattempt' ? 'btn-secondary' : 'btn-outline-secondary'; ?>">
      📧 Students With No Attempt
    </a>
  </div>
  <?php endif; ?>

  <?php if ($isManager && $gview === 'voided'): ?>
    <?php
    // ── Voided Attempts view — filterable (finished date / score / voided date),
    // with a bulk Reverse action per row using the prevstate/timevoided columns.
    $vfDateFrom   = optional_param('vf_datefrom', '', PARAM_TEXT);
    $vfDateTo     = optional_param('vf_dateto', '', PARAM_TEXT);
    $vfVoidedFrom = optional_param('vf_voidedfrom', '', PARAM_TEXT);
    $vfVoidedTo   = optional_param('vf_voidedto', '', PARAM_TEXT);
    $vfScoreMin   = optional_param('vf_scoremin', '', PARAM_TEXT);
    $vfScoreMax   = optional_param('vf_scoremax', '', PARAM_TEXT);

    $voidedRows = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.sumgrades, a.timefinish, a.timevoided, a.prevstate,
                u.firstname, u.lastname, u.email
           FROM {learnguard_attempts} a
           JOIN {user} u ON u.id = a.userid
          WHERE a.learnguard = :lgid AND a.state = 'voided'
       ORDER BY a.timevoided DESC, a.timefinish DESC",
        ['lgid' => $cm->instance]
    );
    $voidedFiltered = array_filter($voidedRows, function($r) use (
        $vfDateFrom, $vfDateTo, $vfVoidedFrom, $vfVoidedTo, $vfScoreMin, $vfScoreMax
    ) {
        if ($vfDateFrom !== '' && $r->timefinish < strtotime($vfDateFrom . ' 00:00:00')) { return false; }
        if ($vfDateTo !== '' && $r->timefinish > strtotime($vfDateTo . ' 23:59:59')) { return false; }
        if ($vfVoidedFrom !== '' && (!$r->timevoided || $r->timevoided < strtotime($vfVoidedFrom . ' 00:00:00'))) { return false; }
        if ($vfVoidedTo !== '' && (!$r->timevoided || $r->timevoided > strtotime($vfVoidedTo . ' 23:59:59'))) { return false; }
        if ($vfScoreMin !== '' && (is_null($r->sumgrades) || (float)$r->sumgrades < (float)$vfScoreMin)) { return false; }
        if ($vfScoreMax !== '' && (is_null($r->sumgrades) || (float)$r->sumgrades > (float)$vfScoreMax)) { return false; }
        return true;
    });
    ?>
    <div style="border:1px solid #dee2e6;border-radius:8px;padding:16px;margin-bottom:20px;">
      <h5 style="margin-top:0;">👁 Voided Attempts (<?php echo count($voidedFiltered); ?> of <?php echo count($voidedRows); ?>)</h5>
      <form method="get" style="display:flex;gap:10px;flex-wrap:wrap;align-items:end;margin-bottom:14px;padding:10px;background:#f8f9fa;border-radius:6px;">
        <input type="hidden" name="id" value="<?php echo $id; ?>">
        <input type="hidden" name="gview" value="voided">
        <div><label style="font-size:11px;display:block;">Finished from</label><input type="date" name="vf_datefrom" value="<?php echo s($vfDateFrom); ?>" class="form-control form-control-sm"></div>
        <div><label style="font-size:11px;display:block;">Finished to</label><input type="date" name="vf_dateto" value="<?php echo s($vfDateTo); ?>" class="form-control form-control-sm"></div>
        <div><label style="font-size:11px;display:block;">Voided from</label><input type="date" name="vf_voidedfrom" value="<?php echo s($vfVoidedFrom); ?>" class="form-control form-control-sm"></div>
        <div><label style="font-size:11px;display:block;">Voided to</label><input type="date" name="vf_voidedto" value="<?php echo s($vfVoidedTo); ?>" class="form-control form-control-sm"></div>
        <div><label style="font-size:11px;display:block;">Score min</label><input type="number" step="any" name="vf_scoremin" value="<?php echo s($vfScoreMin); ?>" class="form-control form-control-sm" style="width:90px;"></div>
        <div><label style="font-size:11px;display:block;">Score max</label><input type="number" step="any" name="vf_scoremax" value="<?php echo s($vfScoreMax); ?>" class="form-control form-control-sm" style="width:90px;"></div>
        <button type="submit" class="btn btn-sm btn-primary">Filter</button>
        <a href="<?php echo (new moodle_url('/mod/learnguard/grader.php', ['id' => $id, 'gview' => 'voided']))->out(false); ?>" class="btn btn-sm btn-outline-secondary">Clear</a>
      </form>
      <?php if (empty($voidedFiltered)): ?>
        <p style="color:#6c757d;">No voided attempts<?php echo count($voidedRows) ? ' match this filter.' : ' for this assessment.'; ?></p>
      <?php else: ?>
        <form method="post" id="edg-reverse-form">
          <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
          <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'value' => 'reversevoid']); ?>
          <div style="margin-bottom:8px;">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.edg-rv-chk').forEach(c=>c.checked=true);">☑ Select All</button>
            <button type="submit" class="btn btn-sm btn-success"
                    onclick="return confirm('Restore every ticked attempt to its state before it was voided? This puts it back into normal grading/scoring.');">
              ↩ Reverse Void — Restore Selected
            </button>
          </div>
          <table class="generaltable w-100">
            <tr>
              <th></th><th>Student</th><th>Score</th><th>Finished</th><th>Voided</th><th>Will restore to</th>
            </tr>
            <?php foreach ($voidedFiltered as $vr): ?>
              <tr>
                <td><input type="checkbox" class="edg-rv-chk" name="rvids[]" value="<?php echo $vr->id; ?>"></td>
                <td><?php echo fullname($vr) . ' (' . s($vr->email) . ')'; ?></td>
                <td><?php echo is_null($vr->sumgrades) ? '—' : number_format((float)$vr->sumgrades, 2); ?></td>
                <td><?php echo $vr->timefinish ? userdate($vr->timefinish, '%d %b %Y %H:%M') : '—'; ?></td>
                <td><?php echo $vr->timevoided ? userdate($vr->timevoided, '%d %b %Y %H:%M') : '— (voided before this was tracked)'; ?></td>
                <td><?php echo $vr->prevstate ?: '(best guess) finished/suspended'; ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <?php if ($isManager && $gview === 'noattempt'): ?>
    <?php
    // ── Students With No Attempt view — anyone enrolled with capability to attempt
    // this activity who currently has no state IN (inprogress, finished, suspended)
    // row: genuinely never attempted, OR their only attempt is now voided. Same plain
    // reminder email Score Matrix's own N/A-cell button sends — no mention of voiding.
    $naEnrolled = get_enrolled_users($context, 'mod/learnguard:attempt', 0, 'u.id, u.firstname, u.lastname, u.email');
    $naHasAttempt = $DB->get_records_sql(
        "SELECT DISTINCT userid FROM {learnguard_attempts}
          WHERE learnguard = :lgid AND state IN ('inprogress', 'finished', 'suspended')",
        ['lgid' => $cm->instance]
    );
    $naHasAttemptIds = array_keys($naHasAttempt);
    $naNoAttempt = array_filter($naEnrolled, function($u) use ($naHasAttemptIds) {
        return !in_array($u->id, $naHasAttemptIds);
    });
    ?>
    <div style="border:1px solid #dee2e6;border-radius:8px;padding:16px;margin-bottom:20px;">
      <h5 style="margin-top:0;">📧 Students With No Attempt (<?php echo count($naNoAttempt); ?>)</h5>
      <?php if (empty($naNoAttempt)): ?>
        <p style="color:#6c757d;">Every enrolled student has at least one counted attempt.</p>
      <?php else: ?>
        <form method="post">
          <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
          <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'value' => 'sendreminders']); ?>
          <div style="margin-bottom:8px;">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll('.edg-na-chk').forEach(c=>c.checked=true);">☑ Select All</button>
            <button type="submit" class="btn btn-sm btn-primary"
                    onclick="return confirm('Send a reminder email to every ticked student? It simply says the assessment is showing no attempt yet and asks them to log in and check — no mention of any voided attempt.');">
              📧 Send Reminder To Selected
            </button>
          </div>
          <table class="generaltable w-100">
            <tr><th></th><th>Student</th><th>Email</th></tr>
            <?php foreach ($naNoAttempt as $nu): ?>
              <tr>
                <td><input type="checkbox" class="edg-na-chk" name="remindids[]" value="<?php echo $nu->id; ?>"></td>
                <td><?php echo fullname($nu); ?></td>
                <td><?php echo s($nu->email); ?></td>
              </tr>
            <?php endforeach; ?>
          </table>
        </form>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Tabs: Not Yet Graded | Graded -->
  <div class="edg-tabs">
    <button class="edg-tab active" id="edg-tab-ng" onclick="edgTab('ng')">
      Not Yet Graded &nbsp;<span style="background:#dee2e6;border-radius:10px;padding:1px 8px;font-size:11px;"><?php echo count($notGradedStudents); ?></span>
    </button>
    <button class="edg-tab" id="edg-tab-g" onclick="edgTab('g')">
      Graded &nbsp;<span style="background:#dee2e6;border-radius:10px;padding:1px 8px;font-size:11px;"><?php echo count($gradedStudents); ?></span>
    </button>
  </div>

  <?php if ($isManager): ?>
  <!-- Bulk void/delete for ticked attempts (finished/suspended/voided only, not live sessions) -->
  <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;background:#f8f9fa;
    padding:8px 14px;border-radius:8px;border:1px solid #dee2e6;margin:10px 0;">
    <strong style="font-size:12px;">Bulk:</strong>
    <button type="button" onclick="edgBulkSelectAll(true)" class="btn btn-sm btn-outline-secondary">☑ Select All</button>
    <button type="button" onclick="edgBulkSelectAll(false)" class="btn btn-sm btn-outline-secondary">☐ Deselect All</button>
    <button type="button" class="btn btn-sm btn-warning" onclick="edgBulkSubmit('bulkvoidattempts')">🚫 Void Selected</button>
    <button type="button" class="btn btn-sm btn-danger" onclick="edgBulkSubmit('bulkdeleteattempts')">🗑 Delete Selected</button>
    <span id="edg-bulk-count" style="font-size:12px;color:#6c757d;">0 selected</span>
  </div>
  <form method="post" action="" id="edg-bulk-form" style="display:none;">
    <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
    <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'gaction', 'id' => 'edg-bulk-gaction', 'value' => '']); ?>
    <div id="edg-bulk-attids"></div>
  </form>
  <?php endif; ?>

  <!-- Legend -->
  <div class="edg-legend">
    <span><span class="edg-qpill full" style="cursor:default;">Q✓</span> Full marks</span>
    <span><span class="edg-qpill partial" style="cursor:default;">Q✓</span> Partial</span>
    <span><span class="edg-qpill zero" style="cursor:default;">Q✓</span> Zero / Wrong</span>
    <span><span class="edg-qpill rg" style="cursor:default;">RG</span> Requires Grading</span>
    <span><span class="edg-qpill na" style="cursor:default;">NA</span> Not Answered</span>
    <span>🔄 Review Pending — learner has a Continue &amp; Review session open; grade shown is their prior score</span>
    <span><span style="color:#28a745;">▲</span>/<span style="color:#dc3545;">▼</span> Score rose/fell after a review resubmission</span>
  </div>

  <!-- Filter / sort bar -->
  <div class="edg-filter-bar">
    <input type="search" id="edg-search" class="edg-filter-input"
           placeholder="🔍 Search by name or email…" oninput="edgSortFilter()">
    <select id="edg-sort" class="edg-sort-select" onchange="edgSortFilter()">
      <option value="timefinish-desc">📅 Completed (newest first)</option>
      <option value="timefinish-asc">📅 Completed (oldest first)</option>
      <option value="name-asc">🔤 Name A → Z</option>
      <option value="name-desc">🔤 Name Z → A</option>
      <option value="grade-desc">⭐ Grade (highest first)</option>
      <option value="grade-asc">⭐ Grade (lowest first)</option>
      <option value="timetaken-desc">⏱ Time taken (longest first)</option>
      <option value="timetaken-asc">⏱ Time taken (shortest first)</option>
    </select>
    <span id="edg-filter-count" class="edg-filter-count"></span>
  </div>

  <!-- Not Yet Graded -->
  <div id="edg-pane-ng" class="edg-tab-pane show">
    <?php if (empty($notGradedStudents)): ?>
      <div class="edg-empty">🎉 All students have been fully graded!</div>
    <?php else: ?>
      <?php foreach ($notGradedStudents as $s):
            echo edg_render_student_row($s, $perAttemptSlotData, $slotStatusMap, $essayGradeMap, $perAttemptMaxGrade, $anonymous, $isManager, $id, $lg, $lastGraderMap);
      endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Graded -->
  <div id="edg-pane-g" class="edg-tab-pane">
    <?php if (empty($gradedStudents)): ?>
      <div class="edg-empty">No students fully graded yet.</div>
    <?php else: ?>
      <?php foreach ($gradedStudents as $s):
            echo edg_render_student_row($s, $perAttemptSlotData, $slotStatusMap, $essayGradeMap, $perAttemptMaxGrade, $anonymous, $isManager, $id, $lg, $lastGraderMap);
      endforeach; ?>
    <?php endif; ?>
  </div>

</div><!-- /edg-grader-page -->
</div><!-- /edg-wrap -->

<script>
var edgAjaxUrl         = '<?php echo (new moodle_url('/mod/learnguard/grader.php'))->out(false); ?>';
var edgId              = <?php echo (int)$id; ?>;
var edgSesskey         = '<?php echo sesskey(); ?>';
var edgActivityGrade   = <?php echo (float)$lg->grade; ?>;
var edgEssaySlots      = <?php echo json_encode(array_map(function($s){ return ['slotid' => (int)$s->slotid, 'slot' => (int)$s->slot, 'maxmark' => (float)$s->maxmark]; }, $essaySlots ?? [])); ?>;
var edgPerAttemptSlots = <?php echo json_encode($perAttemptSlots); ?>;
var edgSlotScores      = <?php echo json_encode($slotScoreMap); ?>;
var edgSlotStatus      = <?php echo json_encode($slotStatusMap); ?>;
var edgQuestionCache   = {}; // keyed by "attemptid_slotid" — avoids re-fetching on pill re-click

var edgCurrentAttemptId     = 0;
var edgCurrentSlotId        = 0;
var edgCurrentSlotIdx       = 0;
var edgCurrentAttemptSlots  = []; // filtered slot list for the active attempt
var edgLastGraderHistory    = []; // grade history for the currently open question
var edgLastGradeChangeHistory = []; // true chronological First Grade/Regraded timeline for the currently open question

function edgTab(t) {
    document.querySelectorAll('.edg-tab').forEach(function(b){ b.classList.remove('active'); });
    document.querySelectorAll('.edg-tab-pane').forEach(function(p){ p.classList.remove('show'); });
    document.getElementById('edg-tab-'  + t).classList.add('active');
    document.getElementById('edg-pane-' + t).classList.add('show');
    edgClosePanel();
    edgSortFilter();
}

var edgPageState = {}; // { ng: 1, g: 1 } — current page per tab
var EDG_PAGE_SIZE = 20;

function edgSortFilter(resetPage) {
    var query = (document.getElementById('edg-search').value || '').toLowerCase().trim();
    var sort  = (document.getElementById('edg-sort').value || 'timefinish-desc');
    var parts = sort.split('-');
    var field = parts[0];
    var dir   = parts.slice(1).join('-'); // handles 'timefinish-desc' → field='timefinish', dir='desc'

    var activePane = document.querySelector('.edg-tab-pane.show');
    if (!activePane) { return; }

    var blocks  = Array.from(activePane.querySelectorAll('.edg-student-block'));
    var visible = 0;

    // Step 1: filter visibility
    blocks.forEach(function(b) {
        var haystack = (b.dataset.search || '').toLowerCase();
        var show     = !query || haystack.indexOf(query) !== -1;
        b.style.display = show ? '' : 'none';
        if (show) { visible++; }
    });

    // Step 2: sort only the visible blocks, then re-append them
    var sortable = blocks.filter(function(b){ return b.style.display !== 'none'; });
    sortable.sort(function(a, b) {
        if (field === 'name') {
            var an = a.dataset.name || '', bn = b.dataset.name || '';
            return dir === 'asc' ? an.localeCompare(bn) : bn.localeCompare(an);
        }
        var av = parseFloat(a.dataset[field]) || 0;
        var bv = parseFloat(b.dataset[field]) || 0;
        return dir === 'asc' ? av - bv : bv - av;
    });
    sortable.forEach(function(b){ activePane.appendChild(b); });

    // When sorting by completion date, also reorder attempts within each student block
    // so the most-recently-completed attempt appears first inside the block.
    if (field === 'timefinish') {
        sortable.forEach(function(block) {
            var rows = Array.from(block.querySelectorAll('.edg-student-row'));
            if (rows.length < 2) { return; }
            rows.sort(function(a, b) {
                var av = parseFloat(a.dataset.timefinish) || 0;
                var bv = parseFloat(b.dataset.timefinish) || 0;
                return dir === 'asc' ? av - bv : bv - av;
            });
            rows.forEach(function(r){ block.appendChild(r); });
        });
    }

    // Step 3: paginate the filtered+sorted set, 20 per page.
    var paneId = activePane.id.replace('edg-pane-', '');
    if (resetPage !== false) { edgPageState[paneId] = 1; }
    var totalPages = Math.max(1, Math.ceil(sortable.length / EDG_PAGE_SIZE));
    var page = edgPageState[paneId] || 1;
    if (page > totalPages) { page = totalPages; }
    edgPageState[paneId] = page;
    sortable.forEach(function(b, i) {
        var onPage = (i >= (page - 1) * EDG_PAGE_SIZE && i < page * EDG_PAGE_SIZE);
        b.style.display = onPage ? '' : 'none';
    });
    edgRenderPagination(activePane, paneId, sortable.length, page, totalPages);

    // Step 4: show no-results message if needed
    var emptyEl = activePane.querySelector('.edg-empty');
    if (query && visible === 0 && !emptyEl) {
        var msg = document.createElement('div');
        msg.className = 'edg-empty edg-filter-empty';
        msg.textContent = 'No students match "' + query + '".';
        activePane.appendChild(msg);
    } else if (emptyEl && emptyEl.classList.contains('edg-filter-empty')) {
        emptyEl.remove();
    }

    // Step 5: update count display
    var countEl = document.getElementById('edg-filter-count');
    if (countEl) {
        countEl.textContent = visible + ' student' + (visible !== 1 ? 's' : '');
    }
}

function edgRenderPagination(activePane, paneId, total, page, totalPages) {
    var existing = activePane.querySelector('.edg-pagination');
    if (existing) { existing.remove(); }
    if (total <= EDG_PAGE_SIZE) { return; }
    var wrap = document.createElement('div');
    wrap.className = 'edg-pagination';
    wrap.style.cssText = 'display:flex;gap:6px;justify-content:center;align-items:center;padding:16px 0;flex-wrap:wrap;';
    var html = '<button type="button" class="btn btn-sm btn-outline-secondary"' +
        (page <= 1 ? ' disabled' : ' onclick="edgGoToPage(\'' + paneId + '\',' + (page - 1) + ')"') + '>‹ Prev</button>';
    for (var p = 1; p <= totalPages; p++) {
        html += '<button type="button" class="btn btn-sm ' + (p === page ? 'btn-primary' : 'btn-outline-secondary') +
            '" onclick="edgGoToPage(\'' + paneId + '\',' + p + ')">' + p + '</button>';
    }
    html += '<button type="button" class="btn btn-sm btn-outline-secondary"' +
        (page >= totalPages ? ' disabled' : ' onclick="edgGoToPage(\'' + paneId + '\',' + (page + 1) + ')"') + '>Next ›</button>';
    wrap.innerHTML = html;
    activePane.appendChild(wrap);
}

function edgGoToPage(paneId, page) {
    edgPageState[paneId] = page;
    edgSortFilter(false);
}

function edgBulkSelectAll(sel) {
    // Only checkboxes currently visible (respecting the active pane + search + page).
    document.querySelectorAll('.edg-tab-pane.show .edg-student-block').forEach(function(block) {
        if (block.style.display === 'none') { return; }
        block.querySelectorAll('.edg-student-row').forEach(function(row) {
            if (row.style.display === 'none') { return; }
            var chk = row.querySelector('.edg-bulk-chk');
            if (chk) { chk.checked = sel; }
        });
    });
    edgUpdateBulkCount();
}

function edgUpdateBulkCount() {
    // Same visible-in-active-pane scoping edgBulkSubmit() now uses — the displayed
    // count must match what a bulk action will actually touch, not a raw global count
    // that could include stale-checked boxes sitting in the other, hidden tab.
    var n = edgVisibleCheckedBulkBoxes().length;
    var el = document.getElementById('edg-bulk-count');
    if (el) { el.textContent = n + ' selected'; }
}
document.addEventListener('change', function(e) {
    if (e.target && e.target.classList && e.target.classList.contains('edg-bulk-chk')) { edgUpdateBulkCount(); }
});

// Reported live: ticking 15 attempts and clicking "Void Selected" voided 407 instead —
// this collected EVERY '.edg-bulk-chk:checked' box in the whole document, including
// ones sitting in the OTHER tab (Not Yet Graded / Graded both stay in the DOM at once,
// only CSS-hidden via .edg-tab-pane.show) or hidden by an active search filter, left
// checked from an earlier click and never visible/unticked since. Same class of stale-
// checkbox leak already fixed for assessmentmanager.php in v2.0.189 — this page's own
// edgBulkSelectAll() already scoped correctly; edgBulkSubmit() (used by BOTH Void
// Selected and the even more dangerous Delete Selected) never did. Now shares the exact
// same "visible in the active pane" scoping so a bulk action can never reach further
// than what's actually on screen and ticked.
function edgVisibleCheckedBulkBoxes() {
    var out = [];
    document.querySelectorAll('.edg-tab-pane.show .edg-student-block').forEach(function(block) {
        if (block.style.display === 'none') { return; }
        block.querySelectorAll('.edg-student-row').forEach(function(row) {
            if (row.style.display === 'none') { return; }
            var chk = row.querySelector('.edg-bulk-chk');
            if (chk && chk.checked) { out.push(chk); }
        });
    });
    return out;
}
function edgBulkSubmit(action) {
    var checked = edgVisibleCheckedBulkBoxes();
    if (checked.length === 0) { alert('Please select at least one attempt.'); return; }
    var ok = (action === 'bulkdeleteattempts')
        ? confirm('PERMANENTLY DELETE ' + checked.length + ' attempt(s)? This removes every score, response, snapshot, and audio clip for them. This CANNOT be undone.')
        : confirm('Void ' + checked.length + ' attempt(s)? They stay visible (struck through) and are excluded from grading/scores. This can be reversed from the Score Matrix.');
    if (!ok) { return; }
    document.getElementById('edg-bulk-gaction').value = action;
    var targets = document.getElementById('edg-bulk-attids');
    targets.innerHTML = '';
    checked.forEach(function(c) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = 'attids[]'; inp.value = c.getAttribute('data-attid');
        targets.appendChild(inp);
    });
    document.getElementById('edg-bulk-form').submit();
}

// Apply default sort (newest completion) on page load.
document.addEventListener('DOMContentLoaded', function(){ edgSortFilter(); });

// edgToggleAttempt removed — each attempt now has its own flat row (no tabs).

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
        var pill     = document.createElement('button');
        var status   = ((edgSlotStatus[attemptid] || {})[slot.slotid]) || 'na';
        var score    = (edgSlotScores[attemptid] || {})[slot.slotid];
        var colorCls = '';
        if (status === 'graded') {
            if (score === null || score === undefined || score >= 0.9999) { colorCls = ' full'; }
            else if (score > 0) { colorCls = ' partial'; }
            else { colorCls = ' zero'; }
        } else if (status === 'rg') {
            colorCls = ' rg';
        } else {
            colorCls = ' na';
        }
        pill.className = 'edg-hq-pill' + colorCls + (i === slotidx ? ' active' : '');
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
    edgCurrentRubric = null;
    document.getElementById('edg-rubric-wrap').style.display = 'none';
    document.getElementById('edg-marks-row').style.display   = 'flex';

    // Fetch question data (served from cache on re-click to avoid repeated round-trips).
    var cacheKey = attemptid + '_' + slotid;
    var fetchPromise = edgQuestionCache[cacheKey]
        ? Promise.resolve(edgQuestionCache[cacheKey])
        : (function() {
            var url = edgAjaxUrl + '?id=' + edgId + '&lgajax=1&action=get_question&sesskey=' + edgSesskey
                    + '&attemptid=' + attemptid + '&slotid=' + slotid;
            return fetch(url).then(function(r){ return r.json(); }).then(function(d) {
                if (d.ok) { edgQuestionCache[cacheKey] = d; }
                return d;
            });
        })();
    fetchPromise.then(function(d) {
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
        
        edgLastGraderHistory = d.grader_history || [];
        edgLastGradeChangeHistory = d.grade_change_history || [];
        var badge = document.getElementById('edg-state-badge');
        if (!d.has_answer) {
            badge.textContent = 'Not Answered'; badge.className = 'edg-state-badge na';
        } else if (d.existing_grade !== null) {
            if (d.graded_by === 'You') {
                var byHtml = 'Graded by <a href="#" onclick="edgShowGraderHistory();return false;"'
                    + ' style="color:#fff;text-decoration:underline;">You</a>';
                if (edgLastGraderHistory.length > 1) {
                    byHtml += ' <em style="font-size:11px;opacity:0.8;">(click to see ' + edgLastGraderHistory.length + ' grade entries)</em>';
                }
                badge.innerHTML = byHtml; badge.className = 'edg-state-badge graded';
            } else if (d.graded_by === 'Auto') {
                badge.innerHTML = 'Auto-graded <em style="font-size:11px;opacity:0.8;">(you can override)</em>';
                badge.className = 'edg-state-badge graded';
            } else if (d.graded_by) {
                badge.innerHTML = 'Graded by <a href="#" onclick="edgShowGraderHistory();return false;"'
                    + ' style="color:#fff;text-decoration:underline;">' + d.graded_by + '</a>'
                    + ' <em style="font-size:11px;opacity:0.8;">(you can override)</em>';
                badge.className = 'edg-state-badge graded';
            }
        } else {
            badge.textContent = 'Requires Grading'; badge.className = 'edg-state-badge';
        }

        if (d.existing_grade !== null)   { document.getElementById('edg-mark-in').value     = d.existing_grade; }
        if (d.existing_feedback)         { document.getElementById('edg-feedback-ta').value  = d.existing_feedback; }
        document.getElementById('edg-mark-in').max = d.maxmark;

        // Rubric grading (Choice Essay only, and only when this activity has one) —
        // swap the plain single-mark box for one score input per criterion, capped at
        // that criterion's own weight. The grader never sees a computed total unless the
        // admin explicitly turned that on (d.rubric.showtotal).
        var rubricWrap = document.getElementById('edg-rubric-wrap');
        var marksRow   = document.getElementById('edg-marks-row');
        var pctWrap    = document.getElementById('edg-pct-btns');
        if (d.rubric && d.rubric.criteria && d.rubric.criteria.length > 0) {
            edgCurrentRubric = d.rubric.criteria;
            marksRow.style.display = 'none';
            pctWrap.style.display = 'none';
            rubricWrap.style.display = 'block';
            var rowsHtml = '';
            d.rubric.criteria.forEach(function(c) {
                rowsHtml += '<div class="edg-rubric-row" style="display:flex;align-items:flex-start;gap:10px;padding:8px 0;">' +
                    '<div style="flex:1;"><div class="edg-rubric-crit-name" style="font-size:13px;font-weight:600;">' + c.name +
                    ' <span class="edg-rubric-crit-weight" style="font-weight:400;">(' + c.weight + '%)</span></div>' +
                    (c.focus ? '<div class="edg-rubric-crit-focus" style="font-size:12px;margin-top:2px;">' + c.focus + '</div>' : '') +
                    '</div>' +
                    '<div style="display:flex;flex-direction:column;align-items:flex-end;gap:2px;">' +
                    '<input type="number" id="edg-rubric-score-' + c.id + '" class="edg-mark-in" style="width:64px;" ' +
                    'min="0" max="' + c.weight + '" step="any" value="' + (c.score !== null ? c.score : '') + '" ' +
                    'placeholder="0" oninput="edgRubricInputChanged(this, ' + c.weight + ')">' +
                    '<div id="edg-rubric-warn-' + c.id + '" style="display:none;color:#dc3545;font-size:11px;">Max ' + c.weight + '</div>' +
                    '</div>' +
                    '</div>';
            });
            document.getElementById('edg-rubric-rows').innerHTML = rowsHtml;
            document.getElementById('edg-rubric-total-row').style.display = d.rubric.showtotal ? 'block' : 'none';
            edgUpdateRubricTotal();
        } else {
            edgCurrentRubric = null;
            rubricWrap.style.display = 'none';
            marksRow.style.display = 'flex';
            pctWrap.style.display = 'flex';
        }

        // Quick-percent buttons (plain single-mark mode only).
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

        // Update the submit button label to reflect what will happen after saving.
        edgUpdateSubmitButton();
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
        if (d.ok) { edgOnGradeSaved(d, grade); }
        else if (d.error) { alert(d.error); }
    }).catch(function() {
        btn.disabled = false;
        document.getElementById('edg-submit-spin').style.display = 'none';
        alert('Failed to save grade. Please try again.');
    });
}

// Shared post-save UI update — used by both the plain single-mark save (edgSaveGrade)
// and the rubric save (edgSaveRubricGrade), since both ultimately produce one final
// grade value for the slot and need to update the same pills/caches/navigation.
function edgOnGradeSaved(d, grade) {
    document.getElementById('edg-saved-badge').style.display = 'block';
    var maxInp   = document.getElementById('edg-mark-in');
    var maxMark  = parseFloat(maxInp.max) || 0;
    var savedPct = maxMark > 0 ? parseFloat(grade) / maxMark : 0;
    var savedCls = savedPct >= 0.9999 ? 'full' : (savedPct > 0 ? 'partial' : 'zero');
    var pill = document.querySelector('.edg-qpill[data-attempt="' + edgCurrentAttemptId + '"][data-slotid="' + edgCurrentSlotId + '"]');
    if (pill) { pill.className = 'edg-qpill ' + savedCls; pill.textContent = 'Q' + (edgCurrentSlotIdx + 1) + ' ✓'; }
    var navPills = document.querySelectorAll('#edg-q-nav .edg-hq-pill');
    if (navPills[edgCurrentSlotIdx]) { navPills[edgCurrentSlotIdx].className = 'edg-hq-pill active ' + savedCls; }
    if (!edgSlotScores[edgCurrentAttemptId]) { edgSlotScores[edgCurrentAttemptId] = {}; }
    edgSlotScores[edgCurrentAttemptId][edgCurrentSlotId] = savedPct;
    if (!edgSlotStatus[edgCurrentAttemptId]) { edgSlotStatus[edgCurrentAttemptId] = {}; }
    edgSlotStatus[edgCurrentAttemptId][edgCurrentSlotId] = 'graded';
    delete edgQuestionCache[edgCurrentAttemptId + '_' + edgCurrentSlotId];
    if (d.sumgrades !== null && d.sumgrades !== undefined) {
        var slots2 = edgPerAttemptSlots[edgCurrentAttemptId] || [];
        var totalMax2 = 0;
        for (var ti = 0; ti < slots2.length; ti++) { totalMax2 += (slots2[ti].maxmark || 0); }
        var rawEarned2 = (totalMax2 > 0 && edgActivityGrade > 0)
            ? Math.round(d.sumgrades * totalMax2 / edgActivityGrade * 100) / 100 : 0;
        var gradeDisplay2 = rawEarned2.toFixed(2) + ' / ' + totalMax2.toFixed(2);
        var rowPP = document.querySelector('.edg-q-pills[data-attempt="' + edgCurrentAttemptId + '"]');
        var rowEl = rowPP ? rowPP.closest('.edg-student-row') : null;
        if (rowEl) { var gvEl = rowEl.querySelector('.edg-grade-val'); if (gvEl) { gvEl.textContent = gradeDisplay2; } }
    }
    edgUpdateSubmitButton();
    setTimeout(function() {
        var statuses  = edgSlotStatus[edgCurrentAttemptId] || {};
        var nextRgIdx = -1;
        var i;
        for (i = edgCurrentSlotIdx + 1; i < edgCurrentAttemptSlots.length; i++) {
            if ((statuses[edgCurrentAttemptSlots[i].slotid] || 'na') === 'rg') { nextRgIdx = i; break; }
        }
        if (nextRgIdx === -1) {
            for (i = 0; i < edgCurrentSlotIdx; i++) {
                if ((statuses[edgCurrentAttemptSlots[i].slotid] || 'na') === 'rg') { nextRgIdx = i; break; }
            }
        }
        if (nextRgIdx !== -1) {
            edgOpenQuestion(edgCurrentAttemptId, edgCurrentAttemptSlots[nextRgIdx].slotid, nextRgIdx);
        } else {
            edgAdvanceToNextStudent();
        }
    }, 800);
}

// Rubric grading — one score box per criterion (capped client-side at that criterion's
// weight, re-validated server-side regardless), summed server-side into the one final
// grade value that then flows through the exact same edgOnGradeSaved() path as a plain
// single-mark save.
var edgCurrentRubric = null; // criteria array for the currently-open slot, or null.
function edgSaveRubricGrade() {
    if (!edgCurrentRubric) { return; }
    var feedback = document.getElementById('edg-feedback-ta').value;
    var body = 'id=' + edgId + '&lgajax=1&action=save_rubric_grade&sesskey=' + edgSesskey
        + '&attemptid=' + edgCurrentAttemptId + '&slotid=' + edgCurrentSlotId
        + '&feedback=' + encodeURIComponent(feedback);
    for (var i = 0; i < edgCurrentRubric.length; i++) {
        var c = edgCurrentRubric[i];
        var inp = document.getElementById('edg-rubric-score-' + c.id);
        var val = inp ? parseFloat(inp.value) : 0;
        if (isNaN(val) || val < 0) { val = 0; }
        if (val > c.weight) {
            alert('"' + c.name + '" cannot exceed its weight of ' + c.weight + '.');
            return;
        }
        body += '&scores[' + c.id + ']=' + encodeURIComponent(val);
    }
    var btn = document.querySelector('.edg-submit-btn');
    btn.disabled = true;
    document.getElementById('edg-submit-spin').style.display = 'inline';
    fetch(edgAjaxUrl, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
    }).then(function(r){ return r.json(); }).then(function(d) {
        btn.disabled = false;
        document.getElementById('edg-submit-spin').style.display = 'none';
        if (d.ok) { edgOnGradeSaved(d, d.grade); }
        else if (d.error) { alert(d.error); }
    }).catch(function() {
        btn.disabled = false;
        document.getElementById('edg-submit-spin').style.display = 'none';
        alert('Failed to save grade. Please try again.');
    });
}

// Router — the Submit button always calls this; it dispatches to whichever save path
// applies to the currently-open slot.
function edgSubmitGrade() {
    if (edgCurrentRubric) { edgSaveRubricGrade(); } else { edgSaveGrade(); }
}

// Rubric score input: flags AND clamps immediately (not just on Submit) — a grader
// should never be able to hold a value above the criterion's own weight in the box at
// all, per explicit request. The little "Max N" warning briefly appears the moment the
// entered value is capped, so it's clear something happened rather than the number just
// silently changing under them.
function edgRubricInputChanged(inp, weight) {
    var val = parseFloat(inp.value);
    var warnEl = document.getElementById('edg-rubric-warn-' + inp.id.replace('edg-rubric-score-', ''));
    if (!isNaN(val) && val > weight) {
        inp.value = weight;
        inp.style.borderColor = '#dc3545';
        if (warnEl) {
            warnEl.style.display = 'block';
            clearTimeout(inp._edgWarnTimer);
            inp._edgWarnTimer = setTimeout(function() {
                warnEl.style.display = 'none';
                inp.style.borderColor = '';
            }, 2500);
        }
    } else {
        inp.style.borderColor = '';
        if (warnEl) { warnEl.style.display = 'none'; }
    }
    edgUpdateRubricTotal();
}

// Live running total for the rubric's own optional display — only ever shown when the
// admin enabled rubric_show_total_grader; otherwise the row stays hidden and this is a
// harmless no-op write to an invisible element.
function edgUpdateRubricTotal() {
    if (!edgCurrentRubric) { return; }
    var total = 0;
    edgCurrentRubric.forEach(function(c) {
        var inp = document.getElementById('edg-rubric-score-' + c.id);
        var val = inp ? parseFloat(inp.value) : 0;
        if (!isNaN(val)) { total += Math.min(Math.max(val, 0), c.weight); }
    });
    var totalEl = document.getElementById('edg-rubric-total');
    if (totalEl) { totalEl.textContent = total + ' / 100'; }
}

function edgClosePanel() {
    document.getElementById('edg-detail-panel').classList.remove('open');
    document.body.style.overflow = '';
}

// Dynamically label the submit button based on what will happen after saving:
//   "Submit"                 — more ungraded questions remain for this student
//   "Submit & Next Student"  — this is the last ungraded question; another student awaits
//   "Submit & Close"         — this is the last ungraded question; no more students
function edgUpdateSubmitButton() {
    var btn = document.querySelector('.edg-submit-btn');
    if (!btn || !edgCurrentAttemptId) { return; }
    var spin = '<span id="edg-submit-spin" style="display:none;">⏳</span>';
    var statuses = edgSlotStatus[edgCurrentAttemptId] || {};

    // 1. Are there other 'rg' (ungraded) slots for this student after the current one?
    var moreRgForThis = false;
    for (var i = 0; i < edgCurrentAttemptSlots.length; i++) {
        if (edgCurrentAttemptSlots[i].slotid === edgCurrentSlotId) { continue; }
        if ((statuses[edgCurrentAttemptSlots[i].slotid] || 'na') === 'rg') { moreRgForThis = true; break; }
    }
    if (moreRgForThis) {
        btn.innerHTML = '✅ Submit &nbsp;' + spin;
        return;
    }

    // 2. No more 'rg' slots. If there are more slots after the current position
    //    (i.e. the grader is reviewing already-graded questions, not on the last one),
    //    show plain "Submit" — do NOT jump to "Submit & Next Student" yet.
    if (edgCurrentSlotIdx < edgCurrentAttemptSlots.length - 1) {
        btn.innerHTML = '✅ Submit &nbsp;' + spin;
        return;
    }

    // 3. This is the last slot for this student. Determine if another student has rg pills.
    var hasNextStudent = false;
    var candidates = document.querySelectorAll('.edg-qpill.rg');
    for (var j = 0; j < candidates.length; j++) {
        if (parseInt(candidates[j].getAttribute('data-attempt'), 10) !== edgCurrentAttemptId) {
            hasNextStudent = true; break;
        }
    }
    btn.innerHTML = hasNextStudent
        ? '✅ Submit &amp; Next Student &nbsp;' + spin
        : '✅ Submit &amp; Close &nbsp;' + spin;
}

function edgAdvanceToNextStudent() {
    var badge = document.getElementById('edg-saved-badge');

    // Move the fully-graded student's row from "Not Yet Graded" to "Graded" pane.
    var pillPanel  = document.querySelector('.edg-q-pills[data-attempt="' + edgCurrentAttemptId + '"]');
    var studentRow = pillPanel ? pillPanel.closest('.edg-student-row') : null;
    var ngPane     = document.getElementById('edg-pane-ng');
    var gPane      = document.getElementById('edg-pane-g');
    if (studentRow && gPane) {
        gPane.insertBefore(studentRow, gPane.firstChild);
        // Update the count badges in the tab buttons.
        var ngTab = document.getElementById('edg-tab-ng');
        if (ngTab) { var ns = ngTab.querySelector('span'); if (ns) { ns.textContent = Math.max(0, (parseInt(ns.textContent, 10) || 0) - 1); } }
        var gTab  = document.getElementById('edg-tab-g');
        if (gTab)  { var gs = gTab.querySelector('span'); if (gs)  { gs.textContent = (parseInt(gs.textContent, 10) || 0) + 1; } }
        // If the ng pane is now empty, show the "all done" message.
        if (ngPane && ngPane.querySelectorAll('.edg-student-row').length === 0) {
            ngPane.innerHTML = '<div class="edg-empty">🎉 All students have been fully graded!</div>';
        }
    }

    // Find the first ungraded (rg) pill belonging to a different attempt.
    // Extract attemptid, slotid, and slotidx so we can call edgOpenQuestion() directly —
    // more reliable than pill.click() which depends on inline onclick firing correctly.
    var nextAttemptId = 0;
    var nextSlotId    = 0;
    var nextSlotIdx   = 0;
    var rgPills = document.querySelectorAll('.edg-qpill.rg');
    for (var p = 0; p < rgPills.length; p++) {
        var pAid = parseInt(rgPills[p].getAttribute('data-attempt'), 10);
        var pSid = parseInt(rgPills[p].getAttribute('data-slotid'),  10);
        if (pAid > 0 && pAid !== edgCurrentAttemptId) {
            nextAttemptId = pAid;
            nextSlotId    = pSid;
            // Resolve index within edgPerAttemptSlots[pAid] for the nav bar.
            var pSlots = edgPerAttemptSlots[pAid] || edgEssaySlots;
            for (var si = 0; si < pSlots.length; si++) {
                if (pSlots[si].slotid === pSid) { nextSlotIdx = si; break; }
            }
            break;
        }
    }

    if (nextAttemptId > 0) {
        if (badge) { badge.textContent = '✅ All questions graded — moving to next student…'; badge.style.display = 'block'; }
        setTimeout(function() { edgOpenQuestion(nextAttemptId, nextSlotId, nextSlotIdx); }, 900);
    } else {
        if (badge) { badge.textContent = '✅ All students graded! Closing panel…'; badge.style.display = 'block'; }
        setTimeout(function() { edgClosePanel(); }, 1200);
    }
}

function edgResponseHistory() {
    if (!edgCurrentAttemptId) { return; }
    window.open('<?php echo (new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id]))->out(false); ?>&attempt=' + edgCurrentAttemptId, '_blank');
}

function edgShowGraderHistory() {
    var modal = document.getElementById('edg-gh-modal');
    var body  = document.getElementById('edg-gh-body');
    if (!edgLastGraderHistory || !edgLastGraderHistory.length) {
        body.innerHTML = '<em style="color:#6c757d;">No manual grade history for this question.</em>';
    } else {
        var html = '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            + '<thead><tr style="border-bottom:2px solid #dee2e6;text-align:left;">'
            + '<th style="padding:6px 10px;">Grader</th>'
            + '<th style="padding:6px 10px;text-align:right;">Grade</th>'
            + '<th style="padding:6px 10px;text-align:right;">Date</th>'
            + '</tr></thead><tbody>';
        edgLastGraderHistory.forEach(function(g) {
            html += '<tr style="border-bottom:1px solid #f0f0f0;'
                  + (g.is_me ? 'background:#f0fff4;' : '') + '">'
                  + '<td style="padding:6px 10px;">' + g.name + '</td>'
                  + '<td style="padding:6px 10px;text-align:right;font-weight:700;">' + g.grade + '</td>'
                  + '<td style="padding:6px 10px;text-align:right;font-size:11px;color:#6c757d;">' + g.time + '</td>'
                  + '</tr>';
        });
        html += '</tbody></table>';
        body.innerHTML = html;
    }

    // Regrade timeline — the true chronological record of every save (not just the
    // latest-per-grader snapshot above): was this a First Grade or a Regrade, what was
    // the previous score, and who changed it, when. Only populated for grades saved
    // since v2.0.210 (learnguard_grade_history) — older grades have nothing to show here.
    var tlHtml = '<h4 style="margin:16px 0 8px;font-size:13px;color:#6c757d;">Regrade Timeline</h4>';
    if (!edgLastGradeChangeHistory || !edgLastGradeChangeHistory.length) {
        tlHtml += '<em style="color:#6c757d;font-size:12px;">No regrade history recorded for this question yet.</em>';
    } else {
        tlHtml += '<table style="width:100%;border-collapse:collapse;font-size:13px;">'
            + '<thead><tr style="border-bottom:2px solid #dee2e6;text-align:left;">'
            + '<th style="padding:6px 10px;">Grader</th>'
            + '<th style="padding:6px 10px;">Status</th>'
            + '<th style="padding:6px 10px;text-align:right;">Previous</th>'
            + '<th style="padding:6px 10px;text-align:right;">New</th>'
            + '<th style="padding:6px 10px;text-align:right;">Date</th>'
            + '</tr></thead><tbody>';
        edgLastGradeChangeHistory.forEach(function(g) {
            var statusColor = (g.status === 'Regraded') ? '#dc3545' : '#28a745';
            tlHtml += '<tr style="border-bottom:1px solid #f0f0f0;">'
                  + '<td style="padding:6px 10px;">' + g.name + '</td>'
                  + '<td style="padding:6px 10px;color:' + statusColor + ';font-weight:600;">' + g.status + '</td>'
                  + '<td style="padding:6px 10px;text-align:right;color:#6c757d;">' + (g.oldgrade === null ? '—' : g.oldgrade) + '</td>'
                  + '<td style="padding:6px 10px;text-align:right;font-weight:700;">' + g.newgrade + '</td>'
                  + '<td style="padding:6px 10px;text-align:right;font-size:11px;color:#6c757d;">' + g.time + '</td>'
                  + '</tr>';
        });
        tlHtml += '</tbody></table>';
    }
    body.innerHTML += tlHtml;
    modal.style.display = 'flex';
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
function edg_render_student_row($s, $perAttemptSlotData, $slotStatusMap, $essayGradeMap, $perAttemptMaxGrade, $anonymous, $isManager, $cmid, $lg = null, $lastGraderMap = []) {
    global $slotScoreMap;
    $initials    = $anonymous ? '??' : strtoupper(substr($s['firstname'] ?? '?', 0, 1) . substr($s['lastname'] ?? '?', 0, 1));
    $displayName = $anonymous ? 'Candidate #' . array_values($s['attempts'])[0]->id : fullname((object)$s);
    $uid         = (int)$s['userid'];

    // One flat row per attempt — no tabs, no hiding/showing.
    $actGrade  = $lg ? (float)$lg->grade : 0;
    $passMark  = $lg ? (float)($lg->passmark ?? 0) : 0;
    $reportUrl = (new moodle_url('/mod/learnguard/grader_user_report.php', ['id' => $cmid, 'userid' => $uid]))->out(false);

    // Compute sort/filter data from the latest finished (or pending-review) attempt.
    $latestFin = null;
    foreach ($s['attempts'] as $att) {
        if (!edg_effectively_finished($att)) { continue; }
        if ($latestFin === null || (int)$att->timefinish > (int)$latestFin->timefinish) {
            $latestFin = $att;
        }
    }
    $sortTimefinish = $latestFin ? (int)$latestFin->timefinish : 0;
    $sortGrade      = ($latestFin && edg_effective_sumgrades($latestFin) !== null) ? (float)edg_effective_sumgrades($latestFin) : -1;
    $sortTimetaken  = ($latestFin && $latestFin->timestart && $latestFin->timefinish)
        ? max(0, (int)$latestFin->timefinish - (int)$latestFin->timestart) : 0;
    $sortName       = $anonymous ? 'candidate' : strtolower(($s['lastname'] ?? '') . ' ' . ($s['firstname'] ?? ''));
    $searchText     = $anonymous ? '' : strtolower(($s['firstname'] ?? '') . ' ' . ($s['lastname'] ?? '') . ' ' . ($s['email'] ?? ''));

    $html  = '<div class="edg-student-block"'
           . ' data-name="' . htmlspecialchars($sortName, ENT_QUOTES) . '"'
           . ' data-search="' . htmlspecialchars($searchText, ENT_QUOTES) . '"'
           . ' data-timefinish="' . $sortTimefinish . '"'
           . ' data-grade="' . $sortGrade . '"'
           . ' data-timetaken="' . $sortTimetaken . '">';

    foreach ($s['attempts'] as $i => $att) {
        $attId     = (int)$att->id;
        $attRawMax = (float)($perAttemptMaxGrade[$attId] ?? 0);
        $attSumG   = edg_effective_sumgrades($att);
        $attEarned = ($actGrade > 0 && $attRawMax > 0 && $attSumG !== null)
            ? round((float)$attSumG * $attRawMax / $actGrade, 2) : null;
        if ($att->state === 'voided') {
            $gradeDisplay = ($attEarned !== null && $attRawMax > 0)
                ? number_format($attEarned, 2) . ' / ' . number_format($attRawMax, 2)
                  . ' <em style="font-size:11px;color:#dc3545;">(voided)</em>'
                : '<em style="font-size:11px;color:#dc3545;">(voided)</em>';
        } elseif ($attEarned !== null && $attRawMax > 0) {
            $gradeDisplay = number_format($attEarned, 2) . ' / ' . number_format($attRawMax, 2);
        } elseif ($attRawMax > 0) {
            $gradeDisplay = '— / ' . number_format($attRawMax, 2);
        } else {
            $gradeDisplay = '—';
        }
        // Score changed after a review resubmission — same green ▲ / red ▼ pattern as
        // scores.php, shown once the learner has actually resubmitted (state='finished'),
        // comparing the new sumgrades against the pre-review snapshot.
        if ($att->state === 'finished' && $att->sumgrades !== null && $att->prev_sumgrades !== null) {
            if ((float)$att->sumgrades > (float)$att->prev_sumgrades) {
                $gradeDisplay .= ' <span title="Up from ' . number_format((float)$att->prev_sumgrades, 2) . '" style="color:#28a745;font-weight:700;">▲</span>';
            } elseif ((float)$att->sumgrades < (float)$att->prev_sumgrades) {
                $gradeDisplay .= ' <span title="Down from ' . number_format((float)$att->prev_sumgrades, 2) . '" style="color:#dc3545;font-weight:700;">▼</span>';
            }
        }
        // Review granted but not yet resubmitted — the grade above is the PRIOR score,
        // kept visible (instead of vanishing) while the learner has their one review open.
        if ($att->state === 'inprogress' && !empty($att->reopened_for_review)) {
            $gradeDisplay .= ' <span style="background:#0f6cbf;color:#fff;font-size:10px;font-weight:700;' .
                'padding:1px 6px;border-radius:10px;margin-left:4px;white-space:nowrap;" ' .
                'title="Learner has a Continue & Review session open — this is their prior score, shown until they resubmit.">🔄 Review Pending</span>';
        }

        $started  = $att->timestart  ? userdate((int)$att->timestart,  '%d %b %y %H:%M') : '—';
        $finished = $att->timefinish ? userdate((int)$att->timefinish, '%d %b %y %H:%M') : '—';
        $taken    = ($att->timestart && $att->timefinish)
                  ? edg_format_duration(max(0, (int)$att->timefinish - (int)$att->timestart)) : '—';

        $html .= '<div class="edg-student-row" data-timefinish="' . (int)$att->timefinish . '">';
        $html .= '<div class="edg-row-top">';
        // Bulk-select checkbox — void/delete act on finished/suspended/voided attempts
        // only, matching the single-attempt actions; not shown for a live inprogress
        // session (still being taken, or a Continue & Review pending resubmission).
        if ($isManager && in_array($att->state, ['finished', 'suspended', 'voided'], true)) {
            $html .= '<input type="checkbox" class="edg-bulk-chk" data-attid="' . $attId
                   . '" style="flex-shrink:0;width:16px;height:16px;margin-right:8px;align-self:center;cursor:pointer;">';
        }
        // Avatar + name/email.
        $html .= '<div class="edg-stinfo">';
        $html .= '<div class="edg-avatar">' . $initials . '</div>';
        $html .= '<div>';
        $html .= '<div class="edg-stname">' . s($displayName) . '</div>';
        if (!$anonymous) { $html .= '<div class="edg-stemail">' . s($s['email']) . '</div>'; }
        // View All Attempts link reveals real student name — hide in anonymous mode.
        if (!$anonymous) {
            $html .= '<div style="margin-top:4px;"><a href="' . $reportUrl . '" target="_blank" '
                   . 'style="font-size:11px;color:#0f6cbf;text-decoration:none;">📄 View All Attempts</a></div>';
        }
        $html .= '</div>';
        $html .= '</div>'; // /edg-stinfo
        // Attempt number badge.
        $badgeStyle = $att->state === 'voided'
            ? 'background:#6c757d;color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;white-space:nowrap;text-decoration:line-through;'
            : 'background:#0f6cbf;color:#fff;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:700;white-space:nowrap;';
        $html .= '<div style="flex-shrink:0;padding:0 12px;"><span style="' . $badgeStyle . '">#' . ($i + 1) . '</span></div>';
        // Grade for this attempt — with optional Pass/Fail badge.
        $pfHtml = '';
        if ($passMark > 0 && edg_effectively_finished($att) && $attSumG !== null && $actGrade > 0) {
            $attPct  = round((float)$attSumG / $actGrade * 100, 1);
            $pfHtml  = $attPct >= $passMark
                ? '<span style="background:#28a745;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px;">✓ PASS</span>'
                : '<span style="background:#dc3545;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px;margin-left:4px;">✗ FAIL</span>';
        }
        $html .= '<div class="edg-grade-section"><div class="edg-grade-label">Grade</div>'
               . '<div class="edg-grade-val">' . $gradeDisplay . $pfHtml . '</div></div>';
        // Meta: started/completed/time + action buttons.
        $html .= '<div class="edg-att-area"><div class="edg-att-meta">';
        $html .= '<span>Started: <strong>' . $started . '</strong></span>';
        $html .= '<span>Completed: <strong>' . $finished . '</strong></span>';
        $html .= '<span>Time taken: <strong>' . $taken . '</strong></span>';
        if ($isManager && $att->state === 'finished') {
            $html .= '<button class="btn btn-sm btn-outline-warning" style="font-size:11px;"'
                   . ' onclick="edgVoidAttempt(' . $attId . ',this)">🔄 Reattempt</button>';
        }
        if ($att->state === 'voided') {
            $html .= '<span class="badge badge-secondary" style="font-size:11px;">⛔ Voided</span>';
        }
        $html .= '</div></div>'; // /edg-att-meta /edg-att-area
        $html .= '</div>'; // /edg-row-top

        // Question pills for this attempt.
        $aid           = $attId;
        $attEssaySlots = array_values($perAttemptSlotData[$aid] ?? []);
        $html .= '<div class="edg-row-bottom edg-q-pills" data-attempt="' . $aid . '" data-user="' . $uid . '">';
        foreach ($attEssaySlots as $qi => $slot) {
            $status = $slotStatusMap[$aid][(int)$slot->slotid] ?? 'na';
            $score  = $slotScoreMap[$aid][(int)$slot->slotid] ?? null;
            if ($status === 'graded') {
                if ($score === null) { $cls = 'full'; }
                elseif ($score >= 0.9999) { $cls = 'full'; }
                elseif ($score > 0.0) { $cls = 'partial'; }
                else { $cls = 'zero'; }
                $label  = 'Q' . ($qi+1) . ' ✓';
            } elseif ($status === 'rg') {
                $cls = 'rg';
                $label = 'Q' . ($qi+1) . ' RG';
            } else {
                $cls = 'na';
                $label = 'Q' . ($qi+1) . ' NA';
            }
            // For essay slots: show last grader name as a tooltip and subtitle line.
            $graderAttr    = '';
            $graderSubline = '';
            if ($slot->qtype === 'essay' || $slot->qtype === 'choiceessay') {
                $lastGraderName = $lastGraderMap[$aid][(int)$slot->slotid] ?? '';
                if ($lastGraderName !== '') {
                    $graderAttr    = ' title="Last graded by: ' . s($lastGraderName) . '"';
                    $graderSubline = '<span style="font-size:9px;font-weight:400;opacity:.85;display:block;line-height:1.3;max-width:80px;overflow:hidden;white-space:nowrap;text-overflow:ellipsis;">'
                                   . s($lastGraderName) . '</span>';
                }
            }
            $html  .= '<span class="edg-qpill ' . $cls . '"'
                    . $graderAttr
                    . ' data-attempt="' . $aid . '" data-slotid="' . (int)$slot->slotid . '"'
                    . ' onclick="edgOpenQuestion(' . $aid . ',' . (int)$slot->slotid . ',' . $qi . ')">'
                    . s($label) . $graderSubline . '</span>';
        }
        if (empty($attEssaySlots)) {
            $html .= '<em style="color:#6c757d;font-size:12px;padding:4px 0;">No questions in this attempt.</em>';
        }
        $html .= '</div>'; // /edg-row-bottom (edg-q-pills)
        $html .= '</div>'; // /edg-student-row
    } // end foreach attempt
    $html .= '</div>'; // /edg-student-block
    return $html;
}
