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
 * Export LearnGuard question bank to CSV or JSON.
 *
 * CSV format (one row per answer option, blanks for subsequent options on same question):
 *   Question Name | Category | Type | Question Text | Answer Text | Fraction | Feedback | Tags
 *
 * JSON format: array of question objects each with answers[] array.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('mod/learnguard:manage', context_system::instance());
require_sesskey();

$format = optional_param('format', 'csv', PARAM_ALPHA);
$lgid   = optional_param('lgid', 0, PARAM_INT); // 0 = all, otherwise per assessment

$where = $lgid > 0 ? 'WHERE q.learnguard = ?' : '';
$params = $lgid > 0 ? [$lgid] : [];

$questions = $DB->get_records_sql(
    "SELECT q.id, q.name, q.qtype, q.questiontext, q.defaultmark, q.tags,
            q.timemodified, c.name AS catname, q.learnguard
       FROM {learnguard_qbank} q
  LEFT JOIN {learnguard_qbank_cats} c ON c.id = q.categoryid
       $where
   ORDER BY c.name ASC, q.name ASC",
    $params
);

// Pre-fetch all answers in one query.
$qids = array_keys($questions);
$answers = [];
if ($qids) {
    list($insql, $inparams) = $DB->get_in_or_equal($qids);
    $ansRows = $DB->get_records_sql(
        "SELECT * FROM {learnguard_qbank_answers} WHERE questionid $insql ORDER BY questionid, sortorder",
        $inparams
    );
    foreach ($ansRows as $ans) {
        $answers[$ans->questionid][] = $ans;
    }
}

$filename = 'learnguard_questions_' . date('Ymd_His') . '.' . ($format === 'json' ? 'json' : 'csv');
$mime     = $format === 'json' ? 'application/json' : 'text/csv';

header('Content-Type: ' . $mime . '; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');

if ($format === 'json') {
    $out = [];
    foreach ($questions as $q) {
        $qanswers = $answers[$q->id] ?? [];
        $ansArr   = array_values(array_map(function($a) {
            return [
                'answertext' => $a->answertext,
                'fraction'   => (float)$a->fraction,
                'feedback'   => $a->feedback ?? '',
                'sortorder'  => (int)$a->sortorder,
            ];
        }, $qanswers));
        $out[] = [
            'name'         => $q->name,
            'category'     => $q->catname ?? '',
            'qtype'        => $q->qtype,
            'questiontext' => strip_tags($q->questiontext),
            'defaultmark'  => (float)$q->defaultmark,
            'tags'         => $q->tags ?? '',
            'answers'      => $ansArr,
        ];
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// CSV output.
echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel.
$headers = ['Question Name', 'Category', 'Type', 'Question Text', 'Answer Text', 'Fraction (0-1)', 'Answer Feedback', 'Default Mark', 'Tags'];
echo '"' . implode('","', $headers) . '"' . "\n";

function qcsv($v) {
    return '"' . str_replace('"', '""', html_entity_decode(strip_tags((string)$v), ENT_QUOTES, 'UTF-8')) . '"';
}

foreach ($questions as $q) {
    $qanswers = $answers[$q->id] ?? [];
    if (empty($qanswers)) {
        // Question with no answers (essay, shortanswer).
        echo implode(',', [
            qcsv($q->name), qcsv($q->catname ?? ''), qcsv($q->qtype), qcsv($q->questiontext),
            '""', '""', '""', qcsv($q->defaultmark), qcsv($q->tags ?? ''),
        ]) . "\n";
    } else {
        $first = true;
        foreach ($qanswers as $a) {
            if ($first) {
                echo implode(',', [
                    qcsv($q->name), qcsv($q->catname ?? ''), qcsv($q->qtype), qcsv($q->questiontext),
                    qcsv($a->answertext), qcsv($a->fraction), qcsv($a->feedback ?? ''),
                    qcsv($q->defaultmark), qcsv($q->tags ?? ''),
                ]) . "\n";
                $first = false;
            } else {
                // Subsequent answer rows: blank question fields.
                echo implode(',', [
                    '""', '""', '""', '""',
                    qcsv($a->answertext), qcsv($a->fraction), qcsv($a->feedback ?? ''),
                    '""', '""',
                ]) . "\n";
            }
        }
    }
}
exit;
