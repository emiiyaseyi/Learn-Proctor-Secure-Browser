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
 * Bulk import questions into LearnGuard's own question bank.
 *
 * Supported formats:
 *  - LearnGuard CSV (own format, most flexible)
 *  - GIFT (standard Moodle text format)
 *  - Aiken (simple multiple-choice plain text)
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id       = optional_param('id',       0, PARAM_INT);
$courseid = optional_param('courseid', 0, PARAM_INT);

if ($id) {
    $cm      = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
    $course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    $context = context_module::instance($cm->id);
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

$PAGE->set_url('/mod/learnguard/questionimport.php',
    array_filter(['id' => $id, 'courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_title('Import Questions');
$PAGE->set_heading($course ? format_string($course->fullname) : 'LearnGuard Question Bank');
$PAGE->requires->css('/mod/learnguard/styles.css');

// -----------------------------------------------------------------------
// Import logic
// -----------------------------------------------------------------------

function learnguard_import_csv(string $content, int $catid, int $userid): array {
    global $DB;
    $lines   = preg_split('/\r\n|\r|\n/', trim($content));
    $header  = array_map('strtolower', str_getcsv(array_shift($lines)));
    $imported = 0; $errors = [];
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;

    $required = ['type', 'question'];
    foreach ($required as $r) {
        if (!in_array($r, $header)) {
            return [0, ["Missing required column: $r"]];
        }
    }

    foreach ($lines as $ln => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        $row = array_combine($header, str_getcsv($line));
        if (!$row) { continue; }

        $qtype = trim(strtolower($row['type'] ?? 'multichoice'));
        $qtext = trim($row['question'] ?? '');
        if ($qtext === '') { $errors[] = "Row " . ($ln + 2) . ": empty question text"; continue; }
        $qtext = nl2br($qtext); // preserve newlines in HTML display

        $markingguide = trim($row['marking_guide'] ?? $row['markingguidance'] ?? '');
        $qid = $DB->insert_record('learnguard_qbank', (object)[
            'categoryid'            => $catid,
            'name'                  => mb_substr(strip_tags($qtext), 0, 120),
            'questiontext'          => $qtext,
            'questiontextformat'    => FORMAT_HTML,
            'qtype'                 => $qtype,
            'defaultmark'           => (float)($row['marks'] ?? $defaultmark),
            'shuffleanswers'        => 1,
            'generalfeedback'       => $row['feedback'] ?? '',
            'generalfeedbackformat' => FORMAT_HTML,
            'markingguidance'       => $markingguide,
            'markingguidanceformat' => FORMAT_HTML,
            'casesensitive'         => (strtolower(trim($row['case_sensitive'] ?? 'no')) === 'yes') ? 1 : 0,
            'timecreated'           => time(),
            'timemodified'          => time(),
            'createdby'             => $userid,
        ]);

        // Process answers based on type.
        if ($qtype === 'truefalse') {
            $correct = strtolower(trim($row['correct'] ?? 'true'));
            foreach (['true', 'false'] as $val) {
                $DB->insert_record('learnguard_qbank_answers', (object)[
                    'questionid' => $qid, 'answertext' => $val,
                    'answertextformat' => FORMAT_HTML,
                    'fraction' => ($val === $correct) ? 1.0 : 0.0,
                    'feedback' => '', 'sortorder' => ($val === 'true') ? 0 : 1,
                ]);
            }
        } elseif ($qtype === 'essay') {
            // No answers needed.
        } elseif ($qtype === 'shortanswer') {
            foreach (explode('|', $row['correct'] ?? '') as $ans) {
                $ans = trim($ans);
                if ($ans !== '') {
                    $DB->insert_record('learnguard_qbank_answers', (object)[
                        'questionid' => $qid, 'answertext' => $ans,
                        'answertextformat' => FORMAT_HTML,
                        'fraction' => 1.0, 'feedback' => '', 'sortorder' => 0,
                    ]);
                }
            }
        } else {
            // multichoice / multicheck: columns a1,a2,a3,a4... and correct = A,B,C,...
            $correctLetters = array_map('trim', explode(',', strtoupper($row['correct'] ?? 'A')));
            $sort = 0;
            foreach (['a', 'b', 'c', 'd', 'e', 'f'] as $letter) {
                $col  = 'a' . (ord($letter) - ord('a') + 1);
                $atext = trim($row[$col] ?? $row[$letter] ?? '');
                if ($atext === '') { continue; }
                $fraction = in_array(strtoupper($letter), $correctLetters) ? 1.0 : 0.0;
                $DB->insert_record('learnguard_qbank_answers', (object)[
                    'questionid' => $qid, 'answertext' => $atext,
                    'answertextformat' => FORMAT_HTML,
                    'fraction' => $fraction, 'feedback' => '', 'sortorder' => $sort++,
                ]);
            }
        }
        $imported++;
    }
    return [$imported, $errors];
}

function learnguard_import_gift(string $content, int $catid, int $userid): array {
    global $DB;
    // Strip comments.
    $content = preg_replace('/\/\/.*$/m', '', $content);
    // Split on double-newline between questions.
    $blocks = preg_split('/\n{2,}/', trim($content));
    $imported = 0; $errors = [];
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;

    foreach ($blocks as $block) {
        $block = trim($block);
        if ($block === '') { continue; }

        // Extract question title ::Title::.
        $title = '';
        if (preg_match('/^::(.+?)::/s', $block, $m)) {
            $title = trim($m[1]);
            $block = preg_replace('/^::(.+?)::/s', '', $block, 1);
        }

        // Find answer block { ... }.
        if (!preg_match('/\{(.*?)\}/s', $block, $am)) {
            // No answer block = essay or plain.
            $qtext = nl2br(trim($block));
            if ($qtext === '') { continue; }
            $qid = $DB->insert_record('learnguard_qbank', (object)[
                'categoryid' => $catid,
                'name'       => $title ?: mb_substr(strip_tags($qtext), 0, 100),
                'questiontext' => $qtext, 'questiontextformat' => FORMAT_HTML,
                'qtype' => 'essay', 'defaultmark' => $defaultmark, 'shuffleanswers' => 0,
                'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
                'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
            ]);
            $imported++;
            continue;
        }

        $qtext  = nl2br(trim(preg_replace('/\{.*?\}/s', '', $block)));
        $ansblk = trim($am[1]);

        // True/False?
        if (preg_match('/^(TRUE|FALSE|T|F)$/i', $ansblk, $tfm)) {
            $correct = strtolower($tfm[1][0]) === 't' ? 'true' : 'false';
            $qid = $DB->insert_record('learnguard_qbank', (object)[
                'categoryid' => $catid,
                'name'       => $title ?: mb_substr($qtext, 0, 100),
                'questiontext' => $qtext, 'questiontextformat' => FORMAT_HTML,
                'qtype' => 'truefalse', 'defaultmark' => $defaultmark, 'shuffleanswers' => 0,
                'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
                'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
            ]);
            foreach (['true', 'false'] as $val) {
                $DB->insert_record('learnguard_qbank_answers', (object)[
                    'questionid' => $qid, 'answertext' => $val, 'answertextformat' => FORMAT_HTML,
                    'fraction' => ($val === $correct) ? 1.0 : 0.0, 'feedback' => '',
                    'sortorder' => ($val === 'true') ? 0 : 1,
                ]);
            }
            $imported++;
            continue;
        }

        // Short answer = {...}.
        if ($ansblk[0] === '=') {
            $qid = $DB->insert_record('learnguard_qbank', (object)[
                'categoryid' => $catid,
                'name'       => $title ?: mb_substr($qtext, 0, 100),
                'questiontext' => $qtext, 'questiontextformat' => FORMAT_HTML,
                'qtype' => 'shortanswer', 'defaultmark' => $defaultmark, 'shuffleanswers' => 0,
                'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
                'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
            ]);
            preg_match_all('/=([^=~#\n]+)(?:#[^\n]*)?/u', $ansblk, $am2);
            $sort = 0;
            foreach ($am2[1] as $ans) {
                $DB->insert_record('learnguard_qbank_answers', (object)[
                    'questionid' => $qid, 'answertext' => trim($ans), 'answertextformat' => FORMAT_HTML,
                    'fraction' => 1.0, 'feedback' => '', 'sortorder' => $sort++,
                ]);
            }
            $imported++;
            continue;
        }

        // Multiple choice: lines starting with = (correct) or ~ (wrong).
        preg_match_all('/([=~])([^=~#\n]+)(?:#([^\n]*))?/u', $ansblk, $matches, PREG_SET_ORDER);
        if (!$matches) { $errors[] = "Could not parse: " . mb_substr($qtext, 0, 60); continue; }

        $qid = $DB->insert_record('learnguard_qbank', (object)[
            'categoryid' => $catid,
            'name'       => $title ?: mb_substr($qtext, 0, 100),
            'questiontext' => $qtext, 'questiontextformat' => FORMAT_HTML,
            'qtype' => 'multichoice', 'defaultmark' => $defaultmark, 'shuffleanswers' => 1,
            'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
        ]);
        $sort = 0;
        foreach ($matches as $m2) {
            $DB->insert_record('learnguard_qbank_answers', (object)[
                'questionid' => $qid, 'answertext' => trim($m2[2]), 'answertextformat' => FORMAT_HTML,
                'fraction' => ($m2[1] === '=') ? 1.0 : 0.0,
                'feedback' => trim($m2[3] ?? ''), 'sortorder' => $sort++,
            ]);
        }
        $imported++;
    }
    return [$imported, $errors];
}

function learnguard_import_aiken(string $content, int $catid, int $userid): array {
    global $DB;
    $lines = preg_split('/\r\n|\r|\n/', trim($content));
    $imported = 0; $errors = [];
    $qlines = []; $options = []; $answer = ''; $currentOption = null;
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;

    $flush = function() use (&$qlines, &$options, &$answer, &$currentOption, &$imported, $catid, $userid, $DB, $defaultmark) {
        if (!$qlines || !$options || !$answer) {
            $qlines = []; $options = []; $answer = ''; $currentOption = null; return;
        }
        $qtext = lg_join_html_lines($qlines);
        $qid = $DB->insert_record('learnguard_qbank', (object)[
            'categoryid' => $catid,
            'name'       => mb_substr(strip_tags($qtext), 0, 100),
            'questiontext' => $qtext, 'questiontextformat' => FORMAT_HTML,
            'qtype' => 'multichoice', 'defaultmark' => $defaultmark, 'shuffleanswers' => 1,
            'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_HTML,
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
        ]);
        $sort = 0;
        foreach ($options as $letter => $text) {
            $DB->insert_record('learnguard_qbank_answers', (object)[
                'questionid' => $qid, 'answertext' => $text, 'answertextformat' => FORMAT_HTML,
                'fraction' => (strtoupper($letter) === strtoupper($answer)) ? 1.0 : 0.0,
                'feedback' => '', 'sortorder' => $sort++,
            ]);
        }
        $imported++;
        $qlines = []; $options = []; $answer = ''; $currentOption = null;
    };

    foreach ($lines as $line) {
        $line = trim($line);
        // Blank lines are just spacing in Aiken — ANSWER: is the sole question terminator.
        // Word/browser formatting often produces blank lines between every line, so we skip them.
        if ($line === '') { continue; }
        // New option starts: A) or A.  (letter followed by ) or .)
        // Require non-empty text after the letter — a line like "Y." with no text
        // is continuation text from the previous option (e.g. "Company Y." split by Word).
        if (preg_match('/^([A-Z])[.)]\s*(.*)$/i', $line, $m) && trim($m[2]) !== '') {
            $currentOption = strtoupper($m[1]);
            $options[$currentOption] = trim($m[2]);
        } elseif (preg_match('/^ANSWER:\s*([A-Z])\b\s*(.*)/is', $line, $m)) {
            $answer = strtoupper($m[1]);
            $currentOption = null;
            $flush();
            $leftover = trim($m[2]);
            if ($leftover !== '') { $qlines[] = $leftover; }
        } elseif ($currentOption !== null) {
            // Continuation of the current option (word-wrapped from Word/PDF,
            // or a bare "Y." that is part of the previous option text).
            if ($options[$currentOption] !== '') { $options[$currentOption] .= ' '; }
            $options[$currentOption] .= $line;
        } else {
            // Question body (before the first option), including tables and images.
            $qlines[] = $line;
        }
    }
    $flush();
    return [$imported, $errors];
}

function learnguard_import_choiceessay(string $content, int $catid, int $userid): array {
    global $DB;
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;
    $imported = 0; $errors = [];

    // Split into CE question blocks on === (3+ equals) or on 3+ consecutive blank lines.
    $questionBlocks = preg_split('/\n={3,}\n|\n{3,}/', $content);

    foreach ($questionBlocks as $block) {
        $block = trim($block);
        if ($block === '') { continue; }

        // Parse optional header directives from leading lines.
        $title     = '';
        $marks     = $defaultmark;
        $showCount = 0;
        $guide     = '';
        $lines     = preg_split('/\r\n|\r|\n/', $block);
        $bodyStart = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^TITLE:\s*(.+)$/i', trim($line), $m)) {
                $title = trim($m[1]); $bodyStart = $i + 1;
            } elseif (preg_match('/^MARKS?:\s*([\d.]+)/i', trim($line), $m)) {
                $marks = (float)$m[1]; $bodyStart = $i + 1;
            } elseif (preg_match('/^SHOW:\s*(\d+)/i', trim($line), $m)) {
                $showCount = (int)$m[1]; $bodyStart = $i + 1;
            } elseif (preg_match('/^GUIDE:\s*(.*)$/i', trim($line), $m)) {
                $guide = trim($m[1]); $bodyStart = $i + 1;
            } elseif (trim($line) === '' && $bodyStart === $i) {
                // Skip blank lines immediately after headers.
                $bodyStart = $i + 1;
            } else {
                break; // First non-directive non-blank line ends the header section.
            }
        }

        // Remaining lines after headers are prompt blocks separated by blank lines.
        $bodyLines = array_slice($lines, $bodyStart);
        $bodyText  = implode("\n", $bodyLines);
        $promptRaw = preg_split('/\n{2,}/', trim($bodyText));
        $prompts   = [];
        foreach ($promptRaw as $raw) {
            $raw = trim($raw);
            if ($raw === '') { continue; }
            // Multi-line prompts: join with <br>.
            $promptLines = preg_split('/\r\n|\r|\n/', $raw);
            $promptHtml  = implode('<br>', array_filter(array_map('trim', $promptLines), fn($l) => $l !== ''));
            if ($promptHtml !== '') { $prompts[] = $promptHtml; }
        }

        if (empty($prompts)) {
            $errors[] = 'No prompts found in block: ' . mb_substr($block, 0, 60);
            continue;
        }
        if (count($prompts) < 2) {
            $errors[] = 'Choice Essay needs at least 2 prompts (found 1): ' . mb_substr($block, 0, 60);
            continue;
        }

        // Build question name from title or first prompt.
        $qname = $title ?: mb_substr(strip_tags($prompts[0]), 0, 100);

        $qid = $DB->insert_record('learnguard_qbank', (object)[
            'categoryid'          => $catid,
            'name'                => $qname,
            'questiontext'        => 'Choose one of the following prompts to answer.',
            'questiontextformat'  => FORMAT_HTML,
            'qtype'               => 'choiceessay',
            'defaultmark'         => $marks,
            'shuffleanswers'      => 1,
            'show_prompts_count'  => $showCount,
            'generalfeedback'     => '',
            'generalfeedbackformat' => FORMAT_HTML,
            'markingguidance'     => $guide,
            'markingguidanceformat' => FORMAT_HTML,
            'timecreated'         => time(),
            'timemodified'        => time(),
            'createdby'           => $userid,
        ]);

        foreach ($prompts as $sort => $ptext) {
            $DB->insert_record('learnguard_ce_prompts', (object)[
                'questionid'       => $qid,
                'prompttext'       => $ptext,
                'prompttextformat' => FORMAT_HTML,
                'sortorder'        => $sort,
            ]);
        }
        $imported++;
    }
    return [$imported, $errors];
}

function learnguard_import_shortanswer(string $content, int $catid, int $userid): array {
    global $DB;
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;
    // Questions separated by blank lines; each block has question text then "Answer: v1 or v2".
    $blocks = preg_split('/\n{2,}/', trim($content));
    $imported = 0; $errors = [];

    foreach ($blocks as $blockraw) {
        $block = trim($blockraw);
        if ($block === '') { continue; }
        $lines = preg_split('/\r\n|\r|\n/', $block);
        $qlines = []; $answers = []; $marks = $defaultmark; $casesensitive = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') { continue; }
            if (preg_match('/^Answer:\s*(.+)$/i', $line, $m)) {
                // Support multiple "Answer:" lines AND "Answer: var1 or var2" on the same line.
                foreach (preg_split('/\s+or\s+/i', trim($m[1])) as $ans) {
                    $ans = trim($ans);
                    if ($ans !== '') { $answers[] = $ans; }
                }
            } elseif (preg_match('/^Marks?\s*:\s*([\d.]+)/i', $line, $m)) {
                $marks = (float)$m[1];
            } elseif (preg_match('/^Case:\s*sensitive/i', $line)) {
                $casesensitive = 1;
            } else {
                $qlines[] = $line;
            }
        }

        $qtext = lg_join_html_lines($qlines);
        if ($qtext === '') { continue; }
        if (empty($answers)) {
            $errors[] = 'No "Answer:" line for: ' . mb_substr($qtext, 0, 60);
            continue;
        }

        $qid = $DB->insert_record('learnguard_qbank', (object)[
            'categoryid'            => $catid,
            'name'                  => mb_substr($qtext, 0, 120),
            'questiontext'          => $qtext,
            'questiontextformat'    => FORMAT_HTML,
            'qtype'                 => 'shortanswer',
            'defaultmark'           => $marks,
            'shuffleanswers'        => 0,
            'generalfeedback'       => '',
            'generalfeedbackformat' => FORMAT_HTML,
            'markingguidance'       => '',
            'markingguidanceformat' => FORMAT_HTML,
            'casesensitive'         => $casesensitive,
            'timecreated'           => time(),
            'timemodified'          => time(),
            'createdby'             => $userid,
        ]);

        $sort = 0;
        foreach ($answers as $ans) {
            $ans = trim($ans);
            if ($ans !== '') {
                $DB->insert_record('learnguard_qbank_answers', (object)[
                    'questionid'       => $qid,
                    'answertext'       => $ans,
                    'answertextformat' => FORMAT_HTML,
                    'fraction'         => 1.0,
                    'feedback'         => '',
                    'sortorder'        => $sort++,
                ]);
            }
        }
        $imported++;
    }
    return [$imported, $errors];
}

function learnguard_import_essay(string $content, int $catid, int $userid): array {
    global $DB;
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;

    // Supported formats (both accepted simultaneously):
    //
    // Inline format (preferred):
    //   Question text
    //   Mark: 3
    //   Answer guide: Guide text here
    //
    // Block format (legacy):
    //   Question text [N Marks]
    //   <blank line>
    //   Answer/marking guide:
    //   Guide text here
    //
    // Guide is stored for graders only; students never see it.

    $blocks = preg_split('/\n{2,}/', trim($content));
    $imported = 0; $errors = [];

    // Used by the legacy block format only.
    $pending_qtext = null;
    $pending_marks = $defaultmark;

    // Regex matching any guide/answer header line.
    $guide_re = '/^(?:Answer\s*(?:guide|\/\s*marking\s*guide)?)\s*:\s*(.*)/i';
    // Regex matching a standalone guide block (entire block is the guide).
    $block_guide_re = '/^Answer\s*(?:\/\s*marking\s*guide\s*)?:/i';

    foreach ($blocks as $blockraw) {
        $block = trim($blockraw);
        if ($block === '') { continue; }

        // Legacy block format: this entire block is a guide for the pending question.
        if (preg_match($block_guide_re, $block) && $pending_qtext !== null) {
            $guide = trim(preg_replace('/^Answer\s*(?:\/\s*marking\s*guide\s*)?:\s*/i', '', $block, 1));
            learnguard_essay_insert($DB, $catid, $userid, $pending_qtext, $pending_marks, $guide);
            $imported++;
            $pending_qtext = null;
            $pending_marks = $defaultmark;
            continue;
        }

        // Parse the block line-by-line, looking for inline Mark: / Answer guide: metadata.
        $lines     = preg_split('/\n/', $block);
        $qlines    = [];
        $guide     = '';
        $marks     = $defaultmark;
        $in_guide  = false;

        foreach ($lines as $line) {
            if ($in_guide) {
                // Continuation of a multi-line answer guide.
                $guide .= ($guide !== '' ? ' ' : '') . trim($line);
            } elseif (preg_match($guide_re, $line, $gm)) {
                $guide    = trim($gm[1]);
                $in_guide = true;
            } elseif (preg_match('/^Marks?\s*:\s*([\d.]+)/i', $line, $mm)) {
                $marks = (float)$mm[1];
            } else {
                // Plain question text line; also check for [N Marks] inline.
                if (preg_match('/\[(\d+)\s*[Mm]arks?\]/', $line, $lm)) {
                    $marks = (float)$lm[1];
                }
                $qlines[] = trim($line);
            }
        }

        $qtext = lg_join_html_lines(array_filter($qlines, fn($l) => trim($l) !== ''));
        if ($qtext === '') { continue; }

        if ($guide !== '') {
            // Inline format: question and guide are in the same block.
            // Flush any legacy pending question first.
            if ($pending_qtext !== null) {
                learnguard_essay_insert($DB, $catid, $userid, $pending_qtext, $pending_marks, '');
                $imported++;
                $pending_qtext = null;
                $pending_marks = $defaultmark;
            }
            learnguard_essay_insert($DB, $catid, $userid, $qtext, $marks, $guide);
            $imported++;
        } else {
            // No inline guide found — legacy block format. Flush any prior pending question.
            if ($pending_qtext !== null) {
                learnguard_essay_insert($DB, $catid, $userid, $pending_qtext, $pending_marks, '');
                $imported++;
            }
            $pending_qtext = $qtext;
            $pending_marks = $marks;
        }
    }

    // Flush trailing question (no guide block followed it).
    if ($pending_qtext !== null) {
        learnguard_essay_insert($DB, $catid, $userid, $pending_qtext, $pending_marks, '');
        $imported++;
    }

    return [$imported, $errors];
}

function learnguard_essay_insert($DB, int $catid, int $userid, string $qtext, float $marks, string $guide): void {
    $DB->insert_record('learnguard_qbank', (object)[
        'categoryid'            => $catid,
        'name'                  => mb_substr($qtext, 0, 120),
        'questiontext'          => $qtext,
        'questiontextformat'    => FORMAT_HTML,
        'qtype'                 => 'essay',
        'defaultmark'           => $marks,
        'shuffleanswers'        => 0,
        'generalfeedback'       => '',
        'generalfeedbackformat' => FORMAT_HTML,
        'markingguidance'       => $guide,
        'markingguidanceformat' => FORMAT_HTML,
        'casesensitive'         => 0,
        'timecreated'           => time(),
        'timemodified'          => time(),
        'createdby'             => $userid,
    ]);
}

// -----------------------------------------------------------------------
// Preview infrastructure: parse-only functions, insert helper, renderer.
// -----------------------------------------------------------------------

/** Build a bare question stdClass for preview / insertion. */
function lg_make_q(string $qtype, string $qtext, float $mark): stdClass {
    $q = new stdClass;
    $q->qtype               = $qtype;
    $q->questiontext        = $qtext;
    $q->questiontextformat  = FORMAT_HTML;
    $q->name                = mb_substr(strip_tags($qtext), 0, 100) ?: $qtype;
    $q->defaultmark         = $mark;
    $q->shuffleanswers      = ($qtype === 'multichoice' || $qtype === 'multicheck') ? 1 : 0;
    $q->generalfeedback     = '';
    $q->markingguidance     = '';
    $q->casesensitive       = 0;
    $q->options             = [];   // MCQ/multicheck: [{letter, text, correct}]
    $q->variants            = [];   // SA/TF:          [{text, fraction}]
    $q->ce_prompts          = [];   // CE:             string[]
    $q->show_prompts_count  = 0;
    return $q;
}

/**
 * Join question-text lines into HTML, preserving tables and images without extra spacing.
 * Text lines get <br> between them. Lines that are (or immediately follow) block-level
 * HTML (table, img) are concatenated directly so no <br> is injected inside or adjacent
 * to a block element.
 */
function lg_join_html_lines(array $lines): string {
    $out = ''; $prev_block = false;
    foreach ($lines as $line) {
        $line = trim($line); if ($line === '') { continue; }
        // A line is "block HTML" if it opens or closes a table, or is a standalone img.
        $curr_block = (bool)preg_match('/^<(?:table|\/table|img)\b/i', $line);
        if ($out === '') {
            $out = $line;
        } elseif ($curr_block || $prev_block) {
            $out .= $line;  // no <br> separator adjacent to block elements
        } else {
            $out .= '<br>' . $line;
        }
        // Mark block if this line ends a table or is a whole-table or img line.
        $prev_block = $curr_block || (bool)preg_match('/<\/table>\s*$|<img\b[^>]*>\s*$/i', $line);
    }
    return $out;
}

/** Parse Aiken format — returns [stdClass[], string[]]. */
function lg_parse_aiken(string $content): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($content));
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;
    $questions = []; $errors = [];
    $qlines = []; $options = []; $answer = ''; $currentOption = null;

    $flush = function() use (&$qlines, &$options, &$answer, &$currentOption, &$questions, $defaultmark) {
        if (!$qlines || !$options || !$answer) {
            $qlines = []; $options = []; $answer = ''; $currentOption = null; return;
        }
        $q = lg_make_q('multichoice', lg_join_html_lines($qlines), $defaultmark);
        foreach ($options as $letter => $text) {
            $q->options[] = ['letter' => $letter, 'text' => $text,
                             'correct' => strtoupper($letter) === strtoupper($answer)];
        }
        $questions[] = $q;
        $qlines = []; $options = []; $answer = ''; $currentOption = null;
    };

    foreach ($lines as $line) {
        $line = trim($line);
        // Blank lines are just spacing in Aiken — ANSWER: is the sole terminator.
        if ($line === '') { continue; }
        // Require non-empty text after the letter — a bare "Y." with no text is
        // continuation of the previous option, not a new option.
        if (preg_match('/^([A-Z])[.)]\s*(.*)$/i', $line, $m) && trim($m[2]) !== '') {
            $currentOption = strtoupper($m[1]);
            $options[$currentOption] = trim($m[2]);
        } elseif (preg_match('/^ANSWER:\s*([A-Z])\b\s*(.*)/is', $line, $m)) {
            $answer = strtoupper($m[1]); $currentOption = null; $flush();
            // Any text after the answer letter on the same line is the start of the next question.
            // This handles cases where Answer: B and the next question ended up on one line.
            $leftover = trim($m[2]);
            if ($leftover !== '') { $qlines[] = $leftover; }
        } elseif ($currentOption !== null) {
            if ($options[$currentOption] !== '') { $options[$currentOption] .= ' '; }
            $options[$currentOption] .= $line;
        } else {
            // Question body — including tables, images, bullets, multi-line text.
            $qlines[] = $line;
        }
    }
    $flush();
    return [$questions, $errors];
}

