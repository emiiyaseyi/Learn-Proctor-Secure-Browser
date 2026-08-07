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
 * LearnGuard own question bank browser.
 *
 * Shows all questions stored in learnguard_qbank.
 * Teachers/managers can create, edit, delete and organise questions.
 * Site admins see all questions site-wide.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

// Accept either a course-module context or a course context.
$id         = optional_param('id',       0, PARAM_INT); // cmid
$courseid   = optional_param('courseid', 0, PARAM_INT);
$catid      = optional_param('cat',      0, PARAM_INT);
$search     = optional_param('search',  '', PARAM_TEXT);
$action     = optional_param('action',  '', PARAM_ALPHA);
$qid        = optional_param('qid',     0,  PARAM_INT);

if ($id) {
    $cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
    $course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $context  = context_module::instance($cm->id);
    require_login($course, false, $cm);
    require_capability('mod/learnguard:manage', $context);
} elseif ($courseid) {
    $course  = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context = context_course::instance($course->id);
    require_login($course);
    require_capability('moodle/course:manageactivities', $context);
    $cm = null; $id = 0;
} else {
    require_login();
    require_capability('moodle/site:config', context_system::instance());
    $course = null; $cm = null; $context = context_system::instance();
}

// AJAX — must run before any $PAGE setup or buffered output.
if (optional_param('lgajax', 0, PARAM_INT)) {
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: application/json');
    if (!confirm_sesskey()) {
        echo json_encode(['ok' => false, 'error' => 'Invalid sesskey']);
        exit;
    }
    $ajaxAction = optional_param('action', '', PARAM_ALPHANUMEXT);
    $ajaxQid    = optional_param('qid',   0,  PARAM_INT);
    if ($ajaxAction === 'preview_question' && $ajaxQid > 0) {
        try {
            $q   = $DB->get_record('learnguard_qbank', ['id' => $ajaxQid], '*', MUST_EXIST);
            $ans = array_values($DB->get_records('learnguard_qbank_answers',
                ['questionid' => $ajaxQid], 'sortorder ASC'));
            echo json_encode([
                'ok'              => true,
                'name'            => format_string($q->name),
                'qtype'           => $q->qtype,
                'questiontext'    => $q->questiontext,
                'defaultmark'     => (float)$q->defaultmark,
                'markingguidance' => $q->markingguidance ?? '',
                'answers'         => array_map(function($a) {
                    return ['text' => $a->answertext, 'fraction' => (float)$a->fraction];
                }, $ans),
            ]);
        } catch (Exception $e) {
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    }
    exit;
}

$PAGE->set_url('/mod/learnguard/questionbank.php',
    array_filter(['id' => $id, 'courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('LearnGuard Question Bank');
$PAGE->set_heading($course ? format_string($course->fullname) : 'LearnGuard Question Bank');
$PAGE->requires->css('/mod/learnguard/styles.css');

// ---- POST actions ----
if (data_submitted() && confirm_sesskey()) {
    // Bulk delete selected questions
    if ($action === 'bulkdelete') {
        $qids = optional_param_array('qids', [], PARAM_INT);
        $qids = array_filter(array_map('intval', $qids));
        $deleted = 0;
        foreach ($qids as $dqid) {
            // Get slot IDs before deleting slots, so AQ records can be cleaned first.
            $slotIds = $DB->get_fieldset_select('learnguard_slots', 'id', 'questionid = :qid', ['qid' => $dqid]);
            if (!empty($slotIds)) {
                [$slotSql, $slotP] = $DB->get_in_or_equal($slotIds, SQL_PARAMS_NAMED, 'sl');
                $DB->delete_records_select('learnguard_attempt_questions', "slotid $slotSql", $slotP);
            }
            $DB->delete_records('learnguard_qbank_answers', ['questionid' => $dqid]);
            $DB->delete_records('learnguard_slots',         ['questionid' => $dqid]);
            $DB->delete_records('learnguard_qbank',         ['id' => $dqid]);
            $deleted++;
        }
        redirect(
            new moodle_url('/mod/learnguard/questionbank.php', array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $catid])),
            $deleted . ' question(s) deleted.', null, \core\output\notification::NOTIFY_SUCCESS
        );
    }
    // Bulk move to category
    if ($action === 'bulkmove') {
        $qids    = optional_param_array('qids', [], PARAM_INT);
        $newcat  = required_param('newcatid', PARAM_INT);
        $qids    = array_filter(array_map('intval', $qids));
        foreach ($qids as $mqid) {
            $DB->set_field('learnguard_qbank', 'categoryid', $newcat, ['id' => $mqid]);
        }
        redirect(
            new moodle_url('/mod/learnguard/questionbank.php', array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $newcat])),
            count($qids) . ' question(s) moved.', null, \core\output\notification::NOTIFY_SUCCESS
        );
    }
    // Bulk change mark for selected questions + recalculate all affected attempt scores.
    if ($action === 'bulkchangemark') {
        $qids    = optional_param_array('qids', [], PARAM_INT);
        $newmark = optional_param('newmark', 0, PARAM_FLOAT);
        $qids    = array_filter(array_map('intval', $qids));
        if ($newmark <= 0 || empty($qids)) {
            redirect(
                new moodle_url('/mod/learnguard/questionbank.php', array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $catid])),
                'Invalid mark or no questions selected.', null, \core\output\notification::NOTIFY_ERROR
            );
        }
        // Update question default mark and all slot maxmarks that use these questions.
        foreach ($qids as $mqid) {
            $DB->set_field('learnguard_qbank', 'defaultmark', $newmark, ['id' => $mqid]);
            $DB->execute('UPDATE {learnguard_slots} SET maxmark = ? WHERE questionid = ?', [$newmark, $mqid]);
        }
        // Find all finished attempts that included these questions via AQ records.
        [$qidSql, $qidP] = $DB->get_in_or_equal($qids, SQL_PARAMS_NAMED, 'qid');
        $affectedAids = $DB->get_fieldset_sql(
            "SELECT DISTINCT aq.attemptid
               FROM {learnguard_attempt_questions} aq
               JOIN {learnguard_slots} s ON s.id = aq.slotid
              WHERE s.questionid $qidSql",
            $qidP
        );
        // Recalculate scores for all affected finished attempts.
        require_once($CFG->libdir . '/gradelib.php');
        require_once(__DIR__ . '/classes/question_engine.php');
        $recalcCount = 0;
        foreach ($affectedAids as $aid) {
            $att = $DB->get_record('learnguard_attempts', ['id' => (int)$aid, 'state' => 'finished']);
            if (!$att) { continue; }
            $lgInst = $DB->get_record('learnguard', ['id' => $att->learnguard]);
            if (!$lgInst) { continue; }
            if (\mod_learnguard\question_engine::has_ungraded_essays((int)$att->id, (int)$lgInst->id)) {
                $DB->set_field('learnguard_attempts', 'sumgrades', null, ['id' => $att->id]);
            } else {
                $newgrade = \mod_learnguard\question_engine::calculate_total_grade((int)$att->id, $lgInst);
                $DB->set_field('learnguard_attempts', 'sumgrades', $newgrade, ['id' => $att->id]);
                grade_update('mod/learnguard', $lgInst->course, 'mod', 'learnguard', $lgInst->id, 0,
                    [(object)['userid' => $att->userid, 'rawgrade' => $newgrade]]);
                $recalcCount++;
            }
        }
        redirect(
            new moodle_url('/mod/learnguard/questionbank.php', array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $catid])),
            count($qids) . ' question(s) updated to ' . number_format($newmark, 2) . ' marks. ' .
            $recalcCount . ' attempt(s) recalculated.',
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    }
    // Bulk toggle shuffle answers for selected questions.
    if ($action === 'bulkshuffle') {
        $qids      = optional_param_array('qids', [], PARAM_INT);
        $shuffleval = optional_param('shuffleval', 0, PARAM_INT);
        $shuffleval = ($shuffleval ? 1 : 0);
        $qids       = array_filter(array_map('intval', $qids));
        if (empty($qids)) {
            redirect(
                new moodle_url('/mod/learnguard/questionbank.php', array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $catid])),
                'No questions selected.', null, \core\output\notification::NOTIFY_ERROR
            );
        }
        foreach ($qids as $mqid) {
            $DB->set_field('learnguard_qbank', 'shuffleanswers', $shuffleval, ['id' => $mqid]);
        }
        redirect(
            new moodle_url('/mod/learnguard/questionbank.php', array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $catid])),
            'Shuffle answers ' . ($shuffleval ? 'enabled' : 'disabled') . ' for ' . count($qids) . ' question(s).',
            null, \core\output\notification::NOTIFY_SUCCESS
        );
    }
    // Shuffle ALL questions in the current category.
    if ($action === 'shuffleall') {
        $shuffleval = optional_param('shuffleval', 0, PARAM_INT);
        $shuffleval = ($shuffleval ? 1 : 0);
        if ($catid) {
            $allqs = $DB->get_fieldset_select('learnguard_qbank', 'id',
                'categoryid = :cid AND qtype IN (:t1,:t2)',
                ['cid' => $catid, 't1' => 'multichoice', 't2' => 'multicheck']);
            foreach ($allqs as $qid) {
                $DB->set_field('learnguard_qbank', 'shuffleanswers', $shuffleval, ['id' => $qid]);
            }
            $msg = 'Shuffle answers ' . ($shuffleval ? 'enabled' : 'disabled') . ' for all ' . count($allqs) . ' MCQ question(s) in this category.';
        } else {
            $msg = 'No category selected.';
        }
        redirect(
            new moodle_url('/mod/learnguard/questionbank.php', array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $catid])),
            $msg, null, \core\output\notification::NOTIFY_SUCCESS
        );
    }

    if ($action === 'deleteq' && $qid > 0) {
        // Clean up AQ records referencing slots for this question before deleting slots.
        $slotIds = $DB->get_fieldset_select('learnguard_slots', 'id', 'questionid = :qid', ['qid' => $qid]);
        if (!empty($slotIds)) {
            [$slotSql, $slotP] = $DB->get_in_or_equal($slotIds, SQL_PARAMS_NAMED, 'sl');
            $DB->delete_records_select('learnguard_attempt_questions', "slotid $slotSql", $slotP);
        }
        $DB->delete_records('learnguard_qbank_answers', ['questionid' => $qid]);
        $DB->delete_records('learnguard_slots', ['questionid' => $qid]);
        $DB->delete_records('learnguard_qbank', ['id' => $qid]);
        redirect(
            new moodle_url('/mod/learnguard/questionbank.php', array_filter(['id' => $id, 'courseid' => $courseid])),
            'Question deleted.', null, \core\output\notification::NOTIFY_SUCCESS
        );
    }
    if ($action === 'addcat') {
        $catname = required_param('catname', PARAM_TEXT);
        if (trim($catname)) {
            $DB->insert_record('learnguard_qbank_cats', (object)[
                'name'         => clean_param(trim($catname), PARAM_TEXT),
                'description'  => '',
                'sortorder'    => 0,
                'timecreated'  => time(),
                'timemodified' => time(),
            ]);
        }
        redirect(new moodle_url('/mod/learnguard/questionbank.php',
            array_filter(['id' => $id, 'courseid' => $courseid])));
    }
    if ($action === 'deletecat' && $catid > 0) {
        // Move questions in this category to General (cat id 1 or first).
        $fallback = $DB->get_field_sql('SELECT MIN(id) FROM {learnguard_qbank_cats}');
        if ($fallback && $fallback != $catid) {
            $DB->set_field('learnguard_qbank', 'categoryid', $fallback, ['categoryid' => $catid]);
        }
        $DB->delete_records('learnguard_qbank_cats', ['id' => $catid]);
        redirect(new moodle_url('/mod/learnguard/questionbank.php',
            array_filter(['id' => $id, 'courseid' => $courseid])));
    }
    if ($action === 'renamecat' && $catid > 0) {
        $newname = optional_param('newcatname', '', PARAM_TEXT);
        if (trim($newname)) {
            $DB->set_field('learnguard_qbank_cats', 'name',
                clean_param(trim($newname), PARAM_TEXT), ['id' => $catid]);
        }
        redirect(new moodle_url('/mod/learnguard/questionbank.php',
            array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $catid])));
    }
}

