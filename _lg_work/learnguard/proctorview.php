<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnGuard Proctor Backend — dedicated interface for human invigilators.
 *
 * Accessible to users assigned as proctors (global, course-level, or per-assessment).
 * Global admins can also access this page.
 * Shows only assessments the current user is assigned to proctor.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
$context = context_system::instance();
$PAGE->set_context($context);

$isGlobalAdmin = has_capability('moodle/site:config', $context);

// Determine if the current user is a proctor for at least one assessment.
$isGlobalProctor = $DB->record_exists_select(
    'learnguard_proctors',
    'userid = :uid AND is_global = 1',
    ['uid' => $USER->id]
);
$isAnyProctor = $isGlobalAdmin || $isGlobalProctor || $DB->record_exists(
    'learnguard_proctors',
    ['userid' => $USER->id]
);

if (!$isAnyProctor) {
    throw new moodle_exception('accessdenied', 'error', '', 'You are not assigned as a proctor for any LearnGuard assessment.');
}

$tab = optional_param('tab', 'live', PARAM_ALPHA);
$PAGE->set_url('/mod/learnguard/proctorview.php', ['tab' => $tab]);
$PAGE->set_title('LearnGuard Proctor View');
$PAGE->set_heading('LearnGuard — Invigilator Dashboard');
$PAGE->requires->css('/mod/learnguard/styles.css');

$brand = learnguard_brand();

// Load proctor permissions from global settings.
$permCanGallery    = get_config('learnguard', 'proctor_perm_gallery')    ?? 1;
$permCanReports    = get_config('learnguard', 'proctor_perm_reports')    ?? 1;
$permCanSuspend    = get_config('learnguard', 'proctor_perm_suspend')    ?? 1;
$permCanGrantTime  = get_config('learnguard', 'proctor_perm_granttime')  ?? 1;
$permCanFaceApprov = get_config('learnguard', 'proctor_perm_faceapprove')?? 1;
$permCanExport     = get_config('learnguard', 'proctor_perm_export')     ?? 0;

// Get the assessments this proctor is assigned to.
if ($isGlobalAdmin || $isGlobalProctor) {
    // Global proctors/admins see all assessments.
    $myAssessments = $DB->get_records_sql(
        "SELECT DISTINCT lg.id, lg.name, c.fullname AS coursename, c.id AS courseid,
                cm.id AS cmid, lg.timeopen, lg.timeclose, lg.timelimit
           FROM {learnguard} lg
           JOIN {course} c ON c.id = lg.course
           JOIN {modules} md ON md.name = 'learnguard'
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = md.id
        ORDER BY c.fullname, lg.name"
    );
} else {
    // Per-assessment and course-level proctors — only their assigned assessments.
    $myAssessments = $DB->get_records_sql(
        "SELECT DISTINCT lg.id, lg.name, c.fullname AS coursename, c.id AS courseid,
                cm.id AS cmid, lg.timeopen, lg.timeclose, lg.timelimit
           FROM {learnguard_proctors} p
           JOIN {learnguard} lg ON (
               lg.id = p.learnguard
               OR (p.courseid = lg.course AND p.learnguard = 0)
           )
           JOIN {course} c ON c.id = lg.course
           JOIN {modules} md ON md.name = 'learnguard'
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = md.id
          WHERE p.userid = :uid
       ORDER BY c.fullname, lg.name",
        ['uid' => $USER->id]
    );
}

$myLgIds = array_keys($myAssessments);
$myCmIds = array_column((array)$myAssessments, 'cmid', 'id');

// Summary counts across proctor's assessments.
$liveCount = $highRiskCount = $suspendedCount = $pendingFaceCount = 0;
if (!empty($myLgIds)) {
    list($insql, $inparams) = $DB->get_in_or_equal($myLgIds, SQL_PARAMS_NAMED);
    $liveCount      = (int)$DB->count_records_select('learnguard_attempts', "learnguard $insql AND state='inprogress'", $inparams);
    $highRiskCount  = (int)$DB->count_records_select('learnguard_attempts', "learnguard $insql AND risk_level='high' AND state='inprogress'", $inparams);
    $suspendedCount = (int)$DB->count_records_select('learnguard_attempts', "learnguard $insql AND state='suspended'", $inparams);
    try {
        $myCmIdsList = array_values($myCmIds);
        list($cmInsql, $cmInparams) = $DB->get_in_or_equal($myCmIdsList, SQL_PARAMS_NAMED);
        $pendingFaceCount = (int)$DB->count_records_select('learnguard_face_approvals', "cmid $cmInsql AND status='pending'", $cmInparams);
    } catch (Exception $e) {}
}

echo $OUTPUT->header();
?>

<style>
/* ── Proctor view theme ─────────────────────────── */
:root { --pv-primary: <?php echo $brand->primary; ?>; --pv-accent: <?php echo $brand->accent; ?>; }
.pv-wrap        { font-family:"Segoe UI",system-ui,sans-serif; }

/* Header bar */
.pv-header {
    background:var(--pv-primary); color:<?php echo $brand->textcolor; ?>;
    padding:0 24px; height:56px; display:flex; align-items:center;
    justify-content:space-between; box-shadow:0 2px 12px rgba(0,0,0,.2);
    position:sticky; top:0; z-index:100; flex-shrink:0;
}
.pv-header-brand { font-size:18px; font-weight:800; display:flex; align-items:center; gap:10px; }
.pv-header-meta  { font-size:12px; opacity:.85; }

