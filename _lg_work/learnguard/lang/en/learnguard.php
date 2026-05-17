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

// Randomization.
$string['randomize_heading']  = 'Question Randomization';
$string['randomize']          = 'Randomize questions';
$string['randomize_help']     = 'When enabled, each student receives a different random selection of questions from the question pool. Shuffle is also applied to answer options.';
$string['num_questions']      = 'Questions per student';
$string['num_questions_help'] = 'How many questions each student receives. 0 = all questions in the pool. Example: set to 30 to give each student 30 random questions from 100 in the bank.';

// Availability window.
$string['availability']             = 'Availability';
$string['assessmentopen']           = 'Open from';
$string['assessmentopen_help']      = 'The date and time from which students can begin this assessment. Leave unchecked for no start restriction.';
$string['assessmentclose']          = 'Close on';
$string['assessmentclose_help']     = 'The date and time after which students can no longer attempt this assessment. Leave unchecked for no close restriction.';
$string['assessmentnotopen']        = 'This assessment is not yet open. It will open on {$a}.';
$string['assessmentclosed']         = 'This assessment closed on {$a}.';
$string['error:notavailable']       = 'This assessment is not currently available.';
$string['opensoon']                 = 'Opens {$a}';
$string['closesin']                 = 'Closes {$a}';

// Branding.
$string['branding_heading']         = 'Branding & Appearance';
$string['brand_primary']            = 'Primary colour';
$string['brand_primary_help']       = 'Hex colour code used for banners, buttons and top bars across LearnGuard. Default: #0f6cbf';
$string['brand_accent']             = 'Accent colour';
$string['brand_accent_help']        = 'Hex colour for success badges and active elements. Default: #28a745';
$string['brand_name']               = 'Plugin display name';
$string['brand_name_help']          = 'Override the name shown in the assessment header and notifications. Leave blank to use "LearnGuard".';

// Result visibility.
$string['results_heading']          = 'Result Visibility';
$string['show_results']             = 'When to show results to learners';
$string['show_results_help']        = 'Choose when learners can see their grade and feedback. ' .
    'If "Hold until released", learners see only a confirmation screen after submitting, and grades are hidden until you explicitly release them from the Admin Backend.';
$string['show_results_immediate']   = 'Show immediately after submission';
$string['show_results_held']        = 'Hold — release results manually';
$string['results_pending']          = 'Your assessment has been submitted. Your results will be released by your instructor.';
$string['results_released']         = 'Results Released';
$string['release_results']          = 'Release Results to Learners';
$string['release_results_confirm']  = 'Release grades and feedback to all learners now?';
$string['results_already_released'] = 'Results have already been released ({$a}).';
$string['notify_email']             = 'Send email notification';
$string['notify_inapp']             = 'Send in-app Moodle notification';
$string['notify_both']              = 'Send both email and in-app notification';
$string['notify_none']              = 'Do not notify learners';
$string['notification_type']        = 'Notification method';
$string['results_released_subject'] = 'Your LearnGuard assessment results are ready';
$string['results_released_body']    = 'Hi {$a->firstname},

Your results for "{$a->assessmentname}" are now available.

Your grade: {$a->grade} / {$a->maxgrade}

Click here to review your attempt:
{$a->url}

— Learn Guard';
$string['results_released_ok']      = 'Results released and {$a} learner(s) notified.';

// Errors.
$string['error:noattempt']          = 'No active attempt found.';
$string['error:nopermission']       = 'You do not have permission to access this assessment.';
$string['error:attemptclosed']      = 'This attempt has already been submitted.';
$string['error:noquestions']        = 'No questions have been added to this assessment yet.';
$string['error:mobileblocked']      = 'Mobile devices are not permitted for this proctored assessment. Please use a desktop or laptop computer.';
$string['error:gradenegative']      = 'Maximum grade must be a positive number.';
