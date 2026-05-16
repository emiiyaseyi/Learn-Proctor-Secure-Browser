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
 * Question management for mod_learnguard.
 *
 * Allows teachers to add questions from the question bank to a LearnGuard assessment
 * and reorder/remove them.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/questionlib.php');

$id     = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$qid    = optional_param('qid', 0, PARAM_INT);
$slotid = optional_param('slot', 0, PARAM_INT);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:manage', $context);

$PAGE->set_url('/mod/learnguard/editquestions.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title('Edit Questions — ' . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

// ---- Process POST actions ----
if (data_submitted() && confirm_sesskey()) {

    if ($action === 'add' && $qid > 0) {
        // Check if question is already in the assessment.
        if (!$DB->record_exists('learnguard_slots', ['learnguard' => $instance->id, 'questionid' => $qid])) {
            $maxslot = (int)$DB->get_field_sql(
                'SELECT COALESCE(MAX(slot), 0) FROM {learnguard_slots} WHERE learnguard = ?',
                [$instance->id]
            );
            $DB->insert_record('learnguard_slots', (object)[
                'learnguard'  => $instance->id,
                'questionid'  => $qid,
                'slot'        => $maxslot + 1,
                'maxmark'     => 1.0,
            ]);
        }
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }

    if ($action === 'remove' && $slotid > 0) {
        $DB->delete_records('learnguard_slots', ['id' => $slotid, 'learnguard' => $instance->id]);
        // Re-number remaining slots.
        $remaining = $DB->get_records('learnguard_slots', ['learnguard' => $instance->id], 'slot ASC');
        $n = 1;
        foreach ($remaining as $row) {
            $DB->set_field('learnguard_slots', 'slot', $n++, ['id' => $row->id]);
        }
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }

    if ($action === 'mark' && $slotid > 0) {
        $maxmark = optional_param('maxmark', 1.0, PARAM_FLOAT);
        $DB->set_field('learnguard_slots', 'maxmark', max(0.0, $maxmark), ['id' => $slotid, 'learnguard' => $instance->id]);
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }
}

echo $OUTPUT->header();

echo html_writer::link(
    new moodle_url('/mod/learnguard/view.php', ['id' => $id]),
    '← Back to Assessment',
    ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

echo html_writer::tag('h2', '📝 Manage Questions — ' . format_string($instance->name));

// ---- Current question list ----
$slots = $DB->get_records_sql(
    "SELECT s.*, q.name AS qname, q.qtype
       FROM {learnguard_slots} s
       JOIN {question} q ON q.id = s.questionid
      WHERE s.learnguard = :lgid
   ORDER BY s.slot ASC",
    ['lgid' => $instance->id]
);

echo html_writer::tag('h4', 'Current Questions (' . count($slots) . ')', ['class' => 'mt-3 mb-2']);

if (empty($slots)) {
    echo html_writer::div('No questions added yet. Add from the question bank below.', 'alert alert-info');
} else {
    foreach ($slots as $row) {
        echo html_writer::start_div('lg-qbank-item');
        echo html_writer::start_div('d-flex align-items-center gap-3 flex-grow-1');
        echo html_writer::tag('span', '#' . $row->slot, ['style' => 'font-weight:700;color:#6c757d;min-width:28px;']);
        echo html_writer::start_div();
        echo html_writer::tag('div', format_string($row->qname), ['style' => 'font-weight:600;']);
        echo html_writer::tag('div', ucfirst($row->qtype), ['style' => 'font-size:12px;color:#6c757d;']);
        echo html_writer::end_div();
        echo html_writer::end_div();

        // Max mark form.
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;align-items:center;gap:4px;']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'mark']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'slot',    'value' => $row->id]);
        echo html_writer::empty_tag('input', [
            'type' => 'number', 'name' => 'maxmark', 'value' => $row->maxmark,
            'min' => '0', 'step' => '0.5', 'style' => 'width:60px;padding:4px;border:1px solid #ccc;border-radius:4px;',
        ]);
        echo html_writer::tag('span', 'pts', ['style' => 'font-size:12px;color:#6c757d;']);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => 'Set',
            'class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:3px 8px;',
        ]);
        echo html_writer::end_tag('form');

        // Remove button.
        echo html_writer::start_tag('form', ['method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'remove']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'slot',    'value' => $row->id]);
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => '✕',
            'class' => 'btn btn-sm btn-outline-danger',
            'onclick' => 'return confirm("Remove this question?");',
        ]);
        echo html_writer::end_tag('form');

        echo html_writer::end_div(); // lg-qbank-item
    }
}

// ---- Question bank picker ----
echo html_writer::tag('h4', 'Add Questions from Question Bank', ['class' => 'mt-4 mb-2']);

// Get questions available in this course context (and system context).
$coursecontext = context_course::instance($course->id);
$questions = $DB->get_records_sql(
    "SELECT q.id, q.name, q.qtype, c.name AS catname
       FROM {question} q
       JOIN {question_categories} c ON c.id = q.category
      WHERE c.contextid IN (:cctx, :sctx)
        AND q.hidden = 0
        AND q.parent = 0
   ORDER BY c.name, q.name",
    ['cctx' => $coursecontext->id, 'sctx' => context_system::instance()->id]
);

$alreadyadded = array_column($slots ? array_values($slots) : [], 'questionid');

if (empty($questions)) {
    echo html_writer::div(
        'No questions found in the course question bank. ' .
        html_writer::link(
            new moodle_url('/question/edit.php', ['courseid' => $course->id]),
            'Open Question Bank →',
            ['target' => '_blank', 'class' => 'btn btn-sm btn-primary']
        ),
        'alert alert-warning'
    );
} else {
    $bycat = [];
    foreach ($questions as $q) {
        $bycat[$q->catname][] = $q;
    }

    foreach ($bycat as $catname => $qs) {
        echo html_writer::tag('div', format_string($catname),
            ['style' => 'font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;' .
                        'letter-spacing:0.5px;margin:12px 0 4px;']);

        foreach ($qs as $q) {
            $added = in_array($q->id, $alreadyadded);
            echo html_writer::start_div('lg-qbank-item', ['style' => $added ? 'opacity:0.5;' : '']);

            echo html_writer::start_div('flex-grow-1');
            echo html_writer::tag('div', format_string($q->name), ['style' => 'font-weight:600;font-size:14px;']);
            echo html_writer::tag('div', ucfirst($q->qtype), ['style' => 'font-size:12px;color:#6c757d;']);
            echo html_writer::end_div();

            if ($added) {
                echo html_writer::tag('span', '✓ Added', ['class' => 'btn btn-sm btn-success disabled']);
            } else {
                echo html_writer::start_tag('form', ['method' => 'post']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'add']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qid',     'value' => $q->id]);
                echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Add', 'class' => 'btn btn-sm btn-primary']);
                echo html_writer::end_tag('form');
            }

            echo html_writer::end_div();
        }
    }
}

echo html_writer::start_div('mt-3');
echo html_writer::link(
    new moodle_url('/question/edit.php', ['courseid' => $course->id]),
    '🏦 Open Question Bank to Create Questions',
    ['class' => 'btn btn-outline-primary', 'target' => '_blank']
);
echo html_writer::end_div();

echo $OUTPUT->footer();
