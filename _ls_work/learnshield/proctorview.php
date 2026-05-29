<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnShield Proctor Backend — dedicated interface for human invigilators.
 *
 * Accessible to users with quizaccess/learnshield:liveproctor capability.
 * Shows quizzes the current user is assigned to proctor.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

require_login();

$context = context_system::instance();
$PAGE->set_context($context);

$isAdmin = has_capability('moodle/site:config', $context);
$isProctor = $isAdmin || has_capability('quizaccess/learnshield:liveproctor', $context);

if (!$isProctor) {
    throw new moodle_exception('accessdenied', 'error', '', 'You are not assigned as a proctor for any LearnShield quiz.');
}

$tab = optional_param('tab', 'live', PARAM_ALPHA);
$PAGE->set_url('/mod/quiz/accessrule/learnshield/proctorview.php', ['tab' => $tab]);
$PAGE->set_title('LearnShield Proctor View');
$PAGE->set_heading('LearnShield — Invigilator Dashboard');

// Get all quizzes with LearnShield enabled that this proctor can see.
if ($isAdmin) {
    $myQuizzes = $DB->get_records_sql(
        "SELECT DISTINCT q.id, q.name, c.fullname AS coursename, c.id AS courseid,
                cm.id AS cmid, q.timeopen, q.timeclose, q.timelimit
           FROM {quiz} q
           JOIN {course} c ON c.id = q.course
           JOIN {modules} md ON md.name = 'quiz'
           JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = md.id
           JOIN {quizaccess_learnshield_sessions} s ON s.quizid = cm.id
          GROUP BY q.id, q.name, c.fullname, c.id, cm.id, q.timeopen, q.timeclose, q.timelimit
         ORDER BY c.fullname, q.name"
    );
} else {
    // Non-admin proctors see quizzes where they have the liveproctor capability at course level.
    $myQuizzes = $DB->get_records_sql(
        "SELECT DISTINCT q.id, q.name, c.fullname AS coursename, c.id AS courseid,
                cm.id AS cmid, q.timeopen, q.timeclose, q.timelimit
           FROM {quiz} q
           JOIN {course} c ON c.id = q.course
           JOIN {modules} md ON md.name = 'quiz'
           JOIN {course_modules} cm ON cm.instance = q.id AND cm.module = md.id
           JOIN {context} ctx ON ctx.instanceid = c.id AND ctx.contextlevel = 50
           JOIN {role_assignments} ra ON ra.contextid = ctx.id AND ra.userid = :uid
           JOIN {role_capabilities} rc ON rc.roleid = ra.roleid
                AND rc.capability = 'quizaccess/learnshield:liveproctor' AND rc.permission = 1
         ORDER BY c.fullname, q.name",
        ['uid' => $USER->id]
    );
}

$myCmIds = array_column((array)$myQuizzes, 'cmid', 'id');

// Summary counts.
$liveCount = $highRiskCount = $suspendedCount = $pendingFaceCount = $precheckCount = 0;
if (!empty($myCmIds)) {
    list($insql, $inp) = $DB->get_in_or_equal(array_values($myCmIds), SQL_PARAMS_NAMED);
    $liveCount      = (int)$DB->count_records_select('quizaccess_learnshield_sessions', "quizid $insql AND status='active'", $inp);
    $highRiskCount  = (int)$DB->count_records_select('quizaccess_learnshield_sessions', "quizid $insql AND risk_level='high' AND status='active'", $inp);
    $suspendedCount = (int)$DB->count_records_select('quizaccess_learnshield_sessions', "quizid $insql AND status='suspended'", $inp);
    try {
        $pendingFaceCount = (int)$DB->count_records_select('quizaccess_learnshield_face_approvals', "cmid $insql AND status='pending'", $inp);
    } catch (\Exception $e) {}
    try {
        $startOfDay  = mktime(0, 0, 0);
        $precheckCount = (int)$DB->count_records_select('quizaccess_learnshield_precheck_sessions',
            "cmid $insql AND timecreated > :sod", array_merge($inp, ['sod' => $startOfDay]));
    } catch (\Exception $e) {}
}

echo $OUTPUT->header();
?>

