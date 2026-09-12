<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Manual essay grading interface for mod_learnguard.
 *
 * Lists all essay responses that need grading, lets teachers enter
 * a score and feedback, and writes the result back to learnguard_responses.
 *
 * URL: /mod/learnguard/gradeessays.php?id={cmid}
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/gradelib.php');

$id        = required_param('id', PARAM_INT);
$attemptid = optional_param('attempt', 0, PARAM_INT);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:grade', $context);
$isManager = has_capability('mod/learnguard:manage', $context);

// Grading-unit restriction (see grader.php) — this page is a separate, independently
// linked grading surface (from admin.php, attemptdetail.php, proctordashboard.php), so
// a restricted grader must be blocked here too, not just on grader.php, or the whole
// restriction is trivially bypassed by using this page instead. Keyed by userid,
// managers never restricted, unitid=0 (unassigned) always visible to everyone.
$myUnitRestrictions = [];
if (!$isManager) {
    try {
        $myUnitRestrictions = array_map('intval', $DB->get_fieldset_select(
            'learnguard_grader_units', 'unitid', 'userid = :uid', ['uid' => $USER->id]));
    } catch (Exception $e) { $myUnitRestrictions = []; }
}

// Resolves the Grading Unit for one response row — a plain question's unit lives on
// q.unitid; a Choice Essay's unit can only be known from the prompt the student chose.
// Returns 0 (unrestricted) for anything that can't be resolved.
function ge_resolve_unit(string $qtype, int $slotUnitId, ?string $responsedata, int $slotnum): int {
    global $DB;
    if ($qtype !== 'choiceessay') { return $slotUnitId; }
    $decoded = $responsedata ? (json_decode($responsedata, true) ?? []) : [];
    $raw     = $decoded["q{$slotnum}_answer"] ?? '';
    $ceDec   = is_string($raw) ? (json_decode($raw, true) ?? []) : [];
    $promptid = is_array($ceDec) ? (int)($ceDec['promptid'] ?? 0) : 0;
    if ($promptid <= 0) { return 0; }
    return (int)($DB->get_field('learnguard_ce_prompts', 'unitid', ['id' => $promptid]) ?: 0);
}
function ge_unit_allowed(int $resolvedUnit, array $myUnitRestrictions): bool {
    if (empty($myUnitRestrictions)) { return true; }
    return $resolvedUnit === 0 || in_array($resolvedUnit, $myUnitRestrictions, true);
}

$PAGE->set_url('/mod/learnguard/gradeessays.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name) . ' — Grade Essays');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/learnguard/styles.css');

