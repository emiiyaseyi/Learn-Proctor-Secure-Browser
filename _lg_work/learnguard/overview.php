<?php
// This file is part of Moodle - http://moodle.org/
//
// @package    mod_learnguard
// @author     Michael Adeniran <michaeladeniransnr@gmail.com>
// @copyright  2025 Michael Adeniran
// @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later

/**
 * LearnGuard Plugin Overview — entry point for all plugin features.
 * Linked from: Moodle admin plugin list, view.php teacher buttons.
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$PAGE->set_url('/mod/learnguard/overview.php');
$PAGE->set_context(context_system::instance());
$PAGE->set_title('LearnGuard — Plugin Overview');
$PAGE->set_heading('LearnGuard — Plugin Overview');
$PAGE->set_pagelayout('admin');
$PAGE->requires->css('/mod/learnguard/styles.css');

echo $OUTPUT->header();
$brand = learnguard_brand();
$base  = $CFG->wwwroot;

// ── Live stats ────────────────────────────────────────────────────────────────
$totalAssessments = $DB->count_records('learnguard');
$totalAttempts    = $DB->count_records('learnguard_attempts');
$totalQuestions   = $DB->count_records('learnguard_qbank');
$inprogress       = $DB->count_records('learnguard_attempts', ['state' => 'inprogress']);
$snapshots        = $DB->count_records('learnguard_snapshots');

echo learnguard_banner('LearnGuard — Plugin Overview',
    'Enterprise Proctored Assessment Platform · Version ' . get_config('learnguard', 'version'), '🛡');

// ── Top navigation ────────────────────────────────────────────────────────────
echo '<div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:20px;">';
echo html_writer::link($base . '/admin/index.php',  '← Moodle Admin',   ['class' => 'btn btn-outline-secondary btn-sm']);
echo html_writer::link($base . '/course/index.php', '📚 All Courses',   ['class' => 'btn btn-outline-secondary btn-sm']);
echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php'),  '⚙️ Admin Backend',  ['class' => 'btn btn-outline-primary btn-sm']);
echo html_writer::link(new moodle_url('/mod/learnguard/siteadmin_ai.php'), '🤖 AI Services', ['class' => 'btn btn-outline-info btn-sm']);
echo html_writer::link(new moodle_url('/mod/quiz/accessrule/learnshield/overview.php'), '🛡 LearnShield', ['class' => 'btn btn-outline-secondary btn-sm']);
echo '</div>';

// ── Stats bar ──────────────────────────────────────────────────────────────────
echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">';
foreach ([
    ['Assessments',  $totalAssessments, $brand->primary],
    ['Attempts',     $totalAttempts,    '#6f42c1'],
    ['Active Now',   $inprogress,       '#dc3545'],
    ['Questions',    $totalQuestions,   '#0d6efd'],
    ['Snapshots',    $snapshots,        '#17a2b8'],
] as [$label, $val, $col]) {
    echo '<div style="background:' . $col . ';color:#fff;padding:12px 20px;border-radius:8px;text-align:center;min-width:100px;">';
    echo '<div style="font-size:22px;font-weight:700;">' . $val . '</div>';
    echo '<div style="font-size:11px;opacity:.85;">' . $label . '</div></div>';
}
echo '</div>';
?>

<style>
.lg-ov-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:16px; margin-bottom:24px; }
.lg-ov-card { border:1px solid #dee2e6; border-radius:10px; overflow:hidden; background:#fff; transition:box-shadow .2s; }
.lg-ov-card:hover { box-shadow:0 4px 16px rgba(0,0,0,0.12); }
.lg-ov-head { padding:12px 16px; color:#fff; font-weight:700; font-size:14px; }
.lg-ov-body { padding:12px 16px; }
.lg-ov-link { display:flex; align-items:flex-start; gap:8px; padding:7px 0; border-bottom:1px solid #f5f5f5;
              text-decoration:none; color:#212529; font-size:13px; transition:color .15s; }
.lg-ov-link:last-child { border-bottom:none; }
.lg-ov-link:hover { color:<?php echo $brand->primary; ?>; text-decoration:none; }
.lg-ov-link.no-link { cursor:default; color:#6c757d; }
.lg-ov-link.no-link:hover { color:#6c757d; }
</style>

<?php
// Helper: render a feature card.
// $links = [['url'=>'...','label'=>'...','desc'=>'...','internal'=>false], ...]
// url='#' means contextual (inside an assessment) — shown as note, not link
function lgOvCard(string $title, string $icon, string $colour, array $links): void {
    echo '<div class="lg-ov-card">';
    echo '<div class="lg-ov-head" style="background:' . $colour . ';">' . $icon . ' ' . htmlspecialchars($title) . '</div>';
    echo '<div class="lg-ov-body">';
    foreach ($links as $lnk) {
        $url  = $lnk['url'] ?? '';
        $lbl  = $lnk['label'];
        $desc = $lnk['desc'] ?? '';
        $note = $lnk['note'] ?? '';  // Shown as italic note when no direct link.

        if ($url && $url !== '#') {
            echo '<a href="' . htmlspecialchars($url) . '" class="lg-ov-link">';
            echo '<span style="min-width:16px;color:' . $colour . ';font-weight:700;">→</span>';
        } else {
            echo '<div class="lg-ov-link no-link">';
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

echo '<div class="lg-ov-grid">';

// Assessment Management.
lgOvCard('Assessment Management', '📋', '#0f6cbf', [
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments', 'label' => 'All Assessments',      'desc' => 'View, manage and delete assessments across all courses'],
    ['url' => $base . '/course/index.php',                              'label' => 'Create Assessment',   'desc' => 'Browse courses → Add LearnGuard activity'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=questions',   'label' => 'Question Bank',        'desc' => 'Manage all questions site-wide'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=branding',    'label' => 'Branding Settings',    'desc' => 'Customise colours, plugin name'],
]);

// Live Proctoring.
lgOvCard('Live Proctoring & Monitoring', '🔴', '#dc3545', [
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=inprogress', 'label' => '🟢 Live Exams Now',        'desc' => 'All assessments currently in progress site-wide'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=overview',  'label' => 'Site Overview',             'desc' => 'Dashboard: active, completed, suspended counts'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=highrisk',  'label' => 'High Risk Attempts',        'desc' => 'Medium and high-risk sessions site-wide'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=media',     'label' => 'Media Gallery',             'desc' => 'All snapshots, screen captures, 360° recordings, audio clips'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments','label' => 'Live Dashboard (per assessment)',
     'desc' => 'Click an assessment → 🔴 Live Monitor button in the admin page'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments','label' => 'Session Playback',
     'desc' => 'Open any assessment admin → Attempts tab → click an attempt → ▶ Session Playback'],
]);

// Proctor Management.
lgOvCard('Proctor Management', '👤', '#6f42c1', [
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=proctors', 'label' => 'Global Proctors',        'desc' => 'Assign users who proctor ALL assessments site-wide'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=proctors', 'label' => 'Per-Assessment Proctors','desc' => 'Assign proctors to specific assessments'],
    ['url' => '#', 'label' => 'Proctor Chat',                       'desc' => 'Live Dashboard → chat with individual students',
     'note' => 'Available from Live Dashboard during active sessions'],
    ['url' => '#', 'label' => 'Break Approval',                     'desc' => 'Live Dashboard → approve/deny student break requests',
     'note' => 'Available from Live Dashboard'],
]);

// Reports & Grading.
lgOvCard('Reports & Grading', '📊', '#28a745', [
    ['url' => '#', 'label' => 'Attempt Reports',   'desc' => 'Open any assessment → 📊 Reports',
     'note' => 'Requires a specific assessment'],
    ['url' => '#', 'label' => 'Attempt Detail',    'desc' => 'Snapshots, events, audio clips, responses per student',
     'note' => 'Requires a specific attempt'],
    ['url' => '#', 'label' => 'Grade Essays',      'desc' => 'Assessment Admin → Grade Essays',
     'note' => 'Available when essay questions exist'],
    ['url' => '#', 'label' => 'PDF Report',        'desc' => 'Attempt Detail → 📄 Download PDF Report',
     'note' => 'Requires a specific attempt'],
    ['url' => '#', 'label' => 'Incident Management','desc' => 'Attempt Detail → ⚠️ Incidents',
     'note' => 'Requires a specific attempt'],
]);

// Student Experience.
lgOvCard('Student Experience', '🎓', '#0d6efd', [
    ['url' => '#', 'label' => '13-Step Pre-Exam Wizard',      'desc' => 'Browser, camera, audio, face, Photo ID, keystroke, 360°, privacy, fullscreen',
     'note' => 'Runs automatically at exam start (proctored assessments)'],
    ['url' => '#', 'label' => 'Calculator (Alt+C)',            'desc' => 'Built-in calculator during exam',    'note' => 'Available inside any assessment'],
    ['url' => '#', 'label' => 'Scratch Pad (Alt+N)',           'desc' => 'Digital notepad during exam',        'note' => 'Available inside any assessment'],
    ['url' => '#', 'label' => 'Question Flagging',             'desc' => 'Flag questions for review',          'note' => 'Available inside any assessment'],
    ['url' => '#', 'label' => 'Request Break',                 'desc' => 'Student requests proctor-approved break',
     'note' => 'Available inside proctored assessments'],
    ['url' => '#', 'label' => 'Text Zoom (Alt+± )',            'desc' => 'Increase or decrease question text size',
     'note' => 'Available inside any assessment'],
]);

// Security & Proctoring Features.
lgOvCard('Security Features (Auto-Active)', '🔒', '#fd7e14', [
    ['url' => '#', 'label' => 'Fullscreen Enforcement',   'desc' => 'Students cannot exit fullscreen — overlay blocks immediately', 'note' => 'Enabled in assessment settings'],
    ['url' => '#', 'label' => 'Tab Switch Detection',     'desc' => 'Logged + overlay shown',            'note' => 'Enabled in assessment settings'],
    ['url' => '#', 'label' => 'Phone / Object Detection', 'desc' => 'TensorFlow.js detects phone, book, extra laptop on webcam',
     'note' => 'Runs on student\'s browser — no server needed'],
    ['url' => '#', 'label' => 'Face Identity Matching',   'desc' => 'Compares live face to wizard registration every 5 min',
     'note' => 'Requires webcam + face-api.js loaded'],
    ['url' => '#', 'label' => 'Audio Clip Recording',     'desc' => 'Records 8s audio clip when background noise detected',
     'note' => 'Requires microphone enabled in settings'],
    ['url' => '#', 'label' => 'Background Change',        'desc' => 'Detects sudden scene change in webcam feed', 'note' => 'Runs every 15 seconds'],
    ['url' => '#', 'label' => 'Exam PIN',                 'desc' => 'Students must enter PIN before starting', 'note' => 'Set in assessment settings'],
    ['url' => '#', 'label' => 'Mobile Device Block',      'desc' => 'Blocks access from phones/tablets',  'note' => 'Enabled in assessment settings'],
    ['url' => '#', 'label' => 'Multi-Monitor Detection',  'desc' => 'Flags extended display setups',      'note' => 'Auto-detected'],
]);

// Admin Actions.
lgOvCard('Admin Actions', '⚙️', '#495057', [
    ['url' => $base . '/mod/learnguard/siteadmin.php',                 'label' => 'Admin Backend',           'desc' => 'Full site-wide administration panel'],
    ['url' => $base . '/mod/learnguard/siteadmin_ai.php',              'label' => 'AI Services',             'desc' => 'Configure AI gateway, face detection, object detection'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=media',       'label' => 'Media Gallery',           'desc' => 'All snapshots, screen captures, 360° recordings, audio clips'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=suspended',   'label' => 'Suspended Users',         'desc' => 'All suspended students with reinstate/delete actions'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments', 'label' => 'All Assessments',         'desc' => 'Open any assessment admin to access: Emergency Pause, Delete Attempt, Grant Time, Force Submit'],
    ['url' => $base . '/mod/learnguard/export_questions.php?format=csv&sesskey=' . sesskey(), 'label' => '⬇ Export All Questions', 'desc' => 'Download full question bank as CSV'],
]);

// AI Services.
lgOvCard('AI Services (Phase 4)', '🤖', '#17a2b8', [
    ['url' => $base . '/mod/learnguard/siteadmin_ai.php',                    'label' => 'AI Gateway Settings',     'desc' => 'Configure server URL, API key, enable/disable modules'],
    ['url' => $base . '/mod/learnguard/siteadmin_ai.php?tab=face',           'label' => 'Face Analysis (DeepFace)','desc' => 'Server-side identity verification, liveness'],
    ['url' => $base . '/mod/learnguard/siteadmin_ai.php?tab=objects',        'label' => 'Object Detection (YOLO)', 'desc' => 'Server-side phone/suspicious object analysis'],
    ['url' => $base . '/mod/learnguard/siteadmin_ai.php?tab=audio',          'label' => 'Whisper Transcription',   'desc' => 'Convert audio clips to text'],
    ['url' => $base . '/mod/learnguard/siteadmin_ai.php?tab=essay',          'label' => 'Essay Plagiarism',        'desc' => 'Semantic similarity and plagiarism indicators'],
    ['url' => $base . '/mod/learnguard/siteadmin_ai.php?tab=interview',      'label' => 'AI Interviewing',         'desc' => 'Dynamic questions, scoring via Ollama LLM'],
    ['url' => $base . '/mod/learnguard/siteadmin_ai.php?tab=status',         'label' => 'Service Status',          'desc' => 'Health check — see which AI services are online'],
]);

echo '</div>'; // end grid

// ── Developer section ──────────────────────────────────────────────────────────
echo '<div style="border:2px solid ' . $brand->primary . ';border-radius:12px;padding:24px;max-width:600px;margin:0 auto;text-align:center;">';
echo '<div style="font-size:48px;margin-bottom:10px;">👨‍💻</div>';
echo '<h3 style="margin-bottom:4px;color:' . $brand->primary . ';">Developed by</h3>';
echo '<h2 style="margin-bottom:6px;">Michael Adeniran</h2>';
echo '<p style="color:#6c757d;font-size:14px;">Plugin Developer · Nigeria 🇳🇬</p>';
echo '<div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin-top:12px;">';
echo '<a href="https://www.linkedin.com/in/michaeladeniran/" target="_blank" '
   . 'style="color:#0a66c2;text-decoration:none;font-weight:600;font-size:14px;">🔗 linkedin.com/in/michaeladeniran</a>';
echo '<a href="mailto:michaeladeniransnr@gmail.com" '
   . 'style="color:#dc3545;text-decoration:none;font-weight:600;font-size:14px;">✉️ michaeladeniransnr@gmail.com</a>';
echo '</div>';
echo '<hr style="margin:16px 0;border-color:#dee2e6;">';
echo '<div style="display:flex;gap:20px;justify-content:center;">';
echo '<span style="font-size:12px;color:#adb5bd;">LearnGuard v' . get_config('learnguard', 'version') . '</span>';
echo '<a href="' . $base . '/mod/quiz/accessrule/learnshield/overview.php" style="font-size:12px;color:' . $brand->primary . ';">LearnShield Overview →</a>';
echo '</div></div>';

echo $OUTPUT->footer();
