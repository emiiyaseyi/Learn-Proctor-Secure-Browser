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
 * Attempts report for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT); // Course module ID.

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:viewreports', $context);

// Proctor: toggle violation scoring for a specific in-progress attempt.
if (optional_param('action', '', PARAM_ALPHANUMEXT) === 'togglevio' && confirm_sesskey()
        && has_capability('mod/learnguard:liveproctor', $context)) {
    $toggleAid = required_param('attemptid', PARAM_INT);
    $toggleAtt = $DB->get_record('learnguard_attempts',
        ['id' => $toggleAid, 'learnguard' => $instance->id], '*', MUST_EXIST);
    $newVal = empty($toggleAtt->violations_suspended) ? 1 : 0;
    $DB->set_field('learnguard_attempts', 'violations_suspended', $newVal, ['id' => $toggleAid]);
    \core\notification::success($newVal ? '⏸ Violation scoring paused.' : '▶ Violation scoring resumed.');
    redirect(new moodle_url('/mod/learnguard/report.php', ['id' => $id]));
}

// Handle reinstate (unsuspend) directly from this report.
if (optional_param('action', '', PARAM_ALPHA) === 'reinstate' && confirm_sesskey()
        && has_capability('mod/learnguard:manage', $context)) {
    $attemptid = required_param('attemptid', PARAM_INT);
    $attempt   = $DB->get_record('learnguard_attempts',
        ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
    if ($attempt->state === 'suspended') {
        $DB->set_field('learnguard_attempts', 'state', 'inprogress', ['id' => $attemptid]);
        $penalty = (int)(get_config('learnguard', 'score_reinstatement') ?: 0);
        if ($penalty > 0) {
            \mod_learnguard\attempt_manager::log_event($attemptid, $attempt->userid, 'reinstatement',
                json_encode(['reason' => 'Attempt reinstated by proctor']), 'warning', $penalty);
        }
        learnguard_notify_student_reinstated($attemptid);
        \core\notification::success('Learner reinstated.');
    } else {
        // Previously fell through here with no message at all if the attempt was no
        // longer 'suspended' (e.g. already reinstated elsewhere) — silent no-op that
        // read as "nothing happened, is this broken."
        \core\notification::warning('This attempt is no longer suspended (state: ' .
            ucfirst($attempt->state) . ') — someone may have already acted on it.');
    }
    redirect(new moodle_url('/mod/learnguard/report.php', ['id' => $id]));
}

// Reinstate with a custom message + selected evidence, mirroring attemptdetail.php's
// "Confirm Suspension (Notify Student)" flow but for the opposite (reinstate) decision.
if (optional_param('action', '', PARAM_ALPHA) === 'reinstatenotify' && confirm_sesskey()
        && has_capability('mod/learnguard:manage', $context)) {
    $attemptid = required_param('attemptid', PARAM_INT);
    $message   = trim(optional_param('message', '', PARAM_TEXT));
    $snapIds   = optional_param_array('snapids', [], PARAM_INT);
    $audioIds  = optional_param_array('audioids', [], PARAM_INT);
    $attempt   = $DB->get_record('learnguard_attempts',
        ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
    if ($attempt->state === 'suspended') {
        $DB->set_field('learnguard_attempts', 'state', 'inprogress', ['id' => $attemptid]);
        $penalty = (int)(get_config('learnguard', 'score_reinstatement') ?: 0);
        if ($penalty > 0) {
            \mod_learnguard\attempt_manager::log_event($attemptid, $attempt->userid, 'reinstatement',
                json_encode(['reason' => 'Attempt reinstated by proctor']), 'warning', $penalty);
        }
        $mailSent = learnguard_notify_student_reinstated($attemptid, $message, $snapIds, $audioIds);
        redirect(new moodle_url('/mod/learnguard/report.php', ['id' => $id]),
            $mailSent
                ? 'Learner reinstated and notified by email.'
                : 'Learner reinstated, but the notification email was not sent — see the Suspension Notices log for details.',
            null,
            $mailSent ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING);
    }
    // Same silent-no-op gap as the plain reinstate action above, for the same reason.
    redirect(new moodle_url('/mod/learnguard/report.php', ['id' => $id]),
        'This attempt is no longer suspended (state: ' . ucfirst($attempt->state) . ') — someone may have already ' .
        'acted on it. No email was sent.', null, \core\output\notification::NOTIFY_WARNING);
}

// AJAX: fetch a suspended attempt's evidence (webcam/screen snapshots + audio clips) so
// the Reinstate & Notify modal below can offer the same tick-to-attach picker attemptdetail.php
// has for Confirm Suspension. Capped so the modal payload stays reasonably sized.
if (optional_param('action', '', PARAM_ALPHA) === 'getevidence' && confirm_sesskey()
        && has_capability('mod/learnguard:manage', $context)) {
    $evAttemptId = required_param('attemptid', PARAM_INT);
    $evAttempt   = $DB->get_record('learnguard_attempts',
        ['id' => $evAttemptId, 'learnguard' => $instance->id], '*', IGNORE_MISSING);
    header('Content-Type: application/json');
    if (!$evAttempt) {
        echo json_encode(['error' => 'not found']);
        exit;
    }
    $snaps = $DB->get_records('learnguard_snapshots', ['attemptid' => $evAttemptId],
        'timemodified DESC', 'id, snapshot_type, timemodified', 0, 40);
    $webcam = [];
    $screen = [];
    foreach ($snaps as $s) {
        $isScreen = $s->snapshot_type === 'screen_capture';
        $item = [
            'id'  => (int)$s->id,
            'url' => (new moodle_url('/mod/learnguard/snapshot.php', ['id' => $s->id, 'sesskey' => sesskey()]))->out(false),
            'time' => userdate($s->timemodified, '%H:%M:%S'),
        ];
        if ($isScreen) { $screen[] = $item; } else { $webcam[] = $item; }
    }
    $audio = [];
    if ($DB->get_manager()->table_exists('learnguard_audio_clips')) {
        $clips = $DB->get_records('learnguard_audio_clips', ['attemptid' => $evAttemptId],
            'timecreated DESC', 'id, noise_level, duration, timecreated', 0, 20);
        foreach ($clips as $c) {
            $audio[] = [
                'id'    => (int)$c->id,
                'time'  => userdate($c->timecreated, '%H:%M:%S'),
                'level' => (int)$c->noise_level,
                'duration' => (int)$c->duration,
            ];
        }
    }
    echo json_encode(['webcam' => $webcam, 'screen' => $screen, 'audio' => $audio]);
    exit;
}

$PAGE->set_url('/mod/learnguard/report.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . format_string($instance->name) . ' — Report');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

// Excel XML export.
$download = optional_param('download', '', PARAM_ALPHA);

if ($download === 'excel') {
    $attempts = $DB->get_records_sql(
        "SELECT a.*, u.firstname, u.lastname, u.email
           FROM {learnguard_attempts} a
           JOIN {user} u ON u.id = a.userid
          WHERE a.learnguard = :lgid
       ORDER BY a.timestart DESC",
        ['lgid' => $instance->id]
    );
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="learnguard_report_' . $instance->id . '.xls"');
    echo '<?xml version="1.0"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ';
    echo 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">';
    echo '<Worksheet ss:Name="Attempts"><Table>';
    $headers = ['Name', 'Email', 'Attempt', 'State', 'Grade', 'Max Grade', 'Risk Score', 'Risk Level', 'Started', 'Duration'];
    echo '<Row>';
    foreach ($headers as $h) {
        echo '<Cell><Data ss:Type="String">' . htmlspecialchars($h) . '</Data></Cell>';
    }
    echo '</Row>';
    foreach ($attempts as $a) {
        $dur = '';
        if (!empty($a->timefinish) && $a->timefinish > $a->timestart) {
            $dur = gmdate('H:i:s', $a->timefinish - $a->timestart);
        } elseif ($a->state === 'inprogress' && $a->timestart > 0) {
            $dur = gmdate('H:i:s', time() - $a->timestart) . ' (live)';
        } elseif ($a->state === 'inprogress') {
            $dur = 'preparing…';
        }
        $row = [
            fullname($a), $a->email, $a->attempt, $a->state,
            isset($a->sumgrades) ? round($a->sumgrades, 2) : '',
            $instance->grade,
            $a->risk_score, $a->risk_level,
            $a->timestart ? userdate($a->timestart) : '',
            $dur,
        ];
        echo '<Row>';
        foreach ($row as $val) {
            $type = is_numeric($val) ? 'Number' : 'String';
            echo '<Cell><Data ss:Type="' . $type . '">' . htmlspecialchars($val) . '</Data></Cell>';
        }
        echo '</Row>';
    }
    echo '</Table></Worksheet></Workbook>';
    exit;
}

if ($download === 'csv') {
    $attempts = $DB->get_records_sql(
        "SELECT a.*, u.firstname, u.lastname, u.email
           FROM {learnguard_attempts} a
           JOIN {user} u ON u.id = a.userid
          WHERE a.learnguard = :lgid
       ORDER BY a.timestart DESC",
        ['lgid' => $instance->id]
    );

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="learnguard_report_' . $instance->id . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Name', 'Email', 'Attempt', 'State', 'Grade', 'Risk Score', 'Risk Level', 'Start Time', 'Finish Time']);
    foreach ($attempts as $a) {
        fputcsv($fh, [
            fullname($a),
            $a->email,
            $a->attempt,
            $a->state,
            isset($a->sumgrades) ? round($a->sumgrades, 2) : '',
            $a->risk_score,
            $a->risk_level,
            $a->timestart ? userdate($a->timestart) : '',
            isset($a->timefinish) && $a->timefinish ? userdate($a->timefinish) : '',
        ]);
    }
    fclose($fh);
    exit;
}

echo $OUTPUT->header();
$brand = learnguard_brand();
echo learnguard_nav_bar($id, 'Attempts Report');
echo learnguard_banner(format_string($instance->name), 'Attempts Report', '📊');

// Summary stats.
$totalattempts = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id]);
$finished      = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'finished']);
$inprogress    = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'inprogress']);
$highriskattempts = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'risk_level' => 'high']);
$suspendedattempts = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'suspended']);

echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
foreach ([
    ['Total Attempts', $totalattempts, $brand->primary],
    ['Completed', $finished, '#28a745'],
    ['In Progress', $inprogress, '#fd7e14'],
    ['High Risk', $highriskattempts, '#dc3545'],
    ['Suspended', $suspendedattempts, '#6f1d1b'],
] as [$label, $count, $colour]) {
    echo html_writer::start_div('', ['style' =>
        'background:' . $colour . ';color:#fff;border-radius:8px;padding:16px 24px;min-width:120px;text-align:center;']);
    echo html_writer::tag('div', $count, ['style' => 'font-size:28px;font-weight:700;']);
    echo html_writer::tag('div', $label, ['style' => 'font-size:13px;opacity:0.85;']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

// Download buttons.
echo html_writer::start_div('mb-3 d-flex gap-2');
echo html_writer::link(
    new moodle_url('/mod/learnguard/report.php', ['id' => $id, 'download' => 'csv']),
    '⬇ CSV',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/report.php', ['id' => $id, 'download' => 'excel']),
    '📊 Excel',
    ['class' => 'btn btn-outline-success btn-sm']
);
echo html_writer::tag('button',
    '🖨 Print Report',
    ['class' => 'btn btn-outline-dark btn-sm', 'onclick' => 'window.print(); return false;']
);
if (has_capability('mod/learnguard:liveproctor', $context)) {
    echo html_writer::link(
        new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $id]),
        '🔴 Live Dashboard',
        ['class' => 'btn btn-danger btn-sm']
    );
}
echo html_writer::end_div();

