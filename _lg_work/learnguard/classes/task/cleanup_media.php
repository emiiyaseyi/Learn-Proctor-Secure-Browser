<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Ad-hoc task: deletes snapshots / audio clips matching a Storage-tab cleanup job, in small
 * batches so it never holds a long lock or runs past a cron slot. Each run works for at most
 * ~3 minutes, records progress on the job row after every batch, and re-queues itself until
 * nothing matching is left. Media from an in-progress attempt is never touched. When done it
 * records disk usage and (optionally) queues a Reclaim Disk Space run — deleting rows only
 * frees space INSIDE the database tables; the disk figure drops after that reclaim.
 *
 * @package    mod_learnguard
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/learnguard/lib.php');

class cleanup_media extends \core\task\adhoc_task {

    private const BATCH = 500;
    private const BUDGET_SECONDS = 180;

    public function execute(): void {
        global $DB;
        $jobid = (int)($this->get_custom_data()->jobid ?? 0);
        $job = $jobid ? $DB->get_record('learnguard_cleanup_jobs', ['id' => $jobid]) : false;
        if (!$job || in_array($job->status, ['done', 'error', 'cancelled', 'reclaiming'], true)) { return; }

        try {
            if ((int)$job->timestarted === 0) {
                $DB->update_record('learnguard_cleanup_jobs', (object)[
                    'id' => $jobid, 'status' => 'running', 'timestarted' => time(),
                    'diskusedbefore' => learnguard_disk_used_bytes(),
                    'totalrows' => learnguard_cleanup_count($job),
                ]);
            } else {
                $DB->set_field('learnguard_cleanup_jobs', 'status', 'running', ['id' => $jobid]);
            }

            $deadline = time() + self::BUDGET_SECONDS;
            $deleted = (int)$DB->get_field('learnguard_cleanup_jobs', 'deletedrows', ['id' => $jobid]);
            $morework = true;
            while ($morework && time() < $deadline) {
                $status = $DB->get_field('learnguard_cleanup_jobs', 'status', ['id' => $jobid]);
                if ($status === 'cancelled') { return; }
                $morework = false;
                foreach (learnguard_cleanup_types($job) as $type) {
                    $ids = learnguard_cleanup_batch_ids($type, $job, self::BATCH);
                    if (empty($ids)) { continue; }
                    $dtable = learnguard_cleanup_table_for($type, $job);
                    // Moodle's popup markers point at notifications by id — remove them together
                    // so no marker is left pointing at a notification that no longer exists.
                    if ($dtable === 'notifications') {
                        $DB->delete_records_list('message_popup_notifications', 'notificationid', $ids);
                    }
                    $DB->delete_records_list($dtable, 'id', $ids);
                    $deleted += count($ids);
                    $morework = true;
                }
                $DB->set_field('learnguard_cleanup_jobs', 'deletedrows', $deleted, ['id' => $jobid]);
                if ($morework) { usleep(100000); } // breathe between batches — keep the live site responsive.
            }

            if ($morework) {
                // Out of time for this cron slot with rows remaining — pick it up again next run.
                $again = new self();
                $again->set_custom_data(['jobid' => $jobid]);
                \core\task\manager::queue_adhoc_task($again);
                return;
            }

            $DB->update_record('learnguard_cleanup_jobs', (object)[
                'id' => $jobid, 'deletedrows' => $deleted, 'diskusedafter' => learnguard_disk_used_bytes(),
            ]);
            if ((int)$job->autoreclaim === 1) {
                $hid = learnguard_queue_reclaim((int)$job->queuedby, $jobid, learnguard_cleanup_tables($job));
                $DB->update_record('learnguard_cleanup_jobs', (object)[
                    'id' => $jobid, 'status' => 'reclaiming', 'reclaimhistoryid' => $hid,
                    'errormessage' => 'Rows deleted; waiting for the disk-space reclaim to shrink the files.',
                ]);
            } else {
                $DB->update_record('learnguard_cleanup_jobs', (object)[
                    'id' => $jobid, 'status' => 'done', 'timefinished' => time(),
                    'errormessage' => 'Rows deleted. Disk usage only drops after a Reclaim Disk Space run.',
                ]);
            }
        } catch (\Throwable $e) {
            $DB->update_record('learnguard_cleanup_jobs', (object)[
                'id' => $jobid, 'status' => 'error', 'timefinished' => time(), 'errormessage' => $e->getMessage(),
            ]);
            mtrace('LearnGuard cleanup FAILED: ' . $e->getMessage());
        }
    }
}
