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
 * Per-learner exam scheduling for quizaccess_learnshield.
 *
 * Lets a teacher/manager assign each learner (individually, via checkboxes,
 * or in bulk via CSV upload) a personal open/close window for this quiz.
 * This is implemented entirely on top of Moodle's native {quiz_overrides}
 * table — the same table used by the core "User overrides" screen — so the
 * quiz's own timer, autosubmission and the "time remaining" display all
 * pick up the personal window automatically. A countdown is additionally
 * shown to the learner (rule.php::description() and proctoring.js) using
 * quizaccess_learnshield_effective_window().
 *
 * URL: /mod/quiz/accessrule/learnshield/schedule.php?courseid={courseid}&cmid={cmid}
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

$courseid = required_param('courseid', PARAM_INT);
$cmid     = required_param('cmid',     PARAM_INT);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$cm     = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
$quiz   = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/quiz:manageoverrides', $context);

// ── Download CSV template (GET, no side-effects) ────────────────────────────
$action = optional_param('action', '', PARAM_ALPHA);
if ($action === 'template') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="learnshield_schedule_template.csv"');
    echo "\xEF\xBB\xBF";
    echo "name,username,email,datestart,dateend,timestart,timeend\n";
    echo "Jane Doe,jdoe,jdoe@example.com,2025-06-20,2025-06-20,09:00,11:00\n";
    exit;
}

