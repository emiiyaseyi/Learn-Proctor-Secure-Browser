<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Ad-hoc task: runs OPTIMIZE TABLE on learnguard_snapshots/learnguard_audio_clips to
 * physically reclaim disk space after a bulk delete — moved off the request/response
 * cycle entirely after a live report of the synchronous version ("🗜 Reclaim Disk
 * Space" button in siteadmin.php) hanging indefinitely with the browser showing no
 * feedback. OPTIMIZE TABLE on a table with hundreds of thousands of rows and large BLOB
 * content is a genuine full-table rebuild that can run far longer than a web
 * request/gateway timeout allows — same underlying problem already fixed for bulk ZIP
 * export in v2.0.175, same fix shape here.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

class reclaim_disk_space extends \core\task\adhoc_task {

    public function execute(): void {
        global $DB, $CFG;

        set_config('reclaim_status', 'running', 'learnguard');
        set_config('reclaim_started', time(), 'learnguard');

        if ($DB->get_dbfamily() !== 'mysql') {
            set_config('reclaim_status', 'error', 'learnguard');
            set_config('reclaim_error', 'Disk space reclaim is only supported on MySQL/MariaDB.', 'learnguard');
            return;
        }

        try {
            $tables = array_values(array_filter(
                ['learnguard_snapshots', 'learnguard_audio_clips'],
                fn($t) => $DB->get_manager()->table_exists($t)
            ));

            // Moving this off the web request (v2.0.176) only fixed the symptom that was
            // actually reported (a hung page with no feedback) — it did NOT change what
            // OPTIMIZE TABLE itself needs to run: InnoDB rebuilds a table by writing an
            // entirely NEW copy alongside the old one before swapping it in, so it
            // genuinely needs roughly the table's own current size again in free space —
            // confirmed live (79GB -> 91GB and climbing) when this ran without headroom.
            // Refuse up front, with a clear reason, rather than silently risk the exact
            // same disk-exhaustion incident just because it's no longer visible in a
            // browser tab.
            $needed = 0;
            foreach ($tables as $t) {
                $prefix = $DB->get_prefix();
                $row = $DB->get_record_sql(
                    "SELECT (DATA_LENGTH + INDEX_LENGTH) AS bytes
                       FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                    [$prefix . $t]
                );
                $needed += $row ? (float)$row->bytes : 0;
            }
            // 20% safety margin on top of the table's own size — the rebuild needs
            // "roughly" that much, not exactly, and other things on the same disk keep
            // writing while this runs.
            $needed = (int)($needed * 1.2);

            $free = @disk_free_space($CFG->dataroot);
            if ($free !== false && $needed > 0 && $free < $needed) {
                set_config('reclaim_status', 'error', 'learnguard');
                set_config('reclaim_error', sprintf(
                    'Refused to run: this needs roughly %s free (the tables\' own size, plus margin) ' .
                    'but only %s is currently free. Free up more space first, then retry.',
                    display_size($needed), display_size($free)
                ), 'learnguard');
                mtrace('LearnGuard reclaim REFUSED: needed ~' . display_size($needed) .
                    ', only ' . display_size($free) . ' free.');
                return;
            }

            foreach ($tables as $rtable) {
                mtrace("LearnGuard reclaim: OPTIMIZE TABLE $rtable starting…");
                $DB->execute('OPTIMIZE TABLE {' . $rtable . '}');
                mtrace("LearnGuard reclaim: OPTIMIZE TABLE $rtable done.");
            }
            set_config('reclaim_status', 'done', 'learnguard');
            set_config('reclaim_finished', time(), 'learnguard');
        } catch (\Throwable $e) {
            set_config('reclaim_status', 'error', 'learnguard');
            set_config('reclaim_error', $e->getMessage(), 'learnguard');
            mtrace('LearnGuard reclaim FAILED: ' . $e->getMessage());
        }
    }
}