$brand = learnguard_brand();

echo $OUTPUT->header();
if ($id) { echo learnguard_nav_bar($id, 'Question Bank'); }
else { echo learnguard_nav_bar(0, 'Question Bank'); }
echo learnguard_banner('LearnGuard Question Bank',
    'Create, import and manage questions for all assessments.', '🏦');

// ---- Action bar ----
echo html_writer::start_div('d-flex gap-2 flex-wrap mb-3 align-items-center');
echo html_writer::link(
    new moodle_url('/mod/learnguard/questionedit.php',
        array_filter(['id' => $id, 'courseid' => $courseid])),
    '+ New Question', ['class' => 'btn btn-primary']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/questionimport.php',
        array_filter(['id' => $id, 'courseid' => $courseid])),
    '📥 Import CSV', ['class' => 'btn btn-outline-secondary']
);
// Build context-sensitive export filter: category selection always takes priority
// so the user exports exactly what they see in the sidebar. Fall back to
// assessment-slot questions only when no category is selected.
$exportBase = ['sesskey' => sesskey()];
if ($catid) {
    $exportBase['catid'] = (int)$catid; // selected category — all types, takes priority
} elseif (!empty($cm)) {
    $exportBase['lgid'] = (int)$cm->instance; // no category selected — assessment's slots
}
// Export dropdown group — Plain Text is the default/primary format.
echo '<div class="btn-group">';
echo html_writer::link(
    new moodle_url('/mod/learnguard/export_questions.php', $exportBase + ['format' => 'txt']),
    '📝 Export (.txt)', ['class' => 'btn btn-success', 'title' => 'Export as plain text — MCQ, essay, short-answer in readable format']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/export_questions.php', $exportBase + ['format' => 'csv']),
    '⬇ CSV', ['class' => 'btn btn-outline-secondary', 'title' => 'Export as CSV (re-importable into LearnGuard)']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/export_questions.php', $exportBase + ['format' => 'xml']),
    '📄 Moodle XML', ['class' => 'btn btn-outline-secondary', 'title' => 'Export as Moodle XML — importable into any Moodle quiz']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/export_questions.php', $exportBase + ['format' => 'gift']),
    '🎁 GIFT', ['class' => 'btn btn-outline-secondary', 'title' => 'Export as GIFT text format']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/export_questions.php', $exportBase + ['format' => 'json']),
    '{ } JSON', ['class' => 'btn btn-outline-secondary', 'title' => 'Export as JSON for developers']
);
echo '</div>';
if ($id) {
    echo html_writer::link(
        new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]),
        '📋 Assessment Questions', ['class' => 'btn btn-outline-dark']
    );
}
echo html_writer::end_div();

