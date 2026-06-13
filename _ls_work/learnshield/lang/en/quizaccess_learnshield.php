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
 * English language strings for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

defined('MOODLE_INTERNAL') || die();

// -----------------------------------------------------------------------
// Plugin meta
// -----------------------------------------------------------------------
$string['pluginname']        = 'Learn Shield V1';
$string['quizaccess_learnshield'] = 'Learn Shield V1';
$string['plugin_description'] = 'Learn Shield V1 is a fully self-hosted, privacy-first Moodle quiz proctoring plugin. It captures webcam snapshots, enforces fullscreen mode, detects tab switching, monitors keyboard activity, provides a live proctor dashboard with grid monitoring, real-time alerts, a chat system, audit logs, suspicion scoring, and full incident management — all within your own Moodle infrastructure. No mandatory cloud dependency. Developed by Michael Adeniran.';

// -----------------------------------------------------------------------
// General
// -----------------------------------------------------------------------
$string['accessdenied']         = 'Access Denied';
$string['actions']              = 'Actions';
$string['back']                 = 'Back';
$string['cancel']               = 'Cancel';
$string['clear']                = 'Clear';
$string['delete']               = 'Delete';
$string['email']                = 'Email address';
$string['foundtext']            = 'Found';
$string['name']                 = 'Student name';
$string['none']                 = 'None';
$string['nodata']               = 'No data found for the given criteria.';
$string['notenrolled']          = 'You are not enrolled in this course or do not have the required permissions.';
$string['notfoundtext']         = 'Not Found';
$string['nousersfound']         = 'No users found';
$string['nopermission']         = 'You do not have permission to perform this action.';
$string['no_permission']        = 'You do not have proper permission to view this page';
$string['notpermissionreport']  = 'Proctoring reports are disabled for you.';
$string['notrequired']          = 'Not required';
$string['numberofimages']       = 'Number of images';
$string['submit']               = 'Submit';
$string['status']               = 'Validation status';
$string['timemodified']         = 'Last modified';
$string['user']                 = 'Users';
$string['username']             = 'User Name';
$string['quiztitle']            = 'Quiz Title';
$string['viewimages']           = 'View images';
$string['warninglabel']         = 'Warnings';
$string['webcam']               = 'Webcam';
$string['webcampicture']        = 'Captured pictures';
$string['dateverified']         = 'Date and time';
$string['invalidsesskey']       = 'Invalid session key. Please try again.';
$string['invalidtype']          = 'The provided type is invalid.';

// -----------------------------------------------------------------------
// Proctoring controls & consent
// -----------------------------------------------------------------------
$string['proctoringrequired']        = 'Webcam identity validation';
$string['proctoringrequired_help']   = 'Enabling proctoring requires students to be monitored via webcam, with fullscreen enforcement and browser-behaviour tracking during the quiz attempt.';
$string['proctoringrequiredoption']  = 'Enable Learn Shield proctoring';
$string['proctoringheader']          = '<strong>To continue with this quiz you must allow webcam access. Snapshots will be captured at regular intervals. Fullscreen mode will be enforced.</strong>';
$string['proctoringlabel']           = 'I agree to be monitored during this quiz.';
$string['proctoringstatement']       = 'This exam requires webcam access and fullscreen mode.<br />(Please allow webcam access and proceed to fullscreen).';
$string['youmustagree']              = 'You must agree to the monitoring terms before continuing.';

// -----------------------------------------------------------------------
// Webcam / camera
// -----------------------------------------------------------------------
$string['openwebcam']                       = 'Allow your webcam to continue';
$string['photoalttext']                     = 'The screen capture will appear in this box.';
$string['photonotuploaded']                 = 'Photo not uploaded. Please contact the admin.';
$string['videonotavailable']                = 'Video stream not available.';
$string['wrong_during_taking_image']        = 'Something went wrong while capturing the image.';
$string['wrong_during_taking_screenshot']   = 'Something went wrong while taking the screenshot.';
$string['enable_web_camera_before_submitting'] = 'You need to enable your webcam before submitting this quiz!';
$string['info:cameraallow']                 = 'Your camera is now in use.';
$string['warning:cameraallowwarning']       = 'Please allow camera access to proceed.';
$string['facenotfoundoncam']               = 'Face not found. Try moving to a better-lit area.';

