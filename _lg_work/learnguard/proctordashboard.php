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
 * Live proctor dashboard — redesigned grid view.
 *
 * Shows webcam/screen thumbnails for each active candidate.
 * Proctor can toggle between webcam and screen-capture views
 * for individual candidates or all candidates at once.
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
$context = context_module::instance($cm->id);
require_capability('mod/learnguard:liveproctor', $context);

$PAGE->set_url('/mod/learnguard/proctordashboard.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_title('Live Proctor — ' . format_string($instance->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->requires->css('/mod/learnguard/styles.css');

$brand = learnguard_brand();

// Break requests awaiting approval.
$breakRequests = $DB->get_records_sql(
    "SELECT a.id AS attemptid, u.firstname, u.lastname, a.break_requested
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.learnguard = :lgid AND a.break_requested > 0 AND a.break_approved = 0",
    ['lgid' => $instance->id]
);

// Summary counts.
$totalActive    = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'inprogress']);
$totalSuspended = $DB->count_records('learnguard_attempts', ['learnguard' => $instance->id, 'state' => 'suspended']);
$totalHighRisk  = (int)$DB->get_field_sql(
    "SELECT COUNT(*) FROM {learnguard_attempts} WHERE learnguard = ? AND state='inprogress' AND risk_level='high'",
    [$instance->id]
);

echo $OUTPUT->header();
echo learnguard_nav_bar($id, 'Live Dashboard');
?>

