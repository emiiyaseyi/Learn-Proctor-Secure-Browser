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
 * Per-attempt proctoring detail report — Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use quizaccess_learnshield\suspicion_scorer;
use quizaccess_learnshield\event_tracker;
use quizaccess_learnshield\incident_manager;

$courseid = required_param('courseid', PARAM_INT);
$quizid   = required_param('quizid',   PARAM_INT); // course_module id
$userid   = required_param('userid',   PARAM_INT);

// Proper page setup — MUST use require_login($course, false, $cm) to avoid set_cm mismatch.
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$cm     = get_coursemodule_from_id('quiz', $quizid, $courseid, false, MUST_EXIST);
$quiz   = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
$user   = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('quizaccess/learnshield:viewreport', $context);

// Fetch session.
$session = $DB->get_record('quizaccess_learnshield_sessions', [
    'courseid' => $courseid, 'quizid' => $quizid, 'userid' => $userid,
]);

// Fetch all snapshot logs (webcam + screen, separated by awsflag).
$allLogs = $DB->get_records_select('quizaccess_learnshield_logs',
    'courseid = :cid AND quizid = :qid AND userid = :uid AND webcampicture != :empty',
    ['cid' => $courseid, 'qid' => $quizid, 'uid' => $userid, 'empty' => ''],
    'timemodified ASC');
// Keep $logs as all records for legacy code, but split for display.
$logs        = $allLogs;
$webcamLogs  = array_filter($allLogs, fn($l) => (int)$l->awsflag !== 4);
$screenLogs  = array_filter($allLogs, fn($l) => (int)$l->awsflag === 4);

// Fetch events / audit.
$events = $session ? event_tracker::get_session_events($session->id) : [];

// Fetch violations.
$violations = $session
    ? $DB->get_records('quizaccess_learnshield_violations', ['sessionid' => $session->id], 'timemodified ASC')
    : [];

// Fetch incidents for this user.
$allincidents = incident_manager::get_incidents($courseid, $quizid);
$incidents    = array_filter($allincidents, fn($i) => $i->userid == $userid);

// Face-match result from logs.
$facematchresult = null;
foreach ($logs as $lr) {
    if ($lr->awsflag == 2) {
        $facematchresult = $lr->awsscore >= 68 ? 'pass' : 'fail';
        break;
    }
}

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/attemptdetail.php', [
    'courseid' => $courseid, 'quizid' => $quizid, 'userid' => $userid,
]));
$PAGE->set_heading($course->fullname);
$PAGE->set_title('Attempt Report — ' . fullname($user));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');
$PAGE->requires->js('/mod/quiz/accessrule/learnshield/amd/build/lightbox2.min.js', true);

$attemptsurl = new moodle_url('/mod/quiz/accessrule/learnshield/attempts.php',
    ['courseid' => $courseid, 'cmid' => $quizid]);

echo $OUTPUT->header();

