<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * LearnGuard Plugin Overview Page.
 *
 * Shows all features of the plugin with links to each section.
 * Accessible to site admins and assessment managers.
 *
 * URL: /mod/learnguard/overview.php
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
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
?>

<style>
.lg-feature-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(320px,1fr)); gap:16px; margin-bottom:24px; }
.lg-feature-card { border:1px solid #dee2e6; border-radius:10px; overflow:hidden; background:#fff; transition:box-shadow .2s; }
.lg-feature-card:hover { box-shadow:0 4px 16px rgba(0,0,0,0.12); }
.lg-feature-card-header { padding:12px 16px; color:#fff; font-weight:700; font-size:14px; display:flex; align-items:center; gap:8px; }
.lg-feature-card-body { padding:14px 16px; }
.lg-feature-link { display:flex; align-items:center; gap:8px; padding:6px 0; border-bottom:1px solid #f5f5f5; text-decoration:none; color:#212529; font-size:13px; }
.lg-feature-link:last-child { border-bottom:none; }
.lg-feature-link:hover { color:<?php echo $brand->primary; ?>; }
.lg-badge-phase { display:inline-block; padding:1px 8px; border-radius:10px; font-size:10px; font-weight:700; }
</style>

<?php
echo learnguard_banner('LearnGuard — Complete Feature Overview',
    'Enterprise Proctoring & Assessment Platform for Moodle', '🛡');

// Stats bar.
$totalAssessments = $DB->count_records('learnguard');
$totalAttempts    = $DB->count_records('learnguard_attempts');
$totalQuestions   = $DB->count_records('learnguard_qbank');
$totalSnapshots   = $DB->count_records('learnguard_snapshots');

echo '<div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:24px;">';
foreach ([
    ['Assessments', $totalAssessments, $brand->primary],
    ['Attempts',    $totalAttempts,    '#6f42c1'],
    ['Questions',   $totalQuestions,   '#0d6efd'],
    ['Snapshots',   $totalSnapshots,   '#17a2b8'],
] as [$label, $val, $col]) {
    echo '<div style="background:' . $col . ';color:#fff;padding:12px 20px;border-radius:8px;text-align:center;min-width:100px;">';
    echo '<div style="font-size:22px;font-weight:700;">' . $val . '</div>';
    echo '<div style="font-size:11px;opacity:.85;">' . $label . '</div>';
    echo '</div>';
}
echo '</div>';

// Helper to render a feature section card.
function featureCard(string $title, string $icon, string $colour, array $links): string {
    $html  = '<div class="lg-feature-card">';
    $html .= '<div class="lg-feature-card-header" style="background:' . $colour . ';">' . $icon . ' ' . htmlspecialchars($title) . '</div>';
    $html .= '<div class="lg-feature-card-body">';
    foreach ($links as $link) {
        $url  = $link['url'] ?? '#';
        $lbl  = $link['label'];
        $desc = $link['desc'] ?? '';
        $html .= '<a href="' . $url . '" class="lg-feature-link">';
        $html .= '<span style="min-width:20px;">→</span>';
        $html .= '<span><strong>' . htmlspecialchars($lbl) . '</strong>';
        if ($desc) { $html .= '<br><small style="color:#6c757d;">' . htmlspecialchars($desc) . '</small>'; }
        $html .= '</span></a>';
    }
    $html .= '</div></div>';
    return $html;
}

$wwwroot = $CFG->wwwroot;
echo '<div class="lg-feature-grid">';

// Assessment Management.
echo featureCard('Assessment Management', '📋', '#0f6cbf', [
    ['url' => $wwwroot . '/mod/learnguard/siteadmin.php?tab=assessments', 'label' => 'All Assessments', 'desc' => 'View all assessments across courses'],
    ['url' => $wwwroot . '/course/index.php', 'label' => 'Create Assessment', 'desc' => 'Add LearnGuard activity to a course'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin.php?tab=questions', 'label' => 'Question Bank', 'desc' => 'Manage all questions site-wide'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin.php?tab=branding', 'label' => 'Branding Settings', 'desc' => 'Customize colours and name'],
]);

// Proctoring & Live Monitoring.
echo featureCard('Proctoring & Live Monitoring', '🔴', '#dc3545', [
    ['url' => $wwwroot . '/mod/learnguard/siteadmin.php?tab=overview', 'label' => 'Site Overview', 'desc' => 'All active sessions across all assessments'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin.php?tab=highrisk', 'label' => 'High Risk Attempts', 'desc' => 'All medium and high-risk sessions'],
    ['url' => '#', 'label' => 'Live Dashboard (per assessment)', 'desc' => 'Go to Assessment → 🔴 Live Monitor'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin.php?tab=media', 'label' => 'Media Gallery', 'desc' => 'All webcam snapshots & 360° recordings'],
    ['url' => '#', 'label' => 'Session Playback', 'desc' => 'Attempt Detail → ▶ Session Playback'],
]);

// Proctor Management.
echo featureCard('Proctor Management', '👤', '#6f42c1', [
    ['url' => $wwwroot . '/mod/learnguard/siteadmin.php?tab=proctors', 'label' => 'Global Proctors', 'desc' => 'Users who proctor ALL assessments site-wide'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin.php?tab=proctors', 'label' => 'Per-Assessment Proctors', 'desc' => 'Assign proctors to specific assessments'],
    ['url' => '#', 'label' => 'Proctor Chat', 'desc' => 'Live Dashboard → message individual students'],
    ['url' => '#', 'label' => 'Break Management', 'desc' => 'Approve/deny student break requests'],
]);

// Reports & Grading.
echo featureCard('Reports & Grading', '📊', '#28a745', [
    ['url' => '#', 'label' => 'Attempt Reports', 'desc' => 'Assessment → 📊 Reports'],
    ['url' => '#', 'label' => 'Attempt Detail', 'desc' => 'View snapshots, events, responses per student'],
    ['url' => '#', 'label' => 'Grade Essays', 'desc' => 'Manual grading for essay question responses'],
    ['url' => '#', 'label' => 'PDF Report', 'desc' => 'Attempt Detail → 📄 Download PDF Report'],
    ['url' => '#', 'label' => 'Incident Management', 'desc' => 'Create and resolve proctor incidents'],
]);

// Student Experience.
echo featureCard('Student Experience', '🎓', '#0d6efd', [
    ['url' => '#', 'label' => 'Pre-Exam Wizard (13 steps)', 'desc' => 'Browser, internet, camera, audio, face, ID, keystroke, monitor, 360°, privacy, fullscreen'],
    ['url' => '#', 'label' => 'Calculator', 'desc' => 'Built-in calculator (Alt+C)'],
    ['url' => '#', 'label' => 'Scratch Pad', 'desc' => 'Digital notepad (Alt+N)'],
    ['url' => '#', 'label' => 'Text Zoom', 'desc' => 'Increase/decrease question text size'],
    ['url' => '#', 'label' => 'Question Flagging', 'desc' => 'Flag questions for review'],
    ['url' => '#', 'label' => 'Request Break', 'desc' => 'Student can request proctor-approved breaks'],
]);

// Security Features.
echo featureCard('Security Features', '🔒', '#fd7e14', [
    ['url' => '#', 'label' => 'Fullscreen Enforcement', 'desc' => 'Students cannot exit fullscreen'],
    ['url' => '#', 'label' => 'Tab Switch Detection', 'desc' => 'Logged + blocked with overlay'],
    ['url' => '#', 'label' => 'Keyboard Blocking', 'desc' => 'F12, Ctrl+C/V/U, Alt+Tab blocked'],
    ['url' => '#', 'label' => 'Mobile Device Block', 'desc' => 'Prevents exam on mobile browsers'],
    ['url' => '#', 'label' => 'VPN/Proxy Detection', 'desc' => 'Flags suspicious network connections'],
    ['url' => '#', 'label' => 'Exam PIN', 'desc' => 'Students must enter PIN to start'],
    ['url' => '#', 'label' => 'DevTools Detection', 'desc' => 'Detects browser developer tools'],
    ['url' => '#', 'label' => 'Multi-Monitor Detection', 'desc' => 'Flags extended display setups'],
]);

// AI Services.
echo featureCard('AI Services (Phase 4)', '🤖', '#17a2b8', [
    ['url' => $wwwroot . '/mod/learnguard/siteadmin_ai.php', 'label' => 'AI Gateway Settings', 'desc' => 'Configure all AI modules'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin_ai.php?tab=face', 'label' => 'Face Analysis (DeepFace)', 'desc' => 'Identity verification, liveness, multi-person'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin_ai.php?tab=objects', 'label' => 'Object Detection (YOLOv8)', 'desc' => 'Phone, suspicious objects, multi-person'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin_ai.php?tab=gaze', 'label' => 'Gaze Tracking (MediaPipe)', 'desc' => 'Eye tracking, head pose, attention'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin_ai.php?tab=audio', 'label' => 'Audio Analysis (Whisper)', 'desc' => 'Speech detection, transcription'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin_ai.php?tab=essay', 'label' => 'Essay Analysis', 'desc' => 'Plagiarism indicators, AI grading suggestions'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin_ai.php?tab=interview', 'label' => 'AI Interviewing (Ollama)', 'desc' => 'Dynamic questions, scoring, summaries'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin_ai.php?tab=status', 'label' => 'Service Status', 'desc' => 'Health check for all AI services'],
]);

// Administration.
echo featureCard('Administration', '⚙️', '#495057', [
    ['url' => $wwwroot . '/mod/learnguard/siteadmin.php', 'label' => 'Site Administration', 'desc' => 'Full site-wide admin panel'],
    ['url' => $wwwroot . '/mod/learnguard/siteadmin_ai.php', 'label' => 'AI Services Admin', 'desc' => 'Configure AI gateway and services'],
    ['url' => '#', 'label' => 'Emergency Pause', 'desc' => 'Assessment → Admin → ⏸ Pause all students instantly'],
    ['url' => '#', 'label' => 'Delete Attempt', 'desc' => 'Clears all student attempts, allows retry'],
    ['url' => '#', 'label' => 'Unsuspend Student', 'desc' => 'Remove suspension, allow continuation'],
    ['url' => '#', 'label' => 'Grant Extra Time', 'desc' => 'Add minutes to a specific student\'s timer'],
    ['url' => '#', 'label' => 'Force Submit', 'desc' => 'Immediately grade and close a student\'s attempt'],
    ['url' => $wwwroot . '/mod/learnguard/overview.php', 'label' => 'This Overview Page', 'desc' => 'Complete feature listing'],
]);

echo '</div>'; // end grid

// ── Developer section ──────────────────────────────────────────────────────────
echo '<div style="border:2px solid ' . $brand->primary . ';border-radius:12px;padding:24px;max-width:600px;margin:0 auto;text-align:center;">';
echo '<div style="font-size:48px;margin-bottom:12px;">👨‍💻</div>';
echo '<h3 style="margin-bottom:4px;color:' . $brand->primary . ';">Developed by</h3>';
echo '<h2 style="margin-bottom:8px;">Michael Adeniran</h2>';
echo '<p style="color:#6c757d;margin-bottom:4px;font-size:14px;">Plugin Developer · Nigeria 🇳🇬</p>';
echo '<div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;margin-top:12px;">';
echo '<a href="https://www.linkedin.com/in/michaeladeniran/" target="_blank" ' .
     'style="display:flex;align-items:center;gap:6px;color:#0a66c2;text-decoration:none;font-weight:600;font-size:14px;">' .
     '<span style="font-size:20px;">🔗</span> linkedin.com/in/michaeladeniran</a>';
echo '<a href="mailto:michaeladeniransnr@gmail.com" ' .
     'style="display:flex;align-items:center;gap:6px;color:#dc3545;text-decoration:none;font-weight:600;font-size:14px;">' .
     '<span style="font-size:20px;">✉️</span> michaeladeniransnr@gmail.com</a>';
echo '</div>';
echo '<hr style="margin:16px 0;border-color:#dee2e6;">';
echo '<div style="display:flex;gap:16px;justify-content:center;flex-wrap:wrap;">';
echo '<div style="text-align:center;">';
echo '<div style="font-size:13px;font-weight:700;color:#6c757d;">LEARNGUARD</div>';
echo '<div style="font-size:12px;color:#adb5bd;">v' . get_config('learnguard', 'version') . '</div>';
echo '<div style="font-size:11px;color:#adb5bd;">Standalone Activity Module</div>';
echo '</div>';
echo '<div style="border-left:1px solid #dee2e6;margin:0 8px;"></div>';
echo '<div style="text-align:center;">';
echo '<div style="font-size:13px;font-weight:700;color:#6c757d;">LEARNSHIELD</div>';
echo '<div style="font-size:11px;color:#adb5bd;">Quiz Access Rule Plugin</div>';
echo '<a href="' . $CFG->wwwroot . '/mod/quiz/accessrule/learnshield/overview.php" ' .
     'style="font-size:11px;color:' . $brand->primary . ';">View Overview →</a>';
echo '</div>';
echo '</div>';
echo '</div>';

echo $OUTPUT->footer();