// -----------------------------------------------------------------------
// Fullscreen enforcement
// -----------------------------------------------------------------------
$string['fullscreen_enforced']          = 'Fullscreen mode is required for this exam.';
$string['fullscreen_exit_warning']      = 'Warning: You have exited fullscreen mode! Please return to fullscreen immediately.';
$string['fullscreen_exit_logged']       = 'Fullscreen exit has been logged. Repeated violations may result in disqualification.';
$string['fullscreen_return_btn']        = 'Return to Fullscreen';
$string['fullscreen_violation']         = 'Fullscreen exit detected';

// -----------------------------------------------------------------------
// Tab / window switching
// -----------------------------------------------------------------------
$string['tab_switch_warning']           = 'Warning: Tab switching has been detected and logged.';
$string['tab_switch_logged']            = 'Switching away from the exam has been recorded.';
$string['tab_switch_violation']         = 'Tab switch detected';

// -----------------------------------------------------------------------
// Keyboard & clipboard
// -----------------------------------------------------------------------
$string['keyboard_violation_warning']   = 'Warning: A restricted keyboard shortcut was detected.';
$string['clipboard_copy_warning']       = 'Warning: Copy action detected during the exam.';
$string['clipboard_paste_warning']      = 'Warning: Paste action detected during the exam.';
$string['keyboard_violation']           = 'Restricted keyboard shortcut';
$string['clipboard_violation']          = 'Clipboard activity detected';

// -----------------------------------------------------------------------
// Suspicion scoring
// -----------------------------------------------------------------------
$string['suspicion_score']              = 'Integrity risk score';
$string['risk_level_low']               = 'Low Risk';
$string['risk_level_medium']            = 'Medium Risk';
$string['risk_level_high']              = 'High Risk';
$string['risk_score_label']             = 'Risk Score';
$string['score_impact']                 = 'Score impact';

// -----------------------------------------------------------------------
// Face match
// -----------------------------------------------------------------------
$string['facefound']                 = 'Face found in the uploaded image.';
$string['facematch']                 = 'Face match successful. Student identity verified.';
$string['facematched']               = 'Face matched.';
$string['facematchs']                = 'All images have been analysed. Please review them.';
$string['facenotfound']              = 'Face not found in the uploaded image.';
$string['facenotmatched']            = 'Face not matched.';
$string['analyzbtn']                 = 'Analyse';
$string['analyzbtnconfirm']          = 'Click Analyse to perform a face match for this user.';
$string['analyzimage']               = 'Analyse images';
$string['image_not_uploaded']        = 'The uploaded image does not contain any faces.';
$string['error_face_not_found']      = 'Face not found. Please contact the administrator.';
$string['error_invalid_report']      = 'Invalid report data. Please try again.';
$string['invalid_api']               = 'The provided BS API key is invalid.';
$string['invalid_facematch_method']  = 'Invalid face match method. Please configure valid BS API credentials.';
$string['invalid_service_api']       = 'The provided BS service API is invalid.';
$string['invalidapi']                = 'BS API key is invalid. Please contact the admin.';
$string['identity_mismatch_label']   = 'Identity Mismatch';
$string['modal:facevalidation']      = 'Face validated:';
$string['modal:pending']             = 'Pending';
$string['modal:validateface']        = 'Validate face recognition';

// -----------------------------------------------------------------------
// Image management
// -----------------------------------------------------------------------
$string['image']                         = 'Upload Image';
$string['image_updated']                 = 'Image updated';
$string['image_upload']                  = 'Upload image';
$string['upload_first_image']            = 'Please upload user image.';
$string['upload_image']                  = 'Upload image';
$string['upload_image_heading']          = 'Upload user image';
$string['upload_image_info']             = 'Upload images for user identity verification during proctored quizzes.';
$string['upload_image_link_text']        = 'Click here to upload user images.';
$string['upload_image_message']          = 'Learn Shield needs a reference image to verify learner identity.';
$string['upload_image_title']            = 'Upload image for face detection';
$string['uploadimagehere']               = 'Click here to upload the image.';
$string['action_upload_image']           = 'Action';
$string['cancel_image_upload']           = 'Cancelled image upload';
$string['provide_image']                 = 'Please provide an image to upload.';
$string['user_image_not_uploaded']       = 'User image is not uploaded. Please upload the image.';
$string['user_image_not_uploaded_teacher'] = 'User image is not uploaded. Please contact the administrator.';
$string['userimagenotuploaded']          = 'User image is not uploaded.';

