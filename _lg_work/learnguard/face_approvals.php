<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Proctor face-match approval panel.
 *
 * Proctors see all pending/recent face match approval requests.
 * They can approve, deny, or send a message to the waiting student.
 *
 * URL: /mod/learnguard/face_approvals.php         (site-wide, needs siteadmin)
 *      /mod/learnguard/face_approvals.php?id=CMID (per-assessment)
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$id = optional_param('id', 0, PARAM_INT); // cmid (0 = site-wide)

if ($id) {
    $cm      = get_coursemodule_from_id('learnguard', $id, 0, false, MUST_EXIST);
    $course  = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
    require_capability('mod/learnguard:liveproctor', $context);
} else {
    require_login();
    require_capability('moodle/site:config', context_system::instance());
    $cm = null; $course = null;
}

$PAGE->set_url('/mod/learnguard/face_approvals.php', $id ? ['id' => $id] : []);
$PAGE->set_context($id ? context_module::instance($cm->id) : context_system::instance());
$PAGE->set_title('Face Match Approvals');
$PAGE->set_heading($id ? format_string($course->fullname) : 'LearnGuard — Face Match Approvals');
$PAGE->requires->css('/mod/learnguard/styles.css');

$brand = learnguard_brand();

// Build WHERE
$where  = $id ? 'fa.cmid = :cmid' : '1=1';
$params = $id ? ['cmid' => $cm->id] : [];

