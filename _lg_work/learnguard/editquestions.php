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
            if ($bulkqid > 0 && !$DB->record_exists_select('learnguard_slots',
                    'learnguard = :lgid AND questionid = :qid AND attemptid = 0',
                    ['lgid' => $instance->id, 'qid' => $bulkqid])) {
                $maxslot = (int)$DB->get_field_sql(
                    'SELECT COALESCE(MAX(slot), 0) FROM {learnguard_slots} WHERE learnguard = ? AND attemptid = 0',
                    [$instance->id]
                );
                $q = $DB->get_record('learnguard_qbank', ['id' => $bulkqid]);
                $DB->insert_record('learnguard_slots', (object)[
                    'learnguard' => $instance->id, 'questionid' => $bulkqid,
                    'slot'       => $maxslot + 1,
                    'maxmark'    => $q ? (float)$q->defaultmark : 1.0,
                    'page'       => 1,
                    'attemptid'  => 0,
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
        $returntab = optional_param('returntab', 'bank', PARAM_ALPHA);
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => $returntab]),
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
            if (!$DB->record_exists_select('learnguard_slots',
                    'learnguard = :lgid AND questionid = :qid AND attemptid = 0',
                    ['lgid' => $instance->id, 'qid' => $q->id])) {
                $maxslot = (int)$DB->get_field_sql(
                    'SELECT COALESCE(MAX(slot), 0) FROM {learnguard_slots} WHERE learnguard = ? AND attemptid = 0',
                    [$instance->id]
                );
                $DB->insert_record('learnguard_slots', (object)[
                    'learnguard' => $instance->id,
                    'questionid' => $q->id,
                    'slot'       => $maxslot + 1,
                    'maxmark'    => (float)($q->defaultmark ?: 1.0),
                    'page'       => 1,
                    'attemptid'  => 0,
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
        $qids      = optional_param_array('qids', [], PARAM_INT);
        $returntab = optional_param('returntab', 'bank', PARAM_ALPHA);
        $marked = 0;
        foreach ($qids as $bulkqid) {
            if ($bulkqid > 0) {
                $DB->set_field('learnguard_qbank', 'is_mandatory', 1, ['id' => $bulkqid]);
                $marked++;
            }
        }
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => $returntab]),
            "$marked question(s) marked as mandatory — they will always be included in randomised sets.",
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'add' && $qid > 0) {
        if (!$DB->record_exists_select('learnguard_slots',
                'learnguard = :lgid AND questionid = :qid AND attemptid = 0',
                ['lgid' => $instance->id, 'qid' => $qid])) {
            $maxslot = (int)$DB->get_field_sql(
                'SELECT COALESCE(MAX(slot), 0) FROM {learnguard_slots} WHERE learnguard = ? AND attemptid = 0',
                [$instance->id]
            );
            $q = $DB->get_record('learnguard_qbank', ['id' => $qid]);
            $DB->insert_record('learnguard_slots', (object)[
                'learnguard'  => $instance->id,
                'questionid'  => $qid,
                'slot'        => $maxslot + 1,
                'maxmark'     => $q ? (float)$q->defaultmark : 1.0,
                'page'        => 1,
                'attemptid'   => 0,
            ]);
        }
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }

    if ($action === 'remove' && $slotid > 0) {
        $DB->delete_records_select('learnguard_slots',
            'id = :sid AND learnguard = :lgid AND attemptid = 0',
            ['sid' => $slotid, 'lgid' => $instance->id]);
        $remaining = $DB->get_records_select('learnguard_slots',
            'learnguard = :lgid AND attemptid = 0', ['lgid' => $instance->id], 'slot ASC');
        $n = 1;
        foreach ($remaining as $row) { $DB->set_field('learnguard_slots', 'slot', $n++, ['id' => $row->id]); }
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]));
    }

    if ($action === 'bulkremove') {
        $slotids = optional_param_array('slotids', [], PARAM_INT);
        $removed = 0;
        foreach ($slotids as $sid) {
            if ($sid > 0) {
                $DB->delete_records_select('learnguard_slots',
                    'id = :sid AND learnguard = :lgid AND attemptid = 0',
                    ['sid' => $sid, 'lgid' => $instance->id]);
                $removed++;
            }
        }
        $remaining = $DB->get_records_select('learnguard_slots',
            'learnguard = :lgid AND attemptid = 0', ['lgid' => $instance->id], 'slot ASC');
        $n = 1;
        foreach ($remaining as $row) { $DB->set_field('learnguard_slots', 'slot', $n++, ['id' => $row->id]); }
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'slots']),
            "$removed question(s) removed from assessment.", null, \core\output\notification::NOTIFY_SUCCESS
        );
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
            'SELECT MAX(slot) FROM {learnguard_slots} WHERE learnguard = ? AND attemptid = 0', [$instance->id]
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

    // Add a question bank pool rule to this assessment.
    if ($action === 'addpool') {
        $poolcatid    = optional_param('poolcatid',    0,   PARAM_INT);
        $pooldraw     = optional_param('pooldraw',     1,   PARAM_INT);
        $poolmaxmark  = optional_param('poolmaxmark',  1.0, PARAM_FLOAT);
        $pooltimemin  = optional_param('pooltimemin',  0,   PARAM_INT);
        if ($poolcatid > 0 && $DB->record_exists('learnguard_qbank_cats', ['id' => $poolcatid])) {
            $pooldraw    = max(1, $pooldraw);
            $poolmaxmark = max(0.0, $poolmaxmark);
            $nextsort    = (int)$DB->get_field_sql(
                'SELECT COALESCE(MAX(sortorder), 0) FROM {learnguard_pools} WHERE learnguard = ?',
                [$instance->id]
            );
            $DB->insert_record('learnguard_pools', (object)[
                'learnguard'  => $instance->id,
                'catid'       => $poolcatid,
                'drawcount'   => $pooldraw,
                'maxmark'     => $poolmaxmark,
                'timelimit'   => max(0, (int)$pooltimemin) * 60,
                'sortorder'   => $nextsort + 1,
                'timecreated' => time(),
            ]);
            redirect(
                new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'pools']),
                'Pool bank added.', null, \core\output\notification::NOTIFY_SUCCESS
            );
        }
        redirect(new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'pools']));
    }

    // Toggle the section-breaks UI on/off for this assessment.
    if ($action === 'toggle_section_breaks') {
        $current = (int)$DB->get_field('learnguard', 'show_section_breaks', ['id' => $instance->id]);
        $DB->set_field('learnguard', 'show_section_breaks', $current ? 0 : 1, ['id' => $instance->id]);
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'pools']),
            $current ? 'Section breaks disabled — questions shown as one continuous stream.'
                     : 'Section breaks enabled — students will see each bank name before its questions.',
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Remove a pool rule from this assessment.
    if ($action === 'removepool') {
        $poolid = optional_param('poolid', 0, PARAM_INT);
        if ($poolid > 0) {
            $DB->delete_records('learnguard_pools', ['id' => $poolid, 'learnguard' => $instance->id]);
        }
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'pools']),
            'Pool bank removed.', null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Update draw count, maxmark, or timelimit for an existing pool rule.
    if ($action === 'editpool') {
        $poolid      = optional_param('poolid',     0,   PARAM_INT);
        $pooldraw    = optional_param('pooldraw',   1,   PARAM_INT);
        $poolmaxmark = optional_param('poolmaxmark',1.0, PARAM_FLOAT);
        $pooltimemin = optional_param('pooltimemin',0,   PARAM_INT);
        if ($poolid > 0) {
            $DB->set_field('learnguard_pools', 'drawcount', max(1, $pooldraw),        ['id' => $poolid, 'learnguard' => $instance->id]);
            $DB->set_field('learnguard_pools', 'maxmark',   max(0.0, $poolmaxmark),   ['id' => $poolid, 'learnguard' => $instance->id]);
            $DB->set_field('learnguard_pools', 'timelimit', max(0, (int)$pooltimemin) * 60, ['id' => $poolid, 'learnguard' => $instance->id]);
        }
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'pools']),
            'Pool updated.', null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    // Set the Maximum Grade (scales all attempt scores to this value in Moodle gradebook).
    if ($action === 'setmaxgrade') {
        $newgrade = optional_param('maxgrade', 0.0, PARAM_FLOAT);
        if ($newgrade > 0) {
            $DB->set_field('learnguard', 'grade', $newgrade, ['id' => $instance->id]);
            // Update Moodle gradebook with the new grade scale.
            require_once($CFG->libdir . '/gradelib.php');
            grade_update('mod/learnguard', $instance->course, 'mod', 'learnguard',
                $instance->id, 0, null, [
                    'itemname' => $instance->name,
                    'grademax' => (float)$newgrade,
                    'grademin' => 0,
                ]);
        }
        $returntab = optional_param('qtab', 'slots', PARAM_ALPHA);
        redirect(
            new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => $returntab]),
            'Maximum grade updated to ' . $newgrade . '.', null, \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

