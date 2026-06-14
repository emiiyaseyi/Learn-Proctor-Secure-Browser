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
 * Compatible with Moodle 4.1+ question bank (question_bank_entries / question_versions).
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/questionlib.php');

$id     = optional_param('id',      0, PARAM_INT); // course-module id — required, but handled gracefully
$action = optional_param('action',  '', PARAM_ALPHA);
$qid    = optional_param('qid',     0,  PARAM_INT);
$slotid = optional_param('slot',    0,  PARAM_INT);

// Redirect gracefully if id missing instead of fatal error.
if (!$id) {
    require_login();
    redirect(new moodle_url('/mod/learnguard/questionbank.php'),
        'Please access question management from within an assessment.', null,
        \core\output\notification::NOTIFY_WARNING);
}

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

// ---- Helper: load all questions from LearnGuard's OWN bank ----
function learnguard_load_bank_questions(): array {
    global $DB;
    try {
        return $DB->get_records_sql(
            "SELECT q.id, q.name, q.qtype, c.name AS catname
               FROM {learnguard_qbank} q
          LEFT JOIN {learnguard_qbank_cats} c ON c.id = q.categoryid
           ORDER BY c.name, q.name"
        );
    } catch (Throwable $e) {
        debugging('LearnGuard editquestions: ' . $e->getMessage(), DEBUG_DEVELOPER);
        return [];
    }
}

// ---- POST actions ----
if (data_submitted() && confirm_sesskey()) {

    // Bulk add multiple selected questions.
    if ($action === 'addmulti') {
        $qids = optional_param_array('qids', [], PARAM_INT);
        $added = 0;
        foreach ($qids as $bulkqid) {
            if ($bulkqid > 0 && !$DB->record_exists('learnguard_slots', ['learnguard' => $instance->id, 'questionid' => $bulkqid])) {
                $maxslot = (int)$DB->get_field_sql(
                    'SELECT COALESCE(MAX(slot), 0) FROM {learnguard_slots} WHERE learnguard = ?', [$instance->id]
                );
                $q = $DB->get_record('learnguard_qbank', ['id' => $bulkqid]);
                $DB->insert_record('learnguard_slots', (object)[
                    'learnguard' => $instance->id, 'questionid' => $bulkqid,
                    'slot'       => $maxslot + 1,
                    'maxmark'    => $q ? (float)$q->defaultmark : 1.0,
                    'page'       => 1,
                ]);
                $added++;
            }
        }
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'slots']),
            "$added question(s) added to assessment.", null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'add' && $qid > 0) {
        if (!$DB->record_exists('learnguard_slots', ['learnguard' => $instance->id, 'questionid' => $qid])) {
            $maxslot = (int)$DB->get_field_sql(
                'SELECT COALESCE(MAX(slot), 0) FROM {learnguard_slots} WHERE learnguard = ?', [$instance->id]
            );
            $q = $DB->get_record('learnguard_qbank', ['id' => $qid]);
            $DB->insert_record('learnguard_slots', (object)[
                'learnguard'  => $instance->id,
                'questionid'  => $qid,
                'slot'        => $maxslot + 1,
                'maxmark'     => $q ? (float)$q->defaultmark : 1.0,
                'page'        => 1,
            ]);
        }
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }

    if ($action === 'remove' && $slotid > 0) {
        $DB->delete_records('learnguard_slots', ['id' => $slotid, 'learnguard' => $instance->id]);
        $remaining = $DB->get_records('learnguard_slots', ['learnguard' => $instance->id], 'slot ASC');
        $n = 1;
        foreach ($remaining as $row) { $DB->set_field('learnguard_slots', 'slot', $n++, ['id' => $row->id]); }
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }

    if ($action === 'mark' && $slotid > 0) {
        $maxmark = optional_param('maxmark', 1.0, PARAM_FLOAT);
        $DB->set_field('learnguard_slots', 'maxmark', max(0.0, $maxmark), ['id' => $slotid, 'learnguard' => $instance->id]);
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }

    if ($action === 'moveup' && $slotid > 0) {
        $slot = $DB->get_record('learnguard_slots', ['id' => $slotid, 'learnguard' => $instance->id]);
        if ($slot && $slot->slot > 1) {
            $prev = $DB->get_record('learnguard_slots', ['learnguard' => $instance->id, 'slot' => $slot->slot - 1]);
            if ($prev) {
                $DB->set_field('learnguard_slots', 'slot', $slot->slot, ['id' => $prev->id]);
                $DB->set_field('learnguard_slots', 'slot', $slot->slot - 1, ['id' => $slot->id]);
            }
        }
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }

    if ($action === 'movedown' && $slotid > 0) {
        $slot = $DB->get_record('learnguard_slots', ['id' => $slotid, 'learnguard' => $instance->id]);
        $maxslot = (int)$DB->get_field_sql(
            'SELECT MAX(slot) FROM {learnguard_slots} WHERE learnguard = ?', [$instance->id]
        );
        if ($slot && $slot->slot < $maxslot) {
            $next = $DB->get_record('learnguard_slots', ['learnguard' => $instance->id, 'slot' => $slot->slot + 1]);
            if ($next) {
                $DB->set_field('learnguard_slots', 'slot', $slot->slot, ['id' => $next->id]);
                $DB->set_field('learnguard_slots', 'slot', $slot->slot + 1, ['id' => $slot->id]);
            }
        }
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }
}

