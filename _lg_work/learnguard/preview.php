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
 * Teacher preview — see the assessment exactly as a student would.
 *
 * No attempt is created. Questions are rendered in read-only mode
 * with a "PREVIEW" banner. Teachers can navigate through all questions.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id   = required_param('id',   PARAM_INT); // cmid
$slot = optional_param('slot', 1, PARAM_INT);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:manage', $context);

$PAGE->set_url('/mod/learnguard/preview.php', ['id' => $id, 'slot' => $slot]);
$PAGE->set_context($context);
$PAGE->set_title('Preview — ' . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

// Load question slots.
$slots = $DB->get_records('learnguard_slots', ['learnguard' => $instance->id], 'slot ASC');
$total = count($slots);

if ($total === 0) {
    echo $OUTPUT->header();
    echo learnguard_nav_bar($id, 'Preview');
    echo html_writer::div(
        '⚠️ No questions in this assessment. ' .
        html_writer::link(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]),
            'Add questions →', ['class' => 'btn btn-sm btn-primary']
        ),
        'alert alert-warning'
    );
    echo $OUTPUT->footer();
    exit;
}

// Clamp slot.
$slot = max(1, min($slot, $total));

// Find question for this slot.
$slotrow = null;
foreach ($slots as $s) {
    if ((int)$s->slot === $slot) { $slotrow = $s; break; }
}

$brand = learnguard_brand();

// Render question HTML using own engine (preview mode = no saved response).
use mod_learnguard\question_engine;
$qhtml = question_engine::render_question(
    (int)$slotrow->questionid,
    $slot,
    [],    // no saved response in preview
    false  // not review mode (show blank question)
);

echo $OUTPUT->header();

// Preview banner.
echo html_writer::start_div('', ['style' =>
    'background:linear-gradient(135deg,#fd7e14,#e55d00);color:#fff;border-radius:8px;' .
    'padding:12px 20px;margin-bottom:16px;display:flex;align-items:center;gap:12px;']);
echo html_writer::tag('span', '👁 PREVIEW MODE', ['style' => 'font-weight:800;font-size:16px;']);
echo html_writer::tag('span',
    'This is how the assessment appears to students. No attempt is created.',
    ['style' => 'opacity:0.9;font-size:14px;']);
echo html_writer::end_div();

echo learnguard_nav_bar($id, 'Preview');

// Assessment shell — mirrors what students see but without proctoring.
echo html_writer::start_div('', ['style' =>
    'display:flex;flex-direction:column;font-family:system-ui,sans-serif;' .
    'border:2px dashed #fd7e14;border-radius:8px;overflow:hidden;']);

// Top bar (mirrors assessment.js shell).
echo html_writer::start_div('', ['style' =>
    'background:' . $brand->primary . ';color:#fff;padding:10px 16px;' .
    'display:flex;align-items:center;justify-content:space-between;']);
echo html_writer::tag('div', '🛡 ' . format_string($instance->name), ['style' => 'font-weight:700;font-size:15px;']);
echo html_writer::start_div('', ['style' => 'display:flex;gap:12px;align-items:center;']);
echo html_writer::tag('div', 'Question ' . $slot . ' of ' . $total, ['style' => 'font-size:14px;']);
if ($instance->timelimit) {
    echo html_writer::tag('div', '⏱ ' . gmdate('H:i:s', $instance->timelimit), ['style' => 'font-size:13px;opacity:0.8;']);
}
echo html_writer::end_div();
echo html_writer::end_div();

// Content area + sidebar.
echo html_writer::start_div('', ['style' => 'display:flex;']);

// Question.
echo html_writer::start_div('', ['style' => 'flex:1;padding:24px;background:#fff;']);
echo $qhtml;

