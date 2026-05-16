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
 * Audit log viewer for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

use quizaccess_learnshield\event_tracker;

$courseid  = required_param('courseid', PARAM_INT);
$cmid      = required_param('cmid',     PARAM_INT);
$sessionid = optional_param('sessionid', 0, PARAM_INT);
$download  = optional_param('download',  0, PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$cm     = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('quizaccess/learnshield:viewauditlog', $context);

if ($sessionid) {
    $events = event_tracker::get_session_events($sessionid);
} else {
    $sql = "SELECT e.*, u.firstname, u.lastname
              FROM {quizaccess_learnshield_events} e
             INNER JOIN {user} u ON u.id = e.userid
             WHERE e.courseid = :courseid AND e.quizid = :quizid
             ORDER BY e.timemodified DESC
             LIMIT 500";
    $events = array_values($DB->get_records_sql($sql, ['courseid' => $courseid, 'quizid' => $cmid]));
}

// CSV download.
if ($download) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="learnshield-auditlog-' . time() . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Time', 'User ID', 'Event Type', 'Severity', 'Score Impact', 'Data']);
    foreach ($events as $ev) {
        fputcsv($out, [
            $ev->id, userdate($ev->timemodified), $ev->userid,
            $ev->event_type, $ev->severity, $ev->score_impact, $ev->event_data,
        ]);
    }
    fclose($out);
    exit;
}

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/auditlog.php', [
    'courseid' => $courseid, 'cmid' => $cmid,
]));
$PAGE->set_context($context);
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('audit_log', 'quizaccess_learnshield'));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('audit_log', 'quizaccess_learnshield'));
echo html_writer::tag('p', get_string('audit_log_desc', 'quizaccess_learnshield'));

// Export button.
$exporturl = new moodle_url('/mod/quiz/accessrule/learnshield/auditlog.php', [
    'courseid' => $courseid, 'cmid' => $cmid, 'download' => 1,
]);
echo $OUTPUT->single_button($exporturl, get_string('export_audit', 'quizaccess_learnshield'), 'get');

echo html_writer::start_div('mt-3');
echo html_writer::start_tag('table', ['class' => 'table table-sm table-bordered learnshield-audit-table']);
echo html_writer::start_tag('thead');
echo html_writer::start_tag('tr');
foreach (['Time', 'User', 'Event Type', 'Severity', 'Score', 'Data'] as $h) {
    echo html_writer::tag('th', $h);
}
echo html_writer::end_tag('tr');
echo html_writer::end_tag('thead');
echo html_writer::start_tag('tbody');

if (empty($events)) {
    echo html_writer::tag('tr',
        html_writer::tag('td', get_string('no_events', 'quizaccess_learnshield'), ['colspan' => 6])
    );
} else {
    foreach ($events as $ev) {
        $severityclass = match ($ev->severity) {
            'danger'  => 'table-danger',
            'warning' => 'table-warning',
            default   => '',
        };
        $username = isset($ev->firstname)
            ? s(fullname($ev))
            : 'User #' . $ev->userid;

        echo html_writer::start_tag('tr', ['class' => $severityclass]);
        echo html_writer::tag('td', userdate($ev->timemodified));
        echo html_writer::tag('td', $username);
        echo html_writer::tag('td', html_writer::span($ev->event_type, 'event-type'));
        echo html_writer::tag('td', ucfirst($ev->severity));
        echo html_writer::tag('td', $ev->score_impact > 0 ? '+' . $ev->score_impact : '0');
        echo html_writer::tag('td', html_writer::tag('small', s($ev->event_data)));
        echo html_writer::end_tag('tr');
    }
}

echo html_writer::end_tag('tbody');
echo html_writer::end_tag('table');
echo html_writer::end_div();

echo $OUTPUT->footer();
