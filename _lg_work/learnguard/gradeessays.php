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

        // Get max mark for this slot.
        $slotrow = $DB->get_record('learnguard_slots', ['id' => $response->slotid]);
        $maxmark = $slotrow ? (float)$slotrow->maxmark : 1.0;
        $fraction = ($maxmark > 0) ? min(1.0, max(0.0, $grade / $maxmark)) : 0.0;

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
      WHERE s.learnguard = :lgid AND q.qtype = 'essay'
      ORDER BY s.slot",
    ['lgid' => $instance->id]
);

if (empty($essaySlots)) {
    echo html_writer::div('No essay questions in this assessment.', 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

// Fetch all finished attempts with essay responses.
$whereAttempt = $attemptid > 0 ? 'AND a.id = ' . $attemptid : '';
$responses = $DB->get_records_sql(
    "SELECT r.id AS rid, r.attemptid, r.slotid, r.responsedata, r.fraction, r.state,
            a.userid, u.firstname, u.lastname,
            s.maxmark, s.slot AS slotnum, q.questiontext, q.markingguidance,
            eg.grade AS manualgrade, eg.feedback AS manualfeedback, eg.timegraded,
            gr.firstname AS graderfn, gr.lastname AS graderlname
       FROM {learnguard_responses} r
       JOIN {learnguard_attempts} a     ON a.id = r.attemptid
       JOIN {user} u                    ON u.id = a.userid
       JOIN {learnguard_slots} s        ON s.id = r.slotid
       JOIN {learnguard_qbank} q        ON q.id = s.questionid
  LEFT JOIN {learnguard_essay_grades} eg ON eg.attemptid = r.attemptid AND eg.slotid = r.slotid
  LEFT JOIN {user} gr                  ON gr.id = eg.graderid
      WHERE a.learnguard = :lgid AND q.qtype = 'essay' AND a.state = 'finished' $whereAttempt
      ORDER BY a.userid, s.slot",
    ['lgid' => $instance->id]
);

if (empty($responses)) {
    echo html_writer::div('No finished essay attempts to grade.', 'alert alert-info');
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
    echo html_writer::start_div('', ['style' =>
        'background:#f8f9fa;border-left:4px solid ' . ($isGraded ? '#28a745' : '#ffc107') . ';' .
        'padding:12px;border-radius:0 6px 6px 0;margin-bottom:12px;font-size:14px;line-height:1.6;']);
    // Handles both an OLD plain-text answer (escaped, newline-to-<br>) and a NEW rich-text
    // HTML answer from the essay editor's formatting toolbar (rendered as real formatting)
    // — a bare nl2br(s()) here would show literal tag text for any newly-formatted answer.
    echo \mod_learnguard\question_engine::render_essay_answer_html((string)$answer);
    echo html_writer::end_div();

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

    echo html_writer::end_div();
}
if ($currentStudent !== null) { echo html_writer::end_div(); }

echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => '💾 Save All Grades',
    'class' => 'btn btn-primary btn-lg mt-3',
]);
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
