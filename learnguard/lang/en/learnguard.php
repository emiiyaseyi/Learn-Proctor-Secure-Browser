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
 * English language strings for mod_learnguard.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();

// Plugin meta.
$string['modulename']       = 'LearnGuard Assessment';
$string['modulenameplural'] = 'LearnGuard Assessments';
$string['modulename_help']  = 'LearnGuard is a fully integrated assessment and proctoring platform. ' .
    'It uses Moodle\'s Question Bank but provides its own Single Page Application interface — maintaining ' .
    'persistent fullscreen, webcam monitoring, and secure browser controls throughout the entire assessment ' .
    'without any page reloads.';
$string['pluginname']           = 'LearnGuard Assessment';
$string['pluginadministration'] = 'LearnGuard administration';

// Activity settings.
$string['learnguardname']       = 'Assessment name';
$string['assessmentname']       = 'Assessment name';
$string['timelimit']            = 'Time limit';
$string['timelimit_help']       = 'Maximum time allowed for this assessment. Set to 0 for no limit.';
$string['maxattempts']          = 'Maximum attempts';
$string['maxattempts_help']     = 'Maximum number of attempts a student can make. 0 = unlimited.';
$string['questionsperpage']     = 'Questions per page';
$string['shufflequestions']     = 'Shuffle questions';
$string['shuffleanswers']       = 'Shuffle answers within questions';
$string['grading']              = 'Grading';
$string['maximumgrade']         = 'Maximum grade';
$string['maximumgrade_help']    = 'The highest possible grade for this assessment.';
$string['grademethod']          = 'Grading method';
$string['grademethod_highest']  = 'Highest grade';
$string['grademethod_average']  = 'Average grade';
$string['grademethod_first']    = 'First attempt';
$string['grademethod_last']     = 'Last attempt';
$string['instructions']         = 'Exam instructions';
$string['instructions_help']    = 'Instructions shown to students before the assessment begins.';

// Proctoring settings.
$string['proctoring_heading']   = 'Proctoring Settings';
$string['proctoring']           = 'Enable proctoring';
$string['proctoring_help']      = 'Enable Learn Guard proctoring for this assessment.';
$string['proctor_fullscreen']   = 'Enforce fullscreen mode';
$string['proctor_webcam']       = 'Require webcam';
$string['proctor_audio']        = 'Monitor audio';
$string['proctor_tabblock']     = 'Block tab/window switching';
$string['proctor_keyblock']     = 'Block restricted keyboard shortcuts';
$string['proctor_clipboard']    = 'Monitor clipboard';
$string['proctor_snapinterval'] = 'Webcam snapshot interval (seconds)';
$string['proctor_fcmethod']     = 'Face match method';
$string['proctor_env_record']   = 'Require 360° environment recording';
$string['block_mobile']         = 'Block mobile devices';
$string['autosuspend_score']    = 'Auto-suspend when risk score reaches';
$string['autosuspend_score_help'] = 'Automatically suspend the student\'s attempt when their cumulative risk score reaches this threshold. Set to 0 to disable auto-suspension.';
$string['fcmethod_none']        = 'None (no face matching)';
$string['fcmethod_local']       = 'Local (face-api.js)';
$string['fcmethod_bs']          = 'BS API (cloud)';

// Student-facing.
$string['startassessment']      = 'Start Assessment';
$string['resumeassessment']     = 'Resume Assessment';
$string['finishattempt']        = 'Submit Assessment';
$string['confirmfinish']        = 'Are you sure you want to submit your assessment? You cannot change your answers after submission.';
$string['question']             = 'Question';
$string['of']                   = 'of';
$string['previousquestion']     = '← Previous';
$string['nextquestion']         = 'Next →';
$string['saveandnext']          = 'Save and Next →';
$string['timeleft']             = 'Time remaining';
$string['autosaved']            = 'Answer saved automatically';
$string['attemptfinished']      = 'Assessment submitted successfully.';
$string['yourgrade']            = 'Your grade';
$string['attempt']              = 'Attempt {$a}';

// States.
$string['inprogress']           = 'In Progress';
$string['finished']             = 'Finished';
$string['abandoned']            = 'Abandoned';
$string['suspended']            = 'Suspended';

// Navigation summary.
$string['answeredquestions']    = '{$a} questions answered';
$string['notattempted']         = 'Not attempted';
$string['answered']             = 'Answered';
$string['flagged']              = 'Flagged for review';

// Proctoring runtime messages.
$string['proctoring_active']        = '🔒 This assessment is being monitored by Learn Guard.';
$string['fullscreen_exit_warning']  = 'You have exited fullscreen. Return to fullscreen immediately.';
$string['return_to_fullscreen']     = 'Return to Fullscreen';
$string['tab_switch_blocked']       = '⚠️ Tab switching is not allowed during this assessment.';
$string['return_to_assessment']     = 'Return to Assessment';
$string['keyboard_violation']       = '⚠️ Restricted keyboard shortcut blocked.';

// Review.
$string['reviewattempt']            = 'Review attempt';
$string['reviewattempt_help']       = 'Allow students to review their attempt after finishing.';

// Admin / teacher.
$string['allAttempts']              = 'All Attempts';
$string['liveproctor']              = 'Live Proctor Dashboard';
$string['questionbank']             = 'Question Bank';
$string['editquestions']            = 'Edit Questions';
$string['addquestions']             = 'Add questions from question bank';
$string['administration']           = 'Administration';
$string['overview']                 = 'Overview';
$string['recentevents']             = 'Recent Events';
$string['proctors']                 = 'Assigned Proctors';

// Capabilities.
$string['learnguard:view']           = 'View LearnGuard assessment';
$string['learnguard:attempt']        = 'Attempt LearnGuard assessment';
$string['learnguard:viewreports']    = 'View assessment reports';
$string['learnguard:grade']          = 'Grade assessment attempts';
$string['learnguard:liveproctor']    = 'Access live proctor dashboard';
$string['learnguard:manage']         = 'Manage assessment settings';
$string['learnguard:suspendattempt'] = 'Suspend a student\'s active attempt';
$string['learnguard:addinstance']    = 'Add a LearnGuard activity';

// Errors.
$string['error:noattempt']          = 'No active attempt found.';
$string['error:nopermission']       = 'You do not have permission to access this assessment.';
$string['error:attemptclosed']      = 'This attempt has already been submitted.';
$string['error:noquestions']        = 'No questions have been added to this assessment yet.';
$string['error:mobileblocked']      = 'Mobile devices are not permitted for this proctored assessment. Please use a desktop or laptop computer.';
$string['error:gradenegative']      = 'Maximum grade must be a positive number.';