// -----------------------------------------------------------------------
// Reports
// -----------------------------------------------------------------------
$string['proctoring_report']         = 'Learn Shield Report';
$string['course_proctoring_summary'] = 'Course Proctoring Summary';
$string['eprotroringreports']        = 'Proctoring report for: ';
$string['eprotroringreportsdesc']    = 'This report lists all webcam snapshots captured during the exam. Verify learner identity by comparing profile pictures with webcam snapshots.';
$string['picturesreport']            = 'View proctoring report';
$string['picturesusedreport']        = 'These are the pictures captured during the quiz.';
$string['reportpage']                = 'Course Proctoring Summary';
$string['studentreport']             = 'Student report';
$string['summarypagedesc']           = 'Summary of proctoring data for this course. You can delete all data for a quiz or the entire course including images and logs.';
$string['report_search_clear']       = 'Clear';
$string['report_search_placeholder'] = 'Search by email or name';
$string['report_search_submit']      = 'Search';
$string['examdata']                  = 'No data available for this exam session. Please check the exam setup or monitoring configurations.';
$string['nodata']                    = 'No data found for the given criteria.';

// -----------------------------------------------------------------------
// Live proctor dashboard
// -----------------------------------------------------------------------
$string['live_dashboard']            = 'Live Proctor Dashboard';
$string['live_dashboard_desc']       = 'Monitor all active exam sessions in real time.';
$string['active_learners']           = 'Active Learners';
$string['active_quizzes']            = 'Active Quizzes';
$string['flagged_sessions']          = 'Flagged Sessions';
$string['total_violations']          = 'Total Violations';
$string['grid_4']                    = '2×2 Grid (4 learners)';
$string['grid_9']                    = '3×3 Grid (9 learners)';
$string['grid_16']                   = '4×4 Grid (16 learners)';
$string['no_active_sessions']        = 'No active sessions at the moment.';
$string['refresh_dashboard']         = 'Refresh';
$string['filter_by_risk']            = 'Filter by risk level';
$string['filter_by_course']          = 'Filter by course';
$string['learner_focus_view']        = 'Learner Focus View';
$string['latest_snapshot']           = 'Latest snapshot';
$string['session_duration']          = 'Session duration';
$string['connectivity_status']       = 'Connectivity';

// -----------------------------------------------------------------------
// Alerts & interventions
// -----------------------------------------------------------------------
$string['alert_tab_switch']         = 'Tab switch detected for {$a}';
$string['alert_fullscreen_exit']    = 'Fullscreen exit detected for {$a}';
$string['alert_face_missing']       = 'Face not visible in webcam for {$a}';
$string['alert_high_risk']          = 'High risk score reached for {$a}';
$string['alert_keyboard_violation'] = 'Keyboard violation detected for {$a}';
$string['new_alert']                = 'New alert';
$string['dismiss_alert']            = 'Dismiss';
$string['flag_learner']             = 'Flag Learner';
$string['unflag_learner']           = 'Unflag Learner';
$string['suspend_session']          = 'Suspend Session';
$string['force_submit']             = 'Force Submit';
$string['send_warning']             = 'Send Warning';
$string['learner_flagged']          = 'Learner has been flagged.';
$string['session_suspended']        = 'Session has been suspended.';
$string['warning_sent']             = 'Warning sent to learner.';

// -----------------------------------------------------------------------
// Chat system
// -----------------------------------------------------------------------
$string['chat_panel']                = 'Proctor Chat';
$string['chat_placeholder']          = 'Type a message…';
$string['send_message']              = 'Send';
$string['broadcast_message']         = 'Broadcast to all';
$string['private_message']           = 'Private message';
$string['chat_message_type_warning'] = 'Warning';
$string['chat_message_type_chat']    = 'Message';
$string['chat_to_learner']           = 'Message to learner';
$string['chat_empty']                = 'No messages yet.';
$string['you']                       = 'You';
$string['proctor']                   = 'Proctor';

// -----------------------------------------------------------------------
// Audit log
// -----------------------------------------------------------------------
$string['audit_log']                 = 'Audit Log';
$string['audit_log_desc']            = 'Complete immutable timeline of all proctoring events for this session.';
$string['event_type']                = 'Event type';
$string['event_data']                = 'Event data';
$string['severity']                  = 'Severity';
$string['severity_info']             = 'Info';
$string['severity_warning']          = 'Warning';
$string['severity_danger']           = 'Danger';
$string['no_events']                 = 'No events recorded.';
$string['export_audit']              = 'Export audit log (CSV)';

