<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Proctor face-match approval panel for LearnShield.
 *
 * URL: /mod/quiz/accessrule/learnshield/face_approvals.php          (site-wide)
 *      /mod/quiz/accessrule/learnshield/face_approvals.php?cmid=X   (per-quiz)
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../../config.php');

$cmid = optional_param('cmid', 0, PARAM_INT);

if ($cmid) {
    $cm     = get_coursemodule_from_id('quiz', $cmid, 0, false, MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
    require_login($course, false, $cm);
    $context = context_module::instance($cm->id);
    require_capability('quizaccess/learnshield:liveproctor', $context);
} else {
    require_login();
    require_capability('moodle/site:config', context_system::instance());
    $cm = null; $course = null;
}

$PAGE->set_url('/mod/quiz/accessrule/learnshield/face_approvals.php', $cmid ? ['cmid' => $cmid] : []);
$PAGE->set_context($cmid ? context_module::instance($cm->id) : context_system::instance());
$PAGE->set_title('Face Match Approvals — LearnShield');
$PAGE->set_heading($cmid ? format_string($course->fullname) : 'LearnShield — Face Match Approvals');

$where  = $cmid ? 'fa.cmid = :cmid' : '1=1';
$params = $cmid ? ['cmid' => $cmid] : [];

$approvals = [];
try {
    $approvals = $DB->get_records_sql(
        "SELECT fa.id, fa.cmid, fa.userid, fa.status, fa.matchpct, fa.reviewnote,
                fa.reviewedby, fa.passportdata, fa.timecreated, fa.timemodified,
                u.firstname, u.lastname, u.email,
                q.name AS quizname, c.fullname AS coursename, cm2.id AS cmid2
           FROM {quizaccess_learnshield_face_approvals} fa
           JOIN {user}           u   ON u.id = fa.userid
           JOIN {course_modules} cm2 ON cm2.id = fa.cmid
           JOIN {quiz}           q   ON q.id = cm2.instance
           JOIN {course}         c   ON c.id = q.course
          WHERE $where
       ORDER BY
         CASE fa.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
         fa.timecreated DESC",
        $params
    );
} catch (Exception $e) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(
        '⚠️ The face approvals table does not exist yet. ' .
        '<a href="' . (new moodle_url('/mod/quiz/accessrule/learnshield/fix_db.php',
            ['sesskey' => sesskey()]))->out(false) . '" style="font-weight:700;">▶ Fix Database Now</a>',
        'warning'
    );
    echo $OUTPUT->footer();
    exit;
}

echo $OUTPUT->header();
?>