/** Parse Short Answer format — returns [stdClass[], string[]]. */
function lg_parse_shortanswer(string $content): array {
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;
    $blocks = preg_split('/\n{2,}/', trim($content));
    $questions = []; $errors = [];

    foreach ($blocks as $blockraw) {
        $block = trim($blockraw); if ($block === '') { continue; }
        $lines = preg_split('/\r\n|\r|\n/', $block);
        $qlines = []; $answers = []; $marks = $defaultmark; $casesens = 0;
        foreach ($lines as $line) {
            $line = trim($line); if ($line === '') { continue; }
            if (preg_match('/^Answer:\s*(.+)$/i', $line, $m)) {
                foreach (preg_split('/\s+or\s+/i', trim($m[1])) as $a) {
                    $a = trim($a); if ($a !== '') { $answers[] = $a; }
                }
            } elseif (preg_match('/^Marks?\s*:\s*([\d.]+)/i', $line, $m)) {
                $marks = (float)$m[1];
            } elseif (preg_match('/^Case:\s*sensitive/i', $line)) {
                $casesens = 1;
            } else {
                $qlines[] = $line;
            }
        }
        $qtext = lg_join_html_lines($qlines);
        if ($qtext === '') { continue; }
        if (empty($answers)) { $errors[] = 'No "Answer:" for: ' . mb_substr($qtext, 0, 60); continue; }
        $q = lg_make_q('shortanswer', $qtext, $marks);
        $q->casesensitive = $casesens;
        foreach ($answers as $a) { $q->variants[] = ['text' => $a, 'fraction' => 1.0]; }
        $questions[] = $q;
    }
    return [$questions, $errors];
}

