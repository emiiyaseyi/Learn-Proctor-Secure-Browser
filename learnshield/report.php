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

// Fetch records.
$helper = new additional_settings_helper();

if (!empty($search)) {
    $records = $helper->search($search, $search, '', '');
} else {
    $sql = "SELECT e.id as reportid, e.userid as studentid, e.webcampicture, e.status,
                   e.quizid, e.courseid, e.timemodified, e.awsscore, e.awsflag,
                   u.firstname, u.lastname, u.email,
                   c.fullname as coursename, q.name as quizname
              FROM {quizaccess_learnshield_logs} e
             INNER JOIN {user}           u  ON u.id  = e.userid
             INNER JOIN {course}         c  ON c.id  = e.courseid
             INNER JOIN {course_modules} cm ON cm.id = e.quizid
             INNER JOIN {quiz}           q  ON q.id  = cm.instance
             WHERE e.courseid = :courseid AND e.quizid = :quizid AND e.webcampicture != ''
             ORDER BY e.timemodified DESC";
    $records = $DB->get_recordset_sql($sql, ['courseid' => $courseid, 'quizid' => $cmid]);
}

// Group by student.
$students = [];
foreach ($records as $row) {
    $uid = $row->studentid ?? $row->userid;
    if (!isset($students[$uid])) {
        $students[$uid] = ['name' => fullname($row), 'email' => $row->email, 'snapshots' => []];
    }
    if (!empty($row->webcampicture)) {
        $students[$uid]['snapshots'][] = $row;
    }
}
if (is_a($records, 'moodle_recordset')) {
    $records->close();
}

if (empty($students)) {
    echo $OUTPUT->notification(get_string('nodata', 'quizaccess_learnshield'), 'info');
} else {
    foreach ($students as $uid => $student) {
        $detailurl = new moodle_url('/mod/quiz/accessrule/learnshield/attemptdetail.php', [
            'courseid' => $courseid, 'quizid' => $cmid, 'userid' => $uid,
        ]);
        $analyzeurl = new moodle_url('/mod/quiz/accessrule/learnshield/analyzeimage.php', [
            'courseid' => $courseid, 'cmid' => $cmid, 'studentid' => $uid,
        ]);

        echo html_writer::start_div('card mb-4');
        echo html_writer::start_div('card-header d-flex justify-content-between align-items-center flex-wrap gap-2');
        echo html_writer::tag('strong', s($student['name']) . ' &lt;' . s($student['email']) . '&gt;');
        echo html_writer::start_div('d-flex gap-2');
        echo $OUTPUT->single_button($detailurl,  'View Detail Report', 'get');
        echo $OUTPUT->single_button($analyzeurl, get_string('analyzbtn', 'quizaccess_learnshield'), 'get');
        echo html_writer::end_div();
        echo html_writer::end_div();

        echo html_writer::start_div('card-body');
        if (empty($student['snapshots'])) {
            echo html_writer::tag('p', get_string('nodata', 'quizaccess_learnshield'));
        } else {
            echo html_writer::start_div('learnshield-snapshot-grid');
            foreach ($student['snapshots'] as $snap) {
                echo html_writer::start_div('learnshield-snapshot-item');
                echo html_writer::link($snap->webcampicture,
                    html_writer::empty_tag('img', ['src' => $snap->webcampicture, 'class' => 'img-fluid', 'loading' => 'lazy']),
                    ['data-lightbox' => 'student-' . $uid]);
                echo html_writer::div(
                    userdate($snap->timemodified, get_string('strftimedatetimeshort', 'core_langconfig')),
                    'learnshield-snapshot-caption'
                );
                echo html_writer::end_div();
            }
            echo html_writer::end_div();
        }
        echo html_writer::end_div();
        echo html_writer::end_div();
    }
}

echo $OUTPUT->footer();