<style>
.ls-fa-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(340px,1fr)); gap:16px; margin-top:16px; }
.ls-fa-card { border-radius:12px; background:#fff; box-shadow:0 2px 12px rgba(0,0,0,.08); overflow:hidden; }
.ls-fa-card.pending   { border-left:5px solid #fd7e14; }
.ls-fa-card.approved  { border-left:5px solid #28a745; }
.ls-fa-card.denied    { border-left:5px solid #dc3545; opacity:.7; }
.ls-fa-head { padding:14px 16px 10px; }
.ls-fa-passport { width:100%; max-height:180px; object-fit:cover; background:#f8f9fa; display:block; }
.ls-fa-actions { padding:10px 14px; background:#f8f9fa; display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.ls-fa-chat  { padding:12px 14px; border-top:1px solid #dee2e6; }
.ls-fa-msgs  { max-height:160px; overflow-y:auto; display:flex; flex-direction:column; gap:6px; margin-bottom:8px; }
.ls-fa-msg   { padding:6px 10px; border-radius:8px; font-size:12px; max-width:90%; }
.ls-fa-msg.proctor { align-self:flex-end; background:#0f6cbf; color:#fff; }
.ls-fa-msg.student { align-self:flex-start; background:#e9ecef; color:#212529; }
.ls-status-badge { padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; color:#fff; display:inline-block; }
.ls-status-pending  { background:#fd7e14; }
.ls-status-approved { background:#28a745; }
.ls-status-denied   { background:#dc3545; }
@keyframes ls-pulse { 0%,100%{opacity:1}50%{opacity:.4} }
.pulse { animation:ls-pulse 1.5s infinite; }
</style>

<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:4px;">
  <h3 style="margin:0;">👤 Face Match Approvals</h3>
  <a href="<?php echo $cmid
      ? (new moodle_url('/mod/quiz/accessrule/learnshield/proctordashboard.php', ['cmid' => $cmid]))->out(false)
      : (new moodle_url('/mod/quiz/accessrule/learnshield/admin.php'))->out(false); ?>"
     class="btn btn-sm btn-outline-secondary">← Back</a>
</div>
<p style="color:#6c757d;font-size:13px;">Students who could not pass automated face detection and are waiting for invigilator review.</p>

<?php if (empty($approvals)): ?>
<div class="alert alert-success" style="max-width:600px;">
  <strong>✅ All clear!</strong> No pending face match approval requests at this time.
</div>
<?php else: ?>

<div style="margin-bottom:16px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
  <?php
  $pendingCnt  = count(array_filter((array)$approvals, fn($a) => $a->status === 'pending'));
  $approvedCnt = count(array_filter((array)$approvals, fn($a) => $a->status === 'approved'));
  $deniedCnt   = count(array_filter((array)$approvals, fn($a) => $a->status === 'denied'));
  ?>
  <?php if ($pendingCnt > 0): ?>
  <span style="background:#fd7e14;color:#fff;padding:6px 14px;border-radius:20px;font-weight:700;font-size:13px;">
    ⏳ <?php echo $pendingCnt; ?> Pending
    <span class="pulse" style="display:inline-block;width:8px;height:8px;background:#fff;border-radius:50%;margin-left:6px;"></span>
  </span>
  <?php endif; ?>
  <span style="background:#28a745;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;">✅ <?php echo $approvedCnt; ?> Approved</span>
  <span style="background:#dc3545;color:#fff;padding:6px 14px;border-radius:20px;font-size:13px;">❌ <?php echo $deniedCnt; ?> Denied</span>
  <span style="margin-left:auto;font-size:13px;color:#6c757d;">Auto-refreshes every 15 seconds</span>
</div>

<div class="ls-fa-grid" id="ls-fa-grid">
<?php foreach ($approvals as $fa):
    $student    = (object)['firstname' => $fa->firstname, 'lastname' => $fa->lastname];
    $passportSrc = $fa->passportdata
        ? (strpos($fa->passportdata, 'data:') === 0
            ? $fa->passportdata
            : 'data:image/jpeg;base64,' . $fa->passportdata)
        : null;

    $msgs = [];
    try {
        $msgs = $DB->get_records_sql(
            "SELECT m.*, u.firstname, u.lastname FROM {quizaccess_learnshield_fa_messages} m
              JOIN {user} u ON u.id = m.userid
             WHERE m.approvalid = :aid ORDER BY m.timecreated ASC",
            ['aid' => $fa->id]
        );
    } catch (Exception $e) { $msgs = []; }
?>
<div class="ls-fa-card <?php echo s($fa->status); ?>" id="ls-fa-card-<?php echo $fa->id; ?>">

  <?php if ($passportSrc): ?>
  <img src="<?php echo htmlspecialchars($passportSrc, ENT_QUOTES); ?>"
       class="ls-fa-passport" alt="Webcam snapshot of <?php echo fullname($student); ?>">
  <?php else: ?>
  <div class="ls-fa-passport" style="display:flex;align-items:center;justify-content:center;color:#6c757d;font-size:13px;height:100px;">
    No snapshot uploaded
  </div>
  <?php endif; ?>

  <div class="ls-fa-head">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:6px;">
      <div>
        <div style="font-weight:700;font-size:15px;"><?php echo fullname($student); ?></div>
        <div style="font-size:12px;color:#6c757d;"><?php echo s($fa->email); ?></div>
      </div>
      <span class="ls-status-badge ls-status-<?php echo s($fa->status); ?>">
        <?php echo $fa->status === 'pending' ? '⏳ Pending' : ($fa->status === 'approved' ? '✅ Approved' : '❌ Denied'); ?>
      </span>
    </div>
    <div style="font-size:12px;color:#495057;margin-bottom:4px;">📋 <?php echo format_string($fa->quizname); ?></div>
    <div style="font-size:12px;color:#6c757d;">📚 <?php echo format_string($fa->coursename); ?></div>
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

  <?php if ($fa->status === 'pending'): ?>
  <div class="ls-fa-actions">
    <button onclick="lsFaAction(<?php echo $fa->id; ?>,'approve')"
      style="flex:1;background:#28a745;color:#fff;border:none;border-radius:8px;padding:9px;font-size:13px;font-weight:700;cursor:pointer;">
      ✅ Approve
    </button>
    <button onclick="lsFaDeny(<?php echo $fa->id; ?>)"
      style="flex:1;background:#dc3545;color:#fff;border:none;border-radius:8px;padding:9px;font-size:13px;font-weight:700;cursor:pointer;">
      ❌ Deny
    </button>
  </div>
  <?php endif; ?>

  <div class="ls-fa-chat">
    <div style="font-size:11px;font-weight:700;color:#6c757d;letter-spacing:.5px;text-transform:uppercase;margin-bottom:6px;">
      Chat with student
    </div>
    <div class="ls-fa-msgs" id="msgs-<?php echo $fa->id; ?>">
      <?php foreach ($msgs as $m): ?>
      <div class="ls-fa-msg <?php echo $m->sendertype === 'proctor' ? 'proctor' : 'student'; ?>">
        <div style="font-size:10px;opacity:.7;margin-bottom:2px;">
          <?php echo $m->sendertype === 'proctor' ? 'You (Invigilator)' : fullname((object)['firstname'=>$m->firstname,'lastname'=>$m->lastname]); ?>
        </div>
        <?php echo htmlspecialchars($m->message, ENT_QUOTES); ?>
      </div>
      <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:6px;">
      <input type="text" id="chat-inp-<?php echo $fa->id; ?>"
             placeholder="Type a message…" maxlength="200"
             style="flex:1;border:1px solid #dee2e6;border-radius:6px;padding:6px 10px;font-size:12px;"
             onkeydown="if(event.key==='Enter'){lsFaSendMsg(<?php echo $fa->id; ?>);}">
      <button onclick="lsFaSendMsg(<?php echo $fa->id; ?>)"
        style="background:#0f6cbf;color:#fff;border:none;border-radius:6px;padding:6px 12px;font-size:12px;cursor:pointer;">
        Send
      </button>
    </div>
  </div>

</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<script>
var wwwroot = <?php echo json_encode($CFG->wwwroot); ?>;
var sk      = <?php echo json_encode(sesskey()); ?>;

function wsCall(method, args, cb) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', wwwroot + '/lib/ajax/service.php?sesskey=' + sk, true);
    xhr.setRequestHeader('Content-Type','application/json');
    xhr.onload = function() {
        try { var r = JSON.parse(xhr.responseText); if(r&&r[0]&&r[0].data) cb(r[0].data); } catch(e){}
    };
    xhr.send(JSON.stringify([{index:0, methodname:method, args:args}]));
}

window.lsFaAction = function(approvalid, action, note) {
    note = note || '';
    wsCall('quizaccess_learnshield_action_face_approval', {approvalid:approvalid, action:action, note:note}, function(d) {
        if (d.success) { location.reload(); }
    });
};

window.lsFaDeny = function(approvalid) {
    var note = prompt('Optional: reason for denying (will be shown to student):', '');
    if (note === null) { return; }
    lsFaAction(approvalid, 'deny', note);
};

window.lsFaSendMsg = function(approvalid) {
    var inp = document.getElementById('chat-inp-' + approvalid);
    if (!inp || !inp.value.trim()) { return; }
    var msg = inp.value.trim();
    inp.value = '';
    wsCall('quizaccess_learnshield_action_face_approval', {approvalid:approvalid, action:'message', note:msg}, function(d) {
        if (d.success) {
            var box = document.getElementById('msgs-' + approvalid);
            if (box) {
                var div = document.createElement('div');
                div.className = 'ls-fa-msg proctor';
                div.innerHTML = '<div style="font-size:10px;opacity:.7;margin-bottom:2px;">You (Invigilator)</div>' +
                    msg.replace(/</g,'&lt;');
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
