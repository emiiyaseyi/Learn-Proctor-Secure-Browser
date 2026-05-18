<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Scheduled task: GDPR / data retention cleanup.
 *
 * Runs daily. Deletes:
 *  - Webcam snapshots older than configured retention (default 14 days)
 *  - 360° recordings older than configured retention (default 7 days)
 *  - Proctoring event logs older than configured retention (default 180 days)
 *  - Chat messages older than 90 days
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

class cleanup_proctoring_data extends \core\task\scheduled_task {

    public function get_name(): string {
        return 'LearnGuard: GDPR data retention cleanup';
    }

    public function execute(): void {
        global $DB;

        $now = time();

        // Configurable retention periods (in days).
        $snapRetain    = (int)(get_config('learnguard', 'retention_snapshots')  ?: 14);
        $env360Retain  = (int)(get_config('learnguard', 'retention_360')        ?: 7);
        $eventsRetain  = (int)(get_config('learnguard', 'retention_events')     ?: 180);
        $chatRetain    = (int)(get_config('learnguard', 'retention_chat')       ?: 90);
        $messagesRetain= (int)(get_config('learnguard', 'retention_messages')   ?: 90);

        // ── Delete old webcam snapshots ──────────────────────────────────────
        $cutoff = $now - ($snapRetain * DAYSECS);
        $count  = $DB->count_records_select('learnguard_snapshots',
            "snapshot_type = 'periodic' AND timemodified < :cutoff", ['cutoff' => $cutoff]);
        if ($count > 0) {
            $DB->delete_records_select('learnguard_snapshots',
                "snapshot_type = 'periodic' AND timemodified < :cutoff", ['cutoff' => $cutoff]);
            mtrace("  Deleted $count webcam snapshots older than {$snapRetain} days.");
        }

        // ── Delete old 360° recordings ───────────────────────────────────────
        $cutoff360 = $now - ($env360Retain * DAYSECS);
        $count360  = $DB->count_records_select('learnguard_snapshots',
            "snapshot_type = '360_video' AND timemodified < :cutoff", ['cutoff' => $cutoff360]);
        if ($count360 > 0) {
            $DB->delete_records_select('learnguard_snapshots',
                "snapshot_type = '360_video' AND timemodified < :cutoff", ['cutoff' => $cutoff360]);
            mtrace("  Deleted $count360 360° recordings older than {$env360Retain} days.");
        }

        // ── Delete old photo ID captures ─────────────────────────────────────
        $cutoffPid = $now - (30 * DAYSECS);  // Photo IDs kept 30 days.
        $DB->delete_records_select('learnguard_snapshots',
            "snapshot_type = 'photo_id' AND timemodified < :cutoff", ['cutoff' => $cutoffPid]);

        // ── Delete old proctoring events ─────────────────────────────────────
        $cutoffEvents = $now - ($eventsRetain * DAYSECS);
        $countEvt = $DB->count_records_select('learnguard_events',
            "timemodified < :cutoff", ['cutoff' => $cutoffEvents]);
        if ($countEvt > 0) {
            $DB->delete_records_select('learnguard_events',
                "timemodified < :cutoff", ['cutoff' => $cutoffEvents]);
            mtrace("  Deleted $countEvt event log entries older than {$eventsRetain} days.");
        }

        // ── Delete old chat messages ─────────────────────────────────────────
        if ($DB->get_manager()->table_exists('learnguard_messages')) {
            $cutoffChat = $now - ($messagesRetain * DAYSECS);
            $countChat  = $DB->count_records_select('learnguard_messages',
                "timecreated < :cutoff", ['cutoff' => $cutoffChat]);
            if ($countChat > 0) {
                $DB->delete_records_select('learnguard_messages',
                    "timecreated < :cutoff", ['cutoff' => $cutoffChat]);
                mtrace("  Deleted $countChat chat messages older than {$messagesRetain} days.");
            }
        }

        mtrace('LearnGuard GDPR cleanup complete.');
    }
}