/** Parse Essay format — returns [stdClass[], string[]]. */
function lg_parse_essay(string $content): array {
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;
    $guide_re      = '/^(?:Answer\s*(?:guide|\/\s*marking\s*guide)?)\s*:\s*(.*)/i';
    $block_guide_re = '/^Answer\s*(?:\/\s*marking\s*guide\s*)?:/i';
    $blocks = preg_split('/\n{2,}/', trim($content));
    $questions = []; $errors = [];
    $pending = null; $pending_marks = $defaultmark;

    $addQ = function(string $qt, float $m, string $g) use (&$questions) {
        $q = lg_make_q('essay', $qt, $m); $q->markingguidance = $g; $questions[] = $q;
    };

    foreach ($blocks as $blockraw) {
        $block = trim($blockraw); if ($block === '') { continue; }
        if (preg_match($block_guide_re, $block) && $pending !== null) {
            $guide = trim(preg_replace('/^Answer\s*(?:\/\s*marking\s*guide\s*)?:\s*/i', '', $block, 1));
            $addQ($pending, $pending_marks, $guide);
            $pending = null; $pending_marks = $defaultmark; continue;
        }
        $lines = preg_split('/\n/', $block);
        $qlines = []; $guide = ''; $marks = $defaultmark; $in_guide = false;
        foreach ($lines as $line) {
            if ($in_guide) { $guide .= ($guide !== '' ? ' ' : '') . trim($line); }
            elseif (preg_match($guide_re, $line, $gm)) { $guide = trim($gm[1]); $in_guide = true; }
            elseif (preg_match('/^Marks?\s*:\s*([\d.]+)/i', $line, $mm)) { $marks = (float)$mm[1]; }
            else {
                if (preg_match('/\[(\d+)\s*[Mm]arks?\]/', $line, $lm)) { $marks = (float)$lm[1]; }
                $qlines[] = trim($line);
            }
        }
        $qtext = lg_join_html_lines(array_filter($qlines, fn($l) => trim($l) !== ''));
        if ($qtext === '') { continue; }
        if ($guide !== '') {
            if ($pending !== null) { $addQ($pending, $pending_marks, ''); }
            $addQ($qtext, $marks, $guide);
            $pending = null; $pending_marks = $defaultmark;
        } else {
            if ($pending !== null) { $addQ($pending, $pending_marks, ''); }
            $pending = $qtext; $pending_marks = $marks;
        }
    }
    if ($pending !== null) { $addQ($pending, $pending_marks, ''); }
    return [$questions, $errors];
}

