<?php

namespace PriceGrabber\Models;

use PriceGrabber\Core\Database;
use PriceGrabber\Core\Logger;

class ScraperLock
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Try to acquire a scraper lock
     * In parallel mode, multiple locks can exist (up to max_concurrent_scrapers)
     *
     * @return bool True if lock was acquired, false if max limit reached
     */
    public function acquireLock()
    {
        // First, clean any stale locks
        $this->cleanStaleLocks();

        // Acquire the lock (multiple locks allowed in parallel mode)
        $pid = getmypid();
        $hostname = gethostname();

        $sql = "INSERT INTO scraper_locks (process_id, hostname) VALUES (:pid, :hostname)";

        try {
            $this->db->execute($sql, [
                ':pid' => $pid,
                ':hostname' => $hostname
            ]);

            Logger::info('Scraper lock acquired', [
                'pid' => $pid,
                'hostname' => $hostname
            ]);

            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to acquire scraper lock', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Release the current scraper lock
     *
     * @return bool True if lock was released
     */
    public function releaseLock()
    {
        $pid = getmypid();

        $sql = "DELETE FROM scraper_locks WHERE process_id = :pid";

        try {
            $this->db->execute($sql, [':pid' => $pid]);

            Logger::info('Scraper lock released', ['pid' => $pid]);
            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to release scraper lock', [
                'pid' => $pid,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if scraper is currently locked
     *
     * @return bool True if locked and process is running
     */
    public function isLocked()
    {
        $lock = $this->getCurrentLock();

        if (!$lock) {
            return false;
        }

        // Check if the process is still running
        if ($this->isProcessRunning($lock['process_id'])) {
            return true;
        }

        // Process is dead, this is a stale lock
        return false;
    }

    /**
     * Get the current lock details
     *
     * @return array|null Lock details or null if no lock exists
     */
    public function getCurrentLock()
    {
        $sql = "SELECT * FROM scraper_locks ORDER BY started_at DESC LIMIT 1";
        return $this->db->fetchOne($sql);
    }

    /**
     * Clean up stale locks (locks where process is no longer running)
     *
     * @return int Number of stale locks removed
     */
    public function cleanStaleLocks()
    {
        $locks = $this->getAllLocks();
        $cleaned = 0;

        foreach ($locks as $lock) {
            if (!$this->isProcessRunning($lock['process_id'])) {
                $sql = "DELETE FROM scraper_locks WHERE id = :id";
                $this->db->execute($sql, [':id' => $lock['id']]);

                Logger::info('Cleaned stale scraper lock', [
                    'pid' => $lock['process_id'],
                    'started_at' => $lock['started_at']
                ]);

                $cleaned++;
            }
        }

        return $cleaned;
    }

    /**
     * Get all locks
     *
     * @return array All lock records
     */
    private function getAllLocks()
    {
        $sql = "SELECT * FROM scraper_locks";
        return $this->db->fetchAll($sql);
    }

    /**
     * Check if a process is currently running
     *
     * @param int $pid Process ID
     * @return bool True if process is running
     */
    private function isProcessRunning($pid)
    {
        // Check if posix extension is available
        if (function_exists('posix_getpgid')) {
            // posix_getpgid returns false if process doesn't exist
            return posix_getpgid($pid) !== false;
        }

        // Fallback for systems without posix extension
        // Use ps command to check if process exists
        $output = [];
        $result = 0;
        exec("ps -p {$pid}", $output, $result);

        // If ps returns 0, process exists
        return $result === 0 && count($output) > 1;
    }

    /**
     * Count active scraper locks (where process is still running)
     *
     * @return int Number of active scraper instances
     */
    public function countActiveLocks()
    {
        $locks = $this->getAllLocks();
        $activeCount = 0;

        foreach ($locks as $lock) {
            if ($this->isProcessRunning($lock['process_id'])) {
                $activeCount++;
            }
        }

        return $activeCount;
    }

    /**
     * Force release all locks (use with caution)
     *
     * @return bool True if all locks were released
     */
    public function forceReleaseAll()
    {
        $sql = "DELETE FROM scraper_locks";

        try {
            $this->db->execute($sql);
            Logger::warning('All scraper locks force released');
            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to force release scraper locks', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
