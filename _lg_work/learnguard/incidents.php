<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Incident management for mod_learnguard.
 *
 * Proctors can create, view, escalate, and resolve incidents.
 *
 * URL: /mod/learnguard/incidents.php?id={cmid}&attempt={attemptid}
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id        = required_param('id', PARAM_INT);
$attemptid = optional_param('attempt', 0, PARAM_INT);
$action    = optional_param('action', '', PARAM_ALPHA);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:liveproctor', $context);

$PAGE->set_url('/mod/learnguard/incidents.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title(format_string($instance->name) . ' — Incidents');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_pagelayout('incourse');
$PAGE->requires->css('/mod/learnguard/styles.css');

// ── Create incident ────────────────────────────────────────────────────────────
if ($action === 'create' && data_submitted() && confirm_sesskey()) {
    $aid      = required_param('attemptid', PARAM_INT);
    $title    = required_param('title',     PARAM_TEXT);
    $desc     = required_param('description', PARAM_TEXT);
    $severity = required_param('severity',  PARAM_ALPHA);

    $DB->insert_record('learnguard_incidents', (object)[
        'learnguard'  => $instance->id,
        'attemptid'   => $aid,
        'proctoriid'  => $USER->id,
        'severity'    => in_array($severity, ['low','medium','high']) ? $severity : 'medium',
        'title'       => clean_param($title, PARAM_TEXT),
        'description' => clean_param($desc,  PARAM_TEXT),
        'status'      => 'open',
        'timecreated' => time(),
        'timeresolved'=> 0,
    ]);
    redirect(new moodle_url('/mod/learnguard/incidents.php', ['id' => $id]),
        'Incident created.', null, \core\output\notification::NOTIFY_SUCCESS);
}

// ── Resolve incident ───────────────────────────────────────────────────────────
if ($action === 'resolve' && data_submitted() && confirm_sesskey()) {
    $iid       = required_param('incidentid', PARAM_INT);
    $resolveNote = optional_param('resolution', '', PARAM_TEXT);
    $incident  = $DB->get_record('learnguard_incidents', ['id' => $iid, 'learnguard' => $instance->id], '*', MUST_EXIST);
    $DB->update_record('learnguard_incidents', (object)[
        'id'           => $iid,
        'status'       => 'resolved',
        'resolution'   => clean_param($resolveNote, PARAM_TEXT),
        'timeresolved' => time(),
    ]);
    redirect(new moodle_url('/mod/learnguard/incidents.php', ['id' => $id]),
        'Incident marked as resolved.', null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
$brand = learnguard_brand();

echo learnguard_banner('Incident Management', format_string($instance->name), '⚠️');

echo html_writer::link(
    new moodle_url('/mod/learnguard/admin.php', ['id' => $id, 'tab' => 'attempts']),
    '← Back to Admin', ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);
echo ' ';
echo html_writer::link(
    new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $id]),
    '🔴 Live Dashboard', ['class' => 'btn btn-outline-danger btn-sm mb-3']
);

// ── Create incident form ───────────────────────────────────────────────────────
$allAttempts = $DB->get_records_sql(
    "SELECT a.id, u.firstname, u.lastname
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.learnguard = :lgid AND a.state IN ('inprogress','finished','suspended')
      ORDER BY u.lastname, u.firstname",
    ['lgid' => $instance->id]
);

echo html_writer::start_div('', ['style' =>
    'border:1px solid #dee2e6;border-radius:10px;padding:20px;margin-bottom:24px;background:#fff;']);
echo html_writer::tag('h5', '➕ Create New Incident',
    ['style' => 'margin-bottom:14px;color:' . $brand->primary . ';']);
echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',   'value' => 'create']);
echo '<div class="row g-3">';

echo '<div class="col-md-4"><label class="fw-bold">Student</label>';
echo html_writer::start_tag('select', ['name' => 'attemptid', 'class' => 'form-control']);
echo html_writer::tag('option', '— Select student —', ['value' => '']);
foreach ($allAttempts as $a) {
    $sel = ($attemptid === (int)$a->id) ? ' selected' : '';
    echo '<option value="' . $a->id . '"' . $sel . '>' . fullname($a) . '</option>';
}
echo html_writer::end_tag('select');
echo '</div>';

echo '<div class="col-md-2"><label class="fw-bold">Severity</label>';
echo html_writer::start_tag('select', ['name' => 'severity', 'class' => 'form-control']);
foreach (['low' => '🟡 Low', 'medium' => '🟠 Medium', 'high' => '🔴 High'] as $v => $l) {
    echo html_writer::tag('option', $l, ['value' => $v]);
}
echo html_writer::end_tag('select');
echo '</div>';

echo '<div class="col-md-6"><label class="fw-bold">Title</label>';
echo html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title', 'class' => 'form-control',
    'placeholder' => 'e.g. Mobile phone detected', 'required' => 'required']);
