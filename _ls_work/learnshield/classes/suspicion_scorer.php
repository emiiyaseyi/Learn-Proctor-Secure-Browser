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
 * Suspicion scoring engine for Learn Shield V1.
 *
 * @package    quizaccess_learnshield
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @link       https://www.linkedin.com/in/michaeladeniran/
 */

namespace quizaccess_learnshield;

defined('MOODLE_INTERNAL') || die();

// Forward-declare sibling classes to avoid circular autoload issues.
// (These are in the same namespace so autoload will resolve them.)
use quizaccess_learnshield\email_notifier;
use quizaccess_learnshield\session_manager;
use quizaccess_learnshield\event_tracker;
use quizaccess_learnshield\incident_manager;

/**
 * Rule-based suspicion scoring. All thresholds are configurable from the admin settings page.
 */
class suspicion_scorer {

    /**
     * Return the score impact for a given event type (reads from plugin config).
     *
     * @param string $eventtype
     * @return int
     */
    public static function get_score_for_event(string $eventtype): int {
        // Read an int admin setting, falling back to $default only when the
        // setting has never been saved — preserves an explicit "0" (unlike
        // `?:`, which treats the string "0" as falsy).
        $cfg = function(string $key, int $default): int {
            $v = get_config('quizaccess_learnshield', $key);
            return ($v === false || $v === '') ? $default : (int)$v;
        };
        $map = [
            event_tracker::TYPE_TAB_SWITCH         => (int)(get_config('quizaccess_learnshield', 'score_tab_switch')    ?: 10),
            event_tracker::TYPE_FULLSCREEN_EXIT     => (int)(get_config('quizaccess_learnshield', 'score_fs_exit')        ?: 15),
            event_tracker::TYPE_FACE_MISSING        => (int)(get_config('quizaccess_learnshield', 'score_face_missing')   ?: 10),
            event_tracker::TYPE_KEYBOARD_VIOLATION  => (int)(get_config('quizaccess_learnshield', 'score_keyboard')       ?: 10),
            event_tracker::TYPE_CLIPBOARD_COPY      => (int)(get_config('quizaccess_learnshield', 'score_clipboard')      ?: 10),
            event_tracker::TYPE_CLIPBOARD_PASTE     => (int)(get_config('quizaccess_learnshield', 'score_clipboard')      ?: 10),
            // Object detection (YOLOv5n/COCO-SSD) — extra person physically present.
            'multiple_persons_detected' => $cfg('score_2nd_person', 25),
            // Object detection — mobile phone in exam area.
            'object_detected_cell_phone' => $cfg('score_phone_detected', 25),
            // Object detection — other flagged items. Not admin-configurable
            // (matches mod_learnguard's hardcoded OBJECT_VIOLATIONS scores).
            'object_detected_book'     => 10,
            'object_detected_laptop'   => 20,
            'object_detected_keyboard' => 10,
            'object_detected_remote'   => 5,
            // face-api — webcam shows >1 face, or live face doesn't match the
            // candidate's registered identity.
            'multiple_faces_detected' => $cfg('score_face_anomaly', 10),
            'face_identity_mismatch'  => $cfg('score_face_anomaly', 10),
        ];
        return $map[$eventtype] ?? 0;
    }

