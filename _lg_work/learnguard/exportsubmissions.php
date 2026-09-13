<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Export a student's submitted question(s) + answer(s) as a plain PDF — no score, no
 * grade, no rubric breakdown, no proctoring data. Per explicit request: this is purely
 * so a student can see what they submitted. A grader can select one or more finished
 * attempts and either email each student their own PDF, or (site admin / global grader)
 * download a single ZIP containing every selected PDF, one file per student, named by
 * that student's email address.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id     = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$cm      = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$lg      = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context   = context_module::instance($cm->id);
$isManager = has_capability('mod/learnguard:manage', $context);
$myGrader  = $DB->get_record('learnguard_graders', ['learnguard' => $cm->instance, 'userid' => $USER->id]);
$isGlobalGrader = false;
if (!$myGrader) {
    try {
        $myGrader = $DB->get_record_sql(
            "SELECT * FROM {learnguard_graders} WHERE userid = :uid AND is_global = 1 ORDER BY id LIMIT 1",
            ['uid' => $USER->id]
        ) ?: null;
        $isGlobalGrader = !empty($myGrader);
    } catch (Exception $e) { $myGrader = null; }
}
$isGrader = $isManager || !empty($myGrader);
if (!$isGrader) {
    throw new moodle_exception('accessdenied', 'error', '', 'You are not assigned as a grader for this assessment.');
}
// Bulk ZIP download is limited to a site admin or a global grader — a per-activity-only
// grader can still email individual students, but downloading every student's raw
// submitted answer in one file is a wider capability than grading a single question.
$canBulkDownload = is_siteadmin() || $isGlobalGrader;

// Only attempts with at least one essay/choiceessay slot have anything to export.
$essayCount = $DB->count_records_sql(
    "SELECT COUNT(*) FROM {learnguard_slots} s JOIN {learnguard_qbank} q ON q.id = s.questionid
      WHERE s.learnguard = ? AND q.qtype IN ('essay', 'choiceessay')", [$cm->instance]
);

if (data_submitted() && confirm_sesskey() && $action) {
    $attemptIds = optional_param_array('attemptids', [], PARAM_INT);
    $attemptIds = array_values(array_unique(array_filter(array_map('intval', $attemptIds))));

    if ($action === 'email') {
        $sent = 0; $failed = 0;
        foreach ($attemptIds as $aid) {
            $att = $DB->get_record('learnguard_attempts', ['id' => $aid, 'learnguard' => $cm->instance]);
            if (!$att || $att->state !== 'finished') { continue; }
            try {
                if (\mod_learnguard\submission_pdf::email_to_student($aid)) { $sent++; } else { $failed++; }
            } catch (Exception $e) { $failed++; }
        }
        redirect(new moodle_url('/mod/learnguard/exportsubmissions.php', ['id' => $id]),
            $sent . ' PDF(s) emailed to students.' . ($failed > 0 ? ' ' . $failed . ' failed.' : ''),
            null, $failed > 0 ? \core\output\notification::NOTIFY_WARNING : \core\output\notification::NOTIFY_SUCCESS);
    }

    if ($action === 'zip') {
        if (!$canBulkDownload) {
            throw new moodle_exception('accessdenied', 'error', '', 'Bulk ZIP download is restricted to a site admin or a global grader.');
        }
        core_php_time_limit::raise(300);
        require_once($CFG->libdir . '/filelib.php');
        $tmpZip = tempnam(sys_get_temp_dir(), 'lgsub') . '.zip';
        $zip = new ZipArchive();
        $zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $count = 0;
        foreach ($attemptIds as $aid) {
            $att = $DB->get_record('learnguard_attempts', ['id' => $aid, 'learnguard' => $cm->instance]);
            if (!$att || $att->state !== 'finished') { continue; }
            $student = $DB->get_record('user', ['id' => $att->userid]);
            if (!$student) { continue; }
            try {
                $pdfContent = \mod_learnguard\submission_pdf::build($aid);
                $zip->addFromString(clean_filename($student->email) . '.pdf', $pdfContent);
                $count++;
            } catch (Exception $e) { /* skip this one, continue with the rest */ }
        }
        $zip->close();
        if ($count === 0) {
            @unlink($tmpZip);
            redirect(new moodle_url('/mod/learnguard/exportsubmissions.php', ['id' => $id]),
                'No attempts selected (or none were finished) — nothing to export.', null,
                \core\output\notification::NOTIFY_WARNING);
        }
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . clean_filename(format_string($lg->name)) . '_submissions.zip"');
        header('Content-Length: ' . filesize($tmpZip));
        readfile($tmpZip);
        @unlink($tmpZip);
        exit;
    }
}

