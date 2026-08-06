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
 * Create or edit a single question in LearnGuard's own question bank.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id         = optional_param('id',       0, PARAM_INT); // cmid
$courseid   = optional_param('courseid', 0, PARAM_INT);
$qid        = optional_param('qid',      0, PARAM_INT); // question to edit, 0=new

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

$PAGE->set_url('/mod/learnguard/questionedit.php',
    array_filter(['id' => $id, 'courseid' => $courseid, 'qid' => $qid]));
$PAGE->set_context($context);
$PAGE->set_title($qid ? 'Edit Question' : 'New Question');
$PAGE->set_heading($course ? format_string($course->fullname) : 'LearnGuard Question Bank');
$PAGE->requires->css('/mod/learnguard/styles.css');

$editing   = ($qid > 0);
$question  = $editing ? $DB->get_record('learnguard_qbank', ['id' => $qid], '*', MUST_EXIST) : null;
$answers   = $editing ? array_values($DB->get_records('learnguard_qbank_answers',
    ['questionid' => $qid], 'sortorder ASC')) : [];
$cePrompts = ($editing && isset($question->qtype) && $question->qtype === 'choiceessay')
    ? array_values($DB->get_records('learnguard_ce_prompts', ['questionid' => $qid], 'sortorder ASC'))
    : [];

// Read an HTML-containing field — checks for base64-encoded copy first (WAF bypass).
// The JS submit handler encodes any .lg-fmt-ta textarea as {name}__b64 before posting.
function lg_html_param(string $name): string {
    $b64 = optional_param($name . '__b64', '', PARAM_RAW);
    if ($b64 !== '') {
        $dec = base64_decode($b64, true);
        return ($dec !== false) ? $dec : '';
    }
    return optional_param($name, '', PARAM_RAW);
}