echo $OUTPUT->header();
echo learnguard_nav_bar($id, 'Manage Questions');

echo html_writer::tag('h2', '📝 Manage Questions — ' . format_string($instance->name));

// ---- Tabs ----
$qtab = optional_param('qtab', 'slots', PARAM_ALPHA);
$tabs = ['slots' => '📋 Current Questions', 'bank' => '🏦 Question Bank', 'import' => '📥 Import Questions'];
echo html_writer::start_div('d-flex gap-2 mb-4', ['style' => 'border-bottom:2px solid #dee2e6;']);
foreach ($tabs as $k => $label) {
    $active = ($qtab === $k);
    echo html_writer::link(
        new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => $k]),
        $label,
        ['style' => 'padding:8px 16px;text-decoration:none;font-size:14px;font-weight:600;border-radius:4px 4px 0 0;' .
            ($active ? 'background:' . learnguard_brand()->primary . ';color:#fff;' : 'color:#495057;background:#f8f9fa;border:1px solid #dee2e6;')]
    );
}
echo html_writer::end_div();

// =====================================================================
// TAB: Current Question Slots
// =====================================================================
if ($qtab === 'slots') {
    $slots = $DB->get_records_sql(
        "SELECT s.*, q.name AS qname, q.qtype
           FROM {learnguard_slots} s
           JOIN {question} q ON q.id = s.questionid
          WHERE s.learnguard = :lgid
       ORDER BY s.slot ASC",
        ['lgid' => $instance->id]
    );

    $totalmarks = array_sum(array_column($slots ? array_values($slots) : [], 'maxmark'));

    echo html_writer::tag('h4', count($slots) . ' question(s) · Total marks: ' . $totalmarks, ['class' => 'mb-3']);

    if (empty($slots)) {
        echo html_writer::div(
            'No questions added yet. Use the <strong>Question Bank</strong> tab or <strong>Import Questions</strong> tab to add questions.',
            'alert alert-info'
        );
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['#', 'Question', 'Type', 'Marks', 'Reorder', 'Actions'] as $h) {
            echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        $total = count($slots);
        $i = 0;
        foreach ($slots as $row) {
            $i++;
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', $row->slot, ['style' => 'font-weight:700;color:#6c757d;width:40px;text-align:center;']);
            echo html_writer::start_tag('td');
            echo html_writer::tag('div', format_string($row->qname), ['style' => 'font-weight:600;']);
            echo html_writer::tag('div', ucfirst($row->qtype), ['style' => 'font-size:12px;color:#6c757d;']);
            echo html_writer::end_tag('td');
            echo html_writer::tag('td', html_writer::tag('span', strtoupper($row->qtype),
                ['style' => 'background:#e9ecef;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;']));

            // Mark editor.
            echo html_writer::start_tag('td');
            echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:flex;align-items:center;gap:4px;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'mark']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'slot',    'value' => $row->id]);
            echo html_writer::empty_tag('input', [
                'type' => 'number', 'name' => 'maxmark', 'value' => $row->maxmark,
                'min' => '0', 'step' => '0.5',
                'style' => 'width:55px;padding:3px;border:1px solid #ccc;border-radius:4px;font-size:13px;',
            ]);
            echo html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => '✓',
                'class' => 'btn btn-sm btn-outline-secondary', 'style' => 'padding:3px 6px;',
            ]);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');

            // Reorder buttons.
            echo html_writer::start_tag('td');
            if ($i > 1) {
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'moveup']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'slot',    'value' => $row->id]);
                echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '▲',
                    'class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Move up']);
                echo html_writer::end_tag('form');
            }
            if ($i < $total) {
                echo ' ';
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'movedown']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'slot',    'value' => $row->id]);
                echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '▼',
                    'class' => 'btn btn-sm btn-outline-secondary', 'title' => 'Move down']);
                echo html_writer::end_tag('form');
            }
            echo html_writer::end_tag('td');

            // Remove button.
            echo html_writer::start_tag('td');
            echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'remove']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'slot',    'value' => $row->id]);
            echo html_writer::empty_tag('input', [
                'type' => 'submit', 'value' => '✕ Remove',
                'class' => 'btn btn-sm btn-outline-danger',
                'onclick' => 'return confirm("Remove this question?");',
            ]);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }
}

