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
 * Per-learner exam scheduling for mod_learnguard.
 *
 * Lets a manager assign each learner (individually, via checkboxes, or in
 * bulk via CSV upload) a personal open/close window for this assessment.
 * The effective window is resolved by learnguard_effective_window() and
 * drives both the access check (view.php / external.php) and the live
 * countdowns shown in amd/src/assessment.js.
 *
 * URL: /mod/learnguard/schedule.php?id={cmid}
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

$cm       = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
$course   = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
$instance = $DB->get_record('learnguard', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context       = context_module::instance($cm->id);
$courseContext = context_course::instance($course->id);
require_capability('mod/learnguard:manage', $context);

// All LearnGuard activities in this course (for course-level bulk scheduling).
$courseLgActivities = $DB->get_records_sql(
    "SELECT cm.id AS cmid, cm.instance, lg.name
       FROM {course_modules} cm
       JOIN {learnguard} lg ON lg.id = cm.instance
       JOIN {modules} m ON m.id = cm.module AND m.name = 'learnguard'
      WHERE cm.course = :courseid AND cm.visible = 1
      ORDER BY lg.name ASC",
    ['courseid' => $course->id]
);

// Course-level enrolled users (for bulk scheduling across activities).
$courseEnrolled = get_enrolled_users($courseContext, '', 0, 'u.id,u.username,u.firstname,u.lastname,u.email', 'u.lastname,u.firstname');

// ── Download CSV template (GET, no side-effects) ────────────────────────────
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="learnguard_schedule_template.csv"');
    echo "\xEF\xBB\xBF";
    echo "name,username,email,datestart,dateend,timestart,timeend\n";
    echo "Jane Doe,jdoe,jdoe@example.com,2025-06-20,2025-06-20,09:00,11:00\n";
    exit;
}

