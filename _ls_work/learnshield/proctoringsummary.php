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
 * Course proctoring summary page — Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

$courseid = required_param('courseid', PARAM_INT);

require_login();

$course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$context = context_course::instance($courseid);
require_capability('quizaccess/learnshield:viewreport', $context);

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/proctoringsummary.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('course_proctoring_summary', 'quizaccess_learnshield'));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('course_proctoring_summary', 'quizaccess_learnshield'));
echo html_writer::tag('p', get_string('summarypagedesc', 'quizaccess_learnshield'));

// Summarise by quiz.
$sql = "SELECT cm.id as cmid, q.name as quizname,
               COUNT(DISTINCT e.userid) as studentcount,
               COUNT(e.id)             as snapshotcount
          FROM {quizaccess_learnshield_logs} e
         INNER JOIN {course_modules} cm ON cm.id = e.quizid
         INNER JOIN {quiz}           q  ON q.id  = cm.instance
         WHERE e.courseid = :courseid
         GROUP BY cm.id, q.name
         ORDER BY q.name ASC";
$quizzes = $DB->get_records_sql($sql, ['courseid' => $courseid]);

if (empty($quizzes)) {
    echo $OUTPUT->notification(get_string('nodata', 'quizaccess_learnshield'), 'info');
} else {
    echo html_writer::start_tag('table', ['class' => 'table table-bordered']);
    echo html_writer::start_tag('thead');
    echo html_writer::tag('tr',
        html_writer::tag('th', 'Quiz') .
        html_writer::tag('th', 'Students') .
        html_writer::tag('th', 'Snapshots') .
        html_writer::tag('th', 'Actions')
    );
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($quizzes as $q) {
        $reporturl  = new moodle_url('/mod/quiz/accessrule/learnshield/report.php',
            ['courseid' => $courseid, 'cmid' => $q->cmid]);
        $deleteurl  = new moodle_url('/mod/quiz/accessrule/learnshield/bulkdelete.php', [
            'courseid' => $courseid, 'cmid' => $q->cmid, 'sesskey' => sesskey(),
        ]);

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', s($q->quizname));
        echo html_writer::tag('td', $q->studentcount);
        echo html_writer::tag('td', $q->snapshotcount);
        echo html_writer::tag('td',
            html_writer::link($reporturl, get_string('picturesreport', 'quizaccess_learnshield'),
                ['class' => 'btn btn-sm btn-primary mr-1']) .
            html_writer::link($deleteurl, get_string('deletequizdata', 'quizaccess_learnshield'),
                ['class' => 'btn btn-sm btn-danger'])
        );
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo $OUTPUT->footer();