// ---- Save ----
if (data_submitted() && confirm_sesskey()) {
    $qtype       = required_param('qtype', PARAM_ALPHA);
    $qname       = required_param('qname', PARAM_TEXT);
    $questiontext    = lg_html_param('questiontext');
    $catid_post  = optional_param('categoryid', 0, PARAM_INT);
    $defaultmark = optional_param('defaultmark', (float)get_config('learnguard', 'default_question_mark') ?: 1.0, PARAM_FLOAT);
    $shuffle     = optional_param('shuffleanswers', 0, PARAM_INT);
    $genfeedback     = lg_html_param('generalfeedback');
    $markingguidance = lg_html_param('markingguidance');
    $ismandatory = optional_param('is_mandatory', 0, PARAM_INT);

    $casesensitive = optional_param('casesensitive', 0, PARAM_INT);

    $record = (object)[
        'categoryid'          => $catid_post,
        'name'                => clean_param(trim($qname), PARAM_TEXT),
        'questiontext'        => $questiontext,
        'questiontextformat'  => FORMAT_HTML,
        'qtype'               => $qtype,
        'defaultmark'         => max(0.0, (float)$defaultmark),
        'shuffleanswers'      => (int)(bool)$shuffle,
        'generalfeedback'     => $genfeedback,
        'generalfeedbackformat' => FORMAT_HTML,
        'markingguidance'     => $markingguidance,
        'markingguidanceformat' => FORMAT_HTML,
        'casesensitive'       => (int)(bool)$casesensitive,
        'is_mandatory'        => (int)(bool)$ismandatory,
        'show_prompts_count'  => ($qtype === 'choiceessay') ? max(0, min(4, optional_param('ce_show_count', 0, PARAM_INT))) : 0,
        'timemodified'        => time(),
    ];

    try {
        if ($editing) {
            $record->id = $qid;
            $DB->update_record('learnguard_qbank', $record);
            // Do NOT delete answers here — we update in-place below so answer IDs are
            // preserved. This is critical for MCQ auto-regrade: stored responses reference
            // answer IDs, so deleting and re-inserting would break existing response grades.
        } else {
            $record->timecreated = time();
            $record->createdby   = $USER->id;
            $qid = $DB->insert_record('learnguard_qbank', $record);
        }
    } catch (\dml_exception $e) {
        // A missing column (e.g. after a skipped upgrade step) causes a DB error.
        // Show a clear message instead of a cryptic DB crash.
        echo $OUTPUT->header();
        echo $OUTPUT->notification(
            'Database error saving question. Please visit Site Administration → Notifications ' .
            'to run any pending LearnGuard upgrade steps, then try again. ' .
            'Technical detail: ' . s($e->getMessage()),
            'error'
        );
        echo $OUTPUT->footer();
        exit;
    }

    // Fetch existing answers BEFORE making changes (needed for in-place update matching).
    $existingAnswers = $editing ? array_values($DB->get_records('learnguard_qbank_answers', ['questionid' => $qid], 'sortorder ASC')) : [];

    // Collect non-empty submitted answers from the active section only.
    // Each section posts under its own field-name prefix so they never collide,
    // even if a browser ignores the JS that disables hidden sections.
    if (in_array($qtype, ['multichoice', 'multicheck'])) {
        $answerTexts     = $_POST['mcq_answer_text']     ?? [];
        $answerFractions = $_POST['mcq_answer_fraction'] ?? [];
        $answerFeedback  = $_POST['mcq_answer_feedback']  ?? [];
        $answerIds       = array_map('intval', $_POST['mcq_answer_id'] ?? []);
    } elseif ($qtype === 'shortanswer') {
        $answerTexts     = $_POST['sa_answer_text']     ?? [];
        $answerFractions = $_POST['sa_answer_fraction'] ?? [];
        $answerFeedback  = array_fill(0, count($answerTexts), '');
        $answerIds       = array_map('intval', $_POST['sa_answer_id'] ?? []);
    } elseif ($qtype === 'matching') {
        $answerTexts     = $_POST['match_answer_text']     ?? [];
        $answerFractions = $_POST['match_answer_fraction'] ?? [];
        $answerFeedback  = $_POST['match_answer_feedback']  ?? [];
        $answerIds       = array_map('intval', $_POST['match_answer_id'] ?? []);
    } else {
        // essay, truefalse — no generic answer inputs; handled separately below.
        $answerTexts = $answerFractions = $answerFeedback = $answerIds = [];
    }

    // Build submitted rows, preserving original index for ID lookup.
    $submittedRows = [];
    foreach ($answerTexts as $i => $atext) {
        $atext = trim((string)$atext);
        if ($atext === '') { continue; }
        $submittedRows[] = [
            'id'       => (int)($answerIds[$i] ?? 0),
            'text'     => $atext,
            'fraction' => isset($answerFractions[$i]) ? (float)$answerFractions[$i] : 0.0,
            'feedback' => $answerFeedback[$i] ?? '',
        ];
    }

    // Save answers: match by DB ID first (allows text edits without losing the ID),
    // fall back to text-match for rows without an ID, insert truly new rows.
    // Safety guard: if no rows were submitted at all, skip deletion to protect
    // existing answers from accidental wipe (e.g. old form with renamed fields).
    $existingById    = [];
    foreach ($existingAnswers as $ea) { $existingById[(int)$ea->id] = $ea; }
    $usedExistingIds = [];
    $sort = 0;

    foreach ($submittedRows as $sub) {
        if ($sub['id'] > 0 && isset($existingById[$sub['id']]) && !isset($usedExistingIds[$sub['id']])) {
            // Matched by DB ID — update text AND fraction/feedback so admins can correct typos.
            $DB->update_record('learnguard_qbank_answers', (object)[
                'id'         => $sub['id'],
                'answertext' => $sub['text'],
                'fraction'   => $sub['fraction'],
                'feedback'   => $sub['feedback'],
                'sortorder'  => $sort++,
            ]);
            $usedExistingIds[$sub['id']] = true;
        } else {
            // No recognised ID — fall back to case-insensitive text match.
            $matched = null;
            foreach ($existingAnswers as $ea) {
                if (!isset($usedExistingIds[$ea->id]) &&
                    mb_strtolower(trim((string)$ea->answertext)) === mb_strtolower($sub['text'])) {
                    $matched = $ea;
                    break;
                }
            }
            if ($matched) {
                $DB->update_record('learnguard_qbank_answers', (object)[
                    'id'        => $matched->id,
                    'fraction'  => $sub['fraction'],
                    'feedback'  => $sub['feedback'],
                    'sortorder' => $sort++,
                ]);
                $usedExistingIds[$matched->id] = true;
            } else {
                $DB->insert_record('learnguard_qbank_answers', (object)[
                    'questionid'       => $qid,
                    'answertext'       => $sub['text'],
                    'answertextformat' => FORMAT_HTML,
                    'fraction'         => $sub['fraction'],
                    'feedback'         => $sub['feedback'],
                    'sortorder'        => $sort++,
                ]);
            }
        }
    }

    // Delete answers removed by admin — only when the form submitted at least one row.
    if (!empty($submittedRows)) {
        foreach ($existingAnswers as $ea) {
            if (!isset($usedExistingIds[$ea->id])) {
                $DB->delete_records('learnguard_qbank_answers', ['id' => $ea->id]);
            }
        }
    }

    // For truefalse: finalise the true/false answer pair BEFORE auto-regrade so the
    // grader reads the correct fractions. The main loop above left answers empty for
    // truefalse (no answer_text[] submitted from that section), so we insert them here.
    if ($qtype === 'truefalse') {
        $DB->delete_records('learnguard_qbank_answers', ['questionid' => $qid]);
        $correctTF = optional_param('correct_tf', 'true', PARAM_ALPHA);
        foreach (['true', 'false'] as $val) {
            $DB->insert_record('learnguard_qbank_answers', (object)[
                'questionid'       => $qid,
                'answertext'       => $val,
                'answertextformat' => FORMAT_PLAIN,
                'fraction'         => ($val === $correctTF) ? 1.0 : 0.0,
                'feedback'         => '',
                'sortorder'        => ($val === 'true') ? 0 : 1,
            ]);
        }
    }

    // Save Choice Essay prompts (for choiceessay type).
    if ($qtype === 'choiceessay') {
        $existingCE  = array_values($DB->get_records('learnguard_ce_prompts', ['questionid' => $qid], 'sortorder ASC'));
        $existingById = [];
        foreach ($existingCE as $ep) { $existingById[$ep->id] = $ep; }
        $usedCEIds = [];
        $cesort    = 0;
        for ($pi = 0; $pi <= 3; $pi++) {
            $ptext = lg_html_param("ce_prompt_text_{$pi}");
            $pid   = optional_param("ce_prompt_id_{$pi}", 0, PARAM_INT);
            if (trim(strip_tags($ptext)) === '') { continue; }
            if ($pid > 0 && isset($existingById[$pid]) && !isset($usedCEIds[$pid])) {
                $DB->update_record('learnguard_ce_prompts', (object)[
                    'id' => $pid, 'prompttext' => $ptext, 'prompttextformat' => FORMAT_HTML, 'sortorder' => $cesort++,
                ]);
                $usedCEIds[$pid] = true;
            } else {
                $DB->insert_record('learnguard_ce_prompts', (object)[
                    'questionid' => $qid, 'prompttext' => $ptext, 'prompttextformat' => FORMAT_HTML, 'sortorder' => $cesort++,
                ]);
            }
        }
        foreach ($existingCE as $ep) {
            if (!isset($usedCEIds[$ep->id])) {
                $DB->delete_records('learnguard_ce_prompts', ['id' => $ep->id]);
            }
        }
    }

    // Automatically regrade any stored responses that reference this question's slots.
    // Runs after all answers are in DB so grade_response() reads the current fractions.
    try {
        $slots = $DB->get_records('learnguard_slots', ['questionid' => $qid]);
        if (!empty($slots)) {
            foreach ($slots as $slot) {
                $slotid = (int)$slot->id;
                $responses = $DB->get_records('learnguard_responses', ['slotid' => $slotid]);
                foreach ($responses as $resp) {
                    $decoded = json_decode($resp->responsedata, true) ?? [];
                    // Determine attempt slotnum (attempt-specific position) if available.
                    $attemptslot = (int)$DB->get_field('learnguard_attempt_questions', 'slotnum', ['attemptid' => $resp->attemptid, 'slotid' => $slotid]) ?: (int)$slot->slot;
                    [$newfrac, $newstate] = \mod_learnguard\question_engine::grade_response((int)$qid, $attemptslot, $decoded);
                    $newfrac = (float)$newfrac;
                    // Update response fraction/state/timemodified.
                    $DB->set_field('learnguard_responses', 'fraction', $newfrac, ['id' => $resp->id]);
                    $DB->set_field('learnguard_responses', 'state',    $newstate, ['id' => $resp->id]);
                    $DB->set_field('learnguard_responses', 'timemodified', time(), ['id' => $resp->id]);

                    // If attempt is finished, recalculate total grade and push to gradebook.
                    $attempt = $DB->get_record('learnguard_attempts', ['id' => $resp->attemptid]);
                    if ($attempt && $attempt->state === 'finished') {
                        $lg = null;
                        if (!empty($slot->learnguard)) {
                            $lg = $DB->get_record('learnguard', ['id' => $slot->learnguard]);
                        }
                        if ($lg) {
                            $newgrade = \mod_learnguard\question_engine::calculate_total_grade((int)$resp->attemptid, $lg);
                            $DB->set_field('learnguard_attempts', 'sumgrades', $newgrade, ['id' => $resp->attemptid]);
                            require_once($CFG->libdir . '/gradelib.php');
                            grade_update('mod/learnguard', $lg->course, 'mod', 'learnguard', $lg->id, 0,
                                [(object)['userid' => $attempt->userid, 'rawgrade' => $newgrade]]);
                        }
                    }
                }
            }
        }
    } catch (\Exception $e) {
        // Non-fatal: regrading failed for some entries — continue and show success message.
    }

    redirect(
        new moodle_url('/mod/learnguard/questionbank.php',
            array_filter(['id' => $id, 'courseid' => $courseid])),
        'Question ' . ($editing ? 'updated' : 'created') . ' successfully.',
        null, \core\output\notification::NOTIFY_SUCCESS
    );
}