echo $OUTPUT->header();
echo learnguard_nav_bar($id, 'Manage Questions');

echo html_writer::tag('h2', '📝 Manage Questions — ' . format_string($instance->name));

// ---- Tabs ----
$qtab = optional_param('qtab', 'slots', PARAM_ALPHA);
$tabs = ['slots' => '📋 Fixed Questions', 'pools' => '🎲 Question Bank Pools', 'bank' => '🏦 Question Bank', 'import' => '📥 Import Questions'];
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
        "SELECT s.*, q.name AS qname, q.qtype, q.is_mandatory
           FROM {learnguard_slots} s
           JOIN {learnguard_qbank} q ON q.id = s.questionid
          WHERE s.learnguard = :lgid AND s.attemptid = 0
       ORDER BY s.slot ASC",
        ['lgid' => $instance->id]
    );

    // Pre-compute marks and randomisation data so the grade box can show per-attempt figures.
    $totalmarks     = 0.0;
    foreach ($slots as $s) { $totalmarks += (float)$s->maxmark; }

    $randomize      = !empty($instance->randomize);
    $numshow        = (int)($instance->num_questions ?? 0);
    $mandatorySlots = [];
    $optionalSlots  = [];
    if ($randomize) {
        foreach ($slots as $s) {
            if (!empty($s->is_mandatory)) { $mandatorySlots[] = $s; }
            else                          { $optionalSlots[]  = $s; }
        }
        $mandatoryCount = count($mandatorySlots);
        $totalShown     = $numshow > 0 ? $numshow : count($slots);
        $randomCount    = max(0, $totalShown - $mandatoryCount);
        $mandatoryMarks = 0.0;
        foreach ($mandatorySlots as $ms) { $mandatoryMarks += (float)$ms->maxmark; }
        $optAvg = count($optionalSlots) > 0
            ? array_sum(array_map(function ($s) { return (float)$s->maxmark; }, $optionalSlots)) / count($optionalSlots)
            : 0.0;
        $displaytotalmarks = $mandatoryMarks + ($optAvg * $randomCount);
        $displaycount      = $totalShown;
    } else {
        $mandatoryCount    = 0;
        $totalShown        = count($slots);
        $randomCount       = 0;
        $displaytotalmarks = $totalmarks;
        $displaycount      = count($slots);
    }

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
        'Total of marks: <strong>' . number_format($displaytotalmarks, 2, '.', '') . '</strong>' .
        ($randomize && $numshow > 0 ? ' <small style="color:#a0800d;">(expected per attempt)</small>' : ''),
        ['style' => 'font-size:13px;color:#856404;']);
    echo html_writer::tag('div',
        $displaycount . ' question(s)' . ($randomize ? ' per attempt' : ''),
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
        if ($randomize) {
            $totalPool = count($slots);

            // Info banner.
            echo html_writer::start_div('alert alert-info mb-3', ['style' => 'border-left:4px solid #0d6efd;margin-bottom:16px;']);
            echo '🎲 <strong>Randomised Assessment</strong> — Each student sees <strong>' . $totalShown . ' question(s)</strong> per attempt: ';
            if ($mandatoryCount > 0) {
                echo '<strong>' . $mandatoryCount . ' mandatory</strong> (always shown) + ' .
                     '<strong>' . $randomCount . ' randomly selected</strong> from ' . count($optionalSlots) . ' optional question(s) in the pool.';
            } else {
                echo '<strong>' . $randomCount . ' randomly selected</strong> from the pool of <strong>' . $totalPool . '</strong>.';
            }
            echo html_writer::end_div();

            // Per-attempt preview — mirrors Moodle's "Random (Category)" slot view.
            echo html_writer::tag('h6', '🎯 What each student\'s attempt looks like (' . $totalShown . ' slots)',
                ['style' => 'font-weight:700;margin-bottom:8px;']);
            echo html_writer::start_tag('table', ['class' => 'generaltable w-100', 'style' => 'margin-bottom:24px;']);
            echo html_writer::start_tag('thead');
            echo html_writer::start_tag('tr');
            foreach (['Slot', 'Question', 'Type', 'Marks'] as $h) {
                echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;background:#e9ecef;']);
            }
            echo html_writer::end_tag('tr');
            echo html_writer::end_tag('thead');
            echo html_writer::start_tag('tbody');

            $slotNum = 1;
            // Mandatory rows — shown by name (always included).
            foreach ($mandatorySlots as $ms) {
                echo html_writer::start_tag('tr', ['style' => 'background:#fff3cd;']);
                echo html_writer::tag('td', $slotNum++,
                    ['style' => 'padding:8px 10px;text-align:center;font-weight:700;color:#856404;width:40px;']);
                echo html_writer::start_tag('td', ['style' => 'padding:8px 10px;']);
                echo html_writer::tag('div', '📌 ' . format_string($ms->qname), ['style' => 'font-weight:600;']);
                echo html_writer::tag('div', 'Always included — mandatory', ['style' => 'font-size:12px;color:#856404;']);
                echo html_writer::end_tag('td');
                echo html_writer::tag('td', html_writer::tag('span', strtoupper($ms->qtype),
                    ['style' => 'background:#e9ecef;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;']));
                echo html_writer::tag('td', number_format((float)$ms->maxmark, 2),
                    ['style' => 'padding:8px 10px;']);
                echo html_writer::end_tag('tr');
            }
            // Random placeholder rows — one per optional slot that will be shown.
            for ($i = 0; $i < $randomCount; $i++) {
                echo html_writer::start_tag('tr', ['style' => 'background:#f0f6ff;']);
                echo html_writer::tag('td', $slotNum++,
                    ['style' => 'padding:8px 10px;text-align:center;font-weight:700;color:#0d6efd;width:40px;']);
                echo html_writer::start_tag('td', ['style' => 'padding:8px 10px;']);
                echo html_writer::tag('div', '🎲 Random question from optional pool',
                    ['style' => 'font-weight:600;color:#0d6efd;']);
                echo html_writer::tag('div', 'Randomly selected per student at attempt start  (pool: ' . count($optionalSlots) . ' optional questions)',
                    ['style' => 'font-size:12px;color:#6c757d;']);
                echo html_writer::end_tag('td');
                echo html_writer::tag('td', html_writer::tag('span', 'RANDOM',
                    ['style' => 'background:#cfe2ff;padding:2px 6px;border-radius:4px;font-size:11px;font-weight:700;color:#0d6efd;']));
                echo html_writer::tag('td', '—', ['style' => 'padding:8px 10px;color:#6c757d;']);
                echo html_writer::end_tag('tr');
            }

            echo html_writer::end_tag('tbody');
            echo html_writer::end_tag('table');

            // Pool heading above the management table.
            echo html_writer::tag('h6',
                '📋 Question Pool — ' . $totalPool . ' total' .
                ($mandatoryCount > 0
                    ? ' (' . $mandatoryCount . ' mandatory · ' . count($optionalSlots) . ' optional)'
                    : ''),
                ['style' => 'font-weight:700;margin-bottom:8px;color:#6c757d;']);
        }

        // Bulk toolbar.
        echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#f8f9fa;'
            . 'padding:8px 12px;border-radius:6px;margin-bottom:10px;border:1px solid #dee2e6;">';
        echo '<button type="button" onclick="lgSlotsSelectAll(true)" class="btn btn-sm btn-outline-secondary">☑ All</button>';
        echo '<button type="button" onclick="lgSlotsSelectAll(false)" class="btn btn-sm btn-outline-secondary">☐ None</button>';
        echo '<span id="lg-slots-count" style="font-size:12px;color:#6c757d;">0 selected</span>';
        echo '<div style="border-left:1px solid #dee2e6;height:20px;margin:0 2px;"></div>';
        echo '<button type="button" onclick="lgSlotsMakeMandatory()" class="btn btn-sm btn-warning" title="Always include selected questions, even in randomised sets">📌 Make Mandatory</button>';
        echo '<button type="button" onclick="lgSlotsRemoveSelected()" class="btn btn-sm btn-danger">🗑 Remove Selected</button>';
        echo '</div>';

        echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th',
            '<input type="checkbox" id="lg-slots-chk-all" onclick="lgSlotsSelectAll(this.checked)" style="width:16px;height:16px;">',
            ['style' => 'padding:8px 10px;width:36px;']);
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
            echo html_writer::start_tag('tr', ['id' => 'slotrow-' . $row->id]);
            echo html_writer::tag('td',
                '<input type="checkbox" class="lg-slot-chk" value="' . (int)$row->id . '"'
                . ' data-qid="' . (int)$row->questionid . '"'
                . ' onchange="lgSlotsUpdateCount()" style="width:16px;height:16px;">',
                ['style' => 'padding:8px 10px;']);
            echo html_writer::tag('td', $row->slot, ['style' => 'font-weight:700;color:#6c757d;width:40px;text-align:center;']);
            echo html_writer::start_tag('td');
            echo html_writer::tag('div',
                format_string($row->qname) .
                (!empty($row->is_mandatory)
                    ? ' <span style="font-size:11px;font-weight:600;color:#856404;background:#fff3cd;padding:1px 6px;border-radius:4px;margin-left:6px;">📌 Mandatory</span>'
                    : ''),
                ['style' => 'font-weight:600;']);
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

            // Actions cell: mandatory toggle + remove.
            echo html_writer::start_tag('td', ['style' => 'white-space:nowrap;']);
            // Mandatory toggle (per-row).
            echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;margin-right:4px;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',        'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',   'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',    'value' => 'togglemandatory']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qid',       'value' => $row->questionid]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'returntab', 'value' => 'slots']);
            echo html_writer::empty_tag('input', [
                'type'  => 'submit',
                'value' => !empty($row->is_mandatory) ? '📌 Mandatory' : '📌 Make Mandatory',
                'class' => !empty($row->is_mandatory) ? 'btn btn-sm btn-warning' : 'btn btn-sm btn-outline-secondary',
                'title' => !empty($row->is_mandatory) ? 'Click to remove mandatory flag' : 'Always include in randomised sets',
            ]);
            echo html_writer::end_tag('form');
            // Remove button.
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

    // ---- Inline "Add Questions from Bank" widget ----
    $addcats = $DB->get_records_sql(
        "SELECT c.id, c.name, COUNT(q.id) AS qcount
           FROM {learnguard_qbank_cats} c
      LEFT JOIN {learnguard_qbank} q ON q.categoryid = c.id
       GROUP BY c.id, c.name
      HAVING COUNT(q.id) > 0
       ORDER BY c.name ASC"
    );
    $addallqs = learnguard_load_bank_questions();
    // Build JS data: {catid: [{id,name,qtype,marks},...]}
    $jsqdata = [];
    foreach ($addallqs as $aq) {
        $cid = (int)($aq->catid ?? 0);
        $jsqdata[$cid][] = [
            'id'    => (int)$aq->id,
            'name'  => s($aq->name),
            'qtype' => s($aq->qtype),
            'marks' => (float)($aq->defaultmark ?? 1),
        ];
    }

    echo '<div style="margin-top:28px;border:2px dashed #28a745;border-radius:10px;overflow:hidden;">';
    echo '<div style="background:#d4edda;padding:10px 16px;display:flex;align-items:center;gap:12px;cursor:pointer;" '
       . 'onclick="lgAFBToggle()" id="lg-afb-header">';
    echo '<span style="font-size:15px;font-weight:700;color:#155724;">➕ Add Questions from Bank</span>';
    echo '<span id="lg-afb-arrow" style="color:#155724;font-size:13px;">▼ expand</span>';
    echo '</div>';
    echo '<div id="lg-afb-body" style="display:none;padding:16px;">';

    // Category selector.
    echo '<div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-bottom:12px;">';
    echo '<label style="font-weight:600;margin:0;">Category:</label>';
    echo '<select id="lg-afb-cat" onchange="lgAFBLoadCat(this.value)" '
       . 'class="form-control form-control-sm" style="max-width:280px;">';
    echo '<option value="">— select a category —</option>';
    foreach ($addcats as $cat) {
        echo '<option value="' . (int)$cat->id . '">' . s($cat->name) . ' (' . (int)$cat->qcount . ')</option>';
    }
    echo '</select>';
    echo '<input type="text" id="lg-afb-search" placeholder="Search questions..." '
       . 'class="form-control form-control-sm" style="max-width:220px;" '
       . 'oninput="lgAFBFilter()" disabled>';
    echo '</div>';

    // Question list area.
    echo '<div id="lg-afb-list" style="max-height:340px;overflow-y:auto;border:1px solid #dee2e6;'
       . 'border-radius:6px;background:#fff;">';
    echo '<div style="padding:24px;text-align:center;color:#adb5bd;font-size:13px;">Select a category above to browse questions.</div>';
    echo '</div>';

    // Action bar.
    echo '<div style="display:flex;gap:8px;align-items:center;margin-top:10px;">';
    echo '<button type="button" onclick="lgAFBSelectAll(true)" class="btn btn-sm btn-outline-secondary">☑ All</button>';
    echo '<button type="button" onclick="lgAFBSelectAll(false)" class="btn btn-sm btn-outline-secondary">☐ None</button>';
    echo '<span id="lg-afb-count" style="font-size:12px;color:#6c757d;">0 selected</span>';
    echo '<button type="button" onclick="lgAFBAddSelected()" class="btn btn-sm btn-success" '
       . 'title="Add checked questions as fixed slots">➕ Add Selected</button>';
    echo '<button type="button" onclick="lgAFBAddCategory()" class="btn btn-sm btn-outline-success" '
       . 'title="Add all questions in the selected category">📋 Add Entire Category</button>';
    echo '</div>';

    // Hidden forms for submitting.
    echo '<form method="post" id="lg-afb-form-multi" style="display:none;">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="action" value="addmulti">';
    echo '<div id="lg-afb-hidden-qids"></div>';
    echo '</form>';
    echo '<form method="post" id="lg-afb-form-cat" style="display:none;">';
    echo '<input type="hidden" name="id" value="' . $id . '">';
    echo '<input type="hidden" name="sesskey" value="' . sesskey() . '">';
    echo '<input type="hidden" name="action" value="addcategory">';
    echo '<input type="hidden" name="catid" id="lg-afb-catid-hidden" value="">';
    echo '<input type="hidden" name="catname" id="lg-afb-catname-hidden" value="">';
    echo '</form>';

    echo '</div></div>'; // close lg-afb-body and wrapper

    // JS for the widget.
    $jsQDataJson = json_encode($jsqdata, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
    echo '<script>
var lgAfbData = ' . $jsQDataJson . ';
var lgAfbOpen = false;
function lgAFBToggle() {
    lgAfbOpen = !lgAfbOpen;
    document.getElementById("lg-afb-body").style.display = lgAfbOpen ? "" : "none";
    document.getElementById("lg-afb-arrow").textContent = lgAfbOpen ? "▲ collapse" : "▼ expand";
}
function lgAFBLoadCat(catid) {
    var list = document.getElementById("lg-afb-list");
    var search = document.getElementById("lg-afb-search");
    document.getElementById("lg-afb-catid-hidden").value = catid;
    var sel = document.getElementById("lg-afb-cat");
    document.getElementById("lg-afb-catname-hidden").value = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : "";
    if (!catid) {
        list.innerHTML = "<div style=\"padding:24px;text-align:center;color:#adb5bd;font-size:13px;\">Select a category above to browse questions.</div>";
        search.disabled = true; search.value = "";
        lgAFBUpdateCount();
        return;
    }
    search.disabled = false; search.value = "";
    var qs = lgAfbData[catid] || [];
    if (!qs.length) {
        list.innerHTML = "<div style=\"padding:16px;color:#6c757d;\">No questions in this category.</div>";
        lgAFBUpdateCount();
        return;
    }
    var html = "<table style=\"width:100%;border-collapse:collapse;\">";
    html += "<thead><tr style=\"background:#f8f9fa;\"><th style=\"padding:6px 10px;width:32px;\"></th><th style=\"padding:6px 10px;\">Question</th><th style=\"padding:6px 10px;width:90px;\">Type</th><th style=\"padding:6px 10px;width:70px;\">Marks</th></tr></thead><tbody>";
    qs.forEach(function(q) {
        html += "<tr class=\"lg-afb-row\" data-name=\"" + q.name.toLowerCase() + "\">";
        html += "<td style=\"padding:6px 10px;\"><input type=\"checkbox\" class=\"lg-afb-chk\" value=\"" + q.id + "\" onchange=\"lgAFBUpdateCount()\"></td>";
        html += "<td style=\"padding:6px 10px;font-size:13px;\">" + q.name + "</td>";
        html += "<td style=\"padding:6px 10px;\"><span style=\"background:#e9ecef;padding:1px 5px;border-radius:3px;font-size:11px;font-weight:700;\">" + q.qtype.toUpperCase() + "</span></td>";
        html += "<td style=\"padding:6px 10px;font-size:13px;\">" + q.marks.toFixed(2) + "</td>";
        html += "</tr>";
    });
    html += "</tbody></table>";
    list.innerHTML = html;
    lgAFBUpdateCount();
}
function lgAFBFilter() {
    var term = document.getElementById("lg-afb-search").value.toLowerCase();
    document.querySelectorAll(".lg-afb-row").forEach(function(row) {
        row.style.display = (!term || row.dataset.name.indexOf(term) !== -1) ? "" : "none";
    });
}
function lgAFBUpdateCount() {
    var n = document.querySelectorAll(".lg-afb-chk:checked").length;
    document.getElementById("lg-afb-count").textContent = n + " selected";
}
function lgAFBSelectAll(val) {
    document.querySelectorAll(".lg-afb-chk").forEach(function(chk) {
        if (chk.closest("tr").style.display !== "none") chk.checked = val;
    });
    lgAFBUpdateCount();
}
function lgAFBAddSelected() {
    var chks = document.querySelectorAll(".lg-afb-chk:checked");
    if (!chks.length) { alert("Select at least one question first."); return; }
    var hidden = document.getElementById("lg-afb-hidden-qids");
    hidden.innerHTML = "";
    chks.forEach(function(chk) {
        var inp = document.createElement("input");
        inp.type = "hidden"; inp.name = "qids[]"; inp.value = chk.value;
        hidden.appendChild(inp);
    });
    document.getElementById("lg-afb-form-multi").submit();
}
function lgAFBAddCategory() {
    var catid = document.getElementById("lg-afb-cat").value;
    if (!catid) { alert("Select a category first."); return; }
    var catname = document.getElementById("lg-afb-catname-hidden").value;
    if (!confirm("Add all questions from \"" + catname + "\" as fixed slots?")) return;
    document.getElementById("lg-afb-form-cat").submit();
}
</script>';
}