/** Parse GIFT format — returns [stdClass[], string[]]. */
function lg_parse_gift(string $content): array {
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;
    $questions = []; $errors = [];
    $content = preg_replace('/\/\/[^\n]*/m', '', $content);
    $blocks  = preg_split('/\n{2,}/', trim($content));

    foreach ($blocks as $blockraw) {
        $block = trim($blockraw);
        if ($block === '' || !preg_match('/\{/', $block)) { continue; }
        $title = '';
        if (preg_match('/^::(.+?)::\s*/s', $block, $tm)) {
            $title = trim($tm[1]); $block = substr($block, strlen($tm[0]));
        }
        if (!preg_match('/^([\s\S]*?)\{([\s\S]*)\}[\s\S]*$/', $block, $bm)) { continue; }
        $qtext  = trim($bm[1]); $inside = trim($bm[2]);
        if ($qtext === '') { continue; }
        if ($title === '') { $title = mb_substr($qtext, 0, 100); }

        if (preg_match('/^(TRUE|FALSE|T|F)$/i', $inside, $tf)) {
            $q = lg_make_q('truefalse', $qtext, $defaultmark); $q->name = $title;
            $correct = strtoupper($tf[1][0]) === 'T';
            $q->variants = [
                ['text' => 'true',  'fraction' => $correct ? 1.0 : 0.0],
                ['text' => 'false', 'fraction' => $correct ? 0.0 : 1.0],
            ];
            $questions[] = $q; continue;
        }
        if (preg_match('/^=/', $inside) && !preg_match('/\n\s*[=~]/', $inside)) {
            preg_match_all('/=([^=#~}]+)/s', $inside, $am);
            if (!empty($am[1])) {
                $q = lg_make_q('shortanswer', $qtext, $defaultmark); $q->name = $title;
                foreach ($am[1] as $a) { $q->variants[] = ['text' => trim($a), 'fraction' => 1.0]; }
                $questions[] = $q; continue;
            }
        }
        preg_match_all('/([=~])\s*((?:(?![=~]).)+)/s', $inside, $am, PREG_SET_ORDER);
        if (!empty($am)) {
            $q = lg_make_q('multichoice', $qtext, $defaultmark); $q->name = $title;
            $letters = range('A', 'Z');
            foreach ($am as $i => $m2) {
                $q->options[] = [
                    'letter'  => $letters[$i] ?? chr(65 + $i),
                    'text'    => trim(preg_replace('/#.*$/s', '', $m2[2])),
                    'correct' => $m2[1] === '=',
                ];
            }
            $questions[] = $q;
        }
    }
    return [$questions, $errors];
}

/** Parse LearnGuard CSV format — returns [stdClass[], string[]]. */
function lg_parse_csv(string $content): array {
    $lines = preg_split('/\r\n|\r|\n/', trim($content));
    $header = array_map('strtolower', str_getcsv(array_shift($lines)));
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;
    $questions = []; $errors = [];
    if (!in_array('type', $header) || !in_array('question', $header)) {
        return [[], ['Missing required column: type or question']];
    }
    foreach ($lines as $line) {
        $line = trim($line); if ($line === '') { continue; }
        $cols = str_getcsv($line); $row = [];
        foreach ($header as $ci => $cn) { $row[$cn] = $cols[$ci] ?? ''; }
        $qtype = strtolower(trim($row['type']));
        $qtext = trim($row['question']);
        if (!$qtext || !$qtype) { continue; }
        $marks = (isset($row['marks']) && is_numeric(trim($row['marks']))) ? (float)trim($row['marks']) : $defaultmark;
        $q = lg_make_q($qtype, $qtext, $marks);
        $q->markingguidance = $row['marking_guide'] ?? '';
        $q->casesensitive = preg_match('/^yes$/i', trim($row['case_sensitive'] ?? '')) ? 1 : 0;
        if (in_array($qtype, ['multichoice', 'multicheck'])) {
            $corrects = str_split(preg_replace('/[^A-Z]/', '', strtoupper(trim($row['correct'] ?? ''))));
            for ($ci = 1; $ci <= 6; $ci++) {
                $v = trim($row["a$ci"] ?? ''); if ($v === '') { continue; }
                $letter = chr(64 + $ci);
                $q->options[] = ['letter' => $letter, 'text' => $v, 'correct' => in_array($letter, $corrects)];
            }
        } elseif ($qtype === 'truefalse') {
            $isTrue = in_array(strtolower(trim($row['correct'] ?? '')), ['true', 't', '1', 'yes']);
            $q->variants = [
                ['text' => 'true',  'fraction' => $isTrue ? 1.0 : 0.0],
                ['text' => 'false', 'fraction' => $isTrue ? 0.0 : 1.0],
            ];
        } elseif ($qtype === 'shortanswer') {
            foreach (preg_split('/\|/', trim($row['correct'] ?? '')) as $a) {
                $a = trim($a); if ($a !== '') { $q->variants[] = ['text' => $a, 'fraction' => 1.0]; }
            }
        }
        $questions[] = $q;
    }
    return [$questions, $errors];
}

/** Parse Choice Essay format — returns [stdClass[], string[]]. */
function lg_parse_choiceessay(string $content): array {
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;
    $questions = []; $errors = [];
    $blocks = preg_split('/\n={3,}\n|\n{3,}/', $content);

    foreach ($blocks as $block) {
        $block = trim($block); if ($block === '') { continue; }
        $title = ''; $marks = $defaultmark; $showCount = 0; $guide = '';
        $lines = preg_split('/\r\n|\r|\n/', $block);
        $bodyStart = 0;
        foreach ($lines as $i => $line) {
            if (preg_match('/^TITLE:\s*(.+)$/i', trim($line), $m)) { $title = trim($m[1]); $bodyStart = $i + 1; }
            elseif (preg_match('/^MARKS?:\s*([\d.]+)/i', trim($line), $m)) { $marks = (float)$m[1]; $bodyStart = $i + 1; }
            elseif (preg_match('/^SHOW:\s*(\d+)/i', trim($line), $m)) { $showCount = (int)$m[1]; $bodyStart = $i + 1; }
            elseif (preg_match('/^GUIDE:\s*(.*)$/i', trim($line), $m)) { $guide = trim($m[1]); $bodyStart = $i + 1; }
            elseif (trim($line) === '' && $bodyStart === $i) { $bodyStart = $i + 1; }
            else { break; }
        }
        $bodyText = implode("\n", array_slice($lines, $bodyStart));
        $prompts = [];
        foreach (preg_split('/\n{2,}/', trim($bodyText)) as $raw) {
            $raw = trim($raw); if ($raw === '') { continue; }
            $pHtml = implode('<br>', array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $raw)), fn($l) => $l !== ''));
            if ($pHtml !== '') { $prompts[] = $pHtml; }
        }
        if (count($prompts) < 2) {
            $errors[] = 'CE needs ≥2 prompts: ' . mb_substr($block, 0, 60); continue;
        }
        $q = lg_make_q('choiceessay', 'Choose one of the following prompts to answer.', $marks);
        $q->name = $title ?: mb_substr(strip_tags($prompts[0]), 0, 100);
        $q->ce_prompts = $prompts;
        $q->show_prompts_count = $showCount;
        $q->markingguidance = $guide;
        $questions[] = $q;
    }
    return [$questions, $errors];
}

/** Dispatcher — parse content for the given format. */
function lg_parse_questions(string $format, string $content): array {
    return match($format) {
        'aiken'       => lg_parse_aiken($content),
        'shortanswer' => lg_parse_shortanswer($content),
        'essay'       => lg_parse_essay($content),
        'gift'        => lg_parse_gift($content),
        'csv'         => lg_parse_csv($content),
        'choiceessay' => lg_parse_choiceessay($content),
        default       => [[], ['Unknown format: ' . $format]],
    };
}

/** Insert one parsed question stdClass into the DB. */
function lg_insert_parsed_q(stdClass $q, int $catid, int $userid): bool {
    global $DB;
    $qid = $DB->insert_record('learnguard_qbank', (object)[
        'categoryid'            => $catid,
        'name'                  => $q->name,
        'questiontext'          => $q->questiontext,
        'questiontextformat'    => $q->questiontextformat ?? FORMAT_HTML,
        'qtype'                 => $q->qtype,
        'defaultmark'           => $q->defaultmark,
        'shuffleanswers'        => $q->shuffleanswers ?? 0,
        'generalfeedback'       => $q->generalfeedback ?? '',
        'generalfeedbackformat' => FORMAT_HTML,
        'markingguidance'       => $q->markingguidance ?? '',
        'markingguidanceformat' => FORMAT_HTML,
        'show_prompts_count'    => $q->show_prompts_count ?? 0,
        'casesensitive'         => $q->casesensitive ?? 0,
        'timecreated'           => time(),
        'timemodified'          => time(),
        'createdby'             => $userid,
    ]);
    if (!$qid) { return false; }
    foreach ($q->options ?? [] as $sort => $opt) {
        $DB->insert_record('learnguard_qbank_answers', (object)[
            'questionid'       => $qid,
            'answertext'       => $opt['text'],
            'answertextformat' => FORMAT_HTML,
            'fraction'         => $opt['correct'] ? 1.0 : 0.0,
            'feedback'         => '',
            'sortorder'        => $sort,
        ]);
    }
    foreach ($q->variants ?? [] as $sort => $v) {
        $DB->insert_record('learnguard_qbank_answers', (object)[
            'questionid'       => $qid,
            'answertext'       => $v['text'],
            'answertextformat' => FORMAT_HTML,
            'fraction'         => (float)$v['fraction'],
            'feedback'         => '',
            'sortorder'        => $sort,
        ]);
    }
    foreach ($q->ce_prompts ?? [] as $sort => $ptext) {
        $DB->insert_record('learnguard_ce_prompts', (object)[
            'questionid'       => $qid,
            'prompttext'       => $ptext,
            'prompttextformat' => FORMAT_HTML,
            'sortorder'        => $sort,
        ]);
    }
    return true;
}

