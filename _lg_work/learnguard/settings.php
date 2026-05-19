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
 * Plugin settings for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {

    // ---- Plugin entry points ----
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
        html_writer::link(new moodle_url('/mod/learnguard/siteadmin_ai.php'),
            '🤖 AI Services', ['class' => 'btn btn-outline-info btn-sm']) .
        ' ' .
        html_writer::link(new moodle_url('/mod/learnguard/siteadmin.php?tab=media'),
            '🖼 Media Gallery', ['class' => 'btn btn-outline-secondary btn-sm']) .
        html_writer::end_div()
    ));

    // ---- Branding ----
    $settings->add(new admin_setting_heading(
        'learnguard_branding_heading',
        get_string('branding_heading', 'learnguard'),
        get_string('branding_heading', 'learnguard')
    ));

    $settings->add(new admin_setting_configtext(
        'learnguard/brand_name',
        get_string('brand_name', 'learnguard'),
        get_string('brand_name_help', 'learnguard'),
        '',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'learnguard/brand_primary',
        get_string('brand_primary', 'learnguard'),
        get_string('brand_primary_help', 'learnguard'),
        '#0f6cbf',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'learnguard/brand_accent',
        get_string('brand_accent', 'learnguard'),
        get_string('brand_accent_help', 'learnguard'),
        '#28a745',
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtext(
        'learnguard/brand_textcolor',
        'Banner / Top-bar Text Color',
        'Color of text shown in the branded banner and exam top bar. Default: #ffffff (white).',
        '#ffffff',
        PARAM_TEXT
    ));

    // ---- Default proctoring ----
    $settings->add(new admin_setting_heading(
        'learnguard_defaults_heading',
        'Default Proctoring Settings',
        'These values pre-fill the form when creating a new LearnGuard assessment.'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'learnguard/proctoring', get_string('proctoring', 'learnguard'),
        get_string('proctoring_help', 'learnguard'), 1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'learnguard/proctor_fullscreen', get_string('proctor_fullscreen', 'learnguard'), '', 1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'learnguard/proctor_webcam', get_string('proctor_webcam', 'learnguard'), '', 1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'learnguard/proctor_tabdetect', get_string('proctor_tabblock', 'learnguard'), '', 1
    ));
    $settings->add(new admin_setting_configcheckbox(
        'learnguard/block_mobile', get_string('block_mobile', 'learnguard'), '', 1
    ));
    $settings->add(new admin_setting_configtext(
        'learnguard/autosuspend_score',
        get_string('autosuspend_score', 'learnguard'),
        get_string('autosuspend_score_help', 'learnguard'),
        80, PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'learnguard/rndscreenshotinterval',
        'Random Screenshot Interval (seconds)',
        'Webcam snapshot taken at a random point within this window during the exam. Set to 0 to disable. Default: 60.',
        60, PARAM_INT
    ));
}