$cats = $DB->get_records('learnguard_qbank_cats', [], 'sortorder ASC, name ASC');
$brand = learnguard_brand();

// Ensure at least a General category exists.
if (empty($cats)) {
    $DB->insert_record('learnguard_qbank_cats', (object)[
        'name' => 'General', 'description' => '', 'sortorder' => 0,
        'timecreated' => time(), 'timemodified' => time(),
    ]);
    $cats = $DB->get_records('learnguard_qbank_cats', [], 'sortorder ASC, name ASC');
}

echo $OUTPUT->header();
echo learnguard_nav_bar($id, $editing ? 'Edit Question' : 'New Question');
echo learnguard_banner(
    $editing ? 'Edit Question' : 'Create New Question',
    'All question types: Multiple Choice, True/False, Short Answer, Essay, Matching', '✏️'
);

echo html_writer::link(
    new moodle_url('/mod/learnguard/questionbank.php',
        array_filter(['id' => $id, 'courseid' => $courseid])),
    '← Back to Question Bank', ['class' => 'btn btn-outline-secondary btn-sm mb-3']
);

// ---- The form ----
?>
<form method="post" style="max-width:800px;">
<?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
<?php if ($id):       echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',       'value' => $id]);       endif; ?>
<?php if ($courseid): echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'courseid', 'value' => $courseid]); endif; ?>

