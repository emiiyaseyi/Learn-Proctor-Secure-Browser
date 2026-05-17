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
 * Print-to-PDF integrity report — Learn Shield.
 *
 * Opens a printable browser window. Users print to PDF from the browser.
 * Supports: individual (userid param) or bulk (all students for a quiz).
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/quiz/locallib.php');

use quizaccess_learnshield\suspicion_scorer;
use quizaccess_learnshield\event_tracker;

$courseid = required_param('courseid', PARAM_INT);
$cmid     = required_param('cmid',     PARAM_INT);
$userid   = optional_param('userid',   0, PARAM_INT); // 0 = all students

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
$cm     = get_coursemodule_from_id('quiz', $cmid, $courseid, false, MUST_EXIST);
$quiz   = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

require_login($course, false, $cm);
$context = context_module::instance($cm->id);
require_capability('quizaccess/learnshield:viewreport', $context);

// Fetch students to report on.
if ($userid) {
    $users = [$DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,email', MUST_EXIST)];
} else {
    $users = $DB->get_records_sql(
        "SELECT DISTINCT u.id, u.firstname, u.lastname, u.email
           FROM {quizaccess_learnshield_logs} l
           INNER JOIN {user} u ON u.id = l.userid
          WHERE l.courseid = :cid AND l.quizid = :qid
          ORDER BY u.lastname ASC, u.firstname ASC",
        ['cid' => $courseid, 'qid' => $cmid]
    );
}

// ---- Output printable HTML ----
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Learn Shield Integrity Report — <?php echo s($quiz->name); ?></title>
<style>
    * { box-sizing: border-box; }
    body { font-family: Arial, sans-serif; font-size: 12px; color: #000; margin: 0; padding: 20px; }
    h1 { font-size: 18px; margin-bottom: 4px; }
    h2 { font-size: 14px; margin: 16px 0 6px; border-bottom: 1px solid #ccc; padding-bottom: 4px; }
    h3 { font-size: 13px; margin: 12px 0 4px; }
    .header { border-bottom: 2px solid #000; padding-bottom: 10px; margin-bottom: 16px; }
    .meta { color: #555; font-size: 11px; margin-top: 4px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 12px; }
    th, td { border: 1px solid #ccc; padding: 5px 8px; text-align: left; font-size: 11px; }
    th { background: #f0f0f0; font-weight: bold; }
    .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; color: #fff; font-size: 10px; }
    .badge-low    { background: #28a745; }
    .badge-medium { background: #fd7e14; }
    .badge-high   { background: #dc3545; }
    .student-block { page-break-inside: avoid; margin-bottom: 24px; border: 1px solid #ddd;
                      border-radius: 4px; padding: 12px; }
    .score-bar-wrap { background: #eee; border-radius: 4px; height: 8px; margin: 4px 0; }
    .score-bar { height: 100%; border-radius: 4px; }
    .score-low    { background: #28a745; }
    .score-medium { background: #fd7e14; }
    .score-high   { background: #dc3545; }
    .snapshots { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
    .snapshot-item { text-align: center; }
    .snapshot-item img { width: 100px; height: 75px; object-fit: cover; border: 1px solid #ccc; }
    .snapshot-item small { display: block; font-size: 9px; color: #666; }
    .event-row-warning td { background: #fff9e6; }
    .event-row-danger  td { background: #fff0f0; }
    @media print {
        .no-print { display: none; }
        .student-block { page-break-inside: avoid; }
        .new-page { page-break-before: always; }
    }
</style>
</head>
<body>

<div class="no-print" style="background:#f0f4ff;padding:10px;margin-bottom:16px;border-radius:4px;">
    <strong>📄 Print this page to save as PDF.</strong>
    Use your browser's Print function (Ctrl+P / Cmd+P) and select "Save as PDF".
    <button onclick="window.print()" style="margin-left:12px;padding:4px 12px;">🖨 Print / Save PDF</button>
</div>

<div class="header">
    <h1>Learn Shield — Integrity Report</h1>
    <div class="meta">
        Course: <strong><?php echo s($course->fullname); ?></strong> &nbsp;|&nbsp;
        Quiz: <strong><?php echo s($quiz->name); ?></strong> &nbsp;|&nbsp;
        Generated: <strong><?php echo date('d M Y H:i'); ?></strong> &nbsp;|&nbsp;
        Generated by: <strong><?php echo s(fullname($USER)); ?></strong>
    </div>
    <div class="meta">
        Total students in report: <strong><?php echo count($users); ?></strong>
    </div>
</div>

<?php
$first = true;
foreach ($users as $user) {
    $session = $DB->get_record('quizaccess_learnshield_sessions',
        ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $user->id]);

    $logs = $DB->get_records('quizaccess_learnshield_logs',
        ['courseid' => $courseid, 'quizid' => $cmid, 'userid' => $user->id],
        'timemodified ASC');

    $events = $session ? event_tracker::get_session_events($session->id) : [];
    $snapshotlogs = array_filter((array)$logs, fn($l) => !empty($l->webcampicture));

    $riskscore = $session ? $session->risk_score : 0;
    $risklevel = $session ? $session->risk_level : 'low';
    $riskpct   = min($riskscore, 100);
    ?>

<div class="student-block <?php echo $first ? '' : 'new-page'; ?>">
    <h2><?php echo s(fullname($user)); ?> &lt;<?php echo s($user->email); ?>&gt;</h2>

    <table>
        <tr>
            <th style="width:140px;">Integrity Risk Score</th>
            <td>
                <span class="badge badge-<?php echo $risklevel; ?>"><?php echo $riskscore; ?> pts — <?php echo ucfirst($risklevel); ?> Risk</span>
                <div class="score-bar-wrap"><div class="score-bar score-<?php echo $risklevel; ?>" style="width:<?php echo $riskpct; ?>%"></div></div>
            </td>
            <th style="width:120px;">Face Match</th>
            <td>
                <?php
                $fmresult = '—';
                foreach ($logs as $lr) {
                    if ($lr->awsflag == 2) {
                        $fmresult = $lr->awsscore >= 68 ? '✓ Passed (' . $lr->awsscore . '%)' : '✗ Failed (' . $lr->awsscore . '%)';
                        break;
                    }
                }
                echo $fmresult;
                ?>
            </td>
        </tr>
        <tr>
            <th>Tab Switches</th><td><?php echo $session ? $session->tab_switches : 0; ?></td>
            <th>Fullscreen Exits</th><td><?php echo $session ? $session->fullscreen_exits : 0; ?></td>
        </tr>
        <tr>
            <th>Face Missing Events</th><td><?php echo $session ? $session->face_missing_count : 0; ?></td>
            <th>Keyboard Violations</th><td><?php echo $session ? $session->keyboard_violations : 0; ?></td>
        </tr>
        <tr>
            <th>Clipboard Events</th><td><?php echo $session ? $session->clipboard_events : 0; ?></td>
            <th>Total Snapshots</th><td><?php echo count($snapshotlogs); ?></td>
        </tr>
        <?php if ($session): ?>
        <tr>
            <th>Session Started</th><td><?php echo userdate($session->start_time); ?></td>
            <th>IP Address</th><td><?php echo s($session->ip_address); ?></td>
        </tr>
        <tr>
            <th>Browser</th><td colspan="3"><?php echo s(substr($session->browser_info, 0, 100)); ?></td>
        </tr>
        <?php endif; ?>
    </table>

    <?php if (!empty($snapshotlogs)): ?>
    <h3>Webcam Snapshots (<?php echo count($snapshotlogs); ?>)</h3>
    <div class="snapshots">
        <?php foreach (array_slice($snapshotlogs, 0, 20) as $snap): ?>
        <div class="snapshot-item">
            <img src="<?php echo s($snap->webcampicture); ?>" alt="Snapshot" onerror="this.style.display='none'">
            <small><?php echo date('H:i:s', $snap->timemodified); ?></small>
        </div>
        <?php endforeach; ?>
        <?php if (count($snapshotlogs) > 20): ?>
        <p><em><?php echo count($snapshotlogs) - 20; ?> more snapshots not shown (see online report).</em></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($events)): ?>
    <h3>Event Log (<?php echo count($events); ?> events)</h3>
    <table>
        <tr class="thead"><th>Time</th><th>Event Type</th><th>Severity</th><th>Score Impact</th></tr>
        <?php
        $evcount = 0;
        foreach ($events as $ev):
            if ($evcount++ > 50) { echo '<tr><td colspan="4"><em>... ' . (count($events) - 50) . ' more events (see online audit log)</em></td></tr>'; break; }
            $rowclass = match($ev->severity) { 'danger' => 'event-row-danger', 'warning' => 'event-row-warning', default => '' };
        ?>
        <tr class="<?php echo $rowclass; ?>">
            <td><?php echo date('H:i:s', $ev->timemodified); ?></td>
            <td><?php echo s(str_replace('_', ' ', $ev->event_type)); ?></td>
            <td><?php echo ucfirst($ev->severity); ?></td>
            <td><?php echo $ev->score_impact > 0 ? '+' . $ev->score_impact : '—'; ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php endif; ?>
</div>
    <?php
    $first = false;
}
?>

</body>
</html>
<?php
exit;
