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
 * Proctoring report page — Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use quizaccess_learnshield\additional_settings_helper;
use quizaccess_learnshield\suspicion_scorer;
use quizaccess_learnshield\link_generator;

$courseid = required_param('courseid', PARAM_INT);
$cmid     = required_param('cmid',     PARAM_INT);
$search   = optional_param('search',   '', PARAM_TEXT);

// Proper require_login with course + cm to satisfy $PAGE->set_cm requirements.
$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$cm     = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);

require_login($course, false, $cm);

$context = context_module::instance($cm->id);
require_capability('quizaccess/learnshield:viewreport', $context);

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/report.php', [
    'courseid' => $courseid, 'cmid' => $cmid,
]));
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('proctoring_report', 'quizaccess_learnshield'));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');
$PAGE->requires->js('/mod/quiz/accessrule/learnshield/amd/build/lightbox2.min.js', true);

echo $OUTPUT->header();
require_once(__DIR__ . '/navhelper.php');
ls_nav_bar($courseid, $cmid, 'report');
echo $OUTPUT->heading(
    get_string('eprotroringreports', 'quizaccess_learnshield') . format_string($cm->name)
);
echo html_writer::tag('p', get_string('eprotroringreportsdesc', 'quizaccess_learnshield'));

// Navigation bar.
$attemptsurl  = new moodle_url('/mod/quiz/accessrule/learnshield/attempts.php',      ['courseid' => $courseid, 'cmid' => $cmid]);
$dashboardurl = new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php', ['courseid' => $courseid, 'cmid' => $cmid]);
$auditurl     = new moodle_url('/mod/quiz/accessrule/learnshield/auditlog.php',       ['courseid' => $courseid, 'cmid' => $cmid]);
$incidenturl  = new moodle_url('/mod/quiz/accessrule/learnshield/incidentreview.php', ['courseid' => $courseid, 'cmid' => $cmid]);
$summaryurl   = new moodle_url('/mod/quiz/accessrule/learnshield/proctoringsummary.php', ['courseid' => $courseid]);

echo html_writer::start_div('d-flex flex-wrap gap-2 mb-3');
echo $OUTPUT->single_button($attemptsurl,  'All Attempts',  'get');
echo $OUTPUT->single_button($dashboardurl, get_string('live_dashboard', 'quizaccess_learnshield'), 'get');
echo $OUTPUT->single_button($auditurl,     get_string('audit_log',      'quizaccess_learnshield'), 'get');
echo $OUTPUT->single_button($incidenturl,  get_string('incidents',      'quizaccess_learnshield'), 'get');
echo $OUTPUT->single_button($summaryurl,   get_string('course_proctoring_summary', 'quizaccess_learnshield'), 'get');
echo html_writer::end_div();

// Search form.
$searchurl = new moodle_url('/mod/quiz/accessrule/learnshield/report.php', ['courseid' => $courseid, 'cmid' => $cmid]);
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'form-inline mb-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid',     'value' => $cmid]);
echo html_writer::empty_tag('input', ['type' => 'text',   'name' => 'search',   'value' => s($search),
    'class' => 'form-control mr-2', 'placeholder' => get_string('report_search_placeholder', 'quizaccess_learnshield')]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('report_search_submit', 'quizaccess_learnshield'),
    'class' => 'btn btn-primary mr-2']);
if ($search) {
    echo html_writer::link($searchurl, get_string('report_search_clear', 'quizaccess_learnshield'), ['class' => 'btn btn-secondary']);
}
echo html_writer::end_tag('form');

// Fetch snapshot logs for all students.
$sql = "SELECT e.id as reportid, e.userid as studentid, e.webcampicture, e.awsflag,
               e.quizid, e.courseid, e.timemodified, e.awsscore,
               u.firstname, u.lastname, u.email
          FROM {quizaccess_learnshield_logs} e
         INNER JOIN {user} u ON u.id = e.userid
         WHERE e.courseid = :courseid AND e.quizid = :quizid AND e.webcampicture != ''";
$sqlparams = ['courseid' => $courseid, 'quizid' => $cmid];
if (!empty($search)) {
    $sql .= " AND (" . $DB->sql_like('u.firstname', ':s1', false) .
            " OR " . $DB->sql_like('u.lastname', ':s2', false) .
            " OR " . $DB->sql_like('u.email', ':s3', false) . ")";
    $sqlparams['s1'] = '%' . $DB->sql_like_escape($search) . '%';
    $sqlparams['s2'] = '%' . $DB->sql_like_escape($search) . '%';
    $sqlparams['s3'] = '%' . $DB->sql_like_escape($search) . '%';
}
$sql .= " ORDER BY e.timemodified DESC";
$records = $DB->get_recordset_sql($sql, $sqlparams);

// Group by student, separating webcam (awsflag != 4) from screen captures (awsflag = 4).
$students = [];
foreach ($records as $row) {
    $uid = $row->studentid;
    if (!isset($students[$uid])) {
        $students[$uid] = [
            'name'    => fullname($row),
            'email'   => $row->email,
            'webcam'  => [],
            'screen'  => [],
        ];
    }
    if (!empty($row->webcampicture)) {
        if ((int)$row->awsflag === 4) {
            $students[$uid]['screen'][] = $row;
        } else {
            $students[$uid]['webcam'][] = $row;
        }
    }
}
$records->close();