$PAGE->set_url('/mod/learnguard/schedule.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title('Schedule — ' . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

/**
 * Convert a "YYYY-MM-DD" date string and an optional "HH:MM" time string
 * into a unix timestamp using the current user's timezone (matches the
 * behaviour of Moodle's own date_time_selector form element).
 */
function learnguard_schedule_parse_dt(string $date, string $time): int {
    $date = trim($date);
    if ($date === '') {
        return 0;
    }
    $dparts = explode('-', $date);
    if (count($dparts) !== 3) {
        return 0;
    }
    [$y, $mo, $d] = array_map('intval', $dparts);

    $h = 0; $mi = 0;
    $time = trim($time);
    if ($time !== '') {
        $tparts = explode(':', $time);
        $h  = (int)($tparts[0] ?? 0);
        $mi = (int)($tparts[1] ?? 0);
    }
    return make_timestamp($y, $mo, $d, $h, $mi);
}

/**
 * Import a CSV of per-learner schedules.
 *
 * Columns: name,username,email,datestart,dateend,timestart,timeend
 * - "name" is informational only (not used for matching).
 * - Learners are matched by username first, falling back to email.
 * - Only learners enrolled with mod/learnguard:attempt in this context match.
 *
 * @return array{0:int,1:array<int,string>} [imported count, error lines]
 */
function learnguard_schedule_import_csv(string $content, int $learnguardid, \context $context, int $createdby): array {
    global $DB;

    $lines  = preg_split('/\r\n|\r|\n/', trim($content));
    $header = array_map('strtolower', array_map('trim', str_getcsv((string)array_shift($lines))));

    if (!in_array('username', $header, true) && !in_array('email', $header, true)) {
        return [0, ['CSV must contain a "username" and/or "email" column.']];
    }

    $enrolled = get_enrolled_users($context, 'mod/learnguard:attempt');
    $byUsername = []; $byEmail = [];
    foreach ($enrolled as $u) {
        $byUsername[strtolower($u->username)] = $u;
        $byEmail[strtolower($u->email)]       = $u;
    }

    $imported = 0; $errors = []; $now = time();

    foreach ($lines as $ln => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') { continue; }
        $row = array_combine($header, str_getcsv($line));
        if (!$row) {
            $errors[] = 'Row ' . ($ln + 2) . ': could not parse line.';
            continue;
        }

        $username = trim($row['username'] ?? '');
        $email    = trim($row['email'] ?? '');

        $user = null;
        if ($username !== '' && isset($byUsername[strtolower($username)])) {
            $user = $byUsername[strtolower($username)];
        } else if ($email !== '' && isset($byEmail[strtolower($email)])) {
            $user = $byEmail[strtolower($email)];
        }
        if (!$user) {
            $errors[] = 'Row ' . ($ln + 2) . ': learner not found or not enrolled (' .
                s($username ?: $email) . ').';
            continue;
        }

        $timeopen  = learnguard_schedule_parse_dt($row['datestart'] ?? '', $row['timestart'] ?? '');
        $timeclose = learnguard_schedule_parse_dt($row['dateend']   ?? '', $row['timeend']   ?? '');
        if (!$timeopen && !$timeclose) {
            $errors[] = 'Row ' . ($ln + 2) . ': no valid date/time provided for ' . s($user->username) . '.';
            continue;
        }

        $existing = $DB->get_record('learnguard_schedules', ['learnguard' => $learnguardid, 'userid' => $user->id]);
        if ($existing) {
            $DB->update_record('learnguard_schedules', (object)[
                'id'           => $existing->id,
                'timeopen'     => $timeopen,
                'timeclose'    => $timeclose,
                'timemodified' => $now,
            ]);
        } else {
            $DB->insert_record('learnguard_schedules', (object)[
                'learnguard'   => $learnguardid,
                'userid'       => $user->id,
                'timeopen'     => $timeopen,
                'timeclose'    => $timeclose,
                'createdby'    => $createdby,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }
        $imported++;
    }

    return [$imported, $errors];
}

// ── Handle POST actions ──────────────────────────────────────────────────────
$messages = [];
$errors   = [];

if (data_submitted() && confirm_sesskey()) {
    if ($action === 'assign') {
        $userids   = optional_param_array('userids', [], PARAM_INT);
        $datestart = optional_param('datestart', '', PARAM_TEXT);
        $timestart = optional_param('timestart', '', PARAM_TEXT);
        $dateend   = optional_param('dateend',   '', PARAM_TEXT);
        $timeend   = optional_param('timeend',   '', PARAM_TEXT);

        $timeopen  = learnguard_schedule_parse_dt($datestart, $timestart);
        $timeclose = learnguard_schedule_parse_dt($dateend,   $timeend);

        if (empty($userids)) {
            $errors[] = 'No learners were selected.';
        } else if (!$timeopen && !$timeclose) {
            $errors[] = 'Please provide an opening date/time, a closing date/time, or both.';
        } else {
            $now = time();
            $count = 0;
            foreach ($userids as $uid) {
                $uid = (int)$uid;
                if (!$uid) { continue; }
                $existing = $DB->get_record('learnguard_schedules', ['learnguard' => $instance->id, 'userid' => $uid]);
                if ($existing) {
                    $DB->update_record('learnguard_schedules', (object)[
                        'id'           => $existing->id,
                        'timeopen'     => $timeopen,
                        'timeclose'    => $timeclose,
                        'timemodified' => $now,
                    ]);
                } else {
                    $DB->insert_record('learnguard_schedules', (object)[
                        'learnguard'   => $instance->id,
                        'userid'       => $uid,
                        'timeopen'     => $timeopen,
                        'timeclose'    => $timeclose,
                        'createdby'    => $USER->id,
                        'timecreated'  => $now,
                        'timemodified' => $now,
                    ]);
                }
                $count++;
            }
            $messages[] = $count . ' learner schedule(s) saved.';
        }
    } else if ($action === 'remove') {
        $scheduleid = required_param('scheduleid', PARAM_INT);
        $DB->delete_records('learnguard_schedules', ['id' => $scheduleid, 'learnguard' => $instance->id]);
        $messages[] = 'Schedule removed — this learner now follows the assessment\'s default availability.';
    } else if ($action === 'assigncourse') {
        // Course-level bulk scheduling — apply one window to multiple users × multiple activities.
        $userids    = optional_param_array('buserids', [], PARAM_INT);
        $targetAll  = optional_param('target_all', 0, PARAM_INT);
        $targetCmids = optional_param_array('target_cmids', [], PARAM_INT);
        $datestart  = optional_param('b_datestart', '', PARAM_TEXT);
        $timestart  = optional_param('b_timestart', '', PARAM_TEXT);
        $dateend    = optional_param('b_dateend', '', PARAM_TEXT);
        $timeend    = optional_param('b_timeend', '', PARAM_TEXT);

        $timeopen  = learnguard_schedule_parse_dt($datestart, $timestart);
        $timeclose = learnguard_schedule_parse_dt($dateend,   $timeend);

        if (empty($userids)) {
            $errors[] = 'No learners were selected.';
        } else if (!$timeopen && !$timeclose) {
            $errors[] = 'Please provide an opening date/time, a closing date/time, or both.';
        } else {
            // Resolve the target activity instances.
            $targetInstances = [];
            if ($targetAll) {
                foreach ($courseLgActivities as $a) { $targetInstances[] = (int)$a->instance; }
            } else {
                foreach ($targetCmids as $tcmid) {
                    $tcmid = (int)$tcmid;
                    if (isset($courseLgActivities[$tcmid])) {
                        $targetInstances[] = (int)$courseLgActivities[$tcmid]->instance;
                    }
                }
            }
            if (empty($targetInstances)) {
                $errors[] = 'No activities were selected.';
            } else {
                $now = time(); $count = 0;
                foreach ($userids as $uid) {
                    $uid = (int)$uid;
                    if (!$uid || !isset($courseEnrolled[$uid])) { continue; }
                    foreach ($targetInstances as $lgInstance) {
                        $existing = $DB->get_record('learnguard_schedules', ['learnguard' => $lgInstance, 'userid' => $uid]);
                        if ($existing) {
                            $DB->update_record('learnguard_schedules', (object)[
                                'id' => $existing->id, 'timeopen' => $timeopen,
                                'timeclose' => $timeclose, 'timemodified' => $now,
                            ]);
                        } else {
                            $DB->insert_record('learnguard_schedules', (object)[
                                'learnguard' => $lgInstance, 'userid' => $uid,
                                'timeopen' => $timeopen, 'timeclose' => $timeclose,
                                'createdby' => $USER->id, 'timecreated' => $now, 'timemodified' => $now,
                            ]);
                        }
                        $count++;
                    }
                }
                $actWord = count($targetInstances) === 1 ? '1 activity' : count($targetInstances) . ' activities';
                $messages[] = count($userids) . ' learner(s) scheduled across ' . $actWord . ' (' . $count . ' schedule record(s) saved).';
                // Refresh courseLgActivities-derived data for this activity's section.
                $schedules = $DB->get_records('learnguard_schedules', ['learnguard' => $instance->id]);
                $schedulesByUser = [];
                foreach ($schedules as $s) { $schedulesByUser[$s->userid] = $s; }
            }
        }
    } else if ($action === 'importcsv') {
        if (!empty($_FILES['importfile']['tmp_name']) && is_uploaded_file($_FILES['importfile']['tmp_name'])) {
            $content = file_get_contents($_FILES['importfile']['tmp_name']);
            [$imported, $csverrors] = learnguard_schedule_import_csv($content, $instance->id, $context, $USER->id);
            if ($imported > 0) {
                $messages[] = $imported . ' schedule(s) imported from CSV.';
            }
            $errors = array_merge($errors, $csverrors);
        } else {
            $errors[] = 'No file was uploaded.';
        }
    }
}

// ── Load data for display ────────────────────────────────────────────────────
$enrolled = get_enrolled_users($context, 'mod/learnguard:attempt', 0, 'u.*', 'lastname, firstname');

$schedules = $DB->get_records('learnguard_schedules', ['learnguard' => $instance->id]);
$schedulesByUser = [];
foreach ($schedules as $s) {
    $schedulesByUser[$s->userid] = $s;
}

// ── Output ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
$brand = learnguard_brand();

echo learnguard_activity_tabs($id, 'schedule');
echo learnguard_nav_bar($id, 'Schedule');
echo learnguard_banner('Per-Learner Scheduling', format_string($instance->name), '📅');

foreach ($messages as $m) {
    echo html_writer::div('✅ ' . s($m), 'alert alert-success');
}
if ($errors) {
    echo html_writer::div('⚠️ ' . implode('<br>⚠️ ', array_map('s', $errors)), 'alert alert-danger');
}

$now = time();
?>
<div style="max-width:1000px;margin:0 auto;font-family:system-ui,sans-serif;">

<!-- ══ Course-level Bulk Scheduling ══════════════════════════════════════════ -->
<?php if (count($courseLgActivities) > 1): ?>
<div style="background:#fff;border:2px solid #0f6cbf;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.08);">
    <h4 style="margin:0 0 4px;color:#0f6cbf;">🏫 Course-Level Bulk Scheduling</h4>
    <p style="color:#6c757d;font-size:13px;margin:0 0 16px;">
        Schedule one or more learners across <strong>multiple activities</strong> in this course at once.
        Individual activity schedules already set are updated, not deleted.
    </p>

    <form method="post" action="">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="assigncourse">

        <!-- Activity selector -->
        <div style="margin-bottom:16px;">
            <label style="font-weight:700;display:block;margin-bottom:8px;">Apply to Activities</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:8px;">
                <label style="display:flex;align-items:center;gap:5px;background:#e8f4fd;border:1px solid #b8d8f8;border-radius:6px;padding:5px 10px;cursor:pointer;">
                    <input type="checkbox" id="lg-bulk-target-all" name="target_all" value="1"
                           onchange="lgBulkToggleAll(this.checked)"> <strong>All activities</strong>
                </label>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;" id="lg-bulk-activity-list">
                <?php foreach ($courseLgActivities as $a): ?>
                    <label style="display:flex;align-items:center;gap:5px;background:#f8f9fa;border:1px solid #dee2e6;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:13px;" class="lg-bulk-act-lbl">
                        <input type="checkbox" class="lg-bulk-act-cb" name="target_cmids[]" value="<?php echo (int)$a->cmid; ?>"
                               <?php echo ((int)$a->cmid === (int)$id) ? 'checked' : ''; ?>>
                        <?php echo htmlspecialchars(format_string($a->name)); ?>
                        <?php if ((int)$a->cmid === (int)$id): ?><em style="color:#0f6cbf;font-size:11px;">(this activity)</em><?php endif; ?>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Course participant selector -->
        <div style="margin-bottom:16px;">
            <label style="font-weight:700;display:block;margin-bottom:8px;">Learners</label>
            <div style="max-height:280px;overflow-y:auto;border:1px solid #e9ecef;border-radius:6px;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                    <thead style="position:sticky;top:0;background:#f8f9fa;z-index:1;">
                        <tr style="border-bottom:2px solid #dee2e6;">
                            <th style="padding:7px 12px;width:36px;">
                                <input type="checkbox" id="lg-bulk-selectall"
                                       onclick="document.querySelectorAll('.lg-bulk-ucb').forEach(function(c){c.checked=this.checked;},this)">
                            </th>
                            <th style="padding:7px 12px;">Name</th>
                            <th style="padding:7px 12px;">Username</th>
                            <th style="padding:7px 12px;">Email</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($courseEnrolled)): ?>
                        <tr><td colspan="4" style="padding:14px;text-align:center;color:#6c757d;">No enrolled users found.</td></tr>
                    <?php else: foreach ($courseEnrolled as $u): ?>
                        <tr style="border-bottom:1px solid #f0f0f0;">
                            <td style="padding:7px 12px;"><input type="checkbox" class="lg-bulk-ucb" name="buserids[]" value="<?php echo (int)$u->id; ?>"></td>
                            <td style="padding:7px 12px;"><?php echo htmlspecialchars(fullname($u)); ?></td>
                            <td style="padding:7px 12px;"><?php echo htmlspecialchars($u->username); ?></td>
                            <td style="padding:7px 12px;"><?php echo htmlspecialchars($u->email); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Date/time pickers -->
        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;">
            <div>
                <label style="font-weight:700;display:block;margin-bottom:4px;">Opens</label>
                <div style="display:flex;gap:6px;">
                    <input type="date" name="b_datestart" class="form-control">
                    <input type="time" name="b_timestart" class="form-control" value="00:00">
                </div>
            </div>
            <div>
                <label style="font-weight:700;display:block;margin-bottom:4px;">Closes</label>
                <div style="display:flex;gap:6px;">
                    <input type="date" name="b_dateend" class="form-control">
                    <input type="time" name="b_timeend" class="form-control" value="23:59">
                </div>
            </div>
        </div>
        <button type="submit" class="btn btn-primary">🏫 Apply to Selected Learners & Activities</button>
    </form>
</div>
<script>
function lgBulkToggleAll(checked) {
    document.querySelectorAll('.lg-bulk-act-cb').forEach(function(cb){ cb.checked = checked; });
}
</script>
<?php endif; ?>

<p style="color:#6c757d;font-size:14px;">
    Assign each learner a personal date/time window for this assessment. Learners with a
    personal schedule will see a live countdown to their opening and closing times — both
    when they open the assessment and while they are attempting it. Learners without a
    personal schedule follow the assessment's default <strong>Open</strong> /
    <strong>Close</strong> dates set in its settings.
</p>

<!-- ══ Section A — Select learners & assign window ═══════════════════════ -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <h4 style="margin:0 0 14px;">Assign a Window to Selected Learners</h4>

    <form method="post" action="">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="assign">

        <div style="max-height:340px;overflow-y:auto;border:1px solid #e9ecef;border-radius:6px;margin-bottom:14px;">
            <table class="table table-hover" style="width:100%;border-collapse:collapse;font-size:13px;margin:0;">
                <thead style="position:sticky;top:0;background:#f8f9fa;">
                    <tr style="border-bottom:2px solid #dee2e6;">
                        <th style="padding:8px 12px;width:36px;">
                            <input type="checkbox" id="lg-sched-selectall"
                                onclick="document.querySelectorAll('.lg-sched-cb').forEach(function(c){c.checked=document.getElementById('lg-sched-selectall').checked;})">
                        </th>
                        <th style="padding:8px 12px;">Name</th>
                        <th style="padding:8px 12px;">Username</th>
                        <th style="padding:8px 12px;">Email</th>
                        <th style="padding:8px 12px;">Current Schedule</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($enrolled)): ?>
                    <tr><td colspan="5" style="padding:14px;text-align:center;color:#6c757d;">No enrolled learners found.</td></tr>
                <?php else: foreach ($enrolled as $u): ?>
                    <tr style="border-bottom:1px solid #f0f0f0;">
                        <td style="padding:8px 12px;">
                            <input type="checkbox" class="lg-sched-cb" name="userids[]" value="<?php echo $u->id; ?>">
                        </td>
                        <td style="padding:8px 12px;"><?php echo htmlspecialchars(fullname($u)); ?></td>
                        <td style="padding:8px 12px;"><?php echo htmlspecialchars($u->username); ?></td>
                        <td style="padding:8px 12px;"><?php echo htmlspecialchars($u->email); ?></td>
                        <td style="padding:8px 12px;">
                            <?php if (isset($schedulesByUser[$u->id])):
                                $s = $schedulesByUser[$u->id]; ?>
                                <span style="font-size:12px;color:#495057;">
                                    <?php echo $s->timeopen  ? '🕐 ' . userdate($s->timeopen, '%d %b %Y, %H:%M')  : '—'; ?>
                                    →
                                    <?php echo $s->timeclose ? '🔒 ' . userdate($s->timeclose, '%d %b %Y, %H:%M') : '—'; ?>
                                </span>
                            <?php else: ?>
                                <span style="font-size:12px;color:#adb5bd;">Default (no personal schedule)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>

        <div style="display:flex;gap:20px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;">
            <div>
                <label style="font-weight:700;display:block;margin-bottom:4px;">Opens</label>
                <div style="display:flex;gap:6px;">
                    <input type="date" name="datestart" class="form-control">
                    <input type="time" name="timestart" class="form-control" value="00:00">
                </div>
            </div>
            <div>
                <label style="font-weight:700;display:block;margin-bottom:4px;">Closes</label>
                <div style="display:flex;gap:6px;">
                    <input type="date" name="dateend" class="form-control">
                    <input type="time" name="timeend" class="form-control" value="23:59">
                </div>
            </div>
        </div>
        <p style="font-size:12px;color:#6c757d;margin:0 0 14px;">
            Leave "Opens" blank for no opening restriction, or "Closes" blank for no closing
            deadline. At least one must be set.
        </p>

        <button type="submit" class="btn btn-primary">📅 Apply Schedule to Selected Learners</button>
    </form>
</div>

<!-- ══ Section B — CSV upload ═════════════════════════════════════════════ -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <h4 style="margin:0 0 14px;">Bulk Import via CSV</h4>

    <form method="post" action="" enctype="multipart/form-data">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="importcsv">

        <div class="mb-3">
            <label style="font-weight:700;display:block;margin-bottom:4px;">CSV File</label>
            <input type="file" name="importfile" class="form-control" accept=".csv" style="max-width:400px;">
        </div>

        <button type="submit" class="btn btn-primary">📥 Import Schedules</button>
        <a href="<?php echo new moodle_url('/mod/learnguard/schedule.php', ['id' => $id, 'action' => 'template']); ?>"
           class="btn btn-outline-secondary" style="margin-left:8px;">⬇ Download CSV Template</a>
    </form>

    <div style="background:#f8f9fa;border-radius:8px;padding:16px;margin-top:16px;">
        <p style="font-size:13px;margin:0 0 8px;"><strong>Required columns:</strong>
            <code>name, username, email, datestart, dateend, timestart, timeend</code></p>
        <ul style="font-size:13px;color:#6c757d;margin:0;padding-left:20px;">
            <li><code>name</code> is informational only.</li>
            <li>Learners are matched by <code>username</code> first, then by <code>email</code>.</li>
            <li>Dates use <code>YYYY-MM-DD</code>, times use 24-hour <code>HH:MM</code>.</li>
            <li><code>dateend</code> / <code>timeend</code> may be left blank for no closing deadline.</li>
        </ul>
        <pre style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:12px;font-size:12px;overflow-x:auto;margin:10px 0 0;">name,username,email,datestart,dateend,timestart,timeend
Jane Doe,jdoe,jdoe@example.com,2025-06-20,2025-06-20,09:00,11:00</pre>
    </div>
</div>

<!-- ══ Section C — Current schedules ══════════════════════════════════════ -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <h4 style="margin:0 0 14px;">Current Schedules (<?php echo count($schedules); ?>)</h4>

    <?php if (empty($schedules)): ?>
        <div style="background:#f0f4ff;border:1px solid #b8d0f9;border-radius:8px;padding:16px;color:#0f6cbf;">
            ℹ️ No personal schedules assigned yet. All learners follow the assessment's default availability.
        </div>
    <?php else: ?>
        <table class="table table-hover" style="width:100%;border-collapse:collapse;font-size:14px;">
            <thead>
                <tr style="background:#f8f9fa;border-bottom:2px solid #dee2e6;">
                    <th style="padding:10px 14px;">Learner</th>
                    <th style="padding:10px 14px;">Opens</th>
                    <th style="padding:10px 14px;">Closes</th>
                    <th style="padding:10px 14px;text-align:center;">Status</th>
                    <th style="padding:10px 14px;text-align:center;width:100px;">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($schedules as $s):
                $u = $enrolled[$s->userid] ?? $DB->get_record('user', ['id' => $s->userid]);
                if (!$u) { continue; }

                if ($s->timeopen && $now < $s->timeopen) {
                    $status = ['⏳ Upcoming', '#fff3cd', '#856404'];
                } else if ($s->timeclose && $now > $s->timeclose) {
                    $status = ['🔒 Closed', '#f8d7da', '#721c24'];
                } else {
                    $status = ['🟢 Open', '#d4edda', '#155724'];
                }
            ?>
                <tr style="border-bottom:1px solid #f0f0f0;">
                    <td style="padding:10px 14px;">
                        <strong><?php echo htmlspecialchars(fullname($u)); ?></strong>
                        <br><small style="color:#6c757d;"><?php echo htmlspecialchars($u->email ?? ''); ?></small>
                    </td>
                    <td style="padding:10px 14px;"><?php echo $s->timeopen  ? userdate($s->timeopen, '%d %b %Y, %H:%M')  : '— No restriction'; ?></td>
                    <td style="padding:10px 14px;"><?php echo $s->timeclose ? userdate($s->timeclose, '%d %b %Y, %H:%M') : '— No deadline'; ?></td>
                    <td style="padding:10px 14px;text-align:center;">
                        <span style="background:<?php echo $status[1]; ?>;color:<?php echo $status[2]; ?>;
                            border-radius:20px;padding:3px 12px;font-size:12px;font-weight:700;">
                            <?php echo $status[0]; ?>
                        </span>
                    </td>
                    <td style="padding:10px 14px;text-align:center;">
                        <form method="post" action="" style="display:inline;">
                            <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="scheduleid" value="<?php echo $s->id; ?>">
                            <button type="submit" class="btn btn-outline-danger btn-sm"
                                onclick="return confirm('Remove this learner\'s personal schedule?')">Remove</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

</div>
<?php echo $OUTPUT->footer();