// =====================================================================
// TAB: Question Bank Picker
// =====================================================================
if ($qtab === 'bank') {
    $questions = learnguard_load_bank_questions();

    // Get already-added question IDs.
    $alreadyadded = $DB->get_fieldset_select('learnguard_slots', 'questionid',
        'learnguard = :lgid', ['lgid' => $instance->id]);
    $alreadyadded = array_flip($alreadyadded); // for fast lookup.

    if (empty($questions)) {
        echo html_writer::start_div('alert alert-warning');
        echo 'No questions in the LearnGuard question bank yet. ';
        echo html_writer::link(
            new moodle_url('/mod/learnguard/questionedit.php', ['id' => $id]),
            '+ Create a question', ['class' => 'btn btn-sm btn-primary']
        );
        echo ' ';
        echo html_writer::link(
            new moodle_url('/mod/learnguard/questionimport.php', ['id' => $id]),
            '📥 Import', ['class' => 'btn btn-sm btn-outline-secondary']
        );
        echo html_writer::end_div();
    } else {
        // ---- Bulk-add form wrapping the entire list ----
        echo html_writer::start_tag('form', ['method' => 'post', 'id' => 'lg-bulk-form']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'addmulti']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qtab',    'value' => 'bank']);

        // Toolbar: search + select-all + bulk add.
        echo html_writer::start_div('mb-3 d-flex gap-2 align-items-center flex-wrap');
        echo html_writer::empty_tag('input', [
            'type' => 'text', 'id' => 'lg-qsearch', 'placeholder' => '🔍 Filter questions…',
            'class' => 'form-control', 'style' => 'max-width:280px;',
            'oninput' => 'document.querySelectorAll(".lg-qbank-item").forEach(function(el){' .
                'el.style.display=el.textContent.toLowerCase().includes(this.value.toLowerCase())?"flex":"none";})',
        ]);
        echo html_writer::tag('span', count($questions) . ' questions', ['style' => 'color:#6c757d;font-size:13px;']);
        echo html_writer::tag('button',
            '☑ Select All', [
                'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary',
                'onclick' => 'document.querySelectorAll(".lg-bulk-cb:not(:disabled)").forEach(function(cb){cb.checked=true;});',
            ]
        );
        echo html_writer::tag('button',
            '☐ Deselect All', [
                'type' => 'button', 'class' => 'btn btn-sm btn-outline-secondary',
                'onclick' => 'document.querySelectorAll(".lg-bulk-cb").forEach(function(cb){cb.checked=false;});',
            ]
        );
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => '+ Add Selected to Assessment',
            'class' => 'btn btn-sm btn-success',
            'onclick' => 'return document.querySelectorAll(".lg-bulk-cb:checked").length > 0 || alert("Select at least one question.");',
        ]);
        echo html_writer::end_div();

        $bycat = [];
        foreach ($questions as $q) {
            $bycat[$q->catname ?? 'General'][] = $q;
        }

        foreach ($bycat as $catname => $qs) {
            echo html_writer::tag('div', format_string($catname), [
                'style' => 'font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;' .
                           'letter-spacing:0.5px;margin:16px 0 6px;border-bottom:1px solid #dee2e6;padding-bottom:4px;',
            ]);

            foreach ($qs as $q) {
                $added = isset($alreadyadded[$q->id]);
                echo html_writer::start_div('lg-qbank-item', ['style' => $added ? 'opacity:0.55;' : '']);

                // Bulk checkbox.
                echo html_writer::start_div('', ['style' => 'flex-shrink:0;margin-right:4px;']);
                echo html_writer::empty_tag('input', [
                    'type'     => 'checkbox',
                    'name'     => 'qids[]',
                    'value'    => $q->id,
                    'class'    => 'lg-bulk-cb',
                    'style'    => 'width:16px;height:16px;cursor:pointer;',
                    ($added ? 'disabled' : '') => ($added ? 'disabled' : ''),
                    'title'    => $added ? 'Already in assessment' : 'Select to add',
                ]);
                echo html_writer::end_div();

                echo html_writer::start_div('flex-grow-1');
                echo html_writer::tag('div', format_string($q->name), ['style' => 'font-weight:600;font-size:14px;']);
                echo html_writer::tag('div', ucfirst($q->qtype) . ' · ' . $q->defaultmark . ' pt',
                    ['style' => 'font-size:12px;color:#6c757d;']);
                echo html_writer::end_div();

                if ($added) {
                    echo html_writer::tag('span', '✓ Added', ['class' => 'btn btn-sm btn-success disabled']);
                } else {
                    // Individual add button (separate mini-form).
                    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'add']);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qid',     'value' => $q->id]);
                    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qtab',    'value' => 'bank']);
                    echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '+ Add',
                        'class' => 'btn btn-sm btn-primary']);
                    echo html_writer::end_tag('form');
                }
                echo html_writer::end_div(); // lg-qbank-item
            }
        }

        echo html_writer::end_tag('form'); // bulk form
    }

    echo html_writer::start_div('mt-3 d-flex gap-2');
    echo html_writer::link(
        new moodle_url('/mod/learnguard/questionbank.php', ['id' => $id]),
        '🏦 Manage Question Bank', ['class' => 'btn btn-outline-primary']
    );
    echo html_writer::link(
        new moodle_url('/mod/learnguard/questionedit.php', ['id' => $id]),
        '+ New Question', ['class' => 'btn btn-primary']
    );
    echo html_writer::link(
        new moodle_url('/mod/learnguard/questionimport.php', ['id' => $id]),
        '📥 Import', ['class' => 'btn btn-outline-secondary']
    );
    echo html_writer::end_div();
}

