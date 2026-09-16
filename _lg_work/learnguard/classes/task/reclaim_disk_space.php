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
 * v2.0.219: every run now writes its own permanent row to learnguard_reclaim_history
 * (queued -> running -> done/error/refused), with before/after byte sizes when the run
 * actually completes — previously only the MOST RECENT run's status was visible (3
 * plain config values, silently overwritten by the next run), so there was no way to
 * see past runs or how much was actually reclaimed.
 *
 * @package    mod_learnguard
 * @author     Michael Adeniran <michaeladeniransnr@gmail.com>
 * @copyright  2025 Michael Adeniran
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

class reclaim_disk_space extends \core\task\adhoc_task {

    /**
     * Sums DATA_LENGTH + INDEX_LENGTH across the given tables via information_schema —
     * used both for the pre-flight free-space check and for measuring before/after size
     * so the history row can show how much was actually reclaimed.
     */
    private function tables_size_bytes(array $tables): int {
        global $DB;
        $prefix = $DB->get_prefix();
        $total = 0;
        foreach ($tables as $t) {
            $row = $DB->get_record_sql(
                "SELECT (DATA_LENGTH + INDEX_LENGTH) AS bytes
                   FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$prefix . $t]
            );
            $total += $row ? (float)$row->bytes : 0;
        }
        return (int)$total;
    }

    public function execute(): void {
        global $DB, $CFG;

        $historyid = (int)($this->get_custom_data()->historyid ?? 0);
        $historyExists = $historyid > 0 && $DB->record_exists('learnguard_reclaim_history', ['id' => $historyid]);

        // Kept alongside the new history table for backward compatibility with any cached
        // page that still reads the old config values directly — not the source of truth
        // anymore, the history row is.
        set_config('reclaim_status', 'running', 'learnguard');
        set_config('reclaim_started', time(), 'learnguard');
        if ($historyExists) {
            $DB->set_field('learnguard_reclaim_history', 'status', 'running', ['id' => $historyid]);
            $DB->set_field('learnguard_reclaim_history', 'timestarted', time(), ['id' => $historyid]);
        }

        if ($DB->get_dbfamily() !== 'mysql') {
            $msg = 'Disk space reclaim is only supported on MySQL/MariaDB.';
            set_config('reclaim_status', 'error', 'learnguard');
            set_config('reclaim_error', $msg, 'learnguard');
            if ($historyExists) {
                $DB->update_record('learnguard_reclaim_history', (object)[
                    'id' => $historyid, 'status' => 'error', 'errormessage' => $msg, 'timefinished' => time(),
                ]);
            }
            return;
        }

        try {
            $tables = array_values(array_filter(
                ['learnguard_snapshots', 'learnguard_audio_clips'],
                fn($t) => $DB->get_manager()->table_exists($t)
            ));

            $bytesBefore = $this->tables_size_bytes($tables);

            // Moving this off the web request (v2.0.176) only fixed the symptom that was
            // actually reported (a hung page with no feedback) — it did NOT change what
            // OPTIMIZE TABLE itself needs to run: InnoDB rebuilds a table by writing an
            // entirely NEW copy alongside the old one before swapping it in, so it
            // genuinely needs roughly the table's own current size again in free space —
            // confirmed live (79GB -> 91GB and climbing) when this ran without headroom.
            // Refuse up front, with a clear reason, rather than silently risk the exact
            // same disk-exhaustion incident just because it's no longer visible in a
            // browser tab.
            // 20% safety margin on top of the table's own size — the rebuild needs
            // "roughly" that much, not exactly, and other things on the same disk keep
            // writing while this runs.
            $needed = (int)($bytesBefore * 1.2);

            $free = @disk_free_space($CFG->dataroot);
            if ($free !== false && $needed > 0 && $free < $needed) {
                $msg = sprintf(
                    'Refused to run: this needs roughly %s free (the tables\' own size, plus margin) ' .
                    'but only %s is currently free. Free up more space first, then retry.',
                    display_size($needed), display_size($free)
                );
                set_config('reclaim_status', 'error', 'learnguard');
                set_config('reclaim_error', $msg, 'learnguard');
                if ($historyExists) {
                    $DB->update_record('learnguard_reclaim_history', (object)[
                        'id' => $historyid, 'status' => 'refused', 'errormessage' => $msg,
                        'bytesbefore' => $bytesBefore, 'timefinished' => time(),
                    ]);
                }
                mtrace('LearnGuard reclaim REFUSED: needed ~' . display_size($needed) .
                    ', only ' . display_size($free) . ' free.');
                return;
            }

            foreach ($tables as $rtable) {
                mtrace("LearnGuard reclaim: OPTIMIZE TABLE $rtable starting…");
                $DB->execute('OPTIMIZE TABLE {' . $rtable . '}');
                mtrace("LearnGuard reclaim: OPTIMIZE TABLE $rtable done.");
            }

            $bytesAfter = $this->tables_size_bytes($tables);
            $bytesReclaimed = max(0, $bytesBefore - $bytesAfter);

            set_config('reclaim_status', 'done', 'learnguard');
            set_config('reclaim_finished', time(), 'learnguard');
            if ($historyExists) {
                $DB->update_record('learnguard_reclaim_history', (object)[
                    'id' => $historyid, 'status' => 'done', 'timefinished' => time(),
                    'bytesbefore' => $bytesBefore, 'bytesafter' => $bytesAfter, 'bytesreclaimed' => $bytesReclaimed,
                ]);
            }
        } catch (\Throwable $e) {
            set_config('reclaim_status', 'error', 'learnguard');
            set_config('reclaim_error', $e->getMessage(), 'learnguard');
            if ($historyExists) {
                $DB->update_record('learnguard_reclaim_history', (object)[
                    'id' => $historyid, 'status' => 'error', 'errormessage' => $e->getMessage(), 'timefinished' => time(),
                ]);
            }
            mtrace('LearnGuard reclaim FAILED: ' . $e->getMessage());
        }
    }
}
