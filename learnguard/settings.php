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
 * Plugin settings page for mod_learnguard.
 *
 * Adds a "Site Administration" link to the Moodle admin panel under
 * Site Administration → Plugins → Activity modules → LearnGuard Assessment.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'learnguard_siteadmin_link',
        get_string('administration', 'learnguard'),
        html_writer::link(
            new moodle_url('/mod/learnguard/siteadmin.php'),
            '🛡 Open LearnGuard Site Administration →',
            ['class' => 'btn btn-primary btn-sm']
        )
    ));

    $settings->add(new admin_setting_heading(
        'learnguard_defaults_heading',
        'Default Proctoring Settings',
        'These values are used as defaults when a new LearnGuard assessment is created.'
    ));

    $settings->add(new admin_setting_configcheckbox(
        'learnguard/proctoring',
        get_string('proctoring', 'learnguard'),
        get_string('proctoring_help', 'learnguard'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'learnguard/proctor_fullscreen',
        get_string('proctor_fullscreen', 'learnguard'),
        '',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'learnguard/proctor_webcam',
        get_string('proctor_webcam', 'learnguard'),
        '',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'learnguard/proctor_tabdetect',
        get_string('proctor_tabblock', 'learnguard'),
        '',
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'learnguard/block_mobile',
        get_string('block_mobile', 'learnguard'),
        '',
        1
    ));

    $settings->add(new admin_setting_configtext(
        'learnguard/autosuspend_score',
        get_string('autosuspend_score', 'learnguard'),
        get_string('autosuspend_score_help', 'learnguard'),
        80,
        PARAM_INT
    ));
}