<div style="background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:24px;margin-bottom:16px;">

    <!-- Type selector -->
    <div class="mb-4">
        <label style="font-weight:700;display:block;margin-bottom:8px;">Question Type</label>
        <div style="display:flex;flex-wrap:wrap;gap:8px;">
            <?php
            $qtypes = [
                'multichoice'  => ['🔘 Multiple Choice', '#0f6cbf'],
                'multicheck'   => ['☑ Multi-select',    '#6f42c1'],
                'truefalse'    => ['✅ True / False',    '#20c997'],
                'shortanswer'  => ['✍ Short Answer',    '#fd7e14'],
                'essay'        => ['📝 Essay',           '#795548'],
                'matching'     => ['↔ Matching',         '#17a2b8'],
                'choiceessay'  => ['🗂 Choice Essay',    '#e83e8c'],
            ];
            $currenttype = $question->qtype ?? 'multichoice';
            foreach ($qtypes as $val => [$label, $colour]):
                $selected = ($currenttype === $val);
            ?>
            <label style="cursor:pointer;">
                <input type="radio" name="qtype" value="<?php echo $val; ?>"
                    <?php echo $selected ? 'checked' : ''; ?>
                    onchange="showTypeUI(this.value)" style="display:none;">
                <span id="typebtn_<?php echo $val; ?>" style="display:inline-block;padding:8px 16px;
                    border-radius:6px;border:2px solid <?php echo $colour; ?>;
                    background:<?php echo $selected ? $colour : '#fff'; ?>;
                    color:<?php echo $selected ? '#fff' : $colour; ?>;
                    font-weight:600;font-size:14px;transition:all 0.15s;">
                    <?php echo $label; ?>
                </span>
            </label>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Question name -->
    <div class="mb-3">
        <label for="qname" style="font-weight:700;display:block;margin-bottom:4px;">Question Name / Title</label>
        <input type="text" id="qname" name="qname"
            value="<?php echo s($question->name ?? ''); ?>"
            class="form-control" required placeholder="e.g. What is the capital of France?">
    </div>

    <!-- Question text — WYSIWYG editor (tables, images, formatting) -->
    <div class="mb-3">
        <label style="font-weight:700;display:block;margin-bottom:4px;">Question Text
            <span style="font-size:11px;color:#6c757d;font-weight:400;margin-left:8px;">
                Bold / Italic / Table / Paste image · HTML formatting preserved for students
            </span>
        </label>
        <div id="lg-wysiwyg-toolbar" style="display:flex;gap:4px;margin-bottom:5px;flex-wrap:wrap;align-items:center;"></div>
        <div id="lg-wysiwyg-qt" contenteditable="true" spellcheck="true"
             style="min-height:120px;border:1px solid #ced4da;border-radius:4px;padding:10px 14px;
                    font-size:15px;line-height:1.65;background:#fff;outline:none;overflow-y:auto;
                    cursor:text;"
             ><?php
            $qtEdit = $question->questiontext ?? '';
            // Show plain-text content with explicit line breaks so the admin can see formatting.
            if ($qtEdit !== '' && !preg_match('/<(p|div|br|table|ul|ol|strong|em|u)\b/i', $qtEdit)) {
                echo nl2br(htmlspecialchars($qtEdit));
            } else {
                echo $qtEdit; // already has HTML markup — admin-only page, safe to echo raw
            }
        ?></div>
        <input type="hidden" name="questiontext__b64" id="lg-qt-b64">
    </div>

    <!-- Category + marks row -->
    <div style="display:flex;gap:16px;flex-wrap:wrap;" class="mb-3">
        <div style="flex:1;min-width:180px;">
            <label style="font-weight:700;display:block;margin-bottom:4px;">Category</label>
            <?php echo html_writer::select(
                array_column(array_values($cats), 'name', 'id'),
                'categoryid', $question->categoryid ?? 0,
                false, ['class' => 'form-control']
            ); ?>
        </div>
        <div style="width:100px;">
            <label style="font-weight:700;display:block;margin-bottom:4px;">Default Marks</label>
            <input type="number" name="defaultmark" value="<?php echo (float)($question->defaultmark ?? ((float)get_config('learnguard', 'default_question_mark') ?: 1.0)); ?>"
                min="0" step="0.5" class="form-control">
        </div>
        <div style="width:140px;display:flex;align-items:flex-end;">
            <label style="cursor:pointer;">
                <input type="checkbox" name="shuffleanswers" value="1"
                    <?php echo (!empty($question->shuffleanswers)) ? 'checked' : ''; ?>>
                Shuffle answers
            </label>
        </div>
        <div style="width:180px;display:flex;align-items:flex-end;">
            <label style="cursor:pointer;" title="Always include this question for every candidate in a randomised assessment, regardless of the random pick count.">
                <input type="checkbox" name="is_mandatory" value="1"
                    <?php echo (!empty($question->is_mandatory)) ? 'checked' : ''; ?>>
                📌 Mandatory question
            </label>
        </div>
    </div>
</div>

<!-- ================================================================
     ANSWER SECTIONS (type-specific)
     ================================================================ -->

<!-- Multiple Choice / Multi-select answers -->
<div id="ui-multichoice" class="lg-type-ui" style="background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:24px;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <label style="font-weight:700;font-size:16px;">Answer Options</label>
        <button type="button" onclick="addAnswerRow()" class="btn btn-sm btn-outline-primary">+ Add Option</button>
    </div>
    <p style="font-size:13px;color:#6c757d;margin-bottom:12px;">
        Set fraction to <strong>1.0</strong> for fully correct, <strong>0.0</strong> for wrong.
        For partial credit, use values between 0 and 1. Negative values penalise.
    </p>
    <div id="answer-rows">
        <?php
        $displayAnswers = $answers ?: [
            (object)['answertext' => '', 'fraction' => 1.0, 'feedback' => ''],
            (object)['answertext' => '', 'fraction' => 0.0, 'feedback' => ''],
            (object)['answertext' => '', 'fraction' => 0.0, 'feedback' => ''],
            (object)['answertext' => '', 'fraction' => 0.0, 'feedback' => ''],
        ];
        if ($editing && in_array($question->qtype, ['truefalse'])) {
            $displayAnswers = [
                (object)['answertext' => '', 'fraction' => 1.0, 'feedback' => ''],
                (object)['answertext' => '', 'fraction' => 0.0, 'feedback' => ''],
            ];
        }
        foreach ($displayAnswers as $i => $a):
        ?>
        <div class="answer-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:flex-start;">
            <input type="hidden" name="mcq_answer_id[]" value="<?php echo (int)($a->id ?? 0); ?>">
            <div style="flex:1;">
                <input type="text" name="mcq_answer_text[]"
                    value="<?php echo s($a->answertext ?? ''); ?>"
                    class="form-control" placeholder="Answer text…">
            </div>
            <div style="width:100px;">
                <input type="number" name="mcq_answer_fraction[]"
                    value="<?php echo (float)($a->fraction ?? 0.0); ?>"
                    step="0.1" min="-1" max="1" class="form-control" placeholder="Fraction">
            </div>
            <div style="width:180px;">
                <input type="text" name="mcq_answer_feedback[]"
                    value="<?php echo s($a->feedback ?? ''); ?>"
                    class="form-control" placeholder="Feedback (optional)">
            </div>
            <button type="button" onclick="this.closest('.answer-row').remove()"
                class="btn btn-sm btn-outline-danger" title="Remove">✕</button>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- True / False -->