// ── Handle grade submission ────────────────────────────────────────────────────
if (data_submitted() && confirm_sesskey()) {
    $grades = optional_param_array('grade', [], PARAM_FLOAT);
    $feedbacks = optional_param_array('feedback', [], PARAM_TEXT);
    $maxmark = 1.0;  // Default.

    foreach ($grades as $responseId => $grade) {
        $responseId = (int)$responseId;
        $response   = $DB->get_record('learnguard_responses', ['id' => $responseId]);
        if (!$response) { continue; }

        // Get max mark + qtype/unit for this slot.
        $slotrow = $DB->get_record_sql(
            "SELECT s.id, s.slot, s.maxmark, q.qtype, COALESCE(q.unitid, 0) AS unitid
               FROM {learnguard_slots} s LEFT JOIN {learnguard_qbank} q ON q.id = s.questionid
              WHERE s.id = :sid", ['sid' => $response->slotid]
        );
        $maxmark = $slotrow ? (float)$slotrow->maxmark : 1.0;
        $fraction = ($maxmark > 0) ? min(1.0, max(0.0, $grade / $maxmark)) : 0.0;

        // Defense in depth — reject a grade for a slot outside this grader's restricted
        // unit(s), same as grader.php, in case this form was ever submitted with a
        // responseId that was never actually rendered to them.
        if ($slotrow) {
            $resolvedUnit = ge_resolve_unit((string)$slotrow->qtype, (int)$slotrow->unitid,
                $response->responsedata, (int)$slotrow->slot);
            if (!ge_unit_allowed($resolvedUnit, $myUnitRestrictions)) { continue; }
        }

        // This page has no rubric UI — a single flat grade here would silently overwrite
        // a rubric-graded question's proper per-criterion breakdown with no record of how
        // it was arrived at. Skip Choice Essay rows entirely when this activity has a
        // rubric configured; grader.php is the only place they can be graded.
        if ($slotrow && $slotrow->qtype === 'choiceessay' && !empty($instance->rubric_enabled)) {
            continue;
        }

        $DB->update_record('learnguard_responses', (object)[
            'id'           => $responseId,
            'fraction'     => $fraction,
            'state'        => 'graded',
            'timemodified' => time(),
        ]);

        // Store the manual grade record.
        $existing = $DB->get_record('learnguard_essay_grades',
            ['attemptid' => $response->attemptid, 'slotid' => $response->slotid]);
        if ($existing) {
            $DB->update_record('learnguard_essay_grades', (object)[
                'id'         => $existing->id,
                'graderid'   => $USER->id,
                'grade'      => $grade,
                'feedback'   => $feedbacks[$responseId] ?? '',
                'timegraded' => time(),
            ]);
        } else {
            $DB->insert_record('learnguard_essay_grades', (object)[
                'attemptid'  => $response->attemptid,
                'slotid'     => $response->slotid,
                'graderid'   => $USER->id,
                'grade'      => $grade,
                'feedback'   => $feedbacks[$responseId] ?? '',
                'timegraded' => time(),
            ]);
        }
    }

    // Recalculate and update overall grades for all affected attempts.
    $affectedAttempts = array_unique(array_map(function($rid) use ($DB) {
        $r = $DB->get_record('learnguard_responses', ['id' => (int)$rid]);
        return $r ? (int)$r->attemptid : 0;
    }, array_keys($grades)));

    foreach ($affectedAttempts as $aid) {
        if (!$aid) { continue; }
        $attempt = $DB->get_record('learnguard_attempts', ['id' => $aid]);
        if (!$attempt || $attempt->state !== 'finished') { continue; }
        // Pre-existing gap found while adding Choice Essay support here: this page
        // pushed sumgrades unconditionally, unlike grader.php/scores.php, which only
        // publish a score once every essay/choiceessay slot for the attempt is graded.
        // Without this guard, a score could reach the gradebook while another essay
        // question on the same attempt still sits ungraded.
        if (\mod_learnguard\question_engine::has_ungraded_essays($aid, $cm->instance)) {
            $DB->set_field('learnguard_attempts', 'sumgrades', null, ['id' => $aid]);
            continue;
        }
        $grade = \mod_learnguard\question_engine::calculate_total_grade($aid, $instance);
        $DB->set_field('learnguard_attempts', 'sumgrades', $grade, ['id' => $aid]);
        grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
            $instance->id, 0, [(object)['userid' => $attempt->userid, 'rawgrade' => $grade]]);
    }

    redirect(new moodle_url('/mod/learnguard/gradeessays.php', ['id' => $id]),
        'Grades saved successfully.', null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
$brand = learnguard_brand();

echo learnguard_banner('Grade Essay Responses', format_string($instance->name), '📝');

echo html_writer::link(
    new moodle_url('/mod/learnguard/admin.php', ['id' => $id]),
    '← Back to Admin',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// ── Fetch essay responses needing grading ─────────────────────────────────────
$essaySlots = $DB->get_records_sql(
    "SELECT s.id AS slotid, s.slot, q.questiontext, s.maxmark
       FROM {learnguard_slots} s
       JOIN {learnguard_qbank} q ON q.id = s.questionid
      WHERE s.learnguard = :lgid AND q.qtype IN ('essay', 'choiceessay')
      ORDER BY s.slot",
    ['lgid' => $instance->id]
);

if (empty($essaySlots)) {
    echo html_writer::div('No essay or Choice Essay questions in this assessment.', 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

// Fetch all finished attempts with essay responses.
$whereAttempt = $attemptid > 0 ? 'AND a.id = ' . $attemptid : '';
$responses = $DB->get_records_sql(
    "SELECT r.id AS rid, r.attemptid, r.slotid, r.responsedata, r.fraction, r.state,
            a.userid, u.firstname, u.lastname,
            s.maxmark, s.slot AS slotnum, q.qtype, COALESCE(q.unitid, 0) AS unitid,
            COALESCE(q.word_limit, 0) AS word_limit,
            q.questiontext, q.markingguidance,
            eg.grade AS manualgrade, eg.feedback AS manualfeedback, eg.timegraded,
            gr.firstname AS graderfn, gr.lastname AS graderlname
       FROM {learnguard_responses} r
       JOIN {learnguard_attempts} a     ON a.id = r.attemptid
       JOIN {user} u                    ON u.id = a.userid
       JOIN {learnguard_slots} s        ON s.id = r.slotid
       JOIN {learnguard_qbank} q        ON q.id = s.questionid
  LEFT JOIN {learnguard_essay_grades} eg ON eg.attemptid = r.attemptid AND eg.slotid = r.slotid
  LEFT JOIN {user} gr                  ON gr.id = eg.graderid
      WHERE a.learnguard = :lgid AND q.qtype IN ('essay', 'choiceessay') AND a.state = 'finished' $whereAttempt
      ORDER BY a.userid, s.slot",
    ['lgid' => $instance->id]
);

// Grading-unit restriction: drop rows outside this grader's allowed unit(s) before
// deciding whether there's anything left to show, so a fully-restricted view correctly
// reports "nothing to grade" rather than an empty table.
if (!empty($myUnitRestrictions)) {
    $responses = array_filter($responses, function($r) use ($myUnitRestrictions) {
        $resolvedUnit = ge_resolve_unit((string)$r->qtype, (int)$r->unitid, $r->responsedata, (int)$r->slotnum);
        return ge_unit_allowed($resolvedUnit, $myUnitRestrictions);
    });
}

if (empty($responses)) {
    echo html_writer::div('No finished essay/Choice Essay attempts to grade.', 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$currentStudent = null;
foreach ($responses as $r) {
    $studentName = fullname($r);
    if ($currentStudent !== $r->userid) {
        if ($currentStudent !== null) { echo html_writer::end_div(); }
        $currentStudent = $r->userid;
        echo html_writer::start_div('', ['style' =>
            'border:1px solid #dee2e6;border-radius:10px;margin-bottom:20px;overflow:hidden;']);
        echo html_writer::start_div('', ['style' =>
            'background:' . $brand->primary . ';color:#fff;padding:12px 18px;' .
            'display:flex;justify-content:space-between;align-items:center;']);
        echo html_writer::tag('strong', '👤 ' . $studentName);
        echo html_writer::end_div();
    }

    $decoded  = json_decode($r->responsedata, true) ?? [];
    $answer   = $decoded["q{$r->slotnum}_answer"] ?? '(no response)';
    $chosenPromptHtml = '';
    $wordLimitHtml = '';
    if ($r->qtype === 'choiceessay') {
        // The raw value here is the whole {promptid,text,shown} JSON blob, not just the
        // written text — decode it and show which prompt was chosen, same as grader.php.
        $ceDec  = is_string($answer) ? (json_decode($answer, true) ?? []) : [];
        $answer = (string)($ceDec['text'] ?? '');
        $ceProm = (int)($ceDec['promptid'] ?? 0);
        if ($ceProm > 0) {
            $promptRow = $DB->get_record('learnguard_ce_prompts', ['id' => $ceProm],
                'prompttext, prompttextformat', IGNORE_MISSING);
            if ($promptRow) {
                $chosenPromptHtml = '<div style="background:#332900;border:1px solid #664d00;' .
                    'border-radius:6px;padding:8px 10px;margin-bottom:8px;font-size:13px;color:#856404;">' .
                    '<strong>Prompt chosen by student:</strong> ' .
                    format_text($promptRow->prompttext, $promptRow->prompttextformat ?? FORMAT_HTML) . '</div>';
            }
        }
        $ceWordCount = \mod_learnguard\question_engine::count_words((string)$answer);
        $ceWordLimit = (int)$r->word_limit;
        if ($ceWordLimit > 0 && $ceWordCount > $ceWordLimit) {
            $wordLimitHtml = '<div style="background:#f8d7da;border:1px solid #dc3545;border-radius:6px;' .
                'padding:8px 10px;margin-bottom:8px;font-size:13px;color:#842029;font-weight:600;">' .
                '⚠️ Exceeds the ' . $ceWordLimit . '-word limit — submitted ' . $ceWordCount . ' words.</div>';
        }
    }
    $maxmark  = (float)$r->maxmark;
    $curGrade = $r->manualgrade !== null ? (float)$r->manualgrade : round($r->fraction * $maxmark, 2);
    $isGraded = ($r->timegraded > 0);

    echo html_writer::start_div('', ['style' => 'padding:16px 18px;border-bottom:1px solid #f0f0f0;']);

    // Question.
    echo html_writer::tag('div',
        'Q' . $r->slotnum . ': ' . format_text($r->questiontext, FORMAT_HTML),
        ['style' => 'font-weight:600;margin-bottom:8px;color:#495057;']);

    // Marking guide (grader-only).
    if (!empty($r->markingguidance)) {
        echo html_writer::tag('div',
            '<strong>📋 Marking Guide:</strong> ' . format_text($r->markingguidance, FORMAT_HTML),
            ['style' => 'background:#fff8e1;border:1px solid #ffe082;border-radius:6px;' .
                'padding:10px 12px;font-size:13px;color:#6d5300;margin-bottom:12px;']);
    }

    // Response.
    echo $chosenPromptHtml;
    echo $wordLimitHtml;
    echo html_writer::start_div('', ['style' =>
        'background:#f8f9fa;border-left:4px solid ' . ($isGraded ? '#28a745' : '#ffc107') . ';' .
        'padding:12px;border-radius:0 6px 6px 0;margin-bottom:12px;font-size:14px;line-height:1.6;']);
    // Handles both an OLD plain-text answer (escaped, newline-to-<br>) and a NEW rich-text
    // HTML answer from the essay editor's formatting toolbar (rendered as real formatting)
    // — a bare nl2br(s()) here would show literal tag text for any newly-formatted answer.
    echo !\mod_learnguard\question_engine::is_essay_text_blank((string)$answer)
        ? \mod_learnguard\question_engine::render_essay_answer_html((string)$answer)
        : '<em style="color:#6c757d;">No response submitted.</em>';
    echo html_writer::end_div();

    $rubricGuarded = ($r->qtype === 'choiceessay' && !empty($instance->rubric_enabled));
    if ($rubricGuarded) {
        // This activity grades Choice Essay against a rubric — a flat score box here
        // would silently bypass the per-criterion breakdown grader.php enforces, so this
        // page only shows a pointer instead of an input, and posts nothing for this row.
        echo html_writer::div(
            '📋 This assessment uses a scoring rubric — grade this Choice Essay question ' .
            'via the <a href="' . new moodle_url('/mod/learnguard/grader.php', ['id' => $id]) . '">Grader interface</a>.' .
            ($isGraded ? ' Currently scored ' . $curGrade . '/' . $maxmark . '.' : ''),
            'alert alert-info', ['style' => 'font-size:13px;margin-bottom:0;']
        );
    } else {
        // Grade input.
        echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:12px;flex-wrap:wrap;']);
        echo html_writer::tag('label', 'Score (out of ' . $maxmark . '):', ['style' => 'font-weight:600;margin:0;']);
        echo html_writer::empty_tag('input', [
            'type' => 'number', 'name' => 'grade[' . $r->rid . ']',
            'value' => $curGrade, 'min' => '0', 'max' => $maxmark, 'step' => 'any',
            'class' => 'form-control', 'style' => 'width:100px;',
        ]);

        if ($isGraded) {
            echo html_writer::tag('span',
                '✅ Graded by ' . fullname((object)['firstname' => $r->graderfn, 'lastname' => $r->graderlname]) .
                ' on ' . userdate($r->timegraded, '%d/%m/%y %H:%M'),
                ['style' => 'font-size:12px;color:#28a745;']);
        }
        echo html_writer::end_div();

        // Feedback.
        echo html_writer::tag('label', 'Feedback for student:', ['style' => 'font-weight:600;display:block;margin-top:8px;margin-bottom:4px;font-size:13px;']);
        echo html_writer::tag('textarea',
            s($r->manualfeedback ?? ''),
            ['name' => 'feedback[' . $r->rid . ']', 'rows' => '2',
             'class' => 'form-control', 'style' => 'font-size:13px;',
             'placeholder' => 'Optional feedback for the student…']
        );
    }

    echo html_writer::end_div();
}
if ($currentStudent !== null) { echo html_writer::end_div(); }

echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => '💾 Save All Grades',
    'class' => 'btn btn-primary btn-lg mt-3',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