// -----------------------------------------------------------------------
// Incident management
// -----------------------------------------------------------------------
$string['incidents']                 = 'Incidents';
$string['incident_review']           = 'Incident Review';
$string['incident_title']            = 'Incident title';
$string['incident_description']      = 'Description';
$string['incident_severity']         = 'Severity';
$string['incident_status']           = 'Status';
$string['incident_status_pending']   = 'Pending review';
$string['incident_status_investigating'] = 'Investigating';
$string['incident_status_resolved']  = 'Resolved';
$string['incident_status_confirmed'] = 'Violation confirmed';
$string['incident_status_cleared']   = 'Cleared';
$string['resolve_incident']          = 'Resolve';
$string['confirm_violation']         = 'Confirm Violation';
$string['clear_incident']            = 'Clear (No Violation)';
$string['resolution_notes']          = 'Resolution notes';
$string['assigned_to']               = 'Assigned to';
$string['no_incidents']              = 'No incidents recorded.';
$string['create_incident']           = 'Create Incident';

// -----------------------------------------------------------------------
// Retention & storage
// -----------------------------------------------------------------------
$string['retention_settings']        = 'Retention & Storage Settings';
$string['retention_snapshots']       = 'Snapshot retention (days)';
$string['retention_snapshots_desc']  = 'Number of days to retain webcam snapshots before automatic deletion. Default: 14.';
$string['retention_event_clips']     = 'Event clip retention (days)';
$string['retention_event_clips_desc'] = 'Number of days to retain short event-triggered clips. Default: 30.';
$string['retention_logs']            = 'Log retention (days)';
$string['retention_logs_desc']       = 'Number of days to retain event logs. Default: 180.';
$string['retention_flagged']         = 'Flagged evidence retention (days)';
$string['retention_flagged_desc']    = 'Number of days to retain flagged evidence. Default: 90.';
$string['storage_dashboard']         = 'Storage Dashboard';
$string['storage_total']             = 'Total storage used';
$string['storage_by_course']         = 'By course';
$string['purge_expired']             = 'Purge expired evidence now';
$string['purge_success']             = 'Expired evidence has been purged successfully.';

// -----------------------------------------------------------------------
// Pre-exam system check
// -----------------------------------------------------------------------
$string['precheck_title']            = 'Pre-Exam System Check';
$string['precheck_desc']             = 'Please wait while we verify your system is ready for the proctored exam.';
$string['precheck_webcam']           = 'Webcam';
$string['precheck_browser']          = 'Browser compatibility';
$string['precheck_internet']         = 'Internet connection';
$string['precheck_permissions']      = 'Camera permissions';
$string['precheck_microphone']       = 'Microphone (optional)';
$string['precheck_fullscreen']       = 'Fullscreen support';
$string['precheck_pass']             = 'Pass';
$string['precheck_fail']             = 'Fail';
$string['precheck_pending']          = 'Checking…';
$string['precheck_all_pass']         = 'All checks passed. You may proceed.';
$string['precheck_failed']           = 'One or more checks failed. Please resolve issues before starting.';