// Navigation buttons.
echo html_writer::start_div('', ['style' => 'display:flex;justify-content:space-between;margin-top:24px;']);
if ($slot > 1) {
    echo html_writer::link(
        new moodle_url('/mod/learnguard/preview.php', ['id' => $id, 'slot' => $slot - 1]),
        '← Previous',
        ['class' => 'btn btn-outline-secondary', 'style' => 'padding:10px 20px;']
    );
} else {
    echo html_writer::tag('span', '');
}
if ($slot < $total) {
    echo html_writer::link(
        new moodle_url('/mod/learnguard/preview.php', ['id' => $id, 'slot' => $slot + 1]),
        'Next →',
        ['class' => 'btn btn-primary', 'style' => 'padding:10px 24px;background:' . $brand->primary . ';']
    );
} else {
    echo html_writer::tag('div',
        '✅ End of assessment',
        ['style' => 'color:#28a745;font-weight:700;align-self:center;']
    );
}
echo html_writer::end_div();
echo html_writer::end_div(); // question area

// Sidebar: question navigator.
echo html_writer::start_div('', ['style' =>
    'width:190px;border-left:1px solid #dee2e6;padding:16px;background:#f8f9fa;flex-shrink:0;']);
echo html_writer::tag('div', 'QUESTIONS',
    ['style' => 'font-size:11px;font-weight:700;color:#6c757d;letter-spacing:1px;margin-bottom:10px;']);

foreach ($slots as $s) {
    $snum    = (int)$s->slot;
    $active  = ($snum === $slot);
    $slotq   = $DB->get_record('learnguard_qbank', ['id' => $s->questionid]);
    $typeShort = [
        'multichoice' => 'MC', 'multicheck' => 'MCK', 'truefalse' => 'TF',
        'shortanswer' => 'SA', 'essay' => 'ES', 'matching' => 'MT',
    ][$slotq->qtype ?? 'multichoice'] ?? '?';

    echo html_writer::link(
        new moodle_url('/mod/learnguard/preview.php', ['id' => $id, 'slot' => $snum]),
        $snum,
        ['style' => 'display:inline-flex;align-items:center;justify-content:center;' .
            'width:34px;height:34px;border-radius:4px;text-decoration:none;font-weight:700;' .
            'font-size:13px;margin:2px;border:2px solid ' . ($active ? $brand->primary : '#dee2e6') . ';' .
            'background:' . ($active ? $brand->primary : '#fff') . ';' .
            'color:' . ($active ? '#fff' : '#333') . ';',
         'title' => ucfirst($slotq->qtype ?? 'question') . ' — ' . round($s->maxmark, 1) . 'pt',
        ]
    );
}

echo html_writer::end_div(); // sidebar
echo html_writer::end_div(); // flex
echo html_writer::end_div(); // shell

// Question info panel below.
echo html_writer::start_div('', ['style' => 'background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:12px 16px;margin-top:12px;font-size:13px;']);
$previewq = $DB->get_record('learnguard_qbank', ['id' => $slotrow->questionid]);
echo '📋 <strong>Type:</strong> ' . ucfirst($previewq->qtype ?? 'unknown') . ' &nbsp;|&nbsp; ';
echo '<strong>Marks:</strong> ' . (float)$slotrow->maxmark . ' &nbsp;|&nbsp; ';
echo '<strong>Randomize:</strong> ' . (!empty($instance->randomize) ? '✅ Yes (' . (int)$instance->num_questions . ' from ' . $total . ')' : '❌ Fixed order');
echo ' &nbsp;|&nbsp; <strong>Proctoring:</strong> ' . (!empty($instance->proctoring) ? '✅ On' : '❌ Off');
echo html_writer::end_div();

echo html_writer::start_div('mt-3 d-flex gap-2');
echo html_writer::link(
    new moodle_url('/mod/learnguard/view.php', ['id' => $id]),
    '← Close Preview', ['class' => 'btn btn-outline-secondary']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]),
    '📝 Edit Questions', ['class' => 'btn btn-outline-primary']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/admin.php', ['id' => $id]),
    '🛡 Admin Backend', ['class' => 'btn btn-outline-dark']
);
echo html_writer::end_div();

echo $OUTPUT->footer();
