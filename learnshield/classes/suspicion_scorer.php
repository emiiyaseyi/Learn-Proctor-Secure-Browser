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
        $map = [
            event_tracker::TYPE_TAB_SWITCH         => (int)(get_config('quizaccess_learnshield', 'score_tab_switch')    ?: 10),
            event_tracker::TYPE_FULLSCREEN_EXIT     => (int)(get_config('quizaccess_learnshield', 'score_fs_exit')        ?: 15),
            event_tracker::TYPE_FACE_MISSING        => (int)(get_config('quizaccess_learnshield', 'score_face_missing')   ?: 20),
            event_tracker::TYPE_KEYBOARD_VIOLATION  => (int)(get_config('quizaccess_learnshield', 'score_keyboard')       ?: 10),
            event_tracker::TYPE_CLIPBOARD_COPY      => (int)(get_config('quizaccess_learnshield', 'score_clipboard')      ?: 10),
            event_tracker::TYPE_CLIPBOARD_PASTE     => (int)(get_config('quizaccess_learnshield', 'score_clipboard')      ?: 10),
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