$PAGE->set_url('/mod/learnguard/exportsubmissions.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title('Export Submissions — ' . format_string($lg->name));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo learnguard_nav_bar($id, 'Export Submissions');

echo html_writer::tag('h4', '📄 Export Student Submissions (Question + Answer Only)', ['class' => 'mb-2']);
echo html_writer::tag('p',
    'Generates a plain PDF of the question(s) and the student\'s own written answer(s) for '.
    'essay and Choice Essay questions — no score, grade, or rubric breakdown is included. '.
    'Email it directly to the student, or (site admin / global grader) download a ZIP with '.
    'one PDF per selected student, named by their email address.',
    ['style' => 'color:#6c757d;font-size:13px;']);

if ($essayCount === 0) {
    echo html_writer::div('This assessment has no essay or Choice Essay questions — nothing to export.', 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

$attempts = $DB->get_records_sql(
    "SELECT a.id, a.userid, a.timefinish, u.firstname, u.lastname, u.email
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.learnguard = ? AND a.state = 'finished'
   ORDER BY a.timefinish DESC", [$cm->instance]
);

if (empty($attempts)) {
    echo html_writer::div('No finished attempts yet.', 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

echo html_writer::start_div('d-flex gap-2 align-items-center mb-3');
echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll(\'.esub-chk\').forEach(function(c){c.checked=true;})">☑ Select All</button>';
echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll(\'.esub-chk\').forEach(function(c){c.checked=false;})">☐ Deselect All</button>';
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '📧 Email PDF to Selected',
    'class' => 'btn btn-sm btn-primary', 'formaction' => new moodle_url('/mod/learnguard/exportsubmissions.php', ['id' => $id, 'action' => 'email'])]);
if ($canBulkDownload) {
    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '⬇ Download ZIP of Selected',
        'formaction' => new moodle_url('/mod/learnguard/exportsubmissions.php', ['id' => $id, 'action' => 'zip']),
        'class' => 'btn btn-sm btn-outline-success']);
} else {
    echo html_writer::tag('span', 'ZIP bulk download is restricted to a site admin or a global grader.',
        ['style' => 'font-size:12px;color:#6c757d;']);
}
echo html_writer::end_div();

echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'font-size:13px;']);
echo html_writer::start_tag('tr');
foreach (['', 'Student', 'Email', 'Finished'] as $h) {
    echo html_writer::tag('th', $h);
}
echo html_writer::end_tag('tr');
foreach ($attempts as $a) {
    echo html_writer::start_tag('tr');
    echo html_writer::tag('td', html_writer::empty_tag('input', [
        'type' => 'checkbox', 'name' => 'attemptids[]', 'value' => $a->id, 'class' => 'esub-chk',
    ]));
    echo html_writer::tag('td', s(fullname($a)));
    echo html_writer::tag('td', s($a->email));
    echo html_writer::tag('td', $a->timefinish ? userdate($a->timefinish, '%d %b %y, %H:%M') : '—');
    echo html_writer::end_tag('tr');
}
echo html_writer::end_tag('table');
echo html_writer::end_tag('form');

echo $OUTPUT->footer();