/** Render one parsed question as a preview card (HTML). */
function lg_render_preview_q(stdClass $q, int $idx): string {
    $typeLabels = [
        'multichoice' => 'MCQ', 'multicheck' => 'Multi-Select',
        'truefalse'   => 'True/False', 'shortanswer' => 'Short Answer',
        'essay'       => 'Essay', 'choiceessay' => 'Choice Essay',
    ];
    $typeColors = [
        'multichoice' => '#0f6cbf', 'multicheck' => '#17a2b8',
        'truefalse'   => '#6f42c1', 'shortanswer' => '#28a745',
        'essay'       => '#fd7e14', 'choiceessay' => '#e83e8c',
    ];
    $typeName  = $typeLabels[$q->qtype] ?? $q->qtype;
    $typeColor = $typeColors[$q->qtype] ?? '#6c757d';

    $h  = '<div class="lg-preview-card" style="border:1px solid #dee2e6;border-radius:8px;';
    $h .= 'background:#fff;padding:16px 18px;margin-bottom:10px;">';
    // Header row: checkbox + type badge + mark.
    $h .= '<div style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;">';
    $h .= '<input type="checkbox" name="import_idx[]" value="' . (int)$idx . '" checked';
    $h .= ' style="margin-top:3px;width:17px;height:17px;cursor:pointer;flex-shrink:0;">';
    $h .= '<span style="background:' . $typeColor . ';color:#fff;padding:2px 10px;border-radius:12px;';
    $h .= 'font-size:11px;font-weight:700;white-space:nowrap;">' . s($typeName) . '</span>';
    $h .= '<span style="color:#6c757d;font-size:12px;white-space:nowrap;">' . number_format((float)$q->defaultmark, 1) . ' mark(s)</span>';
    $h .= '</div>';
    // Question text.
    $h .= '<div style="line-height:1.65;margin-bottom:10px;">' . format_text($q->questiontext, FORMAT_HTML) . '</div>';
    // MCQ / multicheck options.
    if (in_array($q->qtype, ['multichoice', 'multicheck']) && !empty($q->options)) {
        foreach ($q->options as $opt) {
            $bg = $opt['correct'] ? '#d4edda' : '#f8f9fa';
            $br = $opt['correct'] ? '#28a745' : '#dee2e6';
            $h .= '<div style="display:flex;gap:10px;padding:7px 12px;border-radius:6px;margin-bottom:4px;background:' . $bg . ';border:1px solid ' . $br . ';">';
            $h .= '<strong style="min-width:20px;flex-shrink:0;">' . s($opt['letter']) . ')</strong>';
            $h .= '<span>' . nl2br(s($opt['text'])) . '</span>';
            if ($opt['correct']) { $h .= '<span style="margin-left:auto;font-weight:700;color:#155724;flex-shrink:0;">✓</span>'; }
            $h .= '</div>';
        }
    }
    // True/False.
    if ($q->qtype === 'truefalse' && !empty($q->variants)) {
        foreach ($q->variants as $v) {
            $correct = (float)$v['fraction'] >= 1.0;
            $bg = $correct ? '#d4edda' : '#f8f9fa';
            $br = $correct ? '#28a745' : '#dee2e6';
            $h .= '<div style="padding:6px 12px;border-radius:6px;margin-bottom:4px;background:' . $bg . ';border:1px solid ' . $br . ';">';
            $h .= ucfirst($v['text']) . ($correct ? ' <strong style="color:#155724;">✓</strong>' : '') . '</div>';
        }
    }
    // Short answer accepted values.
    if ($q->qtype === 'shortanswer' && !empty($q->variants)) {
        $h .= '<div style="background:#d1ecf1;border:1px solid #bee5eb;border-radius:6px;padding:7px 12px;font-size:13px;margin-top:4px;">';
        $h .= '<strong>Accepted:</strong> ' . implode(' <span style="color:#6c757d;">/</span> ', array_map(fn($v) => s($v['text']), $q->variants));
        $h .= '</div>';
    }
    // CE prompts.
    if ($q->qtype === 'choiceessay' && !empty($q->ce_prompts)) {
        $cnt = count($q->ce_prompts); $show = (int)($q->show_prompts_count ?? 0);
        $h .= '<div style="margin-top:8px;font-size:12px;color:#6c757d;margin-bottom:4px;">';
        $h .= 'Shows ' . ($show ?: 'all') . ' of ' . $cnt . ' prompt(s) per student</div>';
        foreach ($q->ce_prompts as $pi => $ptext) {
            $h .= '<div style="border:1px solid #dee2e6;border-radius:5px;padding:7px 10px;margin-bottom:4px;font-size:13px;background:#fafafa;">';
            $h .= '<strong style="color:#6c757d;font-size:11px;">Prompt ' . ($pi + 1) . ':</strong> ';
            $h .= format_text($ptext, FORMAT_HTML) . '</div>';
        }
    }
    // Marking guide.
    if (!empty($q->markingguidance)) {
        $h .= '<div style="margin-top:8px;background:#fff3cd;border:1px solid #ffc107;border-radius:6px;padding:7px 12px;font-size:12px;">';
        $h .= '<strong>Marking guide:</strong> ' . nl2br(s($q->markingguidance)) . '</div>';
    }
    $h .= '</div>';
    return $h;
}

// -----------------------------------------------------------------------
// Sample template download
// -----------------------------------------------------------------------
$downloadtpl = optional_param('download', '', PARAM_ALPHA);
if ($downloadtpl === 'template') {
    $tplfmt  = optional_param('fmt', 'csv', PARAM_ALPHA);
    $samples = [
        'csv' => [
            'filename' => 'learnguard_csv_template.csv',
            'mime'     => 'text/csv; charset=utf-8',
            'content'  =>
                "type,question,a1,a2,a3,a4,correct,marks,case_sensitive,marking_guide\r\n" .
                "multichoice,What is the capital of France?,London,Berlin,Paris,Madrid,C,1,,\r\n" .
                "truefalse,The Earth orbits the Sun.,,,,,true,1,,\r\n" .
                "shortanswer,Name the largest planet.,,,,,Jupiter|jupiter,2,no,\r\n" .
                "shortanswer,Type the exact command to list files.,,,,,ls,1,yes,\r\n" .
                "essay,Describe the water cycle.,,,,,,5,,Award marks for: evaporation condensation precipitation runoff.\r\n",
        ],
        'shortanswer' => [
            'filename' => 'learnguard_shortanswer_template.txt',
            'mime'     => 'text/plain; charset=utf-8',
            'content'  =>
                "Generally, an initial public offering (IPO) is sold in the ______ Market.\r\n" .
                "Answer: Primary Market\r\n" .
                "Answer: Market\r\n" .
                "Answer: PM\r\n" .
                "\r\n" .
                "Meristem has ____ subsidiaries\r\n" .
                "Answer: six\r\n" .
                "Answer: 6\r\n" .
                "\r\n" .
                "Type the exact UNIX command to list files in a directory.\r\n" .
                "Answer: ls\r\n" .
                "Case: sensitive\r\n" .
                "Marks: 2\r\n",
        ],
        'essay' => [
            'filename' => 'learnguard_essay_template.txt',
            'mime'     => 'text/plain; charset=utf-8',
            'content'  =>
                "Distinguish the money market and capital market with 2 examples each.\r\n" .
                "Mark: 3\r\n" .
                "Answer guide: The money market trades short-term securities (e.g. treasury bills, commercial papers). The capital market trades longer-term instruments (e.g. equities/shares, bonds).\r\n" .
                "\r\n" .
                "What are the four functions of money?\r\n" .
                "Mark: 4\r\n" .
                "Answer guide: 1. Medium of exchange. 2. Store of value. 3. Unit of account. 4. Standard of deferred payment.\r\n",
        ],
        'gift' => [
            'filename' => 'learnguard_gift_template.txt',
            'mime'     => 'text/plain; charset=utf-8',
            'content'  =>
                "::Q1:: What is 2+2? {\r\n" .
                "  =4 # Correct!\r\n" .
                "  ~2 # Wrong\r\n" .
                "  ~6 # Wrong\r\n" .
                "}\r\n" .
                "\r\n" .
                "The sky is blue. {TRUE}\r\n" .
                "\r\n" .
                "::Short:: Name a primary colour. {=red =blue =yellow}\r\n",
        ],
        'aiken' => [
            'filename' => 'learnguard_aiken_template.txt',
            'mime'     => 'text/plain; charset=utf-8',
            'content'  =>
                "What color is the sky?\r\n" .
                "A) Red\r\n" .
                "B) Blue\r\n" .
                "C) Green\r\n" .
                "D) Yellow\r\n" .
                "ANSWER: B\r\n" .
                "\r\n" .
                "How many days in a week?\r\n" .
                "A) 5\r\n" .
                "B) 7\r\n" .
                "C) 10\r\n" .
                "ANSWER: B\r\n",
        ],
        'choiceessay' => [
            'filename' => 'learnguard_choiceessay_template.txt',
            'mime'     => 'text/plain; charset=utf-8',
            'content'  =>
                "TITLE: Economic Policy Essay\r\n" .
                "MARKS: 10\r\n" .
                "SHOW: 2\r\n" .
                "\r\n" .
                "Analyze the impact of inflation on developing economies and suggest appropriate policy measures that governments in such economies can adopt. [10 marks]\r\n" .
                "\r\n" .
                "Discuss the role of monetary policy in managing economic downturns, with reference to the experience of at least two African countries. [10 marks]\r\n" .
                "\r\n" .
                "Evaluate the effectiveness of fiscal stimulus during recessions and identify conditions under which it is most successful. [10 marks]\r\n" .
                "\r\n" .
                "===\r\n" .
                "\r\n" .
                "TITLE: Constitutional Law Essay\r\n" .
                "MARKS: 15\r\n" .
                "SHOW: 2\r\n" .
                "\r\n" .
                "With reference to decided cases, examine the doctrine of judicial precedent as applied by Nigerian courts. [15 marks]\r\n" .
                "\r\n" .
                "Critically assess the constitutional provisions on fundamental rights under Nigeria's 1999 Constitution (as amended). [15 marks]\r\n" .
                "\r\n" .
                "Discuss the principle of separation of powers and its relevance to democratic governance. [15 marks]\r\n",
        ],
    ];
    $tpl = $samples[$tplfmt] ?? $samples['csv'];
    header('Content-Type: ' . $tpl['mime']);
    header('Content-Disposition: attachment; filename="' . $tpl['filename'] . '"');
    echo "\xEF\xBB\xBF" . $tpl['content'];
    exit;
}

