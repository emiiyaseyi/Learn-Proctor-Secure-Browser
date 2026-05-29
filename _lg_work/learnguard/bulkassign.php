<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Bulk assignment page — enable/disable LearnGuard proctoring for
 * all LearnGuard activities in a course, or individual ones.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url(new moodle_url('/mod/learnguard/bulkassign.php'));
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Bulk Assignment');
$PAGE->set_heading('LearnGuard — Bulk Assignment');

// ── Handle POST ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && confirm_sesskey()) {
    $courseid   = required_param('courseid',    PARAM_INT);
    $lgids      = optional_param_array('lgids', [], PARAM_INT);
    $enableall  = optional_param('enableall',   0,  PARAM_INT);
    $disableall = optional_param('disableall',  0,  PARAM_INT);

    if ($enableall || $disableall) {
        $recs  = $DB->get_records('learnguard', ['course' => $courseid], '', 'id');
        $lgids = array_keys($recs);
    }

    $enabled = 0; $disabled = 0;
    foreach ($lgids as $lgid) {
        $lgid = (int)$lgid;
        if (!$lgid) { continue; }
        $enable = $enableall ? true : (!$disableall);
        $DB->set_field('learnguard', 'proctoring', $enable ? 1 : 0, ['id' => $lgid]);
        if ($enable) { $enabled++; } else { $disabled++; }
    }

    $msg = '';
    if ($enabled)  { $msg .= $enabled  . ' assessment' . ($enabled  > 1 ? 's' : '') . ' proctoring enabled. '; }
    if ($disabled) { $msg .= $disabled . ' assessment' . ($disabled > 1 ? 's' : '') . ' proctoring disabled. '; }

    redirect(new moodle_url('/mod/learnguard/bulkassign.php',
        ['courseid' => $courseid, 'saved' => 1, 'msg' => urlencode(trim($msg))]),
        trim($msg), 2);
}

// ── Load data ─────────────────────────────────────────────────────────────────
$selectedCourse = optional_param('courseid', 0, PARAM_INT);
$saved          = optional_param('saved',    0, PARAM_INT);
$savedMsg       = optional_param('msg',      '', PARAM_TEXT);

// Courses that have at least one LearnGuard activity.
$courses = $DB->get_records_sql(
    "SELECT DISTINCT c.id, c.fullname, c.shortname
       FROM {course} c
       JOIN {learnguard} lg ON lg.course = c.id
      WHERE c.id > 1
   ORDER BY c.fullname ASC"
);

$activities = [];
if ($selectedCourse) {
    $lgList = $DB->get_records('learnguard', ['course' => $selectedCourse], 'name ASC',
        'id, name, proctoring, proctor_webcam, timecreated');
    foreach ($lgList as $lg) {
        $activities[] = $lg;
    }
}

$enabledCount  = count(array_filter($activities, function($a) { return $a->proctoring; }));
$disabledCount = count($activities) - $enabledCount;

// ── Output ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
?>

