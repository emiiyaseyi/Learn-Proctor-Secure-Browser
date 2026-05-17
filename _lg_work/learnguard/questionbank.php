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

$PAGE->set_url('/mod/learnguard/questionbank.php',
    array_filter(['id' => $id, 'courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('LearnGuard Question Bank');
$PAGE->set_heading($course ? format_string($course->fullname) : 'LearnGuard Question Bank');
$PAGE->requires->css('/mod/learnguard/styles.css');

// ---- POST actions ----
if (data_submitted() && confirm_sesskey()) {
    if ($action === 'deleteq' && $qid > 0) {
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
}

$brand = learnguard_brand();

echo $OUTPUT->header();
if ($id) { echo learnguard_nav_bar($id, 'Question Bank'); }
else { echo learnguard_nav_bar(0, 'Question Bank'); }
echo learnguard_banner('LearnGuard Question Bank',
    'Create, import and manage questions for all assessments.', '🏦');

// ---- Action bar ----
echo html_writer::start_div('d-flex gap-2 flex-wrap mb-3');
echo html_writer::link(
    new moodle_url('/mod/learnguard/questionedit.php',
        array_filter(['id' => $id, 'courseid' => $courseid])),
    '+ New Question',
    ['class' => 'btn btn-primary']
);
echo html_writer::link(
    new moodle_url('/mod/learnguard/questionimport.php',
        array_filter(['id' => $id, 'courseid' => $courseid])),
    '📥 Import Questions',
    ['class' => 'btn btn-outline-secondary']
);
if ($id) {
    echo html_writer::link(
        new moodle_url('/mod/learnguard/editquestions.php', ['id' => $id]),
        '📋 Manage Assessment Questions',
        ['class' => 'btn btn-outline-dark']
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
    echo html_writer::start_div('', ['style' =>
        'display:flex;align-items:center;justify-content:space-between;' .
        'padding:6px 10px;border-radius:6px;margin-bottom:2px;' .
        ($active ? 'background:' . $brand->primary . ';color:#fff;' : 'background:#f8f9fa;')]);
    echo html_writer::link(
        new moodle_url('/mod/learnguard/questionbank.php',
            array_filter(['id' => $id, 'courseid' => $courseid, 'cat' => $cat->id])),
        format_string($cat->name),
        ['style' => 'text-decoration:none;' . ($active ? 'color:#fff;' : 'color:#333;') . 'flex:1;font-size:14px;']
    );
    echo html_writer::tag('span', $count,
        ['style' => 'font-size:11px;' . ($active ? 'color:#fff;opacity:0.7;' : 'color:#6c757d;')]);
    echo html_writer::end_div();
}

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

echo html_writer::tag('div',
    count($questions) . ' question(s) found',
    ['style' => 'color:#6c757d;font-size:13px;margin-bottom:8px;']
);

if (empty($questions)) {
    echo html_writer::div('No questions found. Create your first question or import from a file.', 'alert alert-info');
} else {
    echo html_writer::start_tag('table', ['class' => 'generaltable w-100']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    foreach (['Question', 'Type', 'Category', 'Marks', 'Modified', 'Actions'] as $h) {
        echo html_writer::tag('th', $h, ['style' => 'padding:8px 10px;font-size:13px;']);
    }
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');

    foreach ($questions as $q) {
        $tinfo = $qtypeLabels[$q->qtype] ?? ['label' => ucfirst($q->qtype), 'badge' => '#6c757d'];
        echo html_writer::start_tag('tr');

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
        // Delete.
        echo html_writer::start_tag('form', ['method' => 'post', 'style' => 'display:inline;']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',  'value' => 'deleteq']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'qid',     'value' => $q->id]);
        if ($id)       { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',       'value' => $id]); }
        if ($courseid) { echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]); }
        echo html_writer::empty_tag('input', [
            'type' => 'submit', 'value' => '🗑',
            'class' => 'btn btn-sm btn-outline-danger', 'title' => 'Delete',
            'onclick' => 'return confirm("Delete this question? This cannot be undone.");',
        ]);
        echo html_writer::end_tag('form');
        echo html_writer::end_tag('td');
        echo html_writer::end_tag('tr');
    }

    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
}

echo html_writer::end_div(); // question list
echo html_writer::end_div(); // flex container

echo $OUTPUT->footer();