$PAGE->set_url(new moodle_url('/mod/quiz/accessrule/learnshield/schedule.php', [
    'courseid' => $courseid, 'cmid' => $cmid,
]));
$PAGE->set_context($context);
$PAGE->set_title('Schedule — ' . format_string($quiz->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/quiz/accessrule/learnshield/styles.css');

/**
 * Convert a "YYYY-MM-DD" date string and an optional "HH:MM" time string
 * into a unix timestamp using the current user's timezone (matches the
 * behaviour of Moodle's own date_time_selector form element).
 */
function quizaccess_learnshield_schedule_parse_dt(string $date, string $time): int {
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
 * Upsert a {quiz_overrides} row for one user and refresh any in-progress
 * attempts so they pick up the new deadline immediately.
 */
function quizaccess_learnshield_schedule_save_override(int $quizid, int $userid, int $timeopen, int $timeclose): void {
    global $DB;

    $existing = $DB->get_record('quiz_overrides', ['quiz' => $quizid, 'userid' => $userid]);
    if ($existing) {
        $DB->update_record('quiz_overrides', (object)[
            'id'        => $existing->id,
            'timeopen'  => $timeopen,
            'timeclose' => $timeclose,
        ]);
    } else {
        $DB->insert_record('quiz_overrides', (object)[
            'quiz'      => $quizid,
            'userid'    => $userid,
            'timeopen'  => $timeopen,
            'timeclose' => $timeclose,
        ]);
    }

    quiz_update_open_attempts(['quizid' => $quizid, 'userid' => $userid]);
}

/**
 * Import a CSV of per-learner schedules.
 *
 * Columns: name,username,email,datestart,dateend,timestart,timeend
 * - "name" is informational only (not used for matching).
 * - Learners are matched by username first, falling back to email.
 * - Only learners enrolled with mod/quiz:attempt in this context match.
 *
 * @return array{0:int,1:array<int,string>} [imported count, error lines]
 */
function quizaccess_learnshield_schedule_import_csv(string $content, int $quizid, \context $context): array {
    $lines  = preg_split('/\r\n|\r|\n/', trim($content));
    $header = array_map('strtolower', array_map('trim', str_getcsv((string)array_shift($lines))));

    if (!in_array('username', $header, true) && !in_array('email', $header, true)) {
        return [0, ['CSV must contain a "username" and/or "email" column.']];
    }

    $enrolled = get_enrolled_users($context, 'mod/quiz:attempt');
    $byUsername = []; $byEmail = [];
    foreach ($enrolled as $u) {
        $byUsername[strtolower($u->username)] = $u;
        $byEmail[strtolower($u->email)]       = $u;
    }

    $imported = 0; $errors = [];

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

        $timeopen  = quizaccess_learnshield_schedule_parse_dt($row['datestart'] ?? '', $row['timestart'] ?? '');
        $timeclose = quizaccess_learnshield_schedule_parse_dt($row['dateend']   ?? '', $row['timeend']   ?? '');
        if (!$timeopen && !$timeclose) {
            $errors[] = 'Row ' . ($ln + 2) . ': no valid date/time provided for ' . s($user->username) . '.';
            continue;
        }

        quizaccess_learnshield_schedule_save_override($quizid, (int)$user->id, $timeopen, $timeclose);
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

        $timeopen  = quizaccess_learnshield_schedule_parse_dt($datestart, $timestart);
        $timeclose = quizaccess_learnshield_schedule_parse_dt($dateend,   $timeend);

        if (empty($userids)) {
            $errors[] = 'No learners were selected.';
        } else if (!$timeopen && !$timeclose) {
            $errors[] = 'Please provide an opening date/time, a closing date/time, or both.';
        } else {
            $count = 0;
            foreach ($userids as $uid) {
                $uid = (int)$uid;
                if (!$uid) { continue; }
                quizaccess_learnshield_schedule_save_override($quiz->id, $uid, $timeopen, $timeclose);
                $count++;
            }
            $messages[] = $count . ' learner schedule(s) saved.';
        }
    } else if ($action === 'remove') {
        $overrideid = required_param('overrideid', PARAM_INT);
        $override = $DB->get_record('quiz_overrides', ['id' => $overrideid, 'quiz' => $quiz->id]);
        if ($override && !empty($override->userid)) {
            $DB->delete_records('quiz_overrides', ['id' => $overrideid]);
            quiz_update_open_attempts(['quizid' => $quiz->id, 'userid' => $override->userid]);
            $messages[] = 'Schedule removed — this learner now follows the quiz\'s default availability.';
        }
    } else if ($action === 'importcsv') {
        if (!empty($_FILES['importfile']['tmp_name']) && is_uploaded_file($_FILES['importfile']['tmp_name'])) {
            $content = file_get_contents($_FILES['importfile']['tmp_name']);
            [$imported, $csverrors] = quizaccess_learnshield_schedule_import_csv($content, $quiz->id, $context);
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
$enrolled = get_enrolled_users($context, 'mod/quiz:attempt', 0, 'u.*', 'lastname, firstname');

$overrides = $DB->get_records_select('quiz_overrides', 'quiz = :quiz AND userid IS NOT NULL', ['quiz' => $quiz->id]);
$overridesByUser = [];
foreach ($overrides as $o) {
    $overridesByUser[$o->userid] = $o;
}

// ── Output ────────────────────────────────────────────────────────────────────
echo $OUTPUT->header();
require_once(__DIR__ . '/navhelper.php');
ls_nav_bar($courseid, $cmid, 'schedule');

foreach ($messages as $m) {
    echo html_writer::div('✅ ' . s($m), 'alert alert-success');
}
if ($errors) {
    echo html_writer::div('⚠️ ' . implode('<br>⚠️ ', array_map('s', $errors)), 'alert alert-danger');
}

$now = time();
?>
<div style="max-width:1000px;margin:0 auto;font-family:system-ui,sans-serif;">

<div style="display:flex;align-items:center;gap:14px;margin-bottom:24px;">
    <div style="font-size:40px;">📅</div>
    <div>
        <h2 style="margin:0;font-size:22px;">Per-Learner Scheduling</h2>
        <p style="margin:4px 0 0;color:#6c757d;font-size:14px;"><?php echo format_string($quiz->name); ?></p>
    </div>
</div>

<p style="color:#6c757d;font-size:14px;">
    Assign each learner a personal date/time window for this quiz. This uses Moodle's own
    "user override" mechanism, so the quiz's normal timer and auto-submission respect each
    learner's window. Learners with a personal schedule will also see a live countdown to
    their opening and closing times — both when they open the quiz and while attempting it.
    Learners without a personal schedule follow the quiz's default <strong>Open</strong> /
    <strong>Close</strong> dates set in its settings.
</p>

<!-- ══ Section A — Select learners & assign window ═══════════════════════ -->
<div style="background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:20px;margin-bottom:20px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <h4 style="margin:0 0 14px;">Assign a Window to Selected Learners</h4>

    <form method="post" action="">
        <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
        <input type="hidden" name="action" value="assign">
        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
        <input type="hidden" name="cmid" value="<?php echo $cmid; ?>">

        <div style="max-height:340px;overflow-y:auto;border:1px solid #e9ecef;border-radius:6px;margin-bottom:14px;">
            <table class="table table-hover" style="width:100%;border-collapse:collapse;font-size:13px;margin:0;">
                <thead style="position:sticky;top:0;background:#f8f9fa;">
                    <tr style="border-bottom:2px solid #dee2e6;">
                        <th style="padding:8px 12px;width:36px;">
                            <input type="checkbox" id="ls-sched-selectall"
                                onclick="document.querySelectorAll('.ls-sched-cb').forEach(function(c){c.checked=document.getElementById('ls-sched-selectall').checked;})">
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
                            <input type="checkbox" class="ls-sched-cb" name="userids[]" value="<?php echo $u->id; ?>">
                        </td>
                        <td style="padding:8px 12px;"><?php echo htmlspecialchars(fullname($u)); ?></td>
                        <td style="padding:8px 12px;"><?php echo htmlspecialchars($u->username); ?></td>
                        <td style="padding:8px 12px;"><?php echo htmlspecialchars($u->email); ?></td>
                        <td style="padding:8px 12px;">
                            <?php if (isset($overridesByUser[$u->id])):
                                $o = $overridesByUser[$u->id]; ?>
                                <span style="font-size:12px;color:#495057;">
                                    <?php echo $o->timeopen  ? '🕐 ' . userdate($o->timeopen, '%d %b %Y, %H:%M')  : '—'; ?>
                                    →
                                    <?php echo $o->timeclose ? '🔒 ' . userdate($o->timeclose, '%d %b %Y, %H:%M') : '—'; ?>
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
        <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
        <input type="hidden" name="cmid" value="<?php echo $cmid; ?>">

        <div class="mb-3">
            <label style="font-weight:700;display:block;margin-bottom:4px;">CSV File</label>
            <input type="file" name="importfile" class="form-control" accept=".csv" style="max-width:400px;">
        </div>

        <button type="submit" class="btn btn-primary">📥 Import Schedules</button>
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/schedule.php', ['courseid' => $courseid, 'cmid' => $cmid, 'action' => 'template']); ?>"
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
    <h4 style="margin:0 0 14px;">Current Schedules (<?php echo count($overrides); ?>)</h4>

    <?php if (empty($overrides)): ?>
        <div style="background:#f0f4ff;border:1px solid #b8d0f9;border-radius:8px;padding:16px;color:#0f6cbf;">
            ℹ️ No personal schedules assigned yet. All learners follow the quiz's default availability.
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
            <?php foreach ($overrides as $o):
                $u = $enrolled[$o->userid] ?? $DB->get_record('user', ['id' => $o->userid]);
                if (!$u) { continue; }

                if ($o->timeopen && $now < $o->timeopen) {
                    $status = ['⏳ Upcoming', '#fff3cd', '#856404'];
                } else if ($o->timeclose && $now > $o->timeclose) {
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
                    <td style="padding:10px 14px;"><?php echo $o->timeopen  ? userdate($o->timeopen, '%d %b %Y, %H:%M')  : '— No restriction'; ?></td>
                    <td style="padding:10px 14px;"><?php echo $o->timeclose ? userdate($o->timeclose, '%d %b %Y, %H:%M') : '— No deadline'; ?></td>
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
                            <input type="hidden" name="courseid" value="<?php echo $courseid; ?>">
                            <input type="hidden" name="cmid" value="<?php echo $cmid; ?>">
                            <input type="hidden" name="overrideid" value="<?php echo $o->id; ?>">
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