// -----------------------------------------------------------------------
// Handle file upload
// -----------------------------------------------------------------------
// HTML→importable-text converter.
// Pasted content from the rich editor arrives as HTML (preserving Word tables
// and images). This function collapses that HTML into a line-based format
// that the plain-text parsers (Aiken, GIFT, etc.) can handle:
//   - <table> elements are collapsed to a single line (internal newlines removed)
//   - <img> tags are kept as single-line self-closing tags
//   - block-level closing tags (</p>, </div>, <br>, </li>, </tr>) → \n
//   - all remaining non-table/img HTML tags are stripped
//   - HTML entities are decoded
// This means a Word table in question text arrives as one long <table>...</table>
// string which the Aiken/essay parsers accumulate as question text, and it
// renders correctly to students via format_text(FORMAT_HTML).
function lg_prepare_html_import(string $html): string {
    if (trim($html) === '') { return ''; }
    // 1. Collapse whitespace inside table elements so they stay on one line
    //    after the later \n-based split.
    $html = preg_replace_callback(
        '/(<table\b[^>]*>[\s\S]*?<\/table>)/i',
        fn($m) => preg_replace('/\s+/', ' ', $m[0]),
        $html
    );
    // 2. Collapse whitespace inside img tags (they may span lines).
    $html = preg_replace_callback(
        '/(<img\b[^>]*>)/i',
        fn($m) => preg_replace('/\s+/', ' ', $m[0]),
        $html
    );
    // 2.5. Normalize content within each <li> to a single line before we do anything
    //      with the list structure.  Word and browsers often insert <br> elements or
    //      wrap the text in nested <p> tags inside <li>, which would later produce
    //      unwanted line breaks (e.g. "Inflation is<br>declining." splits into two lines).
    $html = preg_replace_callback(
        '/<li([^>]*)>([\s\S]*?)<\/li>/i',
        function ($m) {
            $content = $m[2];
            // Replace <br> with a space.
            $content = preg_replace('/<br\s*\/?>/i', ' ', $content);
            // Collapse adjacent </p><p> (inner paragraph breaks) to a space.
            $content = preg_replace('/<\/p\s*>\s*<p[^>]*>/i', ' ', $content);
            // Remove any remaining inner <p>/<span>/etc. open/close tags.
            $content = preg_replace('/<p[^>]*>|<\/p\s*>/i', '', $content);
            // Collapse all whitespace (including any raw newlines in the HTML source).
            $content = preg_replace('/\s+/', ' ', $content);
            return '<li' . $m[1] . '>' . trim($content) . '</li>';
        },
        $html
    );
    // 2.55. Protect <br> that immediately precede an Aiken option letter (A. B) C. etc.)
    //       OR an Answer: line, by converting them to paragraph breaks BEFORE step 2.6 runs.
    //       Without this, pasting "A. ...<br>B. ...<br>Answer: B" inside one <p> would have
    //       step 2.6 merge the last option and Answer: into one line, causing the Aiken
    //       parser to never see a standalone Answer: — resulting in "no questions found".
    $html = preg_replace('/<br\s*\/?>\s*(?=[A-Z][.)]\s|ANSWER:\s)/i', '</p><p>', $html);

    // 2.6. Collapse <br> inside <p> and <div> blocks to a space (not a newline).
    //      Word uses soft returns (<br>) inside paragraphs for visual line wrapping;
    //      those should join as a single space, while </p>/</div> produce real breaks.
    //      Chrome contenteditable uses <div>, Firefox uses <p>, so we handle both.
    $html = preg_replace_callback(
        '/<(p|div)([^>]*)>([\s\S]*?)<\/\1>/i',
        function ($m) {
            $content = $m[3];
            $content = preg_replace('/<br\s*\/?>/i', ' ', $content);  // soft-return → space
            $content = preg_replace('/\s+/', ' ', $content);           // collapse whitespace
            return '<' . $m[1] . $m[2] . '>' . trim($content) . '</' . $m[1] . '>';
        },
        $html
    );
    // 3a. Convert list items to Unicode bullets/numbers BEFORE stripping tags,
    //     so the visual structure is preserved in the imported question text.
    $html = preg_replace('/<li[^>]*>/i', "\n\xE2\x80\xA2 ", $html); // • (U+2022)
    $html = preg_replace('/<\/li>/i', '', $html);
    $html = preg_replace('/<\/?[ou]l[^>]*>/i', "\n", $html);
    // 3b. Block-level closing / self-closing tags → newline.
    // Note: </tr> is intentionally excluded — the table was already collapsed to one line
    // in step 1, so converting </tr> here would split it back into multiple lines and
    // later cause <br> to be injected inside the table HTML (extra spaces in student view).
    $html = preg_replace('/<\/p\s*>|<\/div\s*>|<\/h[1-6]\s*>|<br\s*\/?\s*>/i', "\n", $html);
    // 4. Strip all tags EXCEPT table structure and img (which we want to keep).
    $html = preg_replace('/<(?!\/?(?:table|thead|tbody|tfoot|colgroup|col|tr|th|td|caption|img)\b)[^>]+>/i', '', $html);
    // 5. Decode HTML entities so question text is human-readable.
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // 6. Tidy up whitespace.
    $html = preg_replace('/[ \t]+/', ' ', $html);        // collapse horizontal whitespace
    $html = preg_replace('/\n[ \t]+/', "\n", $html);     // trim leading spaces per line
    $html = preg_replace('/[ \t]+\n/', "\n", $html);     // trim trailing spaces per line
    $html = preg_replace('/\n{3,}/', "\n\n", $html);     // max one blank line between blocks
    return trim($html);
}
// -----------------------------------------------------------------------
$importmessages   = [];
$importcount      = 0;
$showPreview      = false;
$previewQuestions = [];
$previewFormat    = '';
$previewCatid     = 0;

if (data_submitted() && confirm_sesskey()) {
    $action     = optional_param('action', 'import', PARAM_ALPHA);
    $format     = required_param('format', PARAM_ALPHA);
    $catid_post = optional_param('categoryid', 0, PARAM_INT);

    if ($action === 'confirm') {
        // --- Step 2: insert selected questions from session preview ---
        $preview = $SESSION->lg_import_preview ?? null;
        if ($preview && !empty($preview['questions'])) {
            $selectedIdx = optional_param_array('import_idx', [], PARAM_INT);
            foreach ($preview['questions'] as $i => $qobj) {
                if (empty($selectedIdx) || in_array($i, $selectedIdx)) {
                    if (lg_insert_parsed_q($qobj, $preview['catid'], $USER->id)) { $importcount++; }
                }
            }
            unset($SESSION->lg_import_preview);
            redirect(
                new moodle_url('/mod/learnguard/questionimport.php',
                    array_filter(['id' => $id, 'courseid' => $courseid, 'fmt' => $format])),
                "$importcount question(s) imported successfully!",
                null, \core\output\notification::NOTIFY_SUCCESS
            );
        } else {
            $importmessages[] = 'Preview session expired — please paste your questions again and click Preview.';
        }
    } else {
        // --- Step 1: parse content, store in session, show preview ---
        $textinput     = optional_param('textinput', '', PARAM_RAW);
        $textinputhtml = optional_param('textinput_html', '', PARAM_RAW);
        $content = ''; $fromHtml = false;

        if (!empty($_FILES['importfile']['tmp_name']) && is_uploaded_file($_FILES['importfile']['tmp_name'])) {
            $content = file_get_contents($_FILES['importfile']['tmp_name']);
        } elseif (trim($textinputhtml)) {
            $content = $textinputhtml; $fromHtml = true;
        } elseif (trim($textinput)) {
            $content = $textinput;
        }

        if ($content) {
            $content = ltrim($content, "\xEF\xBB\xBF");
            $content = preg_replace('/\r\n?/', "\n", $content);
            if ($fromHtml) { $content = lg_prepare_html_import($content); }
            if (!$catid_post) {
                $catid_post = (int)$DB->get_field_sql('SELECT MIN(id) FROM {learnguard_qbank_cats}');
            }
            [$previewQuestions, $errs] = lg_parse_questions($format, $content);
            $importmessages = $errs;

            if (!empty($previewQuestions)) {
                $SESSION->lg_import_preview = [
                    'questions'   => $previewQuestions,
                    'catid'       => $catid_post,
                    'fmt'         => $format,
                    'ts'          => time(),
                    'editor_html' => $textinputhtml,  // restore editor when user clicks "Edit"
                ];
                $showPreview   = true;
                $previewFormat = $format;
                $previewCatid  = $catid_post;
            } elseif (empty($importmessages)) {
                $importmessages[] = 'No questions found. Check the format matches the selected tab and try again.';
            }
        } else {
            $importmessages[] = 'No content to import. Upload a file or paste the question text below.';
        }
    }
}

$cats  = $DB->get_records('learnguard_qbank_cats', [], 'sortorder ASC, name ASC');
$brand = learnguard_brand();

echo $OUTPUT->header();
echo learnguard_nav_bar($id, 'Import Questions');
echo learnguard_banner('Import Questions', 'Bulk-import questions into the LearnGuard question bank.', '📥');

if ($importmessages) {
    echo html_writer::div(
        implode('<br>', array_map('s', $importmessages)),
        'alert alert-' . ($showPreview ? 'warning' : 'danger')
    );
}

