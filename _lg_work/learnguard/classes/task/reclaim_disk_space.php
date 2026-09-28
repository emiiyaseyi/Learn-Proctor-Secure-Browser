<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

/**
 * Ad-hoc task: shrinks learnguard_snapshots / learnguard_audio_clips on disk after bulk deletes,
 * IN BATCHES. InnoDB never gives file space back after DELETE (it becomes slack inside the table's
 * own file); the classic fix, OPTIMIZE TABLE, is one huge all-at-once rebuild that needs the whole
 * table's size free again and holds the table busy for its full duration. Instead this:
 *   1. creates an empty twin table (CREATE TABLE ... LIKE),
 *   2. copies only the SURVIVING rows across 200 at a time (needs headroom for just the live data,
 *      progress survives between cron runs, and it can stop cleanly at any point),
 *   3. does an atomic RENAME swap, copies any rows that arrived during the copy, verifies row
 *      counts, and only then DROPs the old table — which is what actually returns the disk space.
 * If anything fails the original table is left (or put back) exactly as it was. Measures the real
 * file size (data + index + slack) before/after so a successful shrink shows as one.
 *
 * @package    mod_learnguard
 */

namespace mod_learnguard\task;

defined('MOODLE_INTERNAL') || die();

class reclaim_disk_space extends \core\task\adhoc_task {

    private const BATCH = 200;
    private const BUDGET_SECONDS = 240;
    private const MIN_FREE_GUARD = 524288000; // 500 MB — stop copying if the disk gets this tight.