// Breadcrumb nav.
echo html_writer::start_div('d-flex align-items-center gap-3 mb-3 flex-wrap');
echo html_writer::link($attemptsurl,
    html_writer::tag('i', '', ['class' => 'fa fa-arrow-left mr-1']) . 'All Attempts',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
echo html_writer::tag('h3', 'Attempt Report: ' . s(fullname($user)), ['class' => 'mb-0']);
echo html_writer::end_div();

// ---- Section 1: Summary ----
$riskscore = $session ? $session->risk_score : 0;
$risklevel = $session ? $session->risk_level : 'low';
$riskclass = suspicion_scorer::risk_class($risklevel);

// Integrity score badge (prominent).
echo html_writer::start_div('alert d-flex align-items-center gap-3 mb-4 ' .
    ($risklevel === 'high' ? 'alert-danger' : ($risklevel === 'medium' ? 'alert-warning' : 'alert-success')));
echo html_writer::tag('span', $riskscore . ' pts', [
    'class' => "learnshield-risk-badge {$riskclass}",
    'style' => 'font-size:24px;padding:8px 18px;',
]);
echo html_writer::start_div();
echo html_writer::tag('strong', 'Integrity Risk Score: ' . ucfirst($risklevel) . ' Risk');
echo html_writer::tag('div',
    ($risklevel === 'high'   ? 'This attempt shows a high level of suspicious activity.' :
    ($risklevel === 'medium' ? 'Moderate suspicious activity was detected.' :
                               'No significant violations detected.')),
    ['class' => 'small mt-1']
);
echo html_writer::end_div();
echo html_writer::end_div();

// Student / session info table.
echo html_writer::start_div('row mb-4');
echo html_writer::start_div('col-md-6');
echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered']);
echo html_writer::tag('tr', html_writer::tag('th', 'Student')  . html_writer::tag('td', s(fullname($user))));
echo html_writer::tag('tr', html_writer::tag('th', 'Email')    . html_writer::tag('td', s($user->email)));
echo html_writer::tag('tr', html_writer::tag('th', 'Quiz')     . html_writer::tag('td', s($quiz->name)));
echo html_writer::tag('tr', html_writer::tag('th', 'Course')   . html_writer::tag('td', s($course->fullname)));
if ($session) {
    echo html_writer::tag('tr', html_writer::tag('th', 'Started') .
        html_writer::tag('td', userdate($session->start_time)));
    echo html_writer::tag('tr', html_writer::tag('th', 'IP Address') .
        html_writer::tag('td', s($session->ip_address)));
    echo html_writer::tag('tr', html_writer::tag('th', 'Browser') .
        html_writer::tag('td', html_writer::tag('small', s($session->browser_info))));
    echo html_writer::tag('tr', html_writer::tag('th', 'Consent') .
        html_writer::tag('td', $session->consent_given ? '✓ Yes' : '✗ No'));
}
if ($facematchresult !== null) {
    echo html_writer::tag('tr', html_writer::tag('th', 'Face Match') .
        html_writer::tag('td', $facematchresult === 'pass'
            ? '<span class="badge badge-success">✓ Passed</span>'
            : '<span class="badge badge-danger">✗ Failed</span>'));
}
echo html_writer::end_tag('table');
echo html_writer::end_div();

// Stats column.
echo html_writer::start_div('col-md-6');
echo html_writer::start_div('card h-100');
echo html_writer::start_div('card-body text-center');
echo html_writer::div('Integrity Breakdown', 'font-weight-bold mb-3');

echo html_writer::start_div('row');
foreach ([
    ['Webcam Snaps',    count($webcamLogs)],
    ['Screen Caps',     count($screenLogs)],
    ['Tab Switches',    $session ? $session->tab_switches       : 0],
    ['FS Exits',        $session ? $session->fullscreen_exits   : 0],
    ['Face Missing',    $session ? $session->face_missing_count : 0],
    ['Keyboard Viol.',  $session ? $session->keyboard_violations: 0],
    ['Clipboard',       $session ? $session->clipboard_events   : 0],
] as [$label, $val]) {
    echo html_writer::start_div('col-6 mb-2');
    echo html_writer::tag('div', $val, ['style' => 'font-size:1.5rem;font-weight:700;']);
    echo html_writer::tag('small', $label, ['class' => 'text-muted']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

$scorebarhtml = html_writer::div(
    html_writer::div('', "learnshield-score-bar {$risklevel}",
        ['style' => 'width:' . min($riskscore, 100) . '%;height:100%;']),
    'learnshield-score-bar-wrap', ['style' => 'height:12px;margin-top:12px;']
);
echo $scorebarhtml;
echo html_writer::tag('small', 'Risk score: ' . $riskscore . '/100+', ['class' => 'text-muted']);
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// ---- Section 2: Webcam Snapshots ----
echo html_writer::start_div('card mb-4');
echo html_writer::div('📷 Webcam Snapshots (' . count($webcamLogs) . ')', 'card-header font-weight-bold');
echo html_writer::start_div('card-body');
if (empty($webcamLogs)) {
    echo html_writer::tag('p',
        'No webcam snapshots recorded. Webcam snapshots are captured every 15 seconds during active proctored attempts.',
        ['class' => 'text-muted small']);
} else {
    echo html_writer::start_div('learnshield-snapshot-grid');
    foreach ($webcamLogs as $log) {
        echo html_writer::start_div('learnshield-snapshot-item');
        echo html_writer::link($log->webcampicture,
            html_writer::empty_tag('img', ['src' => $log->webcampicture, 'class' => 'img-fluid', 'loading' => 'lazy']),
            ['data-lightbox' => 'webcam-' . $userid]);
        echo html_writer::div(userdate($log->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            'learnshield-snapshot-caption');
        if ($log->awsflag == 2) {
            $fmclass = $log->awsscore >= 68 ? 'badge-success' : 'badge-danger';
            $fmtext  = $log->awsscore >= 68 ? '✓ Match' : '✗ No Match';
            echo html_writer::div(html_writer::span($fmtext, 'badge ' . $fmclass), 'learnshield-snapshot-caption');
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

// ---- Section 2b: Screen Captures ----
echo html_writer::start_div('card mb-4');
echo html_writer::div('🖥 Screen Captures (' . count($screenLogs) . ')', 'card-header font-weight-bold');
echo html_writer::start_div('card-body');
if (empty($screenLogs)) {
    echo html_writer::tag('p',
        'No screen captures recorded. Screen captures are taken every 15 seconds when screen sharing is active.',
        ['class' => 'text-muted small']);
} else {
    echo html_writer::start_div('learnshield-snapshot-grid');
    foreach ($screenLogs as $log) {
        echo html_writer::start_div('learnshield-snapshot-item');
        echo html_writer::link($log->webcampicture,
            html_writer::empty_tag('img', ['src' => $log->webcampicture, 'class' => 'img-fluid', 'loading' => 'lazy']),
            ['data-lightbox' => 'screen-' . $userid]);
        echo html_writer::div(userdate($log->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            'learnshield-snapshot-caption');
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}
echo html_writer::end_div();
echo html_writer::end_div();

// ---- Section 3: Event timeline ----
echo html_writer::start_div('card mb-4');
echo html_writer::div('Event Timeline (' . count($events) . ' events)', 'card-header font-weight-bold');
echo html_writer::start_div('card-body');
if (empty($events)) {
    echo html_writer::tag('p', 'No events recorded.', ['class' => 'text-muted']);
} else {
    $auditcsvurl = new moodle_url('/mod/quiz/accessrule/learnshield/auditlog.php', [
        'courseid' => $courseid, 'cmid' => $quizid,
        'sessionid'=> $session ? $session->id : 0, 'download' => 1,
    ]);
    echo html_writer::link($auditcsvurl,
        html_writer::tag('i', '', ['class' => 'fa fa-download mr-1']) . 'Export CSV',
        ['class' => 'btn btn-sm btn-outline-secondary mb-3']
    );

    echo html_writer::start_tag('ul', ['class' => 'learnshield-timeline']);
    foreach ($events as $ev) {
        $dotclass = match ($ev->severity) {
            'danger'  => 'tl-danger', 'warning' => 'tl-warning', default => 'tl-info',
        };
        $icon = match (true) {
            str_contains($ev->event_type, 'tab')        => 'fa-exchange',
            str_contains($ev->event_type, 'fullscreen') => 'fa-expand',
            str_contains($ev->event_type, 'face')       => 'fa-user',
            str_contains($ev->event_type, 'keyboard')   => 'fa-keyboard-o',
            str_contains($ev->event_type, 'clipboard')  => 'fa-clipboard',
            str_contains($ev->event_type, 'webcam')     => 'fa-camera',
            str_contains($ev->event_type, 'audio')      => 'fa-microphone',
            str_contains($ev->event_type, 'monitor')    => 'fa-desktop',
            default                                     => 'fa-circle',
        };
        echo html_writer::start_tag('li', ['class' => 'learnshield-timeline-item']);
        echo html_writer::div(html_writer::tag('i', '', ['class' => 'fa ' . $icon]), "learnshield-timeline-dot {$dotclass}");
        echo html_writer::start_div('learnshield-timeline-body');
        echo html_writer::div(
            str_replace('_', ' ', ucfirst($ev->event_type)) .
            ($ev->score_impact > 0 ? html_writer::span('+' . $ev->score_impact . ' pts', 'badge badge-danger ml-2') : ''),
            'learnshield-timeline-title'
        );
        if (!empty($ev->event_data) && $ev->event_data !== '{}') {
            $d = json_decode($ev->event_data, true);
            if ($d) {
                echo html_writer::tag('small',
                    s(implode(' | ', array_map(fn($k, $v) => "$k: " . (is_array($v) ? json_encode($v) : $v),
                        array_keys($d), $d))),
                    ['class' => 'text-muted d-block']);
            }
        }
        echo html_writer::end_div();
        echo html_writer::div(userdate($ev->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
            'learnshield-timeline-time');
        echo html_writer::end_tag('li');
    }
    echo html_writer::end_tag('ul');
}
echo html_writer::end_div();
echo html_writer::end_div();

// ---- Section 4: Incidents ----
if (!empty($incidents)) {
    echo html_writer::start_div('card mb-4');
    echo html_writer::div('Incidents (' . count($incidents) . ')', 'card-header font-weight-bold');
    echo html_writer::start_div('card-body');
    foreach ($incidents as $inc) {
        echo html_writer::start_div('learnshield-incident-card severity-' . $inc->severity);
        echo html_writer::div(s($inc->title), 'learnshield-incident-title');
        echo html_writer::div(ucfirst($inc->severity) . ' | ' . ucfirst(str_replace('_', ' ', $inc->status)) .
            ' | ' . userdate($inc->timemodified), 'learnshield-incident-meta');
        if (!empty($inc->resolution_notes)) {
            echo html_writer::div('Resolution: ' . s($inc->resolution_notes), 'text-muted small mt-1');
        }
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
    echo html_writer::end_div();
}

// Footer nav.
echo html_writer::start_div('d-flex gap-2 mt-3 flex-wrap');
echo html_writer::link($attemptsurl,
    html_writer::tag('i', '', ['class' => 'fa fa-list mr-1']) . 'All Attempts', ['class' => 'btn btn-secondary']);
$incidenturl = new moodle_url('/mod/quiz/accessrule/learnshield/incidentreview.php',
    ['courseid' => $courseid, 'cmid' => $quizid]);
echo html_writer::link($incidenturl, 'Manage Incidents', ['class' => 'btn btn-outline-warning']);
echo html_writer::end_div();

echo $OUTPUT->footer();