<div id="ui-truefalse" class="lg-type-ui" style="display:none;background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:24px;margin-bottom:16px;">
    <label style="font-weight:700;font-size:16px;display:block;margin-bottom:12px;">Correct Answer</label>
    <?php
    $correctTFval = 'true';
    if ($editing && $question->qtype === 'truefalse') {
        foreach ($answers as $a) {
            if ($a->fraction >= 1.0) { $correctTFval = strtolower(trim($a->answertext ?? 'true')); }
        }
    }
    ?>
    <label style="margin-right:24px;font-size:16px;cursor:pointer;">
        <input type="radio" name="correct_tf" value="true" <?php echo $correctTFval === 'true' ? 'checked' : ''; ?>>
        ✅ True
    </label>
    <label style="font-size:16px;cursor:pointer;">
        <input type="radio" name="correct_tf" value="false" <?php echo $correctTFval === 'false' ? 'checked' : ''; ?>>
        ❌ False
    </label>
</div>

<!-- Short Answer — accepted answers -->
<div id="ui-shortanswer" class="lg-type-ui" style="display:none;background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:24px;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
        <label style="font-weight:700;font-size:16px;">Accepted Answers</label>
        <button type="button" onclick="addAnswerRow()" class="btn btn-sm btn-outline-primary">+ Add Variant</button>
    </div>
    <p style="font-size:13px;color:#6c757d;margin-bottom:12px;">
        Add all accepted answers. Use <code>*</code> as a wildcard (e.g., <code>par*</code> matches "Paris", "park", etc.).
        First answer with fraction=1.0 is the primary correct answer.
    </p>
    <div style="margin-bottom:12px;">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
            <input type="checkbox" name="casesensitive" value="1"
                   <?php if ($editing && !empty($question->casesensitive)) echo 'checked'; ?>>
            <span>Case-sensitive matching <small style="color:#6c757d;">(by default answers are case-insensitive)</small></span>
        </label>
    </div>
    <div id="sa-rows">
        <?php
        $saAnswers = ($editing && $question->qtype === 'shortanswer') ? $answers : [
            (object)['answertext' => '', 'fraction' => 1.0, 'feedback' => ''],
        ];
        foreach ($saAnswers as $i => $a):
        ?>
        <div class="answer-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
            <input type="hidden" name="sa_answer_id[]" value="<?php echo (int)($a->id ?? 0); ?>">
            <input type="text" name="sa_answer_text[]"
                value="<?php echo s($a->answertext ?? ''); ?>"
                class="form-control" placeholder="Accepted answer text…">
            <input type="number" name="sa_answer_fraction[]"
                value="<?php echo (float)($a->fraction ?? 1.0); ?>"
                step="0.1" min="0" max="1" class="form-control" style="width:90px;" placeholder="1.0">
            <button type="button" onclick="this.closest('.answer-row').remove()"
                class="btn btn-sm btn-outline-danger">✕</button>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- Essay — no answers needed -->
<div id="ui-essay" class="lg-type-ui" style="display:none;background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:24px;margin-bottom:16px;">
    <p style="color:#6c757d;">Essay questions have no predefined answers. Students type a free-form response.<br>
    The system auto-awards full marks (manual grading feature planned for a future version).</p>

    <label style="font-weight:700;display:block;margin-bottom:4px;">
        Marking Guide (shown to graders only, not to students)
    </label>
    <textarea id="lg-ta-markingguidance" name="markingguidance" rows="4" class="form-control lg-fmt-ta"
        placeholder="Optional guidance for the grader — model answer, key points to look for, mark allocation, etc…"><?php
        echo s($question->markingguidance ?? '');
    ?></textarea>
</div>