// -----------------------------------------------------------------------
// Settings
// -----------------------------------------------------------------------
$string['additional_settings']           = 'General Settings';
$string['mainsettingspagebtn']           = 'Learn Shield settings';
$string['setting:adminimagepage']        = 'Proctoring User List';
$string['setting:adminimagedescription'] = 'These images are used as base images for face verification. Ensure each image contains a clearly visible face.';
$string['setting:userslist']             = 'Upload user images';
$string['setting:camshotdelay']          = 'Delay between webcam captures (seconds)';
$string['setting:camshotdelay_desc']     = 'The interval in seconds between each automatic webcam snapshot.';
$string['setting:camshotwidth']          = 'Webcam image width (pixels)';
$string['setting:camshotwidth_desc']     = 'Width of each webcam snapshot. Height is scaled proportionally.';
$string['setting:fc_method']             = 'Face match method';
$string['setting:fc_methoddesc']         = 'Service used for face matching. Options: BS (Brain Station API), Local (face-api.js only), None.';
$string['setting:bs_api']                = 'BS service API endpoint';
$string['setting:bs_apidesc']            = 'The BS face-matching service API endpoint URL.';
$string['setting:bs_api_key']            = 'BS API key';
$string['setting:bs_api_keydesc']        = 'API key for the BS face-matching service.';
$string['setting:bs_apifacematchthreshold'] = 'Face match threshold (BS)';
$string['setting:bs_bs_apifacematchthresholddesc'] = 'Percentage threshold for BS face verification. Default: 68%.';
$string['setting:facematch']             = 'Number of face match checks per quiz';
$string['setting:facematchdesc']         = 'Number of snapshot checks per quiz attempt. Use 0 or less to check all snapshots.';
$string['settings:fcheckquizstart']      = 'Face validation on quiz start';
$string['settings:fcheckquizstart_desc'] = 'If enabled, learners must validate their face before starting the quiz.';
$string['setting:enablefullscreen']      = 'Enforce fullscreen mode';
$string['setting:enablefullscreen_desc'] = 'Force learners into fullscreen mode when starting the quiz. Exits are detected and flagged.';
$string['setting:enabletabdetect']       = 'Detect tab / window switching';
$string['setting:enabletabdetect_desc']  = 'Detect and log when a learner switches tabs or leaves the browser window.';
$string['setting:enablekeyblock']        = 'Block restricted keyboard shortcuts';
$string['setting:enablekeyblock_desc']   = 'Block and log attempts to use Ctrl+C, Ctrl+V, Print Screen, F12, and other restricted shortcuts.';
$string['setting:enableclipboard']       = 'Monitor clipboard activity';
$string['setting:enableclipboard_desc']  = 'Detect and log copy and paste actions during the quiz.';
$string['setting:enableprecheck']        = 'Enable pre-exam system check';
$string['setting:enableprecheck_desc']   = 'Show a system-readiness check page before learners start the quiz.';
$string['setting:score_tab_switch']      = 'Suspicion score: tab switch';
$string['setting:score_tab_switch_desc'] = 'Score points added per tab switch event. Default: 10.';
$string['setting:score_fs_exit']         = 'Suspicion score: fullscreen exit';
$string['setting:score_fs_exit_desc']    = 'Score points added per fullscreen exit. Default: 15.';
$string['setting:score_face_missing']    = 'Suspicion score: face missing';
$string['setting:score_face_missing_desc'] = 'Score points added when face is not visible. Default: 10.';
$string['setting:score_keyboard']        = 'Suspicion score: keyboard violation';
$string['setting:score_keyboard_desc']   = 'Score points added per keyboard violation. Default: 10.';
$string['setting:score_clipboard']       = 'Suspicion score: clipboard activity';
$string['setting:score_clipboard_desc']  = 'Score points added per clipboard event. Default: 10.';
$string['setting:score_2nd_person']      = 'Suspicion score: 2nd person / extra person detected';
$string['setting:score_2nd_person_desc'] = 'Score points added when object detection finds more than one person in frame. Default: 25.';
$string['setting:score_phone_detected']      = 'Suspicion score: phone detected';
$string['setting:score_phone_detected_desc'] = 'Score points added when a mobile phone is detected in the exam area. Default: 25.';
$string['setting:score_face_anomaly']      = 'Suspicion score: face anomaly (wrong face / multiple faces on webcam)';
$string['setting:score_face_anomaly_desc'] = 'Score points added when the webcam shows more than one face, or the live face does not match the candidate\'s registered identity. Default: 10.';
$string['setting:medium_risk_threshold'] = 'Medium risk threshold (score)';
$string['setting:medium_risk_threshold_desc'] = 'Score at which a session is classified as medium risk. Default: 30.';
$string['setting:high_risk_threshold']   = 'High risk threshold (score)';
$string['setting:high_risk_threshold_desc'] = 'Score at which a session is classified as high risk. Default: 60.';

// -----------------------------------------------------------------------
// Bulk delete / data management
// -----------------------------------------------------------------------
$string['delete_images_task']                  = 'Delete images task';
$string['delete_images_task_desc']             = 'Delete all proctoring images scheduled for removal';
$string['execute_facematch_task']              = 'Execute face match task';
$string['initiate_facematch_task']             = 'Initiate face match task';
$string['initiate_facematch_task_desc']        = 'Initiates face match tasks for unprocessed snapshots.';
$string['task:delete_images']                  = 'Delete images task';
$string['areyousure_delete_all_course_record'] = 'Are you sure you want to delete all images and records for students in <b>this course?</b>';
$string['areyousure_delete_all_record']        = 'Are you sure you want to delete ALL proctoring images and records?';
$string['areyousure_delete_image']             = 'Do you want to delete this image?';
$string['areyousure_delete_record']            = 'Are you sure you want to delete this record?';
$string['confirmdeletioncourse']               = 'Are you sure you want to delete this course\'s pictures?';
$string['confirmdeletionquiz']                 = 'Are you sure you want to delete the pictures for this quiz?';
$string['deleteallcourse']                     = 'Delete course images';
$string['deletequizdata']                      = 'Delete quiz images';
$string['settings:deleteallsuccess']           = 'Successfully deleted all records.';
$string['settings:deleteuserimagesuccess']     = 'Successfully deleted user image.';
$string['settingscontroll:deleteall']          = 'Delete all records captured during exams';
$string['settingscontroll:deleteallcourseimage'] = 'Delete all images and records captured during exams for <b>this course</b>.';
$string['settingscontroll:deletealldescription'] = 'This will permanently delete all captured images and proctoring data. This action cannot be undone.';
$string['settingscontroll:deletealllinktext']  = 'Delete all records';
$string['bulkdelete']                          = 'Bulk delete';