// =====================================================================
// TAB: Import Questions
// =====================================================================
if ($qtab === 'import') {
    echo html_writer::tag('h4', '📥 Import Questions', ['class' => 'mb-3']);

    echo html_writer::start_div('alert alert-info');
    echo html_writer::tag('strong', 'How to import questions into this assessment:');
    echo html_writer::start_tag('ol', ['style' => 'margin:8px 0 0 16px;font-size:14px;']);
    echo html_writer::tag('li', 'First, import your questions into the <strong>Moodle Question Bank</strong> using the link below. ' .
        'Moodle supports GIFT, Moodle XML, Aiken (plain text), and CSV formats.');
    echo html_writer::tag('li', 'After importing into the question bank, switch to the <strong>Question Bank</strong> tab above and add questions to this assessment.');
    echo html_writer::end_tag('ol');
    echo html_writer::end_div();

    // Direct import links for supported formats.
    echo html_writer::tag('h5', 'Import into Question Bank', ['class' => 'mt-3 mb-2']);
    echo html_writer::start_div('d-flex gap-2 flex-wrap mb-4');

    $importformats = [
        'gift'  => ['label' => '📝 GIFT Format',     'desc' => 'Most popular — plain text format'],
        'xml'   => ['label' => '🗂 Moodle XML',      'desc' => 'Full-featured Moodle format'],
        'aiken' => ['label' => '📃 Aiken Format',    'desc' => 'Simple multiple choice (plain text)'],
    ];

    foreach ($importformats as $format => $info) {
        echo html_writer::start_div('', ['style' =>
            'border:1px solid #dee2e6;border-radius:8px;padding:16px;min-width:200px;max-width:220px;']);
        echo html_writer::tag('div', $info['label'], ['style' => 'font-weight:600;margin-bottom:4px;']);
        echo html_writer::tag('div', $info['desc'], ['style' => 'font-size:12px;color:#6c757d;margin-bottom:10px;']);
        echo html_writer::link(
            new moodle_url('/question/bank/importquestions/import.php', [
                'courseid' => $course->id,
                'format'   => $format,
            ]),
            'Import →',
            ['class' => 'btn btn-sm btn-primary', 'target' => '_blank']
        );
        echo html_writer::end_div();
    }
    echo html_writer::end_div();

    // GIFT format reference.
    echo html_writer::tag('h5', 'GIFT Format Quick Reference', ['class' => 'mb-2']);
    echo html_writer::start_tag('pre', ['style' =>
        'background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:16px;' .
        'font-size:13px;overflow-x:auto;']);
    echo htmlspecialchars(
        "// Multiple choice\n" .
        "What is 2 + 2? {\n" .
        "  =4\n" .
        "  ~3\n" .
        "  ~5\n" .
        "}\n\n" .
        "// True/False\n" .
        "The Earth is round.{TRUE}\n\n" .
        "// Short answer\n" .
        "What is the capital of France? {=Paris}\n\n" .
        "// Matching\n" .
        "Match the following. {\n" .
        "  =Cat -> Feline\n" .
        "  =Dog -> Canine\n" .
        "}"
    );
    echo html_writer::end_tag('pre');

    // Aiken format reference.
    echo html_writer::tag('h5', 'Aiken Format (CSV-like multiple choice)', ['class' => 'mt-3 mb-2']);
    echo html_writer::start_tag('pre', ['style' =>
        'background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:16px;' .
        'font-size:13px;overflow-x:auto;']);
    echo htmlspecialchars(
        "What is the color of the sky?\n" .
        "A) Red\n" .
        "B) Blue\n" .
        "C) Green\n" .
        "D) Yellow\n" .
        "ANSWER: B\n\n" .
        "How many days are in a week?\n" .
        "A) 5\n" .
        "B) 6\n" .
        "C) 7\n" .
        "D) 8\n" .
        "ANSWER: C"
    );
    echo html_writer::end_tag('pre');

    // Also show Moodle's full question import page link.
    echo html_writer::start_div('alert alert-secondary mt-3');
    echo 'For CSV format, use Moodle\'s built-in question import with the <strong>Moodle XML</strong> format, ' .
         'or use third-party tools to convert CSV to GIFT/XML first.';
    echo html_writer::tag('div', html_writer::link(
        new moodle_url('/question/bank/importquestions/import.php', ['courseid' => $course->id]),
        '📥 Open Full Question Import Page →',
        ['class' => 'btn btn-sm btn-outline-primary mt-2', 'target' => '_blank']
    ));
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
