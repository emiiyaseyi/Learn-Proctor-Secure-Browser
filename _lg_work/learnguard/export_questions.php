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

$format     = optional_param('format', 'csv', PARAM_ALPHA);
$lgid       = optional_param('lgid',   0,     PARAM_INT);
$selectedIds = optional_param_array('qids', [], PARAM_INT);
$selectedIds = array_filter(array_map('intval', $selectedIds));

if (!empty($selectedIds)) {
    // Export only selected question IDs
    list($insql, $params) = $DB->get_in_or_equal($selectedIds, SQL_PARAMS_NAMED);
    $where = 'WHERE q.id ' . $insql;
} elseif ($lgid > 0) {
    $where  = 'WHERE q.learnguard = :lgid';
    $params = ['lgid' => $lgid];
} else {
    $where  = '';
    $params = [];
}

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

$extMap  = ['json' => 'json', 'xml' => 'xml', 'gift' => 'txt', 'csv' => 'csv'];
$mimeMap = ['json' => 'application/json', 'xml' => 'application/xml', 'gift' => 'text/plain', 'csv' => 'text/csv'];
$ext     = $extMap[$format]  ?? 'csv';
$mime    = $mimeMap[$format] ?? 'text/csv';
$filename = 'learnguard_questions_' . date('Ymd_His') . '.' . $ext;

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

// ── MOODLE XML FORMAT ──────────────────────────────────────────────────────
// Compatible with Moodle's built-in "Moodle XML" import — importable into any
// Moodle quiz or question bank with no conversion needed.
if ($format === 'xml') {
    function xmle($v) { return htmlspecialchars(strip_tags((string)$v), ENT_XML1, 'UTF-8'); }
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n";
    $curCat = null;
    foreach ($questions as $q) {
        // Emit category header when it changes.
        if ($q->catname !== $curCat) {
            $curCat = $q->catname;
            echo '  <question type="category"><category><text>' .
                 xmle($curCat ?: 'LearnGuard') . '</text></category></question>' . "\n";
        }
        $qtypeMap = ['multichoice' => 'multichoice', 'multicheck' => 'multichoice',
                     'truefalse' => 'truefalse', 'shortanswer' => 'shortanswer',
                     'essay' => 'essay', 'matching' => 'matching'];
        $mtype = $qtypeMap[$q->qtype] ?? 'multichoice';
        $isSingle = ($q->qtype === 'multichoice' || $q->qtype === 'truefalse');
        $qanswers = $answers[$q->id] ?? [];
        echo '  <question type="' . xmle($mtype) . '">' . "\n";
        echo '    <name><text>' . xmle($q->name) . '</text></name>' . "\n";
        echo '    <questiontext format="html"><text><![CDATA[' . $q->questiontext . ']]></text></questiontext>' . "\n";
        echo '    <defaultgrade>' . number_format((float)$q->defaultmark, 7, '.', '') . '</defaultgrade>' . "\n";
        if ($mtype === 'multichoice') {
            echo '    <single>' . ($isSingle ? 'true' : 'false') . '</single>' . "\n";
        }
        foreach ($qanswers as $a) {
            $frac = ($a->fraction >= 1) ? '100' : ($a->fraction > 0 ? number_format($a->fraction * 100, 5) : '0');
            echo '    <answer fraction="' . $frac . '" format="html">' . "\n";
            echo '      <text><![CDATA[' . $a->answertext . ']]></text>' . "\n";
            if (!empty($a->feedback)) {
                echo '      <feedback format="html"><text><![CDATA[' . $a->feedback . ']]></text></feedback>' . "\n";
            }
            echo '    </answer>' . "\n";
        }
        echo '  </question>' . "\n";
    }
    echo '</quiz>' . "\n";
    exit;
}

// ── GIFT FORMAT ────────────────────────────────────────────────────────────
// Moodle GIFT is a text format importable into Moodle Quiz and many other LMS.
// Supports multiple choice, true/false, short answer, essay.
if ($format === 'gift') {
    function giftesc($v) { return str_replace(['~','=','{','}','#'], ['\\~','\\=','\\{','\\}','\\#'], strip_tags((string)$v)); }
    $curCat2 = null;
    foreach ($questions as $q) {
        if ($q->catname !== $curCat2) {
            $curCat2 = $q->catname;
            echo '$CATEGORY: $module$/LearnGuard/' . giftesc($curCat2 ?: 'General') . "\n\n";
        }
        $qanswers = $answers[$q->id] ?? [];
        echo '// ' . giftesc($q->name) . "\n";
        echo '::' . giftesc($q->name) . '::' . giftesc($q->questiontext) . "\n";
        switch ($q->qtype) {
            case 'multichoice':
            case 'multicheck':
                echo '{';
                foreach ($qanswers as $a) {
                    $prefix = $a->fraction >= 1.0 ? '=' : ($a->fraction > 0 ? '%' . round($a->fraction * 100) . '%' : '~');
                    echo $prefix . giftesc($a->answertext) . ' ';
                }
                echo "}\n\n";
                break;
            case 'truefalse':
                $correct = '';
                foreach ($qanswers as $a) {
                    if ($a->fraction >= 1.0) { $correct = strtolower(trim(strip_tags($a->answertext))); break; }
                }
                echo '{' . ($correct === 'true' ? 'TRUE' : 'FALSE') . "}\n\n";
                break;
            case 'shortanswer':
                echo '{';
                foreach ($qanswers as $a) {
                    if ($a->fraction >= 1.0) { echo '=' . giftesc($a->answertext) . ' '; }
                }
                echo "}\n\n";
                break;
            case 'essay':
                echo "{}\n\n";
                break;
            default:
                echo "// (type '{$q->qtype}' not supported in GIFT)\n\n";
        }
    }
    exit;
}

// ── CSV FORMAT ─────────────────────────────────────────────────────────────
echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel.
$headers = ['Question Name', 'Category', 'Type', 'Question Text', 'Answer Text', 'Fraction (0-1)', 'Answer Feedback', 'Default Mark', 'Tags'];
echo '"' . implode('","', $headers) . '"' . "\n";

function qcsv($v) {
    return '"' . str_replace('"', '""', html_entity_decode(strip_tags((string)$v), ENT_QUOTES, 'UTF-8')) . '"';
}

foreach ($questions as $q) {
    $qanswers = $answers[$q->id] ?? [];
    if (empty($qanswers)) {
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
