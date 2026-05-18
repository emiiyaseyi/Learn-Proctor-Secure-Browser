<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Live proctor dashboard for mod_learnguard.
 *
 * Features:
 *  - Real-time student session grid with latest webcam snapshot
 *  - Alert inbox for proctoring violations
 *  - Chat with individual students
 *  - Break approval
 *  - Extended time grants
 *  - Force submit
 *  - Incident creation shortcut
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

// Recent high-risk events (alert inbox).
$alerts = $DB->get_records_sql(
    "SELECT e.id, e.event_type, e.severity, e.score_impact, e.timemodified,
            u.firstname, u.lastname, a.id AS attemptid
       FROM {learnguard_events} e
       JOIN {learnguard_attempts} a ON a.id = e.attemptid
       JOIN {user} u ON u.id = e.userid
      WHERE a.learnguard = :lgid AND e.severity IN ('high','medium')
        AND e.timemodified >= :since
      ORDER BY e.timemodified DESC
      LIMIT 50",
    ['lgid' => $instance->id, 'since' => time() - 3600]
);

// Latest snapshot per attempt for the student card thumbnails.
$latestSnapshots = $DB->get_records_sql(
    "SELECT s.attemptid, s.snapshot_url, MAX(s.timemodified) AS lasttime
       FROM {learnguard_snapshots} s
       JOIN {learnguard_attempts} a ON a.id = s.attemptid
      WHERE a.learnguard = :lgid AND s.snapshot_type = 'periodic'
      GROUP BY s.attemptid, s.snapshot_url
      ORDER BY lasttime DESC",
    ['lgid' => $instance->id]
);
$snapMap = [];
foreach ($latestSnapshots as $sn) {
    if (!isset($snapMap[$sn->attemptid])) { $snapMap[$sn->attemptid] = $sn->snapshot_url; }
}

// Break requests awaiting approval.
$breakRequests = $DB->get_records_sql(
    "SELECT a.id AS attemptid, u.firstname, u.lastname, a.break_requested
       FROM {learnguard_attempts} a
       JOIN {user} u ON u.id = a.userid
      WHERE a.learnguard = :lgid AND a.break_requested > 0 AND a.break_approved = 0",
    ['lgid' => $instance->id]
);

echo $OUTPUT->header();
echo learnguard_nav_bar($id, 'Live Dashboard');
?>

