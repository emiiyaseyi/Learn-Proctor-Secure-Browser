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
    // External pages in the quiz admin menu
    // -----------------------------------------------------------------------
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

    // Note: attempts.php and attemptdetail.php are accessed via course context,
    // so they do not need a global external page entry here.

    // -----------------------------------------------------------------------
    // Danger zone: delete all records
    // -----------------------------------------------------------------------
    global $DB;
    $dbman = $DB->get_manager();
    if ($dbman->table_exists('quizaccess_learnshield_logs') &&
        $DB->record_exists('quizaccess_learnshield_logs', ['deletionprogress' => 0])) {

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
