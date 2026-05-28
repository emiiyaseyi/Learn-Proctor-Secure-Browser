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
 * Admin settings for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    // -----------------------------------------------------------------------
    // Plugin header — description only, no Pro upsell.
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/pluginheading',
        '',
        html_writer::tag('p', get_string('plugin_description', 'quizaccess_learnshield'))
    ));

    // -----------------------------------------------------------------------
    // User image management link
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/additional_settings',
        get_string('additional_settings', 'quizaccess_learnshield'),
        ''
    ));

    $settings->add(new admin_setting_description(
        'quizaccess_learnshield/adminimage',
        get_string('setting:adminimagepage', 'quizaccess_learnshield'),
        html_writer::div(
            html_writer::link(
                new moodle_url('/mod/quiz/accessrule/learnshield/userslist.php'),
                get_string('setting:userslist', 'quizaccess_learnshield')
            ) .
            html_writer::tag('p', get_string('setting:adminimagedescription', 'quizaccess_learnshield'))
        )
    ));

    // -----------------------------------------------------------------------
    // Webcam capture settings
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/webcam_heading',
        'Webcam Capture',
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/autoreconfigurecamshotdelay',
        get_string('setting:camshotdelay', 'quizaccess_learnshield'),
        get_string('setting:camshotdelay_desc', 'quizaccess_learnshield'),
        30, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/autoreconfigureimagewidth',
        get_string('setting:camshotwidth', 'quizaccess_learnshield'),
        get_string('setting:camshotwidth_desc', 'quizaccess_learnshield'),
        230, PARAM_INT
    ));

    // -----------------------------------------------------------------------
    // Exam controls
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/controls_heading',
        'Exam Security Controls',
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/enablefullscreen',
        get_string('setting:enablefullscreen', 'quizaccess_learnshield'),
        get_string('setting:enablefullscreen_desc', 'quizaccess_learnshield'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/enabletabdetect',
        get_string('setting:enabletabdetect', 'quizaccess_learnshield'),
        get_string('setting:enabletabdetect_desc', 'quizaccess_learnshield'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/enablekeyblock',
        get_string('setting:enablekeyblock', 'quizaccess_learnshield'),
        get_string('setting:enablekeyblock_desc', 'quizaccess_learnshield'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/enableclipboard',
        get_string('setting:enableclipboard', 'quizaccess_learnshield'),
        get_string('setting:enableclipboard_desc', 'quizaccess_learnshield'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/enableprecheck',
        get_string('setting:enableprecheck', 'quizaccess_learnshield'),
        get_string('setting:enableprecheck_desc', 'quizaccess_learnshield'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/fcheckstartchk',
        get_string('settings:fcheckquizstart', 'quizaccess_learnshield'),
        get_string('settings:fcheckquizstart_desc', 'quizaccess_learnshield'),
        0
    ));

    // -----------------------------------------------------------------------
    // Suspicion scoring
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/scoring_heading',
        'Suspicion Scoring',
        'Configure the score added for each type of violation event.'
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/score_tab_switch',
        get_string('setting:score_tab_switch', 'quizaccess_learnshield'),
        get_string('setting:score_tab_switch_desc', 'quizaccess_learnshield'),
        10, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/score_fs_exit',
        get_string('setting:score_fs_exit', 'quizaccess_learnshield'),
        get_string('setting:score_fs_exit_desc', 'quizaccess_learnshield'),
        15, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/score_face_missing',
        get_string('setting:score_face_missing', 'quizaccess_learnshield'),
        get_string('setting:score_face_missing_desc', 'quizaccess_learnshield'),
        20, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/score_keyboard',
        get_string('setting:score_keyboard', 'quizaccess_learnshield'),
        get_string('setting:score_keyboard_desc', 'quizaccess_learnshield'),
        10, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/score_clipboard',
        get_string('setting:score_clipboard', 'quizaccess_learnshield'),
        get_string('setting:score_clipboard_desc', 'quizaccess_learnshield'),
        10, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/medium_risk_threshold',
        get_string('setting:medium_risk_threshold', 'quizaccess_learnshield'),
        get_string('setting:medium_risk_threshold_desc', 'quizaccess_learnshield'),
        30, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/high_risk_threshold',
        get_string('setting:high_risk_threshold', 'quizaccess_learnshield'),
        get_string('setting:high_risk_threshold_desc', 'quizaccess_learnshield'),
        60, PARAM_INT
    ));

    // -----------------------------------------------------------------------
    // Face match method
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/facematch_heading',
        'Face Matching',
        ''
    ));

    $choices = [
        'None' => get_string('none', 'quizaccess_learnshield'),
        'Local' => 'Local (face-api.js — no external API)',
        'BS'    => 'BS API (Brain Station external service)',
    ];
    $settings->add(new admin_setting_configselect(
        'quizaccess_learnshield/fcmethod',
        get_string('setting:fc_method', 'quizaccess_learnshield'),
        get_string('setting:fc_methoddesc', 'quizaccess_learnshield'),
        'None',
        $choices
    ));

    $settings->add(new admin_setting_configselect(
        'quizaccess_learnshield/face_approval_mode',
        'Face Check Approval Mode',
        '<strong>Auto</strong>: Students proceed as soon as the face check step completes — no invigilator review needed. ' .
        '<strong>Manual</strong>: If face detection fails, a proctor must manually approve before the student can start. ' .
        'Set to <em>Auto</em> to remove the requirement for an invigilator to approve uploaded ID / face captures.',
        'auto',
        ['auto' => 'Auto — system approves automatically (no invigilator needed)', 'manual' => 'Manual — invigilator must review and approve']
    ));

    // BS API settings (kept for institutions using the BS face-match service).
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/bsapi',
        get_string('setting:bs_api', 'quizaccess_learnshield'),
        get_string('setting:bs_apidesc', 'quizaccess_learnshield'),
        '', PARAM_TEXT
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'quizaccess_learnshield/bs_api_key',
        get_string('setting:bs_api_key', 'quizaccess_learnshield'),
        get_string('setting:bs_api_keydesc', 'quizaccess_learnshield'),
        '', PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/threshold',
        get_string('setting:bs_apifacematchthreshold', 'quizaccess_learnshield'),
        get_string('setting:bs_bs_apifacematchthresholddesc', 'quizaccess_learnshield'),
        '68', PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/awschecknumber',
        get_string('setting:facematch', 'quizaccess_learnshield'),
        get_string('setting:facematchdesc', 'quizaccess_learnshield'),
        '', PARAM_INT
    ));

    // -----------------------------------------------------------------------
    // Retention policy
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/retention_heading',
        get_string('retention_settings', 'quizaccess_learnshield'),
        ''
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/retention_snapshots',
        get_string('retention_snapshots', 'quizaccess_learnshield'),
        get_string('retention_snapshots_desc', 'quizaccess_learnshield'),
        14, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/retention_event_clips',
        get_string('retention_event_clips', 'quizaccess_learnshield'),
        get_string('retention_event_clips_desc', 'quizaccess_learnshield'),
        30, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/retention_logs',
        get_string('retention_logs', 'quizaccess_learnshield'),
        get_string('retention_logs_desc', 'quizaccess_learnshield'),
        180, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/retention_flagged',
        get_string('retention_flagged', 'quizaccess_learnshield'),
        get_string('retention_flagged_desc', 'quizaccess_learnshield'),
        90, PARAM_INT
    ));

    // -----------------------------------------------------------------------
    // Mobile device blocking
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/mobile_heading',
        'Mobile Device Policy',
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/block_mobile',
        'Block mobile devices from taking proctored exams',
        'When enabled, students attempting to access a proctored quiz from a mobile device or tablet will be blocked and shown an error message.',
        1
    ));
    $settings->add(new admin_setting_configtextarea(
        'quizaccess_learnshield/mobile_blocked_message',
        'Message shown to blocked mobile users',
        '',
        'This exam requires a desktop or laptop computer. Mobile phones and tablets are not permitted for proctored assessments. Please access this exam from a computer with a webcam.',
        PARAM_TEXT
    ));

    // -----------------------------------------------------------------------
    // Auto-suspension settings
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/autosuspend_heading',
        'Auto-Suspension on Violations',
        'Configure automatic learner suspension when integrity thresholds are exceeded.'
    ));
    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/autosuspend_enabled',
        'Enable automatic suspension',
        'Automatically suspend a learner when their risk score or violation count exceeds the configured threshold.',
        0
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/autosuspend_score',
        'Suspend when risk score reaches',
        'Learner is automatically suspended when their integrity risk score reaches this value. Default: 80.',
        80, PARAM_INT
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/autosuspend_flags',
        'Suspend after N violation flags',
        'Learner is suspended after this many individual violation events (tab switches, fullscreen exits, etc.). Default: 10. Set to 0 to disable.',
        10, PARAM_INT
    ));
    $settings->add(new admin_setting_configselect(
        'quizaccess_learnshield/autosuspend_action',
        'Action on auto-suspension',
        '',
        'suspend',
        [
            'suspend'      => 'Suspend session (learner locked out)',
            'warn'         => 'Send warning only (do not suspend)',
            'forcesubmit'  => 'Force-submit the quiz attempt',
        ]
    ));

    // -----------------------------------------------------------------------
    // Email notification settings
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/email_heading',
        'Email Notifications',
        'Configure which events trigger email notifications to teachers and proctors.'
    ));
    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/notify_high_risk',
        'Notify when high risk threshold reached',
        'Send email when a learner\'s risk score crosses the high-risk threshold.',
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/notify_flagged',
        'Notify when session is manually flagged',
        'Send email when a proctor manually flags a learner session.',
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/notify_suspended',
        'Notify when learner is auto-suspended',
        'Send email when a learner is automatically suspended.',
        1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/notify_learner_suspended',
        'Also notify the learner when suspended',
        'Send an email to the learner themselves when they are suspended.',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/notify_inapp',
        'Also send in-app Moodle notifications',
        'In addition to email, send notifications via Moodle\'s built-in messaging system (bell icon). Recipients see them in their Moodle notifications panel.',
        1
    ));

    // -----------------------------------------------------------------------
    // External storage settings
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/storage_heading',
        'External Storage (Optional)',
        'In addition to Moodle\'s built-in storage, optionally mirror recordings and images to Google Drive or OneDrive. Folders are created automatically: Root / Course / Quiz / Learner.'
    ));
    $settings->add(new admin_setting_configselect(
        'quizaccess_learnshield/external_storage_type',
        'External storage provider',
        '',
        'moodle',
        [
            'moodle'   => 'Moodle only (default)',
            'gdrive'   => 'Google Drive (Service Account)',
            'onedrive' => 'OneDrive / SharePoint (Microsoft Graph)',
        ]
    ));
    // Google Drive settings.
    $settings->add(new admin_setting_configtextarea(
        'quizaccess_learnshield/gdrive_service_key',
        'Google Drive — Service Account JSON Key',
        'Paste the full contents of the service account JSON key file downloaded from Google Cloud Console.',
        '', PARAM_RAW
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/gdrive_folder_id',
        'Google Drive — Root Folder ID',
        'The Google Drive folder ID where Learn Shield will create course/quiz subfolders. Share this folder with the service account email.',
        '', PARAM_TEXT
    ));
    // OneDrive settings.
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/onedrive_tenant_id',
        'OneDrive — Azure Tenant ID',
        '',
        '', PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/onedrive_client_id',
        'OneDrive — Azure Client (App) ID',
        '',
        '', PARAM_TEXT
    ));
    $settings->add(new admin_setting_configpasswordunmask(
        'quizaccess_learnshield/onedrive_client_secret',
        'OneDrive — Azure Client Secret',
        '',
        '', PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/onedrive_drive_id',
        'OneDrive — Drive ID',
        'The ID of the OneDrive or SharePoint document library to use.',
        '', PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/onedrive_root_path',
        'OneDrive — Root Folder Path',
        'Path within the drive where Learn Shield files will be stored. Default: LearnShield',
        'LearnShield', PARAM_TEXT
    ));

    // -----------------------------------------------------------------------
    // Environment recording retention
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/retention_environment',
        'Environment recording retention (days)',
        '360° environment recordings are auto-deleted after this many days. Default: 7.',
        7, PARAM_INT
    ));

    // -----------------------------------------------------------------------
    // External pages in the quiz admin menu
    // -----------------------------------------------------------------------
    $ADMIN->add('modsettingsquizcat', new admin_externalpage(
        'quizaccess_learnshield_admin',
        'Learn Shield — Central Administration',
        new moodle_url('/mod/quiz/accessrule/learnshield/admin.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('modsettingsquizcat', new admin_externalpage(
        'quizaccess_learnshield_userslist',
        get_string('users_list', 'quizaccess_learnshield'),
        new moodle_url('/mod/quiz/accessrule/learnshield/userslist.php'),
        'moodle/site:config'
    ));

    $ADMIN->add('modsettingsquizcat', new admin_externalpage(
        'quizaccess_learnshield_auditlog',
        get_string('audit_log', 'quizaccess_learnshield'),
        new moodle_url('/mod/quiz/accessrule/learnshield/auditlog.php'),
        'moodle/site:config'
    ));

    // Proctor view link.
    $ADMIN->add('modsettingsquizcat', new admin_externalpage(
        'quizaccess_learnshield_proctorview',
        'Learn Shield — Proctor / Invigilator View',
        new moodle_url('/mod/quiz/accessrule/learnshield/proctorview.php'),
        'quizaccess/learnshield:liveproctor'
    ));

    // Fix DB link.
    $ADMIN->add('modsettingsquizcat', new admin_externalpage(
        'quizaccess_learnshield_fixdb',
        'Learn Shield — Fix Database (create missing tables)',
        new moodle_url('/mod/quiz/accessrule/learnshield/fix_db.php', ['sesskey' => sesskey()]),
        'moodle/site:config'
    ));

    // -----------------------------------------------------------------------
    // Proctor permissions
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/proctor_perms_heading',
        'Proctor / Invigilator Permissions',
        'Control what invigilators (non-admin proctors) can access in the Proctor View.'
    ));
    foreach ([
        'gallery'     => ['View snapshot gallery',       'Allow proctors to browse the snapshot gallery.', 1],
        'reports'     => ['View reports & attempt data',  'Allow proctors to view attempt reports.', 1],
        'suspend'     => ['Suspend / reinstate learners', 'Allow proctors to suspend and reinstate learners.', 1],
        'granttime'   => ['Grant extra time',             'Allow proctors to grant extra time to learners.', 1],
        'faceapprove' => ['Approve face matches',         'Allow proctors to approve or deny face match requests.', 1],
        'export'      => ['Export data',                  'Allow proctors to export attempt data (CSV/PDF).', 0],
    ] as $key => [$name, $desc, $default]) {
        $settings->add(new admin_setting_configcheckbox(
            "quizaccess_learnshield/proctor_perm_{$key}",
            $name, $desc, $default
        ));
    }

    // -----------------------------------------------------------------------
    // Self-check & timed results
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/selfcheck_heading',
        'Student Self-Check & Results',
        ''
    ));
    $settings->add(new admin_setting_configcheckbox(
        'quizaccess_learnshield/show_selfcheck',
        'Show student self-check summary after exam',
        'Students see a summary of integrity events logged during their exam.',
        1
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/timed_results_delay',
        'Timed results release (hours after submission)',
        'Set to 0 to release results immediately. Set to N to withhold results for N hours after submission.',
        0, PARAM_INT
    ));

    // -----------------------------------------------------------------------
    // Branding
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/brand_heading',
        'Branding',
        'Customise colours shown in the Proctor View.'
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/brandname',
        'Brand / institution name',
        'Shown in the Proctor View header bar.',
        'Learn Shield', PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/brandprimary',
        'Primary colour (hex)',
        'Hex colour for the header bar and primary UI elements.',
        '#0f6cbf', PARAM_TEXT
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/brandaccent',
        'Accent colour (hex)',
        '',
        '#28a745', PARAM_TEXT
    ));

    // -----------------------------------------------------------------------
    // Random screenshot interval
    // -----------------------------------------------------------------------
    $settings->add(new admin_setting_heading(
        'quizaccess_learnshield/randomsnap_heading',
        'Random Screenshot Capture',
        ''
    ));
    $settings->add(new admin_setting_configtext(
        'quizaccess_learnshield/random_screenshot_interval',
        'Random screenshot interval (seconds)',
        'Take a random screenshot every N seconds during the exam. Set to 0 to disable. This is in addition to the regular webcam snapshot interval.',
        0, PARAM_INT
    ));

    // Note: attempts.php and attemptdetail.php are accessed via course context,
    // so they do not need a global external page entry here.

    // -----------------------------------------------------------------------
    // Danger zone: delete all records
    // -----------------------------------------------------------------------
    global $DB;
    $logsexist = false;
    try {
        $dbman = $DB->get_manager();
        $logsexist = $dbman->table_exists('quizaccess_learnshield_logs') &&
            $DB->record_exists('quizaccess_learnshield_logs', ['deletionprogress' => 0]);
    } catch (Exception $e) {
        $logsexist = false; // Tables not yet created — safe to skip.
    }
    if ($logsexist) {

        $pageurl        = new moodle_url('/mod/quiz/accessrule/learnshield/trigger_delete.php', ['sesskey' => sesskey()]);
        $deleteicon      = html_writer::tag('i', '', ['class' => 'fa fa-trash mr-2']);
        $deletealltext   = get_string('settingscontroll:deleteall', 'quizaccess_learnshield');
        $deletealllnktxt = get_string('settingscontroll:deletealllinktext', 'quizaccess_learnshield');

        $deleteallbtn = html_writer::tag('button', $deletealllnktxt, [
            'class'                       => 'btn btn-danger',
            'data-confirmation'           => 'modal',
            'data-confirmation-type'      => 'delete',
            'data-confirmation-title-str' => json_encode(['delete', 'core']),
            'data-confirmation-content-str' => json_encode(['areyousure_delete_all_record', 'quizaccess_learnshield']),
            'data-confirmation-yes-button-str' => json_encode(['delete', 'core']),
            'data-confirmation-action-url' => $pageurl,
            'data-confirmation-destination' => $pageurl,
        ]);

        $deleteallmsg = html_writer::div(
            $deleteicon . ' ' . $deletealltext . ' ' . $deleteallbtn .
            html_writer::tag('p', get_string('settingscontroll:deletealldescription', 'quizaccess_learnshield')),
            'p-1'
        );

        $settings->add(new admin_setting_heading(
            'quizaccess_learnshield/danger_heading',
            'Data Management',
            ''
        ));

        $settings->add(new admin_setting_description(
            'quizaccess_learnshield/deleteallimages',
            get_string('settingscontroll:deleteall', 'quizaccess_learnshield'),
            $deleteallmsg
        ));
    }
}