// ---- Category sidebar + question list ----
$cats = $DB->get_records('learnguard_qbank_cats', [], 'sortorder ASC, name ASC');
echo html_writer::start_div('', ['style' => 'display:flex;gap:20px;']);

// ---- Sidebar ----
echo html_writer::start_div('', ['style' => 'width:220px;flex-shrink:0;']);
echo html_writer::tag('div', 'CATEGORIES',
    ['style' => 'font-size:12px;font-weight:700;color:#6c757d;letter-spacing:1px;margin-bottom:8px;']);

foreach ($cats as $cat) {
    $count  = $DB->count_records('learnguard_qbank', ['categoryid' => $cat->id]);
    $active = ($catid == $cat->id);
    $catIdEsc = (int)$cat->id;
    $catNameEsc = s(format_string($cat->name));
    echo html_writer::start_div('', ['class' => 'lg-cat-row', 'style' =>
        'display:flex;align-items:center;justify-content:space-between;gap:4px;' .
        'padding:6px 10px;border-radius:6px;margin-bottom:2px;' .
        ($active ? 'background:' . $brand->primary . ';color:#fff;' : 'background:#f8f9fa;')]);
    echo html_writer::link(
        new moodle_url('/mod/learnguard/questionbank.php',
            array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $cat->id])),
        format_string($cat->name),
        ['style' => 'text-decoration:none;' . ($active ? 'color:#fff;' : 'color:#333;') . 'flex:1;font-size:14px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;']
    );
    echo html_writer::tag('span', $count,
        ['style' => 'font-size:11px;flex-shrink:0;' . ($active ? 'color:#fff;opacity:0.7;' : 'color:#6c757d;')]);
    // Rename button — always visible, styled subtly.
    echo '<button type="button" title="Rename category"'
        . ' onclick="lgRenameCategory(' . $catIdEsc . ', \'' . $catNameEsc . '\')"'
        . ' style="background:none;border:none;cursor:pointer;padding:0 2px;flex-shrink:0;'
        . ($active ? 'color:#fff;opacity:0.8;' : 'color:#aaa;')
        . '" class="lg-cat-rename-btn">✏️</button>';
    echo html_writer::end_div();
}
// Hidden rename form — submitted by JS.
echo html_writer::start_tag('form', ['id' => 'lg-rename-cat-form', 'method' => 'post', 'style' => 'display:none;']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',     'value' => 'renamecat']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cat',        'id'    => 'lg-rename-cat-id', 'value' => '']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'newcatname', 'id'    => 'lg-rename-cat-name', 'value' => '']);
if ($id)       { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',       'value' => $id]); }
if ($courseid) { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]); }
echo html_writer::end_tag('form');

