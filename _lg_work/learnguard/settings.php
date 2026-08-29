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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <http://www.gnu.org/licenses/>.

/**
 * Plugin settings for mod_learnguard — all defaults ON.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // ── Quick links ─────────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading(
        'learnguard_links_heading',
        'LearnGuard Quick Links',
        html_writer::start_div('d-flex gap-2 flex-wrap my-2') .
        html_writer::link(new moodle_url('/mod/learnguard/overview.php'),
            '🗺 Plugin Overview', ['class' => 'btn btn-primary btn-sm']) .
        ' ' .
        html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php'),
            '⚙️ Admin Backend', ['class' => 'btn btn-outline-primary btn-sm']) .
        ' ' .
        html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php?tab=media'),
            '🖼 Media Gallery', ['class' => 'btn btn-outline-secondary btn-sm']) .
        ' ' .
        html_writer::link(new moodle_url('/mod/learnguard/email_test.php'),
            '📧 Test Email', ['class' => 'btn btn-outline-warning btn-sm']) .
        ' ' .
        html_writer::link(new moodle_url('/mod/learnguard/bulkassign.php'),
            '🗂 Bulk Assignment', ['class' => 'btn btn-outline-success btn-sm']) .
        html_writer::end_div()
    ));

    // ══════════════════════════════════════════════════════════════════════
    // BRANDING
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_branding_heading',
        '🎨 Branding', 'Customise how LearnGuard appears to students.'));

    $settings->add(new admin_setting_configtext('learnguard/brand_name',
        get_string('brand_name', 'learnguard'), get_string('brand_name_help', 'learnguard'), '', PARAM_TEXT));
    $settings->add(new admin_setting_configtext('learnguard/brand_primary',
        get_string('brand_primary', 'learnguard'), get_string('brand_primary_help', 'learnguard'), '#0f6cbf', PARAM_TEXT));
    $settings->add(new admin_setting_configtext('learnguard/brand_accent',
        get_string('brand_accent', 'learnguard'), get_string('brand_accent_help', 'learnguard'), '#28a745', PARAM_TEXT));
    $settings->add(new admin_setting_configtext('learnguard/brand_textcolor',
        'Banner / Top-bar Text Colour',
        'Colour of text in the branded banner and exam top bar. Default: #ffffff (white).',
        '#ffffff', PARAM_TEXT));

    // ══════════════════════════════════════════════════════════════════════
    // DEFAULT PROCTORING (all ON by default)
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_proctor_heading',
        '🛡 Default Proctoring Settings',
        'Pre-fill values when creating a new LearnGuard assessment. All are enabled by default.'));

    $settings->add(new admin_setting_configcheckbox('learnguard/proctoring',
        get_string('proctoring', 'learnguard'), get_string('proctoring_help', 'learnguard'), 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_fullscreen',
        get_string('proctor_fullscreen', 'learnguard'), 'Force fullscreen mode during exam.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_webcam',
        get_string('proctor_webcam', 'learnguard'), 'Enable webcam monitoring (phone/person detection, snapshots).', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_audio',
        get_string('proctor_audio', 'learnguard'), 'Monitor audio levels; record clips when noise threshold exceeded.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_tabdetect',
        get_string('proctor_tabblock', 'learnguard'), 'Detect and log tab/window switches.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_keyblock',
        get_string('proctor_keyblock', 'learnguard'), 'Block dangerous keyboard shortcuts (Ctrl+C, F12, etc.).', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_clipboard',
        get_string('proctor_clipboard', 'learnguard'), 'Block copy/paste during exam.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_env_record',
        get_string('proctor_env_record', 'learnguard'), 'Record 360° environment video during precheck.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/block_mobile',
        get_string('block_mobile', 'learnguard'), 'Block mobile devices from taking proctored exams.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/enable_chat',
        get_string('enable_chat', 'learnguard'), get_string('enable_chat_help', 'learnguard'), 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/enable_break',
        get_string('enable_break', 'learnguard'), get_string('enable_break_help', 'learnguard'), 0));
    $settings->add(new admin_setting_configcheckbox('learnguard/enable_sos',
        get_string('enable_sos', 'learnguard'), get_string('enable_sos_help', 'learnguard'), 1));

    // ══════════════════════════════════════════════════════════════════════
    // FACE & OBJECT DETECTION (all ON by default)
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_detection_heading',
        '👤 Face & Object Detection',
        'AI detection runs in the student\'s browser (no server needed). face-api.js loads from CDN automatically.'));

    $settings->add(new admin_setting_configselect('learnguard/proctor_fcmethod',
        'Face Detection Method',
        '"Local" uses face-api.js loaded from CDN automatically — no local installation needed.',
        'Local',
        ['None' => 'None (disabled)', 'Local' => 'Local / CDN (recommended)', 'BS' => 'BioSig API']
    ));

    $settings->add(new admin_setting_configselect('learnguard/face_approval_mode',
        'Face Check Approval Mode',
        '<strong>Auto</strong>: Students proceed as soon as the face check step is completed — no invigilator needed. ' .
        '<strong>Manual</strong>: If face detection fails, a proctor must manually review and approve before the student can start the exam. ' .
        'Set to Auto to remove the requirement for an invigilator to approve uploaded ID / face captures.',
        'auto',
        ['auto' => 'Auto — system approves automatically (no invigilator needed)', 'manual' => 'Manual — invigilator must review and approve']
    ));
    $settings->add(new admin_setting_configcheckbox('learnguard/enable_phone_detect',
        'Phone / Object Detection',
        'Detect mobile phones, books, and extra laptops on webcam using TF.js COCO-SSD (loaded from CDN).', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/enable_2nd_face',
        '2nd Person / Multiple Face Detection',
        'Flag when more than one person is visible in the webcam frame.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/enable_bg_change',
        'Background Change Detection',
        'Flag sudden background changes that may indicate swapping location or using a virtual background.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/enable_face_identity',
        'Live Face Identity Matching',
        'Compare the student\'s live face to their precheck face capture during the exam.', 1));
    $settings->add(new admin_setting_configtext('learnguard/noise_threshold',
        'Background Noise Trigger Threshold',
        'Audio level (0–100) above which background noise is flagged and an audio clip is recorded. ' .
        'Lower = more sensitive (flags quieter sounds). Higher = less sensitive (only flags loud sounds). ' .
        'Default: 8 (strict). Try 15–25 to reduce false positives in noisy environments.',
        8, PARAM_INT));

    $settings->add(new admin_setting_configtext('learnguard/snapinterval',
        'Webcam Snapshot Interval (seconds)',
        'How often to take a periodic webcam snapshot. Default: 30.', 30, PARAM_INT));
    $settings->add(new admin_setting_configtext('learnguard/rndscreenshotinterval',
        'Random Screen/Webcam Capture Interval (seconds)',
        'Capture screen (if shared) or webcam at a random point within this window. 0 = disabled. Default: 60.', 60, PARAM_INT));

    // ══════════════════════════════════════════════════════════════════════
    // RISK SCORES FOR FLAGGED EVENTS
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_scores_heading',
        '⚠️ Risk Scores for Flagged Events',
        'Points added to a student\'s risk score when each violation is detected. Higher = more severe.'));

    $flaggedEvents = [
        'score_tab_switch'       => ['Tab / Window Switch',          5],
        'score_fullscreen_exit'  => ['Fullscreen Exit',              10],
        'score_phone_detected'   => ['Phone Detected',               25],
        'score_2nd_person'       => ['2nd Person / Multiple Faces',  25],
        'score_face_anomaly'     => ['Face Anomaly (Wrong Face / Multiple Faces on Webcam)', 10],
        'score_face_missing'     => ['Face Not Visible (No Face Detected)', 10],
        'score_audio_clip'       => ['High Noise / Audio Triggered', 5],
        'score_bg_change'        => ['Background Change',            10],
        'score_camera_covered'   => ['Camera Covered / Blank',       20],
        'score_key_violation'    => ['Blocked Key Pressed',          5],
        'score_copy_paste'       => ['Copy / Paste Attempt',         5],
        'score_printscreen'      => ['PrintScreen Key',              10],
        'score_head_down'        => ['Head Down (Looking Away)',      5],
        'score_no_blink'         => ['No Blink / Inattention (5 min)', 10],
        'score_reinstatement'    => ['Reinstatement Penalty (applied when a suspended attempt is reinstated)', 10],
    ];
    foreach ($flaggedEvents as $key => [$label, $default]) {
        $settings->add(new admin_setting_configtext(
            'learnguard/' . $key, $label, '', $default, PARAM_INT
        ));
    }

    $settings->add(new admin_setting_configtext('learnguard/autosuspend_score',
        get_string('autosuspend_score', 'learnguard'),
        get_string('autosuspend_score_help', 'learnguard'), 80, PARAM_INT));

    $settings->add(new admin_setting_configtext('learnguard/score_notify_pct',
        'High-Risk Email Notification Threshold (%)',
        'Proctors are emailed when a learner\'s risk score reaches this percentage of the Auto-Suspend score. '
        . 'Default: 50 (email fires at half the auto-suspend threshold). '
        . 'Set to 100 to only notify on actual suspension.',
        50, PARAM_INT));

    $settings->add(new admin_setting_configtext('learnguard/reinstate_threshold_delta',
        'Post-Reinstate Suspension Threshold (extra points)',
        'After a learner is reinstated, they will be re-suspended if their risk score rises by this many points above their score at the time of reinstatement. Default: 20.',
        20, PARAM_INT));

    // ── Break feature settings ─────────────────────────────────────────────
    $settings->add(new admin_setting_heading('learnguard_break_heading',
        '☕ Break Feature Settings',
        'Control the minimum and maximum break duration students may request.'));

    $settings->add(new admin_setting_configtext('learnguard/break_min_minutes',
        'Minimum Break Duration (minutes)',
        'The shortest break a student may request. Default: 5.',
        5, PARAM_INT));

    $settings->add(new admin_setting_configtext('learnguard/break_max_minutes',
        'Maximum Break Duration (minutes)',
        'The longest break a student may request. Default: 10.',
        10, PARAM_INT));

    // ── Check-in pop-up ─────────────────────────────────────────────────────
    $settings->add(new admin_setting_heading('learnguard_checkin_heading',
        '🔔 Check-in Pop-up Settings',
        'When a learner accumulates scored face-missing or loud-noise violations, a check-in '
        . 'dialog pauses the assessment and asks whether to continue or pause.'));

    $settings->add(new admin_setting_configtext('learnguard/checkin_violation_threshold',
        'Check-in Violation Threshold',
        'Number of scored face-missing or loud-noise violations before the check-in dialog appears. '
        . 'Default: 5. Set to 0 to disable the check-in dialog entirely.',
        5, PARAM_INT));

    // ── Head-pose and blink detection thresholds ──────────────────────────
    $settings->add(new admin_setting_heading('learnguard_headpose_heading',
        '👁 Head Pose & Blink Detection Thresholds',
        'Controls for the head-down and no-blink detection checks during assessments.'));

    $settings->add(new admin_setting_configtext('learnguard/head_down_threshold',
        'Head-Down Pixel Threshold',
        'Minimum pixel distance (nose tip Y − eye midpoint Y) to count as "head down". Higher = less sensitive. Default: 80.',
        80, PARAM_INT));

    $settings->add(new admin_setting_configtext('learnguard/head_down_consecutive',
        'Head-Down Consecutive Detections',
        'Number of consecutive 8-second checks that must all show head-down before flagging. Default: 5 (= 40 seconds sustained).',
        5, PARAM_INT));

    $settings->add(new admin_setting_configtext('learnguard/no_blink_period',
        'No-Blink Period (seconds)',
        'How long (in seconds) without a detected blink before flagging inattention. Default: 300 (5 minutes).',
        300, PARAM_INT));

    $settings->add(new admin_setting_configtext('learnguard/no_blink_ear_drop',
        'No-Blink EAR Drop Threshold',
        'Relative Eye Aspect Ratio (EAR) drop required to count as a blink (e.g. 0.08 = 8%). Lower = more sensitive. Default: 0.08.',
        '0.08', PARAM_TEXT));

    // ══════════════════════════════════════════════════════════════════════
    // DEFAULT ASSESSMENT SETTINGS
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_assessment_heading',
        '📋 Default Assessment Settings',
        'Pre-fill values when creating a new LearnGuard assessment.'));

    $settings->add(new admin_setting_configtext('learnguard/default_timelimit',
        'Default Time Limit (minutes)',
        'Pre-filled time limit for new assessments. 0 = no limit.', 60, PARAM_INT));
    $settings->add(new admin_setting_configtext('learnguard/default_maxattempts',
        'Default Max Attempts',
        'Pre-filled maximum attempts for new assessments. 0 = unlimited.', 1, PARAM_INT));
    $settings->add(new admin_setting_configselect('learnguard/default_show_results',
        'Default Result Visibility',
        'When students can see their score after submission.',
        'immediately',
        [
            'immediately' => 'Show immediately after submission',
            'afterclose'  => 'Show only after assessment closes',
            'never'       => 'Never show results to students',
        ]
    ));
    $settings->add(new admin_setting_configcheckbox('learnguard/default_randomize',
        'Randomise Questions by Default',
        'Shuffle question order and pick a random subset by default.', 1));
    $settings->add(new admin_setting_configtext('learnguard/default_question_mark',
        'Default Question Mark',
        'Pre-filled "Default Marks" value when creating a new question in the ' .
        'question bank, and used for imported questions that don\'t specify a ' .
        'mark. Each question can still override this individually.', 1.0, PARAM_FLOAT));

    // ══════════════════════════════════════════════════════════════════════
    // PRE-EXAM EMAIL NOTIFICATIONS
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_email_heading',
        '📧 Pre-Exam Email Notifications',
        'Automatic reminder emails sent to enrolled students before the exam opens.'));

    $settings->add(new admin_setting_heading('learnguard_smtp_info_heading', 'SMTP / Mail Server',
        '⚙️ LearnGuard uses <strong>Moodle\'s outgoing mail configuration</strong>. ' .
        'Configure your SMTP server at: <a href="/admin/settings.php?section=outgoingmailconfig" target="_blank">' .
        'Site admin → Server → Email → Outgoing mail configuration</a>. ' .
        'You can also set a custom "From" address below.'));
    $settings->add(new admin_setting_configtext('learnguard/notify_from_email',
        'From Email Address',
        'Email address notifications are sent from. Leave blank to use Moodle\'s support email.',
        '', PARAM_EMAIL
    ));
    $settings->add(new admin_setting_configtext('learnguard/notify_from_name',
        'From Name',
        'Display name used in email notifications. Default: LearnGuard.',
        'LearnGuard', PARAM_TEXT
    ));
    $settings->add(new admin_setting_configcheckbox('learnguard/notify_email_enabled',
        'Enable Pre-Exam Email Reminders',
        'Send automatic email reminders to students before their exam opens.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/notify_proctors_enabled',
        'Send Proctor Notifications',
        'Email assigned proctors when: exam opens, student is suspended, face mismatch flagged, high-risk threshold reached, ' .
        'or when a user is newly assigned as a proctor or grader.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/notify_high_risk_enabled',
        'Send "High Risk Score" Alert Emails',
        'Email assigned proctors specifically when an attempt crosses the high-risk threshold. Off by default to reduce ' .
        'inbox noise; enable here if you want these alerts. Requires "Send Proctor Notifications" above to also be on.', 0));
    $settings->add(new admin_setting_configtext('learnguard/notify_hours_before',
        'Send Reminder (hours before exam)',
        'How many hours before the exam opens to send the reminder email. Default: 24.', 24, PARAM_INT));
    $settings->add(new admin_setting_configtext('learnguard/notify_email_subject',
        'Email Subject',
        'Subject line for the pre-exam reminder email. Use {assessmentname} and {coursename} as placeholders.',
        'Reminder: Your exam "{assessmentname}" opens soon',
        PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtextarea('learnguard/notify_email_body',
        'Email Body',
        'Body of the pre-exam reminder email. Placeholders: {firstname}, {lastname}, {assessmentname}, {coursename}, {timeopen}, {timelimit}.',
        "Dear {firstname},\n\nThis is a reminder that your proctored assessment \"{assessmentname}\" in {coursename} will open on {timeopen}.\n\nTime allowed: {timelimit}\n\nPlease ensure you have a working webcam and a stable internet connection before starting.\n\nGood luck!\n\nThe {coursename} Team",
        PARAM_RAW
    ));

    // ══════════════════════════════════════════════════════════════════════
    // EXAM INSTRUCTION PAGE (editable default message)
    // ══════════════════════════════════════════════════════════════════════
    // ══════════════════════════════════════════════════════════════════════
    // PROCTOR PERMISSIONS — what human invigilators can see/do
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_proctor_perm_heading',
        '👤 Proctor Backend Permissions',
        'Control what human invigilators can access in the Proctor View (<a href="/mod/learnguard/proctorview.php" target="_blank">/mod/learnguard/proctorview.php</a>).'));

    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_perm_gallery',
        'Proctors can view Media Gallery', 'Allow proctors to see snapshots, screen captures, 360° videos and audio clips.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_perm_reports',
        'Proctors can view Reports', 'Allow proctors to view attempt detail, reports and session playback.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_perm_suspend',
        'Proctors can Suspend / Reinstate', 'Allow proctors to suspend or reinstate student attempts.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_perm_granttime',
        'Proctors can Grant Extra Time', 'Allow proctors to grant extra minutes to students during an exam.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_perm_faceapprove',
        'Proctors can Approve Face Matches', 'Allow proctors to review and approve face match failures.', 1));
    $settings->add(new admin_setting_configcheckbox('learnguard/proctor_perm_export',
        'Proctors can Export Data', 'Allow proctors to export scores and question data.', 0));

    // ══════════════════════════════════════════════════════════════════════
    // STUDENT SELF-CHECK REPORT
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_selfcheck_heading',
        '🔍 Student Self-Check Report',
        'After completing an exam, students see a summary of their detected violations (no details, just a risk overview). Can be overridden per-assessment.'));
    $settings->add(new admin_setting_configcheckbox('learnguard/show_selfcheck_default',
        'Show Self-Check Report by Default',
        'Students see their risk score summary immediately after finishing. Default: enabled. Each assessment can override this.',
        1));

    // ══════════════════════════════════════════════════════════════════════
    // TIMED RELEASE OF RESULTS
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_timeresults_heading',
        '⏰ Timed Release of Results',
        'Control when students can see their scores after completing an exam.'));
    $settings->add(new admin_setting_configselect('learnguard/default_show_results_mode',
        'Default Result Visibility Mode',
        'When to show results to students. Can be overridden per-assessment.',
        'afterdays',
        [
            'immediately' => 'Immediately after submission',
            'afterclose'  => 'After assessment closes',
            'afterdays'   => 'After a set number of days (default: 7)',
            'never'       => 'Never (admin reviews and releases manually)',
        ]
    ));
    $settings->add(new admin_setting_configtext('learnguard/default_results_delay_days',
        'Default Delay Before Results Visible (days)',
        'Number of days after submission before results are shown. Default: 7.',
        7, PARAM_INT));

    $settings->add(new admin_setting_heading('learnguard_instruction_heading',
        '📄 Default Exam Instructions',
        'Default instruction message shown to students before they start. Each assessment can override this.'));

    // ══════════════════════════════════════════════════════════════════════
    // RETENTION & STORAGE CLEANUP
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_retention_heading',
        '💾 Retention & Storage Cleanup',
        'The "LearnGuard: GDPR data retention cleanup" scheduled task runs daily and automatically deletes old media and logs according to these settings.'));
    $settings->add(new admin_setting_configtext('learnguard/retention_snapshots',
        'Webcam Snapshot Retention (days)',
        'Number of days to retain periodic webcam snapshots before automatic deletion. Default: 14.',
        14, PARAM_INT));
    $settings->add(new admin_setting_configtext('learnguard/retention_360',
        '360° Recording Retention (days)',
        'Number of days to retain 360° environment recordings before automatic deletion. Default: 7.',
        7, PARAM_INT));
    $settings->add(new admin_setting_configtext('learnguard/retention_events',
        'Event Log Retention (days)',
        'Number of days to retain proctoring event log entries before automatic deletion. Default: 180.',
        180, PARAM_INT));
    $settings->add(new admin_setting_configtext('learnguard/retention_messages',
        'Chat Message Retention (days)',
        'Number of days to retain proctor-student chat messages before automatic deletion. Default: 90.',
        90, PARAM_INT));

    $settings->add(new admin_setting_configtextarea('learnguard/default_instructions',
        'Default Instructions',
        'HTML allowed. Shown on the pre-exam instructions page if the assessment has no custom instructions set.',
        '<p>Please read the following before starting your assessment:</p>
<ul>
  <li>Ensure you are in a quiet, well-lit room with no other people present.</li>
  <li>Your webcam must be on and showing your face clearly throughout the exam.</li>
  <li>You must share your screen when prompted — this is required for monitoring.</li>
  <li>Your screen will be captured periodically at random intervals.</li>
  <li>Tab switching, window switching, and keyboard shortcuts are blocked during the exam.</li>
  <li>The Windows key, Alt, Tab, PrintScreen, and Escape keys are disabled.</li>
  <li>Do not use your mobile phone during the assessment — it will be detected.</li>
  <li>Do not allow any other person to be visible on your camera.</li>
  <li>Your typing pattern and face are verified throughout the session.</li>
  <li>Any violations are automatically logged and reviewed by the proctor.</li>
</ul>
<p>By clicking <strong>Start Assessment</strong>, you confirm you understand and agree to these conditions.</p>',
        PARAM_RAW
    ));

    // ══════════════════════════════════════════════════════════════════════
    // PRIVACY NOTICE
    // ══════════════════════════════════════════════════════════════════════
    $settings->add(new admin_setting_heading('learnguard_privacy_heading',
        '🔒 Privacy & Data Protection Notice',
        'Plain text shown to students in the scrollable Privacy step of the pre-exam wizard. Pre-filled with the current default — edit to match your institution\'s policy.'));

    $settings->add(new admin_setting_configtextarea('learnguard/privacy_notice',
        'Privacy Notice Text',
        'Plain text only (no HTML). Shown inside the Privacy &amp; Data Protection step of the pre-exam wizard.',
<<<'EOT'
LEARNGUARD — PRIVACY & DATA PROTECTION NOTICE

This exam is proctored by LearnGuard. By proceeding, you agree to the following:

DATA COLLECTED
• Webcam snapshots taken at regular intervals during your exam
• A 360° environment recording before the exam starts
• Audio activity levels (volume only — not recorded as audio file)
• Browser events: tab switches, keyboard shortcuts, fullscreen exits
• Session metadata: IP address, browser type, operating system
• Face detection results and integrity risk score

PURPOSE
All data is collected solely to maintain academic integrity.

STORAGE
All data is stored on your institution's Moodle server.

RETENTION
• Webcam snapshots: 14 days
• Environment recordings: 7 days
• Event logs: 180 days

CONSENT
Proceeding constitutes your explicit consent to the above data processing.
EOT,
        PARAM_RAW
    ));
}

// ── Admin sub-pages — registered OUTSIDE $ADMIN->fulltree so they always ──
// appear as expandable sub-items in the Site Admin → Plugins → Activity modules
// → LearnGuard left-nav tree, not only when the full settings page is built.
$ADMIN->add('modsettings', new admin_category('learnguard_admincat', 'LearnGuard'));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_overview',
    'LearnGuard — Overview',
    new moodle_url('/mod/learnguard/overview.php'),
    'moodle/site:config'
));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_siteadmin',
    'LearnGuard — Admin Backend',
    new moodle_url('/mod/learnguard/siteadmin.php'),
    'moodle/site:config'
));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_scorematrix',
    'LearnGuard — Score Matrix',
    new moodle_url('/mod/learnguard/scorematrix.php'),
    'moodle/site:config'
));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_proctorview',
    'LearnGuard — Proctor / Invigilator View',
    new moodle_url('/mod/learnguard/proctorview.php'),
    'mod/learnguard:liveproctor'
));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_bulkassign',
    'LearnGuard — Bulk Assignment',
    new moodle_url('/mod/learnguard/bulkassign.php'),
    'moodle/site:config'
));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_bulkschedule',
    'LearnGuard — Bulk Scheduling',
    new moodle_url('/mod/learnguard/bulkschedule.php'),
    'moodle/site:config'
));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_questionbank',
    'LearnGuard — Question Bank',
    new moodle_url('/mod/learnguard/questionbank.php'),
    'moodle/site:config'
));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_assessmentmanager',
    'LearnGuard — Assessment Manager',
    new moodle_url('/mod/learnguard/assessmentmanager.php'),
    'moodle/site:config'
));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_storage',
    'LearnGuard — Storage Management',
    new moodle_url('/mod/learnguard/storage.php'),
    'moodle/site:config'
));
$ADMIN->add('learnguard_admincat', new admin_externalpage(
    'learnguard_360videos',
    'LearnGuard — 360° Precheck Videos',
    new moodle_url('/mod/learnguard/videos360.php'),
    'moodle/site:config'
));
