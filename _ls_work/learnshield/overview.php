<?php
// This file is part of Moodle - http://moodle.org/
//
// @package    quizaccess_learnshield
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

require_once(__DIR__ . '/../../../../config.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/mod/quiz/accessrule/learnshield/overview.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnShield — Plugin Overview');
$PAGE->set_heading('LearnShield — Plugin Overview');
$PAGE->set_pagelayout('admin');

$bp = '#0f6cbf';

echo $OUTPUT->header();
?>
<style>
.ls-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:24px;}
.ls-card{border:1px solid #dee2e6;border-radius:10px;overflow:hidden;background:#fff;}
.ls-head{padding:12px 16px;color:#fff;font-weight:700;font-size:14px;}
.ls-body{padding:14px 16px;}
.ls-item{display:flex;align-items:flex-start;gap:8px;padding:7px 0;border-bottom:1px solid #f5f5f5;font-size:13px;}
.ls-item:last-child{border-bottom:none;}
</style>

<div style="background:linear-gradient(135deg,<?php echo $bp;?>,#0a4d8c);color:#fff;border-radius:8px;padding:20px 24px;margin-bottom:20px;text-align:center;">
<h2 style="margin:0 0 4px;font-size:20px;">🛡 LearnShield — Quiz Proctoring Plugin</h2>
<p style="margin:0;opacity:.85;font-size:14px;">Proctoring access rule for Moodle Quiz — wraps any existing quiz with enterprise proctoring</p>
</div>

<div class="ls-grid">

<div class="ls-card">
<div class="ls-head" style="background:<?php echo $bp;?>;">✅ Built-in Features</div>
<div class="ls-body">
<?php foreach([
'13-step pre-exam wizard (webcam, audio, face, ID, 360°, keystroke, fullscreen, privacy)',
'Periodic webcam snapshots stored securely','Full-screen enforcement with violation logging',
'Tab switch detection and blocking overlay','Keyboard shortcut blocking (F12, Ctrl+C/V etc.)',
'Clipboard monitoring (copy/paste detection)','Face detection via face-api.js (liveness check)',
'Photo ID capture before exam','360° environment recording','Audio volume monitoring (not recorded)',
'VPN and proxy detection','Multiple monitor detection','DevTools detection',
'VM detection heuristic','Head pose monitoring','Background lighting check',
'Risk score per attempt (auto-suspend threshold)','Privacy policy consent step',
'Mobile device blocking','Keystroke dynamics baseline',
] as $f): ?>
<div class="ls-item"><span>✅</span><span><?php echo htmlspecialchars($f);?></span></div>
<?php endforeach;?>
</div></div>

<div class="ls-card">
<div class="ls-head" style="background:#28a745;">⚙️ How to Enable</div>
<div class="ls-body">
<p style="color:#6c757d;font-size:13px;margin-bottom:12px;">Enabled per-quiz in Moodle quiz settings.</p>
<ol style="padding-left:18px;font-size:13px;line-height:2.2;">
<li>Open Moodle Quiz → Edit Settings</li>
<li>Scroll to <strong>Extra restrictions on attempts</strong></li>
<li>Enable <strong>LearnShield Proctoring</strong></li>
<li>Configure individual features</li>
<li>Save — wizard activates for all future attempts</li>
</ol>
</div></div>

<div class="ls-card">
<div class="ls-head" style="background:#fd7e14;">⚖️ LearnShield vs LearnGuard</div>
<div class="ls-body">
<table style="width:100%;font-size:12px;border-collapse:collapse;">
<tr style="background:#f8f9fa;"><th style="padding:6px;text-align:left;border-bottom:1px solid #dee2e6;">Feature</th>
<th style="padding:6px;text-align:center;border-bottom:1px solid #dee2e6;">LearnShield</th>
<th style="padding:6px;text-align:center;border-bottom:1px solid #dee2e6;">LearnGuard</th></tr>
<?php foreach([
['Uses Moodle Quiz questions','✅','❌'],
['Own question bank','❌','✅'],
['SPA (no page reload)','❌','✅'],
['Pre-exam wizard (11 steps)','✅','✅'],
['Close-tabs step in wizard','✅','✅'],
['Webcam snapshots','✅','✅'],
['Face identity matching','✅','✅'],
['Invigilator face approval','✅','✅'],
['Noise meter (live audio)','✅','✅'],
['LMS chat widget blocking','✅','✅'],
['Always-on key blocking','✅','✅'],
['Live proctor dashboard','✅','✅'],
['Proctor/Invigilator view','✅','✅'],
['Proctor-student chat','✅','✅'],
['Precheck activity tracking','✅','✅'],
['Real-time SSE alerts','✅','✅'],
['Session playback','✅','✅'],
['Auto-suspend on high risk','✅','✅'],
['Face approval panel','✅','✅'],
['PDF reports','❌','✅'],
['Manual essay grading','❌','✅'],
['Exam PIN','❌','✅'],
] as [$f,$ls,$lg]):?>
<tr><td style="padding:5px 8px;border-bottom:1px solid #f5f5f5;"><?php echo $f;?></td>
<td style="text-align:center;padding:5px;border-bottom:1px solid #f5f5f5;"><?php echo $ls;?></td>
<td style="text-align:center;padding:5px;border-bottom:1px solid #f5f5f5;"><?php echo $lg;?></td></tr>
<?php endforeach;?>
</table>
</div></div>

<div class="ls-card">
<div class="ls-head" style="background:#6f42c1;">🔗 Quick Links</div>
<div class="ls-body">
<?php foreach([
['⚙️ LearnShield Admin',        $CFG->wwwroot.'/mod/quiz/accessrule/learnshield/admin.php',         'Central administration dashboard'],
['🛡 Proctor / Invigilator View',$CFG->wwwroot.'/mod/quiz/accessrule/learnshield/proctorview.php',  'Proctor backend — live sessions, precheck, gallery'],
['👤 Face Approvals',            $CFG->wwwroot.'/mod/quiz/accessrule/learnshield/face_approvals.php','Review pending face match approvals'],
['📊 Reports',                   $CFG->wwwroot.'/mod/quiz/accessrule/learnshield/report.php',        'Session and attempt reports'],
['🔧 Fix Database',              $CFG->wwwroot.'/mod/quiz/accessrule/learnshield/fix_db.php?sesskey='.sesskey(), 'Create any missing LearnShield tables'],
['🌐 LearnGuard Overview',       $CFG->wwwroot.'/mod/learnguard/overview.php',                       'Standalone assessment module'],
['⚙️ Moodle Site Admin',         $CFG->wwwroot.'/admin/index.php',                                   'Moodle administration'],
] as [$lbl,$url,$desc]):?>
<div class="ls-item"><span>→</span><span>
<a href="<?php echo $url;?>" style="font-weight:600;color:<?php echo $bp;?>;text-decoration:none;"><?php echo $lbl;?></a><br>
<small style="color:#6c757d;"><?php echo $desc;?></small></span></div>
<?php endforeach;?>
</div></div>

</div>

<div style="border:2px solid <?php echo $bp;?>;border-radius:12px;padding:24px;max-width:600px;margin:0 auto;text-align:center;">
<div style="font-size:48px;margin-bottom:12px;">👨‍💻</div>
<h3 style="margin-bottom:4px;color:<?php echo $bp;?>;">Developed by</h3>
<h2 style="margin-bottom:6px;">Michael Adeniran</h2>
<p style="color:#6c757d;font-size:14px;">Plugin Developer · Nigeria 🇳🇬</p>
<div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin-top:12px;">
<a href="https://www.linkedin.com/in/michaeladeniran/" target="_blank" style="color:#0a66c2;text-decoration:none;font-weight:600;font-size:14px;">🔗 linkedin.com/in/michaeladeniran</a>
<a href="mailto:michaeladeniransnr@gmail.com" style="color:#dc3545;text-decoration:none;font-weight:600;font-size:14px;">✉️ michaeladeniransnr@gmail.com</a>
</div>
<hr style="margin:14px 0;border-color:#dee2e6;">
<div style="font-size:12px;color:#adb5bd;">LearnShield Quiz Proctoring Plugin &nbsp;|&nbsp; Moodle 4.5+</div>
</div>

<?php echo $OUTPUT->footer();
