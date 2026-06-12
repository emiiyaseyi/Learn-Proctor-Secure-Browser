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

$bp   = '#0f6cbf';
$base = $CFG->wwwroot;

echo $OUTPUT->header();
?>
<style>
.ls-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:24px;}
.ls-card{border:1px solid #dee2e6;border-radius:10px;overflow:hidden;background:#fff;}
.ls-head{padding:12px 16px;color:#fff;font-weight:700;font-size:14px;}
.ls-body{padding:14px 16px;}
.ls-item{display:flex;align-items:flex-start;gap:8px;padding:7px 0;border-bottom:1px solid #f5f5f5;font-size:13px;}
.ls-item:last-child{border-bottom:none;}

.ls-ov-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-bottom:24px;}
.ls-ov-card{border:1px solid #dee2e6;border-radius:10px;overflow:hidden;background:#fff;transition:box-shadow .2s;}
.ls-ov-card:hover{box-shadow:0 4px 16px rgba(0,0,0,0.12);}
.ls-ov-head{padding:12px 16px;color:#fff;font-weight:700;font-size:14px;}
.ls-ov-body{padding:12px 16px;}
.ls-ov-link{display:flex;align-items:flex-start;gap:8px;padding:7px 0;border-bottom:1px solid #f5f5f5;
            text-decoration:none;color:#212529;font-size:13px;transition:color .15s;}
.ls-ov-link:last-child{border-bottom:none;}
.ls-ov-link:hover{color:<?php echo $bp;?>;text-decoration:none;}
.ls-ov-link.no-link{cursor:default;color:#6c757d;}
.ls-ov-link.no-link:hover{color:#6c757d;}
</style>

<div style="background:linear-gradient(135deg,<?php echo $bp;?>,#0a4d8c);color:#fff;border-radius:8px;padding:20px 24px;margin-bottom:20px;text-align:center;">
<h2 style="margin:0 0 4px;font-size:20px;">🛡 LearnShield — Quiz Proctoring Plugin</h2>
<p style="margin:0;opacity:.85;font-size:14px;">Proctoring access rule for Moodle Quiz — wraps any existing quiz with enterprise proctoring · Version <?php echo get_config('quizaccess_learnshield', 'version'); ?></p>
</div>

<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;">
<?php
echo html_writer::link($base . '/admin/index.php',  '← Moodle Admin', ['class' => 'btn btn-outline-secondary btn-sm']);
echo html_writer::link($base . '/course/index.php', '📚 All Courses', ['class' => 'btn btn-outline-secondary btn-sm']);
echo html_writer::link(new moodle_url('/mod/quiz/accessrule/learnshield/admin.php'), '⚙️ Central Administration', ['class' => 'btn btn-outline-primary btn-sm']);
echo html_writer::link(new moodle_url('/mod/learnguard/overview.php'), '🛡 LearnGuard Overview', ['class' => 'btn btn-outline-secondary btn-sm']);
?>
</div>

<?php
// Helper: render a feature card with direct or contextual ("note") links.
// $links = [['url'=>'...','label'=>'...','desc'=>'...','note'=>'...'], ...]
// url='#' or empty means contextual (reached from inside a specific quiz) — shown as note, not link.
function lsOvCard(string $title, string $icon, string $colour, array $links): void {
    echo '<div class="ls-ov-card">';
    echo '<div class="ls-ov-head" style="background:' . $colour . ';">' . $icon . ' ' . htmlspecialchars($title) . '</div>';
    echo '<div class="ls-ov-body">';
    foreach ($links as $lnk) {
        $url  = $lnk['url'] ?? '';
        $lbl  = $lnk['label'];
        $desc = $lnk['desc'] ?? '';
        $note = $lnk['note'] ?? '';

        if ($url && $url !== '#') {
            echo '<a href="' . htmlspecialchars($url) . '" class="ls-ov-link">';
            echo '<span style="min-width:16px;color:' . $colour . ';font-weight:700;">→</span>';
        } else {
            echo '<div class="ls-ov-link no-link">';
            echo '<span style="min-width:16px;color:#adb5bd;">·</span>';
        }
        echo '<span><strong>' . htmlspecialchars($lbl) . '</strong>';
        if ($desc) { echo '<br><small style="color:#6c757d;">' . htmlspecialchars($desc) . '</small>'; }
        if ($note) { echo '<br><small style="color:#adb5bd;font-style:italic;">📍 ' . htmlspecialchars($note) . '</small>'; }
        echo '</span>';
        echo ($url && $url !== '#') ? '</a>' : '</div>';
    }
    echo '</div></div>';
}

echo '<div class="ls-ov-grid">';

// Setup & Configuration.
lsOvCard('Setup & Configuration', '📋', $bp, [
    ['url' => '#', 'label' => 'Enable on a Quiz',
     'desc' => 'Quiz → Edit settings → Extra restrictions on attempts → enable LearnShield Proctoring'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/bulkassign.php',  'label' => 'Bulk Assignment',          'desc' => 'Enable/disable proctoring for all quizzes in a course, or individually'],
    ['url' => '#', 'label' => 'Per-Learner Scheduling',
     'desc' => 'Open a proctored quiz → 📅 Schedule tab', 'note' => 'Assign each learner a personal open/close window (native Moodle quiz override), individually or via CSV'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Plugin Settings',        'desc' => 'Global defaults: proctoring features, risk thresholds, face approval mode'],
]);

// Live Proctoring & Monitoring.
lsOvCard('Live Proctoring & Monitoring', '🔴', '#dc3545', [
    ['url' => $base . '/mod/quiz/accessrule/learnshield/proctorview.php?tab=live',      'label' => '🟢 Live Sessions',     'desc' => 'All proctored quiz attempts currently in progress'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/proctorview.php?tab=quizzes',   'label' => 'My Quizzes',           'desc' => 'All quizzes you proctor, with live attempt counts'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/proctorview.php?tab=highrisk',  'label' => 'High Risk Attempts',   'desc' => 'Medium and high-risk sessions across your quizzes'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/proctorview.php?tab=suspended', 'label' => 'Suspended Students',   'desc' => 'Review and reinstate auto-suspended attempts'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/proctorview.php?tab=precheck',  'label' => 'Precheck Activity',    'desc' => 'Real-time precheck wizard progress for every candidate'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/proctorview.php?tab=gallery',   'label' => 'Media Gallery',        'desc' => 'Webcam snapshots, screen captures, 360° recordings, audio clips'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/videos360.php',                 'label' => '360° Video Viewer',    'desc' => 'List and play all precheck 360° environment recordings'],
    ['url' => '#', 'label' => 'Live Dashboard (per quiz)',
     'desc' => 'Open a proctored quiz → 🏠 Dashboard tab', 'note' => 'Grid view of all live webcams/screens for that quiz'],
]);

// Proctor Management.
lsOvCard('Proctor Management', '👤', '#6f42c1', [
    ['url' => $base . '/mod/quiz/accessrule/learnshield/proctorview.php?tab=faceapprove', 'label' => 'Face Match Approvals', 'desc' => 'Review and approve students who failed automated face matching'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/face_approvals.php',              'label' => 'Face Approvals (direct)', 'desc' => 'Direct link to the face approval queue'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/admin.php',                       'label' => 'Assign Proctors',      'desc' => 'Central Administration → find a quiz → assign proctor/invigilator by username or email'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/userslist.php',                   'label' => 'Student ID Photos',    'desc' => 'Manage uploaded passport/ID photos used for face matching'],
    ['url' => '#', 'label' => 'Proctor Chat & Approvals',
     'desc' => 'Open Live Sessions → select a session', 'note' => 'Chat with the candidate and approve/deny face-match requests in real time'],
]);

// Reports & Grading.
lsOvCard('Reports & Grading', '📊', '#28a745', [
    ['url' => '#', 'label' => 'Quiz Grades Report',
     'desc' => 'Open a proctored quiz → 🏆 Grades tab', 'note' => "Moodle's native Quiz Grades report — sort, filter and export scores"],
    ['url' => '#', 'label' => 'Session / Attempt Report',
     'desc' => 'Open a proctored quiz → 📊 Report tab', 'note' => 'Risk scores, violation counts and per-attempt detail'],
    ['url' => '#', 'label' => 'Audit Log',
     'desc' => 'Report tab → 📜 Audit Log', 'note' => 'Full event-by-event proctoring log for a quiz'],
    ['url' => '#', 'label' => 'Incident Review',
     'desc' => 'Report tab → click an attempt → ⚠️ Incidents', 'note' => 'Review and annotate flagged violations'],
    ['url' => '#', 'label' => 'Session Playback',
     'desc' => 'Report tab → click an attempt → ▶ Playback', 'note' => 'Replay snapshots and screen captures in sequence'],
    ['url' => '#', 'label' => 'Excel / PDF Export',
     'desc' => 'Report tab → export buttons', 'note' => 'Download the attempt report as Excel or a print-ready PDF'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/proctorview.php?tab=quizzes', 'label' => 'All Proctored Attempts (per course)',
     'desc' => 'My Quizzes → select a course', 'note' => 'Lists every monitored attempt in that course with risk scores'],
]);

// Student Experience.
lsOvCard('Student Experience', '🎓', '#0d6efd', [
    ['url' => '#', 'label' => 'Pre-Exam Wizard (15 steps)',
     'desc' => 'Welcome → Close tabs → Browser → Internet → Camera → Mic → Photo ID → Face Match → Screen Share → Keystroke → Monitor → 360° → Summary → Privacy → Fullscreen → Ready'],
    ['url' => '#', 'label' => 'Violation Toasts',
     'desc' => 'Students see an on-screen toast for every flagged event during the attempt'],
    ['url' => '#', 'label' => 'Noise Level Meter',
     'desc' => 'Live audio bar shown during precheck so students know their ambient noise level'],
    ['url' => '#', 'label' => 'Privacy Step',
     'desc' => 'Must scroll to bottom of the privacy policy before the consent checkbox unlocks'],
    ['url' => '#', 'label' => 'Dual Countdown',
     'desc' => "Shows the assessment's open/close window before starting, and a closing-soon badge during the attempt (informational — Moodle's quiz timer enforces it)"],
]);

// Security Features.
lsOvCard('Security Features', '🔒', '#fd7e14', [
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Fullscreen Enforcement',   'desc' => 'Students cannot exit fullscreen during the attempt — blocker overlay shown'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Tab Switch Detection',     'desc' => 'Every tab/window switch is logged and shown to the proctor'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Phone & Object Detection', 'desc' => 'YOLOv5n (ONNX) detects phones, books, laptops, keyboards and extra people on webcam — runs in browser'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Face Identity Matching',   'desc' => 'Compares live face to uploaded photo ID every 5 minutes — runs in browser via face-api.js'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Screenshots Every 15s',    'desc' => 'Periodic and random screen captures during the attempt'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Audio Noise Monitoring',   'desc' => 'Records a short audio clip when background noise exceeds the threshold'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Key Blocking',             'desc' => 'Windows key, Alt+Tab, PrintScreen, Escape, DevTools shortcuts disabled during the attempt'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Mobile Device Block',      'desc' => 'Prevents mobile phones and tablets from accessing proctored quizzes'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Multiple Monitor Detection','desc' => 'Detects and logs additional connected displays'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Chat Widget Suppression',  'desc' => 'Hides Moodle chat/messaging widgets during the attempt'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield', 'label' => 'Auto-Suspend on High Risk','desc' => 'Attempt is auto-suspended once the risk score reaches the configured threshold'],
]);

// Admin Actions.
lsOvCard('Admin Actions', '⚙️', '#495057', [
    ['url' => $base . '/mod/quiz/accessrule/learnshield/admin.php',                       'label' => 'Central Administration', 'desc' => 'All courses/quizzes, proctor assignment, emergency pause'],
    ['url' => '#', 'label' => 'Emergency Pause',
     'desc' => 'Central Administration → 🚨 Emergency Pause', 'note' => 'Instantly blocks all proctored assessments site-wide'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/bulkassign.php',                  'label' => 'Bulk Assignment',        'desc' => 'Enable/disable proctoring across many quizzes at once'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/storage.php',                     'label' => 'Storage Management',     'desc' => 'View disk usage by media type, filter and delete snapshots'],
    ['url' => $base . '/mod/quiz/accessrule/learnshield/fix_db.php?sesskey=' . sesskey(), 'label' => 'Fix Database',           'desc' => 'Create any missing LearnShield tables'],
    ['url' => $base . '/admin/settings.php?section=quizaccess_learnshield',               'label' => 'Plugin Settings',        'desc' => 'Global defaults: proctoring, scoring, email, face approval mode'],
    ['url' => $base . '/mod/learnguard/overview.php',                                     'label' => 'LearnGuard Overview',    'desc' => 'Standalone proctored assessment module (sister plugin)'],
]);

echo '</div>'; // end ls-ov-grid
?>

<div class="ls-grid">

<div class="ls-card">
<div class="ls-head" style="background:<?php echo $bp;?>;">✅ Built-in Features</div>
<div class="ls-body">
<?php foreach([
'15-step pre-exam wizard (webcam, audio, face, ID, 360°, keystroke, fullscreen, privacy)',
'Periodic webcam snapshots stored securely','Full-screen enforcement with violation logging',
'Tab switch detection and blocking overlay','Keyboard shortcut blocking (F12, Ctrl+C/V etc.)',
'Clipboard monitoring (copy/paste detection)','Face detection via face-api.js (liveness check)',
'Photo ID capture before exam','360° environment recording','Audio volume monitoring (not recorded)',
'VPN and proxy detection','Multiple monitor detection','DevTools detection',
'VM detection heuristic','Head pose monitoring','Background lighting check',
'Risk score per attempt (auto-suspend threshold)','Privacy policy consent step',
'Mobile device blocking','Keystroke dynamics baseline','Per-learner scheduling with dual countdown',
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
['Pre-exam wizard (15 steps)','✅','✅'],
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
['Per-learner scheduling + countdown','✅','✅'],
['PDF reports','✅','✅'],
['Manual essay grading','❌','✅'],
['Exam PIN','❌','✅'],
['Custom "All Scores" admin page','Native Quiz Grades report','✅'],
] as [$f,$ls,$lg]):?>
<tr><td style="padding:5px 8px;border-bottom:1px solid #f5f5f5;"><?php echo $f;?></td>
<td style="text-align:center;padding:5px;border-bottom:1px solid #f5f5f5;"><?php echo $ls;?></td>
<td style="text-align:center;padding:5px;border-bottom:1px solid #f5f5f5;"><?php echo $lg;?></td></tr>
<?php endforeach;?>
</table>
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
<div style="display:flex;gap:20px;justify-content:center;">
<span style="font-size:12px;color:#adb5bd;">LearnShield v<?php echo get_config('quizaccess_learnshield', 'version'); ?></span>
<a href="<?php echo $base . '/mod/learnguard/overview.php'; ?>" style="font-size:12px;color:<?php echo $bp;?>;">LearnGuard Overview →</a>
</div>
</div>

<?php echo $OUTPUT->footer();