<style>
:root { --pv-primary:#0f6cbf; }
.pv-wrap        { font-family:"Segoe UI",system-ui,sans-serif; }
.pv-header      { background:var(--pv-primary); color:#fff; padding:0 24px; height:56px; display:flex; align-items:center; justify-content:space-between; box-shadow:0 2px 12px rgba(0,0,0,.2); position:sticky; top:0; z-index:100; }
.pv-header-brand{ font-size:18px; font-weight:800; display:flex; align-items:center; gap:10px; }
.pv-stats       { display:flex; gap:10px; padding:16px 24px; background:#f8f9fa; border-bottom:1px solid #dee2e6; flex-wrap:wrap; }
.pv-stat        { display:flex; align-items:center; gap:8px; background:#fff; border-radius:10px; padding:10px 16px; box-shadow:0 1px 4px rgba(0,0,0,.07); min-width:120px; }
.pv-stat-num    { font-size:22px; font-weight:800; line-height:1; }
.pv-stat-lbl    { font-size:11px; color:#6c757d; font-weight:600; }
.pv-tabs        { display:flex; background:#fff; border-bottom:2px solid #dee2e6; overflow-x:auto; scrollbar-width:none; }
.pv-tabs::-webkit-scrollbar { display:none; }
.pv-tab         { display:flex; align-items:center; gap:6px; padding:0 20px; height:46px; font-size:13px; font-weight:600; color:#6c757d; text-decoration:none; border-bottom:3px solid transparent; white-space:nowrap; flex-shrink:0; transition:all .15s; }
.pv-tab:hover   { color:#212529; background:#f8f9fa; }
.pv-tab.active  { color:var(--pv-primary); border-bottom-color:var(--pv-primary); font-weight:700; }
.pv-badge       { background:#dc3545; color:#fff; border-radius:10px; padding:1px 6px; font-size:10px; font-weight:800; }
.pv-badge-green { background:#28a745; }
.pv-content     { padding:20px 24px; }
.pv-asmt-grid   { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; }
.pv-asmt-card   { background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.08); padding:18px; border-left:4px solid var(--pv-primary); }
.pv-asmt-card h4{ margin:0 0 6px; font-size:15px; }
.pv-filter-btn  { padding:4px 12px; border-radius:14px; font-size:12px; font-weight:600; cursor:pointer; border:2px solid transparent; transition:.15s; }
.pv-filter-btn.active { border-color:var(--pv-primary); color:var(--pv-primary); }
.pv-filter-btn:not(.active) { background:#f8f9fa; color:#6c757d; }
.pv-grid-btn    { width:32px; height:28px; border:1px solid #dee2e6; border-radius:4px; cursor:pointer; font-size:11px; font-weight:700; background:#fff; color:#495057; transition:.15s; }
.pv-grid-btn.active { background:var(--pv-primary); color:#fff; border-color:var(--pv-primary); }
@keyframes ls-pulse { 0%,100%{opacity:1}50%{opacity:.3} }
</style>

<div class="pv-wrap">

<div class="pv-header">
  <div class="pv-header-brand">🛡 LearnShield — Invigilator</div>
  <div style="font-size:12px;opacity:.85;">
    <?php echo fullname($USER); ?> &nbsp;·&nbsp;
    <?php echo count($myQuizzes); ?> quiz<?php echo count($myQuizzes) !== 1 ? 'zes' : ''; ?> assigned
    &nbsp;·&nbsp; <?php echo userdate(time(), '%H:%M'); ?>
    <?php if ($isAdmin): ?>
    &nbsp;·&nbsp; <span style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:10px;">Global Access</span>
    &nbsp;&nbsp;<a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/admin.php'); ?>" style="color:rgba(255,255,255,.8);font-size:12px;text-decoration:none;">⚙️ Admin</a>
    <?php endif; ?>
  </div>
</div>

<div class="pv-stats">
  <div class="pv-stat"><div><div class="pv-stat-num" style="color:var(--pv-primary);"><?php echo count($myQuizzes); ?></div><div class="pv-stat-lbl">My Quizzes</div></div></div>
  <div class="pv-stat"><div><div class="pv-stat-num" style="color:#28a745;"><?php echo $liveCount; ?></div><div class="pv-stat-lbl">🟢 Live Now</div></div></div>
  <div class="pv-stat"><div><div class="pv-stat-num" style="color:#dc3545;"><?php echo $highRiskCount; ?></div><div class="pv-stat-lbl">⚠️ High Risk</div></div></div>
  <div class="pv-stat"><div><div class="pv-stat-num" style="color:#fd7e14;"><?php echo $suspendedCount; ?></div><div class="pv-stat-lbl">🚫 Suspended</div></div></div>
  <div class="pv-stat"><div><div class="pv-stat-num" style="color:#6f42c1;"><?php echo $pendingFaceCount; ?></div><div class="pv-stat-lbl">👤 Face Approvals</div></div></div>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
    <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/face_approvals.php'); ?>"
       class="pv-filter-btn<?php echo $pendingFaceCount > 0 ? '' : ' active'; ?>"
       style="<?php echo $pendingFaceCount > 0 ? 'background:#fd7e14;color:#fff;border-color:#fd7e14;' : ''; ?>">
      👤 Face Approvals<?php echo $pendingFaceCount > 0 ? ' (' . $pendingFaceCount . ')' : ''; ?>
    </a>
    <button onclick="location.reload()" class="pv-filter-btn">🔄 Refresh</button>
  </div>
</div>

<div class="pv-tabs">
<?php
$pvTabs = [
    'live'        => ['🔴 Live Monitor',   null,                 true],
    'quizzes'     => ['📋 My Quizzes',     count($myQuizzes),    true],
    'highrisk'    => ['⚠️ High Risk',      $highRiskCount ?: null, true],
    'suspended'   => ['🚫 Suspended',      $suspendedCount ?: null, true],
    'precheck'    => ['🔍 Precheck',       $precheckCount  ?: null, true],
    'faceapprove' => ['👤 Face Approvals', $pendingFaceCount ?: null, true],
    'gallery'     => ['🖼 Gallery',         null,                 true],
];
foreach ($pvTabs as $key => [$label, $badge, $allowed]) {
    if (!$allowed) { continue; }
    $active    = ($tab === $key);
    $badgeHtml = $badge ? '<span class="pv-badge' . ($key === 'live' ? ' pv-badge-green' : '') . '">' . (int)$badge . '</span>' : '';
    echo html_writer::link(
        new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php', ['tab' => $key]),
        $label . $badgeHtml,
        ['class' => 'pv-tab' . ($active ? ' active' : '')]
    );
}
?>
</div>

<?php
// =====================================================================
// TAB: LIVE MONITOR
// =====================================================================
if ($tab === 'live'):
?>
<!-- Course/Quiz switcher row -->
<div style="display:flex;align-items:center;gap:10px;padding:8px 24px;background:#f8f9fa;border-bottom:1px solid #dee2e6;flex-wrap:wrap;">
  <strong style="font-size:12px;color:#6c757d;">📋 Quiz:</strong>
  <select id="pv-quiz-switcher" style="border:1px solid #dee2e6;border-radius:6px;padding:4px 10px;font-size:12px;max-width:340px;"
      onchange="pvSwitchQuiz(this.value)">
    <option value="">— All assigned quizzes —</option>
    <?php foreach ($myQuizzes as $q): ?>
    <option value="<?php echo (int)$q->cmid; ?>">
      <?php echo htmlspecialchars($q->coursename . ' › ' . $q->name); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <span style="font-size:11px;color:#adb5bd;">Switch to monitor a specific quiz</span>
  <div id="pv-autorefresh-label" style="margin-left:auto;font-size:11px;color:#28a745;font-weight:600;">
    ↺ Auto-refresh: 15s
  </div>
</div>

<div style="display:flex;align-items:center;gap:10px;padding:10px 24px;background:#fff;border-bottom:1px solid #dee2e6;flex-wrap:wrap;">
  <strong style="font-size:12px;color:#6c757d;">FILTER:</strong>
  <button id="pvf-all"    class="pv-filter-btn active" onclick="pvApplyFilter('all')">All</button>
  <button id="pvf-high"   class="pv-filter-btn" onclick="pvApplyFilter('high')"   style="border-color:#dc3545;color:#dc3545;">⚠️ High Risk</button>
  <button id="pvf-medium" class="pv-filter-btn" onclick="pvApplyFilter('medium')" style="border-color:#fd7e14;color:#fd7e14;">🟠 Medium</button>
  <button id="pvf-active" class="pv-filter-btn" onclick="pvApplyFilter('active')" style="border-color:#28a745;color:#28a745;">🟢 Active</button>
  <div style="margin-left:12px;display:flex;border:1px solid #dee2e6;border-radius:6px;overflow:hidden;">
    <button id="pvv-cam"    onclick="pvApplyView('webcam')" style="padding:5px 12px;font-size:12px;font-weight:600;background:#fff;color:#6c757d;border:none;cursor:pointer;">📷 Webcam</button>
    <button id="pvv-screen" onclick="pvApplyView('screen')" style="padding:5px 12px;font-size:12px;font-weight:600;background:#0f6cbf;color:#fff;border:none;cursor:pointer;">🖥 Screen</button>
  </div>
  <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
    <span style="font-size:12px;color:#6c757d;">Per page:</span>
    <select id="pv-perpage" onchange="pvApplyPerPage(parseInt(this.value,10))" style="border:1px solid #dee2e6;border-radius:4px;padding:3px 8px;font-size:12px;">
      <option value="9">9</option><option value="15" selected>15</option>
      <option value="24">24</option><option value="36">36</option><option value="60">60</option>
    </select>
    <span style="font-size:12px;color:#6c757d;">Cols:</span>
    <div style="display:flex;gap:3px;">
      <button class="pv-grid-btn" data-cols="2" onclick="pvApplyGrid(2)">2</button>
      <button class="pv-grid-btn active" data-cols="3" onclick="pvApplyGrid(3)">3</button>
      <button class="pv-grid-btn" data-cols="4" onclick="pvApplyGrid(4)">4</button>
      <button class="pv-grid-btn" data-cols="6" onclick="pvApplyGrid(6)">6</button>
    </div>
    <span id="pv-count-badge" style="font-size:12px;color:#6c757d;min-width:80px;text-align:right;"></span>
  </div>
</div>

<div id="pv-live-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;background:#111;padding:12px;min-height:300px;">
  <div style="grid-column:1/-1;display:flex;align-items:center;justify-content:center;color:#555;flex-direction:column;gap:8px;padding:60px;">
    <div style="font-size:36px;">👁</div><p>Loading live sessions…</p>
  </div>
</div>
<div id="pv-pagination" style="display:none;padding:10px 24px;background:#fff;border-top:1px solid #dee2e6;display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;"></div>

<?php
// =====================================================================
// TAB: MY QUIZZES
// =====================================================================
elseif ($tab === 'quizzes'):
?>
<div class="pv-content">
  <h3 style="margin:0 0 16px;font-size:18px;">My LearnShield Quizzes (<?php echo count($myQuizzes); ?>)</h3>
  <?php if (empty($myQuizzes)): ?>
  <div class="alert alert-info">No quizzes with LearnShield active sessions found.</div>
  <?php else: ?>
  <div class="pv-asmt-grid">
  <?php foreach ($myQuizzes as $qid => $q):
      $liveSess = (int)$DB->count_records('quizaccess_learnshield_sessions', ['quizid' => $q->cmid, 'status' => 'active']);
      $suspSess = (int)$DB->count_records('quizaccess_learnshield_sessions', ['quizid' => $q->cmid, 'status' => 'suspended']);
      $now      = time();
      $isOpen   = (!$q->timeopen || $now >= $q->timeopen) && (!$q->timeclose || $now <= $q->timeclose);
  ?>
  <div class="pv-asmt-card">
    <h4><?php echo format_string($q->name); ?></h4>
    <div style="font-size:12px;color:#6c757d;margin-bottom:10px;">📚 <?php echo format_string($q->coursename); ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
      <span style="background:<?php echo $isOpen ? '#28a745' : '#6c757d'; ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">
        <?php echo $isOpen ? '🟢 Open' : '⭕ Closed'; ?>
      </span>
      <?php if ($liveSess > 0): ?>
      <span style="background:#dc3545;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">🔴 <?php echo $liveSess; ?> Live</span>
      <?php endif; ?>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
      <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php', ['cmid' => $q->cmid]); ?>"
         class="btn btn-sm btn-danger">🔴 Live Dashboard</a>
      <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/report.php', ['cmid' => $q->cmid]); ?>"
         class="btn btn-sm btn-outline-primary">📋 Report</a>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php
// =====================================================================
// TAB: HIGH RISK
// =====================================================================
elseif ($tab === 'highrisk'):
    $hrWhere = '1=0'; $hrParams = [];
    if (!empty($myCmIds)) {
        list($hrInsql, $hrInp) = $DB->get_in_or_equal(array_values($myCmIds), SQL_PARAMS_NAMED);
        $hrWhere  = "s.quizid $hrInsql AND s.risk_level IN ('high','medium')";
        $hrParams = $hrInp;
    }
    $hrSessions = $DB->get_records_sql(
        "SELECT s.id, s.quizid, s.userid, s.risk_score, s.risk_level, s.status, s.start_time,
                u.firstname, u.lastname, u.email,
                q.name AS quizname, c.fullname AS coursename
           FROM {quizaccess_learnshield_sessions} s
           JOIN {user}           u ON u.id = s.userid
           JOIN {course_modules} cm ON cm.id = s.quizid
           JOIN {quiz}           q ON q.id = cm.instance
           JOIN {course}         c ON c.id = q.course
          WHERE $hrWhere
       ORDER BY s.risk_score DESC LIMIT 200", $hrParams
    );
?>
<div class="pv-content">
  <h3 style="margin:0 0 16px;">⚠️ High &amp; Medium Risk Sessions (<?php echo count($hrSessions); ?>)</h3>
  <?php if (empty($hrSessions)): ?>
  <div class="alert alert-success">No medium or high risk sessions found. ✅</div>
  <?php else: ?>
  <div style="overflow-x:auto;"><table class="generaltable w-100" style="font-size:13px;">
    <thead><tr>
      <th>Student</th><th>Quiz</th><th>State</th><th>Risk</th><th>Started</th><th>Actions</th>
    </tr></thead><tbody>
    <?php foreach ($hrSessions as $hr):
        $rc = ['low'=>'#28a745','medium'=>'#fd7e14','high'=>'#dc3545'][$hr->risk_level] ?? '#6c757d'; ?>
    <tr>
      <td style="padding:8px 10px;"><strong><?php echo fullname($hr); ?></strong><br><small class="text-muted"><?php echo s($hr->email); ?></small></td>
      <td style="padding:8px 10px;"><?php echo format_string($hr->quizname); ?></td>
      <td style="padding:8px 10px;"><?php echo ucfirst($hr->status); ?></td>
      <td style="padding:8px 10px;"><span style="background:<?php echo $rc; ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;"><?php echo $hr->risk_score; ?> <?php echo strtoupper($hr->risk_level); ?></span></td>
      <td style="padding:8px 10px;"><small><?php echo userdate($hr->start_time, '%d/%m/%y %H:%M'); ?></small></td>
      <td style="padding:8px 10px;white-space:nowrap;">
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/attemptdetail.php', ['sessionid' => $hr->id]); ?>" class="btn btn-sm btn-outline-danger">🔍 Review</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>

<?php
// =====================================================================
// TAB: SUSPENDED
// =====================================================================
elseif ($tab === 'suspended'):
    $susWhere = '1=0'; $susParams = [];
    if (!empty($myCmIds)) {
        list($susInsql, $susInp) = $DB->get_in_or_equal(array_values($myCmIds), SQL_PARAMS_NAMED);
        $susWhere  = "s.quizid $susInsql AND s.status='suspended'";
        $susParams = $susInp;
    }
    $susSessions = $DB->get_records_sql(
        "SELECT s.id, s.quizid, s.userid, s.risk_score, s.risk_level, s.start_time,
                u.firstname, u.lastname, u.email,
                q.name AS quizname
           FROM {quizaccess_learnshield_sessions} s
           JOIN {user}           u ON u.id = s.userid
           JOIN {course_modules} cm ON cm.id = s.quizid
           JOIN {quiz}           q ON q.id = cm.instance
          WHERE $susWhere ORDER BY s.start_time DESC", $susParams
    );
?>
<div class="pv-content">
  <h3 style="margin:0 0 16px;">🚫 Suspended Students (<?php echo count($susSessions); ?>)</h3>
  <?php if (empty($susSessions)): ?>
  <div class="alert alert-success">No suspended students — all clear. ✅</div>
  <?php else: ?>
  <div style="overflow-x:auto;"><table class="generaltable w-100" style="font-size:13px;">
    <thead><tr><th>Student</th><th>Quiz</th><th>Risk</th><th>Since</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($susSessions as $sus):
        $rc = ['low'=>'#28a745','medium'=>'#fd7e14','high'=>'#dc3545'][$sus->risk_level ?? 'low'] ?? '#6c757d'; ?>
    <tr>
      <td style="padding:8px 10px;"><strong><?php echo fullname($sus); ?></strong><br><small><?php echo s($sus->email); ?></small></td>
      <td style="padding:8px 10px;"><?php echo format_string($sus->quizname); ?></td>
      <td style="padding:8px 10px;"><span style="background:<?php echo $rc; ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;"><?php echo $sus->risk_score ?? 0; ?></span></td>
      <td style="padding:8px 10px;"><small><?php echo userdate($sus->start_time, '%d/%m/%y %H:%M'); ?></small></td>
      <td style="padding:8px 10px;">
        <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/attemptdetail.php', ['sessionid' => $sus->id]); ?>" class="btn btn-sm btn-outline-secondary">🔍 Review</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>

<?php
// =====================================================================
// TAB: PRECHECK ACTIVITY
// =====================================================================
elseif ($tab === 'precheck'):
    $precheckSessions = [];
    try {
        $startOfDay  = mktime(0, 0, 0);
        $pcWhere = '1=0'; $pcParams = [];
        if (!empty($myCmIds)) {
            list($pcInsql, $pcInp) = $DB->get_in_or_equal(array_values($myCmIds), SQL_PARAMS_NAMED);
            $pcWhere  = "ps.cmid $pcInsql AND ps.timecreated > :sod";
            $pcParams = array_merge($pcInp, ['sod' => $startOfDay]);
        }
        $precheckSessions = $DB->get_records_sql(
            "SELECT ps.id, ps.cmid, ps.userid, ps.current_step, ps.step_number, ps.total_steps,
                    ps.timecreated, ps.timemodified,
                    u.firstname, u.lastname, u.email,
                    q.name AS quizname
               FROM {quizaccess_learnshield_precheck_sessions} ps
               JOIN {user}           u ON u.id = ps.userid
               JOIN {course_modules} cm ON cm.id = ps.cmid
               JOIN {quiz}           q ON q.id = cm.instance
              WHERE $pcWhere
           ORDER BY ps.timemodified DESC",
            $pcParams
        );
    } catch (\Exception $e) {}

    $pendingApprovals = [];
    try {
        $faWhere = '1=0'; $faParams = [];
        if (!empty($myCmIds)) {
            list($faInsql, $faInp) = $DB->get_in_or_equal(array_values($myCmIds), SQL_PARAMS_NAMED);
            $faWhere  = "fa.cmid $faInsql AND fa.status='pending'";
            $faParams = $faInp;
        }
        $pendingApprovals = $DB->get_records_sql(
            "SELECT fa.id, fa.cmid, fa.userid, fa.matchpct, fa.timecreated,
                    u.firstname, u.lastname, q.name AS quizname
               FROM {quizaccess_learnshield_face_approvals} fa
               JOIN {user}           u ON u.id = fa.userid
               JOIN {course_modules} cm ON cm.id = fa.cmid
               JOIN {quiz}           q ON q.id = cm.instance
              WHERE $faWhere ORDER BY fa.timecreated DESC",
            $faParams
        );
    } catch (\Exception $e) {}

    $stepLabels = ['closetabs'=>'Close Tabs','welcome'=>'Welcome','browser'=>'Browser Check',
        'internet'=>'Internet Check','camera'=>'Camera Setup','audio'=>'Microphone Check',
        'face'=>'Face Detection','environment'=>'360° Recording','privacy'=>'Privacy Acceptance',
        'fullscreen'=>'Fullscreen','ready'=>'✅ Ready to Start'];
?>
<div class="pv-content">
  <?php if (!empty($pendingApprovals)): ?>
  <div style="background:#fff3cd;border:2px solid #ffc107;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <span style="font-size:24px;">⚠️</span>
    <div style="flex:1;">
      <strong><?php echo count($pendingApprovals); ?> student(s) waiting for face approval</strong>
      <p style="margin:3px 0 0;font-size:13px;color:#6c757d;">These students cannot start their exam until you review and approve.</p>
    </div>
    <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/face_approvals.php'); ?>" class="btn btn-warning" style="font-weight:700;">👤 Review &amp; Approve (<?php echo count($pendingApprovals); ?>)</a>
  </div>
  <?php endif; ?>

  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <h3 style="margin:0;">🔍 Precheck Activity — Today
      <span style="background:#0f6cbf;color:#fff;border-radius:12px;padding:2px 10px;font-size:13px;margin-left:8px;"><?php echo count($precheckSessions); ?></span>
    </h3>
    <div style="display:flex;gap:10px;align-items:center;">
      <small style="color:#6c757d;">All students who started precheck today. Auto-refreshes every 20s.</small>
      <button onclick="location.reload()" class="btn btn-sm btn-outline-secondary">🔄 Refresh</button>
    </div>
  </div>

  <?php if (empty($precheckSessions)): ?>
  <div class="alert alert-info"><strong>No students have started precheck today.</strong><br>When students open the pre-exam wizard, their progress will appear here in real time.</div>
  <?php else: ?>
  <?php
  $readyCount      = count(array_filter((array)$precheckSessions, fn($p) => $p->current_step === 'ready'));
  $waitingCount    = count(array_filter((array)$precheckSessions, fn($p) => $p->current_step === 'face'));
  $inProgressCount = count($precheckSessions) - $readyCount - $waitingCount;
  ?>
  <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
    <span style="background:#28a745;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;">✅ <?php echo $readyCount; ?> Ready</span>
    <span style="background:#0f6cbf;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;">🔄 <?php echo $inProgressCount; ?> In Progress</span>
    <?php if ($waitingCount > 0): ?><span style="background:#fd7e14;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;">⚠️ <?php echo $waitingCount; ?> Needs Review</span><?php endif; ?>
  </div>
  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:12px;">
  <?php foreach ($precheckSessions as $ps):
      $elapsed    = max(0, time() - (int)$ps->timecreated);
      $lastSeen   = max(0, time() - (int)$ps->timemodified);
      $pct        = $ps->total_steps > 0 ? round(($ps->step_number / $ps->total_steps) * 100) : 0;
      $stepLabel  = $stepLabels[$ps->current_step] ?? ucwords(str_replace('_',' ',$ps->current_step));
      $isDone     = $ps->current_step === 'ready';
      $needsReview = $ps->current_step === 'face';
      $isStale    = $lastSeen > 300;
      $border     = $isDone ? '#28a745' : ($needsReview ? '#fd7e14' : ($isStale ? '#adb5bd' : '#0f6cbf'));
      $sIcon      = $isDone ? '✅' : ($needsReview ? '⚠️' : ($isStale ? '⏸' : '🔄'));
      $sLabel     = $isDone ? 'Ready' : ($needsReview ? 'Needs Review' : ($isStale ? 'Paused' : 'Active'));
  ?>
  <div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;border-left:5px solid <?php echo $border; ?>;">
    <div style="padding:12px 14px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
        <div><div style="font-weight:700;font-size:14px;"><?php echo fullname($ps); ?></div><div style="font-size:11px;color:#6c757d;"><?php echo s($ps->email); ?></div></div>
        <span style="background:<?php echo $border; ?>;color:#fff;font-size:11px;font-weight:700;padding:3px 8px;border-radius:10px;flex-shrink:0;margin-left:8px;"><?php echo $sIcon; ?> <?php echo $sLabel; ?></span>
      </div>
      <div style="font-size:12px;color:#495057;margin-bottom:8px;">📋 <?php echo format_string($ps->quizname); ?></div>
      <div style="font-size:12px;margin-bottom:6px;">
        <strong>Step:</strong>
        <span style="background:<?php echo $isDone ? '#d4edda' : ($needsReview ? '#fff3cd' : '#e3f2fd'); ?>;border-radius:4px;padding:2px 8px;font-weight:600;">
          <?php echo $ps->step_number; ?>/<?php echo $ps->total_steps; ?>: <?php echo htmlspecialchars($stepLabel); ?>
        </span>
      </div>
      <div style="background:#e9ecef;border-radius:6px;height:10px;overflow:hidden;margin-bottom:8px;">
        <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $border; ?>;border-radius:6px;"></div>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:11px;color:#adb5bd;">
        <span>Started <?php echo $elapsed < 60 ? 'just now' : floor($elapsed/60) . 'min ago'; ?></span>
        <span>Seen <?php echo $lastSeen < 60 ? 'just now' : floor($lastSeen/60) . 'min ago'; ?></span>
      </div>
    </div>
    <?php if ($needsReview): ?>
    <div style="padding:8px 14px;background:#fff3cd;border-top:1px solid #ffc107;font-size:12px;color:#856404;display:flex;align-items:center;justify-content:space-between;">
      <span>⚠️ Waiting for face approval</span>
      <a href="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/face_approvals.php',['cmid'=>$ps->cmid]); ?>" class="btn btn-sm btn-warning" style="font-size:11px;padding:3px 10px;">Review →</a>
    </div>
    <?php elseif ($isDone): ?>
    <div style="padding:8px 14px;background:#d4edda;border-top:1px solid #c3e6cb;font-size:12px;color:#155724;">
      ✅ Precheck complete — student can start exam
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <script>setTimeout(function(){location.reload();},20000);</script>
</div>

<?php
// =====================================================================
// TAB: FACE APPROVALS
// =====================================================================
elseif ($tab === 'faceapprove'):
    $faUrl = new moodle_url('/mod/quiz/accessrule/learnshield/face_approvals.php');
    redirect($faUrl);

// =====================================================================
// TAB: GALLERY
// =====================================================================
elseif ($tab === 'gallery'):
    $filterCmid = optional_param('cmid', 0, PARAM_INT);
    $filterType = optional_param('type', '', PARAM_ALPHANUMEXT);

    // Handle bulk delete.
    $action = optional_param('action', '', PARAM_ALPHA);
    if ($action === 'delete' && confirm_sesskey()) {
        $delIds = optional_param_array('delids', [], PARAM_INT);
        if (!empty($delIds)) {
            foreach ($delIds as $delId) {
                $log = $DB->get_record('quizaccess_learnshield_logs', ['id' => $delId]);
                if ($log && in_array($log->quizid, array_values($myCmIds))) {
                    $DB->delete_records('quizaccess_learnshield_logs', ['id' => $delId]);
                }
            }
            redirect(new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php',
                ['tab'=>'gallery','cmid'=>$filterCmid,'type'=>$filterType]),
                count($delIds) . ' item(s) deleted.', 2);
        }
    }

    $galWhere = '1=0'; $galParams = [];
    if (!empty($myCmIds)) {
        $cmidFilter = $filterCmid > 0 ? [$filterCmid] : array_values($myCmIds);
        list($gInsql, $gInp) = $DB->get_in_or_equal($cmidFilter, SQL_PARAMS_NAMED);
        // Include all media: webcam snapshots (imagetype 1), 360° videos (imagetype 2), photo IDs (imagetype 3).
        $typeFilter = $filterType === 'video' ? ' AND l.imagetype = 2' :
                      ($filterType === 'photoid' ? ' AND l.imagetype = 3' :
                      ($filterType === 'snapshot' ? ' AND l.imagetype = 1' : ''));
        $galWhere  = "l.quizid $gInsql AND (l.webcampicture IS NOT NULL OR l.imagetype = 2)" . $typeFilter;
        $galParams = $gInp;
    }
    $galLogs = $DB->get_records_sql(
        "SELECT l.id, l.quizid, l.userid, l.timemodified, l.imagetype, l.parenttype,
                u.firstname, u.lastname, q.name AS quizname
           FROM {quizaccess_learnshield_logs} l
           JOIN {user}           u ON u.id = l.userid
           JOIN {course_modules} cm ON cm.id = l.quizid
           JOIN {quiz}           q ON q.id = cm.instance
          WHERE $galWhere
          ORDER BY l.timemodified DESC",
        $galParams, 0, 60
    );
?>
<div class="pv-content">
  <div style="display:flex;gap:10px;align-items:flex-end;margin-bottom:16px;flex-wrap:wrap;">
    <div>
      <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Quiz</label>
      <select class="form-control" style="max-width:240px;" onchange="location.href=this.value">
        <option value="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php',['tab'=>'gallery']); ?>">— All Quizzes —</option>
        <?php foreach ($myQuizzes as $qid => $q): ?>
        <option value="<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php',['tab'=>'gallery','cmid'=>$q->cmid]); ?>"
                <?php echo $filterCmid == $q->cmid ? 'selected' : ''; ?>><?php echo format_string($q->name); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Type</label>
      <select class="form-control" style="max-width:180px;" onchange="location.href=this.value">
        <?php $galTypeBase = new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php',['tab'=>'gallery','cmid'=>$filterCmid]); ?>
        <option value="<?php echo $galTypeBase; ?>">All types</option>
        <option value="<?php echo new moodle_url($galTypeBase,['type'=>'snapshot']); ?>" <?php echo $filterType==='snapshot'?'selected':''; ?>>📸 Webcam Snapshots</option>
        <option value="<?php echo new moodle_url($galTypeBase,['type'=>'video']); ?>" <?php echo $filterType==='video'?'selected':''; ?>>🎥 360° Videos</option>
        <option value="<?php echo new moodle_url($galTypeBase,['type'=>'photoid']); ?>" <?php echo $filterType==='photoid'?'selected':''; ?>>🪪 Photo IDs</option>
      </select>
    </div>
    <small style="color:#6c757d;align-self:flex-end;"><?php echo count($galLogs); ?> items</small>
    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
      <label style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;">
        <input type="checkbox" id="ls-gal-sel-all" onchange="lsGalSelAll(this.checked)" style="width:14px;height:14px;"> Select All
      </label>
      <button onclick="lsGalDeleteSelected()" class="btn btn-sm btn-danger" id="ls-gal-del-btn" style="display:none;">🗑 Delete Selected (<span id="ls-gal-sel-count">0</span>)</button>
    </div>
  </div>
  <?php if (empty($galLogs)): ?>
  <div class="alert alert-info">No media found for the selected filter.</div>
  <?php else: ?>
  <div id="ls-gal-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">
  <?php foreach ($galLogs as $gl):
      $src = (new moodle_url('/mod/quiz/accessrule/learnshield/snapshot.php',['id'=>$gl->id,'sesskey'=>sesskey()]))->out(false);
      $isVid = ($gl->imagetype == 2 || $gl->parenttype === 'environment_recording');
      $isId  = ($gl->imagetype == 3 || $gl->parenttype === 'photo_id');
      $icon  = $isVid ? '🎥' : ($isId ? '🪪' : '📷');
      $studentName = htmlspecialchars(fullname((object)['firstname'=>$gl->firstname,'lastname'=>$gl->lastname]), ENT_QUOTES);
  ?>
  <div class="ls-gal-item" data-id="<?php echo $gl->id; ?>"
       style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.1);position:relative;">
    <label style="position:absolute;top:6px;left:6px;z-index:2;cursor:pointer;">
      <input type="checkbox" class="ls-gal-chk" data-id="<?php echo $gl->id; ?>"
             style="width:16px;height:16px;accent-color:#dc3545;"
             onchange="lsGalChkChanged()">
    </label>
    <?php if ($isVid): ?>
    <video src="<?php echo $src; ?>" controls style="width:100%;height:120px;object-fit:cover;background:#111;"></video>
    <?php else: ?>
    <img src="<?php echo $src; ?>" loading="lazy"
         style="width:100%;height:120px;object-fit:cover;cursor:zoom-in;"
         onclick="pvLbOpen('<?php echo $src; ?>','<?php echo $studentName; ?>')">
    <?php endif; ?>
    <div style="padding:6px 8px;">
      <div style="font-size:11px;font-weight:600;"><?php echo $icon; ?> <?php echo htmlspecialchars(fullname((object)['firstname'=>$gl->firstname,'lastname'=>$gl->lastname])); ?></div>
      <div style="font-size:10px;color:#adb5bd;"><?php echo userdate($gl->timemodified,'%d/%m/%y %H:%M'); ?></div>
      <div style="font-size:10px;color:#6c757d;"><?php echo format_string($gl->quizname); ?></div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<script>
function lsGalChkChanged() {
    var checked = document.querySelectorAll('.ls-gal-chk:checked').length;
    var delBtn = document.getElementById('ls-gal-del-btn');
    var cntEl  = document.getElementById('ls-gal-sel-count');
    if (delBtn) { delBtn.style.display = checked > 0 ? 'inline-block' : 'none'; }
    if (cntEl)  { cntEl.textContent = checked; }
}
function lsGalSelAll(checked) {
    document.querySelectorAll('.ls-gal-chk').forEach(function(c) { c.checked = checked; });
    lsGalChkChanged();
}
function lsGalDeleteSelected() {
    var ids = Array.from(document.querySelectorAll('.ls-gal-chk:checked')).map(function(c) { return c.dataset.id; });
    if (!ids.length) { return; }
    if (!confirm('Delete ' + ids.length + ' selected item(s)? This cannot be undone.')) { return; }
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php',['tab'=>'gallery','cmid'=>$filterCmid,'type'=>$filterType,'action'=>'delete']); ?>';
    var sess = document.createElement('input'); sess.type='hidden'; sess.name='sesskey'; sess.value='<?php echo sesskey(); ?>';
    form.appendChild(sess);
    ids.forEach(function(id) {
        var inp = document.createElement('input'); inp.type='hidden'; inp.name='delids[]'; inp.value=id;
        form.appendChild(inp);
    });
    document.body.appendChild(form); form.submit();
}
</script>
<?php endif; // end tab switch ?>

</div><!-- /pv-wrap -->

<div id="pv-lb" onclick="pvLbClose()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.95);z-index:99999;align-items:center;justify-content:center;flex-direction:column;">
  <button onclick="pvLbClose()" style="position:absolute;top:16px;right:20px;background:none;border:none;color:#fff;font-size:28px;cursor:pointer;">✕</button>
  <img id="pv-lb-img" src="" style="max-width:95vw;max-height:85vh;border-radius:4px;" alt="">
  <div id="pv-lb-cap" style="color:#ddd;margin-top:8px;font-size:14px;"></div>
</div>

<script>
(function(){
'use strict';
var wwwroot  = <?php echo json_encode($CFG->wwwroot); ?>;
var sk       = <?php echo json_encode(sesskey()); ?>;
var cmids    = <?php echo json_encode(array_values($myCmIds)); ?>;

var ALL_SESSIONS  = [];
var cardView      = {};      // per-card userid: 'webcam'|'screen'
var globalView    = 'screen'; // Screen is default; proctor can switch to webcam
var currentFilter = 'all';
var currentCols   = 3;
var currentPerPage = 15;
var currentPage   = 0;

function ws(method, args, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', wwwroot + '/lib/ajax/service.php?sesskey=' + sk, true);
    xhr.setRequestHeader('Content-Type','application/json');
    xhr.onload = function() {
        try { var r=JSON.parse(xhr.responseText); if(r&&r[0]&&r[0].data) cb(r[0].data); } catch(e){}
    };
    xhr.send(JSON.stringify([{index:0,methodname:method,args:args}]));
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;'); }

function getFiltered() {
    var now = Math.floor(Date.now()/1000);
    return ALL_SESSIONS.filter(function(s) {
        if (currentFilter==='high')   return s.risk_level==='high';
        if (currentFilter==='medium') return s.risk_level==='high'||s.risk_level==='medium';
        if (currentFilter==='active') return (now-(s.lastseen||0))<120;
        return true;
    });
}

window.pvApplyFilter = function(f) {
    currentFilter = f; currentPage = 0;
    ['all','high','medium','active'].forEach(function(k){
        var b=document.getElementById('pvf-'+k); if(b) b.classList.toggle('active',k===f);
    });
    renderPage();
};

window.pvApplyGrid = function(n) {
    currentCols = n; currentPage = 0;
    var g=document.getElementById('pv-live-grid');
    if(g) g.style.gridTemplateColumns='repeat('+n+',1fr)';
    document.querySelectorAll('.pv-grid-btn').forEach(function(b){
        b.classList.toggle('active', parseInt(b.dataset.cols,10)===n);
    });
    renderPage();
};

window.pvApplyPerPage = function(n) { currentPerPage=n; currentPage=0; renderPage(); };

window.pvApplyView = function(mode) {
    globalView = mode;
    // Reset all per-card overrides to new global default.
    cardView = {};
    ['cam','screen'].forEach(function(k){
        var b=document.getElementById('pvv-'+k); if(!b) return;
        var on=(k==='cam'&&mode==='webcam')||(k==='screen'&&mode==='screen');
        b.style.background=on?'#0f6cbf':'#fff'; b.style.color=on?'#fff':'#6c757d';
    });
    renderPage();
};

window.pvToggleCardView = function(uid) {
    var cur=cardView[uid]||globalView;
    cardView[uid]=cur==='screen'?'webcam':'screen';
    renderPage();
};

function buildCard(s) {
    var uid  = s.userid;
    var view = cardView[uid]||globalView;
    // Snapshots come as URLs from the server — use directly.
    var snap = view==='screen' ? (s.screen_snapshot||s.webcam_snapshot) : (s.webcam_snapshot||s.screen_snapshot);
    var now  = Math.floor(Date.now()/1000);
    var ago  = now-(s.lastseen||s.timemodified||0);
    var online = ago<180;
    var rc = {low:'#28a745',medium:'#fd7e14',high:'#dc3545'}[s.risk_level]||'#6c757d';
    var agoStr = ago<60?'Now':Math.floor(ago/60)+'m';
    var icon = view==='screen'?'🖥':'📷';
    var noSnap = view==='screen'?'No screen':'No webcam';
    var thumb = snap
        ? '<img style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;cursor:zoom-in;" src="'+snap+'" onclick="pvLbOpen(\''+snap.replace(/'/g,"\\'")+'\',\''+esc(s.fullname)+'\')" loading="lazy">'
        : '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;color:#444;font-size:12px;gap:4px;"><span style="font-size:22px;">'+icon+'</span><span>'+noSnap+'</span></div>';
    return '<div style="position:relative;width:100%;padding-top:75%;background:#111;">' +
        thumb +
        '<div style="position:absolute;top:0;left:0;right:0;padding:4px 6px;display:flex;justify-content:space-between;background:linear-gradient(to bottom,rgba(0,0,0,.7),transparent);">' +
        '<span style="width:8px;height:8px;border-radius:50%;background:'+(online?'#28a745':'#dc3545')+';display:inline-block;'+(online?'animation:ls-pulse 1.5s infinite;':'')+'"></span>' +
        '<span style="font-size:9px;font-weight:700;color:#fff;background:'+rc+';padding:1px 5px;border-radius:6px;">'+(s.risk_score||0)+' '+(s.risk_level||'').toUpperCase()+'</span>' +
        '</div>' +
        '<button onclick="pvToggleCardView('+uid+')" style="position:absolute;bottom:4px;right:4px;font-size:10px;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:4px;padding:2px 6px;cursor:pointer;">' +
        (view==='screen'?'📷 Cam':'🖥 Screen')+'</button>' +
        '</div>' +
        '<div style="padding:5px 8px;background:#16213e;color:#ddd;font-size:12px;display:flex;justify-content:space-between;">' +
        '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;font-weight:700;">'+esc(s.fullname)+'</span>' +
        '<div style="display:flex;gap:6px;align-items:center;">' +
        '<span style="font-size:10px;color:#aaa;">Tab:'+( s.tab_switches||0)+'</span>' +
        '<span style="font-size:10px;color:#888;">'+agoStr+'</span>' +
        '</div>' +
        '</div>';
}

function renderPage() {
    var grid=document.getElementById('pv-live-grid');
    var pgBar=document.getElementById('pv-pagination');
    var badge=document.getElementById('pv-count-badge');
    if(!grid) return;
    var filtered=getFiltered();
    var total=filtered.length;
    var pages=Math.max(1,Math.ceil(total/currentPerPage));
    currentPage=Math.min(currentPage,pages-1);
    var slice=filtered.slice(currentPage*currentPerPage,(currentPage+1)*currentPerPage);
    grid.style.gridTemplateColumns='repeat('+currentCols+',1fr)';
    if(badge) badge.textContent=total+' candidate'+(total!==1?'s':'')+(total>currentPerPage?' · Page '+(currentPage+1)+'/'+pages:'');
    if(!total){
        grid.innerHTML='<div style="grid-column:1/-1;display:flex;align-items:center;justify-content:center;color:#555;flex-direction:column;gap:8px;padding:60px;"><div style="font-size:36px;">👁</div><p>'+(ALL_SESSIONS.length?'No sessions match the filter.':'No active sessions right now.')+'</p></div>';
        if(pgBar) pgBar.style.display='none'; return;
    }
    grid.innerHTML='';
    slice.forEach(function(s){
        var card=document.createElement('div');
        card.id='pvc-'+s.id;
        card.style.cssText='border-radius:8px;overflow:hidden;background:#1a1a2e;position:relative;'+(s.risk_level==='high'?'box-shadow:0 0 0 2px #dc3545;':s.risk_level==='medium'?'box-shadow:0 0 0 2px #fd7e14;':'');
        card.innerHTML=buildCard(s);
        grid.appendChild(card);
    });
    if(pgBar){
        if(pages<=1){pgBar.style.display='none';return;}
        pgBar.style.display='flex'; pgBar.innerHTML='';
        function pgBtn(lbl,p,dis){var b=document.createElement('button');b.textContent=lbl;b.disabled=dis;b.style.cssText='padding:5px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;border:1px solid #dee2e6;background:'+(p===currentPage?'#0f6cbf':'#fff')+';color:'+(p===currentPage?'#fff':'#495057')+';';if(!dis)b.addEventListener('click',function(){currentPage=p;renderPage();});return b;}
        pgBar.appendChild(pgBtn('‹',currentPage-1,currentPage===0));
        for(var p=0;p<pages;p++){pgBar.appendChild(pgBtn(p+1,p,false));}
        pgBar.appendChild(pgBtn('›',currentPage+1,currentPage>=pages-1));
    }
}

function fetchLive() {
    if(!cmids.length){ALL_SESSIONS=[];renderPage();return;}
    var pending=cmids.length; var merged=[];
    cmids.forEach(function(cmid){
        ws('quizaccess_learnshield_get_live_sessions',{courseid:0,quizid:cmid},function(data){
            (data.sessions||[]).forEach(function(s){merged.push(s);});
            if(--pending===0){
                // Deduplicate by USERID — one card per learner.
                // If same learner has sessions in multiple quizzes, keep the highest-risk one.
                var byUser={};
                merged.forEach(function(s){
                    var uid=s.userid;
                    if(!byUser[uid]||s.risk_score>(byUser[uid].risk_score||0)||s.lastseen>(byUser[uid].lastseen||0)){
                        byUser[uid]=s;
                    }
                });
                ALL_SESSIONS=Object.keys(byUser).map(function(k){return byUser[k];});
                renderPage();
            }
        });
    });
}

window.pvLbOpen = function(src,cap){
    var el=document.getElementById('pv-lb'); var im=document.getElementById('pv-lb-img'); var cp=document.getElementById('pv-lb-cap');
    if(el)el.style.display='flex'; if(im)im.src=src; if(cp)cp.textContent=cap||'';
};
window.pvLbClose = function(){
    var el=document.getElementById('pv-lb'); var im=document.getElementById('pv-lb-img');
    if(el)el.style.display='none'; if(im)im.src='';
};

<?php if ($tab === 'live'): ?>
(function(){var g=document.getElementById('pv-live-grid');if(g)g.style.gridTemplateColumns='repeat(3,1fr)';})();
// Quiz switcher — filter to a single quiz or show all.
var allCmids = cmids.slice(); // Save original full list.
window.pvSwitchQuiz = function(cmid) {
    cmid = parseInt(cmid, 10);
    if (cmid > 0) {
        cmids = [cmid];
    } else {
        cmids = allCmids.slice();
    }
    ALL_SESSIONS = [];
    currentPage  = 0;
    fetchLive();
};

fetchLive();
setInterval(fetchLive, 15000); // Refresh every 15 seconds.
<?php endif; ?>

})();
</script>

<?php echo $OUTPUT->footer();
