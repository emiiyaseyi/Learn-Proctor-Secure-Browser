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
 * Attempt detail / review page for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id        = required_param('id',      PARAM_INT); // Course module ID.
$attemptid = required_param('attempt', PARAM_INT);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);
$attempt  = $DB->get_record('learnguard_attempts', ['id' => $attemptid, 'learnguard' => $instance->id], '*', MUST_EXIST);
$student  = $DB->get_record('user', ['id' => $attempt->userid], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:viewreports', $context);

$PAGE->set_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]);
$PAGE->set_context($context);
$PAGE->set_title('Attempt Review — ' . fullname($student));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();

// Back link.
echo html_writer::link(
    new moodle_url('/mod/learnguard/report.php', ['id' => $id]),
    '← Back to Report',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// Integrity score badge.
$riskcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
$rc = $riskcolours[$attempt->risk_level] ?? '#6c757d';

echo html_writer::start_div('d-flex align-items-start gap-3 mb-4');

// Student info.
echo html_writer::start_div('flex-grow-1');
echo html_writer::tag('h2', fullname($student), ['style' => 'margin-bottom:4px;']);
echo html_writer::tag('p', $student->email . ' &mdash; Attempt #' . $attempt->attempt, ['style' => 'color:#6c757d;margin:0;']);
echo html_writer::tag('p', 'State: <strong>' . ucfirst($attempt->state) . '</strong> &nbsp;|&nbsp; ' .
    'Started: <strong>' . userdate($attempt->timestart) . '</strong>' .
    (!empty($attempt->timefinish) ? ' &nbsp;|&nbsp; Finished: <strong>' . userdate($attempt->timefinish) . '</strong>' : ''),
    ['style' => 'color:#6c757d;margin-top:4px;']
);
echo html_writer::end_div();

// Risk badge.
echo html_writer::start_div('text-center', ['style' => 'flex-shrink:0;']);
echo html_writer::tag('div', $attempt->risk_score, [
    'class' => 'lg-grade-badge',
    'style' => 'background:' . $rc . ';margin:0 auto 4px;font-size:20px;width:72px;height:72px;',
]);
echo html_writer::tag('div', 'Risk Score', ['style' => 'font-size:12px;color:#6c757d;font-weight:600;']);
echo html_writer::tag('div', strtoupper($attempt->risk_level), [
    'style' => 'font-size:11px;color:' . $rc . ';font-weight:700;letter-spacing:1px;',
]);
echo html_writer::end_div();

// Grade badge.
if (!empty($attempt->sumgrades)) {
    $pct = $instance->grade > 0 ? round(($attempt->sumgrades / $instance->grade) * 100) : 0;
    $gc  = $pct >= 70 ? '#28a745' : ($pct >= 50 ? '#fd7e14' : '#dc3545');
    echo html_writer::start_div('text-center', ['style' => 'flex-shrink:0;']);
    echo html_writer::tag('div', $pct . '%', [
        'class' => 'lg-grade-badge',
        'style' => 'background:' . $gc . ';margin:0 auto 4px;width:72px;height:72px;',
    ]);
    echo html_writer::tag('div', 'Grade', ['style' => 'font-size:12px;color:#6c757d;font-weight:600;']);
    echo html_writer::tag('div', round($attempt->sumgrades, 1) . ' / ' . $instance->grade, [
        'style' => 'font-size:11px;color:#6c757d;',
    ]);
    echo html_writer::end_div();
}

echo html_writer::end_div(); // d-flex

// Snapshots.
$snapshots = $DB->get_records('learnguard_snapshots', ['attemptid' => $attemptid], 'timemodified ASC');
if (!empty($snapshots)) {
    echo html_writer::tag('h4', '📷 Webcam Snapshots');
    echo html_writer::start_div('d-flex flex-wrap gap-2 mb-4');
    foreach ($snapshots as $snap) {
        $src = 'data:image/jpeg;base64,' . $snap->snapshot_url;
        echo html_writer::start_div('', ['style' => 'text-align:center;']);
        echo html_writer::empty_tag('img', [
            'src'   => $src,
            'style' => 'width:160px;height:120px;object-fit:cover;border-radius:6px;border:1px solid #dee2e6;cursor:pointer;',
            'title' => userdate($snap->timemodified) . ' (' . $snap->snapshot_type . ')',
            'onclick' => "window.open('" . $src . "')",
        ]);
        echo html_writer::tag('div', userdate($snap->timemodified, '%H:%M:%S'), [
            'style' => 'font-size:11px;color:#6c757d;margin-top:2px;',
        ]);
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

// Proctoring events.
$events = $DB->get_records('learnguard_events', ['attemptid' => $attemptid], 'timemodified ASC');
if (!empty($events)) {
    echo html_writer::tag('h4', '⚠️ Proctoring Events');
    echo html_writer::start_tag('table', ['class' => 'generaltable w-100 mb-4']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    foreach (['Time', 'Event', 'Severity', 'Score Impact', 'Data'] as $h) {
        echo html_writer::tag('th', $h, ['style' => 'padding:8px 12px;']);
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    $sevcols = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
    foreach ($events as $ev) {
        $sevcol = $sevcols[$ev->severity] ?? '#6c757d';
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', userdate($ev->timemodified, '%H:%M:%S'), ['style' => 'white-space:nowrap;']);
        echo html_writer::tag('td', str_replace('_', ' ', ucfirst($ev->event_type)));
        echo html_writer::tag('td', html_writer::tag('span', ucfirst($ev->severity), [
            'style' => 'color:' . $sevcol . ';font-weight:700;',
        ]));
        echo html_writer::tag('td', $ev->score_impact > 0 ? '+' . $ev->score_impact : '0', ['style' => 'text-align:center;']);
        echo html_writer::tag('td', html_writer::tag('code', htmlspecialchars($ev->event_data ?? ''), ['style' => 'font-size:12px;']));
        echo html_writer::end_tag('tr');
    }
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
} else {
    echo html_writer::div('No proctoring events recorded.', 'alert alert-success mb-4');
}

// Suspend/reinstate actions (for authorised users).
if (has_capability('mod/learnguard:suspendattempt', $context) && $attempt->state === 'inprogress') {
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => 'attemptdetail.php', 'class' => 'mb-3']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id', 'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attempt', 'value' => $attemptid]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'suspend']);
    echo html_writer::empty_tag('input', [
        'type'    => 'submit',
        'value'   => '⛔ Suspend This Attempt',
        'class'   => 'btn btn-danger',
        'onclick' => 'return confirm("Are you sure you want to suspend this attempt?");',
    ]);
    echo html_writer::end_tag('form');
}

// Process suspend action.
if (
    data_submitted() &&
    confirm_sesskey() &&
    optional_param('action', '', PARAM_ALPHA) === 'suspend' &&
    has_capability('mod/learnguard:suspendattempt', $context)
) {
    $DB->set_field('learnguard_attempts', 'state', 'suspended', ['id' => $attemptid]);
    redirect(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $id, 'attempt' => $attemptid]),
        'Attempt suspended.', null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->footer();
