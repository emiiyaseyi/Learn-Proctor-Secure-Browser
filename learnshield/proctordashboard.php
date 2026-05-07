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
 * Live proctor dashboard for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');

use quizaccess_learnshield\session_manager;
use quizaccess_learnshield\suspicion_scorer;
use quizaccess_learnshield\link_generator;

$courseid = optional_param('courseid', 0, PARAM_INT);
$cmid     = optional_param('cmid',     0, PARAM_INT);
$gridsize = optional_param('grid',     9, PARAM_INT);
$riskfilter = optional_param('risk',   '', PARAM_ALPHA);

require_login();

if ($cmid) {
    $cm      = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
    $context = context_module::instance($cm->id);
} else {
    $context = context_system::instance();
}

require_capability('quizaccess/learnshield:liveproctor', $context);

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php', [
    'courseid' => $courseid, 'cmid' => $cmid,
]));
$PAGE->set_context($context);
$PAGE->set_heading(get_string('live_dashboard', 'quizaccess_learnshield'));
$PAGE->set_title(get_string('live_dashboard',   'quizaccess_learnshield'));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');
$PAGE->requires->js_call_amd('quizaccess_learnshield/livemonitor', 'init', [[
    'courseid'   => $courseid,
    'cmid'       => $cmid,
    'gridsize'   => $gridsize,
    'riskfilter' => $riskfilter,
    'refreshurl' => (new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php',
        ['courseid' => $courseid, 'cmid' => $cmid]))->out(false),
]]);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('live_dashboard', 'quizaccess_learnshield'));

// Stats bar.
$activesessions  = session_manager::get_active_sessions($courseid, $cmid);
$flaggedcount    = count(array_filter($activesessions, fn($s) => $s->is_flagged));
$violationcount  = $DB->count_records_select('quizaccess_learnshield_violations',
    'courseid = :c', ['c' => $courseid]);

echo html_writer::start_div('learnshield-stats-bar');
foreach ([
    [count($activesessions), get_string('active_learners',  'quizaccess_learnshield')],
    [$flaggedcount,           get_string('flagged_sessions', 'quizaccess_learnshield')],
    [$violationcount,         get_string('total_violations', 'quizaccess_learnshield')],
] as [$num, $label]) {
    echo html_writer::start_div('learnshield-stat-card');
    echo html_writer::div($num,   'stat-number');
    echo html_writer::div($label, 'stat-label');
    echo html_writer::end_div();
}
echo html_writer::end_div();

// Controls bar.
$gridurl = new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php',
    ['courseid' => $courseid, 'cmid' => $cmid]);
echo html_writer::start_div('d-flex align-items-center gap-2 mb-3 flex-wrap');

foreach ([4 => '2×2', 9 => '3×3', 16 => '4×4'] as $size => $label) {
    $active = ($gridsize == $size) ? 'btn-primary' : 'btn-outline-secondary';
    echo html_writer::link(
        new moodle_url($gridurl, ['grid' => $size]),
        $label,
        ['class' => "btn btn-sm {$active}"]
    );
}

// Risk filter.
foreach (['' => 'All', 'low' => 'Low', 'medium' => 'Medium', 'high' => 'High'] as $val => $label) {
    $active = ($riskfilter === $val) ? 'btn-warning' : 'btn-outline-secondary';
    echo html_writer::link(
        new moodle_url($gridurl, ['grid' => $gridsize, 'risk' => $val]),
        $label,
        ['class' => "btn btn-sm {$active}"]
    );
}

echo html_writer::end_div();

// Alert panel (populated by JS).
echo html_writer::div('', 'learnshield-alert-panel mb-3', ['id' => 'ls-alert-panel']);

// Grid.
$gridclass = "learnshield-grid learnshield-grid-{$gridsize}";
echo html_writer::start_div($gridclass, ['id' => 'ls-session-grid']);

if (empty($activesessions)) {
    echo html_writer::div(
        html_writer::tag('p', get_string('no_active_sessions', 'quizaccess_learnshield'), ['class' => 'text-muted']),
        'col-12 text-center p-5'
    );
} else {
    foreach ($activesessions as $session) {
        if ($riskfilter && $session->risk_level !== $riskfilter) {
            continue;
        }

        $riskclass   = suspicion_scorer::risk_class($session->risk_level);
        $tileclass   = 'learnshield-tile risk-' . $session->risk_level . ($session->is_flagged ? ' flagged' : '');
        $snapshoturl = $session->latest_snapshot ?? '';

        echo html_writer::start_div($tileclass, [
            'data-sessionid' => $session->id,
            'data-userid'    => $session->userid,
        ]);

        // Alert dot if high risk or flagged.
        if ($session->risk_level === 'high' || $session->is_flagged) {
            echo html_writer::div(
                html_writer::span('', 'learnshield-tile-alert-dot'),
                'learnshield-tile-alerts'
            );
        }

        // Risk badge.
        echo html_writer::div(
            html_writer::span(ucfirst($session->risk_level), "learnshield-risk-badge {$riskclass}"),
            'learnshield-tile-risk'
        );

        // Snapshot.
        if ($snapshoturl) {
            echo html_writer::empty_tag('img', [
                'src'   => $snapshoturl,
                'class' => 'learnshield-tile-snapshot',
                'alt'   => fullname($session),
            ]);
        } else {
            echo html_writer::div(
                html_writer::tag('i', '', ['class' => 'fa fa-user fa-3x text-muted']),
                'learnshield-tile-snapshot d-flex align-items-center justify-content-center'
            );
        }

        // Info bar.
        echo html_writer::start_div('learnshield-tile-info');
        echo html_writer::div(s(fullname($session)), 'learnshield-tile-name');
        echo html_writer::div(
            'Score: ' . $session->risk_score . ' | Tabs: ' . $session->tab_switches,
            'learnshield-tile-meta'
        );
        echo html_writer::end_div();

        echo html_writer::end_div(); // .learnshield-tile
    }
}

echo html_writer::end_div(); // #ls-session-grid

// Chat panel placeholder (populated when a tile is clicked).
echo html_writer::div('', 'mt-4', ['id' => 'ls-chat-container']);

echo $OUTPUT->footer();
