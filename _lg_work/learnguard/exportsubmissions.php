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

// Save the Complaint/Support Email shown at the top of the page and used in the PDF
// email body — a site-wide value, editable here directly so Michael never has to leave
// this page to change it (also reachable via the normal admin Settings page).
if (data_submitted() && confirm_sesskey() && optional_param('savesupportemail', 0, PARAM_INT) && is_siteadmin()) {
    $newEmail = trim(optional_param('export_support_email', '', PARAM_EMAIL));
    set_config('export_support_email', $newEmail, 'learnguard');
    redirect(new moodle_url('/mod/learnguard/exportsubmissions.php', ['id' => $id]),
        'Complaint/support email updated.', null, \core\output\notification::NOTIFY_SUCCESS);
}

if (data_submitted() && confirm_sesskey() && $action) {
    $attemptIds = optional_param_array('attemptids', [], PARAM_INT);
    $attemptIds = array_values(array_unique(array_filter(array_map('intval', $attemptIds))));

    if ($action === 'email') {
        $sent = 0; $failed = 0;
        foreach ($attemptIds as $aid) {
            $att = $DB->get_record('learnguard_attempts', ['id' => $aid, 'learnguard' => $cm->instance]);
            if (!$att || $att->state !== 'finished') { continue; }
            $logRow = (object)[
                'learnguard'  => $cm->instance,
                'attemptid'   => $aid,
                'userid'      => $att->userid,
                'sentby'      => $USER->id,
                'status'      => 'sent',
                'error'       => null,
                'timecreated' => time(),
            ];
            try {
                if (\mod_learnguard\submission_pdf::email_to_student($aid)) {
                    $sent++;
                } else {
                    $failed++;
                    $logRow->status = 'failed';
                    $logRow->error  = 'email_to_user() returned false';
                }
            } catch (Exception $e) {
                $failed++;
                $logRow->status = 'failed';
                $logRow->error  = $e->getMessage();
            }
            $DB->insert_record('learnguard_submission_exports', $logRow);
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

// Complaint/Support email — shown in the body of every PDF email sent to a student, so
// they know where to send questions about it. Editable right here, site-admin only
// (it's a site-wide value, same as the other notify_* email settings) — also reachable
// from the normal admin Settings page.
$supportEmail = trim((string)get_config('learnguard', 'export_support_email'));
echo html_writer::start_div('alert alert-secondary', ['style' => 'padding:10px 14px;']);
if (is_siteadmin()) {
    echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0;">';
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'savesupportemail', 'value' => '1']);
    echo '<label style="font-size:13px;font-weight:700;margin:0;">✉ Complaint/support email shown to students:</label>';
    echo '<input type="email" name="export_support_email" value="' . s($supportEmail) . '" placeholder="e.g. support@yourorg.com" ' .
        'class="form-control form-control-sm" style="max-width:280px;">';
    echo '<button type="submit" class="btn btn-sm btn-outline-primary">Save</button>';
    echo '</form>';
} else {
    echo '<span style="font-size:13px;">✉ Complaint/support email shown to students: <strong>' .
        ($supportEmail !== '' ? s($supportEmail) : '— not set —') . '</strong></span>';
}
echo html_writer::end_div();

if ($essayCount === 0) {
    echo html_writer::div('This assessment has no essay or Choice Essay questions — nothing to export.', 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

$attempts = $DB->get_records_sql(
    "SELECT a.id, a.userid, a.timefinish, a.sumgrades, u.firstname, u.lastname, u.email
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.learnguard = ? AND a.state = 'finished'
   ORDER BY a.timefinish DESC", [$cm->instance]
);
$actGrade = (float)$lg->grade;

if (empty($attempts)) {
    echo html_writer::div('No finished attempts yet.', 'alert alert-info');
    echo $OUTPUT->footer();
    exit;
}

// Last successful send per attempt — drives the "Last Sent" column and the "Exclude
// already sent" filter below. A failed-only send history (no successful row) is not
// treated as "already sent" — the student never actually received anything that time.
$lastSentMap = [];
$sentRows = $DB->get_records_sql(
    "SELECT attemptid, MAX(timecreated) AS lastsent
       FROM {learnguard_submission_exports}
      WHERE learnguard = ? AND status = 'sent'
   GROUP BY attemptid", [$cm->instance]
);
foreach ($sentRows as $sr) { $lastSentMap[(int)$sr->attemptid] = (int)$sr->lastsent; }

echo html_writer::start_tag('form', ['method' => 'post']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

// Score filter — percentage of this activity's own max grade. An attempt with no score
// yet (essay not graded) is always excluded the moment either bound is set, since "does
// it meet a score threshold" can't be answered for it; it still shows plainly in the
// table (as "— Ungraded") whenever no filter is active.
echo html_writer::start_div('d-flex gap-2 align-items-center mb-2 flex-wrap');
echo '<label style="font-size:13px;margin:0;">Score % from</label>';
echo '<input type="number" id="esub-min" min="0" max="100" step="0.1" class="form-control form-control-sm" style="width:90px;" oninput="esubApplyFilter()">';
echo '<label style="font-size:13px;margin:0;">to</label>';
echo '<input type="number" id="esub-max" min="0" max="100" step="0.1" class="form-control form-control-sm" style="width:90px;" oninput="esubApplyFilter()">';
echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.getElementById(\'esub-min\').value=\'\';document.getElementById(\'esub-max\').value=\'\';esubApplyFilter();">Clear Filter</button>';
echo '<label style="font-size:13px;margin:0 0 0 12px;display:flex;align-items:center;gap:4px;cursor:pointer;">' .
    '<input type="checkbox" id="esub-exclude-sent" onchange="esubApplyFilter()"> Exclude already-sent students</label>';
echo '<span id="esub-filter-count" style="font-size:12px;color:#6c757d;"></span>';
echo html_writer::end_div();

echo html_writer::start_div('d-flex gap-2 align-items-center mb-3');
echo '<button type="button" class="btn btn-sm btn-outline-secondary" onclick="document.querySelectorAll(\'.esub-chk\').forEach(function(c){if(c.closest(\'tr\').style.display!==\'none\'){c.checked=true;}})">☑ Select All Visible</button>';
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

echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'font-size:13px;', 'id' => 'esub-table']);
echo html_writer::start_tag('tr');
foreach (['', 'Student', 'Email', 'Finished'] as $h) {
    echo html_writer::tag('th', $h);
}
echo '<th style="cursor:pointer;user-select:none;" onclick="esubToggleSort()">Score % <span id="esub-sort-arrow">↕</span></th>';
echo html_writer::tag('th', 'Last Sent');
echo html_writer::end_tag('tr');
foreach ($attempts as $a) {
    // null sumgrades = not yet graded; -1 is the filter's own "exclude me" sentinel,
    // never a real percentage, so it can never accidentally match a 0-100 range.
    $pct = ($actGrade > 0 && $a->sumgrades !== null) ? round(((float)$a->sumgrades / $actGrade) * 100, 1) : null;
    $lastSent = $lastSentMap[(int)$a->id] ?? 0;
    echo html_writer::start_tag('tr', ['data-score' => $pct === null ? '-1' : $pct, 'data-sent' => $lastSent > 0 ? '1' : '0']);
    echo html_writer::tag('td', html_writer::empty_tag('input', [
        'type' => 'checkbox', 'name' => 'attemptids[]', 'value' => $a->id, 'class' => 'esub-chk',
    ]));
    echo html_writer::tag('td', s(fullname($a)));
    echo html_writer::tag('td', s($a->email));
    echo html_writer::tag('td', $a->timefinish ? userdate($a->timefinish, '%d %b %y, %H:%M') : '—');
    echo html_writer::tag('td', $pct === null ? '<em style="color:#6c757d;">— Ungraded</em>' : $pct . '%');
    echo html_writer::tag('td', $lastSent > 0
        ? '<span style="color:#28a745;">✓ ' . userdate($lastSent, '%d %b %y, %H:%M') . '</span>'
        : '<em style="color:#6c757d;">— Never sent</em>');
    echo html_writer::end_tag('tr');
}
echo html_writer::end_tag('table');
echo html_writer::end_tag('form');

echo '<script>
var esubSortDir = 0; // 0 = unsorted, 1 = asc, -1 = desc
function esubApplyFilter() {
    var min = document.getElementById("esub-min").value;
    var max = document.getElementById("esub-max").value;
    var hasScoreFilter = (min !== "" || max !== "");
    var excludeSent = document.getElementById("esub-exclude-sent").checked;
    min = min === "" ? -Infinity : parseFloat(min);
    max = max === "" ? Infinity : parseFloat(max);
    var rows = document.querySelectorAll("#esub-table tr[data-score]");
    var shown = 0;
    rows.forEach(function(tr) {
        var score = parseFloat(tr.getAttribute("data-score"));
        var isUngraded = (score === -1);
        var alreadySent = tr.getAttribute("data-sent") === "1";
        var visible = true;
        if (hasScoreFilter) { visible = visible && !isUngraded && score >= min && score <= max; }
        if (excludeSent) { visible = visible && !alreadySent; }
        tr.style.display = visible ? "" : "none";
        if (visible) { shown++; }
    });
    var countEl = document.getElementById("esub-filter-count");
    if (hasScoreFilter || excludeSent) {
        countEl.textContent = shown + " of " + rows.length + " shown" +
            (hasScoreFilter ? " (ungraded attempts excluded from a score filter)" : "");
    } else {
        countEl.textContent = "";
    }
}
function esubToggleSort() {
    esubSortDir = esubSortDir === 1 ? -1 : 1;
    document.getElementById("esub-sort-arrow").textContent = esubSortDir === 1 ? "↑" : "↓";
    var table = document.getElementById("esub-table");
    var rows = Array.prototype.slice.call(table.querySelectorAll("tr[data-score]"));
    rows.sort(function(a, b) {
        var sa = parseFloat(a.getAttribute("data-score"));
        var sb = parseFloat(b.getAttribute("data-score"));
        return esubSortDir * (sa - sb);
    });
    rows.forEach(function(tr) { table.appendChild(tr); });
}
</script>';

// ── Send History — every emailed-PDF attempt for this assessment, newest first. ──
$historyRows = $DB->get_records_sql(
    "SELECT h.id, h.attemptid, h.status, h.error, h.timecreated,
            u.firstname, u.lastname, u.email,
            su.firstname AS sbfirst, su.lastname AS sblast
       FROM {learnguard_submission_exports} h
       JOIN {user} u ON u.id = h.userid
       JOIN {user} su ON su.id = h.sentby
      WHERE h.learnguard = ?
   ORDER BY h.timecreated DESC", [$cm->instance], 0, 200
);
echo '<details style="margin-top:18px;"><summary style="cursor:pointer;font-weight:700;font-size:14px;">📜 Send History (' .
    count($historyRows) . ($historyRows && count($historyRows) >= 200 ? '+' : '') . ')</summary>';
if (empty($historyRows)) {
    echo html_writer::div('No PDFs have been emailed yet for this assessment.', 'alert alert-info', ['style' => 'margin-top:8px;']);
} else {
    echo '<table class="generaltable w-100" style="font-size:12px;margin-top:8px;">';
    echo '<tr><th>Student</th><th>Email</th><th>Sent At</th><th>Status</th><th>Sent By</th><th>Error</th></tr>';
    foreach ($historyRows as $h) {
        echo '<tr>';
        echo html_writer::tag('td', s($h->firstname . ' ' . $h->lastname));
        echo html_writer::tag('td', s($h->email));
        echo html_writer::tag('td', userdate((int)$h->timecreated, '%d %b %y, %H:%M:%S'));
        echo '<td>' . ($h->status === 'sent'
            ? '<span style="color:#28a745;font-weight:700;">✓ Sent</span>'
            : '<span style="color:#dc3545;font-weight:700;">✗ Failed</span>') . '</td>';
        echo html_writer::tag('td', s($h->sbfirst . ' ' . $h->sblast));
        echo html_writer::tag('td', $h->error ? s($h->error) : '');
        echo '</tr>';
    }
    echo '</table>';
}
echo '</details>';

// ── Self-Service Downloads — who has used "Download PDF" on their own course page. ──
$dlRows = $DB->get_records_sql(
    "SELECT d.id, d.timecreated, u.email
       FROM {learnguard_submission_downloads} d
       JOIN {user} u ON u.id = d.userid
      WHERE d.learnguard = ?
   ORDER BY d.timecreated DESC", [$cm->instance], 0, 500
);
echo '<details style="margin-top:12px;"><summary style="cursor:pointer;font-weight:700;font-size:14px;">📥 Self-Service Downloads (' .
    count($dlRows) . ($dlRows && count($dlRows) >= 500 ? '+' : '') . ')</summary>';
if (empty($dlRows)) {
    echo html_writer::div('No learner has used the "Download PDF" link on their own course page yet.', 'alert alert-info', ['style' => 'margin-top:8px;']);
} else {
    echo '<table class="generaltable w-100" style="font-size:12px;margin-top:8px;">';
    echo '<tr><th>Email</th><th>Downloaded At</th></tr>';
    foreach ($dlRows as $d) {
        echo '<tr>' .
            html_writer::tag('td', s($d->email)) .
            html_writer::tag('td', userdate((int)$d->timecreated, '%d %b %y, %H:%M:%S')) .
            '</tr>';
    }
    echo '</table>';
}
echo '</details>';

echo $OUTPUT->footer();