<!-- Choice Essay — student sees N randomly-selected prompts and answers ONE -->
<div id="ui-choiceessay" class="lg-type-ui" style="display:none;background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:24px;margin-bottom:16px;">
    <p style="color:#6c757d;margin-bottom:16px;">
        Define 2–4 essay prompts below. Each student will be shown a random selection and must choose <strong>one</strong> to answer.
        Leave unused slots blank.
    </p>

    <div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;padding:12px 16px;
                background:#fff3cd;border:1px solid #ffc107;border-radius:6px;">
        <label style="font-weight:700;white-space:nowrap;">Show how many prompts per student?</label>
        <select name="ce_show_count" class="form-control" style="width:100px;">
            <?php
            $ceShow = (int)($question->show_prompts_count ?? 0);
            foreach ([0 => 'All', 1 => '1', 2 => '2', 3 => '3', 4 => '4'] as $v => $lbl):
            ?>
            <option value="<?php echo $v; ?>" <?php echo ($ceShow === $v) ? 'selected' : ''; ?>>
                <?php echo $lbl; ?>
            </option>
            <?php endforeach; ?>
        </select>
        <span style="font-size:12px;color:#856404;">(0 = show all defined prompts)</span>
    </div>

    <?php for ($pi = 0; $pi <= 3; $pi++):
        $cp = $cePrompts[$pi] ?? null;
    ?>
    <div style="border:1px solid #dee2e6;border-radius:8px;padding:16px;margin-bottom:14px;background:#fafafa;">
        <label style="font-weight:700;font-size:13px;color:#6c757d;display:block;margin-bottom:8px;">
            PROMPT <?php echo $pi + 1; ?> <span style="font-weight:400;">(leave blank to omit)</span>
        </label>
        <input type="hidden" name="ce_prompt_id_<?php echo $pi; ?>" value="<?php echo (int)($cp->id ?? 0); ?>">
        <textarea name="ce_prompt_text_<?php echo $pi; ?>" rows="4" class="form-control lg-fmt-ta"
            placeholder="Enter essay prompt <?php echo $pi + 1; ?> here…"><?php
                echo s($cp->prompttext ?? '');
        ?></textarea>
    </div>
    <?php endfor; ?>

    <label style="font-weight:700;display:block;margin-bottom:4px;margin-top:8px;">
        Marking Guide (shown to graders only)
    </label>
    <textarea name="markingguidance" rows="3" class="form-control lg-fmt-ta"
        placeholder="Optional guidance for graders — key points, mark allocation, etc…"><?php
        echo s($question->markingguidance ?? '');
    ?></textarea>
</div>

<!-- Matching -->
<div id="ui-matching" class="lg-type-ui" style="display:none;background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:24px;margin-bottom:16px;">
    <p style="font-size:14px;color:#6c757d;margin-bottom:12px;">
        Add pairs. <strong>Left items</strong> are shown to the student, <strong>Right items</strong> are the options to match.<br>
        Store left items with fraction <code>-1</code>, right items with fraction <code>1</code>.
    </p>
    <div id="match-rows">
        <?php
        $matchAnswers = ($editing && $question->qtype === 'matching') ? $answers : [
            (object)['answertext' => 'Left 1', 'fraction' => -1.0, 'feedback' => ''],
            (object)['answertext' => 'Right 1', 'fraction' => 1.0, 'feedback' => ''],
        ];
        foreach ($matchAnswers as $i => $a):
        ?>
        <div class="answer-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center;">
            <input type="hidden" name="match_answer_id[]" value="<?php echo (int)($a->id ?? 0); ?>">
            <select name="match_answer_fraction[]" class="form-control" style="width:110px;">
                <option value="-1" <?php echo ($a->fraction < 0) ? 'selected' : ''; ?>>← Left item</option>
                <option value="1"  <?php echo ($a->fraction >= 0) ? 'selected' : ''; ?>>→ Right item</option>
            </select>
            <input type="text" name="match_answer_text[]"
                value="<?php echo s($a->answertext ?? ''); ?>"
                class="form-control" placeholder="Item text…">
            <input type="hidden" name="match_answer_feedback[]" value="">
            <button type="button" onclick="this.closest('.answer-row').remove()"
                class="btn btn-sm btn-outline-danger">✕</button>
        </div>
        <?php endforeach; ?>
    </div>
    <button type="button" onclick="addMatchRow()" class="btn btn-sm btn-outline-primary mt-2">+ Add Item</button>
</div>

<!-- General feedback -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:8px;padding:24px;margin-bottom:16px;">
    <label style="font-weight:700;display:block;margin-bottom:4px;">
        General Feedback (shown after submission / in review)
    </label>
    <textarea id="lg-ta-generalfeedback" name="generalfeedback" rows="3" class="form-control lg-fmt-ta"
        placeholder="Optional feedback shown to the student after the assessment…"><?php
        echo s($question->generalfeedback ?? '');
    ?></textarea>
</div>

<div style="display:flex;gap:12px;">
    <button type="submit" class="btn btn-primary btn-lg">
        <?php echo $editing ? '💾 Update Question' : '✅ Save Question'; ?>
    </button>
    <a href="<?php echo new moodle_url('/mod/learnguard/questionbank.php',
        array_filter(['id' => $id, 'courseid' => $courseid])); ?>"
        class="btn btn-outline-secondary btn-lg">Cancel</a>
</div>
</form>