// Attempts table — includes latest snapshot ID for thumbnail.
$attempts = $DB->get_records_sql(
    "SELECT a.*, u.firstname, u.lastname, u.email,
            (SELECT s.id FROM {learnguard_snapshots} s
              WHERE s.attemptid = a.id
                AND s.snapshot_type NOT IN ('environment_recording','360_video')
              ORDER BY s.timemodified DESC LIMIT 1) AS latest_snap_id
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.learnguard = :lgid
   ORDER BY a.timestart DESC",
    ['lgid' => $instance->id]
);

if (empty($attempts)) {
    echo html_writer::div('No attempts yet.', 'alert alert-info');
} else {
    $riskcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];

    echo html_writer::start_tag('table', ['class' => 'generaltable lg-attempts-table w-100']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    foreach (['Latest Snap', 'Student', 'Attempt #', 'State', 'Grade', 'Risk Score', 'Started', 'Duration', 'Actions'] as $h) {
        echo html_writer::tag('th', $h, ['style' => 'padding:10px 12px;']);
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($attempts as $a) {
        $riskcolour = $riskcolours[$a->risk_level] ?? '#6c757d';

        // Duration.
        $duration = '—';
        if (!empty($a->timefinish) && $a->timefinish > $a->timestart) {
            $secs = $a->timefinish - $a->timestart;
            $duration = gmdate('H:i:s', $secs);
        } elseif ($a->state === 'inprogress' && $a->timestart > 0) {
            $secs = time() - $a->timestart;
            $duration = gmdate('H:i:s', $secs) . ' ⏱';
        } elseif ($a->state === 'inprogress') {
            $duration = 'preparing…';
        }

        $grade = isset($a->sumgrades) ? round($a->sumgrades, 1) . ' / ' . $instance->grade : '—';

        $rowattrs = $a->state === 'suspended' ? ['style' => 'background:#fff5f5;'] : [];
        echo html_writer::start_tag('tr', $rowattrs);
        // Latest snapshot thumbnail.
        $thumbHtml = '<span style="font-size:11px;color:#adb5bd;">No snap</span>';
        if (!empty($a->latest_snap_id)) {
            $snapUrl = (new moodle_url('/mod/learnguard/snapshot.php',
                ['id' => (int)$a->latest_snap_id, 'sesskey' => sesskey()]))->out(false);
            $thumbHtml = '<img src="' . $snapUrl . '" style="width:64px;height:48px;object-fit:cover;' .
                'border-radius:4px;border:1px solid #dee2e6;cursor:zoom-in;" loading="lazy" ' .
                'onclick="window.open(\'' . $snapUrl . '\',\'_blank\')" title="Click to enlarge">';
        }
        echo html_writer::tag('td', $thumbHtml, ['style' => 'text-align:center;width:80px;']);
        echo html_writer::tag('td', html_writer::link(
            new moodle_url('/user/view.php', ['id' => $a->userid, 'course' => $course->id]),
            fullname($a)
        ) . html_writer::tag('div', $a->email, ['style' => 'font-size:12px;color:#6c757d;']));
        echo html_writer::tag('td', '#' . $a->attempt, ['style' => 'text-align:center;']);
        if ($a->state === 'suspended') {
            echo html_writer::tag('td', html_writer::tag('span', '🚫 Suspended', [
                'style' => 'background:#dc3545;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;',
            ]));
        } else {
            echo html_writer::tag('td', ucfirst($a->state));
        }
        echo html_writer::tag('td', $grade);
        echo html_writer::tag('td', html_writer::tag('span', $a->risk_score . ' ' . ucfirst($a->risk_level), [
            'class' => 'lg-badge-risk',
            'style' => 'background:' . $riskcolour . ';',
        ]));
        echo html_writer::tag('td', userdate($a->timestart, get_string('strftimedatetimeshort', 'langconfig')));
        echo html_writer::tag('td', $duration);
        echo html_writer::start_tag('td', ['style' => 'white-space:nowrap;']);
        echo html_writer::link(
            new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $a->id]),
            '🔍 Review',
            ['class' => 'btn btn-sm btn-outline-primary']
        );
        if ($a->state === 'inprogress' && has_capability('mod/learnguard:liveproctor', $context)) {
            $vioSus = !empty($a->violations_suspended);
            echo ' ';
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'style' => 'display:inline;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'togglevio']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $a->id]);
            echo html_writer::empty_tag('input', [
                'type' => 'submit',
                'value' => $vioSus ? '▶ Resume Vio.' : '⏸ Pause Vio.',
                'class' => 'btn btn-sm ' . ($vioSus ? 'btn-success' : 'btn-warning'),
                'title' => $vioSus ? 'Resume violation scoring' : 'Pause violation scoring (events still logged, no score impact)',
                'onclick' => 'return confirm("' . ($vioSus ? 'Resume violation scoring?' : 'Pause violation scoring for this learner?') . '");',
            ]);
            echo html_writer::end_tag('form');
        }
        if ($a->state === 'suspended' && has_capability('mod/learnguard:manage', $context)) {
            echo ' ';
            echo html_writer::start_tag('form', ['method' => 'post', 'action' => '', 'style' => 'display:inline;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'reinstate']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $a->id]);
            echo html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => '▶ Reinstate',
                'class' => 'btn btn-sm btn-success', 'title' => 'Reinstate suspended attempt (standard notice, no evidence)',
                'onclick' => 'return confirm("Reinstate this student?");',
            ]);
            echo html_writer::end_tag('form');
            echo ' <button type="button" class="btn btn-sm btn-outline-success" ' .
                 'title="Reinstate with a custom message and selected evidence attached" ' .
                 'onclick="rpOpenReinstate(' . (int)$a->id . ', \'' . addslashes(s(fullname($a))) . '\');">' .
                 '✉ Reinstate & Notify</button>';
        }
        if (has_capability('mod/learnguard:manage', $context)) {
            echo ' ';
            echo html_writer::start_tag('form', ['method' => 'post',
                'action' => new moodle_url('/mod/learnguard/admin.php'), 'style' => 'display:inline;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'deleteattempt']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $a->id]);
            echo html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => '🗑',
                'class' => 'btn btn-sm btn-outline-danger', 'title' => 'Delete attempt',
                'onclick' => 'return confirm("Delete this attempt? The student can re-attempt. Cannot be undone.");',
            ]);
            echo html_writer::end_tag('form');
        }
        echo html_writer::end_tag('td');
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

// Reinstate & Notify modal — mirrors attemptdetail.php's Confirm Suspension modal, but
// for the reinstate decision, with evidence lists fetched via AJAX per-attempt since this
// page lists many attempts at once (unlike attemptdetail.php, which is already scoped to
// a single attempt and can render its evidence inline).
if (has_capability('mod/learnguard:manage', $context)) {
    echo '<div id="rp-reinstate-modal" style="display:none;position:fixed;inset:0;z-index:99999;' .
         'background:rgba(0,0,0,0.6);align-items:center;justify-content:center;font-family:inherit;">' .
         '<div style="background:#fff;border-radius:10px;padding:24px;max-width:560px;width:92%;' .
         'max-height:85vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.4);">' .
         '<h4 style="margin-top:0;">✅ Reinstate <span id="rp-rn-student"></span></h4>' .
         '<p class="text-muted" style="font-size:13px;">Reinstates the attempt and emails the student your ' .
         'message, with any evidence images/audio clips ticked below attached.</p>' .
         '<form method="post" action="report.php" id="rp-reinstate-form">' .
         html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]) .
         html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]) .
         html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'reinstatenotify']) .
         html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'id' => 'rp-rn-attemptid', 'value' => '']) .
         '<div id="rp-rn-snapids"></div>' .
         '<div id="rp-rn-audioids"></div>' .
         '<label style="font-weight:600;font-size:13px;display:block;margin-bottom:6px;">Message (sent to student):</label>' .
         '<textarea name="message" rows="3" style="width:100%;padding:8px;border:1px solid #ced4da;' .
         'border-radius:6px;font-size:14px;margin-bottom:12px;" placeholder="e.g. Your suspension has been reviewed and lifted — please continue your exam."></textarea>' .
         '<div id="rp-rn-evidence" style="font-size:12px;color:#6c757d;margin-bottom:8px;">Loading evidence…</div>' .
         '<div id="rp-rn-evidence-body"></div>' .
         '<div style="display:flex;gap:10px;margin-top:14px;">' .
         '<button type="button" class="btn btn-secondary" style="flex:1;" onclick="document.getElementById(\'rp-reinstate-modal\').style.display=\'none\';">Cancel</button>' .
         '<button type="submit" class="btn btn-success" style="flex:1;">Reinstate &amp; Send Email</button>' .
         '</div></form></div></div>';

    $ajaxUrl = (new moodle_url('/mod/learnguard/report.php',
        ['id' => $id, 'action' => 'getevidence', 'sesskey' => sesskey()]))->out(false);

    echo '<script>
    function rpOpenReinstate(attemptid, studentName) {
        document.getElementById("rp-rn-attemptid").value = attemptid;
        document.getElementById("rp-rn-student").textContent = studentName;
        document.getElementById("rp-rn-snapids").innerHTML = "";
        document.getElementById("rp-rn-audioids").innerHTML = "";
        var evEl = document.getElementById("rp-rn-evidence");
        var bodyEl = document.getElementById("rp-rn-evidence-body");
        evEl.textContent = "Loading evidence…";
        bodyEl.innerHTML = "";
        document.getElementById("rp-reinstate-modal").style.display = "flex";

        fetch(' . json_encode($ajaxUrl) . ' + "&attemptid=" + attemptid)
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (data.error) { evEl.textContent = "Could not load evidence."; return; }
                var html = "";
                function renderGrid(items, chkClass, dataAttr, label) {
                    if (!items.length) { return ""; }
                    var out = "<div style=\"font-size:12px;font-weight:700;color:#495057;margin:10px 0 6px;\">" + label + " (" + items.length + ")</div>";
                    out += "<div style=\"display:grid;grid-template-columns:repeat(auto-fill,minmax(90px,1fr));gap:8px;margin-bottom:6px;\">";
                    items.forEach(function(it) {
                        out += "<div style=\"text-align:center;position:relative;\">" +
                            "<input type=\"checkbox\" class=\"" + chkClass + "\" " + dataAttr + "=\"" + it.id + "\" " +
                            "style=\"position:absolute;top:2px;left:2px;width:16px;height:16px;z-index:2;cursor:pointer;\">" +
                            "<img src=\"" + it.url + "\" loading=\"lazy\" style=\"width:100%;height:64px;object-fit:cover;" +
                            "border-radius:5px;border:1px solid #dee2e6;\" title=\"" + it.time + "\">" +
                            "<div style=\"font-size:10px;color:#6c757d;\">" + it.time + "</div></div>";
                    });
                    out += "</div>";
                    return out;
                }
                html += renderGrid(data.webcam, "rp-snap-chk", "data-snapid", "📷 Webcam");
                html += renderGrid(data.screen, "rp-snap-chk", "data-snapid", "🖥 Screen");
                if (data.audio && data.audio.length) {
                    html += "<div style=\"font-size:12px;font-weight:700;color:#495057;margin:10px 0 6px;\">🎤 Audio Clips (" + data.audio.length + ")</div>";
                    data.audio.forEach(function(c) {
                        html += "<label style=\"display:flex;align-items:center;gap:6px;font-size:12px;margin-bottom:4px;cursor:pointer;\">" +
                            "<input type=\"checkbox\" class=\"rp-audio-chk\" data-clipid=\"" + c.id + "\">" +
                            c.time + " · Level " + c.level + " · " + c.duration + "s</label>";
                    });
                }
                if (!html) { html = "<div style=\"color:#adb5bd;font-size:12px;\">No evidence recorded for this attempt.</div>"; }
                bodyEl.innerHTML = html;
                evEl.textContent = "Tick any evidence to attach, or leave unticked to send just the message.";
            })
            .catch(function() { evEl.textContent = "Could not load evidence."; });
    }
    document.getElementById("rp-reinstate-form").addEventListener("submit", function() {
        var snapTargets = document.getElementById("rp-rn-snapids");
        snapTargets.innerHTML = "";
        document.querySelectorAll(".rp-snap-chk:checked").forEach(function(c) {
            var inp = document.createElement("input");
            inp.type = "hidden"; inp.name = "snapids[]"; inp.value = c.getAttribute("data-snapid");
            snapTargets.appendChild(inp);
        });
        var audioTargets = document.getElementById("rp-rn-audioids");
        audioTargets.innerHTML = "";
        document.querySelectorAll(".rp-audio-chk:checked").forEach(function(c) {
            var inp = document.createElement("input");
            inp.type = "hidden"; inp.name = "audioids[]"; inp.value = c.getAttribute("data-clipid");
            audioTargets.appendChild(inp);
        });
    });
    </script>';
}

echo $OUTPUT->footer();
