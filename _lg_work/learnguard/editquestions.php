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
            "SELECT q.id, q.name, q.qtype, q.defaultmark, q.is_mandatory,
                    c.id AS catid, c.name AS catname
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

    // Toggle mandatory flag on a question.
    if ($action === 'togglemandatory' && $qid > 0) {
        $q = $DB->get_record('learnguard_qbank', ['id' => $qid], 'id, is_mandatory');
        if ($q) {
            $DB->set_field('learnguard_qbank', 'is_mandatory', $q->is_mandatory ? 0 : 1, ['id' => $qid]);
        }
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'bank']),
            'Mandatory status updated.', null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Bulk-add all questions from a category (or a random subset).
    if ($action === 'addcategory') {
        $catid    = optional_param('catid',    0,  PARAM_INT);
        $maxcount = optional_param('maxcount', 0,  PARAM_INT); // 0 = add all
        $catname  = optional_param('catname',  '', PARAM_TEXT);

        if ($catid > 0) {
            $catqs = array_values($DB->get_records('learnguard_qbank',
                ['categoryid' => $catid], 'id ASC', 'id, defaultmark'));
        } else {
            // "General" = questions with no category.
            $catqs = array_values($DB->get_records_select('learnguard_qbank',
                'categoryid IS NULL OR categoryid = 0', [], 'id ASC', 'id, defaultmark'));
        }

        if ($maxcount > 0 && count($catqs) > $maxcount) {
            shuffle($catqs);
            $catqs = array_slice($catqs, 0, $maxcount);
        }

        $added = 0;
        foreach ($catqs as $q) {
            if (!$DB->record_exists('learnguard_slots', ['learnguard' => $instance->id, 'questionid' => $q->id])) {
                $maxslot = (int)$DB->get_field_sql(
                    'SELECT COALESCE(MAX(slot), 0) FROM {learnguard_slots} WHERE learnguard = ?',
                    [$instance->id]
                );
                $DB->insert_record('learnguard_slots', (object)[
                    'learnguard' => $instance->id,
                    'questionid' => $q->id,
                    'slot'       => $maxslot + 1,
                    'maxmark'    => (float)($q->defaultmark ?: 1.0),
                    'page'       => 1,
                ]);
                $added++;
            }
        }
        $label = $maxcount > 0 ? "$added random question(s)" : "$added question(s)";
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'slots']),
            "$label added from '" . s($catname) . "'.",
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Save randomisation settings (randomize flag + num_questions).
    if ($action === 'setrandomize') {
        $randomize     = optional_param('randomize',     0, PARAM_INT);
        $num_questions = optional_param('num_questions', 0, PARAM_INT);
        $DB->set_field('learnguard', 'randomize',     $randomize > 0 ? 1 : 0, ['id' => $instance->id]);
        $DB->set_field('learnguard', 'num_questions', max(0, $num_questions),  ['id' => $instance->id]);
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'slots']),
            'Randomisation settings saved.', null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Bulk mark selected questions as mandatory (always included in randomised sets).
    if ($action === 'bulkmandatory') {
        $qids = optional_param_array('qids', [], PARAM_INT);
        $marked = 0;
        foreach ($qids as $bulkqid) {
            if ($bulkqid > 0) {
                $DB->set_field('learnguard_qbank', 'is_mandatory', 1, ['id' => $bulkqid]);
                $marked++;
            }
        }
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'bank']),
            "$marked question(s) marked as mandatory — they will always be included in randomised sets.",
            null, \core\output\notification::NOTIFY_SUCCESS
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

    // Set the Maximum Grade (scales all attempt scores to this value in Moodle gradebook).
    if ($action === 'setmaxgrade') {
        $newgrade = optional_param('maxgrade', 0.0, PARAM_FLOAT);
        if ($newgrade > 0) {
            $DB->set_field('learnguard', 'grade', $newgrade, ['id' => $instance->id]);
            // Update Moodle gradebook with the new grade scale.
            require_once($CFG->libdir . '/gradelib.php');
            $gradeitem = (object)[
                'itemname'   => $instance->name,
                'grademax'   => $newgrade,
                'grademin'   => 0,
            ];
            grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
                $instance->id, 0, null, $gradeitem);
        }
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'slots']),
            'Maximum grade updated to ' . $newgrade . '.', null, \core\output\notification::NOTIFY_SUCCESS
        );
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
           JOIN {learnguard_qbank} q ON q.id = s.questionid
          WHERE s.learnguard = :lgid
       ORDER BY s.slot ASC",
        ['lgid' => $instance->id]
    );

    $totalmarks = array_sum(array_column($slots ? array_values($slots) : [], 'maxmark'));

    // ---- Maximum Grade / grade conversion box ----
    echo html_writer::start_div('', ['style' =>
        'display:flex;align-items:center;gap:24px;flex-wrap:wrap;margin-bottom:18px;' .
        'background:#fffbe6;border:1px solid #ffc107;border-radius:8px;padding:14px 18px;']);
    // Maximum grade form.
    echo html_writer::start_tag('form', ['method' => 'post',
        'style' => 'display:flex;align-items:center;gap:8px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'setmaxgrade']);
    echo html_writer::tag('label', 'Maximum grade',
        ['style' => 'font-weight:700;color:#856404;white-space:nowrap;margin:0;']);
    echo html_writer::empty_tag('input', [
        'type'  => 'number', 'name' => 'maxgrade',
        'value' => number_format((float)$instance->grade, 2, '.', ''),
        'min'   => '0', 'step' => '0.01',
        'style' => 'width:90px;padding:5px 8px;border:1px solid #ccc;border-radius:6px;font-size:14px;',
    ]);
    echo html_writer::empty_tag('input', [
        'type'  => 'submit', 'value' => 'Save',
        'class' => 'btn btn-sm btn-primary',
        'style' => 'font-weight:700;',
    ]);
    echo html_writer::end_tag('form');
    // Total marks display.
    echo html_writer::tag('div',
        'Total of marks: <strong>' . number_format($totalmarks, 2, '.', '') . '</strong>',
        ['style' => 'font-size:13px;color:#856404;']);
    echo html_writer::tag('div',
        count($slots) . ' question(s)',
        ['style' => 'font-size:13px;color:#856404;']);
    echo html_writer::end_div();

    // ---- Randomisation Settings ----
    echo html_writer::start_div('', ['style' =>
        'display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin-bottom:18px;' .
        'background:#f0fff4;border:1px solid #28a745;border-radius:8px;padding:14px 18px;']);
    echo html_writer::start_tag('form', ['method' => 'post',
        'style' => 'display:flex;align-items:center;gap:16px;flex-wrap:wrap;width:100%;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'setrandomize']);
    echo html_writer::tag('span', '🎲 Randomisation', ['style' => 'font-weight:700;color:#155724;white-space:nowrap;']);
    $randChecked = !empty($instance->randomize) ? ['checked' => 'checked'] : [];
    echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:6px;']);
    echo html_writer::empty_tag('input', array_merge(
        ['type' => 'checkbox', 'name' => 'randomize', 'value' => '1',
         'id' => 'lg-rand-cb', 'style' => 'width:16px;height:16px;cursor:pointer;'],
        $randChecked));
    echo html_writer::tag('label', 'Randomise questions', ['for' => 'lg-rand-cb',
        'style' => 'margin:0;font-weight:600;font-size:14px;cursor:pointer;']);
    echo html_writer::end_div();
    echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:6px;']);
    echo html_writer::tag('span', 'Show', ['style' => 'font-size:14px;color:#555;white-space:nowrap;']);
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'name' => 'num_questions',
        'value' => (int)($instance->num_questions ?? 0),
        'min' => '0', 'step' => '1',
        'style' => 'width:70px;padding:5px 8px;border:1px solid #ccc;border-radius:6px;font-size:14px;',
        'title' => 'Number of questions to show per attempt (0 = show all)',
    ]);
    echo html_writer::tag('span', 'questions per attempt  (0 = show all)',
        ['style' => 'font-size:13px;color:#555;white-space:nowrap;']);
    echo html_writer::end_div();
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => 'Save',
        'class' => 'btn btn-sm btn-success', 'style' => 'font-weight:700;',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

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
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'id' => 'lg-bulk-action', 'value' => 'addmulti']);
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
            'onclick' => 'document.getElementById("lg-bulk-action").value="addmulti";' .
                'return document.querySelectorAll(".lg-bulk-cb:checked").length > 0 || alert("Select at least one question.");',
        ]);
        echo html_writer::tag('button', '📌 Make Selected Mandatory', [
            'type'    => 'submit',
            'class'   => 'btn btn-sm btn-warning',
            'title'   => 'Always include checked questions in randomised sets',
            'onclick' => 'if(!document.querySelectorAll(".lg-bulk-cb:checked").length){alert("Select at least one question.");return false;}' .
                'document.getElementById("lg-bulk-action").value="bulkmandatory";',
        ]);
        echo html_writer::end_div();

        $bycat = [];
        foreach ($questions as $q) {
            $key = ($q->catid ?? 0) . '|' . ($q->catname ?? 'General');
            $bycat[$key][] = $q;
        }

        // Category filter dropdown.
        echo html_writer::start_div('mb-2 d-flex align-items-center gap-2');
        echo html_writer::tag('label', 'Filter by category:', ['style' => 'font-size:13px;color:#6c757d;margin:0;white-space:nowrap;']);
        echo html_writer::start_tag('select', [
            'id'       => 'lg-catfilter',
            'class'    => 'form-control',
            'style'    => 'max-width:240px;font-size:13px;',
            'onchange' => 'var v=this.value;document.querySelectorAll(".lg-catgroup").forEach(function(g){' .
                'g.style.display=(v===""||g.getAttribute("data-catkey")===v)?"block":"none";});',
        ]);
        echo html_writer::tag('option', '— All categories —', ['value' => '']);
        foreach ($bycat as $ckey => $cqs) {
            [, $cname] = explode('|', $ckey, 2);
            echo html_writer::tag('option', htmlspecialchars(format_string($cname)) . ' (' . count($cqs) . ')', ['value' => $ckey]);
        }
        echo html_writer::end_tag('select');
        echo html_writer::end_div();

        foreach ($bycat as $key => $qs) {
            [$catidstr, $catname] = explode('|', $key, 2);
            $catid    = (int)$catidstr;
            $catcount = count($qs);

            echo html_writer::start_div('lg-catgroup', ['data-catkey' => $key]);

            // Category heading with count and bulk-import buttons.
            echo html_writer::start_div('', ['style' =>
                'display:flex;align-items:center;gap:10px;flex-wrap:wrap;' .
                'margin:16px 0 6px;border-bottom:1px solid #dee2e6;padding-bottom:6px;']);
            echo html_writer::tag('span', format_string($catname), ['style' =>
                'font-size:12px;font-weight:700;color:#6c757d;text-transform:uppercase;' .
                'letter-spacing:0.5px;flex-grow:1;']);
            echo html_writer::tag('span', $catcount . ' question' . ($catcount === 1 ? '' : 's'),
                ['style' => 'font-size:12px;color:#adb5bd;']);

            // "Add all from category" button.
            echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',       'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',  'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',   'value' => 'addcategory']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'catid',    'value' => $catid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'catname',  'value' => $catname]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'maxcount', 'value' => '0']);
            echo html_writer::empty_tag('input', ['type' => 'submit',
                'value' => '+ Add all ' . $catcount,
                'class' => 'btn btn-sm btn-outline-primary',
                'title' => 'Add all ' . $catcount . ' question' . ($catcount === 1 ? '' : 's') . ' from this category to the assessment']);
            echo html_writer::end_tag('form');

            // "Add N random" mini-form.
            echo html_writer::start_tag('form', ['method' => 'post',
                'style' => 'display:inline-flex;align-items:center;gap:4px;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'addcategory']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'catid',   'value' => $catid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'catname', 'value' => $catname]);
            echo html_writer::tag('span', 'Add', ['style' => 'font-size:13px;color:#555;white-space:nowrap;']);
            echo html_writer::empty_tag('input', [
                'type' => 'number', 'name' => 'maxcount',
                'min' => '1', 'max' => $catcount, 'value' => min(5, $catcount),
                'style' => 'width:55px;padding:3px 5px;border:1px solid #ccc;border-radius:4px;font-size:13px;',
                'title' => 'Number of random questions to pick from this category',
            ]);
            echo html_writer::tag('span', 'random', ['style' => 'font-size:13px;color:#555;white-space:nowrap;']);
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => '→',
                'class' => 'btn btn-sm btn-outline-secondary',
                'title' => 'Add this many randomly-selected questions from the category']);
            echo html_writer::end_tag('form');

            echo html_writer::end_div(); // category heading row

            foreach ($qs as $q) {
                $added = isset($alreadyadded[$q->id]);
                $rowStyle = !empty($q->is_mandatory)
                    ? 'background:#fff3cd;border-left:4px solid #ffc107;' . ($added ? 'opacity:0.7;' : '')
                    : ($added ? 'opacity:0.55;' : '');
                echo html_writer::start_div('lg-qbank-item', ['style' => $rowStyle]);

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
                $nameHtml = format_string($q->name);
                if (!empty($q->is_mandatory)) {
                    $nameHtml .= html_writer::tag('span', ' 📌 Mandatory',
                        ['style' => 'font-size:11px;font-weight:600;color:#dc3545;margin-left:6px;',
                         'title' => 'Always included in randomised sets']);
                }
                echo html_writer::tag('div', $nameHtml, ['style' => 'font-weight:600;font-size:14px;']);
                echo html_writer::tag('div', ucfirst($q->qtype) . ' · ' . $q->defaultmark . ' pt',
                    ['style' => 'font-size:12px;color:#6c757d;']);
                echo html_writer::end_div();

                // Mandatory toggle button (always visible, even if already added).
                echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;margin-right:4px;']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'togglemandatory']);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qid',     'value' => $q->id]);
                echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qtab',    'value' => 'bank']);
                $mandatoryLabel = !empty($q->is_mandatory) ? '📌 Mandatory' : '📌 Make Mandatory';
                $mandatoryClass = !empty($q->is_mandatory)
                    ? 'btn btn-sm btn-danger'
                    : 'btn btn-sm btn-outline-secondary';
                echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => $mandatoryLabel,
                    'class' => $mandatoryClass,
                    'title' => !empty($q->is_mandatory)
                        ? 'Click to remove mandatory — question will join the random pool'
                        : 'Click to make mandatory — always included even in randomised sets']);
                echo html_writer::end_tag('form');

                // Edit button (always visible).
                echo html_writer::link(
                    new moodle_url('/mod/learnguard/questionedit.php', ['id' => $id, 'qid' => $q->id]),
                    '✏️ Edit',
                    ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'margin-right:4px;']
                );

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
            echo html_writer::end_div(); // lg-catgroup
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

    // ---- LearnGuard CSV Importer (short answer + essay) — PRIMARY ----
    echo html_writer::start_div('', ['style' =>
        'border:2px solid #0d6efd;border-radius:10px;padding:20px;margin-bottom:28px;background:#f0f6ff;']);
    echo html_writer::tag('h5', '📊 LearnGuard CSV Bulk Import',
        ['style' => 'color:#0d6efd;margin-bottom:6px;']);
    echo html_writer::tag('p',
        'Use this importer to bulk-upload <strong>Short Answer</strong> and <strong>Essay</strong> ' .
        'questions directly into the LearnGuard Question Bank for this assessment. ' .
        'Questions are added instantly — no Moodle question bank or GIFT conversion needed.',
        ['style' => 'font-size:14px;margin-bottom:12px;']);

    echo html_writer::tag('h6', 'CSV Format', ['style' => 'font-weight:700;margin-bottom:4px;']);
    echo html_writer::start_tag('pre', ['style' =>
        'background:#fff;border:1px solid #b8d0fb;border-radius:6px;padding:14px;' .
        'font-size:12px;overflow-x:auto;margin-bottom:12px;']);
    echo htmlspecialchars(
        "type,question,a1,a2,a3,a4,correct,marks,case_sensitive,marking_guide\n" .
        "shortanswer,What is the capital of France?,,,,,Paris,1,0,\n" .
        "shortanswer,Spell the colour of grass.,,,,,green|grene,2,0,Accept common misspelling\n" .
        "essay,Discuss the impact of climate change.,,,,,,,0,Focus on economic + environmental effects\n"
    );
    echo html_writer::end_tag('pre');

    echo html_writer::start_tag('ul', ['style' => 'font-size:13px;margin:0 0 14px 16px;']);
    echo html_writer::tag('li', '<strong>type</strong> — <code>shortanswer</code> or <code>essay</code>');
    echo html_writer::tag('li', '<strong>a1–a4</strong> — leave blank for short answer/essay');
    echo html_writer::tag('li', '<strong>correct</strong> — accepted answer(s); use <code>|</code> to separate variants for short answer');
    echo html_writer::tag('li', '<strong>marks</strong> — default marks (e.g. <code>1</code>). Leave blank to use the global default.');
    echo html_writer::tag('li', '<strong>case_sensitive</strong> — <code>1</code> = exact case required, <code>0</code> = ignore case');
    echo html_writer::tag('li', '<strong>marking_guide</strong> — grader-only note shown above the essay response (optional)');
    echo html_writer::end_tag('ul');

    echo html_writer::link(
        new moodle_url('/mod/learnguard/questionimport.php', ['id' => $id]),
        '📊 Open LearnGuard CSV Importer →',
        ['class' => 'btn btn-primary']
    );
    echo html_writer::end_div();

    // ---- Moodle Question Bank Formats (MCQ / TF / etc.) ----
    echo html_writer::tag('h5', 'Other Question Types (MCQ, True/False, etc.)',
        ['class' => 'mb-2', 'style' => 'color:#495057;']);
    echo html_writer::start_div('alert alert-secondary');
    echo 'For multiple choice and true/false questions, use the <strong>Question Bank</strong> tab to add ' .
         'questions individually, or import into Moodle\'s native question bank using GIFT / Moodle XML / Aiken format, ' .
         'then add them via the Question Bank tab.';
    echo html_writer::end_div();

    echo html_writer::start_div('d-flex gap-2 flex-wrap mb-4');
    $importformats = [
        'gift'        => ['label' => '✏️ GIFT Format',     'desc' => 'Multiple choice, short answer, T/F'],
        'aiken'       => ['label' => '📃 Aiken Format',    'desc' => 'Simple multiple choice (plain text)'],
        'shortanswer' => ['label' => '📝 Short Answer',    'desc' => 'Fill-in-the-blank questions with accepted answer variants'],
        'essay'       => ['label' => '✍️ Essay',           'desc' => 'Essay questions with optional marking guide'],
        'csv'         => ['label' => '📊 LearnGuard CSV',  'desc' => 'All question types in spreadsheet format'],
    ];
    foreach ($importformats as $fmt => $info) {
        echo html_writer::start_div('', ['style' =>
            'border:1px solid #dee2e6;border-radius:8px;padding:16px;min-width:200px;max-width:220px;']);
        echo html_writer::tag('div', $info['label'], ['style' => 'font-weight:600;margin-bottom:4px;']);
        echo html_writer::tag('div', $info['desc'], ['style' => 'font-size:12px;color:#6c757d;margin-bottom:10px;']);
        echo html_writer::link(
            new moodle_url('/mod/learnguard/questionimport.php', ['id' => $id, 'fmt' => $fmt]),
            'Import →',
            ['class' => 'btn btn-sm btn-primary']
        );
        echo html_writer::end_div();
    }
    echo html_writer::end_div();
}

echo $OUTPUT->footer();
