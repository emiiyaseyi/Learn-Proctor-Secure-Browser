<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Bulk assignment page — enable/disable Learn Shield proctoring for
 * all quizzes in a course, or for individual quizzes.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/bulkassign.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('Learn Shield — Bulk Assignment');
$PAGE->set_heading('Learn Shield — Bulk Assignment');

// ── Handle POST (save assignments) ───────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $courseid    = required_param('courseid',    PARAM_INT);
    $action      = optional_param('bulkaction',  '',      PARAM_ALPHA); // 'enable' | 'disable'
    $quizids     = optional_param_array('quizids', [],    PARAM_INT);
    $enableall   = optional_param('enableall',   0,       PARAM_INT);
    $disableall  = optional_param('disableall',  0,       PARAM_INT);

    // Get all quiz IDs in this course if bulk action requested.
    if ($enableall || $disableall) {
        $quizrecords = $DB->get_records('quiz', ['course' => $courseid], '', 'id');
        $quizids = array_keys($quizrecords);
    }

    $enabled  = 0;
    $disabled = 0;

    foreach ($quizids as $quizid) {
        $quizid = (int)$quizid;
        if (!$quizid) { continue; }

        $enable = $enableall ? true : (!$disableall);

        if ($enable) {
            // Enable proctoring: upsert record.
            $existing = $DB->get_record('quizaccess_learnshield', ['quizid' => $quizid]);
            if ($existing) {
                $existing->proctoringrequired = 1;
                $DB->update_record('quizaccess_learnshield', $existing);
            } else {
                $DB->insert_record('quizaccess_learnshield', [
                    'quizid'             => $quizid,
                    'proctoringrequired' => 1,
                ]);
            }
            $enabled++;
        } else {
            // Disable proctoring: remove the record so make() returns null.
            $DB->delete_records('quizaccess_learnshield', ['quizid' => $quizid]);
            $disabled++;
        }
    }

    $msg = '';
    if ($enabled)  { $msg .= $enabled  . ' quiz' . ($enabled  > 1 ? 'zes' : '') . ' enabled. '; }
    if ($disabled) { $msg .= $disabled . ' quiz' . ($disabled > 1 ? 'zes' : '') . ' disabled. '; }

    redirect(new moodle_url('/mod/quiz/accessrule/learnshield/bulkassign.php',
        ['courseid' => $courseid, 'saved' => 1, 'msg' => urlencode(trim($msg))]),
        trim($msg), 2);
}

// ── Load selected course's quizzes ───────────────────────────────────────────
$selectedCourse = optional_param('courseid', 0, PARAM_INT);
$saved          = optional_param('saved',    0, PARAM_INT);
$savedMsg       = optional_param('msg',      '', PARAM_TEXT);

// Get all courses (with at least one quiz).
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, c.shortname
       FROM {course} c
       JOIN {quiz} q ON q.course = c.id
      WHERE c.id > 1
   ORDER BY c.fullname ASC"
);

// Get quizzes for selected course, with proctoring status.
$quizzes = [];
if ($selectedCourse) {
    $quizList = $DB->get_records('quiz', ['course' => $selectedCourse], 'name ASC', 'id, name, timeopen, timeclose');
    foreach ($quizList as $q) {
        $lsRec = $DB->get_record('quizaccess_learnshield', ['quizid' => $q->id]);
        $q->proctored = ($lsRec && $lsRec->proctoringrequired);
        $quizzes[] = $q;
    }
}

$enabledCount  = count(array_filter($quizzes, function($q) { return $q->proctored; }));
$disabledCount = count($quizzes) - $enabledCount;

// ── Output ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();

$backUrl = new moodle_url('/admin/settings.php', ['section' => 'modsettingsquizcat']);
?>

