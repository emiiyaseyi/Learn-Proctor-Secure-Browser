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

$PAGE->set_url('/mod/learnguard/report.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title($course->shortname . ': ' . format_string($instance->name) . ' — Report');
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

// Download CSV.
$download = optional_param('download', '', PARAM_ALPHA);
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

// Header card.
echo html_writer::start_div('lg-report-header');
echo html_writer::tag('h2', '📊 ' . format_string($instance->name), ['style' => 'margin:0 0 8px;']);
echo html_writer::tag('p', 'Attempts Report', ['style' => 'margin:0;opacity:0.8;']);
echo html_writer::end_div();

// Summary stats.
$totalattempts = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id]);
$finished      = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'finished']);
$inprogress    = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'inprogress']);
$highriskattempts = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'risk_level' => 'high']);

echo html_writer::start_div('d-flex gap-3 flex-wrap mb-4');
foreach ([
    ['Total Attempts', $totalattempts, '#0f6cbf'],
    ['Completed', $finished, '#28a745'],
    ['In Progress', $inprogress, '#fd7e14'],
    ['High Risk', $highriskattempts, '#dc3545'],
] as [$label, $count, $colour]) {
    echo html_writer::start_div('', ['style' =>
        'background:' . $colour . ';color:#fff;border-radius:8px;padding:16px 24px;min-width:120px;text-align:center;']);
    echo html_writer::tag('div', $count, ['style' => 'font-size:28px;font-weight:700;']);
    echo html_writer::tag('div', $label, ['style' => 'font-size:13px;opacity:0.85;']);
    echo html_writer::end_div();
}
echo html_writer::end_div();

// Download button.
echo html_writer::start_div('mb-3 d-flex gap-2');
echo html_writer::link(
    new moodle_url('/mod/learnguard/report.php', ['id' => $id, 'download' => 'csv']),
    '⬇ Export CSV',
    ['class' => 'btn btn-outline-secondary btn-sm']
);
if (has_capability('mod/learnguard:liveproctor', $context)) {
    echo html_writer::link(
        new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $id]),
        '🔴 Live Dashboard',
        ['class' => 'btn btn-danger btn-sm']
    );
}
echo html_writer::end_div();

// Attempts table.
$attempts = $DB->get_records_sql(
    "SELECT a.*, u.firstname, u.lastname, u.email
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
    foreach (['Student', 'Attempt #', 'State', 'Grade', 'Risk Score', 'Started', 'Duration', 'Actions'] as $h) {
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
        } elseif ($a->state === 'inprogress') {
            $secs = time() - $a->timestart;
            $duration = gmdate('H:i:s', $secs) . ' ⏱';
        }

        $grade = isset($a->sumgrades) ? round($a->sumgrades, 1) . ' / ' . $instance->grade : '—';

        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', html_writer::link(
            new moodle_url('/user/view.php', ['id' => $a->userid, 'course' => $course->id]),
            fullname($a)
        ) . html_writer::tag('div', $a->email, ['style' => 'font-size:12px;color:#6c757d;']));
        echo html_writer::tag('td', '#' . $a->attempt, ['style' => 'text-align:center;']);
        echo html_writer::tag('td', ucfirst($a->state));
        echo html_writer::tag('td', $grade);
        echo html_writer::tag('td', html_writer::tag('span', $a->risk_score . ' ' . ucfirst($a->risk_level), [
            'class' => 'lg-badge-risk',
            'style' => 'background:' . $riskcolour . ';',
        ]));
        echo html_writer::tag('td', userdate($a->timestart, get_string('strftimedatetimeshort', 'langconfig')));
        echo html_writer::tag('td', $duration);
        echo html_writer::start_tag('td');
        echo html_writer::link(
            new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $a->id]),
            '🔍 Review',
            ['class' => 'btn btn-sm btn-outline-primary']
        );
        echo html_writer::end_tag('td');
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo $OUTPUT->footer();