    private function present(string $name): bool {
        global $DB;
        return (bool)$DB->get_field_sql(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$DB->get_prefix() . $name]);
    }

    /** [live bytes (data+index), slack bytes (DATA_FREE)] for one table. */
    private function sizes(string $t): array {
        global $DB;
        $row = $DB->get_record_sql(
            "SELECT DATA_LENGTH AS dl, INDEX_LENGTH AS il, DATA_FREE AS df
               FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
            [$DB->get_prefix() . $t]);
        return $row ? [(int)((float)$row->dl + (float)$row->il), (int)$row->df] : [0, 0];
    }

    /** Real on-disk footprint: live data + indexes + reclaimable slack. */
    private function file_bytes(array $tables): int {
        $n = 0;
        foreach ($tables as $t) { [$l, $s] = $this->sizes($t); $n += $l + $s; }
        return $n;
    }

    /** Free bytes on the volume that actually holds the database, when PHP can see it. */
    private function db_free_bytes(): ?float {
        global $DB, $CFG;
        try {
            $dir = $DB->get_field_sql('SELECT @@datadir');
            if ($dir) { $f = @disk_free_space($dir); if ($f !== false) { return (float)$f; } }
        } catch (\Throwable $e) {}
        $f = @disk_free_space($CFG->dataroot);
        return $f === false ? null : (float)$f;
    }

    private function finish_job(?string $note): void {
        global $DB, $CFG;
        $jobid = (int)($this->get_custom_data()->jobid ?? 0);
        if ($jobid <= 0 || !$DB->record_exists('learnguard_cleanup_jobs', ['id' => $jobid])) { return; }
        require_once($CFG->dirroot . '/mod/learnguard/lib.php');
        $DB->update_record('learnguard_cleanup_jobs', (object)[
            'id' => $jobid, 'status' => 'done', 'timefinished' => time(),
            'diskusedafter' => learnguard_disk_used_bytes(),
            'errormessage' => $note,
        ]);
    }

    /**
     * Rebuilds one table in batches. Returns 'done', 'skipped' (note added) or 'timeout' (out of
     * time for this cron slot — the twin table is kept and the next run resumes from where it stopped).
     */
    private function rebuild(string $t, int $deadline, array &$notes): string {
        global $DB;
        $rc = $t . '_rc';
        $bak = $t . '_bak';

        if ((string)$DB->get_field_sql('SELECT @@innodb_file_per_table') === '0') {
            $notes[] = "$t skipped: the database stores tables in one shared file (innodb_file_per_table is OFF), " .
                'which never shrinks — that needs a database-administrator dump/restore.';
            return 'skipped';
        }

        [$live, $slack] = $this->sizes($t);
        $resuming = $this->present($rc);
        if (!$resuming) {
            if ($slack < max(50 * 1048576, (int)(($live + $slack) * 0.1))) {
                $notes[] = "$t: nothing significant to reclaim (only " . display_size($slack) . ' of slack).';
                return 'skipped';
            }
            $free = $this->db_free_bytes();
            $need = (int)($live * 1.15);
            if ($free !== null && $free < $need) {
                $notes[] = sprintf('%s skipped: even the surviving data (%s) will not fit in the %s free — free some disk first.',
                    $t, display_size($live), display_size($free));
                return 'skipped';
            }
            $DB->execute("CREATE TABLE {{$rc}} LIKE {{$t}}");
        }

        try {
            $last = (int)$DB->get_field_sql("SELECT COALESCE(MAX(id), 0) FROM {{$rc}}");
            $complete = false;
            while (time() < $deadline) {
                $free = $this->db_free_bytes();
                if ($free !== null && $free < self::MIN_FREE_GUARD) {
                    throw new \RuntimeException('disk almost full (' . display_size($free) . ' free) — stopped copying');
                }
                $DB->execute("INSERT INTO {{$rc}} SELECT * FROM {{$t}} WHERE id > ? ORDER BY id LIMIT " . self::BATCH, [$last]);
                $new = (int)$DB->get_field_sql("SELECT COALESCE(MAX(id), 0) FROM {{$rc}}");
                if ($new === $last) { $complete = true; break; }
                $last = $new;
                usleep(50000);
            }
            if (!$complete) { return 'timeout'; }

            // Swap. New rows keep arriving during an exam, so leave a gap in the twin's
            // auto-increment: anything inserted after the swap can never collide with a
            // straggler row still to be copied over from the old table.
            $maxOld = (int)$DB->get_field_sql("SELECT COALESCE(MAX(id), 0) FROM {{$t}}");
            $ai = (int)$DB->get_field_sql(
                "SELECT AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?",
                [$DB->get_prefix() . $t]);
            $DB->execute("ALTER TABLE {{$rc}} AUTO_INCREMENT = " . (max($ai, $maxOld + 1) + 100000));
            $DB->execute("RENAME TABLE {{$t}} TO {{$bak}}, {{$rc}} TO {{$t}}");

            $DB->execute("INSERT IGNORE INTO {{$t}} SELECT * FROM {{$bak}} WHERE id > ?", [$last]);
            $bakCount = (int)$DB->get_field_sql("SELECT COUNT(*) FROM {{$bak}}");
            $liveCount = (int)$DB->get_field_sql("SELECT COUNT(*) FROM {{$t}}");
            if ($liveCount < $bakCount) {
                // Verification failed — put the original back, carrying over anything written meanwhile.
                $DB->execute("RENAME TABLE {{$t}} TO {{$rc}}, {{$bak}} TO {{$t}}");
                $DB->execute("INSERT IGNORE INTO {{$t}} SELECT * FROM {{$rc}} WHERE id > ?", [$maxOld]);
                $DB->execute("DROP TABLE IF EXISTS {{$rc}}");
                $notes[] = "$t: verification failed (copy had $liveCount rows, original $bakCount) — original kept unchanged.";
                return 'skipped';
            }
            $DB->execute("DROP TABLE {{$bak}}"); // this is the step that actually returns the disk space.
            return 'done';
        } catch (\Throwable $e) {
            if ($this->present($rc)) { $DB->execute("DROP TABLE IF EXISTS {{$rc}}"); }
            $notes[] = "$t: stopped — " . $e->getMessage() . ' (original table untouched).';
            return 'skipped';
        }
    }

    public function execute(): void {
        global $DB, $CFG;

        $historyid = (int)($this->get_custom_data()->historyid ?? 0);
        $historyExists = $historyid > 0 && $DB->record_exists('learnguard_reclaim_history', ['id' => $historyid]);

        set_config('reclaim_status', 'running', 'learnguard');
        if ($historyExists) {
            $h = $DB->get_record('learnguard_reclaim_history', ['id' => $historyid]);
            $DB->update_record('learnguard_reclaim_history', (object)[
                'id' => $historyid, 'status' => 'running',
                'timestarted' => (int)$h->timestarted > 0 ? (int)$h->timestarted : time(),
            ]);
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
            $this->finish_job('Rows were deleted, but disk shrinking is unsupported here: ' . $msg);
            return;
        }

        try {
            // Tables to rebuild come from whoever queued this run; restricted to the LearnGuard media
            // tables plus the short purge whitelist, so this can never be pointed at anything else.
            require_once($CFG->dirroot . '/mod/learnguard/lib.php');
            $allowed = array_merge(['learnguard_snapshots', 'learnguard_audio_clips'], array_keys(learnguard_purge_specs()));
            $requested = (array)($this->get_custom_data()->tables ?? ['learnguard_snapshots', 'learnguard_audio_clips']);
            $tables = array_values(array_filter(
                $requested,
                fn($t) => in_array($t, $allowed, true) && $this->present($t)
            ));

            // Baseline is recorded once, on the first run, so a run resumed across several cron
            // slots still reports the true before/after.
            $h = $historyExists ? $DB->get_record('learnguard_reclaim_history', ['id' => $historyid]) : null;
            if ($h && $h->bytesbefore === null) {
                $DB->set_field('learnguard_reclaim_history', 'bytesbefore', $this->file_bytes($tables), ['id' => $historyid]);
            }

            $deadline = time() + self::BUDGET_SECONDS;
            $notes = [];
            $timedOut = false;
            foreach ($tables as $t) {
                mtrace("LearnGuard reclaim: rebuilding $t in batches…");
                $res = $this->rebuild($t, $deadline, $notes);
                mtrace("LearnGuard reclaim: $t -> $res");
                if ($res === 'timeout') { $timedOut = true; break; }
            }

            if ($timedOut) {
                $again = new self();
                $again->set_custom_data((array)$this->get_custom_data());
                \core\task\manager::queue_adhoc_task($again);
                return; // still 'running' — the next cron run resumes the copy.
            }

            $before = $historyExists ? (int)$DB->get_field('learnguard_reclaim_history', 'bytesbefore', ['id' => $historyid]) : 0;
            $after = $this->file_bytes($tables);
            $reclaimed = max(0, $before - $after);
            $note = empty($notes) ? null : implode(' ', $notes);

            set_config('reclaim_status', 'done', 'learnguard');
            set_config('reclaim_finished', time(), 'learnguard');
            if ($historyExists) {
                $DB->update_record('learnguard_reclaim_history', (object)[
                    'id' => $historyid, 'status' => 'done', 'timefinished' => time(),
                    'bytesafter' => $after, 'bytesreclaimed' => $reclaimed, 'errormessage' => $note,
                ]);
            }
            $this->finish_job($note);
        } catch (\Throwable $e) {
            set_config('reclaim_status', 'error', 'learnguard');
            set_config('reclaim_error', $e->getMessage(), 'learnguard');
            if ($historyExists) {
                $DB->update_record('learnguard_reclaim_history', (object)[
                    'id' => $historyid, 'status' => 'error', 'errormessage' => $e->getMessage(), 'timefinished' => time(),
                ]);
            }
            mtrace('LearnGuard reclaim FAILED: ' . $e->getMessage());
            $this->finish_job('Rows were deleted, but the reclaim failed: ' . $e->getMessage());
        }
    }
}