echo '</div>';

echo '<div class="col-12"><label class="fw-bold">Description</label>';
echo html_writer::tag('textarea', '', ['name' => 'description', 'class' => 'form-control', 'rows' => '3',
    'placeholder' => 'Describe the incident in detail…']);
echo '</div>';

echo '</div>';
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '⚠️ Create Incident',
    'class' => 'btn btn-danger mt-3']);
echo html_writer::end_tag('form');
echo html_writer::end_div();

// ── Incidents list ─────────────────────────────────────────────────────────────
$whereAttempt = $attemptid > 0 ? 'AND i.attemptid = ' . $attemptid : '';
$incidents = $DB->get_records_sql(
    "SELECT i.*, u.firstname, u.lastname, p.firstname AS pfn, p.lastname AS pln,
            cm.id AS cmid
       FROM {learnguard_incidents} i
       JOIN {learnguard_attempts} a ON a.id = i.attemptid
       JOIN {user} u   ON u.id = a.userid
       JOIN {user} p   ON p.id = i.proctoriid
       JOIN {modules} md ON md.name = 'learnguard'
       JOIN {course_modules} cm ON cm.instance = i.learnguard AND cm.module = md.id
      WHERE i.learnguard = :lgid $whereAttempt
      ORDER BY i.timecreated DESC",
    ['lgid' => $instance->id]
);

$sevColours = ['low' => '#fd7e14', 'medium' => '#dc8400', 'high' => '#dc3545'];
$statusColours = ['open' => '#dc3545', 'resolved' => '#28a745'];

echo html_writer::tag('h5', 'All Incidents (' . count($incidents) . ')', ['class' => 'mb-3']);

if (empty($incidents)) {
    echo html_writer::div('No incidents recorded yet.', 'alert alert-success');
} else {
    foreach ($incidents as $inc) {
        $sc = $sevColours[$inc->severity]   ?? '#6c757d';
        $rc = $statusColours[$inc->status]  ?? '#6c757d';
        echo html_writer::start_div('', ['style' =>
            'border:1px solid #dee2e6;border-radius:8px;margin-bottom:14px;overflow:hidden;']);

        // Header.
        echo '<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 16px;background:#f8f9fa;border-bottom:1px solid #dee2e6;">';
        echo '<div style="display:flex;align-items:center;gap:10px;">';
        echo '<span style="background:' . $sc . ';color:#fff;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;">' . strtoupper($inc->severity) . '</span>';
        echo '<strong>' . s($inc->title) . '</strong>';
        echo '</div>';
        echo '<div style="display:flex;align-items:center;gap:8px;">';
        echo '<span style="background:' . $rc . ';color:#fff;padding:2px 10px;border-radius:10px;font-size:11px;font-weight:700;">' . strtoupper($inc->status) . '</span>';
        echo html_writer::link(
            new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $inc->cmid, 'attempt' => $inc->attemptid]),
            '🔍 View Attempt', ['class' => 'btn btn-sm btn-outline-primary']
        );
        echo '</div></div>';

        // Body.
        echo '<div style="padding:12px 16px;">';
        echo '<p style="margin:0 0 6px;font-size:14px;">' . s($inc->description) . '</p>';
        echo '<p style="margin:0;font-size:12px;color:#6c757d;">Student: <strong>' . fullname($inc) . '</strong> · ';
        echo 'Reported by: ' . fullname((object)['firstname' => $inc->pfn, 'lastname' => $inc->pln]) . ' · ';
        echo userdate($inc->timecreated, '%d/%m/%y %H:%M') . '</p>';

        if ($inc->status === 'open') {
            echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'margin-top:10px;display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',     'value' => 'resolve']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'incidentid', 'value' => $inc->id]);
            echo html_writer::tag('textarea', '', ['name' => 'resolution', 'class' => 'form-control',
                'rows' => '2', 'placeholder' => 'Resolution notes…', 'style' => 'max-width:400px;font-size:13px;']);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '✅ Mark Resolved',
                'class' => 'btn btn-sm btn-success']);
            echo html_writer::end_tag('form');
        } elseif ($inc->resolution) {
            echo '<p style="margin:6px 0 0;font-size:13px;color:#28a745;">✅ Resolution: ' . s($inc->resolution) . '</p>';
        }
        echo '</div></div>';
    }
}

echo $OUTPUT->footer();