echo html_writer::link(
    new moodle_url('/mod/learnguard/questionbank.php',
        array_filter(['id' => $id, 'courseid' => $courseid])),
    '← Question Bank', ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// ---- Preview pane (shown instead of import form after parsing) ----
if ($showPreview):
?>
<div style="background:#fff;border:2px solid #0f6cbf;border-radius:8px;padding:20px;margin-bottom:20px;">
    <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:16px;">
        <h4 style="margin:0;">👁 Preview — <?php echo count($previewQuestions); ?> question(s) found</h4>
        <span style="font-size:13px;color:#6c757d;">Uncheck questions you don't want, then click <strong>Import Selected</strong>.</span>
    </div>
    <form method="post">
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',    'value' => sesskey()]); ?>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'format',     'value' => $previewFormat]); ?>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',     'value' => 'confirm']); ?>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'categoryid', 'value' => $previewCatid]); ?>
        <?php if ($id):       echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',       'value' => $id]); endif; ?>
        <?php if ($courseid): echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]); endif; ?>
        <div style="display:flex;gap:8px;margin-bottom:14px;">
            <button type="button" onclick="document.querySelectorAll('.lg-preview-card input[type=checkbox]').forEach(function(cb){cb.checked=true;})"
                    class="btn btn-outline-secondary btn-sm">☑ Select All</button>
            <button type="button" onclick="document.querySelectorAll('.lg-preview-card input[type=checkbox]').forEach(function(cb){cb.checked=false;})"
                    class="btn btn-outline-secondary btn-sm">☐ Deselect All</button>
        </div>
        <?php foreach ($previewQuestions as $idx => $pq): ?>
            <?php echo lg_render_preview_q($pq, $idx); ?>
        <?php endforeach; ?>
        <div style="display:flex;gap:10px;align-items:center;margin-top:18px;flex-wrap:wrap;">
            <button type="submit" class="btn btn-success btn-lg">✅ Import Selected</button>
            <a href="<?php echo new moodle_url('/mod/learnguard/questionimport.php',
                array_filter(['id' => $id, 'courseid' => $courseid, 'fmt' => $previewFormat])); ?>"
               class="btn btn-outline-secondary btn-lg">← Edit / Start Over</a>
        </div>
    </form>
</div>
<?php else: /* ---- Import form (shown when not in preview mode) ---- */

// Format tabs.
$fmt = optional_param('fmt', 'csv', PARAM_ALPHA);

// Restore editor content when user clicks "← Edit / Start Over" from the preview.
$editorRestore = '';
if (!empty($SESSION->lg_import_preview['editor_html'])
        && ($SESSION->lg_import_preview['fmt'] ?? '') === $fmt) {
    $editorRestore = $SESSION->lg_import_preview['editor_html'];
}
?>
<div style="display:flex;gap:2px;margin-bottom:0;border-bottom:2px solid #dee2e6;" class="mb-0">
<?php foreach ([
    'csv'         => '📊 LearnGuard CSV',
    'shortanswer' => '📝 Short Answer',
    'essay'       => '✍️ Essay',
    'gift'        => '✏️ GIFT',
    'aiken'       => '📃 Aiken',
    'choiceessay' => '🗂 Choice Essay',
] as $fkey => $flabel): ?>
    <a href="?<?php echo http_build_query(array_filter(['id' => $id, 'courseid' => $courseid, 'fmt' => $fkey])); ?>"
       style="padding:8px 16px;text-decoration:none;font-size:14px;font-weight:600;border-radius:4px 4px 0 0;
              <?php echo ($fmt === $fkey) ? 'background:' . $brand->primary . ';color:#fff;' : 'color:#495057;background:#f8f9fa;border:1px solid #dee2e6;'; ?>">
        <?php echo $flabel; ?>
    </a>
<?php endforeach; ?>
</div>

<div style="background:#fff;border:1px solid #dee2e6;border-top:none;border-radius:0 0 8px 8px;padding:24px;margin-bottom:20px;">
<form method="post" enctype="multipart/form-data">
<?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
<?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'format',  'value' => $fmt]); ?>
<?php if ($id):       echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',       'value' => $id]);       endif; ?>
<?php if ($courseid): echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]); endif; ?>

<div class="mb-3">
    <label style="font-weight:700;display:block;margin-bottom:4px;">Import into Category</label>
    <?php echo html_writer::select(
        array_column(array_values($cats), 'name', 'id'),
        'categoryid', 0, ['— Default (General) —'],
        ['class' => 'form-control', 'style' => 'max-width:300px;']
    ); ?>
</div>

<div class="mb-3">
    <label style="font-weight:700;display:block;margin-bottom:4px;">Upload File</label>
    <input type="file" name="importfile" class="form-control" accept=".csv,.txt,.gift">
    <div style="margin-top:6px;">
        <?php echo html_writer::link(
            new moodle_url('/mod/learnguard/questionimport.php',
                array_filter(['id' => $id, 'courseid' => $courseid, 'fmt' => $fmt, 'download' => 'template'])),
            '⬇ Download Sample Template (' . strtoupper($fmt) . ')',
            ['class' => 'btn btn-outline-secondary btn-sm', 'style' => 'font-size:12px;']
        ); ?>
    </div>
</div>
<p style="text-align:center;color:#6c757d;margin:8px 0;">— or paste directly —</p>
<div class="mb-3">
    <label style="font-weight:700;display:block;margin-bottom:4px;">Paste Question Text</label>
    <?php
    $placeholders = [
        'csv'         => "type,question,a1,a2,a3,a4,correct,marks\nmultichoice,What is 2+2?,2,4,6,8,B,1\ntruefalse,The Earth is round.,,,,,true,1",
        'shortanswer' => "Generally, an IPO is sold in the ______ Market.\nAnswer: Primary Market or Market or PM\n\nMeristem has ____ business units\nAnswer: six or 6",
        'essay'       => "Distinguish the money market and capital market with 2 examples. [3 Marks]\n\nAnswer/marking guide:\nThe money market trades short-term securities (e.g. T-bills, commercial paper). The capital market trades long-term instruments (e.g. equities, bonds).",
        'gift'        => "What is 2+2? {\n  =4\n  ~2\n  ~6\n}",
        'aiken'       => "What color is the sky?\nA) Red\nB) Blue\nC) Green\nANSWER: B",
        'choiceessay' => "TITLE: Economic Policy Essay\nMARKS: 10\nSHOW: 2\n\nFirst essay prompt here — students who see this prompt will answer it.\n\nSecond essay prompt here — each blank-line-separated paragraph is one prompt.\n\nThird essay prompt here.\n\n===\n\nTITLE: Second Question\nSHOW: 1\n\nPrompt A for the second CE question.\n\nPrompt B for the second CE question.",
    ];
    $phText = htmlspecialchars($placeholders[$fmt] ?? '', ENT_QUOTES);
    ?>
    <!-- Rich editor — same WYSIWYG as the question editor.
         Toolbar buttons + browser-native paste (tables/images from Word preserved automatically).
         JS on submit copies innerHTML to the hidden textinput_html field. -->
    <div style="border:1px solid #ced4da;border-radius:4px;overflow:hidden;">
        <div id="lg-import-toolbar"
             style="display:flex;flex-wrap:wrap;gap:3px;padding:6px 8px;
                    background:#f8f9fa;border-bottom:1px solid #dee2e6;font-size:12px;">
            <span style="color:#6c757d;align-self:center;margin-right:4px;font-size:11px;">
                Question Text&nbsp;·&nbsp;Bold / Italic / Table / Paste image&nbsp;·&nbsp;HTML formatting preserved for students
            </span>
        </div>
        <div id="lg-import-editor"
             contenteditable="true"
             data-placeholder="<?php echo $phText; ?>"
             style="min-height:220px;max-height:520px;overflow-y:auto;
                    padding:10px 12px;font-size:13px;line-height:1.6;
                    background:#fff;outline:none;"></div>
    </div>
    <input type="hidden" name="textinput_html" id="lg-import-textinput-html">
    <style>
    #lg-import-editor:empty::before {
        content: attr(data-placeholder);
        color: #adb5bd;
        pointer-events: none;
        white-space: pre-wrap;
        display: block;
    }
    #lg-import-editor table { border-collapse:collapse; margin:8px 0; }
    #lg-import-editor td, #lg-import-editor th {
        border:1px solid #dee2e6 !important; padding:6px 10px !important; min-width:40px;
    }
    #lg-import-editor th { background:#f8f9fa !important; font-weight:700; }
    #lg-import-editor img { max-width:100%; height:auto; }
    #lg-import-editor p { margin:2px 0; }
    </style>
    <script>
    (function() {
        var editor  = document.getElementById('lg-import-editor');
        var toolbar = document.getElementById('lg-import-toolbar');
        var hidden  = document.getElementById('lg-import-textinput-html');
        if (!editor || !toolbar || !hidden) { return; }

        // Toolbar — identical to the question-editor WYSIWYG toolbar.
        var btnStyle = 'padding:2px 9px;border:1px solid #ced4da;border-radius:4px;' +
                       'background:#fff;cursor:pointer;font-size:13px;white-space:nowrap;';
        function cmd(c, v) { editor.focus(); document.execCommand(c, false, v !== undefined ? v : null); }
        [
            ['<strong>B</strong>',  'Bold',            function(){ cmd('bold'); }],
            ['<em>I</em>',          'Italic',           function(){ cmd('italic'); }],
            ['<u>U</u>',            'Underline',        function(){ cmd('underline'); }],
            ['• List',              'Bullet list',      function(){ cmd('insertUnorderedList'); }],
            ['1. List',             'Numbered list',    function(){ cmd('insertOrderedList'); }],
            ['↵ BR',                'Insert line break',function(){ cmd('insertHTML', '<br>'); }],
            ['⊞ Table',             'Insert table',     function(){
                var r = parseInt(prompt('Rows:', '3') || '0');
                var c = parseInt(prompt('Columns:', '3') || '0');
                if (!r || !c) { return; }
                var th  = '<tr>' + Array(c).fill('<th style="border:1px solid #dee2e6;padding:8px;background:#f8f9fa;font-weight:700;">Header</th>').join('') + '</tr>';
                var trs = Array(Math.max(0, r - 1)).fill(null).map(function(){
                    return '<tr>' + Array(c).fill('<td style="border:1px solid #dee2e6;padding:8px;">&nbsp;</td>').join('') + '</tr>';
                }).join('');
                cmd('insertHTML', '<table style="border-collapse:collapse;width:100%;margin:10px 0;">' + th + trs + '</table><p><br></p>');
            }],
            ['🖼 Image',            'Insert image from file', function(){
                var inp = document.createElement('input');
                inp.type = 'file'; inp.accept = 'image/*';
                inp.onchange = function(){
                    var f = inp.files[0]; if (!f) { return; }
                    var rd = new FileReader();
                    rd.onload = function(ev){ cmd('insertImage', ev.target.result); };
                    rd.readAsDataURL(f);
                };
                inp.click();
            }],
        ].forEach(function(b) {
            var btn = document.createElement('button');
            btn.type = 'button'; btn.innerHTML = b[0]; btn.title = b[1];
            btn.style.cssText = btnStyle;
            btn.addEventListener('mousedown', function(e){ e.preventDefault(); });
            btn.addEventListener('click', b[2]);
            toolbar.appendChild(btn);
        });

        // Form submit — copy innerHTML to hidden field.
        var form = editor.closest('form');
        if (form) {
            form.addEventListener('submit', function() {
                hidden.value = editor.innerHTML;
            });
        }

        // Paste handler — same as question editor:
        // Only intercept when clipboard carries a raw image file (e.g. screenshot).
        // All other pastes (Word tables, formatted text, plain text) go through
        // the browser natively — the browser already preserves table HTML correctly.
        editor.addEventListener('paste', function(e) {
            var items = (e.clipboardData || window.clipboardData || {}).items || [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].type.indexOf('image') === 0) {
                    e.preventDefault();
                    var blob = items[i].getAsFile();
                    var rd = new FileReader();
                    rd.onload = function(ev){ cmd('insertImage', ev.target.result); };
                    rd.readAsDataURL(blob);
                    return;
                }
            }
            // Non-image paste (plain text, Word HTML, Excel tables): browser handles.
        });

        // Restore editor when returning from preview ("← Edit / Start Over").
        <?php if ($editorRestore !== ''): ?>
        editor.innerHTML = <?php echo json_encode($editorRestore); ?>;
        <?php endif; ?>
    })();
    </script>