<style>
.lg-dash-grid { display:grid; grid-template-columns:1fr 320px; gap:16px; font-family:"Segoe UI",system-ui,sans-serif; }
.lg-session-cards { display:grid; grid-template-columns:repeat(auto-fill,minmax(280px,1fr)); gap:12px; }
.lg-student-card { border:1px solid #dee2e6; border-radius:10px; overflow:hidden; background:#fff; transition:box-shadow .2s; }
.lg-student-card.risk-high   { border-color:#dc3545; box-shadow:0 0 0 2px rgba(220,53,69,0.2); }
.lg-student-card.risk-medium { border-color:#fd7e14; }
.lg-student-card:hover { box-shadow:0 4px 16px rgba(0,0,0,0.15); }
.lg-snap-thumb { width:100%; height:140px; object-fit:cover; background:#111; cursor:zoom-in; }
.lg-snap-placeholder { width:100%; height:140px; background:#111; display:flex; align-items:center; justify-content:center; color:#444; font-size:12px; }
.lg-alert-item { padding:8px 10px; border-bottom:1px solid #f0f0f0; font-size:12px; }
.lg-alert-item.sev-high   { border-left:3px solid #dc3545; }
.lg-alert-item.sev-medium { border-left:3px solid #fd7e14; }
.lg-badge { padding:2px 8px; border-radius:10px; font-size:11px; font-weight:700; color:#fff; }
</style>

<div style="padding:16px;font-family:'Segoe UI',system-ui,sans-serif;">

<!-- Top bar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
  <div>
    <h2 style="margin:0;font-size:20px;">🔴 Live Proctor Dashboard</h2>
    <p style="margin:2px 0 0;font-size:13px;color:#6c757d;"><?php echo format_string($instance->name); ?></p>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <span id="lg-live-indicator" style="display:flex;align-items:center;gap:6px;">
      <span style="display:inline-block;width:10px;height:10px;border-radius:50%;background:#28a745;animation:lg-pulse 1.5s infinite;"></span>
      <span style="font-size:13px;color:#28a745;font-weight:600;">Live</span>
    </span>
    <span id="lg-active-count" style="font-size:13px;color:#6c757d;background:#f8f9fa;padding:4px 12px;border-radius:20px;border:1px solid #dee2e6;">0 active</span>
    <a href="<?php echo new moodle_url('/mod/learnguard/incidents.php', ['id' => $id]); ?>" class="btn btn-sm btn-outline-warning">⚠️ Incidents</a>
    <a href="<?php echo new moodle_url('/mod/learnguard/gradeessays.php', ['id' => $id]); ?>" class="btn btn-sm btn-outline-secondary">📝 Grade Essays</a>
    <a href="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id]); ?>" class="btn btn-sm btn-outline-primary">⚙️ Admin</a>
  </div>
</div>

<?php if (!empty($breakRequests)): ?>
<!-- Break approval banner -->
<div style="background:#fff3cd;border:1px solid #ffc107;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
  <strong>☕ Break Requests (<?php echo count($breakRequests); ?>)</strong>
  <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;">
    <?php foreach ($breakRequests as $br): ?>
    <div style="background:#fff;border:1px solid #dee2e6;border-radius:6px;padding:8px 12px;display:flex;align-items:center;gap:10px;">
      <span style="font-size:13px;font-weight:600;"><?php echo fullname($br); ?></span>
      <form method="post" action="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id]); ?>" style="display:inline;">
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'approvebreak']); ?>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $br->attemptid]); ?>
        <button type="submit" class="btn btn-sm btn-success">✅ Approve</button>
      </form>
      <form method="post" action="<?php echo new moodle_url('/mod/learnguard/admin.php', ['id' => $id]); ?>" style="display:inline;">
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]); ?>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'denybreak']); ?>
        <?php echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attemptid', 'value' => $br->attemptid]); ?>
        <button type="submit" class="btn btn-sm btn-outline-danger">❌ Deny</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<div class="lg-dash-grid">

  <!-- LEFT: Student session cards -->
  <div>
    <div id="lg-session-cards" class="lg-session-cards">
      <div style="grid-column:1/-1;text-align:center;padding:60px;color:#6c757d;">
        <div style="font-size:40px;margin-bottom:12px;">👁</div>
        <p>Loading active sessions…</p>
      </div>
    </div>
  </div>

  <!-- RIGHT: Alert inbox + chat -->
  <div>
    <!-- Alert Inbox -->
    <div style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;margin-bottom:16px;">
      <div style="background:#dc3545;color:#fff;padding:10px 14px;display:flex;justify-content:space-between;align-items:center;">
        <strong style="font-size:14px;">🔔 Alert Inbox</strong>
        <span id="lg-alert-badge" style="background:#fff;color:#dc3545;border-radius:10px;padding:1px 8px;font-size:12px;font-weight:700;"><?php echo count($alerts); ?></span>
      </div>
      <div id="lg-alert-inbox" style="max-height:340px;overflow-y:auto;">
        <?php if (empty($alerts)): ?>
        <p style="padding:14px;color:#6c757d;font-size:13px;text-align:center;">No recent alerts.</p>
        <?php else: ?>
        <?php foreach ($alerts as $a): ?>
        <div class="lg-alert-item sev-<?php echo $a->severity; ?>">
          <strong><?php echo s(str_replace('_', ' ', $a->event_type)); ?></strong>
          <span style="background:<?php echo $a->severity === 'high' ? '#dc3545' : '#fd7e14'; ?>;color:#fff;padding:1px 6px;border-radius:8px;font-size:10px;margin-left:4px;"><?php echo strtoupper($a->severity); ?></span><br>
          <?php echo fullname($a); ?> · <?php echo userdate($a->timemodified, '%H:%M:%S'); ?>
          <?php if ($a->score_impact > 0): ?>
          <span style="color:#dc3545;font-weight:700;">+<?php echo $a->score_impact; ?> pts</span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        <div id="lg-alert-live" style="border-top:1px solid #f0f0f0;"></div>
      </div>
    </div>

    <!-- Chat Panel -->
    <div style="border:1px solid #dee2e6;border-radius:8px;overflow:hidden;">
      <div style="background:<?php echo $brand->primary; ?>;color:#fff;padding:10px 14px;">
        <strong style="font-size:14px;">💬 Send Message to Student</strong>
      </div>
      <div style="padding:12px;">
        <select id="lg-chat-target" class="form-control form-control-sm mb-2">
          <option value="">— Select student —</option>
        </select>
        <div id="lg-chat-history" style="height:140px;overflow-y:auto;background:#f8f9fa;border-radius:6px;padding:8px;margin-bottom:8px;font-size:12px;display:flex;flex-direction:column;gap:6px;"></div>
        <div style="display:flex;gap:6px;">
          <input type="text" id="lg-chat-msg" class="form-control form-control-sm" placeholder="Type message…" maxlength="200">
          <button onclick="sendProctorMessage()" class="btn btn-sm btn-primary">Send</button>
        </div>
      </div>
    </div>
  </div>
</div>

</div><!-- /padding wrapper -->

<!-- Lightbox for snapshots -->
<div id="lg-snap-lightbox" onclick="this.style.display='none'"
     style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:9999;align-items:center;justify-content:center;cursor:zoom-out;">
  <img id="lg-lb-img" src="" style="max-width:90vw;max-height:90vh;border-radius:4px;" alt=""/>
</div>

<script>
(function() {
var cmid      = <?php echo (int)$cm->id; ?>;
var wwwroot   = <?php echo json_encode($CFG->wwwroot); ?>;
var sesskey   = <?php echo json_encode(sesskey()); ?>;
var timelimit = <?php echo (int)$instance->timelimit; ?>;
var snapMap   = <?php echo json_encode(array_map(function($v) { return 'data:image/jpeg;base64,' . $v; }, $snapMap)); ?>;
var riskCol   = {low:'#28a745', medium:'#fd7e14', high:'#dc3545'};
var sessions  = {};

// ── WS call helper ─────────────────────────────────────────────────────────────
function ws(method, args, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', wwwroot + '/lib/ajax/service.php?sesskey=' + sesskey, true);
    xhr.setRequestHeader('Content-Type', 'application/json');
    xhr.onload = function() {
        try { var r = JSON.parse(xhr.responseText); if (r && r[0] && r[0].data) cb(r[0].data); } catch(e) {}
    };
    xhr.send(JSON.stringify([{index:0, methodname:method, args:args}]));
}

// ── Fetch sessions ─────────────────────────────────────────────────────────────
function fetchSessions() {
    ws('mod_learnguard_get_live_sessions', {cmid: cmid}, function(data) {
        var active = data.sessions || [];
        document.getElementById('lg-active-count').textContent = active.length + ' active';
        renderCards(active);
        updateChatSelector(active);
    });
}

function renderCards(list) {
    var grid = document.getElementById('lg-session-cards');
    if (!grid) return;
    if (list.length === 0) {
        grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:60px;color:#6c757d;"><div style="font-size:40px;">👁</div><p>No active sessions.</p></div>';
        return;
    }
    grid.innerHTML = '';
    list.forEach(function(s) {
        sessions[s.attemptid] = s;
        var rc  = riskCol[s.risk_level] || '#6c757d';
        var ago = Math.floor(Date.now()/1000) - s.lastseen;
        var agoStr = ago < 60 ? ago + 's' : Math.floor(ago/60) + 'm';
        var snapSrc = snapMap[s.attemptid] || null;

        var card = document.createElement('div');
        card.className = 'lg-student-card risk-' + s.risk_level;

        // Thumbnail.
        var thumb = snapSrc
            ? '<img src="' + snapSrc + '" class="lg-snap-thumb" onclick="openLightbox(\'' + snapSrc + '\')" title="Click to enlarge">'
            : '<div class="lg-snap-placeholder">📷 No snapshot yet</div>';

        card.innerHTML = thumb +
            '<div style="padding:10px 12px;">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">' +
            '<strong style="font-size:13px;">' + esc(s.fullname) + '</strong>' +
            '<span class="lg-badge" style="background:' + rc + ';">' + s.risk_score + ' ' + s.risk_level.toUpperCase() + '</span>' +
            '</div>' +
            '<div style="font-size:11px;color:#6c757d;margin-bottom:8px;">' +
            'Q ' + s.currentpage + ' &nbsp;|&nbsp; Attempt #' + s.attempt + ' &nbsp;|&nbsp; ' + agoStr + ' ago' +
            '</div>' +
            // Action buttons.
            '<div style="display:flex;gap:4px;flex-wrap:wrap;">' +
            '<a href="' + wwwroot + '/mod/learnguard/attemptdetail.php?id=' + cmid + '&attempt=' + s.attemptid + '" target="_blank" class="btn btn-sm btn-outline-primary" style="font-size:11px;">🔍 Detail</a>' +
            (timelimit > 0
                ? '<button onclick="grantTime(' + s.attemptid + ')" class="btn btn-sm btn-outline-info" style="font-size:11px;">⏱ +Time</button>'
                : '') +
            '<button onclick="forceSubmitStudent(' + s.attemptid + ',\'' + esc(s.fullname) + '\')" class="btn btn-sm btn-outline-danger" style="font-size:11px;">📤 Submit</button>' +
            '<button onclick="createIncident(' + s.attemptid + ',\'' + esc(s.fullname) + '\')" class="btn btn-sm btn-outline-warning" style="font-size:11px;">⚠️ Incident</button>' +
            '</div>' +
            '</div>';

        grid.appendChild(card);
    });
}

// ── Chat ──────────────────────────────────────────────────────────────────────
function updateChatSelector(list) {
    var sel = document.getElementById('lg-chat-target');
    if (!sel) return;
    var cur = sel.value;
    sel.innerHTML = '<option value="">— Select student —</option>';
    list.forEach(function(s) {
        var opt = document.createElement('option');
        opt.value = s.attemptid;
        opt.textContent = s.fullname;
        if (s.attemptid == cur) opt.selected = true;
        sel.appendChild(opt);
    });
}

window.sendProctorMessage = function() {
    var sel = document.getElementById('lg-chat-target');
    var inp = document.getElementById('lg-chat-msg');
    if (!sel || !inp || !sel.value || !inp.value.trim()) return;
    var attemptid = parseInt(sel.value, 10);
    var msg = inp.value.trim();
    inp.value = '';
    appendChatMsg('You (Proctor)', msg, true);
    ws('mod_learnguard_send_chat', {attemptid: attemptid, message: msg, sendertype: 'proctor'}, function() {});
};

function appendChatMsg(sender, text, ismine) {
    var area = document.getElementById('lg-chat-history');
    if (!area) return;
    var d = document.createElement('div');
    d.style.cssText = 'max-width:85%;padding:5px 8px;border-radius:6px;font-size:12px;line-height:1.4;align-self:' +
        (ismine ? 'flex-end;background:#0f6cbf;color:#fff;' : 'flex-start;background:#e9ecef;color:#212529;');
    d.innerHTML = '<div style="font-size:10px;opacity:.7;">' + esc(sender) + '</div><div>' + esc(text) + '</div>';
    area.appendChild(d);
    area.scrollTop = area.scrollHeight;
}

// Poll chat for each selected student.
var chatPollTimer = null;
var chatLast = 0;
document.getElementById('lg-chat-target').addEventListener('change', function() {
    document.getElementById('lg-chat-history').innerHTML = '';
    chatLast = 0;
    if (chatPollTimer) clearInterval(chatPollTimer);
    if (this.value) {
        pollChat(parseInt(this.value, 10));
        chatPollTimer = setInterval(function() { pollChat(parseInt(document.getElementById('lg-chat-target').value, 10)); }, 5000);
    }
});

function pollChat(attemptid) {
    if (!attemptid) return;
    ws('mod_learnguard_get_chat', {attemptid: attemptid, since: chatLast}, function(data) {
        (data.messages || []).forEach(function(m) {
            if (!m.ismine) appendChatMsg(m.sendername, m.message, false);
            else appendChatMsg('You', m.message, true);
            chatLast = Math.max(chatLast, m.timecreated);
        });
    });
}

// ── Alert inbox live polling ────────────────────────────────────────────────────
var alertLast = Math.floor(Date.now() / 1000) - 3; // Fetch last 3s on init.

function fetchAlerts() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', wwwroot + '/mod/learnguard/proctor_alerts_ajax.php?id=' + cmid + '&since=' + alertLast + '&sesskey=' + sesskey, true);
    xhr.onload = function() {
        try {
            var data = JSON.parse(xhr.responseText);
            if (data.alerts && data.alerts.length) {
                var inbox = document.getElementById('lg-alert-live');
                data.alerts.forEach(function(a) {
                    var d = document.createElement('div');
                    d.className = 'lg-alert-item sev-' + a.severity;
                    d.style.background = '#fff8f8';
                    d.innerHTML = '<strong>' + esc(a.event_type.replace(/_/g,' ')) + '</strong> ' +
                        '<span class="lg-badge" style="background:' + (a.severity==='high'?'#dc3545':'#fd7e14') + ';">' + a.severity.toUpperCase() + '</span><br>' +
                        esc(a.fullname) + ' · ' + new Date(a.timemodified * 1000).toLocaleTimeString();
                    inbox.insertBefore(d, inbox.firstChild);

                    // Show toast-style notification.
                    showAlertToast(a.event_type, a.fullname, a.severity);
                    alertLast = Math.max(alertLast, a.timemodified);
                });
            }
        } catch(e) {}
    };
    xhr.send();
}

function showAlertToast(type, name, severity) {
    var toast = document.createElement('div');
    var bg = severity === 'high' ? '#dc3545' : '#fd7e14';
    toast.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:9999;background:' + bg + ';color:#fff;' +
        'padding:12px 18px;border-radius:8px;font-size:14px;box-shadow:0 4px 20px rgba(0,0,0,0.3);' +
        'font-family:system-ui,sans-serif;max-width:320px;';
    toast.innerHTML = '🔔 <strong>' + esc(type.replace(/_/g,' ')) + '</strong><br>' + esc(name);
    document.body.appendChild(toast);
    setTimeout(function() { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 6000);
}

// ── Proctor actions ────────────────────────────────────────────────────────────
window.grantTime = function(attemptid) {
    var mins = parseInt(prompt('Extra minutes to grant (5, 10, 15…):', '10'), 10);
    if (isNaN(mins) || mins <= 0) return;
    var form = document.createElement('form'); form.method = 'post';
    form.action = wwwroot + '/mod/learnguard/admin.php';
    [['id',cmid],['sesskey',sesskey],['action','granttime'],['attemptid',attemptid],['extramins',mins]].forEach(function(f){
        var i = document.createElement('input'); i.type='hidden'; i.name=f[0]; i.value=f[1]; form.appendChild(i);
    });
    document.body.appendChild(form); form.submit();
};

window.forceSubmitStudent = function(attemptid, name) {
    if (!confirm('Force-submit ' + name + '\'s attempt? Their answers will be graded now.')) return;
    var form = document.createElement('form'); form.method = 'post';
    form.action = wwwroot + '/mod/learnguard/admin.php';
    [['id',cmid],['sesskey',sesskey],['action','forcesubmit'],['attemptid',attemptid]].forEach(function(f){
        var i = document.createElement('input'); i.type='hidden'; i.name=f[0]; i.value=f[1]; form.appendChild(i);
    });
    document.body.appendChild(form); form.submit();
};

window.createIncident = function(attemptid, name) {
    var title = prompt('Brief incident title for ' + name + ':', 'Suspicious behaviour detected');
    if (!title) return;
    window.open(wwwroot + '/mod/learnguard/incidents.php?id=' + cmid + '&attempt=' + attemptid, '_blank');
};

window.openLightbox = function(src) {
    var lb = document.getElementById('lg-snap-lightbox');
    document.getElementById('lg-lb-img').src = src;
    lb.style.display = 'flex';
};

function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

// ── Init + intervals ───────────────────────────────────────────────────────────
fetchSessions();
fetchAlerts();
setInterval(fetchSessions, 10000);
setInterval(fetchAlerts,   5000);

})();
</script>

<style>
@keyframes lg-pulse { 0%,100%{opacity:1;} 50%{opacity:.3;} }
</style>

<?php echo $OUTPUT->footer();
