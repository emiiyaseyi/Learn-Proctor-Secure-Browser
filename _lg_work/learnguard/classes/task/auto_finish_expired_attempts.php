<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled task: force-finish 'inprogress' attempts whose personal/activity window
 * has closed or whose own time limit has run out.
 *
 * The client already auto-submits on its own countdown (both the schedule-window badge
 * and the per-attempt timer), and submit_answer()/autosave() carry a matching server-side
 * backstop — but both of those only fire when the student's browser sends another
 * request. A closed tab, crashed script, lost connection, or backgrounded/throttled tab
 * sends nothing further at all, so an attempt like that would otherwise sit 'inprogress'
 * indefinitely with no result ever recorded. Runs every 5 minutes so this is caught
 * within minutes rather than relying on the student (or an admin) to do anything.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

class auto_finish_expired_attempts extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'LearnGuard: auto-finish expired attempts';
    }

    public function execute(): void {
        global $DB;

        $attempts = $DB->get_records('learnguard_attempts', ['state' => 'inprogress']);
        if (empty($attempts)) {
            return;
        }

        // Batch-fetch the parent activities (one query, not N+1).
        $lgids = array_unique(array_map(fn($a) => (int)$a->learnguard, $attempts));
        [$insql, $inparams] = $DB->get_in_or_equal($lgids, SQL_PARAMS_NAMED);
        $instances = $DB->get_records_select('learnguard', "id $insql", $inparams);

        $finished = 0;
        foreach ($attempts as $attempt) {
            $instance = $instances[$attempt->learnguard] ?? null;
            if (!$instance) {
                continue; // orphaned attempt (activity deleted without cascade) — leave alone.
            }

            $windowClosed  = empty($attempt->reopened_for_review)
                             && !\learnguard_is_available_for_user($instance, (int)$attempt->userid);
            $timelimitOver = \learnguard_attempt_timelimit_expired($instance, $attempt);
            if (!$windowClosed && !$timelimitOver) {
                continue;
            }

            \mod_learnguard\attempt_manager::finish($attempt, $instance);
            $finished++;
        }

        if ($finished > 0) {
            mtrace("  Auto-finished $finished expired attempt(s) left inprogress with no further activity.");
        }
    }
}
