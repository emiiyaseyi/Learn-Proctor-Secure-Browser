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

        $markingguide = trim($row['marking_guide'] ?? $row['markingguidance'] ?? '');
        $qid = $DB->insert_record('learnguard_qbank', (object)[
            'categoryid'            => $catid,
            'name'                  => mb_substr($qtext, 0, 120),
            'questiontext'          => $qtext,
            'questiontextformat'    => FORMAT_PLAIN,
            'qtype'                 => $qtype,
            'defaultmark'           => (float)($row['marks'] ?? $defaultmark),
            'shuffleanswers'        => 1,
            'generalfeedback'       => $row['feedback'] ?? '',
            'generalfeedbackformat' => FORMAT_PLAIN,
            'markingguidance'       => $markingguide,
            'markingguidanceformat' => FORMAT_PLAIN,
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
                    'answertextformat' => FORMAT_PLAIN,
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
                        'answertextformat' => FORMAT_PLAIN,
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
                    'answertextformat' => FORMAT_PLAIN,
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
            $qtext = trim($block);
            if ($qtext === '') { continue; }
            $qid = $DB->insert_record('learnguard_qbank', (object)[
                'categoryid' => $catid,
                'name'       => $title ?: mb_substr($qtext, 0, 100),
                'questiontext' => $qtext, 'questiontextformat' => FORMAT_PLAIN,
                'qtype' => 'essay', 'defaultmark' => $defaultmark, 'shuffleanswers' => 0,
                'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_PLAIN,
                'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
            ]);
            $imported++;
            continue;
        }

        $qtext  = trim(preg_replace('/\{.*?\}/s', '', $block));
        $ansblk = trim($am[1]);

        // True/False?
        if (preg_match('/^(TRUE|FALSE|T|F)$/i', $ansblk, $tfm)) {
            $correct = strtolower($tfm[1][0]) === 't' ? 'true' : 'false';
            $qid = $DB->insert_record('learnguard_qbank', (object)[
                'categoryid' => $catid,
                'name'       => $title ?: mb_substr($qtext, 0, 100),
                'questiontext' => $qtext, 'questiontextformat' => FORMAT_PLAIN,
                'qtype' => 'truefalse', 'defaultmark' => $defaultmark, 'shuffleanswers' => 0,
                'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_PLAIN,
                'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
            ]);
            foreach (['true', 'false'] as $val) {
                $DB->insert_record('learnguard_qbank_answers', (object)[
                    'questionid' => $qid, 'answertext' => $val, 'answertextformat' => FORMAT_PLAIN,
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
                'questiontext' => $qtext, 'questiontextformat' => FORMAT_PLAIN,
                'qtype' => 'shortanswer', 'defaultmark' => $defaultmark, 'shuffleanswers' => 0,
                'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_PLAIN,
                'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
            ]);
            preg_match_all('/=([^=~#\n]+)(?:#[^\n]*)?/u', $ansblk, $am2);
            $sort = 0;
            foreach ($am2[1] as $ans) {
                $DB->insert_record('learnguard_qbank_answers', (object)[
                    'questionid' => $qid, 'answertext' => trim($ans), 'answertextformat' => FORMAT_PLAIN,
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
            'questiontext' => $qtext, 'questiontextformat' => FORMAT_PLAIN,
            'qtype' => 'multichoice', 'defaultmark' => $defaultmark, 'shuffleanswers' => 1,
            'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_PLAIN,
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
        ]);
        $sort = 0;
        foreach ($matches as $m2) {
            $DB->insert_record('learnguard_qbank_answers', (object)[
                'questionid' => $qid, 'answertext' => trim($m2[2]), 'answertextformat' => FORMAT_PLAIN,
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
    $qtext = ''; $options = []; $answer = '';
    $defaultmark = (float)get_config('learnguard', 'default_question_mark') ?: 1.0;

    $flush = function() use (&$qtext, &$options, &$answer, &$imported, $catid, $userid, $DB, $defaultmark) {
        if (!$qtext || !$options || !$answer) { return; }
        $qid = $DB->insert_record('learnguard_qbank', (object)[
            'categoryid' => $catid,
            'name'       => mb_substr($qtext, 0, 100),
            'questiontext' => $qtext, 'questiontextformat' => FORMAT_PLAIN,
            'qtype' => 'multichoice', 'defaultmark' => $defaultmark, 'shuffleanswers' => 1,
            'generalfeedback' => '', 'generalfeedbackformat' => FORMAT_PLAIN,
            'timecreated' => time(), 'timemodified' => time(), 'createdby' => $userid,
        ]);
        $sort = 0;
        foreach ($options as $letter => $text) {
            $DB->insert_record('learnguard_qbank_answers', (object)[
                'questionid' => $qid, 'answertext' => $text, 'answertextformat' => FORMAT_PLAIN,
                'fraction' => (strtoupper($letter) === strtoupper($answer)) ? 1.0 : 0.0,
                'feedback' => '', 'sortorder' => $sort++,
            ]);
        }
        $imported++;
        $qtext = ''; $options = []; $answer = '';
    };

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { $flush(); continue; }
        // Option line: A) or A.
        if (preg_match('/^([A-Z])[.)]\s+(.+)$/i', $line, $m)) {
            $options[$m[1]] = $m[2];
        } elseif (preg_match('/^ANSWER:\s*([A-Z])/i', $line, $m)) {
            $answer = $m[1];
            $flush();
        } else {
            if ($qtext) { $qtext .= ' '; }
            $qtext .= $line;
        }
    }
    $flush();
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
                $answers = preg_split('/\s+or\s+/i', trim($m[1]));
            } elseif (preg_match('/^Marks:\s*([\d.]+)/i', $line, $m)) {
                $marks = (float)$m[1];
            } elseif (preg_match('/^Case:\s*sensitive/i', $line)) {
                $casesensitive = 1;
            } else {
                $qlines[] = $line;
            }
        }

        $qtext = trim(implode(' ', $qlines));
        if ($qtext === '') { continue; }
        if (empty($answers)) {
            $errors[] = 'No "Answer:" line for: ' . mb_substr($qtext, 0, 60);
            continue;
        }

        $qid = $DB->insert_record('learnguard_qbank', (object)[
            'categoryid'            => $catid,
            'name'                  => mb_substr($qtext, 0, 120),
            'questiontext'          => $qtext,
            'questiontextformat'    => FORMAT_PLAIN,
            'qtype'                 => 'shortanswer',
            'defaultmark'           => $marks,
            'shuffleanswers'        => 0,
            'generalfeedback'       => '',
            'generalfeedbackformat' => FORMAT_PLAIN,
            'markingguidance'       => '',
            'markingguidanceformat' => FORMAT_PLAIN,
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
                    'answertextformat' => FORMAT_PLAIN,
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
    // Questions separated by a blank line from their guide.
    // Format per question:
    //   Question text (one or more lines)
    //   <blank line>
    //   Answer/marking guide:
    //   Guide text (one or more lines)
    //   <blank line>  ← separates next question
    //
    // If no guide present, two consecutive blank lines or EOF ends the question.
    $blocks = preg_split('/\n{2,}/', trim($content));
    $imported = 0; $errors = [];

    $pending_qtext = null;
    $pending_marks = $defaultmark;

    foreach ($blocks as $blockraw) {
        $block = trim($blockraw);
        if ($block === '') { continue; }

        // Does this block start with the answer/guide marker?
        if (preg_match('/^Answer\s*(?:\/\s*marking\s*guide\s*)?:/i', $block)) {
            if ($pending_qtext === null) { continue; } // stray guide block — skip
            $guide = trim(preg_replace('/^Answer\s*(?:\/\s*marking\s*guide\s*)?:\s*/i', '', $block, 1));

            $DB->insert_record('learnguard_qbank', (object)[
                'categoryid'            => $catid,
                'name'                  => mb_substr($pending_qtext, 0, 120),
                'questiontext'          => $pending_qtext,
                'questiontextformat'    => FORMAT_PLAIN,
                'qtype'                 => 'essay',
                'defaultmark'           => $pending_marks,
                'shuffleanswers'        => 0,
                'generalfeedback'       => '',
                'generalfeedbackformat' => FORMAT_PLAIN,
                'markingguidance'       => $guide,
                'markingguidanceformat' => FORMAT_PLAIN,
                'casesensitive'         => 0,
                'timecreated'           => time(),
                'timemodified'          => time(),
                'createdby'             => $userid,
            ]);
            $imported++;
            $pending_qtext = null;
            $pending_marks = $defaultmark;
        } else {
            // This is question text. Flush any previous question without a guide.
            if ($pending_qtext !== null) {
                $DB->insert_record('learnguard_qbank', (object)[
                    'categoryid'            => $catid,
                    'name'                  => mb_substr($pending_qtext, 0, 120),
                    'questiontext'          => $pending_qtext,
                    'questiontextformat'    => FORMAT_PLAIN,
                    'qtype'                 => 'essay',
                    'defaultmark'           => $pending_marks,
                    'shuffleanswers'        => 0,
                    'generalfeedback'       => '',
                    'generalfeedbackformat' => FORMAT_PLAIN,
                    'markingguidance'       => '',
                    'markingguidanceformat' => FORMAT_PLAIN,
                    'casesensitive'         => 0,
                    'timecreated'           => time(),
                    'timemodified'          => time(),
                    'createdby'             => $userid,
                ]);
                $imported++;
            }
            // Extract optional [N Marks] from the question text.
            $marks = $defaultmark;
            $qtext = $block;
            if (preg_match('/\[(\d+)\s*[Mm]arks?\]/', $qtext, $mm)) {
                $marks = (float)$mm[1];
            }
            $pending_qtext = trim(str_replace("\n", ' ', $qtext));
            $pending_marks = $marks;
        }
    }

    // Flush trailing question with no guide.
    if ($pending_qtext !== null) {
        $DB->insert_record('learnguard_qbank', (object)[
            'categoryid'            => $catid,
            'name'                  => mb_substr($pending_qtext, 0, 120),
            'questiontext'          => $pending_qtext,
            'questiontextformat'    => FORMAT_PLAIN,
            'qtype'                 => 'essay',
            'defaultmark'           => $pending_marks,
            'shuffleanswers'        => 0,
            'generalfeedback'       => '',
            'generalfeedbackformat' => FORMAT_PLAIN,
            'markingguidance'       => '',
            'markingguidanceformat' => FORMAT_PLAIN,
            'casesensitive'         => 0,
            'timecreated'           => time(),
            'timemodified'          => time(),
            'createdby'             => $userid,
        ]);
        $imported++;
    }

    return [$imported, $errors];
}

// -----------------------------------------------------------------------
// Handle file upload
// -----------------------------------------------------------------------
$importmessages = [];
$importcount    = 0;

if (data_submitted() && confirm_sesskey()) {
    $format    = required_param('format', PARAM_ALPHA);
    $catid_post = optional_param('categoryid', 0, PARAM_INT);
    $textinput  = optional_param('textinput', '', PARAM_RAW);

    // Prefer uploaded file, fall back to pasted text.
    $content = '';
    if (!empty($_FILES['importfile']['tmp_name']) && is_uploaded_file($_FILES['importfile']['tmp_name'])) {
        $content = file_get_contents($_FILES['importfile']['tmp_name']);
    } elseif (trim($textinput)) {
        $content = $textinput;
    }

    if ($content) {
        // Ensure a default category exists.
        if (!$catid_post) {
            $catid_post = (int)$DB->get_field_sql('SELECT MIN(id) FROM {learnguard_qbank_cats}');
        }

        switch ($format) {
            case 'csv':
                [$importcount, $errs] = learnguard_import_csv($content, $catid_post, $USER->id);
                break;
            case 'shortanswer':
                [$importcount, $errs] = learnguard_import_shortanswer($content, $catid_post, $USER->id);
                break;
            case 'essay':
                [$importcount, $errs] = learnguard_import_essay($content, $catid_post, $USER->id);
                break;
            case 'gift':
                [$importcount, $errs] = learnguard_import_gift($content, $catid_post, $USER->id);
                break;
            case 'aiken':
                [$importcount, $errs] = learnguard_import_aiken($content, $catid_post, $USER->id);
                break;
            default:
                $errs = ['Unknown format.'];
        }
        $importmessages = $errs;

        if ($importcount > 0) {
            redirect(
                new moodle_url('/mod/learnguard/questionimport.php',
                    array_filter(['id' => $id, 'courseid' => $courseid, 'fmt' => $format])),
                "$importcount question(s) imported successfully!",
                null, \core\output\notification::NOTIFY_SUCCESS
            );
        }
        if ($importcount === 0 && empty($importmessages)) {
            $importmessages[] = 'No questions were found in the content. Check the format matches the selected tab and try again.';
        }
    } else {
        // POST was submitted but no content (empty file + no pasted text).
        $importmessages[] = 'No content to import. Upload a file or paste the question text below.';
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
        'alert alert-danger'
    );
}

echo html_writer::link(
    new moodle_url('/mod/learnguard/questionbank.php',
        array_filter(['id' => $id, 'courseid' => $courseid])),
    '← Question Bank', ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// Format tabs.
$fmt = optional_param('fmt', 'csv', PARAM_ALPHA);
?>
<div style="display:flex;gap:2px;margin-bottom:0;border-bottom:2px solid #dee2e6;" class="mb-0">
<?php foreach ([
    'csv'         => '📊 LearnGuard CSV',
    'shortanswer' => '📝 Short Answer',
    'essay'       => '✍️ Essay',
    'gift'        => '✏️ GIFT',
    'aiken'       => '📃 Aiken',
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
</div>
<p style="text-align:center;color:#6c757d;margin:8px 0;">— or paste directly —</p>
<div class="mb-3">
    <label style="font-weight:700;display:block;margin-bottom:4px;">Paste Question Text</label>
    <textarea name="textinput" rows="12" class="form-control" style="font-family:monospace;font-size:13px;"
        placeholder="<?php
        $placeholders = [
            'csv'         => "type,question,a1,a2,a3,a4,correct,marks\nmultichoice,What is 2+2?,2,4,6,8,B,1\ntruefalse,The Earth is round.,,,,,true,1",
            'shortanswer' => "Generally, an IPO is sold in the ______ Market.\nAnswer: Primary Market or Market or PM\n\nMeristem has ____ business units\nAnswer: six or 6",
            'essay'       => "Distinguish the money market and capital market with 2 examples. [3 Marks]\n\nAnswer/marking guide:\nThe money market trades short-term securities (e.g. T-bills, commercial paper). The capital market trades long-term instruments (e.g. equities, bonds).",
            'gift'        => "What is 2+2? {\n  =4\n  ~2\n  ~6\n}",
            'aiken'       => "What color is the sky?\nA) Red\nB) Blue\nC) Green\nANSWER: B",
        ];
        echo $placeholders[$fmt] ?? '';
        ?>"></textarea>
</div>
<button type="submit" class="btn btn-primary btn-lg">📥 Import Questions</button>
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
<?php else: ?>
<h5>Format Reference — Aiken</h5>
<p style="font-size:14px;">Each question: text, then options <code>A)</code>–<code>F)</code>, then <code>ANSWER: X</code>. Blank line separates questions.</p>
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
<?php endif; ?>
</div>

<?php echo $OUTPUT->footer();