<div style="max-width:900px;margin:0 auto;font-family:system-ui,sans-serif;">

    <div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;">
        <div style="font-size:40px;">🛡</div>
        <div>
            <h2 style="margin:0;font-size:22px;">LearnGuard — Bulk Assignment</h2>
            <p style="margin:4px 0 0;color:#6c757d;font-size:14px;">
                Enable or disable proctoring for all LearnGuard assessments in a course, or individually.
            </p>
        </div>
    </div>

    <?php if ($saved && $savedMsg): ?>
    <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:12px 16px;margin-bottom:20px;color:#155724;font-weight:600;">
        ✅ <?php echo htmlspecialchars($savedMsg); ?>
    </div>
    <?php endif; ?>

    <?php if (empty($courses)): ?>
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:16px;">
        ⚠️ No courses with LearnGuard assessments found. Add LearnGuard activities to a course first.
    </div>
    <?php else: ?>

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
                <button type="submit" class="btn btn-primary">Load Assessments</button>
            </div>
        </form>
    </div>

    <?php if ($selectedCourse && !empty($activities)): ?>
    <div style="background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">

        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
            <div>
                <h4 style="margin:0;">Step 2 — Manage Assessments</h4>
                <p style="margin:4px 0 0;font-size:13px;color:#6c757d;">
                    <?php echo count($activities); ?> assessment<?php echo count($activities) > 1 ? 's' : ''; ?> found
                    — <span style="color:#28a745;font-weight:600;"><?php echo $enabledCount; ?> proctored</span>,
                    <span style="color:#6c757d;"><?php echo $disabledCount; ?> not proctored</span>
                </p>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <form method="post" action="" style="display:inline;">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="courseid" value="<?php echo $selectedCourse; ?>">
                    <input type="hidden" name="enableall" value="1">
                    <button type="submit" class="btn btn-success btn-sm"
                        onclick="return confirm('Enable proctoring for ALL <?php echo count($activities); ?> assessments?')">
                        ✅ Enable All
                    </button>
                </form>
                <form method="post" action="" style="display:inline;">
                    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                    <input type="hidden" name="courseid" value="<?php echo $selectedCourse; ?>">
                    <input type="hidden" name="disableall" value="1">
                    <button type="submit" class="btn btn-outline-danger btn-sm"
                        onclick="return confirm('Disable proctoring for ALL assessments?')">
                        ❌ Disable All
                    </button>
                </form>
            </div>
        </div>

        <table class="table table-hover" style="width:100%;border-collapse:collapse;font-size:14px;">
            <thead>
                <tr style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">
                    <th style="padding:10px 14px;">Assessment Name</th>
                    <th style="padding:10px 14px;text-align:center;width:130px;">Proctoring</th>
                    <th style="padding:10px 14px;text-align:center;width:180px;">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($activities as $lg): ?>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:10px 14px;">
                        <strong><?php echo htmlspecialchars($lg->name); ?></strong>
                        <br><small style="color:#6c757d;">
                            Created: <?php echo userdate($lg->timecreated, '%d/%m/%Y'); ?>
                        </small>
                    </td>
                    <td style="padding:10px 14px;text-align:center;">
                        <?php if ($lg->proctoring): ?>
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
                        <?php if ($lg->proctoring): ?>
                        <form method="post" action="" style="display:inline;">
                            <input type="hidden" name="sesskey"  value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="courseid" value="<?php echo $selectedCourse; ?>">
                            <input type="hidden" name="lgids[]"  value="<?php echo $lg->id; ?>">
                            <input type="hidden" name="disableall" value="0">
                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                onclick="return confirm('Remove proctoring from \'<?php echo htmlspecialchars(addslashes($lg->name)); ?>\'?')">
                                Remove
                            </button>
                        </form>
                        <?php else: ?>
                        <form method="post" action="" style="display:inline;">
                            <input type="hidden" name="sesskey"  value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="courseid" value="<?php echo $selectedCourse; ?>">
                            <input type="hidden" name="lgids[]"  value="<?php echo $lg->id; ?>">
                            <input type="hidden" name="disableall" value="0">
                            <button type="submit" class="btn btn-success btn-sm">
                                + Enable
                            </button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p style="font-size:12px;color:#6c757d;margin-top:12px;">
            💡 Changes take effect immediately — no page reload needed after saving.
        </p>
    </div>

    <?php elseif ($selectedCourse && empty($activities)): ?>
    <div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:16px;">
        ⚠️ No LearnGuard assessments found in the selected course.
        <a href="<?php echo new moodle_url('/course/view.php', ['id' => $selectedCourse]); ?>" class="btn btn-sm btn-outline-primary" style="margin-left:10px;">
            Go to Course
        </a>
    </div>

    <?php else: ?>
    <div style="background:#f0f4ff;border:1px solid #b8d0f9;border-radius:8px;padding:16px;color:#0f6cbf;">
        ℹ️ Select a course above to view and manage its LearnGuard assessments.
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div style="margin-top:20px;">
        <a href="<?php echo new moodle_url('/admin/settings.php', ['section' => 'modsettingsmod_learnguard']); ?>"
           class="btn btn-outline-secondary btn-sm">← Back to Plugin Settings</a>
    </div>
</div>

<?php echo $OUTPUT->footer(); ?>
