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
 *  - Evidence images (screen captures, violation snapshots) and audio clips older
 *    than configured retention (default 30 days), with a reminder email sent to
 *    all global proctors ~24 hours before each batch is permanently deleted
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
        global $DB, $CFG;
        // lib.php's learnguard_notify_media_deletion_reminder() is a bare global function,
        // not autoloaded — without this, the first call would fatal with "Call to undefined
        // function" and abort the rest of this task's run (webcam/360/events/messages
        // cleanup included), since nothing here previously required lib.php at all.
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');

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

        // ── Evidence media cleanup: violation/evidence images + audio clips ───
        // Covers snapshot_type values NOT already governed by the periodic/360/
        // photo_id rules above (screen captures, violation_* evidence, random
        // screenshots, object/face detections, etc.) plus the entire audio_clips
        // table — neither was previously covered by any auto-deletion at all.
        $mediaRetain      = (int)(get_config('learnguard', 'retention_media_days') ?: 30);
        $reminderEnabled  = (bool)get_config('learnguard', 'media_deletion_reminder_enabled');
        $excludedTypes    = "snapshot_type NOT IN ('periodic', '360_video', 'photo_id')";

        // Reminder: media that will cross the retention cutoff within the next ~24h
        // (i.e. currently aged between retain-1 and retain days). This task runs
        // once daily, so each item passes through this band exactly once.
        if ($reminderEnabled && $mediaRetain > 1) {
            $reminderStart = $now - ($mediaRetain * DAYSECS);
            $reminderEnd   = $now - (($mediaRetain - 1) * DAYSECS);
            $dueSnaps = $DB->count_records_select('learnguard_snapshots',
                "$excludedTypes AND timemodified >= :start AND timemodified < :end",
                ['start' => $reminderStart, 'end' => $reminderEnd]);
            $dueAudio = 0;
            if ($DB->get_manager()->table_exists('learnguard_audio_clips')) {
                $dueAudio = $DB->count_records_select('learnguard_audio_clips',
                    "timecreated >= :start AND timecreated < :end",
                    ['start' => $reminderStart, 'end' => $reminderEnd]);
            }
            if (($dueSnaps + $dueAudio) > 0) {
                learnguard_notify_media_deletion_reminder($dueSnaps, $dueAudio, $mediaRetain);
                mtrace("  Reminder sent: $dueSnaps evidence image(s) and $dueAudio audio clip(s) " .
                       "will be deleted in ~24 hours.");
            }
        }

        // Delete evidence images past retention.
        $mediaCutoff     = $now - ($mediaRetain * DAYSECS);
        $countMediaSnaps = $DB->count_records_select('learnguard_snapshots',
            "$excludedTypes AND timemodified < :cutoff", ['cutoff' => $mediaCutoff]);
        if ($countMediaSnaps > 0) {
            $DB->delete_records_select('learnguard_snapshots',
                "$excludedTypes AND timemodified < :cutoff", ['cutoff' => $mediaCutoff]);
            mtrace("  Deleted $countMediaSnaps evidence image(s) (screen captures / violation " .
                   "snapshots) older than {$mediaRetain} days.");
        }

        // Delete audio clips past retention.
        if ($DB->get_manager()->table_exists('learnguard_audio_clips')) {
            $countAudio = $DB->count_records_select('learnguard_audio_clips',
                "timecreated < :cutoff", ['cutoff' => $mediaCutoff]);
            if ($countAudio > 0) {
                $DB->delete_records_select('learnguard_audio_clips',
                    "timecreated < :cutoff", ['cutoff' => $mediaCutoff]);
                mtrace("  Deleted $countAudio audio clip(s) older than {$mediaRetain} days.");
            }
        }

        mtrace('LearnGuard GDPR cleanup complete.');
    }
}