<style>
/* ── Dashboard layout ─────────────────────────────────── */
.lg-pd-shell { display:flex; gap:0; height:calc(100vh - 120px); overflow:hidden; font-family:"Segoe UI",system-ui,sans-serif; }
.lg-pd-main  { flex:1; display:flex; flex-direction:column; overflow:hidden; min-width:0; }
.lg-pd-side  { width:320px; flex-shrink:0; display:flex; flex-direction:column; border-left:1px solid #dee2e6; overflow:hidden; }

/* ── Top controls bar ─────────────────────────────────── */
.lg-pd-topbar { display:flex; align-items:center; gap:10px; padding:10px 16px; border-bottom:1px solid #dee2e6; background:#fff; flex-shrink:0; flex-wrap:wrap; }

/* ── Candidate grid ───────────────────────────────────── */
.lg-pd-grid { flex:1; overflow-y:auto; padding:12px; display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:10px; align-content:start; background:#111; }

/* ── Candidate card ───────────────────────────────────── */
.lg-cand-card { border-radius:8px; overflow:hidden; background:#1a1a2e; cursor:pointer; position:relative; transition:box-shadow .2s; }
.lg-cand-card:hover { box-shadow:0 0 0 2px #0f6cbf; }
.lg-cand-card.risk-high   { box-shadow:0 0 0 2px #dc3545; }
.lg-cand-card.risk-medium { box-shadow:0 0 0 2px #fd7e14; }
.lg-cand-card.selected    { box-shadow:0 0 0 3px #0f6cbf,0 0 0 6px rgba(15,108,191,0.3); }

/* ── Thumbnail area ───────────────────────────────────── */
.lg-cand-thumb-wrap { position:relative; width:100%; padding-top:75%; overflow:hidden; background:#111; }
.lg-cand-thumb      { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; }
.lg-cand-no-feed    { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; flex-direction:column; color:#444; font-size:12px; gap:6px; }

/* ── Status overlay ───────────────────────────────────── */
.lg-cand-overlay { position:absolute; top:0; left:0; right:0; display:flex; justify-content:space-between; padding:4px 6px; background:linear-gradient(to bottom,rgba(0,0,0,.7) 0%,transparent 100%); }
.lg-online-dot  { width:8px; height:8px; border-radius:50%; display:inline-block; margin-right:4px; }
.lg-risk-pill   { font-size:10px; font-weight:700; color:#fff; padding:1px 6px; border-radius:8px; }
.lg-view-toggle { position:absolute; bottom:6px; right:6px; font-size:11px; background:rgba(0,0,0,.65); color:#fff; border:none; border-radius:4px; padding:3px 7px; cursor:pointer; }

/* ── Name bar ─────────────────────────────────────────── */
.lg-cand-name { padding:6px 10px; background:#16213e; color:#e0e0e0; }
.lg-cand-name strong { font-size:13px; display:block; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.lg-cand-name small  { font-size:11px; color:#888; }

/* ── Side panel ───────────────────────────────────────── */
.lg-pd-side-section { flex-shrink:0; border-bottom:1px solid #dee2e6; }
.lg-pd-side-head    { background:#f8f9fa; padding:8px 12px; font-size:13px; font-weight:700; display:flex; justify-content:space-between; align-items:center; }
.lg-pd-side-body    { overflow-y:auto; }

/* ── Alert items ──────────────────────────────────────── */
.lg-al-item { padding:8px 12px; border-bottom:1px solid #f0f0f0; font-size:12px; line-height:1.4; }
.lg-al-item.sev-high   { border-left:3px solid #dc3545; }
.lg-al-item.sev-medium { border-left:3px solid #fd7e14; }
.lg-al-item.sev-new    { background:#fff8f8; animation:lg-fadein .5s; }

/* ── Fullscreen lightbox ──────────────────────────────── */
#lg-lb { display:none; position:fixed; inset:0; background:rgba(0,0,0,.95); z-index:99999; align-items:center; justify-content:center; flex-direction:column; }
#lg-lb img { max-width:95vw; max-height:88vh; border-radius:4px; object-fit:contain; }
#lg-lb-cap { color:#ddd; margin-top:8px; font-size:14px; }
#lg-lb-close { position:absolute; top:16px; right:20px; color:#fff; font-size:28px; cursor:pointer; background:none; border:none; }

@keyframes lg-pulse  { 0%,100%{opacity:1;} 50%{opacity:.3;} }
@keyframes lg-fadein { from{opacity:0;} to{opacity:1;} }
</style>

<?php if (!empty($breakRequests)): ?>
<div style="background:#fff3cd;border-bottom:2px solid #ffc107;padding:8px 16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
  <strong>☕ Break Requests (<?php echo count($breakRequests); ?>):</strong>
  <?php foreach ($breakRequests as $br): ?>
  <span style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:4px 10px;display:inline-flex;align-items:center;gap:8px;font-size:13px;">
    <strong><?php echo fullname($br); ?></strong>
    <form method="post" action="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id]); ?>" style="display:inline;">
      <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
      <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'approvebreak']); ?>
      <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $br->attemptid]); ?>
      <button type="submit" class="btn btn-sm btn-success" style="padding:2px 8px;font-size:12px;">✅ Approve</button>
    </form>
    <form method="post" action="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id]); ?>" style="display:inline;">
      <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
      <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'denybreak']); ?>
      <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $br->attemptid]); ?>
      <button type="submit" class="btn btn-sm btn-outline-danger" style="padding:2px 8px;font-size:12px;">❌ Deny</button>
    </form>
  </span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="lg-pd-shell">

  <!-- ═══ MAIN AREA ══════════════════════════════════════════════════════════ -->
  <div class="lg-pd-main">

    <!-- Top control bar -->
    <div class="lg-pd-topbar">
      <!-- Live indicator + counts -->
      <span style="display:flex;align-items:center;gap:6px;">
        <span style="width:10px;height:10px;border-radius:50%;background:#28a745;display:inline-block;animation:lg-pulse 1.5s infinite;"></span>
        <span style="font-size:13px;font-weight:600;color:#28a745;">LIVE</span>
      </span>

      <div style="display:flex;gap:8px;">
        <span class="lg-stat-pill" style="background:#28a745;" id="pd-cnt-active">0 active</span>
        <span class="lg-stat-pill" style="background:#dc3545;" id="pd-cnt-high">0 high risk</span>
        <span class="lg-stat-pill" style="background:#fd7e14;" id="pd-cnt-sus">0 suspended</span>
      </div>

      <!-- Global view toggle: webcam / screen -->
      <div style="display:flex;gap:4px;margin-left:auto;">
        <button id="btn-all-cam"    class="btn btn-sm btn-primary"         onclick="setAllView('webcam')">📷 All Webcam</button>
        <button id="btn-all-screen" class="btn btn-sm btn-outline-secondary" onclick="setAllView('screen')">🖥 All Screen</button>
      </div>

      <!-- Assessment header -->
      <div style="font-size:12px;color:#6c757d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px;"
           title="<?php echo s(format_string($instance->name)); ?>">
        <?php echo format_string($instance->name); ?>
      </div>

      <!-- Quick links -->
      <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id]); ?>" class="btn btn-sm btn-outline-primary">⚙️ Admin</a>
      <a href="<?php echo new moodle_url('/mod/learnguard/incidents.php',  ['id' => $id]); ?>" class="btn btn-sm btn-outline-warning">⚠️ Incidents</a>
      <a href="<?php echo new moodle_url('/mod/learnguard/gradeessays.php',['id' => $id]); ?>" class="btn btn-sm btn-outline-secondary">📝 Grade</a>
      <span id="pd-last-refresh" style="font-size:11px;color:#888;">Refreshing…</span>
    </div>

    <!-- Candidate grid -->
    <div class="lg-pd-grid" id="pd-grid">
      <div style="grid-column:1/-1;display:flex;align-items:center;justify-content:center;color:#555;flex-direction:column;gap:8px;padding:40px;">
        <div style="font-size:36px;">👁</div>
        <p>Loading sessions…</p>
      </div>
    </div>

  </div><!-- /main -->

  <!-- ═══ SIDE PANEL ═════════════════════════════════════════════════════════ -->
  <div class="lg-pd-side">

    <!-- Alert inbox -->
    <div class="lg-pd-side-section" style="flex:0 0 auto;">
      <div class="lg-pd-side-head">
        <span>🔔 Alerts</span>
        <span id="pd-alert-cnt" style="background:#dc3545;color:#fff;border-radius:10px;padding:1px 8px;font-size:11px;">0</span>
      </div>
      <div class="lg-pd-side-body" id="pd-alerts" style="max-height:260px;">
        <p style="padding:10px 12px;color:#6c757d;font-size:12px;text-align:center;">No recent alerts.</p>
      </div>
    </div>

    <!-- Selected student actions -->
    <div class="lg-pd-side-section" style="flex:0 0 auto;">
      <div class="lg-pd-side-head">
        <span>⚡ Selected Student</span>
      </div>
      <div style="padding:10px 12px;" id="pd-sel-panel">
        <p style="font-size:12px;color:#6c757d;">Click a candidate card to see actions.</p>
      </div>
    </div>

    <!-- Chat panel -->
    <div class="lg-pd-side-section" style="flex:1;overflow:hidden;display:flex;flex-direction:column;">
      <div class="lg-pd-side-head">
        <span>💬 Message Student</span>
      </div>
      <div style="padding:8px 12px;flex:0 0 auto;">
        <select id="pd-chat-target" class="form-control form-control-sm mb-1">
          <option value="">— Select student —</option>
        </select>
      </div>
      <div id="pd-chat-hist" style="flex:1;overflow-y:auto;padding:0 12px;display:flex;flex-direction:column;gap:6px;font-size:12px;"></div>
      <div style="padding:8px 12px;border-top:1px solid #dee2e6;display:flex;gap:6px;">
        <input type="text" id="pd-chat-inp" class="form-control form-control-sm" placeholder="Type message…" maxlength="200">
        <button onclick="pdSendMsg()" class="btn btn-sm btn-primary">Send</button>
      </div>
    </div>

  </div><!-- /side -->

</div><!-- /shell -->

<!-- Fullscreen lightbox -->
<div id="lg-lb" onclick="lgLbClose()">
  <button id="lg-lb-close" onclick="lgLbClose()">✕</button>
  <img id="lg-lb-img" src="" alt="">
  <div id="lg-lb-cap"></div>
</div>

<style>
.lg-stat-pill { padding:3px 10px; border-radius:12px; font-size:12px; font-weight:700; color:#fff; }
</style>

<script>
(function() {
'use strict';
var cmid    = <?php echo (int)$cm->id; ?>;
var wwwroot = <?php echo json_encode($CFG->wwwroot); ?>;
var sk      = <?php echo json_encode(sesskey()); ?>;
var timelimit = <?php echo (int)$instance->timelimit; ?>;
var rcol    = {low:'#28a745', medium:'#fd7e14', high:'#dc3545'};

// Per-attempt view mode: 'webcam' or 'screen'
var cardView = {};   // attemptid → 'webcam'|'screen'
var globalView = 'webcam';
var selectedAttemptId = 0;
var chatLast = 0;
var alertLast = Math.floor(Date.now()/1000) - 5;

// ── WS helper ─────────────────────────────────────────────────────────────
function ws(method, args, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', wwwroot + '/lib/ajax/service.php?sesskey=' + sk, true);
    xhr.setRequestHeader('Content-Type','application/json');
    xhr.onload = function() {
        try { var r = JSON.parse(xhr.responseText); if(r&&r[0]&&r[0].data) cb(r[0].data); } catch(e){}
    };
    xhr.send(JSON.stringify([{index:0, methodname:method, args:args}]));
}

function esc(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Global view toggle (all webcam / all screen) ───────────────────────────
window.setAllView = function(mode) {
    globalView = mode;
    document.getElementById('btn-all-cam').className    = 'btn btn-sm ' + (mode==='webcam' ? 'btn-primary' : 'btn-outline-secondary');
    document.getElementById('btn-all-screen').className = 'btn btn-sm ' + (mode==='screen' ? 'btn-primary' : 'btn-outline-secondary');
    // Refresh all visible card thumbnails
    document.querySelectorAll('.lg-cand-card').forEach(function(card) {
        var aid = parseInt(card.dataset.attemptid, 10);
        cardView[aid] = mode;
        refreshCardThumb(card, aid);
    });
};

// ── Fetch sessions ─────────────────────────────────────────────────────────
var sessionCache = {};

function fetchSessions() {
    ws('mod_learnguard_get_live_sessions', {cmid: cmid}, function(data) {
        var active = data.sessions || [];
        document.getElementById('pd-cnt-active').textContent = active.length + ' active';
        document.getElementById('pd-cnt-high').textContent   = active.filter(function(s){return s.risk_level==='high';}).length + ' high risk';
        document.getElementById('pd-cnt-sus').textContent    = (data.suspended_count || 0) + ' suspended';
        renderGrid(active);
        updateChatSelector(active);
        var ts = new Date().toLocaleTimeString();
        document.getElementById('pd-last-refresh').textContent = 'Updated ' + ts;
    });
}

// ── Render candidate grid ──────────────────────────────────────────────────
function renderGrid(sessions) {
    var grid = document.getElementById('pd-grid');
    if (!grid) return;

    if (sessions.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#555;padding:60px;"><div style="font-size:40px;">👁</div><p>No active sessions right now.</p></div>';
        return;
    }

    // Update existing cards or create new ones; remove stale ones.
    var seen = {};
    sessions.forEach(function(s) {
        seen[s.attemptid] = true;
        sessionCache[s.attemptid] = s;
        var existing = document.getElementById('card-' + s.attemptid);
        if (existing) {
            updateCard(existing, s);
        } else {
            var card = buildCard(s);
            grid.appendChild(card);
        }
    });
    // Remove cards for sessions that ended.
    document.querySelectorAll('.lg-cand-card').forEach(function(card) {
        if (!seen[card.dataset.attemptid]) { card.parentNode.removeChild(card); }
    });
}

function buildCard(s) {
    var card = document.createElement('div');
    card.id              = 'card-' + s.attemptid;
    card.dataset.attemptid = s.attemptid;
    card.className       = 'lg-cand-card risk-' + (s.risk_level || 'low');
    if (s.attemptid === selectedAttemptId) { card.classList.add('selected'); }

    card.innerHTML = cardHTML(s);
    card.addEventListener('click', function(e) {
        if (e.target.classList.contains('lg-view-toggle') || e.target.tagName === 'BUTTON' || e.target.tagName === 'A') return;
        selectCandidate(s.attemptid, s.fullname);
    });
    return card;
}

function updateCard(card, s) {
    card.className = 'lg-cand-card risk-' + (s.risk_level || 'low');
    if (s.attemptid === selectedAttemptId) { card.classList.add('selected'); }
    card.innerHTML = cardHTML(s);
    card.addEventListener('click', function(e) {
        if (e.target.classList.contains('lg-view-toggle') || e.target.tagName === 'BUTTON' || e.target.tagName === 'A') return;
        selectCandidate(s.attemptid, s.fullname);
    });
}

function cardHTML(s) {
    var aid  = s.attemptid;
    var view = cardView[aid] || globalView;
    var snap = view === 'screen' ? (s.screen_snap || null) : (s.webcam_snap || null);
    var snapSrc = snap ? 'data:image/jpeg;base64,' + snap : null;

    var ago    = Math.floor(Date.now()/1000) - (s.lastseen || 0);
    var online = ago < 120;
    var dotCol = online ? '#28a745' : '#dc3545';
    var agoStr = ago < 60 ? 'Active now' : (ago < 3600 ? Math.floor(ago/60) + 'm ago' : '>1h');

    var riskBg = rcol[s.risk_level] || '#6c757d';
    var alertBadge = s.alert_count > 0
        ? '<span style="position:absolute;top:6px;right:6px;background:#dc3545;color:#fff;border-radius:50%;width:20px;height:20px;display:flex;align-items:center;justify-content:center;font-size:10px;font-weight:700;">' + s.alert_count + '</span>'
        : '';

    var thumbHtml = snapSrc
        ? '<img class="lg-cand-thumb" src="' + snapSrc + '" onclick="lgLbOpen(\'' + snapSrc + '\',\'' + esc(s.fullname) + ' — ' + (view==='screen'?'Screen':'Webcam') + '\')" title="Click to enlarge" alt="">'
        : '<div class="lg-cand-no-feed"><span style="font-size:28px;">' + (view==='screen'?'🖥':'📷') + '</span><span>' + (view==='screen'?'No screen capture yet':'No webcam snapshot yet') + '</span></div>';

    var toggleLabel = view === 'screen' ? '📷 Webcam' : '🖥 Screen';

    return '<div class="lg-cand-thumb-wrap">' +
        thumbHtml +
        alertBadge +
        '<div class="lg-cand-overlay">' +
        '<span><span class="lg-online-dot" style="background:' + dotCol + ';"></span>' +
        '<span style="font-size:10px;color:#ddd;">' + agoStr + '</span></span>' +
        '<span class="lg-risk-pill" style="background:' + riskBg + ';">' + (s.risk_score || 0) + ' ' + (s.risk_level||'low').toUpperCase() + '</span>' +
        '</div>' +
        '<button class="lg-view-toggle" onclick="toggleCardView(' + aid + ',this)">' + toggleLabel + '</button>' +
        '</div>' +
        '<div class="lg-cand-name">' +
        '<strong>' + esc(s.fullname) + '</strong>' +
        '<small>Q' + (s.currentpage||'?') + ' · Attempt #' + (s.attempt||1) + ' · ' + agoStr + '</small>' +
        '</div>';
}

function refreshCardThumb(card, aid) {
    var s = sessionCache[aid];
    if (!s) return;
    card.className = 'lg-cand-card risk-' + (s.risk_level || 'low');
    card.innerHTML = cardHTML(s);
}

// ── Per-card view toggle ───────────────────────────────────────────────────
window.toggleCardView = function(aid, btn) {
    var cur = cardView[aid] || globalView;
    cardView[aid] = cur === 'screen' ? 'webcam' : 'screen';
    var card = document.getElementById('card-' + aid);
    if (card) { refreshCardThumb(card, aid); }
};

// ── Select a candidate ────────────────────────────────────────────────────
function selectCandidate(aid, name) {
    selectedAttemptId = aid;
    // Highlight the selected card.
    document.querySelectorAll('.lg-cand-card').forEach(function(c) { c.classList.remove('selected'); });
    var selCard = document.getElementById('card-' + aid);
    if (selCard) { selCard.classList.add('selected'); }

    // Populate the action panel.
    var panel = document.getElementById('pd-sel-panel');
    if (!panel) return;
    var s = sessionCache[aid] || {};
    panel.innerHTML =
        '<div style="font-weight:700;font-size:14px;margin-bottom:8px;">' + esc(name) + '</div>' +
        '<div style="display:flex;gap:6px;flex-wrap:wrap;">' +
        '<a href="' + wwwroot + '/mod/learnguard/attemptdetail.php?id=' + cmid + '&attempt=' + aid + '" target="_blank" class="btn btn-sm btn-outline-primary" style="font-size:12px;">🔍 Detail</a>' +
        (timelimit > 0 ? '<button onclick="pdGrantTime(' + aid + ')" class="btn btn-sm btn-outline-info" style="font-size:12px;">⏱ +Time</button>' : '') +
        '<button onclick="pdSuspend(' + aid + ',\'' + esc(name) + '\')" class="btn btn-sm btn-outline-warning" style="font-size:12px;">⛔ Suspend</button>' +
        '<button onclick="pdForceSubmit(' + aid + ',\'' + esc(name) + '\')" class="btn btn-sm btn-outline-danger" style="font-size:12px;">📤 End Exam</button>' +
        '</div>';

    // Auto-select in chat dropdown.
    var sel = document.getElementById('pd-chat-target');
    if (sel) { sel.value = aid; sel.dispatchEvent(new Event('change')); }
}

// ── Proctor actions ────────────────────────────────────────────────────────
window.pdGrantTime = function(aid) {
    var mins = parseInt(prompt('Extra minutes to grant (5, 10, 15…):', '10'), 10);
    if (isNaN(mins) || mins <= 0) return;
    postAction(aid, 'granttime', {extramins: mins});
};
window.pdSuspend = function(aid, name) {
    if (!confirm('Suspend ' + name + '? They will see a suspension notice immediately.')) return;
    postAction(aid, 'suspend', {});
};
window.pdForceSubmit = function(aid, name) {
    if (!confirm('Force-submit ' + name + '\'s exam? This cannot be undone.')) return;
    postAction(aid, 'forcesubmit', {});
};

function postAction(aid, action, extra) {
    var form = document.createElement('form');
    form.method = 'post';
    form.action = wwwroot + '/mod/learnguard/admin.php';
    var fields = Object.assign({id: cmid, sesskey: sk, action: action, attemptid: aid}, extra);
    Object.keys(fields).forEach(function(k) {
        var i = document.createElement('input'); i.type='hidden'; i.name=k; i.value=fields[k]; form.appendChild(i);
    });
    document.body.appendChild(form); form.submit();
}

// ── Chat ───────────────────────────────────────────────────────────────────
function updateChatSelector(sessions) {
    var sel = document.getElementById('pd-chat-target');
    if (!sel) return;
    var cur = sel.value;
    sel.innerHTML = '<option value="">— Select student —</option>';
    sessions.forEach(function(s) {
        var o = document.createElement('option');
        o.value = s.attemptid;
        o.textContent = s.fullname;
        if (s.attemptid == cur) o.selected = true;
        sel.appendChild(o);
    });
}

document.getElementById('pd-chat-target').addEventListener('change', function() {
    document.getElementById('pd-chat-hist').innerHTML = '';
    chatLast = 0;
    if (this.value) { pollChat(parseInt(this.value,10)); }
});

function pollChat(aid) {
    if (!aid) return;
    ws('mod_learnguard_get_chat', {attemptid: aid, since: chatLast}, function(d) {
        (d.messages||[]).forEach(function(m) {
            appendChat(m.sendername, m.message, !!m.ismine);
            chatLast = Math.max(chatLast, m.timecreated);
        });
    });
}

window.pdSendMsg = function() {
    var sel = document.getElementById('pd-chat-target');
    var inp = document.getElementById('pd-chat-inp');
    if (!sel||!inp||!sel.value||!inp.value.trim()) return;
    var aid = parseInt(sel.value,10), msg = inp.value.trim();
    inp.value = '';
    appendChat('You (Proctor)', msg, true);
    ws('mod_learnguard_send_chat', {attemptid: aid, message: msg, sendertype: 'proctor'}, function(){});
};

function appendChat(sender, text, mine) {
    var area = document.getElementById('pd-chat-hist');
    if (!area) return;
    var d = document.createElement('div');
    d.style.cssText = 'max-width:85%;padding:5px 8px;border-radius:6px;font-size:12px;line-height:1.4;align-self:' +
        (mine ? 'flex-end;background:#0f6cbf;color:#fff;' : 'flex-start;background:#e9ecef;color:#212529;');
    d.innerHTML = '<div style="font-size:10px;opacity:.7;">' + esc(sender) + '</div><div>' + esc(text) + '</div>';
    area.appendChild(d);
    area.scrollTop = area.scrollHeight;
}

// ── Alert inbox ────────────────────────────────────────────────────────────
function fetchAlerts() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', wwwroot + '/mod/learnguard/proctor_alerts_ajax.php?id=' + cmid + '&since=' + alertLast + '&sesskey=' + sk, true);
    xhr.onload = function() {
        try {
            var d = JSON.parse(xhr.responseText);
            if (d.alerts && d.alerts.length) {
                var box = document.getElementById('pd-alerts');
                if (box && box.querySelector('p')) { box.innerHTML = ''; }
                d.alerts.forEach(function(a) {
                    var item = document.createElement('div');
                    item.className = 'lg-al-item sev-' + a.severity + ' sev-new';
                    item.innerHTML = '<strong>' + esc(a.event_type.replace(/_/g,' ')) + '</strong> ' +
                        '<span style="background:' + (a.severity==='high'?'#dc3545':'#fd7e14') + ';color:#fff;padding:1px 5px;border-radius:6px;font-size:10px;">' + a.severity.toUpperCase() + '</span><br>' +
                        esc(a.fullname) + ' · ' + new Date(a.timemodified*1000).toLocaleTimeString();
                    if (box) { box.insertBefore(item, box.firstChild); }
                    showAlertToast(a);
                    alertLast = Math.max(alertLast, a.timemodified);
                });
                var cnt = box ? box.querySelectorAll('.lg-al-item').length : 0;
                document.getElementById('pd-alert-cnt').textContent = cnt;
            }
        } catch(e) {}
    };
    xhr.send();
}

function showAlertToast(a) {
    var t = document.createElement('div');
    t.style.cssText = 'position:fixed;bottom:20px;left:20px;z-index:9999;background:' +
        (a.severity==='high'?'#dc3545':'#fd7e14') + ';color:#fff;' +
        'padding:12px 18px;border-radius:8px;font-size:13px;box-shadow:0 4px 20px rgba(0,0,0,.3);max-width:300px;';
    t.innerHTML = '🔔 <strong>' + esc(a.event_type.replace(/_/g,' ')) + '</strong><br>' + esc(a.fullname);
    document.body.appendChild(t);
    setTimeout(function() { if(t.parentNode) t.parentNode.removeChild(t); }, 6000);
}

// ── Lightbox ───────────────────────────────────────────────────────────────
window.lgLbOpen = function(src, cap) {
    document.getElementById('lg-lb-img').src = src;
    document.getElementById('lg-lb-cap').textContent = cap || '';
    document.getElementById('lg-lb').style.display = 'flex';
};
window.lgLbClose = function() {
    document.getElementById('lg-lb').style.display = 'none';
    document.getElementById('lg-lb-img').src = '';
};

// ── Init ───────────────────────────────────────────────────────────────────
fetchSessions();
fetchAlerts();
setInterval(fetchSessions, 10000);  // Refresh grid every 10s
setInterval(fetchAlerts,   5000);   // Poll alerts every 5s

// Chat polling for selected student
setInterval(function() {
    var sel = document.getElementById('pd-chat-target');
    if (sel && sel.value) { pollChat(parseInt(sel.value,10)); }
}, 5000);

})();
</script>

<?php echo $OUTPUT->footer(); ?>