    /**
     * Add score points to a session and recalculate the risk level.
     *
     * @param int    $sessionid
     * @param int    $scorepoints
     * @param string $eventtype
     */
    public static function add_score(int $sessionid, int $scorepoints, string $eventtype = ''): void {
        global $DB;

        $session = $DB->get_record('quizaccess_learnshield_sessions', ['id' => $sessionid]);
        if (!$session) {
            return;
        }

        $newscore = $session->risk_score + $scorepoints;
        $risklevel = self::calculate_risk_level($newscore);

        $update = (object)[
            'id'           => $sessionid,
            'risk_score'   => $newscore,
            'risk_level'   => $risklevel,
            'timemodified' => time(),
        ];

        // Increment per-column counters.
        switch ($eventtype) {
            case event_tracker::TYPE_TAB_SWITCH:
                $update->tab_switches = $session->tab_switches + 1;
                break;
            case event_tracker::TYPE_FULLSCREEN_EXIT:
                $update->fullscreen_exits = $session->fullscreen_exits + 1;
                break;
            case event_tracker::TYPE_FACE_MISSING:
                $update->face_missing_count = $session->face_missing_count + 1;
                break;
            case event_tracker::TYPE_KEYBOARD_VIOLATION:
                $update->keyboard_violations = $session->keyboard_violations + 1;
                break;
            case event_tracker::TYPE_CLIPBOARD_COPY:
            case event_tracker::TYPE_CLIPBOARD_PASTE:
                $update->clipboard_events = $session->clipboard_events + 1;
                break;
        }

        $DB->update_record('quizaccess_learnshield_sessions', $update);

        // Auto-create a high-severity incident if score crosses the high threshold.
        $highthreshold = (int)(get_config('quizaccess_learnshield', 'high_risk_threshold') ?: 60);
        if ($newscore >= $highthreshold && $session->risk_score < $highthreshold) {
            incident_manager::auto_create_incident($session, $newscore);

            // Send email notification.
            try {
                email_notifier::notify_high_risk($session, $newscore);
            } catch (\Exception $e) {
                debugging('Learn Shield notify_high_risk failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        // Auto-suspension check.
        $autosuspendEnabled  = (bool)(int)(get_config('quizaccess_learnshield', 'autosuspend_enabled') ?: 0);
        $autosuspendScore    = (int)(get_config('quizaccess_learnshield', 'autosuspend_score')   ?: 80);
        $autosuspendAction   = get_config('quizaccess_learnshield', 'autosuspend_action') ?: 'suspend';

        if ($autosuspendEnabled && $session->status === 'active') {
            $shouldSuspend = false;
            $reason        = '';

            if ($newscore >= $autosuspendScore && $session->risk_score < $autosuspendScore) {
                $shouldSuspend = true;
                $reason        = "Risk score reached {$newscore} (threshold: {$autosuspendScore})";
            }

            // Check total violation flag count.
            $autosuspendFlags = (int)(get_config('quizaccess_learnshield', 'autosuspend_flags') ?: 0);
            if ($autosuspendFlags > 0) {
                $totalViolations = ($session->tab_switches ?? 0) +
                                   ($session->fullscreen_exits ?? 0) +
                                   ($session->face_missing_count ?? 0) +
                                   ($session->keyboard_violations ?? 0) +
                                   ($session->clipboard_events ?? 0);
                if ($totalViolations >= $autosuspendFlags) {
                    $shouldSuspend = true;
                    $reason        = "Total violations reached {$totalViolations} (threshold: {$autosuspendFlags})";
                }
            }

            if ($shouldSuspend) {
                if ($autosuspendAction === 'suspend' || $autosuspendAction === 'forcesubmit') {
                    session_manager::suspend($sessionid, 0); // 0 = system-triggered.
                }
                // Log the auto-suspension event.
                event_tracker::log($sessionid, $session->courseid, $session->quizid,
                    $session->userid, $session->attemptid ?? 0,
                    event_tracker::TYPE_SUSPENSION,
                    json_encode(['reason' => $reason, 'auto' => true, 'action' => $autosuspendAction]),
                    event_tracker::SEVERITY_DANGER, 0
                );
                // Send email notification.
                try {
                    email_notifier::notify_suspended($session, $newscore, $reason);
                } catch (\Exception $e) {
                    debugging('Learn Shield notify_suspended failed: ' . $e->getMessage(), DEBUG_DEVELOPER);
                }
            }
        }
    }

    /**
     * Determine the risk level label from a numeric score.
     *
     * @param int $score
     * @return string low|medium|high
     */
    public static function calculate_risk_level(int $score): string {
        $medium = (int)(get_config('quizaccess_learnshield', 'medium_risk_threshold') ?: 30);
        $high   = (int)(get_config('quizaccess_learnshield', 'high_risk_threshold')   ?: 60);

        if ($score >= $high) {
            return 'high';
        }
        if ($score >= $medium) {
            return 'medium';
        }
        return 'low';
    }

    /**
     * Return a CSS class suffix for a risk level string.
     *
     * @param string $risklevel
     * @return string
     */
    public static function risk_class(string $risklevel): string {
        return match ($risklevel) {
            'high'   => 'learnshield-risk-high',
            'medium' => 'learnshield-risk-medium',
            default  => 'learnshield-risk-low',
        };
    }
}