$approvals = [];
try {
    $approvals = $DB->get_records_sql(
        "SELECT fa.id, fa.cmid, fa.userid, fa.status, fa.matchpct, fa.reviewnote,
                fa.reviewedby, fa.passportdata, fa.timecreated, fa.timemodified,
                u.firstname, u.lastname, u.email,
                lg.name AS assessmentname, c.fullname AS coursename, cm2.id AS cmid2
           FROM {learnguard_face_approvals} fa
           JOIN {user}           u   ON u.id = fa.userid
           JOIN {course_modules} cm2 ON cm2.id = fa.cmid
           JOIN {learnguard}     lg  ON lg.id = cm2.instance
           JOIN {course}         c   ON c.id = lg.course
          WHERE $where
       ORDER BY
         CASE fa.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
         fa.timecreated DESC",
        $params
    );
} catch (Exception $e) {
    // Table may not exist yet — show a helpful upgrade message instead of crashing.
    echo $OUTPUT->header();
    echo learnguard_nav_bar($id, 'Face Approvals');
    echo $OUTPUT->notification(
        '⚠️ The face approvals table does not exist yet. ' .
        '<a href="' . (new moodle_url('/mod/learnguard/fix_db.php', ['sesskey' => sesskey()]))->out(false) . '" ' .
        'style="font-weight:700;">▶ Fix Database Now (one click)</a>',
        'warning'
    );
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
if ($id) { echo learnguard_nav_bar($id, 'Face Approvals'); }
echo learnguard_banner('Face Match Approvals',
    'Students who could not pass the automated face match and are waiting for invigilator review.', '👤');
?>

<style>
.lg-fa-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:16px; }
.lg-fa-card { border-radius:12px; background:#fff; box-shadow:0 2px 12px rgba(0,0,0,.08); overflow:hidden; }
.lg-fa-card.pending   { border-left:5px solid #fd7e14; }
.lg-fa-card.approved  { border-left:5px solid #28a745; }
.lg-fa-card.denied    { border-left:5px solid #dc3545; opacity:.7; }
.lg-fa-head { padding:14px 16px 10px; }
.lg-fa-passport { width:100%; max-height:180px; object-fit:cover; background:#f8f9fa; display:block; }
.lg-fa-actions { padding:10px 14px; background:#f8f9fa; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.lg-fa-chat  { padding:12px 14px; border-top:1px solid #dee2e6; }
.lg-fa-msgs  { max-height:160px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; margin-bottom:8px; }
.lg-fa-msg   { padding:6px 10px; border-radius:8px; font-size:12px; max-width:90%; }
.lg-fa-msg.proctor { align-self:flex-end; background:#0f6cbf; color:#fff; }
.lg-fa-msg.student { align-self:flex-start; background:#e9ecef; color:#212529; }
.lg-status-badge { padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; color:#fff; display:inline-block; }
.lg-status-pending  { background:#fd7e14; }
.lg-status-approved { background:#28a745; }
.lg-status-denied   { background:#dc3545; }
@keyframes lg-pulse2 { 0%,100%{opacity:1}50%{opacity:.4} }
.pulse { animation:lg-pulse2 1.5s infinite; }
</style>

<?php if (empty($approvals)): ?>
<div class="alert alert-success" style="max-width:600px;">
  <strong>✅ All clear!</strong> No pending face match approval requests at this time.
</div>
<?php else: ?>

<div style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
  <?php
  $pendingCnt  = count(array_filter($approvals, fn($a) => $a->status === 'pending'));
  $approvedCnt = count(array_filter($approvals, fn($a) => $a->status === 'approved'));
  $deniedCnt   = count(array_filter($approvals, fn($a) => $a->status === 'denied'));
  ?>
  <?php if ($pendingCnt > 0): ?>
  <span style="background:#fd7e14;color:#fff;padding:6px 14px;border-radius:20px;font-weight:700;font-size:13px;">
    ⏳ <?php echo $pendingCnt; ?> Pending — students waiting
    <span class="pulse" style="display:inline-block;width:8px;height:8px;background:#fff;border-radius:50%;margin-left:6px;"></span>
  </span>
  <?php endif; ?>
  <span style="background:#28a745;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;">✅ <?php echo $approvedCnt; ?> Approved</span>
  <span style="background:#dc3545;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;">❌ <?php echo $deniedCnt; ?> Denied</span>
  <span style="margin-left:auto;font-size:13px;color:#6c757d;">Auto-refreshes every 15 seconds</span>
</div>

<div class="lg-fa-grid" id="lg-fa-grid">
<?php foreach ($approvals as $fa):
    $student = (object)['firstname' => $fa->firstname, 'lastname' => $fa->lastname];
    $passportSrc = $fa->passportdata
        ? (strpos($fa->passportdata, 'data:') === 0 ? $fa->passportdata : 'data:image/jpeg;base64,' . $fa->passportdata)
        : null;

    // Fetch chat messages for this approval.
    $msgs = [];
    try {
        $msgs = $DB->get_records_sql(
            "SELECT m.*, u.firstname, u.lastname FROM {learnguard_fa_messages} m
              JOIN {user} u ON u.id = m.userid
             WHERE m.approvalid = :aid ORDER BY m.timecreated ASC",
            ['aid' => $fa->id]
        );
    } catch (Exception $e) { $msgs = []; }
?>
<div class="lg-fa-card <?php echo s($fa->status); ?>" id="lg-fa-card-<?php echo $fa->id; ?>">

  <!-- Passport photo -->
  <?php if ($passportSrc): ?>
  <img src="<?php echo htmlspecialchars($passportSrc, ENT_QUOTES); ?>"
       class="lg-fa-passport" alt="Passport photo of <?php echo fullname($student); ?>">
  <?php else: ?>
  <div class="lg-fa-passport" style="display:flex;align-items:center;justify-content:center;color:#6c757d;font-size:13px;">
    No passport photo uploaded
  </div>
  <?php endif; ?>

  <!-- Header -->
  <div class="lg-fa-head">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
      <div>
        <div style="font-weight:700;font-size:15px;"><?php echo fullname($student); ?></div>
        <div style="font-size:12px;color:#6c757d;"><?php echo s($fa->email); ?></div>
      </div>
      <span class="lg-status-badge lg-status-<?php echo s($fa->status); ?>">
        <?php echo $fa->status === 'pending' ? '⏳ Pending' : ($fa->status === 'approved' ? '✅ Approved' : '❌ Denied'); ?>
      </span>
    </div>
    <div style="font-size:12px;color:#495057;margin-bottom:4px;">
      📋 <?php echo format_string($fa->assessmentname); ?>
    </div>
    <div style="font-size:12px;color:#6c757d;">
      📚 <?php echo format_string($fa->coursename); ?>
    </div>
    <div style="margin-top:8px;display:flex;align-items:center;gap:8px;">
      <span style="font-size:12px;color:#6c757d;">Best face match:</span>
      <div style="flex:1;background:#e9ecef;border-radius:6px;height:8px;overflow:hidden;">
        <div style="height:100%;width:<?php echo (int)$fa->matchpct; ?>%;background:<?php echo $fa->matchpct >= 70 ? '#28a745' : ($fa->matchpct >= 50 ? '#fd7e14' : '#dc3545'); ?>;border-radius:6px;"></div>
      </div>
      <strong style="font-size:13px;color:<?php echo $fa->matchpct >= 70 ? '#28a745' : '#dc3545'; ?>;"><?php echo (int)$fa->matchpct; ?>%</strong>
    </div>
    <div style="font-size:11px;color:#adb5bd;margin-top:4px;">
      Requested: <?php echo userdate($fa->timecreated, '%d/%m/%Y %H:%M'); ?>
    </div>
    <?php if ($fa->reviewnote): ?>
    <div style="font-size:12px;color:#495057;background:#f8f9fa;border-radius:6px;padding:6px 10px;margin-top:6px;">
      📝 <?php echo s($fa->reviewnote); ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Action buttons (only for pending) -->
  <?php if ($fa->status === 'pending'): ?>
  <div class="lg-fa-actions">
    <button onclick="lgFaAction(<?php echo $fa->id; ?>,'approve')"
      style="flex:1;background:#28a745;color:#fff;border:none;border-radius:8px;padding:9px;font-size:13px;font-weight:700;cursor:pointer;">
      ✅ Approve
    </button>
    <button onclick="lgFaDeny(<?php echo $fa->id; ?>)"
      style="flex:1;background:#dc3545;color:#fff;border:none;border-radius:8px;padding:9px;font-size:13px;font-weight:700;cursor:pointer;">
      ❌ Deny
    </button>
  </div>
  <?php endif; ?>

  <!-- Chat section -->
  <div class="lg-fa-chat">
    <div style="font-size:11px;font-weight:700;color:#6c757d;letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px;">
      Chat with student
    </div>
    <div class="lg-fa-msgs" id="msgs-<?php echo $fa->id; ?>">
      <?php foreach ($msgs as $m): ?>
      <div class="lg-fa-msg <?php echo $m->sendertype === 'proctor' ? 'proctor' : 'student'; ?>">
        <div style="font-size:10px;opacity:.7;margin-bottom:2px;">
          <?php echo $m->sendertype === 'proctor' ? 'You (Proctor)' : fullname((object)['firstname'=>$m->firstname,'lastname'=>$m->lastname]); ?>
        </div>
        <?php echo htmlspecialchars($m->message, ENT_QUOTES); ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:6px;">
      <input type="text" id="chat-inp-<?php echo $fa->id; ?>"
             placeholder="Type a message…" maxlength="200"
             style="flex:1;border:1px solid #dee2e6;border-radius:6px;padding:6px 10px;font-size:12px;"
             onkeydown="if(event.key==='Enter'){lgFaSendMsg(<?php echo $fa->id; ?>);}">
      <button onclick="lgFaSendMsg(<?php echo $fa->id; ?>)"
        style="background:#0f6cbf;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;">
        Send
      </button>
    </div>
  </div>

</div><!-- /card -->
<?php endforeach; ?>
</div><!-- /grid -->
<?php endif; ?>

<script>
var wwwroot = <?php echo json_encode($CFG->wwwroot); ?>;
var sk      = <?php echo json_encode(sesskey()); ?>;

function ws(method, args, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', wwwroot + '/lib/ajax/service.php?sesskey=' + sk, true);
    xhr.setRequestHeader('Content-Type','application/json');
    xhr.onload = function() {
        try { var r = JSON.parse(xhr.responseText); if(r&&r[0]&&r[0].data) cb(r[0].data); } catch(e){}
    };
    xhr.send(JSON.stringify([{index:0, methodname:method, args:args}]));
}

window.lgFaAction = function(approvalid, action, note) {
    note = note || '';
    ws('mod_learnguard_action_face_approval', {approvalid:approvalid, action:action, note:note}, function(d) {
        if (d.success) { location.reload(); }
    });
};

window.lgFaDeny = function(approvalid) {
    var note = prompt('Optional: reason for denying (will be shown to student):', '');
    if (note === null) return; // cancelled
    lgFaAction(approvalid, 'deny', note);
};

window.lgFaSendMsg = function(approvalid) {
    var inp = document.getElementById('chat-inp-' + approvalid);
    if (!inp || !inp.value.trim()) return;
    var msg = inp.value.trim();
    inp.value = '';
    ws('mod_learnguard_action_face_approval', {approvalid:approvalid, action:'message', note:msg}, function(d) {
        if (d.success) {
            var box = document.getElementById('msgs-' + approvalid);
            if (box) {
                var div = document.createElement('div');
                div.className = 'lg-fa-msg proctor';
                div.innerHTML = '<div style="font-size:10px;opacity:.7;margin-bottom:2px;">You (Proctor)</div>' + msg.replace(/</g,'&lt;');
                box.appendChild(div);
                box.scrollTop = box.scrollHeight;
            }
        }
    });
};

// Auto-refresh every 15 seconds.
setInterval(function() { location.reload(); }, 15000);
</script>

<?php echo $OUTPUT->footer();