<div style="max-width:900px;margin:0 auto;font-family:system-ui,sans-serif;">

    <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;">
        <div style="font-size:40px;">🛡</div>
        <div>
            <h2 style="margin:0;font-size:22px;">Learn Shield — Bulk Assignment</h2>
            <p style="margin:4px 0 0;color:#6c757d;font-size:14px;">
                Enable or disable proctoring for all quizzes in a course, or for individual quizzes.
            </p>
        </div>
    </div>

    <?php if ($saved && $savedMsg): ?>
    <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:12px 16px;margin-bottom:20px;color:#155724;font-weight:600;">
        ✅ <?php echo htmlspecialchars($savedMsg); ?>
        Proctoring settings updated successfully.
    </div>
    <?php endif; ?>

    <!-- Course selector -->
    <div style="background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
        <h4 style="margin:0 0 14px;">Step 1 — Select a Course</h4>
        <form method="get" action="">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <select name="courseid" class="form-control" style="max-width:400px;flex:1;"
                    onchange="this.form.submit()">
                    <option value="">— Select a course —</option>
                    <?php foreach ($courses as $c): ?>
                    <option value="<?php echo $c->id; ?>"
                        <?php echo $selectedCourse == $c->id ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($c->fullname . ' (' . $c->shortname . ')'); ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-primary">Load Quizzes</button>
            </div>
        </form>
    </div>

    <?php if ($selectedCourse && !empty($quizzes)): ?>
    <div style="background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
            <div>
                <h4 style="margin:0;">Step 2 — Manage Quizzes</h4>
                <p style="margin:4px 0 0;font-size:13px;color:#6c757d;">
                    <?php echo count($quizzes); ?> quiz<?php echo count($quizzes) > 1 ? 'zes' : ''; ?> found
                    — <span style="color:#28a745;font-weight:600;"><?php echo $enabledCount; ?> proctored</span>,
                    <span style="color:#6c757d;"><?php echo $disabledCount; ?> not proctored</span>
                </p>
            </div>
            <!-- Bulk action buttons -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="post" action="" style="display:inline;">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="courseid" value="<?php echo $selectedCourse; ?>">
                    <input type="hidden" name="enableall" value="1">
                    <button type="submit" class="btn btn-success btn-sm"
                        onclick="return confirm('Enable proctoring for ALL <?php echo count($quizzes); ?> quizzes in this course?')">
                        ✅ Enable All (<?php echo count($quizzes); ?>)
                    </button>
                </form>
                <form method="post" action="" style="display:inline;">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="courseid" value="<?php echo $selectedCourse; ?>">
                    <input type="hidden" name="disableall" value="1">
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                        onclick="return confirm('Disable proctoring for ALL <?php echo count($quizzes); ?> quizzes in this course?')">
                        ❌ Disable All
                    </button>
                </form>
            </div>
        </div>

        <!-- Individual quiz table -->
        <form method="post" action="" id="quiz-form">
            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
            <input type="hidden" name="courseid" value="<?php echo $selectedCourse; ?>">

            <table class="table table-hover" style="width:100%;border-collapse:collapse;font-size:14px;">
                <thead>
                    <tr style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">
                        <th style="padding:10px 14px;text-align:left;">Quiz Name</th>
                        <th style="padding:10px 14px;text-align:center;width:130px;">Proctoring</th>
                        <th style="padding:10px 14px;text-align:center;width:180px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($quizzes as $q): ?>
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:10px 14px;">
                            <strong><?php echo htmlspecialchars($q->name); ?></strong>
                            <?php if ($q->timeopen): ?>
                            <br><small style="color:#6c757d;">
                                Opens: <?php echo userdate($q->timeopen, '%d/%m/%Y %H:%M'); ?>
                                <?php if ($q->timeclose): ?>
                                — Closes: <?php echo userdate($q->timeclose, '%d/%m/%Y %H:%M'); ?>
                                <?php endif; ?>
                            </small>
                            <?php endif; ?>
                        </td>
                        <td style="padding:10px 14px;text-align:center;">
                            <?php if ($q->proctored): ?>
                            <span style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;
                                border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700;">
                                🛡 Enabled
                            </span>
                            <?php else: ?>
                            <span style="background:#f8f9fa;color:#6c757d;border:1px solid #dee2e6;
                                border-radius:20px;padding:3px 12px;font-size:12px;">
                                Off
                            </span>
                            <?php endif; ?>
                        </td>
                        <td style="padding:10px 14px;text-align:center;">
                            <?php if ($q->proctored): ?>
                            <form method="post" action="" style="display:inline;">
                                <input type="hidden" name="sesskey"  value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="courseid" value="<?php echo $selectedCourse; ?>">
                                <input type="hidden" name="quizids[]" value="<?php echo $q->id; ?>">
                                <input type="hidden" name="disableall" value="0">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                    name="bulkaction" value="disable"
                                    onclick="return confirm('Remove proctoring from \'<?php echo htmlspecialchars(addslashes($q->name)); ?>\'?')">
                                    Remove
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="post" action="" style="display:inline;">
                                <input type="hidden" name="sesskey"  value="<?php echo sesskey(); ?>">
                                <input type="hidden" name="courseid" value="<?php echo $selectedCourse; ?>">
                                <input type="hidden" name="quizids[]" value="<?php echo $q->id; ?>">
                                <input type="hidden" name="disableall" value="0">
                                <button type="submit" class="btn btn-success btn-sm"
                                    name="bulkaction" value="enable">
                                    + Enable
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </form>

        <p style="font-size:12px;color:#6c757d;margin-top:12px;">
            💡 <strong>Enable All</strong> applies proctoring to every quiz in this course at once.
            Individual quizzes can still be enabled/disabled separately using the buttons above.
        </p>
    </div>

    <?php elseif ($selectedCourse && empty($quizzes)): ?>
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:16px;">
        ⚠️ No quizzes found in the selected course.
    </div>

    <?php elseif (!$selectedCourse): ?>
    <div style="background:#f0f4ff;border:1px solid #b8d0f9;border-radius:8px;padding:16px;color:#0f6cbf;">
        ℹ️ Select a course above to view and manage its quizzes.
    </div>
    <?php endif; ?>

    <div style="margin-top:20px;">
        <a href="<?php echo $backUrl; ?>" class="btn btn-outline-secondary btn-sm">← Back to Plugin Settings</a>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/bulkassign.php'); ?>"
           class="btn btn-outline-primary btn-sm" style="margin-left:6px;">↺ Load Another Course</a>
    </div>
</div>

<?php echo $OUTPUT->footer(); ?>