// -----------------------------------------------------------------------
// User list
// -----------------------------------------------------------------------
$string['userlist']                  = 'User list';
$string['users_list']                = 'Learn Shield User List';
$string['users_list_info_description'] = 'This page lists all users who require a base image for proctoring. These images are used for face-matching during quizzes to ensure authentication and prevent impersonation.';

// -----------------------------------------------------------------------
// Privacy
// -----------------------------------------------------------------------
$string['privacy:metadata']                         = 'Learn Shield V1 stores webcam snapshots, event logs, and session data within your Moodle installation. No personal data is shared with third parties.';
$string['privacy:core_files']                       = 'Learn Shield webcam pictures';
$string['privacy:metadata:core_files']              = 'Webcam snapshots captured during quiz attempts are stored in Moodle file storage.';
$string['privacy:metadata:quizaccess_learnshield_logs']    = 'Stores webcam snapshot metadata per quiz attempt.';
$string['privacy:metadata:quizaccess_learnshield_events']  = 'Stores the event timeline for each proctored session.';
$string['privacy:metadata:quizaccess_learnshield_sessions'] = 'Stores session-level metadata including browser info, IP address, and risk score.';
$string['privacy:metadata:courseid']                = 'The ID of the course using proctoring.';
$string['privacy:metadata:quizid']                  = 'The ID of the quiz using proctoring.';
$string['privacy:metadata:status']                  = 'The status of the proctoring session.';
$string['privacy:metadata:userid']                  = 'The ID of the user who took the quiz.';
$string['privacy:metadata:webcampicture']            = 'The filename of the webcam picture captured.';
$string['privacy:metadata:geolocation']             = 'Approximate geolocation stored for audit purposes.';
$string['privacy:metadata:browser_info']            = 'Browser and OS information recorded at session start.';

// -----------------------------------------------------------------------
// Quiz-level instructions (admin sets per-quiz)
// -----------------------------------------------------------------------
$string['quiz_instructions_label'] = 'Exam instructions for students';
$string['quiz_instructions_help']  = 'These instructions are shown to students on the quiz page before they start. Use this to communicate specific rules, allowed materials, or any exam-specific guidance.';

// -----------------------------------------------------------------------
// Pre-exam check (inline in preflight form)
// -----------------------------------------------------------------------
// Message provider (Moodle messaging API — required).
$string['messageprovider:proctoring_alert'] = 'Learn Shield integrity alerts';

$string['precheck_inline_title']   = 'System Readiness Check';
$string['precheck_inline_desc']    = 'Verifying your system before you start the exam…';
$string['precheck_all_pass_short'] = '✓ System ready — you may proceed';
$string['precheck_failed_short']   = '✗ One or more checks failed. Resolve issues before starting.';

// -----------------------------------------------------------------------
// Per-quiz security controls (quiz settings form)
// -----------------------------------------------------------------------
$string['perquiz_security_header']    = 'Learn Shield — Security Controls for This Quiz';
$string['perquiz_security_desc']      = 'These settings override the global plugin settings for this specific quiz only. Leave as "Use global setting" to inherit site-wide defaults.';
$string['perquiz_use_global']         = '— Use global plugin setting —';
$string['perquiz_force_on']           = 'Force ON for this quiz';
$string['perquiz_force_off']          = 'Force OFF for this quiz';
$string['perquiz_snapinterval']       = 'Snapshot interval override (seconds)';
$string['perquiz_snapinterval_help']  = 'Set to 0 to use the global snapshot interval. Enter a custom value (e.g. 15, 30, 60) to override for this quiz only.';

// -----------------------------------------------------------------------
// Per-learner scheduling (schedule.php / {quiz_overrides})
// -----------------------------------------------------------------------
$string['schedule_open_countdown']  = '🕐 This quiz opens in: {$a->countdown} — opens {$a->date}';
$string['schedule_close_countdown'] = '⏳ Available until {$a->date} — closes in {$a->countdown}';