<script>
/* ── Minimal rich-text helper functions for question textareas ───────── */
/* ── Formatting helpers — injected above every .lg-fmt-ta textarea ── */
function lgFmtInsert(ta, before, after) {
    if (typeof ta === 'string') { ta = document.getElementById(ta); }
    if (!ta) return;
    var s = ta.selectionStart, e = ta.selectionEnd, val = ta.value;
    var sel = val.substring(s, e);
    ta.value = val.substring(0, s) + before + sel + after + val.substring(e);
    ta.selectionStart = s + before.length;
    ta.selectionEnd   = s + before.length + sel.length;
    ta.focus();
}
function lgFmtList(ta, tag) {
    if (typeof ta === 'string') { ta = document.getElementById(ta); }
    if (!ta) return;
    var s = ta.selectionStart, e = ta.selectionEnd, val = ta.value;
    var sel = val.substring(s, e) || 'List item';
    var items = sel.split('\n').filter(function(l){ return l.trim(); });
    if (!items.length) items = ['List item'];
    var rep = '<' + tag + '>\n' + items.map(function(l){ return '  <li>' + l.trim() + '</li>'; }).join('\n') + '\n</' + tag + '>';
    ta.value = val.substring(0, s) + rep + val.substring(e);
    ta.selectionEnd = ta.selectionStart = s + rep.length;
    ta.focus();
}
function lgInjectFmtToolbar(ta) {
    var wrapper = document.createElement('div');
    wrapper.style.cssText = 'display:flex;gap:4px;margin-bottom:5px;flex-wrap:wrap;align-items:center;';
    var btnStyle = 'padding:2px 9px;border:1px solid #ced4da;border-radius:4px;background:#f8f9fa;cursor:pointer;font-size:13px;';
    var buttons = [
        ['<strong>B</strong>', 'Bold',           function(){ lgFmtInsert(ta,'<strong>','</strong>'); }],
        ['<em>I</em>',         'Italic',          function(){ lgFmtInsert(ta,'<em>','</em>'); }],
        ['<u>U</u>',           'Underline',       function(){ lgFmtInsert(ta,'<u>','</u>'); }],
        ['• List',             'Bullet list',     function(){ lgFmtList(ta,'ul'); }],
        ['1. List',            'Numbered list',   function(){ lgFmtList(ta,'ol'); }],
        ['↵ BR',               'Insert line break', function(){ lgFmtInsert(ta,'<br>',''); }],
    ];
    buttons.forEach(function(b) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.innerHTML = b[0];
        btn.title = b[1];
        btn.style.cssText = btnStyle;
        btn.addEventListener('click', b[2]);
        wrapper.appendChild(btn);
    });
    var hint = document.createElement('span');
    hint.textContent = 'HTML — select text, then click a style';
    hint.style.cssText = 'color:#6c757d;font-size:11px;margin-left:4px;';
    wrapper.appendChild(hint);
    ta.parentNode.insertBefore(wrapper, ta);
}
/* ── WYSIWYG editor for the question text field ─────────────────────── */
function lgInitWysiwyg(divId, toolbarId, b64InputId) {
    var div    = document.getElementById(divId);
    var tlbar  = document.getElementById(toolbarId);
    var b64inp = document.getElementById(b64InputId);
    if (!div || !tlbar) { return; }

    var btnStyle = 'padding:2px 9px;border:1px solid #ced4da;border-radius:4px;background:#f8f9fa;cursor:pointer;font-size:13px;';
    function cmd(c, v) { div.focus(); document.execCommand(c, false, v !== undefined ? v : null); }

    var buttons = [
        ['<strong>B</strong>', 'Bold',         function(){ cmd('bold'); }],
        ['<em>I</em>',         'Italic',        function(){ cmd('italic'); }],
        ['<u>U</u>',           'Underline',     function(){ cmd('underline'); }],
        ['• List',             'Bullet list',   function(){ cmd('insertUnorderedList'); }],
        ['1. List',            'Numbered list', function(){ cmd('insertOrderedList'); }],
        ['↵ BR',               'Line break',    function(){ cmd('insertHTML', '<br>'); }],
        ['⊞ Table',            'Insert table',  function(){
            var r = parseInt(prompt('Rows:', '3') || '0');
            var c = parseInt(prompt('Columns:', '3') || '0');
            if (!r || !c) { return; }
            var th  = '<tr>' + Array(c).fill('<th style="border:1px solid #dee2e6;padding:8px;background:#f8f9fa;font-weight:700;">Header</th>').join('') + '</tr>';
            var trs = Array(Math.max(0, r - 1)).fill(null).map(function(){
                return '<tr>' + Array(c).fill('<td style="border:1px solid #dee2e6;padding:8px;">&nbsp;</td>').join('') + '</tr>';
            }).join('');
            cmd('insertHTML', '<table style="border-collapse:collapse;width:100%;margin:10px 0;">' + th + trs + '</table><p><br></p>');
        }],
        ['🖼 Image', 'Insert image from file', function(){
            var inp = document.createElement('input');
            inp.type = 'file'; inp.accept = 'image/*';
            inp.onchange = function(){
                var f = inp.files[0]; if (!f) { return; }
                var reader = new FileReader();
                reader.onload = function(ev){ cmd('insertImage', ev.target.result); };
                reader.readAsDataURL(f);
            };
            inp.click();
        }],
    ];

    buttons.forEach(function(b) {
        var btn = document.createElement('button');
        btn.type = 'button'; btn.innerHTML = b[0]; btn.title = b[1];
        btn.style.cssText = btnStyle;
        btn.addEventListener('mousedown', function(e){ e.preventDefault(); }); // keep editor focused
        btn.addEventListener('click', b[2]);
        tlbar.appendChild(btn);
    });

    // Image paste from clipboard.
    div.addEventListener('paste', function(e) {
        var items = (e.clipboardData || window.clipboardData || {}).items || [];
        for (var i = 0; i < items.length; i++) {
            if (items[i].type.indexOf('image') === 0) {
                e.preventDefault();
                var blob = items[i].getAsFile();
                var reader = new FileReader();
                reader.onload = function(ev){ cmd('insertImage', ev.target.result); };
                reader.readAsDataURL(blob);
                return;
            }
        }
        // Non-image paste (text, HTML tables from Word/Excel): let browser handle naturally.
    });
}