// =====================================================================
// TAB: Question Bank Pools
// =====================================================================
if ($qtab === 'pools') {

    // Load existing pool rules for this assessment.
    $poolrules = $DB->get_records_sql(
        "SELECT p.*, c.name AS catname,
                (SELECT COUNT(*) FROM {learnguard_qbank} WHERE categoryid = p.catid) AS qcount
           FROM {learnguard_pools} p
           JOIN {learnguard_qbank_cats} c ON c.id = p.catid
          WHERE p.learnguard = :lgid
       ORDER BY p.sortorder ASC, p.id ASC",
        ['lgid' => $instance->id]
    );

    // All available question bank categories.
    $allcats = $DB->get_records_sql(
        "SELECT c.id, c.name,
                COUNT(q.id) AS qcount
           FROM {learnguard_qbank_cats} c
      LEFT JOIN {learnguard_qbank} q ON q.categoryid = c.id
       GROUP BY c.id, c.name
       ORDER BY c.name ASC"
    );

    // Compute per-attempt totals for the info bar.
    $fixedcount = $DB->count_records_select('learnguard_slots',
        'learnguard = :lgid AND attemptid = 0', ['lgid' => $instance->id]);
    $pooldrawn  = 0;
    foreach ($poolrules as $pr) { $pooldrawn += (int)$pr->drawcount; }
    $totalperatt = $fixedcount + $pooldrawn;

    // Info bar.
    echo html_writer::start_div('', ['style' =>
        'background:#e8f4fd;border:1px solid #bee5eb;border-radius:8px;padding:14px 18px;margin-bottom:20px;']);
    echo html_writer::tag('strong', '📊 Per-student attempt composition:', ['style' => 'display:block;margin-bottom:6px;']);
    echo html_writer::tag('span',
        '📋 Fixed questions: <strong>' . $fixedcount . '</strong> &nbsp;|&nbsp; ' .
        '🎲 Pool-drawn questions: <strong>' . $pooldrawn . '</strong> &nbsp;|&nbsp; ' .
        '✅ Total per student: <strong>' . $totalperatt . '</strong>',
        ['style' => 'font-size:14px;color:#0c5460;']);
    echo html_writer::end_div();

    // ---- Maximum Grade box (same as Fixed Questions tab) ----
    $fixedTotalMarks = (float)$DB->get_field_sql(
        'SELECT COALESCE(SUM(maxmark), 0) FROM {learnguard_slots} WHERE learnguard = ? AND attemptid = 0',
        [$instance->id]
    );
    $poolTotalMarks = (float)$DB->get_field_sql(
        'SELECT COALESCE(SUM(drawcount * maxmark), 0) FROM {learnguard_pools} WHERE learnguard = ?',
        [$instance->id]
    );
    $combinedTotalMarks = $fixedTotalMarks + $poolTotalMarks;

    echo html_writer::start_div('', ['style' =>
        'display:flex;align-items:center;gap:24px;flex-wrap:wrap;margin-bottom:18px;' .
        'background:#fffbe6;border:1px solid #ffc107;border-radius:8px;padding:14px 18px;']);
    echo html_writer::start_tag('form', ['method' => 'post',
        'style' => 'display:flex;align-items:center;gap:8px;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'setmaxgrade']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qtab',    'value' => 'pools']);
    echo html_writer::tag('label', 'Maximum grade',
        ['style' => 'font-weight:700;color:#856404;white-space:nowrap;margin:0;']);
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'name' => 'maxgrade',
        'value' => number_format((float)$instance->grade, 2, '.', ''),
        'min' => '0', 'step' => '0.01',
        'style' => 'width:90px;padding:5px 8px;border:1px solid #ccc;border-radius:6px;font-size:14px;',
    ]);
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => 'Save',
        'class' => 'btn btn-sm btn-primary', 'style' => 'font-weight:700;',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::tag('div',
        'Total of marks: <strong>' . number_format($combinedTotalMarks, 2, '.', '') . '</strong>' .
        ' <small style="color:#a0800d;">(fixed + pool combined)</small>',
        ['style' => 'font-size:13px;color:#856404;']);
    echo html_writer::tag('div',
        $totalperatt . ' question(s) per student attempt',
        ['style' => 'font-size:13px;color:#856404;']);
    echo html_writer::end_div();

    // ---- Randomisation Settings (applies to fixed questions) ----
    $randomize_pools = !empty($instance->randomize);
    echo html_writer::start_div('', ['style' =>
        'display:flex;align-items:center;gap:20px;flex-wrap:wrap;margin-bottom:18px;' .
        'background:#f0fff4;border:1px solid #28a745;border-radius:8px;padding:14px 18px;']);
    echo html_writer::start_tag('form', ['method' => 'post',
        'style' => 'display:flex;align-items:center;gap:16px;flex-wrap:wrap;width:100%;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'setrandomize']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qtab',    'value' => 'pools']);
    echo html_writer::tag('span', '🎲 Randomisation', ['style' => 'font-weight:700;color:#155724;white-space:nowrap;']);
    $randCheckedP = $randomize_pools ? ['checked' => 'checked'] : [];
    echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:6px;']);
    echo html_writer::empty_tag('input', array_merge(
        ['type' => 'checkbox', 'name' => 'randomize', 'value' => '1',
         'id' => 'lg-rand-cb-p', 'style' => 'width:16px;height:16px;cursor:pointer;'],
        $randCheckedP));
    echo html_writer::tag('label', 'Randomise fixed questions', ['for' => 'lg-rand-cb-p',
        'style' => 'margin:0;font-weight:600;font-size:14px;cursor:pointer;']);
    echo html_writer::end_div();
    echo html_writer::start_div('', ['style' => 'display:flex;align-items:center;gap:6px;']);
    echo html_writer::tag('span', 'Show', ['style' => 'font-size:14px;color:#555;white-space:nowrap;']);
    echo html_writer::empty_tag('input', [
        'type' => 'number', 'name' => 'num_questions',
        'value' => (int)($instance->num_questions ?? 0),
        'min' => '0', 'step' => '1',
        'style' => 'width:70px;padding:5px 8px;border:1px solid #ccc;border-radius:6px;font-size:14px;',
    ]);
    echo html_writer::tag('span', 'fixed questions per attempt  (0 = show all)',
        ['style' => 'font-size:13px;color:#555;white-space:nowrap;']);
    echo html_writer::end_div();
    echo html_writer::empty_tag('input', [
        'type' => 'submit', 'value' => 'Save',
        'class' => 'btn btn-sm btn-success', 'style' => 'font-weight:700;',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    // Section-breaks toggle.
    $ssbOn = !isset($instance->show_section_breaks) || (int)$instance->show_section_breaks !== 0;
    echo html_writer::start_div('', ['style' =>
        'display:flex;align-items:center;gap:16px;background:' . ($ssbOn ? '#d1e7dd' : '#f8d7da') .
        ';border:1px solid ' . ($ssbOn ? '#a3cfbb' : '#f5c2c7') . ';border-radius:8px;' .
        'padding:12px 16px;margin-bottom:18px;']);
    $ssbIcon = $ssbOn ? '📂' : '📋';
    echo html_writer::tag('span', $ssbIcon . ' <strong>Section breaks:</strong> ' .
        ($ssbOn ? 'ON — students see each question bank name and intro before its questions.'
                : 'OFF — all questions shown as one continuous stream with no bank names.'),
        ['style' => 'flex:1;font-size:14px;color:' . ($ssbOn ? '#155724' : '#842029') . ';']);
    echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'margin:0;']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'toggle_section_breaks']);
    echo html_writer::tag('button', $ssbOn ? '🔕 Disable Section Breaks' : '🔔 Enable Section Breaks', [
        'type'  => 'submit',
        'style' => 'padding:6px 14px;border:none;border-radius:6px;font-size:13px;font-weight:600;cursor:pointer;' .
                   'background:' . ($ssbOn ? '#842029' : '#155724') . ';color:#fff;',
    ]);
    echo html_writer::end_tag('form');
    echo html_writer::end_div();

    // Existing pool rules table.
    echo html_writer::tag('h4', '🎲 Active Pool Banks', ['style' => 'margin-bottom:12px;']);
    if (empty($poolrules)) {
        echo html_writer::div('No pool banks configured yet. Add one below.', 'alert alert-info');
    } else {
        echo html_writer::start_tag('table', ['class' => 'generaltable', 'style' => 'width:100%;margin-bottom:24px;']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach (['Question Bank', 'Questions in Bank', 'Draw Count (per student)', 'Marks per Question', 'Section Timer (min)', 'Total Marks', 'Actions'] as $th) {
            echo html_writer::tag('th', $th, ['style' => 'padding:8px 12px;']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        foreach ($poolrules as $pr) {
            $totalmarks = (float)$pr->maxmark * (int)$pr->drawcount;
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::tag('strong', s($pr->catname)), ['style' => 'padding:8px 12px;']);
            echo html_writer::tag('td', (int)$pr->qcount . ' questions', ['style' => 'padding:8px 12px;color:#6c757d;']);
            // Inline edit form for draw count + maxmark.
            echo html_writer::start_tag('td', ['style' => 'padding:8px 12px;']);
            echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-flex;gap:6px;align-items:center;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'editpool']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'poolid',  'value' => $pr->id]);
            echo html_writer::empty_tag('input', [
                'type' => 'number', 'name' => 'pooldraw', 'value' => (int)$pr->drawcount,
                'min' => '1', 'max' => max(1, (int)$pr->qcount), 'style' => 'width:70px;padding:4px 6px;border:1px solid #ccc;border-radius:4px;',
            ]);
            echo html_writer::end_tag('td');
            echo html_writer::start_tag('td', ['style' => 'padding:8px 12px;']);
            echo html_writer::empty_tag('input', [
                'type' => 'number', 'name' => 'poolmaxmark', 'value' => number_format((float)$pr->maxmark, 2, '.', ''),
                'min' => '0', 'step' => '0.01', 'style' => 'width:80px;padding:4px 6px;border:1px solid #ccc;border-radius:4px;',
            ]);
            echo html_writer::tag('button', '💾 Save', ['type' => 'submit',
                'style' => 'padding:4px 10px;border:none;background:#0d6efd;color:#fff;border-radius:4px;cursor:pointer;font-size:13px;']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');
            // Section timer column — inline edit shares the same form.
            $pooltimelimit = property_exists($pr, 'timelimit') ? (int)$pr->timelimit : 0;
            echo html_writer::start_tag('td', ['style' => 'padding:8px 12px;']);
            echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline-flex;gap:6px;align-items:center;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'editpool']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'poolid',  'value' => $pr->id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'pooldraw',    'value' => (int)$pr->drawcount]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'poolmaxmark', 'value' => number_format((float)$pr->maxmark, 2, '.', '')]);
            echo html_writer::empty_tag('input', [
                'type' => 'number', 'name' => 'pooltimemin',
                'value' => (int)round($pooltimelimit / 60),
                'min' => '0', 'title' => '0 = no separate timer (uses the exam total)',
                'style' => 'width:70px;padding:4px 6px;border:1px solid #ccc;border-radius:4px;',
            ]);
            echo html_writer::tag('button', '💾', ['type' => 'submit',
                'title' => 'Save timer',
                'style' => 'padding:4px 8px;border:none;background:#0d6efd;color:#fff;border-radius:4px;cursor:pointer;font-size:13px;']);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');
            echo html_writer::tag('td',
                '<strong>' . number_format($totalmarks, 2) . '</strong> marks',
                ['style' => 'padding:8px 12px;color:#155724;font-weight:600;']);
            // Remove button.
            echo html_writer::start_tag('td', ['style' => 'padding:8px 12px;']);
            echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'removepool']);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'poolid',  'value' => $pr->id]);
            echo html_writer::tag('button', '🗑 Remove', [
                'type' => 'submit',
                'onclick' => "return confirm('Remove pool bank " . s($pr->catname) . " from this assessment?');",
                'style' => 'padding:4px 10px;border:none;background:#dc3545;color:#fff;border-radius:4px;cursor:pointer;font-size:13px;',
            ]);
            echo html_writer::end_tag('form');
            echo html_writer::end_tag('td');
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
    }

    // Add new pool rule form.
    echo html_writer::tag('h4', '➕ Add a Question Bank Pool', ['style' => 'margin-bottom:12px;']);
    if (empty($allcats)) {
        echo html_writer::div(
            '⚠️ No question bank categories exist yet. ' .
            html_writer::link(new moodle_url('/mod/learnguard/questionbank.php', ['id' => $id]), 'Go to Question Bank →') .
            ' to create a bank and add questions.',
            'alert alert-warning'
        );
    } else {
        echo html_writer::start_tag('form', [
            'method' => 'post',
            'style'  => 'background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:20px;max-width:600px;',
        ]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',      'value' => $id]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'addpool']);

        echo html_writer::start_div('mb-3');
        echo html_writer::tag('label', 'Question Bank (Category)', [
            'style' => 'display:block;font-weight:600;margin-bottom:5px;']);
        echo html_writer::start_tag('select', [
            'name'  => 'poolcatid',
            'style' => 'width:100%;padding:8px;border:1px solid #ccc;border-radius:6px;font-size:14px;']);
        echo html_writer::tag('option', '— Select a question bank —', ['value' => '0']);
        foreach ($allcats as $cat) {
            $already = array_filter((array)$poolrules, fn($pr) => (int)$pr->catid === (int)$cat->id);
            $label   = s($cat->name) . ' (' . (int)$cat->qcount . ' questions)';
            if (!empty($already)) { $label .= ' ✓ already added'; }
            echo html_writer::tag('option', $label, ['value' => $cat->id]);
        }
        echo html_writer::end_tag('select');
        echo html_writer::tag('small',
            'Each question bank can be added more than once (e.g. for different mark weights).',
            ['style' => 'color:#6c757d;display:block;margin-top:4px;']);
        echo html_writer::end_div();

        echo html_writer::start_div('', ['style' => 'display:flex;gap:20px;flex-wrap:wrap;margin-bottom:16px;']);

        echo html_writer::start_div('');
        echo html_writer::tag('label', 'Draw count (per student)', [
            'style' => 'display:block;font-weight:600;margin-bottom:5px;']);
        echo html_writer::empty_tag('input', [
            'type' => 'number', 'name' => 'pooldraw', 'value' => '1', 'min' => '1',
            'style' => 'width:100px;padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;',
        ]);
        echo html_writer::tag('small', 'How many random questions to pick per student', ['style' => 'color:#6c757d;display:block;margin-top:4px;']);
        echo html_writer::end_div();

        echo html_writer::start_div('');
        echo html_writer::tag('label', 'Marks per question', [
            'style' => 'display:block;font-weight:600;margin-bottom:5px;']);
        echo html_writer::empty_tag('input', [
            'type' => 'number', 'name' => 'poolmaxmark', 'value' => '1.00', 'min' => '0', 'step' => '0.01',
            'style' => 'width:100px;padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;',
        ]);
        echo html_writer::tag('small', 'Each drawn question is worth this many marks', ['style' => 'color:#6c757d;display:block;margin-top:4px;']);
        echo html_writer::end_div();

        echo html_writer::start_div('');
        echo html_writer::tag('label', 'Section timer (minutes)', [
            'style' => 'display:block;font-weight:600;margin-bottom:5px;']);
        echo html_writer::empty_tag('input', [
            'type' => 'number', 'name' => 'pooltimemin', 'value' => '0', 'min' => '0',
            'style' => 'width:100px;padding:7px 10px;border:1px solid #ccc;border-radius:6px;font-size:14px;',
        ]);
        echo html_writer::tag('small', '0 = no separate timer (uses the total exam timer)', ['style' => 'color:#6c757d;display:block;margin-top:4px;']);
        echo html_writer::end_div();

        echo html_writer::end_div(); // flex row

        echo html_writer::tag('button', '➕ Add Pool Bank', [
            'type'  => 'submit',
            'style' => 'padding:10px 24px;background:#198754;color:#fff;border:none;border-radius:6px;font-size:15px;font-weight:600;cursor:pointer;',
        ]);
        echo html_writer::end_tag('form');
    }

    // Explanation box.
    echo html_writer::start_div('alert alert-secondary', ['style' => 'margin-top:24px;']);
    echo html_writer::tag('strong', 'How Question Bank Pools work:', ['style' => 'display:block;margin-bottom:6px;']);
    echo html_writer::tag('ul', implode('', array_map(fn($s) => "<li>$s</li>", [
        'Each pool rule draws a set number of <strong>random</strong> questions from a question bank for each student.',
        'Different students get different questions. The same bank can appear in multiple pool rules with different draw counts.',
        'Pool-drawn questions are combined with any <strong>Fixed Questions</strong> (📋 tab) to form each student\'s complete attempt.',
        'Questions within a pool are selected using a fair shuffle — every question has an equal chance per attempt.',
        'Grading, scoring, and all existing reports work on pool questions exactly like fixed questions.',
    ])));
    echo html_writer::end_div();
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

            // "Add all from category" button — JS-triggered, no nested form inside lg-bulk-form.
            echo html_writer::tag('button', '+ Add all ' . $catcount, [
                'type'    => 'button',
                'class'   => 'btn btn-sm btn-outline-primary',
                'title'   => 'Add all ' . $catcount . ' question' . ($catcount === 1 ? '' : 's') . ' from this category to the assessment',
                'onclick' => 'lgBankAddCategory(' . $id . ',' . $catid . ',' . json_encode($catname) . ',0,' . json_encode(sesskey()) . ')',
            ]);

            // "Add N random" — JS-triggered, no nested form inside lg-bulk-form.
            $rndInputId = 'lg-rnd-' . $catid;
            echo html_writer::start_div('', ['style' => 'display:inline-flex;align-items:center;gap:4px;']);
            echo html_writer::tag('span', 'Add', ['style' => 'font-size:13px;color:#555;white-space:nowrap;']);
            echo html_writer::empty_tag('input', [
                'type' => 'number', 'id' => $rndInputId,
                'min' => '1', 'max' => $catcount, 'value' => min(5, $catcount),
                'style' => 'width:55px;padding:3px 5px;border:1px solid #ccc;border-radius:4px;font-size:13px;',
                'title' => 'Number of random questions to pick from this category',
            ]);
            echo html_writer::tag('span', 'random', ['style' => 'font-size:13px;color:#555;white-space:nowrap;']);
            echo html_writer::tag('button', '→', [
                'type'    => 'button',
                'class'   => 'btn btn-sm btn-outline-secondary',
                'title'   => 'Add this many randomly-selected questions from the category',
                'onclick' => 'lgBankAddRandom(' . $id . ',' . $catid . ',' . json_encode($catname) . ',' . json_encode($rndInputId) . ',' . json_encode(sesskey()) . ')',
            ]);
            echo html_writer::end_div();

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

                // Mandatory toggle button — JS-triggered, no nested form inside lg-bulk-form.
                $mandatoryLabel = !empty($q->is_mandatory) ? '📌 Mandatory' : '📌 Make Mandatory';
                $mandatoryClass = !empty($q->is_mandatory)
                    ? 'btn btn-sm btn-danger'
                    : 'btn btn-sm btn-outline-secondary';
                echo html_writer::tag('button', $mandatoryLabel, [
                    'type'    => 'button',
                    'class'   => $mandatoryClass,
                    'style'   => 'margin-right:4px;',
                    'title'   => !empty($q->is_mandatory)
                        ? 'Click to remove mandatory — question will join the random pool'
                        : 'Click to make mandatory — always included even in randomised sets',
                    'onclick' => 'lgBankToggleMandatory(' . $id . ',' . (int)$q->id . ',' . json_encode(sesskey()) . ')',
                ]);

                // Edit button (always visible).
                echo html_writer::link(
                    new moodle_url('/mod/learnguard/questionedit.php', ['id' => $id, 'qid' => $q->id]),
                    '✏️ Edit',
                    ['class' => 'btn btn-sm btn-outline-secondary', 'style' => 'margin-right:4px;']
                );

                if ($added) {
                    echo html_writer::tag('span', '✓ Added', ['class' => 'btn btn-sm btn-success disabled']);
                } else {
                    // Individual add button — JS-triggered, no nested form inside lg-bulk-form.
                    echo html_writer::tag('button', '+ Add', [
                        'type'    => 'button',
                        'class'   => 'btn btn-sm btn-primary',
                        'onclick' => 'lgBankAddOne(' . $id . ',' . (int)$q->id . ',' . json_encode(sesskey()) . ')',
                    ]);
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

?>
<script>
function lgSlotsUpdateCount() {
    var chks = document.querySelectorAll('.lg-slot-chk:checked');
    var cnt  = document.getElementById('lg-slots-count');
    var all  = document.getElementById('lg-slots-chk-all');
    if (cnt) cnt.textContent = chks.length + ' selected';
    if (all) all.indeterminate = (chks.length > 0 && chks.length < document.querySelectorAll('.lg-slot-chk').length);
}
function lgSlotsSelectAll(on) {
    document.querySelectorAll('.lg-slot-chk').forEach(function(c) { c.checked = !!on; });
    lgSlotsUpdateCount();
}
function lgSlotsRemoveSelected() {
    var sel = Array.from(document.querySelectorAll('.lg-slot-chk:checked')).map(function(c) { return c.value; });
    if (!sel.length) { alert('Select at least one question first.'); return; }
    if (!confirm('Remove ' + sel.length + ' question(s) from this assessment? They stay in the question bank.')) { return; }
    var f = document.createElement('form');
    f.method = 'post';
    f.action = '<?php echo (new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id, 'qtab' => 'slots']))->out(false); ?>';
    [['sesskey','<?php echo sesskey(); ?>'],['action','bulkremove']].forEach(function(p) {
        var i = document.createElement('input'); i.type='hidden'; i.name=p[0]; i.value=p[1]; f.appendChild(i);
    });
    sel.forEach(function(sid) {
        var i = document.createElement('input'); i.type='hidden'; i.name='slotids[]'; i.value=sid; f.appendChild(i);
    });
    document.body.appendChild(f); f.submit(); document.body.removeChild(f);
}
function lgSlotsMakeMandatory() {
    var chks = document.querySelectorAll('.lg-slot-chk:checked');
    var qids = Array.from(chks).map(function(c) { return c.getAttribute('data-qid'); }).filter(Boolean);
    if (!qids.length) { alert('Select at least one question first.'); return; }
    if (!confirm('Mark ' + qids.length + ' question(s) as mandatory?\nThey will always be included for every student, even in randomised sets.')) { return; }
    var f = document.createElement('form');
    f.method = 'post';
    f.action = '<?php echo (new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]))->out(false); ?>';
    [['sesskey','<?php echo sesskey(); ?>'],['action','bulkmandatory'],['returntab','slots']].forEach(function(p) {
        var i = document.createElement('input'); i.type='hidden'; i.name=p[0]; i.value=p[1]; f.appendChild(i);
    });
    qids.forEach(function(qid) {
        var i = document.createElement('input'); i.type='hidden'; i.name='qids[]'; i.value=qid; f.appendChild(i);
    });
    document.body.appendChild(f); f.submit(); document.body.removeChild(f);
}
function lgBankSendAction(pairs) {
    var f = document.createElement('form');
    f.method = 'post';
    pairs.forEach(function(p) {
        var inp = document.createElement('input');
        inp.type = 'hidden'; inp.name = p[0]; inp.value = p[1];
        f.appendChild(inp);
    });
    document.body.appendChild(f);
    f.submit();
    document.body.removeChild(f);
}
function lgBankAddCategory(id, catid, catname, maxcount, sesskey) {
    lgBankSendAction([
        ['id', id], ['sesskey', sesskey],
        ['action', 'addcategory'], ['catid', catid],
        ['catname', catname], ['maxcount', maxcount], ['qtab', 'bank']
    ]);
}
function lgBankAddRandom(id, catid, catname, inputId, sesskey) {
    var n = parseInt(document.getElementById(inputId).value, 10) || 1;
    lgBankSendAction([
        ['id', id], ['sesskey', sesskey],
        ['action', 'addcategory'], ['catid', catid],
        ['catname', catname], ['maxcount', n], ['qtab', 'bank']
    ]);
}
function lgBankToggleMandatory(id, qid, sesskey) {
    lgBankSendAction([
        ['id', id], ['sesskey', sesskey],
        ['action', 'togglemandatory'], ['qid', qid],
        ['returntab', 'bank'], ['qtab', 'bank']
    ]);
}
function lgBankAddOne(id, qid, sesskey) {
    lgBankSendAction([
        ['id', id], ['sesskey', sesskey],
        ['action', 'add'], ['qid', qid], ['qtab', 'bank']
    ]);
}
</script>
<?php
echo $OUTPUT->footer();