// Fetch integrity scores from sessions table for all students at once.
$sessionScores = [];
if (!empty($students)) {
    list($inSql, $inParams) = $DB->get_in_or_equal(array_keys($students), SQL_PARAMS_NAMED, 'uid');
    $sessSql = "SELECT userid, risk_score, risk_level
                  FROM {quizaccess_learnshield_sessions}
                 WHERE quizid = :quizid AND userid $inSql
                 ORDER BY timemodified DESC";
    $sessRows = $DB->get_recordset_sql($sessSql, array_merge(['quizid' => $cmid], $inParams));
    foreach ($sessRows as $sr) {
        if (!isset($sessionScores[$sr->userid])) {
            $sessionScores[$sr->userid] = ['score' => $sr->risk_score, 'level' => $sr->risk_level];
        }
    }
    $sessRows->close();
}

if (empty($students)) {
    echo html_writer::div(
        '⚠️ No webcam snapshots found for this quiz. ' .
        'Snapshots are saved automatically during proctored attempts once the plugin is correctly installed. ' .
        'If learners have recently taken the exam, ensure the plugin version is v1.4.6+ which fixed the data-saving bug.',
        'alert alert-info'
    );
} else {
    foreach ($students as $uid => $student) {
        $detailurl  = new moodle_url('/mod/quiz/accessrule/learnshield/attemptdetail.php', [
            'courseid' => $courseid, 'quizid' => $cmid, 'userid' => $uid,
        ]);
        $analyzeurl = new moodle_url('/mod/quiz/accessrule/learnshield/analyzeimage.php', [
            'courseid' => $courseid, 'cmid' => $cmid, 'studentid' => $uid,
        ]);

        $sess       = $sessionScores[$uid] ?? null;
        $riskScore  = $sess ? (int)$sess['score'] : 0;
        $riskLevel  = $sess ? $sess['level'] : 'low';
        $riskColour = ['high' => '#dc3545', 'medium' => '#fd7e14', 'low' => '#28a745'][$riskLevel] ?? '#6c757d';
        $snapCount  = count($student['webcam']) + count($student['screen']);

        echo html_writer::start_div('card mb-4 shadow-sm');

        // Card header — name, score badge, action buttons.
        echo html_writer::start_div('card-header d-flex justify-content-between align-items-center flex-wrap gap-2 py-2');
        echo html_writer::start_div('d-flex align-items-center gap-3');
        echo html_writer::tag('strong', s($student['name']) . ' — ' . s($student['email']));
        // Integrity score badge.
        echo html_writer::tag('span',
            'Risk: ' . $riskScore . ' pts — ' . ucfirst($riskLevel),
            ['style' => 'background:' . $riskColour . ';color:#fff;border-radius:12px;padding:3px 12px;font-size:12px;font-weight:700;white-space:nowrap;']
        );
        echo html_writer::tag('span',
            $snapCount . ' snapshot' . ($snapCount !== 1 ? 's' : ''),
            ['style' => 'font-size:12px;color:#6c757d;']
        );
        echo html_writer::end_div();
        echo html_writer::start_div('d-flex gap-2');
        echo html_writer::link($detailurl,
            '📋 View Full Report',
            ['class' => 'btn btn-sm btn-primary']);
        echo html_writer::link($analyzeurl,
            get_string('analyzbtn', 'quizaccess_learnshield'),
            ['class' => 'btn btn-sm btn-outline-secondary']);
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::start_div('card-body');

        // Webcam snapshots.
        if (!empty($student['webcam'])) {
            echo html_writer::tag('h6', '📷 Webcam Snapshots (' . count($student['webcam']) . ')',
                ['style' => 'font-size:13px;font-weight:700;color:#495057;margin-bottom:8px;']);
            echo html_writer::start_div('learnshield-snapshot-grid');
            foreach ($student['webcam'] as $snap) {
                echo html_writer::start_div('learnshield-snapshot-item');
                echo html_writer::link($snap->webcampicture,
                    html_writer::empty_tag('img', ['src' => $snap->webcampicture, 'class' => 'img-fluid', 'loading' => 'lazy']),
                    ['data-lightbox' => 'webcam-' . $uid]);
                echo html_writer::div(
                    userdate($snap->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
                    'learnshield-snapshot-caption'
                );
                echo html_writer::end_div();
            }
            echo html_writer::end_div();
        }

        // Screen captures.
        if (!empty($student['screen'])) {
            echo html_writer::tag('h6', '🖥 Screen Captures (' . count($student['screen']) . ')',
                ['style' => 'font-size:13px;font-weight:700;color:#495057;margin:12px 0 8px;']);
            echo html_writer::start_div('learnshield-snapshot-grid');
            foreach ($student['screen'] as $snap) {
                echo html_writer::start_div('learnshield-snapshot-item');
                echo html_writer::link($snap->webcampicture,
                    html_writer::empty_tag('img', ['src' => $snap->webcampicture, 'class' => 'img-fluid', 'loading' => 'lazy']),
                    ['data-lightbox' => 'screen-' . $uid]);
                echo html_writer::div(
                    userdate($snap->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
                    'learnshield-snapshot-caption'
                );
                echo html_writer::end_div();
            }
            echo html_writer::end_div();
        }

        if (empty($student['webcam']) && empty($student['screen'])) {
            echo html_writer::tag('p',
                'No snapshots recorded yet. Snapshots are captured every 15 seconds during active proctored attempts.',
                ['class' => 'text-muted small']);
        }

        echo html_writer::end_div(); // card-body
        echo html_writer::end_div(); // card
    }
}

echo $OUTPUT->footer();