// Add category form.
echo html_writer::start_tag('form', ['method' => 'post', 'class' => 'mt-3']);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'addcat']);
if ($id)       { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',       'value' => $id]); }
if ($courseid) { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]); }
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'catname', 'placeholder' => 'New category…',
    'class' => 'form-control', 'style' => 'font-size:13px;margin-bottom:4px;',
]);
echo html_writer::empty_tag('input', [
    'type' => 'submit', 'value' => '+ Add',
    'class' => 'btn btn-sm btn-outline-secondary w-100',
]);
echo html_writer::end_tag('form');
echo html_writer::end_div(); // sidebar

// ---- Question list ----
echo html_writer::start_div('', ['style' => 'flex:1;']);

// Search bar.
echo html_writer::start_tag('form', ['method' => 'get', 'class' => 'd-flex gap-2 mb-3']);
if ($id)       { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',       'value' => $id]); }
if ($courseid) { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]); }
if ($catid)    { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cat',      'value' => $catid]); }
echo html_writer::empty_tag('input', [
    'type' => 'text', 'name' => 'search', 'value' => s($search),
    'placeholder' => '🔍 Search questions…', 'class' => 'form-control',
]);
echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => 'Search', 'class' => 'btn btn-outline-secondary']);
echo html_writer::end_tag('form');

// Build query conditions.
$conditions = [];
$params     = [];

if ($catid > 0) {
    $conditions[] = 'q.categoryid = :catid';
    $params['catid'] = $catid;
}
if (trim($search)) {
    $conditions[] = '(' . $DB->sql_like('q.name', ':search1', false) . ' OR ' .
                    $DB->sql_like('q.questiontext', ':search2', false) . ')';
    $params['search1'] = '%' . $DB->sql_like_escape($search) . '%';
    $params['search2'] = '%' . $DB->sql_like_escape($search) . '%';
}