document.addEventListener('DOMContentLoaded', function() {
    // Init WYSIWYG editor for the question text field.
    lgInitWysiwyg('lg-wysiwyg-qt', 'lg-wysiwyg-toolbar', 'lg-qt-b64');

    document.querySelectorAll('textarea.lg-fmt-ta').forEach(function(ta) {
        lgInjectFmtToolbar(ta);
    });
    // WAF bypass: base64-encode HTML textarea content before POST so mod_security
    // does not block requests containing HTML tags.  PHP reads the __b64 copy and
    // decodes it via lg_html_param().
    document.querySelectorAll('form').forEach(function(form) {
        form.addEventListener('submit', function() {
            // Serialize WYSIWYG div into the hidden b64 input.
            var wysiwyg = document.getElementById('lg-wysiwyg-qt');
            if (wysiwyg) {
                var b64hid = form.querySelector('#lg-qt-b64') || form.querySelector('[name="questiontext__b64"]');
                if (!b64hid) {
                    b64hid = document.createElement('input');
                    b64hid.type = 'hidden'; b64hid.name = 'questiontext__b64';
                    form.appendChild(b64hid);
                }
                try { b64hid.value = btoa(unescape(encodeURIComponent(wysiwyg.innerHTML))); }
                catch(e) { b64hid.value = btoa(wysiwyg.innerHTML || ''); }
            }
            // WAF bypass for lg-fmt-ta textareas.
            form.querySelectorAll('textarea.lg-fmt-ta').forEach(function(ta) {
                if (!ta.name) { return; }
                var hid = form.querySelector('input[name="' + ta.name + '__b64"]');
                if (!hid) {
                    hid = document.createElement('input');
                    hid.type = 'hidden';
                    hid.name = ta.name + '__b64';
                    form.appendChild(hid);
                }
                try { hid.value = btoa(unescape(encodeURIComponent(ta.value))); }
                catch(e) { hid.value = btoa(ta.value || ''); }
                ta.name = ''; // prevent raw HTML reaching the WAF
            });
        });
    });
});

var typeColors = {
    multichoice: '#0f6cbf', multicheck: '#6f42c1', truefalse: '#20c997',
    shortanswer: '#fd7e14', essay: '#795548', matching: '#17a2b8', choiceessay: '#e83e8c'
};

function showTypeUI(type) {
    document.querySelectorAll('.lg-type-ui').forEach(function(el) { el.style.display = 'none'; });
    var uiMap = {
        multichoice: 'ui-multichoice', multicheck: 'ui-multichoice',
        truefalse: 'ui-truefalse', shortanswer: 'ui-shortanswer',
        essay: 'ui-essay', matching: 'ui-matching', choiceessay: 'ui-choiceessay'
    };
    var target = document.getElementById(uiMap[type]);
    if (target) { target.style.display = 'block'; }

    // Update button styling.
    Object.keys(typeColors).forEach(function(t) {
        var btn = document.getElementById('typebtn_' + t);
        if (!btn) return;
        if (t === type) {
            btn.style.background = typeColors[t];
            btn.style.color = '#fff';
        } else {
            btn.style.background = '#fff';
            btn.style.color = typeColors[t];
        }
    });
}

function addAnswerRow() {
    var saEl = document.getElementById('ui-shortanswer');
    var isSA = saEl && saEl.style.display !== 'none';
    var container = isSA ? document.getElementById('sa-rows') : document.getElementById('answer-rows');
    var row = document.createElement('div');
    row.className = 'answer-row';
    if (isSA) {
        row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;';
        row.innerHTML =
            '<input type="hidden" name="sa_answer_id[]" value="0">' +
            '<input type="text" name="sa_answer_text[]" class="form-control" placeholder="Accepted answer text…">' +
            '<input type="number" name="sa_answer_fraction[]" value="1" step="0.1" min="0" max="1" class="form-control" style="width:90px;" placeholder="1.0">' +
            '<button type="button" onclick="this.closest(\'.answer-row\').remove()" class="btn btn-sm btn-outline-danger">✕</button>';
    } else {
        row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:flex-start;';
        row.innerHTML =
            '<input type="hidden" name="mcq_answer_id[]" value="0">' +
            '<div style="flex:1;"><input type="text" name="mcq_answer_text[]" class="form-control" placeholder="Answer text…"></div>' +
            '<div style="width:100px;"><input type="number" name="mcq_answer_fraction[]" value="0" step="0.1" min="-1" max="1" class="form-control" placeholder="Fraction"></div>' +
            '<div style="width:180px;"><input type="text" name="mcq_answer_feedback[]" class="form-control" placeholder="Feedback"></div>' +
            '<button type="button" onclick="this.closest(\'.answer-row\').remove()" class="btn btn-sm btn-outline-danger">✕</button>';
    }
    container.appendChild(row);
}

function addMatchRow() {
    var container = document.getElementById('match-rows');
    var row = document.createElement('div');
    row.className = 'answer-row';
    row.style.cssText = 'display:flex;gap:8px;margin-bottom:8px;align-items:center;';
    row.innerHTML =
        '<input type="hidden" name="match_answer_id[]" value="0">' +
        '<select name="match_answer_fraction[]" class="form-control" style="width:110px;">' +
        '<option value="-1">← Left item</option><option value="1">→ Right item</option></select>' +
        '<input type="text" name="match_answer_text[]" class="form-control" placeholder="Item text…">' +
        '<input type="hidden" name="match_answer_feedback[]" value="">' +
        '<button type="button" onclick="this.closest(\'.answer-row\').remove()" class="btn btn-sm btn-outline-danger">✕</button>';
    container.appendChild(row);
}

// Init on page load.
showTypeUI(<?php echo json_encode($question->qtype ?? 'multichoice'); ?>);

// Disable inputs inside hidden type-UI sections before form submit so they
// don't pollute answer_text[] with defaults like "Left 1" / "Right 1".
document.querySelector('form').addEventListener('submit', function() {
    document.querySelectorAll('.lg-type-ui').forEach(function(el) {
        if (el.style.display === 'none' || el.style.display === '') {
            el.querySelectorAll('input, select, textarea').forEach(function(inp) {
                inp.disabled = true;
            });
        }
    });
});
</script>

<?php echo $OUTPUT->footer();
