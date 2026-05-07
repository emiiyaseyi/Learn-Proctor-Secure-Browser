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
 * Incident review page for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

use quizaccess_learnshield\incident_manager;

$courseid   = required_param('courseid', PARAM_INT);
$cmid       = required_param('cmid',     PARAM_INT);
$action     = optional_param('action',   '',  PARAM_ALPHA);
$incidentid = optional_param('iid',      0,   PARAM_INT);
$status     = optional_param('status',   '',  PARAM_ALPHANUMEXT);
$notes      = optional_param('notes',    '',  PARAM_TEXT);

require_login();

$cm      = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
$context = context_module::instance($cm->id);
require_capability('quizaccess/learnshield:manageincidents', $context);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// Handle resolution action.
if ($action === 'resolve' && $incidentid && confirm_sesskey()) {
    require_capability('quizaccess/learnshield:manageincidents', $context);
    incident_manager::resolve($incidentid, $USER->id, $status ?: incident_manager::STATUS_RESOLVED, $notes);
    redirect(new moodle_url('/mod/quiz/accessrule/learnshield/incidentreview.php',
        ['courseid' => $courseid, 'cmid' => $cmid]),
        'Incident updated.', null, \core\output\notification::NOTIFY_SUCCESS
    );
}

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/incidentreview.php', [
    'courseid' => $courseid, 'cmid' => $cmid,
]));
$PAGE->set_context($context);
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('incident_review', 'quizaccess_learnshield'));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('incident_review', 'quizaccess_learnshield'));

$incidents = incident_manager::get_incidents($courseid, $cmid);

if (empty($incidents)) {
    echo $OUTPUT->notification(get_string('no_incidents', 'quizaccess_learnshield'), 'info');
} else {
    foreach ($incidents as $inc) {
        $severityclass = 'severity-' . $inc->severity;

        echo html_writer::start_div("learnshield-incident-card {$severityclass}");
        echo html_writer::div(s($inc->title), 'learnshield-incident-title');
        echo html_writer::div(
            fullname($inc) . ' | ' . userdate($inc->timemodified) .
            ' | Status: <strong>' . s($inc->status) . '</strong>',
            'learnshield-incident-meta'
        );

        if (!empty($inc->description)) {
            echo html_writer::tag('p', s($inc->description), ['class' => 'mt-2']);
        }

        // Resolution buttons.
        if (!in_array($inc->status, [incident_manager::STATUS_RESOLVED, incident_manager::STATUS_CLEARED, incident_manager::STATUS_CONFIRMED])) {
            $baseurl = new moodle_url('/mod/quiz/accessrule/learnshield/incidentreview.php', [
                'courseid' => $courseid, 'cmid' => $cmid,
                'action'   => 'resolve', 'iid' => $inc->id, 'sesskey' => sesskey(),
            ]);
            echo html_writer::start_div('mt-2 d-flex gap-2 flex-wrap');
            echo html_writer::link(
                new moodle_url($baseurl, ['status' => incident_manager::STATUS_RESOLVED]),
                get_string('resolve_incident', 'quizaccess_learnshield'),
                ['class' => 'btn btn-success btn-sm']
            );
            echo html_writer::link(
                new moodle_url($baseurl, ['status' => incident_manager::STATUS_CONFIRMED]),
                get_string('confirm_violation', 'quizaccess_learnshield'),
                ['class' => 'btn btn-danger btn-sm']
            );
            echo html_writer::link(
                new moodle_url($baseurl, ['status' => incident_manager::STATUS_CLEARED]),
                get_string('clear_incident', 'quizaccess_learnshield'),
                ['class' => 'btn btn-secondary btn-sm']
            );
            echo html_writer::end_div();
        }

        echo html_writer::end_div();
    }
}

echo $OUTPUT->footer();