/* Stat pills */
.pv-stats { display:flex; gap:10px; padding:16px 24px; background:#f8f9fa; border-bottom:1px solid #dee2e6; flex-wrap:wrap; }
.pv-stat  { display:flex; align-items:center; gap:8px; background:#fff; border-radius:10px; padding:10px 16px; box-shadow:0 1px 4px rgba(0,0,0,.07); min-width:120px; }
.pv-stat-num  { font-size:22px; font-weight:800; line-height:1; }
.pv-stat-lbl  { font-size:11px; color:#6c757d; font-weight:600; }

/* Tab strip */
.pv-tabs { display:flex; background:#fff; border-bottom:2px solid #dee2e6; overflow-x:auto; scrollbar-width:none; }
.pv-tabs::-webkit-scrollbar { display:none; }
.pv-tab  { display:flex; align-items:center; gap:6px; padding:0 20px; height:46px; font-size:13px; font-weight:600; color:#6c757d; text-decoration:none; border-bottom:3px solid transparent; white-space:nowrap; flex-shrink:0; transition:all .15s; }
.pv-tab:hover { color:#212529; background:#f8f9fa; }
.pv-tab.active { color:var(--pv-primary); border-bottom-color:var(--pv-primary); font-weight:700; }
.pv-badge { background:#dc3545; color:#fff; border-radius:10px; padding:1px 6px; font-size:10px; font-weight:800; }
.pv-badge-orange { background:#fd7e14; }
.pv-badge-green  { background:#28a745; }

/* Content area */
.pv-content { padding:20px 24px; }

/* Assessment card grid */
.pv-asmt-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(300px,1fr)); gap:14px; }
.pv-asmt-card { background:#fff; border-radius:10px; box-shadow:0 2px 8px rgba(0,0,0,.08); padding:18px; border-left:4px solid var(--pv-primary); }
.pv-asmt-card h4 { margin:0 0 6px; font-size:15px; color:#212529; }
.pv-asmt-card .pv-course { font-size:12px; color:#6c757d; margin-bottom:10px; }
.pv-asmt-card .pv-actions { display:flex; gap:8px; flex-wrap:wrap; }

/* Live grid (same as proctordashboard) */
.pv-live-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(200px,1fr)); gap:10px; background:#111; padding:12px; border-radius:0; }
.pv-live-grid.grid-2 { grid-template-columns:repeat(2,1fr); }
.pv-live-grid.grid-3 { grid-template-columns:repeat(3,1fr); }
.pv-live-grid.grid-4 { grid-template-columns:repeat(4,1fr); }
.pv-live-grid.grid-6 { grid-template-columns:repeat(6,1fr); }

.pv-cand-card { border-radius:8px; overflow:hidden; background:#1a1a2e; cursor:pointer; position:relative; }
.pv-cand-card.risk-high   { box-shadow:0 0 0 2px #dc3545; }
.pv-cand-card.risk-medium { box-shadow:0 0 0 2px #fd7e14; }
.pv-cand-thumb-wrap { position:relative; width:100%; padding-top:75%; background:#111; }
.pv-cand-thumb { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.pv-cand-no-feed { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:#444; font-size:12px; flex-direction:column; gap:4px; }
.pv-cand-overlay { position:absolute; top:0; left:0; right:0; padding:4px 6px; display:flex; justify-content:space-between; background:linear-gradient(to bottom,rgba(0,0,0,.7),transparent); }
.pv-cand-name-bar { padding:5px 8px; background:#16213e; color:#ddd; font-size:12px; display:flex; justify-content:space-between; }
.pv-online-dot { width:8px; height:8px; border-radius:50%; display:inline-block; }
.pv-risk-pill  { font-size:9px; font-weight:700; color:#fff; padding:1px 5px; border-radius:6px; }
.pv-view-toggle { position:absolute; bottom:4px; right:4px; font-size:10px; background:rgba(0,0,0,.6); color:#fff; border:none; border-radius:4px; padding:2px 6px; cursor:pointer; }

/* Filters bar */
.pv-filters { display:flex; gap:8px; align-items:center; padding:10px 24px; background:#fff; border-bottom:1px solid #dee2e6; flex-wrap:wrap; }
.pv-filter-btn { padding:4px 12px; border-radius:14px; font-size:12px; font-weight:600; cursor:pointer; border:2px solid transparent; transition:.15s; }
.pv-filter-btn.active { border-color:var(--pv-primary); color:var(--pv-primary); }
.pv-filter-btn:not(.active) { background:#f8f9fa; color:#6c757d; }

/* Grid size selector */
.pv-grid-size { display:flex; gap:4px; align-items:center; }
.pv-grid-btn { width:32px; height:28px; border:1px solid #dee2e6; border-radius:4px; cursor:pointer; font-size:11px; font-weight:700; background:#fff; color:#495057; transition:.15s; }
.pv-grid-btn.active { background:var(--pv-primary); color:#fff; border-color:var(--pv-primary); }

@keyframes lg-pulse { 0%,100%{opacity:1}50%{opacity:.3} }
</style>

<div class="pv-wrap">

<!-- ── Header ──────────────────────────────────────────────── -->
<div class="pv-header">
  <div class="pv-header-brand">
    🛡 <?php echo htmlspecialchars($brand->name ?: 'LearnGuard'); ?> — Invigilator
  </div>
  <div class="pv-header-meta">
    <?php echo fullname($USER); ?> &nbsp;·&nbsp;
    <?php echo count($myAssessments); ?> assessment<?php echo count($myAssessments) !== 1 ? 's' : ''; ?> assigned
    &nbsp;·&nbsp; <?php echo userdate(time(), '%H:%M'); ?>
    <?php if ($isGlobalAdmin || $isGlobalProctor): ?>
    &nbsp;·&nbsp; <span style="background:rgba(255,255,255,.2);padding:2px 8px;border-radius:10px;">Global Access</span>
    <?php endif; ?>
    &nbsp;&nbsp;
    <?php if ($isGlobalAdmin): ?>
    <a href="<?php echo new moodle_url('/mod/learnguard/siteadmin.php'); ?>"
       style="color:rgba(255,255,255,.8);font-size:12px;text-decoration:none;">⚙️ Admin Panel</a>
    <?php endif; ?>
  </div>
</div>

<!-- ── Stat pills ───────────────────────────────────────────── -->
<div class="pv-stats">
  <div class="pv-stat">
    <div>
      <div class="pv-stat-num" style="color:<?php echo $brand->primary; ?>;"><?php echo count($myAssessments); ?></div>
      <div class="pv-stat-lbl">My Assessments</div>
    </div>
  </div>
  <div class="pv-stat">
    <div>
      <div class="pv-stat-num" id="pv-stat-live" style="color:#28a745;"><?php echo $liveCount; ?></div>
      <div class="pv-stat-lbl">🟢 Live Now</div>
    </div>
  </div>
  <div class="pv-stat">
    <div>
      <div class="pv-stat-num" id="pv-stat-highrisk" style="color:#dc3545;"><?php echo $highRiskCount; ?></div>
      <div class="pv-stat-lbl">⚠️ High Risk</div>
    </div>
  </div>
  <div class="pv-stat">
    <div>
      <div class="pv-stat-num" id="pv-stat-suspended" style="color:#fd7e14;"><?php echo $suspendedCount; ?></div>
      <div class="pv-stat-lbl">🚫 Suspended</div>
    </div>
  </div>
  <?php if ($permCanFaceApprov): ?>
  <div class="pv-stat">
    <div>
      <div class="pv-stat-num" style="color:#6f42c1;"><?php echo $pendingFaceCount; ?></div>
      <div class="pv-stat-lbl">👤 Face Approvals</div>
    </div>
  </div>
  <?php endif; ?>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
    <a href="<?php echo new moodle_url('/mod/learnguard/face_approvals.php'); ?>"
       class="pv-filter-btn<?php echo $pendingFaceCount > 0 ? '' : ' active'; ?>"
       style="<?php echo $pendingFaceCount > 0 ? 'background:#fd7e14;color:#fff;border-color:#fd7e14;' : ''; ?>">
      👤 Face Approvals<?php echo $pendingFaceCount > 0 ? ' (' . $pendingFaceCount . ')' : ''; ?>
    </a>
    <button onclick="location.reload()" class="pv-filter-btn">🔄 Refresh</button>
  </div>
</div>

<!-- ── Tab strip ────────────────────────────────────────────── -->
<div class="pv-tabs">
  <?php
  $pvTabs = [
      'live'        => ['🔴 Live Monitor',    null,                   true],
      'assessments' => ['📋 My Assessments',  count($myAssessments),  true],
      'highrisk'    => ['⚠️ High Risk',       $highRiskCount  ?: null, true],
      'suspended'   => ['🚫 Suspended',       $suspendedCount ?: null, $permCanSuspend],
      'precheck'    => ['🔍 Precheck',        $pendingFaceCount ?: null, true], // Always visible
  ];
  if ($permCanReports)  { $pvTabs['reports'] = ['📊 Reports', null, true]; }
  if ($permCanGallery)  { $pvTabs['gallery'] = ['🖼 Gallery',  null, true]; }

  foreach ($pvTabs as $key => [$label, $badge, $allowed]) {
      if (!$allowed) { continue; }
      $active = ($tab === $key);
      $badgeHtml = $badge ? '<span class="pv-badge' . ($key === 'live' ? ' pv-badge-green' : '') . '">' . (int)$badge . '</span>' : '';
      echo html_writer::link(
          new moodle_url('/mod/learnguard/proctorview.php', ['tab' => $key]),
          $label . $badgeHtml,
          ['class' => 'pv-tab' . ($active ? ' active' : '')]
      );
  }
  ?>
</div>

<!-- ── Content ──────────────────────────────────────────────── -->
<?php

// =====================================================================
// TAB: LIVE MONITOR
// =====================================================================
if ($tab === 'live'):
?>
<!-- Assessment/Course switcher row -->
<div style="display:flex;align-items:center;gap:10px;padding:8px 24px;background:#f8f9fa;border-bottom:1px solid #dee2e6;flex-wrap:wrap;">
  <strong style="font-size:12px;color:#6c757d;">📋 Assessment:</strong>
  <select id="pv-quiz-switcher" style="border:1px solid #dee2e6;border-radius:6px;padding:4px 10px;font-size:12px;max-width:380px;"
      onchange="pvSwitchQuiz(this.value)">
    <option value="">— All assigned assessments —</option>
    <?php foreach ($myAssessments as $asmt): ?>
    <option value="<?php echo (int)($asmt->cmid ?? 0); ?>">
      <?php echo htmlspecialchars(($asmt->coursename ?? '') . ' › ' . ($asmt->name ?? 'Unknown')); ?>
    </option>
    <?php endforeach; ?>
  </select>
  <span style="font-size:11px;color:#adb5bd;">Switch to monitor a specific assessment</span>
  <div style="margin-left:auto;font-size:11px;color:#28a745;font-weight:600;">↺ Auto-refresh: 15s</div>
</div>

<!-- Controls bar -->
<div style="display:flex;align-items:center;gap:8px;padding:10px 24px;background:#fff;border-bottom:1px solid #dee2e6;flex-wrap:wrap;">
  <strong style="font-size:12px;color:#6c757d;">FILTER:</strong>
  <button id="pvf-all"    class="pv-filter-btn active" onclick="pvApplyFilter('all')">All</button>
  <button id="pvf-high"   class="pv-filter-btn" onclick="pvApplyFilter('high')"   style="border-color:#dc3545;color:#dc3545;">⚠️ High Risk</button>
  <button id="pvf-medium" class="pv-filter-btn" onclick="pvApplyFilter('medium')" style="border-color:#fd7e14;color:#fd7e14;">🟠 Medium</button>
  <button id="pvf-active" class="pv-filter-btn" onclick="pvApplyFilter('active')" style="border-color:#28a745;color:#28a745;">🟢 Active</button>
  <div style="margin-left:8px;display:flex;border:1px solid #dee2e6;border-radius:6px;overflow:hidden;">
    <button id="pvv-cam"    onclick="pvApplyView('webcam')"
      style="padding:5px 12px;font-size:12px;font-weight:600;background:#0f6cbf;color:#fff;border:none;cursor:pointer;">📷 Webcam</button>
    <button id="pvv-screen" onclick="pvApplyView('screen')"
      style="padding:5px 12px;font-size:12px;font-weight:600;background:#fff;color:#6c757d;border:none;cursor:pointer;">🖥 Screen</button>
  </div>
  <div style="margin-left:auto;display:flex;align-items:center;gap:10px;">
    <span style="font-size:12px;color:#6c757d;">Per page:</span>
    <select id="pv-perpage" onchange="pvApplyPerPage(parseInt(this.value,10))"
      style="border:1px solid #dee2e6;border-radius:4px;padding:3px 8px;font-size:12px;">
      <option value="9">9</option>
      <option value="15" selected>15</option>
      <option value="24">24</option>
      <option value="36">36</option>
      <option value="60">60</option>
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

<!-- Live grid -->
<div id="pv-live-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;background:#111;padding:12px;min-height:300px;">
  <div style="grid-column:1/-1;display:flex;align-items:center;justify-content:center;color:#555;flex-direction:column;gap:8px;padding:60px;">
    <div style="font-size:36px;">👁</div><p>Loading live sessions…</p>
  </div>
</div>

<!-- Pagination bar -->
<div id="pv-pagination" style="padding:10px 24px;background:#fff;border-top:1px solid #dee2e6;display:none;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;"></div>

<?php
// =====================================================================
// TAB: MY ASSESSMENTS
// =====================================================================
elseif ($tab === 'assessments'):
?>
<div class="pv-content">
  <h3 style="margin:0 0 16px;font-size:18px;">My Assigned Assessments (<?php echo count($myAssessments); ?>)</h3>
  <?php if (empty($myAssessments)): ?>
  <div class="alert alert-info">No assessments assigned to you yet.</div>
  <?php else: ?>
  <div class="pv-asmt-grid">
  <?php foreach ($myAssessments as $asmtId => $asmt):
      $attempts   = $DB->count_records('learnguard_attempts', ['learnguard' => $asmtId]);
      $live       = $DB->count_records('learnguard_attempts', ['learnguard' => $asmtId, 'state' => 'inprogress']);
      $finished   = $DB->count_records('learnguard_attempts', ['learnguard' => $asmtId, 'state' => 'finished']);
      $suspended2 = $DB->count_records('learnguard_attempts', ['learnguard' => $asmtId, 'state' => 'suspended']);
      $now = time();
      $isOpen = (!$asmt->timeopen || $now >= $asmt->timeopen) && (!$asmt->timeclose || $now <= $asmt->timeclose);
  ?>
  <div class="pv-asmt-card">
    <h4><?php echo format_string($asmt->name); ?></h4>
    <div class="pv-course">📚 <?php echo format_string($asmt->coursename); ?></div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;">
      <span style="background:<?php echo $isOpen ? '#28a745' : '#6c757d'; ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">
        <?php echo $isOpen ? '🟢 Open' : '⭕ Closed'; ?>
      </span>
      <?php if ($live > 0): ?>
      <span style="background:#dc3545;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">
        🔴 <?php echo $live; ?> Live
      </span>
      <?php endif; ?>
      <span style="font-size:12px;color:#6c757d;"><?php echo $attempts; ?> total attempts</span>
    </div>
    <div style="font-size:12px;color:#6c757d;margin-bottom:12px;">
      ✅ <?php echo $finished; ?> finished &nbsp;·&nbsp; 🚫 <?php echo $suspended2; ?> suspended
      <?php if ($asmt->timelimit > 0): ?>
      &nbsp;·&nbsp; ⏱ <?php echo round($asmt->timelimit/60); ?>min
      <?php endif; ?>
    </div>
    <div class="pv-actions">
      <a href="<?php echo new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $asmt->cmid]); ?>"
         class="btn btn-sm btn-danger">🔴 Live Dashboard</a>
      <?php if ($permCanReports): ?>
      <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $asmt->cmid, 'tab' => 'attempts']); ?>"
         class="btn btn-sm btn-outline-primary">📋 Attempts</a>
      <?php endif; ?>
      <?php if ($permCanGallery): ?>
      <a href="<?php echo new moodle_url('/mod/learnguard/proctorview.php', ['tab' => 'gallery', 'lgid' => $asmtId]); ?>"
         class="btn btn-sm btn-outline-secondary">🖼 Gallery</a>
      <?php endif; ?>
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
    $hrWhere = '1=1'; $hrParams = [];
    if (!empty($myLgIds)) {
        list($hrInsql, $hrInparams) = $DB->get_in_or_equal($myLgIds, SQL_PARAMS_NAMED);
        $hrWhere  = "a.learnguard $hrInsql AND a.risk_level IN ('high','medium')";
        $hrParams = $hrInparams;
    } else {
        $hrWhere = "1=0";
    }
    $hrAttempts = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.state, a.risk_score, a.risk_level, a.timestart,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}           u  ON u.id = a.userid
           JOIN {learnguard}     lg ON lg.id = a.learnguard
           JOIN {modules}        md ON md.name = 'learnguard'
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = md.id
          WHERE $hrWhere
       ORDER BY a.risk_score DESC LIMIT 200", $hrParams
    );
?>
<div class="pv-content">
  <h3 style="margin:0 0 16px;font-size:18px;">⚠️ High & Medium Risk Attempts</h3>
  <?php if (empty($hrAttempts)): ?>
  <div class="alert alert-success">No medium or high risk attempts found. ✅</div>
  <?php else: ?>
  <div style="overflow-x:auto;">
  <table class="generaltable w-100" style="font-size:13px;">
    <thead>
      <tr>
        <th style="padding:8px 10px;">Student</th>
        <th style="padding:8px 10px;">Assessment</th>
        <th style="padding:8px 10px;">State</th>
        <th style="padding:8px 10px;">Risk</th>
        <th style="padding:8px 10px;">Started</th>
        <th style="padding:8px 10px;">Actions</th>
      </tr>
    </thead>
    <tbody>
    <?php $rcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
    foreach ($hrAttempts as $hr):
        $rc = $rcolours[$hr->risk_level] ?? '#6c757d'; ?>
    <tr>
      <td style="padding:8px 10px;"><strong><?php echo fullname($hr); ?></strong><br><small class="text-muted"><?php echo s($hr->email); ?></small></td>
      <td style="padding:8px 10px;"><?php echo format_string($hr->assessmentname); ?></td>
      <td style="padding:8px 10px;"><?php echo ucfirst($hr->state); ?></td>
      <td style="padding:8px 10px;">
        <span style="background:<?php echo $rc; ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;">
          <?php echo $hr->risk_score; ?> <?php echo strtoupper($hr->risk_level); ?>
        </span>
      </td>
      <td style="padding:8px 10px;"><small><?php echo userdate($hr->timestart, '%d/%m/%y %H:%M'); ?></small></td>
      <td style="padding:8px 10px;white-space:nowrap;">
        <?php echo html_writer::link(new moodle_url('/mod/learnguard/attemptdetail.php', ['id' => $hr->cmid, 'attempt' => $hr->id]), '🔍 Review', ['class' => 'btn btn-sm btn-outline-danger']); ?>
        <?php if ($hr->state === 'inprogress'): echo ' '; echo html_writer::link(new moodle_url('/mod/learnguard/proctordashboard.php', ['id' => $hr->cmid]), '🔴 Live', ['class' => 'btn btn-sm btn-danger']); endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<?php
// =====================================================================
// TAB: SUSPENDED
// =====================================================================
elseif ($tab === 'suspended' && $permCanSuspend):
    $susWhere = '1=0'; $susParams = [];
    if (!empty($myLgIds)) {
        list($susInsql, $susInp) = $DB->get_in_or_equal($myLgIds, SQL_PARAMS_NAMED);
        $susWhere = "a.learnguard $susInsql AND a.state='suspended'";
        $susParams = $susInp;
    }

    // Handle bulk reinstate/confirm.
    if (optional_param('bulksus_action', '', PARAM_ALPHA) && confirm_sesskey()) {
        $bulkAction = optional_param('bulksus_action', '', PARAM_ALPHA);
        $bulkIds    = optional_param_array('sus_ids', [], PARAM_INT);
        if (!empty($bulkIds)) {
            list($bInsql, $bInp) = $DB->get_in_or_equal($bulkIds, SQL_PARAMS_NAMED);
            if ($bulkAction === 'unsuspend') {
                $reinstated = $DB->get_records_list('learnguard_attempts', 'id', $bulkIds, '', 'id, userid');
                $DB->execute("UPDATE {learnguard_attempts} SET state='inprogress' WHERE id $bInsql", $bInp);
                $penalty = (int)(get_config('learnguard', 'score_reinstatement') ?: 0);
                if ($penalty > 0) {
                    foreach ($reinstated as $ra) {
                        \mod_learnguard\attempt_manager::log_event($ra->id, $ra->userid, 'reinstatement',
                            json_encode(['reason' => 'Attempt reinstated by proctor']), 'warning', $penalty);
                    }
                }
                \core\notification::success(count($bulkIds) . ' learner(s) reinstated.');
            } elseif ($bulkAction === 'confirm') {
                $DB->execute("UPDATE {learnguard_attempts} SET is_flagged=1 WHERE id $bInsql", $bInp);
                \core\notification::success(count($bulkIds) . ' suspension(s) confirmed.');
            }
        }
        redirect(new moodle_url('/mod/learnguard/proctorview.php', ['tab' => 'suspended']));
    }

    $susAttempts = $DB->get_records_sql(
        "SELECT a.id, a.userid, a.risk_score, a.risk_level, a.timestart,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, cm.id AS cmid
           FROM {learnguard_attempts} a
           JOIN {user}           u  ON u.id = a.userid
           JOIN {learnguard}     lg ON lg.id = a.learnguard
           JOIN {modules}        md ON md.name = 'learnguard'
           JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = md.id
          WHERE $susWhere
       ORDER BY a.timestart DESC", $susParams
    );
?>
<div class="pv-content">
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px;">
    <h3 style="margin:0;">🚫 Suspended Students (<?php echo count($susAttempts); ?>)</h3>
    <?php if (!empty($susAttempts)): ?>
    <div style="display:flex;gap:8px;">
        <button type="button" class="btn btn-sm btn-outline-secondary"
            onclick="document.querySelectorAll('.sus-chk').forEach(c=>c.checked=true);">☑ Select All</button>
        <button type="button" class="btn btn-sm btn-success"
            onclick="susBulkAction('unsuspend')">✅ Reinstate Selected</button>
        <button type="button" class="btn btn-sm btn-danger"
            onclick="susBulkAction('confirm')">🚫 Confirm Suspension</button>
    </div>
    <?php endif; ?>
  </div>
  <?php if (empty($susAttempts)): ?>
  <div class="alert alert-success">No suspended students — all clear. ✅</div>
  <?php else: ?>
  <form method="post" action="" id="sus-bulk-form">
    <input type="hidden" name="sesskey" value="<?php echo sesskey(); ?>">
    <input type="hidden" name="bulksus_action" id="sus-action-field" value="">
    <div style="overflow-x:auto;"><table class="generaltable w-100" style="font-size:13px;">
    <thead><tr>
      <th style="width:30px;padding:8px 10px;"><input type="checkbox" onchange="document.querySelectorAll('.sus-chk').forEach(c=>c.checked=this.checked);" style="width:15px;height:15px;"></th>
      <th style="padding:8px 10px;">Student</th><th style="padding:8px 10px;">Assessment</th>
      <th style="padding:8px 10px;">Risk</th><th style="padding:8px 10px;">Since</th><th style="padding:8px 10px;">Actions</th>
    </tr></thead><tbody>
    <?php $rcolours = ['low' => '#28a745', 'medium' => '#fd7e14', 'high' => '#dc3545'];
    foreach ($susAttempts as $sus):
        $rc = $rcolours[$sus->risk_level ?? 'low'] ?? '#6c757d'; ?>
    <tr>
      <td style="padding:8px 10px;text-align:center;">
        <input type="checkbox" name="sus_ids[]" class="sus-chk" value="<?php echo $sus->id; ?>" style="width:15px;height:15px;">
      </td>
      <td style="padding:8px 10px;"><strong><?php echo fullname($sus); ?></strong><br><small class="text-muted"><?php echo s($sus->email); ?></small></td>
      <td style="padding:8px 10px;"><?php echo format_string($sus->assessmentname); ?></td>
      <td style="padding:8px 10px;"><span style="background:<?php echo $rc; ?>;color:#fff;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:700;"><?php echo ($sus->risk_score??0); ?></span></td>
      <td style="padding:8px 10px;"><small><?php echo userdate($sus->timestart,'%d/%m/%y %H:%M'); ?></small></td>
      <td style="padding:8px 10px;white-space:nowrap;">
        <?php echo html_writer::link(new moodle_url('/mod/learnguard/attemptdetail.php',['id'=>$sus->cmid,'attempt'=>$sus->id]),'🔍',['class'=>'btn btn-sm btn-outline-secondary','title'=>'View detail']); ?>
        <form method="post" action="" style="display:inline;">
          <?php echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sesskey','value'=>sesskey()]); ?>
          <?php echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'bulksus_action','value'=>'unsuspend']); ?>
          <?php echo html_writer::empty_tag('input',['type'=>'hidden','name'=>'sus_ids[]','value'=>$sus->id]); ?>
          <button type="submit" class="btn btn-sm btn-success" onclick="return confirm('Reinstate this student?')">▶ Reinstate</button>
        </form>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  </form>
  <?php endif; ?>
</div>
<script>
function susBulkAction(action) {
    var n = document.querySelectorAll('.sus-chk:checked').length;
    if (!n) { alert('Select at least one learner first.'); return; }
    if (!confirm(action === 'unsuspend' ? 'Reinstate ' + n + ' selected learner(s)?' : 'Confirm suspension for ' + n + ' learner(s)?')) { return; }
    document.getElementById('sus-action-field').value = action;
    document.getElementById('sus-bulk-form').submit();
}
</script>

<?php
// =====================================================================
// TAB: PRECHECK ACTIVITY / FACE APPROVALS
// =====================================================================
elseif ($tab === 'precheck'):
    // Show all precheck sessions started today (no 15-min cutoff).
    $precheckSessions = [];
    try {
        $startOfDay = mktime(0, 0, 0); // Midnight today
        $pcsql = '1=1'; $pcparams = [];
        if (!empty($myCmIds)) {
            list($pcInsql, $pcInp) = $DB->get_in_or_equal(array_values($myCmIds), SQL_PARAMS_NAMED);
            $pcsql = "ps.cmid $pcInsql AND ps.timecreated > :since";
            $pcparams = array_merge($pcInp, ['since' => $startOfDay]);
        } else { $pcsql = '1=0'; }
        $precheckSessions = $DB->get_records_sql(
            "SELECT ps.id, ps.cmid, ps.userid, ps.current_step, ps.step_number, ps.total_steps,
                    ps.timecreated, ps.timemodified,
                    u.firstname, u.lastname, u.email,
                    lg.name AS assessmentname
               FROM {learnguard_precheck_sessions} ps
               JOIN {user}           u  ON u.id = ps.userid
               JOIN {course_modules} cm ON cm.id = ps.cmid
               JOIN {learnguard}     lg ON lg.id = cm.instance
              WHERE $pcsql
           ORDER BY ps.timemodified DESC",
            $pcparams
        );
    } catch (\Exception $e) {}

    // Get pending face approvals.
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
                    u.firstname, u.lastname, lg.name AS assessmentname
               FROM {learnguard_face_approvals} fa
               JOIN {user}           u  ON u.id = fa.userid
               JOIN {course_modules} cm ON cm.id = fa.cmid
               JOIN {learnguard}     lg ON lg.id = cm.instance
              WHERE $faWhere
           ORDER BY fa.timecreated DESC",
            $faParams
        );
    } catch (\Exception $e) {}

    // Human-readable step labels.
    $stepLabels = ['welcome'=>'Welcome','closetabs'=>'Close Other Tabs','browser'=>'Browser Check',
        'internet'=>'Internet Speed','camera'=>'Camera Setup','audio'=>'Microphone Check',
        'photoid'=>'Photo ID Upload','face'=>'Face Match','screenshare'=>'Screen Sharing',
        'keystroke'=>'Typing Baseline','monitor'=>'Monitor Check',
        'environment'=>'360° Recording','privacy'=>'Privacy Agreement','fullscreen'=>'Fullscreen',
        'ready'=>'✅ Ready to Start'];
?>
<div class="pv-content">

  <!-- Pending face approvals banner -->
  <?php if (!empty($pendingApprovals)): ?>
  <div style="background:#fff3cd;border:2px solid #ffc107;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
    <span style="font-size:24px;">⚠️</span>
    <div style="flex:1;">
      <strong style="font-size:14px;color:#856404;"><?php echo count($pendingApprovals); ?> student(s) waiting for face match approval</strong>
      <p style="margin:3px 0 0;font-size:13px;color:#6c757d;">These students cannot start their exam until you review and approve.</p>
    </div>
    <a href="<?php echo new moodle_url('/mod/learnguard/face_approvals.php'); ?>"
       class="btn btn-warning" style="font-weight:700;flex-shrink:0;">
      👤 Review &amp; Approve (<?php echo count($pendingApprovals); ?>)
    </a>
  </div>
  <?php endif; ?>

  <!-- Live precheck activity header -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px;">
    <h3 style="margin:0;font-size:17px;">🔍 Precheck Activity — Today
      <span style="background:#0f6cbf;color:#fff;border-radius:12px;padding:2px 10px;font-size:13px;margin-left:8px;"><?php echo count($precheckSessions); ?></span>
    </h3>
    <div style="display:flex;align-items:center;gap:10px;">
      <small style="color:#6c757d;">All students who started precheck today. Auto-refreshes every 20s.</small>
      <button onclick="location.reload()" class="btn btn-sm btn-outline-secondary">🔄 Refresh Now</button>
    </div>
  </div>

  <?php if (empty($precheckSessions) && empty($pendingApprovals)): ?>
  <div class="alert alert-info">
    <strong>No students have started precheck today.</strong><br>
    When students open the pre-exam wizard, their progress will appear here in real time.
  </div>
  <?php else: ?>

  <?php if (!empty($precheckSessions)): ?>
  <!-- Status summary row -->
  <?php
  $readyCount     = count(array_filter((array)$precheckSessions, fn($p) => $p->current_step === 'ready'));
  $waitingCount   = count(array_filter((array)$precheckSessions, fn($p) => in_array($p->current_step, ['face','photoid'])));
  $inProgressCount = count($precheckSessions) - $readyCount - $waitingCount;
  ?>
  <div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
    <span style="background:#28a745;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;">✅ <?php echo $readyCount; ?> Ready</span>
    <span style="background:#0f6cbf;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;">🔄 <?php echo $inProgressCount; ?> In Progress</span>
    <?php if ($waitingCount > 0): ?>
    <span style="background:#fd7e14;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;font-weight:700;">⚠️ <?php echo $waitingCount; ?> Needs Review</span>
    <?php endif; ?>
  </div>

  <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:12px;margin-bottom:24px;">
  <?php foreach ($precheckSessions as $ps):
      $elapsed     = max(0, time() - (int)$ps->timecreated);
      $lastSeen    = max(0, time() - (int)$ps->timemodified);
      $pct         = ($ps->total_steps > 0) ? round(($ps->step_number / $ps->total_steps) * 100) : 0;
      $stepLabel   = $stepLabels[$ps->current_step] ?? ucwords(str_replace('_',' ',$ps->current_step));
      $needsAction = in_array($ps->current_step, ['face', 'photoid']);
      $isDone      = $ps->current_step === 'ready';
      $isStale     = $lastSeen > 300; // 5 min no update = stale
      $cardBorder  = $isDone ? '#28a745' : ($needsAction ? '#fd7e14' : ($isStale ? '#adb5bd' : '#0f6cbf'));
      $statusIcon  = $isDone ? '✅' : ($needsAction ? '⚠️' : ($isStale ? '⏸' : '🔄'));
  ?>
  <div style="background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.08);overflow:hidden;border-left:5px solid <?php echo $cardBorder; ?>;">
    <div style="padding:12px 14px;">
      <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
        <div>
          <div style="font-weight:700;font-size:14px;"><?php echo fullname($ps); ?></div>
          <div style="font-size:11px;color:#6c757d;"><?php echo s($ps->email); ?></div>
        </div>
        <span style="background:<?php echo $cardBorder; ?>;color:#fff;font-size:11px;font-weight:700;padding:3px 8px;border-radius:10px;flex-shrink:0;margin-left:8px;">
          <?php echo $statusIcon; ?> <?php echo $isDone ? 'Ready' : ($needsAction ? 'Needs Review' : ($isStale ? 'Paused' : 'Active')); ?>
        </span>
      </div>
      <div style="font-size:12px;color:#495057;margin-bottom:8px;">📋 <?php echo format_string($ps->assessmentname); ?></div>
      <div style="font-size:12px;margin-bottom:6px;">
        <strong>Current step:</strong>
        <span style="background:<?php echo $isDone ? '#d4edda' : ($needsAction ? '#fff3cd' : '#e3f2fd'); ?>;border-radius:4px;padding:2px 8px;font-weight:600;">
          Step <?php echo $ps->step_number; ?>/<?php echo $ps->total_steps; ?>: <?php echo htmlspecialchars($stepLabel); ?>
        </span>
      </div>
      <div style="background:#e9ecef;border-radius:6px;height:10px;overflow:hidden;margin-bottom:8px;">
        <div style="height:100%;width:<?php echo $pct; ?>%;background:<?php echo $cardBorder; ?>;border-radius:6px;transition:width .3s;"></div>
      </div>
      <div style="display:flex;justify-content:space-between;font-size:11px;color:#adb5bd;">
        <span>Started <?php echo $elapsed < 60 ? 'just now' : floor($elapsed/60) . 'min ago'; ?></span>
        <span>Last seen: <?php echo $lastSeen < 60 ? 'just now' : floor($lastSeen/60) . 'min ago'; ?></span>
      </div>
    </div>
    <?php if ($needsAction): ?>
    <div style="padding:8px 14px;background:#fff3cd;border-top:1px solid #ffc107;font-size:12px;color:#856404;display:flex;align-items:center;justify-content:space-between;">
      <span>⚠️ Waiting for face approval</span>
      <a href="<?php echo new moodle_url('/mod/learnguard/face_approvals.php',['id'=>$ps->cmid]); ?>" class="btn btn-sm btn-warning" style="font-size:11px;padding:3px 10px;font-weight:700;">Review →</a>
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
  <?php endif; ?>

  <script>
  // Auto-refresh precheck tab every 20 seconds.
  setTimeout(function() { location.reload(); }, 20000);
  </script>
</div>

<?php
// =====================================================================
// TAB: REPORTS
// =====================================================================
elseif ($tab === 'reports' && $permCanReports):
    $lgid = optional_param('lgid', 0, PARAM_INT);
?>
<div class="pv-content">
  <h3 style="margin:0 0 16px;">📊 Reports — My Assessments</h3>
  <div class="pv-asmt-grid">
  <?php foreach ($myAssessments as $asmtId => $asmt):
      $fin2 = $DB->count_records('learnguard_attempts', ['learnguard' => $asmtId, 'state' => 'finished']);
      $live2 = $DB->count_records('learnguard_attempts', ['learnguard' => $asmtId, 'state' => 'inprogress']);
  ?>
  <div class="pv-asmt-card">
    <h4><?php echo format_string($asmt->name); ?></h4>
    <div class="pv-course">📚 <?php echo format_string($asmt->coursename); ?></div>
    <div style="font-size:12px;color:#6c757d;margin-bottom:12px;">
      ✅ <?php echo $fin2; ?> finished &nbsp; 🟢 <?php echo $live2; ?> live
    </div>
    <div class="pv-actions">
      <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $asmt->cmid, 'tab' => 'attempts']); ?>"
         class="btn btn-sm btn-outline-primary">📋 View Attempts</a>
      <a href="<?php echo new moodle_url('/mod/learnguard/export_scores.php', ['id' => $asmt->cmid, 'format' => 'csv', 'sesskey' => sesskey()]); ?>"
         class="btn btn-sm btn-outline-success">⬇ Export CSV</a>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<?php
// =====================================================================
// TAB: GALLERY
// =====================================================================
elseif ($tab === 'gallery' && $permCanGallery):
    $filterLgid = optional_param('lgid', 0, PARAM_INT);
    $filterType = optional_param('type', '', PARAM_ALPHANUMEXT);

    // Handle bulk delete.
    $action = optional_param('action', '', PARAM_ALPHA);
    if ($action === 'delete' && confirm_sesskey()) {
        $delIds = optional_param_array('delids', [], PARAM_INT);
        if (!empty($delIds)) {
            foreach ($delIds as $delId) {
                $snap = $DB->get_record('learnguard_snapshots', ['id' => $delId]);
                if ($snap && in_array($snap->cmid ?? 0, array_values($myCmIds))) {
                    $DB->delete_records('learnguard_snapshots', ['id' => $delId]);
                }
            }
            redirect(new moodle_url('/mod/learnguard/proctorview.php',['tab'=>'gallery','lgid'=>$filterLgid,'type'=>$filterType]),
                count($delIds) . ' item(s) deleted.', 2);
        }
    }
    $galWhere   = '1=1'; $galParams = [];
    // Gallery query: LEFT JOIN so precheck recordings (attemptid=0) are included.
    // Filter by users who have any attempt in this proctor's assessments.
    $galSnaps = [];
    if (!empty($myLgIds)) {
        $lgidFilter = $filterLgid > 0 ? [$filterLgid] : $myLgIds;
        list($gInsql, $gInp) = $DB->get_in_or_equal($lgidFilter, SQL_PARAMS_NAMED, 'glgid');
        $typeWhere = $filterType ? ' AND s.snapshot_type = :gtype' : '';
        $typeParam = $filterType ? ['gtype' => $filterType] : [];
        // Get all userids who have attempts in the proctor's assessments.
        list($uInsql, $uInp) = $DB->get_in_or_equal($lgidFilter, SQL_PARAMS_NAMED, 'ugid');
        $galSnaps = $DB->get_records_sql(
            "SELECT s.id, s.snapshot_type, s.timemodified, u.firstname, u.lastname,
                    COALESCE(lg.name, '(Precheck Recording)') AS assessmentname,
                    COALESCE(cm.id, 0) AS cmid, s.attemptid
               FROM {learnguard_snapshots} s
               JOIN {user}                u  ON u.id = s.userid
               LEFT JOIN {learnguard_attempts} a  ON a.id = s.attemptid AND s.attemptid > 0
               LEFT JOIN {learnguard}     lg ON lg.id = a.learnguard
               LEFT JOIN {modules}        md ON md.name = 'learnguard'
               LEFT JOIN {course_modules} cm ON cm.instance = lg.id AND cm.module = md.id
              WHERE s.userid IN (
                  SELECT DISTINCT userid FROM {learnguard_attempts} WHERE learnguard $uInsql
              ) $typeWhere
           ORDER BY s.timemodified DESC",
            array_merge($uInp, $typeParam), 0, 60
        );
    }
    $typeLabels = ['periodic'=>'📸','photo_id'=>'🪪','360_video'=>'🎥','face'=>'👤','screen_capture'=>'🖥','random_screenshot'=>'🎲','object_cell_phone'=>'📱','background_change'=>'🖼','multiple_persons'=>'👥'];
?>
<div class="pv-content">
  <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
    <div>
      <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Assessment</label>
      <select class="form-control" style="max-width:240px;" onchange="location.href=this.value">
        <option value="<?php echo new moodle_url('/mod/learnguard/proctorview.php',['tab'=>'gallery','type'=>$filterType]); ?>">— All Assessments —</option>
        <?php foreach ($myAssessments as $aid => $a): ?>
        <option value="<?php echo new moodle_url('/mod/learnguard/proctorview.php',['tab'=>'gallery','lgid'=>$aid,'type'=>$filterType]); ?>"
                <?php echo $filterLgid == $aid ? 'selected' : ''; ?>><?php echo format_string($a->name); ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="font-size:12px;font-weight:700;display:block;margin-bottom:4px;">Type</label>
      <select class="form-control" style="max-width:200px;" onchange="location.href=this.value">
        <option value="<?php echo new moodle_url('/mod/learnguard/proctorview.php',['tab'=>'gallery','lgid'=>$filterLgid]); ?>">All types</option>
        <?php foreach (['periodic'=>'Webcam','photo_id'=>'Photo ID','360_video'=>'360° Video','screen_capture'=>'Screen Capture','random_screenshot'=>'Random Screenshot','object_cell_phone'=>'Phone Detected'] as $tv=>$tl): ?>
        <option value="<?php echo new moodle_url('/mod/learnguard/proctorview.php',['tab'=>'gallery','lgid'=>$filterLgid,'type'=>$tv]); ?>"
                <?php echo $filterType===$tv?'selected':''; ?>><?php echo $tl; ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <small style="color:#6c757d;align-self:flex-end;"><?php echo count($galSnaps); ?> items</small>
  <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
    <label style="font-size:12px;cursor:pointer;display:flex;align-items:center;gap:5px;">
      <input type="checkbox" id="lg-gal-sel-all" onchange="lgGalSelAll(this.checked)" style="width:14px;height:14px;"> Select All
    </label>
    <button onclick="lgGalDeleteSelected()" class="btn btn-sm btn-danger" id="lg-gal-del-btn" style="display:none;">🗑 Delete Selected (<span id="lg-gal-sel-count">0</span>)</button>
  </div>
  </div>
  <?php if (empty($galSnaps)): ?>
  <div class="alert alert-info">No media found for the selected filter.</div>
  <?php else: ?>
  <div id="lg-gal-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">
  <?php foreach ($galSnaps as $gs):
      $src = (new moodle_url('/mod/learnguard/snapshot.php',['id'=>$gs->id,'sesskey'=>sesskey()]))->out(false);
      $isVid = $gs->snapshot_type === '360_video';
      $icon = $typeLabels[$gs->snapshot_type] ?? '📷';
      $studentName = htmlspecialchars(fullname((object)['firstname'=>$gs->firstname,'lastname'=>$gs->lastname]), ENT_QUOTES);
  ?>
  <div class="lg-gal-item" data-id="<?php echo $gs->id; ?>"
       style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 6px rgba(0,0,0,.1);position:relative;">
    <label style="position:absolute;top:6px;left:6px;z-index:2;cursor:pointer;">
      <input type="checkbox" class="lg-gal-chk" data-id="<?php echo $gs->id; ?>"
             style="width:16px;height:16px;accent-color:#dc3545;"
             onchange="lgGalChkChanged()">
    </label>
    <?php if ($isVid): ?>
    <video src="<?php echo $src; ?>" controls style="width:100%;height:120px;object-fit:cover;background:#111;"></video>
    <?php else: ?>
    <img src="<?php echo $src; ?>"
         style="width:100%;height:120px;object-fit:cover;cursor:zoom-in;"
         loading="lazy"
         onclick="pvLbOpen('<?php echo $src; ?>','<?php echo $studentName; ?>')">
    <?php endif; ?>
    <div style="padding:6px 8px;">
      <div style="font-size:11px;font-weight:600;"><?php echo $icon; ?> <?php echo htmlspecialchars(fullname((object)['firstname'=>$gs->firstname,'lastname'=>$gs->lastname])); ?></div>
      <div style="font-size:10px;color:#adb5bd;"><?php echo userdate($gs->timemodified,'%d/%m/%y %H:%M'); ?></div>
      <div style="font-size:10px;color:#6c757d;"><?php echo format_string($gs->assessmentname); ?></div>
    </div>
  </div>
  <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>
<script>
function lgGalChkChanged() {
    var checked = document.querySelectorAll('.lg-gal-chk:checked').length;
    var delBtn = document.getElementById('lg-gal-del-btn');
    var cntEl  = document.getElementById('lg-gal-sel-count');
    if (delBtn) { delBtn.style.display = checked > 0 ? 'inline-block' : 'none'; }
    if (cntEl)  { cntEl.textContent = checked; }
}
function lgGalSelAll(checked) {
    document.querySelectorAll('.lg-gal-chk').forEach(function(c) { c.checked = checked; });
    lgGalChkChanged();
}
function lgGalDeleteSelected() {
    var ids = Array.from(document.querySelectorAll('.lg-gal-chk:checked')).map(function(c) { return c.dataset.id; });
    if (!ids.length) { return; }
    if (!confirm('Delete ' + ids.length + ' selected item(s)? This cannot be undone.')) { return; }
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = '<?php echo new moodle_url('/mod/learnguard/proctorview.php',['tab'=>'gallery','lgid'=>$filterLgid,'type'=>$filterType,'action'=>'delete']); ?>';
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

<!-- Lightbox -->
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
var primary  = <?php echo json_encode($brand->primary); ?>;
var rcolours = {low:'#dc3545', medium:'#fd7e14', high:'#dc3545'};
var cmids    = <?php echo json_encode(array_values($myCmIds)); ?>;

// ── State ──────────────────────────────────────────────────────────────────
var ALL_SESSIONS  = [];
var sessionCache  = {};
var cardView      = {};      // per-card userid: 'webcam'|'screen'
var globalView    = 'screen'; // Screen is default; proctor can switch to webcam
var currentFilter = 'all';
var currentCols   = 3;
var currentPerPage = 15;
var currentPage   = 0;

// ── WS helper ──────────────────────────────────────────────────────────────
function ws(method, args, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', wwwroot + '/lib/ajax/service.php?sesskey=' + sk, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function() {
        try { var r = JSON.parse(xhr.responseText); if(r&&r[0]&&r[0].data) cb(r[0].data); } catch(e){}
    };
    xhr.send(JSON.stringify([{index:0, methodname:method, args:args}]));
}

function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── Filter ─────────────────────────────────────────────────────────────────
function getFilteredSessions() {
    var now = Math.floor(Date.now()/1000);
    return ALL_SESSIONS.filter(function(s) {
        if (currentFilter === 'high')   return s.risk_level === 'high';
        if (currentFilter === 'medium') return s.risk_level === 'high' || s.risk_level === 'medium';
        if (currentFilter === 'active') return (now - (s.lastseen||0)) < 120;
        return true;
    });
}

window.pvApplyFilter = function(f) {
    currentFilter = f;
    currentPage   = 0;
    ['all','high','medium','active'].forEach(function(k) {
        var btn = document.getElementById('pvf-' + k);
        if (btn) btn.classList.toggle('active', k === f);
    });
    renderPage();
};

// ── Grid columns ───────────────────────────────────────────────────────────
window.pvApplyGrid = function(n) {
    currentCols = n;
    currentPage = 0;
    var grid = document.getElementById('pv-live-grid');
    if (grid) { grid.style.gridTemplateColumns = 'repeat(' + n + ',1fr)'; }
    document.querySelectorAll('.pv-grid-btn').forEach(function(b) {
        b.classList.toggle('active', parseInt(b.dataset.cols, 10) === n);
    });
    renderPage();
};

// ── Per-page ───────────────────────────────────────────────────────────────
window.pvApplyPerPage = function(n) {
    currentPerPage = n;
    currentPage    = 0;
    renderPage();
};

// ── View toggle (webcam / screen) — global default ─────────────────────────
window.pvApplyView = function(mode) {
    globalView = mode;
    ['cam','screen'].forEach(function(k) {
        var btn = document.getElementById('pvv-' + k);
        if (!btn) return;
        var isActive = (k === 'cam' && mode === 'webcam') || (k === 'screen' && mode === 'screen');
        btn.style.background = isActive ? primary : '#fff';
        btn.style.color      = isActive ? '#fff'  : '#6c757d';
    });
    renderPage();
};

// ── Per-card toggle (switch individual candidate's view) ───────────────────
window.pvToggleCardView = function(uid) {
    var cur = cardView[uid] || globalView;
    cardView[uid] = cur === 'screen' ? 'webcam' : 'screen';
    renderPage();
};

// ── Build one card's inner HTML ────────────────────────────────────────────
function buildCardHtml(s) {
    var uid     = s.userid;
    var aid     = s.attemptid;
    var view    = cardView[uid] || globalView;
    var snap    = (view === 'screen') ? (s.screen_snap || s.webcam_snap) : (s.webcam_snap || s.screen_snap);
    var snapSrc = snap ? (snap.startsWith('http') ? snap : 'data:image/jpeg;base64,' + snap) : null;
    var ago     = Math.floor(Date.now()/1000) - (s.lastseen||0);
    var online  = ago < 120;
    var dotAnim = online ? 'animation:lg-pulse 1.5s infinite;' : '';
    var dotCol  = online ? '#28a745' : '#dc3545';
    var rc      = rcolours[s.risk_level] || '#6c757d';
    var agoStr  = ago < 60 ? 'Now' : Math.floor(ago/60) + 'm';

    var thumbHtml = snapSrc
        ? '<img style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;cursor:zoom-in;" ' +
          'src="' + snapSrc + '" onclick="pvLbOpen(\'' + snapSrc.replace(/'/g,"\\'") + '\',\'' + esc(s.fullname) + '\')" title="Click to enlarge">'
        : '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;flex-direction:column;color:#444;font-size:12px;gap:4px;">' +
          '<span style="font-size:22px;">' + (view==='screen'?'🖥':'📷') + '</span>' +
          '<span>' + (view==='screen'?'No screen':'No webcam') + '</span></div>';

    return '<div style="position:relative;width:100%;padding-top:75%;background:#111;">' +
        thumbHtml +
        '<div style="position:absolute;top:0;left:0;right:0;padding:4px 6px;display:flex;justify-content:space-between;background:linear-gradient(to bottom,rgba(0,0,0,.7),transparent);">' +
        '<span style="width:8px;height:8px;border-radius:50%;background:' + dotCol + ';display:inline-block;' + dotAnim + '"></span>' +
        '<span style="font-size:9px;font-weight:700;color:#fff;background:' + rc + ';padding:1px 5px;border-radius:6px;animation:lg-pulse 1s infinite;">' +
        (s.risk_score||0) + ' ' + (s.risk_level||'').toUpperCase() + '</span>' +
        '</div>' +
        (s.alert_count > 0 ? '<span style="position:absolute;top:6px;left:22px;background:#dc3545;color:#fff;border-radius:50%;width:18px;height:18px;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;">' + s.alert_count + '</span>' : '') +
        '<button onclick="pvToggleCardView(' + uid + ')" style="position:absolute;bottom:4px;right:4px;font-size:10px;background:rgba(0,0,0,.6);color:#fff;border:none;border-radius:4px;padding:2px 6px;cursor:pointer;">' +
        (view==='screen'?'📷 Cam':'🖥 Screen') + '</button>' +
        '</div>' +
        '<div style="padding:5px 8px;background:#16213e;color:#ddd;font-size:12px;display:flex;justify-content:space-between;">' +
        '<span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:120px;font-weight:700;">' + esc(s.fullname) + '</span>' +
        '<span style="font-size:10px;color:#888;">Q' + (s.currentpage||'?') + ' · ' + agoStr + '</span>' +
        '</div>';
}

// ── Render the current page of the filtered set ────────────────────────────
function renderPage() {
    var grid = document.getElementById('pv-live-grid');
    var pgBar = document.getElementById('pv-pagination');
    var badge = document.getElementById('pv-count-badge');
    if (!grid) return;

    var filtered = getFilteredSessions();
    var total    = filtered.length;
    var pages    = Math.max(1, Math.ceil(total / currentPerPage));
    currentPage  = Math.min(currentPage, pages - 1);

    var start = currentPage * currentPerPage;
    var slice = filtered.slice(start, start + currentPerPage);

    // Apply grid column count via inline style (reliable, no CSS specificity issues).
    grid.style.gridTemplateColumns = 'repeat(' + currentCols + ',1fr)';

    if (badge) {
        badge.textContent = total + ' candidate' + (total !== 1 ? 's' : '') +
            (total > currentPerPage ? ' · Page ' + (currentPage+1) + '/' + pages : '');
    }

    if (!total) {
        grid.innerHTML =
            '<div style="grid-column:1/-1;display:flex;align-items:center;justify-content:center;color:#555;flex-direction:column;gap:8px;padding:60px;">' +
            '<div style="font-size:36px;">👁</div><p>' +
            (ALL_SESSIONS.length ? 'No sessions match the current filter.' : 'No active sessions right now.') +
            '</p></div>';
        if (pgBar) pgBar.style.display = 'none';
        return;
    }

    // Re-render all cards for this page.
    grid.innerHTML = '';
    slice.forEach(function(s) {
        sessionCache[s.attemptid] = s;
        var card = document.createElement('div');
        card.id             = 'pvc-' + s.attemptid;
        card.dataset.attemptid = s.attemptid;
        card.style.cssText  = 'border-radius:8px;overflow:hidden;background:#1a1a2e;position:relative;' +
            (s.risk_level==='high' ? 'box-shadow:0 0 0 2px #dc3545;' :
             s.risk_level==='medium' ? 'box-shadow:0 0 0 2px #fd7e14;' : '');
        card.innerHTML = buildCardHtml(s);
        grid.appendChild(card);
    });

    // Pagination bar.
    if (pgBar) {
        if (pages <= 1) { pgBar.style.display = 'none'; return; }
        pgBar.style.display = 'flex';
        pgBar.innerHTML = '';
        function pgBtn(lbl, page, disabled) {
            var b = document.createElement('button');
            b.textContent = lbl;
            b.disabled    = disabled;
            b.style.cssText = 'padding:5px 12px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;' +
                'border:1px solid #dee2e6;background:' + (page===currentPage ? primary : '#fff') + ';' +
                'color:' + (page===currentPage ? '#fff' : '#495057') + ';';
            if (!disabled) {
                b.addEventListener('click', function() { currentPage = page; renderPage(); });
            }
            return b;
        }
        pgBar.appendChild(pgBtn('‹', currentPage-1, currentPage===0));
        for (var p = 0; p < pages; p++) {
            pgBar.appendChild(pgBtn(p+1, p, false));
        }
        pgBar.appendChild(pgBtn('›', currentPage+1, currentPage>=pages-1));
    }
}

// ── Fetch all sessions from all assigned assessment cmids ─────────────────
function fetchLiveSessions() {
    if (!cmids.length) {
        ALL_SESSIONS = [];
        renderPage();
        updateStats(0);
        return;
    }
    var pending = cmids.length;
    var merged  = [];
    var suspendedTotal = 0;
    cmids.forEach(function(cmid) {
        ws('mod_learnguard_get_live_sessions', {cmid: cmid}, function(data) {
            (data.sessions || []).forEach(function(s) { merged.push(s); });
            suspendedTotal += (data.suspended_count || 0);
            if (--pending === 0) {
                // Deduplicate by USERID — one card per learner, keep highest-risk session.
                var byUser = {};
                merged.forEach(function(s) {
                    var uid = s.userid;
                    if (!byUser[uid] || (s.risk_score||0) > (byUser[uid].risk_score||0) ||
                            (s.lastseen||0) > (byUser[uid].lastseen||0)) {
                        byUser[uid] = s;
                    }
                });
                ALL_SESSIONS = Object.keys(byUser).map(function(k) { return byUser[k]; });
                renderPage();
                updateStats(suspendedTotal);
            }
        });
    });
}

// ── Keep the top stat pills in sync with the live grid below ──────────────
function updateStats(suspendedTotal) {
    var liveEl = document.getElementById('pv-stat-live');
    var highEl = document.getElementById('pv-stat-highrisk');
    var susEl  = document.getElementById('pv-stat-suspended');
    if (liveEl) { liveEl.textContent = ALL_SESSIONS.length; }
    if (highEl) { highEl.textContent = ALL_SESSIONS.filter(function(s) { return s.risk_level === 'high'; }).length; }
    if (susEl)  { susEl.textContent  = suspendedTotal; }
}

// ── Lightbox ───────────────────────────────────────────────────────────────
window.pvLbOpen = function(src, cap) {
    var el = document.getElementById('pv-lb');
    var im = document.getElementById('pv-lb-img');
    var cp = document.getElementById('pv-lb-cap');
    if (el) el.style.display = 'flex';
    if (im) im.src = src;
    if (cp) cp.textContent = cap || '';
};
window.pvLbClose = function() {
    var el = document.getElementById('pv-lb');
    var im = document.getElementById('pv-lb-img');
    if (el) el.style.display = 'none';
    if (im) im.src = '';
};

// ── Init: apply defaults then start polling ────────────────────────────────
<?php if ($tab === 'live'): ?>
// Set grid to default 3-cols inline so it works immediately (no CSS class dependency).
(function() {
    var g = document.getElementById('pv-live-grid');
    if (g) g.style.gridTemplateColumns = 'repeat(3,1fr)';
})();

// Set screen as default in UI.
(function(){
    var cam=document.getElementById('pvv-cam');var scr=document.getElementById('pvv-screen');
    if(cam){cam.style.background='#fff';cam.style.color='#6c757d';}
    if(scr){scr.style.background=primary;scr.style.color='#fff';}
})();

// Assessment/course switcher.
var allCmids = cmids.slice();
window.pvSwitchQuiz = function(cmid) {
    cmid = parseInt(cmid, 10);
    cmids = cmid > 0 ? [cmid] : allCmids.slice();
    ALL_SESSIONS = []; currentPage = 0;
    fetchLiveSessions();
};

fetchLiveSessions();
setInterval(fetchLiveSessions, 15000); // Refresh every 15 seconds.
<?php endif; ?>

})(); // end IIFE
</script>

<?php echo $OUTPUT->footer();