</div>
<button type="submit" class="btn btn-primary btn-lg">👁 Preview &amp; Import</button>
</form>
</div>

<!-- Format reference -->
<div style="background:#f8f9fa;border-radius:8px;padding:20px;">
<?php if ($fmt === 'shortanswer'): ?>
<h5>📝 Short Answer Format Reference</h5>
<p style="font-size:14px;">Each question is a block separated by a <strong>blank line</strong>.
Write the question text first, then <code>Answer:</code> with accepted answers separated by
<strong><code> or </code></strong>. Answers are case-insensitive by default; add <code>Case: sensitive</code>
to require exact capitalisation. Optionally add <code>Marks: N</code> to override the default mark.</p>
<pre style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:16px;font-size:12px;overflow-x:auto;">Generally, an initial public offering (IPO) is sold in the ______ Market.
Answer: Primary Market or Market or PM

Meristem has ____ business units
Answer: six or 6

Type the exact UNIX command to list files in a directory.
Answer: ls
Case: sensitive
Marks: 2</pre>
<p style="font-size:13px;color:#495057;margin-top:10px;">
<strong>Tips:</strong><br>
• Use <strong><code> or </code></strong> (with spaces) to separate accepted answer variants on the same <code>Answer:</code> line.<br>
• Blank lines between questions are required — they tell the importer where one question ends and the next begins.<br>
• Add <code>Case: sensitive</code> to require exact capitalisation (useful for commands, codes, etc.).<br>
• Wildcards are not supported in this format — use the LearnGuard CSV tab if you need wildcard matching.</p>

<?php elseif ($fmt === 'essay'): ?>
<h5>✍️ Essay Format Reference</h5>
<p style="font-size:14px;">Each question is separated from its marking guide by a <strong>blank line</strong>.
Write the question text first (you can include the marks in brackets like <code>[3 Marks]</code> — the importer
will extract the number and set it as the question's default mark). Then on the next paragraph write
<code>Answer/marking guide:</code> followed by the guide text. Questions are separated from each other
by a blank line after the guide.</p>
<pre style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:16px;font-size:12px;overflow-x:auto;">Distinguish the money market and capital market with 2 examples of instruments trading in each market. [3 Marks]

Answer/marking guide:
The money market is a market for trading short-term securities, typically those with less than one-year maturity. Examples include treasury bills, commercial papers, and money market mutual funds. The capital market is where longer-term securities are issued and traded. Examples include equities (shares) and bonds.

What are the four functions of money? [4 Marks]

Answer/marking guide:
1. Medium of exchange — used to buy and sell goods.
2. Store of value — can be saved for future use.
3. Unit of account — used to measure the value of goods.
4. Standard of deferred payment — used for future transactions.</pre>
<p style="font-size:13px;color:#495057;margin-top:10px;">
<strong>Tips:</strong><br>
• The <code>[N Marks]</code> in the question text is read automatically and sets the default mark — you don't need to remove it.<br>
• <code>Answer/marking guide:</code> can also be written as just <code>Answer:</code> — both are accepted.<br>
• The marking guide is shown only to graders, never to students.<br>
• If a question has no guide, just leave it out — the importer will still create the essay question with a blank guide.</p>

<?php elseif ($fmt === 'csv'): ?>
<h5>Format Reference — LearnGuard CSV</h5>
<p style="font-size:14px;">Required columns: <code>type</code>, <code>question</code>. Optional: <code>a1–a6</code>, <code>correct</code>, <code>marks</code>, <code>feedback</code>, <code>case_sensitive</code> (yes/no — short answer only), <code>marking_guide</code> (essay only).</p>
<p style="font-size:13px;color:#495057;"><strong>Short answer tips:</strong> Separate accepted answer variants with <code>|</code> (e.g. <code>Jupiter|JUPITER</code>). Set <code>case_sensitive</code> to <code>yes</code> to require exact capitalisation. Wildcards: use <code>*</code> (e.g. <code>jupit*</code>).<br>
<strong>Essay tips:</strong> Put your marking rubric/guide in the <code>marking_guide</code> column — it is shown to graders but not students.</p>
<pre style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:16px;font-size:12px;overflow-x:auto;">type,question,a1,a2,a3,a4,correct,marks,case_sensitive,marking_guide
multichoice,What is the capital of France?,London,Berlin,Paris,Madrid,C,1,,
truefalse,The Earth orbits the Sun.,,,,,true,1,,
shortanswer,Name the largest planet.,,,,,Jupiter|jupiter,2,no,
shortanswer,Type the exact command to list files.,,,,,ls,1,yes,
essay,Describe the water cycle.,,,,,,5,,Award marks for: evaporation condensation precipitation runoff. 1 mark each.</pre>
<?php elseif ($fmt === 'gift'): ?>
<h5>Format Reference — GIFT</h5>
<p style="font-size:14px;"><code>=</code> correct answer, <code>~</code> wrong answer. <code>::Title::</code> is optional.</p>
<pre style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:16px;font-size:12px;overflow-x:auto;">::Q1:: What is 2+2? {
  =4 # Correct!
  ~2 # Wrong
  ~6 # Wrong
}

The sky is blue. {TRUE}

::Short:: Name a primary colour. {=red =blue =yellow}</pre>
<?php elseif ($fmt === 'aiken'): ?>
<h5>Format Reference — Aiken</h5>
<p style="font-size:14px;">Each question: text (can be multiple lines), then options <code>A)</code>–<code>F)</code>, then <code>ANSWER: X</code>. Blank line separates questions.</p>
<pre style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:16px;font-size:12px;overflow-x:auto;">What color is the sky?
A) Red
B) Blue
C) Green
D) Yellow
ANSWER: B

How many days in a week?
A) 5
B) 7
C) 10
ANSWER: B</pre>
<p style="font-size:13px;color:#495057;margin-top:10px;">
<strong>Tips:</strong><br>
• Multi-line question text is supported — lines before the first <code>A)</code> option are joined as the question text.<br>
• Options can use <code>A)</code> or <code>A.</code> format.<br>
• A blank line between questions is optional — the <code>ANSWER:</code> line also triggers the question save.
</p>

<?php else: ?>
<h5>🗂 Format Reference — Choice Essay</h5>
<p style="font-size:14px;">
Each <strong>Choice Essay question</strong> contains multiple essay prompts. Students see a random selection and must choose one to answer.
Each blank-line-separated paragraph becomes one prompt. Separate multiple CE questions with <code>===</code> on its own line.
</p>
<p style="font-size:14px;">Optional header lines at the top of each question block:</p>
<ul style="font-size:14px;">
    <li><code>TITLE: ...</code> — question name shown in the question bank</li>
    <li><code>MARKS: N</code> — default mark for the question (default: plugin setting)</li>
    <li><code>SHOW: N</code> — how many prompts each student sees (0 = all)</li>
    <li><code>GUIDE: ...</code> — single-line marking guide (shown to graders only)</li>
</ul>
<pre style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:16px;font-size:12px;overflow-x:auto;">TITLE: Economic Policy Essay
MARKS: 10
SHOW: 2

Analyze the impact of inflation on developing economies and suggest
appropriate policy measures. [10 marks]

Discuss the role of monetary policy in managing economic downturns,
with reference to two African countries. [10 marks]

Evaluate the effectiveness of fiscal stimulus during recessions. [10 marks]

===

TITLE: Constitutional Law Essay
MARKS: 15
SHOW: 2

Examine the doctrine of judicial precedent as applied by Nigerian courts. [15 marks]

Critically assess fundamental rights under Nigeria's 1999 Constitution. [15 marks]

Discuss the principle of separation of powers in democratic governance. [15 marks]</pre>
<p style="font-size:13px;color:#495057;margin-top:10px;">
<strong>Tips:</strong><br>
• Each blank line between paragraphs creates a new prompt — use at least two paragraphs per question.<br>
• Separate multiple CE questions with <code>===</code> on its own line (or three or more blank lines).<br>
• The <code>SHOW:</code> count applies when students sit the exam — set it to how many prompts they should choose from.<br>
• If you omit <code>TITLE:</code>, the first prompt's text is used as the question bank name.
</p>
<?php endif; ?>
</div>

<?php endif; /* end else (!$showPreview) */ ?>

<?php echo $OUTPUT->footer();
