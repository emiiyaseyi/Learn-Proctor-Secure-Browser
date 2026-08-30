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
    ['url' => $base . '/mod/learnguard/videos360.php',               'label' => '360° Video Viewer',         'desc' => 'List and play all precheck 360° environment recordings'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments','label' => 'Live Dashboard (per assessment)',
     'desc' => 'Click an assessment → 🔴 Live Monitor button in the admin page'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments','label' => 'Session Playback',
     'desc' => 'Open any assessment admin → Attempts tab → click an attempt → ▶ Session Playback'],
]);

// Proctor Management.
lgOvCard('Proctor Management', '👤', '#6f42c1', [
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=proctors', 'label' => 'Global Proctors',         'desc' => 'Assign users who proctor ALL assessments site-wide'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=proctors', 'label' => 'Course Proctors',         'desc' => 'Assign a proctor to all assessments in a specific course'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=proctors', 'label' => 'Per-Assessment Proctors', 'desc' => 'Assign proctors to a specific assessment'],
    ['url' => $base . '/mod/learnguard/face_approvals.php',         'label' => 'Face Match Approvals',    'desc' => 'Review and approve students who failed automated face matching'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=inprogress','label' => 'Proctor Chat & Break',   'desc' => 'Open Live Exams → select an exam → Open Full Live Dashboard to chat and manage breaks'],
]);

// Reports & Grading.
lgOvCard('Reports & Grading', '📊', '#28a745', [
    ['url' => $base . '/mod/learnguard/results.php',                 'label' => 'All Assessment Results',  'desc' => 'Site-wide scores, filter by course/assessment/state, CSV export'],
    ['url' => $base . '/mod/learnguard/scorematrix.php',             'label' => 'Score Matrix',            'desc' => 'Cross-course matrix: every student vs. every assessment, with totals/averages, CSV export'],
    ['url' => '#', 'label' => 'All Scores (per assessment)',
     'desc' => 'Open an assessment → 🏆 Scores tab', 'note' => 'Sortable/filterable score list with CSV, Excel and PDF export'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments','label' => 'Attempt Reports',        'desc' => 'Open an assessment → Admin → 📊 Reports for detailed attempt data'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments','label' => 'Grade Essays',           'desc' => 'Open an assessment → Admin → ✍️ Graders tab → Grade Essays Now'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments','label' => 'Incident Management',   'desc' => 'Open an assessment → Attempts tab → click an attempt → ⚠️ Incidents'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments','label' => 'Session Playback',       'desc' => 'Open an assessment → Attempts tab → click an attempt → ▶ Session Playback'],
    ['url' => $base . '/mod/learnguard/export_questions.php?format=csv&sesskey=' . sesskey(), 'label' => 'Export Question Bank', 'desc' => 'Download all questions as CSV for offline use or transfer'],
]);

// Student Experience.
lgOvCard('Student Experience', '🎓', '#0d6efd', [
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments', 'label' => 'Pre-Exam Wizard (14 steps)', 'desc' => 'Close tabs → Browser → Internet → Camera → Mic → Photo ID → Face Match → Screen Share → Keystroke → Monitor → 360° → Privacy → Fullscreen → Ready'],
    ['url' => $base . '/mod/learnguard/instructions_editor.php',       'label' => 'Edit Exam Instructions',      'desc' => 'Customise the instruction message shown before every exam starts'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=branding',    'label' => 'Calculator & Scratch Pad',    'desc' => 'Available in every exam via Alt+C (calculator) and Alt+N (notepad)'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments', 'label' => 'Break Requests',              'desc' => 'Students request breaks from within the exam; proctor approves from Live Dashboard'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments', 'label' => 'Noise Level Meter',           'desc' => 'Live audio bar shown during exam so students know their noise level'],
]);

// Security & Proctoring Features.
lgOvCard('Security Features', '🔒', '#fd7e14', [
    ['url' => $base . '/admin/settings.php?section=modsettinglearnguard', 'label' => 'Fullscreen Enforcement',    'desc' => 'Students cannot exit fullscreen — configure in assessment settings'],
    ['url' => $base . '/admin/settings.php?section=modsettinglearnguard', 'label' => 'Tab Switch Detection',      'desc' => 'Every tab switch is logged and shown to the proctor'],
    ['url' => $base . '/admin/settings.php?section=modsettinglearnguard', 'label' => 'Phone & Object Detection',  'desc' => 'TensorFlow.js (COCO-SSD) detects phones, books, 2nd persons on webcam — runs in browser'],
    ['url' => $base . '/admin/settings.php?section=modsettinglearnguard', 'label' => 'Face Identity Matching',    'desc' => 'Compares live face to passport photo uploaded at precheck — runs in browser via face-api.js'],
    ['url' => $base . '/admin/settings.php?section=modsettinglearnguard', 'label' => 'Screen Capture (Random)',   'desc' => 'Captures student screen at random intervals — configurable in global settings'],
    ['url' => $base . '/admin/settings.php?section=modsettinglearnguard', 'label' => 'Audio Clip Recording',      'desc' => 'Records 8s audio when background noise exceeds threshold'],
    ['url' => $base . '/admin/settings.php?section=modsettinglearnguard', 'label' => 'Key Blocking',              'desc' => 'Windows key, Alt, Tab, PrintScreen, Escape all disabled during exam'],
    ['url' => $base . '/admin/settings.php?section=modsettinglearnguard', 'label' => 'Mobile Device Block',       'desc' => 'Prevents mobile phones and tablets from accessing proctored exams'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=media',          'label' => 'Media Gallery',             'desc' => 'View all webcam snapshots, screen captures, 360° recordings, audio clips'],
]);

// Admin Actions.
lgOvCard('Admin Actions', '⚙️', '#495057', [
    ['url' => $base . '/mod/learnguard/siteadmin.php',                  'label' => 'Admin Backend',            'desc' => 'Full site-wide administration panel — all tabs'],
    ['url' => $base . '/mod/learnguard/results.php',                    'label' => 'All Results',              'desc' => 'All attempt scores site-wide with filtering and CSV export'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=inprogress',   'label' => 'Live Exams Monitor',       'desc' => 'See all currently in-progress exams, force-end, suspend'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=suspended',    'label' => 'Suspended Users',          'desc' => 'Reinstate or delete suspended attempts'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=highrisk',     'label' => 'High Risk Attempts',       'desc' => 'Review and act on high-risk flagged sessions'],
    ['url' => $base . '/mod/learnguard/face_approvals.php',             'label' => 'Face Match Approvals',     'desc' => 'Approve or deny students waiting for identity verification'],
    ['url' => $base . '/mod/learnguard/siteadmin.php?tab=assessments', 'label' => '📅 Per-Learner Scheduling',
     'desc' => 'Open any assessment below → 📅 Schedule tab. Supports individual time windows, course-level bulk scheduling, and CSV import (name, username, email, datestart, dateend, timestart, timeend).'],
    ['url' => $base . '/mod/learnguard/bulkassign.php',                 'label' => 'Bulk Assignment',          'desc' => 'Enable/disable proctoring for all assessments in a course, or individually'],
    ['url' => $base . '/mod/learnguard/storage.php',                    'label' => 'Storage Management',       'desc' => 'View disk usage by media type, filter and delete snapshots'],
    ['url' => $base . '/mod/learnguard/orphan_data_report.php',         'label' => 'Orphaned Data Report',     'desc' => 'Read-only: counts data left behind by past activity deletions'],
    ['url' => $base . '/mod/learnguard/email_test.php',                 'label' => 'Test Email',               'desc' => 'Verify Moodle outgoing mail / SMTP is working'],
    ['url' => $base . '/mod/learnguard/backup_manager.php',             'label' => 'Backup & Restore',         'desc' => 'Download or restore full LearnGuard data backup'],
    ['url' => $base . '/admin/settings.php?section=modsettinglearnguard','label' => 'Plugin Settings',         'desc' => 'Global defaults: proctoring, scoring, email, instructions, SMTP'],
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
$lgplugininfo = core_plugin_manager::instance()->get_plugin_info('mod_learnguard');
$lgversionlabel = $lgplugininfo ? ('v' . $lgplugininfo->release) : ('build ' . get_config('learnguard', 'version'));
echo '<span style="font-size:12px;color:#adb5bd;">LearnGuard ' . s($lgversionlabel) . '</span>';
echo '<a href="' . $base . '/mod/quiz/accessrule/learnshield/overview.php" style="font-size:12px;color:' . $brand->primary . ';">LearnShield Overview →</a>';
echo '</div></div>';

echo $OUTPUT->footer();
