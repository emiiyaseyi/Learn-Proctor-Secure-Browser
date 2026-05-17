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
 * Pre-exam system readiness check page — Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

$cmid   = required_param('cmid',   PARAM_INT);
$quizid = optional_param('quizid', 0, PARAM_INT);

require_login();

$cm      = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
$context = context_module::instance($cm->id);
$course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/preexamcheck.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('precheck_title', 'quizaccess_learnshield'));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');
$PAGE->requires->js_call_amd('quizaccess_learnshield/preexamcheck', 'init', [[]]);

$quizviewurl = new moodle_url('/mod/quiz/view.php', ['id' => $cmid]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('precheck_title', 'quizaccess_learnshield'));
echo html_writer::tag('p', get_string('precheck_desc', 'quizaccess_learnshield'), ['class' => 'text-muted']);

echo html_writer::start_div('learnshield-precheck-wrap');
echo html_writer::start_div('card');
echo html_writer::start_div('card-body');

// Check rows.
$checks = [
    'webcam'      => ['icon' => 'fa-video-camera', 'label' => get_string('precheck_webcam',       'quizaccess_learnshield')],
    'browser'     => ['icon' => 'fa-globe',         'label' => get_string('precheck_browser',      'quizaccess_learnshield')],
    'internet'    => ['icon' => 'fa-wifi',           'label' => get_string('precheck_internet',     'quizaccess_learnshield')],
    'permissions' => ['icon' => 'fa-check-circle',   'label' => get_string('precheck_permissions',  'quizaccess_learnshield')],
    'fullscreen'  => ['icon' => 'fa-expand',         'label' => get_string('precheck_fullscreen',   'quizaccess_learnshield')],
];

foreach ($checks as $id => $check) {
    echo html_writer::start_div('learnshield-precheck-item');
    echo html_writer::div(
        html_writer::tag('i', '', ['class' => 'fa ' . $check['icon']]),
        'icon-col'
    );
    echo html_writer::div($check['label'], 'label-col');
    echo html_writer::div(
        html_writer::span(get_string('precheck_pending', 'quizaccess_learnshield'),
            'learnshield-check-pending', ['id' => 'ls-check-' . $id]),
        'status-col'
    );
    echo html_writer::end_div();
}

echo html_writer::div('', 'alert mt-3', ['id' => 'ls-precheck-summary', 'style' => 'display:none;']);

echo html_writer::start_div('text-center mt-4');
echo html_writer::tag('button',
    html_writer::tag('i', '', ['class' => 'fa fa-arrow-right mr-2']) . 'Continue to Quiz',
    [
        'id'       => 'ls-start-exam-btn',
        'class'    => 'btn btn-success btn-lg',
        'disabled' => 'disabled',
        'onclick'  => "window.location='" . $quizviewurl->out() . "'",
    ]
);
echo html_writer::end_div();

echo html_writer::end_div(); // .card-body
echo html_writer::end_div(); // .card
echo html_writer::end_div(); // .learnshield-precheck-wrap

echo $OUTPUT->footer();