$where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';

$questions = $DB->get_records_sql(
    "SELECT q.id, q.name, q.qtype, q.defaultmark, q.timecreated, q.timemodified,
            c.name AS catname
       FROM {learnguard_qbank} q
  LEFT JOIN {learnguard_qbank_cats} c ON c.id = q.categoryid
       $where
   ORDER BY c.name, q.name",
    $params
);

$qtypeLabels = [
    'multichoice' => ['label' => 'Multiple Choice',  'badge' => '#0f6cbf'],
    'multicheck'  => ['label' => 'Multi-select',     'badge' => '#6f42c1'],
    'truefalse'   => ['label' => 'True / False',     'badge' => '#20c997'],
    'shortanswer' => ['label' => 'Short Answer',     'badge' => '#fd7e14'],
    'essay'       => ['label' => 'Essay',            'badge' => '#795548'],
    'matching'    => ['label' => 'Matching',         'badge' => '#17a2b8'],
];

$qCount = count($questions);
echo html_writer::tag('div',
    $qCount . ' question(s) found' . ($catid ? ' in this category' : ' site-wide'),
    ['style' => 'color:#6c757d;font-size:13px;margin-bottom:8px;']
);

if (empty($questions)) {
    echo html_writer::div('No questions found. Create your first question or import from a file.', 'alert alert-info');
} else {
    // Wrap in a form for multi-select bulk actions
    $bulkUrl = new moodle_url('/mod/learnguard/questionbank.php',
        array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $catid]));
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $bulkUrl->out(false), 'id' => 'lg-qb-form']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'id' => 'lg-qb-action', 'value' => '']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qid',     'id' => 'lg-qb-qid',    'value' => '0']);

    // Bulk action bar
    echo '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;background:#f8f9fa;' .
         'padding:8px 12px;border-radius:6px;margin-bottom:10px;border:1px solid #dee2e6;">';
    echo '<button type="button" onclick="lgQbSelectAll(true)" class="btn btn-sm btn-outline-secondary">☑ All</button>';
    echo '<button type="button" onclick="lgQbSelectAll(false)" class="btn btn-sm btn-outline-secondary">☐ None</button>';
    echo '<span id="lg-qb-count" style="font-size:12px;color:#6c757d;">0 selected</span>';
    echo '<div style="border-left:1px solid #dee2e6;height:20px;margin:0 4px;"></div>';
    echo '<button type="button" onclick="lgQbBulkDelete()" class="btn btn-sm btn-danger" ' .
         'title="Delete selected questions">🗑 Delete Selected</button>';
    // Move to category
    echo '<select id="lg-qb-newcat" class="form-control" style="max-width:160px;font-size:13px;">';
    echo '<option value="">— Move to category —</option>';
    foreach ($cats as $c) {
        echo html_writer::tag('option', format_string($c->name), ['value' => $c->id]);
    }
    echo '</select>';
    echo '<button type="button" onclick="lgQbBulkMove()" class="btn btn-sm btn-outline-primary">↗ Move</button>';
    // Export selected
    echo '<button type="button" onclick="lgQbExportSelected()" class="btn btn-sm btn-outline-success">⬇ Export Selected</button>';
    echo '<input type="hidden" name="newcatid"   id="lg-qb-newcatid"   value="">';
    echo '<input type="hidden" name="shuffleval" id="lg-qb-shuffleval" value="">';
    // Bulk change mark
    echo '<div style="border-left:1px solid #dee2e6;height:20px;margin:0 4px;"></div>';
    echo '<input type="number" name="newmark" id="lg-qb-newmark" step="any" min="0.01" ' .
         'placeholder="New mark" style="width:90px;" class="form-control form-control-sm">';
    echo '<button type="button" onclick="lgQbBulkChangeMark()" class="btn btn-sm btn-outline-warning" ' .
         'title="Change marks for selected questions and recalculate all attempt scores">✏ Change Mark</button>';
    // Bulk shuffle toggle
    echo '<div style="border-left:1px solid #dee2e6;height:20px;margin:0 4px;"></div>';
    echo '<button type="button" onclick="lgQbBulkShuffle(1)" class="btn btn-sm btn-outline-info" ' .
         'title="Enable answer shuffling for selected MCQ/multicheck questions">🔀 Shuffle On</button>';
    echo '<button type="button" onclick="lgQbBulkShuffle(0)" class="btn btn-sm btn-outline-secondary" ' .
         'title="Disable answer shuffling for selected questions">🔀 Shuffle Off</button>';
    echo '<div style="border-left:1px solid #dee2e6;height:20px;margin:0 4px;"></div>';
    echo '<button type="button" onclick="lgQbShuffleAll(1)" class="btn btn-sm btn-info" ' .
         'title="Enable shuffle for ALL MCQ questions in this category (no selection needed)">🔀 All On</button>';
    echo '<button type="button" onclick="lgQbShuffleAll(0)" class="btn btn-sm btn-secondary" ' .
         'title="Disable shuffle for ALL MCQ questions in this category (no selection needed)">🔀 All Off</button>';
    echo '</div>';

    echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    // Checkbox header
    echo html_writer::tag('th',
        '<input type="checkbox" id="lg-qb-chk-all" onclick="lgQbSelectAll(this.checked)" style="width:16px;height:16px;">',
        ['style' => 'padding:8px 10px;width:36px;']);
    foreach (['Question', 'Type', 'Category', 'Marks', 'Modified', 'Actions'] as $h) {
        echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;font-size:13px;']);
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($questions as $q) {
        $tinfo = $qtypeLabels[$q->qtype] ?? ['label' => ucfirst($q->qtype), 'badge' => '#6c757d'];
        echo html_writer::start_tag('tr', ['id' => 'qrow-' . $q->id]);

        // Checkbox
        echo html_writer::tag('td',
            '<input type="checkbox" name="qids[]" value="' . $q->id . '" class="lg-qb-chk" ' .
            'onchange="lgQbUpdateCount()" style="width:16px;height:16px;">',
            ['style' => 'padding:8px 10px;']);

        echo html_writer::tag('td', format_string($q->name), ['style' => 'font-weight:600;max-width:320px;']);
        echo html_writer::tag('td', html_writer::tag('span', $tinfo['label'],
            ['style' => 'background:' . $tinfo['badge'] . ';color:#fff;padding:2px 7px;border-radius:10px;font-size:11px;font-weight:700;']));
        echo html_writer::tag('td', format_string($q->catname ?? 'General'), ['style' => 'font-size:13px;color:#6c757d;']);
        echo html_writer::tag('td', (float)$q->defaultmark, ['style' => 'text-align:center;']);
        echo html_writer::tag('td', userdate($q->timemodified, '%d/%m/%y'), ['style' => 'font-size:12px;color:#6c757d;white-space:nowrap;']);

        echo html_writer::start_tag('td');
        // Edit.
        echo html_writer::link(
            new moodle_url('/mod/learnguard/questionedit.php',
                array_filter(['id' => $id, 'courseid' => $courseid, 'qid' => $q->id])),
            '✏️', ['class' => 'btn btn-sm btn-outline-primary', 'title' => 'Edit']
        );
        echo ' ';
        // Delete (single) — sets hidden fields in the outer form, avoids nested-form HTML.
        echo '<button type="button" class="btn btn-sm btn-outline-danger" title="Delete question"'
            . ' onclick="lgQbDeleteOne(' . (int)$q->id . ')">🗑</button>';
        echo ' ';
        // Preview.
        echo '<button type="button" class="btn btn-sm btn-outline-info" title="Preview question"'
            . ' onclick="lgQbPreview(' . (int)$q->id . ')">👁 Preview</button>';
        echo html_writer::end_tag('td');
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_tag('form'); // close bulk form
}

echo html_writer::end_div(); // question list
echo html_writer::end_div(); // flex container
?>

<script>
function lgQbUpdateCount() {
    var chks = document.querySelectorAll('.lg-qb-chk:checked');
    var cnt  = document.getElementById('lg-qb-count');
    var all  = document.getElementById('lg-qb-chk-all');
    if (cnt) cnt.textContent = chks.length + ' selected';
    if (all) all.indeterminate = (chks.length > 0 && chks.length < document.querySelectorAll('.lg-qb-chk').length);
}
function lgQbSelectAll(on) {
    document.querySelectorAll('.lg-qb-chk').forEach(function(c) { c.checked = !!on; });
    lgQbUpdateCount();
}
function lgQbGetSelected() {
    return Array.from(document.querySelectorAll('.lg-qb-chk:checked')).map(function(c) { return c.value; });
}
function lgQbBulkDelete() {
    var sel = lgQbGetSelected();
    if (!sel.length) { alert('Select at least one question first.'); return; }
    if (!confirm('Delete ' + sel.length + ' selected question(s)? This cannot be undone.')) return;
    document.getElementById('lg-qb-action').value = 'bulkdelete';
    document.getElementById('lg-qb-form').submit();
}
function lgQbBulkMove() {
    var sel    = lgQbGetSelected();
    var newcat = document.getElementById('lg-qb-newcat').value;
    if (!sel.length)  { alert('Select at least one question first.'); return; }
    if (!newcat)      { alert('Select a category to move to.'); return; }
    document.getElementById('lg-qb-newcatid').value = newcat;
    document.getElementById('lg-qb-action').value   = 'bulkmove';
    document.getElementById('lg-qb-form').submit();
}
function lgQbBulkChangeMark() {
    var sel  = lgQbGetSelected();
    var markVal = document.getElementById('lg-qb-newmark').value;
    var mark = parseFloat(markVal);
    if (!sel.length) { alert('Select at least one question first.'); return; }
    if (!markVal || isNaN(mark) || mark <= 0) { alert('Enter a valid mark greater than 0.'); return; }
    if (!confirm('Change marks to ' + mark + ' for ' + sel.length + ' question(s)?\n\nAll existing attempt scores using these questions will be recalculated automatically.')) return;
    document.getElementById('lg-qb-action').value = 'bulkchangemark';
    document.getElementById('lg-qb-form').submit();
}
function lgQbBulkShuffle(val) {
    var sel = lgQbGetSelected();
    if (!sel.length) { alert('Select at least one question first.'); return; }
    var label = val ? 'ON' : 'OFF';
    if (!confirm('Turn shuffle answers ' + label + ' for ' + sel.length + ' selected question(s)?')) return;
    document.getElementById('lg-qb-shuffleval').value = val;
    document.getElementById('lg-qb-action').value     = 'bulkshuffle';
    document.getElementById('lg-qb-form').submit();
}
function lgQbShuffleAll(val) {
    var label = val ? 'ON' : 'OFF';
    if (!confirm('Turn shuffle answers ' + label + ' for ALL MCQ/multicheck questions in this category?')) return;
    document.getElementById('lg-qb-shuffleval').value = val;
    document.getElementById('lg-qb-action').value     = 'shuffleall';
    document.getElementById('lg-qb-form').submit();
}
function lgQbExportSelected() {
    var sel = lgQbGetSelected();
    if (!sel.length) { alert('Select at least one question first.'); return; }
    // Build a form that POSTs selected IDs to export_questions.php
    var f = document.createElement('form');
    f.method = 'post';
    f.action = '<?php echo (new moodle_url('/mod/learnguard/export_questions.php'))->out(false); ?>';
    f.target = '_blank';
    [['format','csv'],['sesskey','<?php echo sesskey(); ?>']].forEach(function(p) {
        var i = document.createElement('input'); i.type='hidden'; i.name=p[0]; i.value=p[1]; f.appendChild(i);
    });
    sel.forEach(function(qid) {
        var i = document.createElement('input'); i.type='hidden'; i.name='qids[]'; i.value=qid; f.appendChild(i);
    });
    document.body.appendChild(f); f.submit(); document.body.removeChild(f);
}
function lgRenameCategory(catId, currentName) {
    var newName = window.prompt('Rename category:', currentName);
    if (newName === null || newName.trim() === '' || newName.trim() === currentName) { return; }
    document.getElementById('lg-rename-cat-id').value   = catId;
    document.getElementById('lg-rename-cat-name').value = newName.trim();
    document.getElementById('lg-rename-cat-form').submit();
}
function lgQbDeleteOne(qid) {
    if (!confirm('Delete this question? This cannot be undone.')) { return; }
    document.getElementById('lg-qb-action').value = 'deleteq';
    document.getElementById('lg-qb-qid').value    = qid;
    document.getElementById('lg-qb-form').submit();
}
var lgPreviewBase = '<?php echo (new moodle_url('/mod/learnguard/questionbank.php', array_filter(['id' => $id, 'courseid' => $courseid])))->out(false); ?>';
var lgPreviewSesskey = '<?php echo sesskey(); ?>';
var lgPreviewLetters = ['A','B','C','D','E','F','G','H'];
function lgQbPreview(qid) {
    var modal = document.getElementById('lg-preview-modal');
    var body  = document.getElementById('lg-preview-body');
    modal.style.display = 'block';
    body.innerHTML = '<div style="text-align:center;color:#6c757d;padding:40px;">Loading preview…</div>';
    var sep = lgPreviewBase.indexOf('?') !== -1 ? '&' : '?';
    var url = lgPreviewBase + sep + 'lgajax=1&action=preview_question&qid=' + qid + '&sesskey=' + lgPreviewSesskey;
    fetch(url).then(function(r) {
        return r.text().then(function(txt) {
            try { return JSON.parse(txt); }
            catch(e) { throw new Error('Server returned non-JSON response:\n' + txt.substring(0, 200)); }
        });
    }).then(function(d) {
        if (!d.ok) { body.innerHTML = '<div class="alert alert-danger">Preview error: ' + (d.error || 'Unknown error') + '</div>'; return; }
        var title = document.getElementById('lg-preview-title');
        if (title) title.textContent = d.name;
        body.innerHTML = lgBuildPreview(d);
    }).catch(function(e) { body.innerHTML = '<div class="alert alert-danger">Preview failed: ' + (e.message || 'network error') + '</div>'; });
}
function lgClosePreview() { document.getElementById('lg-preview-modal').style.display = 'none'; }
function lgBuildPreview(q) {
    var bCol = {multichoice:'#0f6cbf',multicheck:'#6f42c1',truefalse:'#20c997',shortanswer:'#fd7e14',essay:'#795548',matching:'#17a2b8'};
    var bLbl = {multichoice:'Multiple Choice',multicheck:'Multi-select',truefalse:'True / False',shortanswer:'Short Answer',essay:'Essay',matching:'Matching'};
    var col = bCol[q.qtype] || '#6c757d';
    var html = '<div style="margin-bottom:12px;">';
    html += '<span style="background:' + col + ';color:#fff;padding:3px 10px;border-radius:10px;font-size:12px;font-weight:700;">' + (bLbl[q.qtype]||q.qtype) + '</span> ';
    html += '<span style="font-size:12px;color:#6c757d;">' + q.defaultmark + ' mark' + (q.defaultmark !== 1 ? 's' : '') + '</span></div>';
    html += '<div style="font-size:16px;font-weight:600;margin-bottom:16px;line-height:1.5;">' + q.questiontext + '</div>';
    if (q.qtype === 'multichoice') {
        q.answers.forEach(function(a, i) {
            var L = lgPreviewLetters[i] || String.fromCharCode(65+i);
            html += '<label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:2px solid #dee2e6;border-radius:8px;margin-bottom:8px;">';
            html += '<span style="width:28px;height:28px;min-width:28px;border-radius:50%;background:#f0f4ff;border:2px solid ' + col + ';display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:' + col + ';">' + L + '</span>';
            html += '<span>' + a.text + '</span></label>';
        });
    } else if (q.qtype === 'multicheck') {
        q.answers.forEach(function(a, i) {
            var L = lgPreviewLetters[i] || String.fromCharCode(65+i);
            html += '<label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:2px solid #dee2e6;border-radius:8px;margin-bottom:8px;">';
            html += '<span style="width:28px;height:28px;min-width:28px;border-radius:4px;background:#f0f4ff;border:2px solid ' + col + ';display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:' + col + ';">' + L + '</span>';
            html += '<span>' + a.text + '</span></label>';
        });
    } else if (q.qtype === 'truefalse') {
        ['True','False'].forEach(function(opt) {
            html += '<label style="display:flex;align-items:center;gap:12px;padding:10px 14px;border:2px solid #dee2e6;border-radius:8px;margin-bottom:8px;">';
            html += '<input type="radio" disabled style="width:18px;height:18px;"> <span style="font-size:15px;">' + opt + '</span></label>';
        });
    } else if (q.qtype === 'shortanswer') {
        html += '<input type="text" class="form-control" placeholder="Type your answer here…" disabled style="max-width:400px;">';
    } else if (q.qtype === 'essay') {
        html += '<textarea class="form-control" rows="6" placeholder="Type your essay response here…" disabled style="resize:vertical;"></textarea>';
    } else if (q.qtype === 'matching') {
        var lefts  = q.answers.filter(function(a){ return a.fraction < 0; });
        var rights = q.answers.filter(function(a){ return a.fraction >= 0; });
        html += '<table style="width:100%;border-collapse:collapse;">';
        lefts.forEach(function(l) {
            html += '<tr><td style="padding:8px;border:1px solid #dee2e6;width:50%;">' + l.text + '</td>';
            html += '<td style="padding:8px;border:1px solid #dee2e6;"><select class="form-control form-control-sm" disabled><option>— Select match —</option>';
            rights.forEach(function(r){ html += '<option>' + r.text + '</option>'; });
            html += '</select></td></tr>';
        });
        html += '</table>';
    }
    return html;
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') { lgClosePreview(); } });
</script>

<!-- Question Preview Modal -->
<div id="lg-preview-modal" onclick="if(event.target===this)lgClosePreview();"
    style="display:none;position:fixed;inset:0;z-index:9999;background:rgba(0,0,0,0.5);padding:20px;overflow-y:auto;">
    <div style="max-width:720px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 8px 32px rgba(0,0,0,0.2);overflow:hidden;">
        <div style="background:#1a3a5c;color:#fff;padding:14px 20px;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-weight:700;font-size:15px;" id="lg-preview-title">Question Preview</span>
            <button onclick="lgClosePreview()" style="background:none;border:none;color:#fff;font-size:22px;line-height:1;cursor:pointer;padding:0 4px;">✕</button>
        </div>
        <div id="lg-preview-body" style="padding:24px;min-height:180px;"></div>
        <div style="padding:10px 20px;background:#f8f9fa;border-top:1px solid #dee2e6;display:flex;justify-content:space-between;align-items:center;">
            <span style="font-size:12px;color:#6c757d;font-style:italic;">Student view (answers not revealed)</span>
            <button onclick="lgClosePreview()" class="btn btn-sm btn-secondary">Close</button>
        </div>
    </div>
</div>

<?php echo $OUTPUT->footer();
