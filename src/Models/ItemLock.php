<?php

namespace PriceGrabber\Models;

use PriceGrabber\Core\Database;
use PriceGrabber\Core\Logger;

class ItemLock
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Try to acquire a lock on an item
     * Uses INSERT ... ON DUPLICATE KEY UPDATE to atomically acquire lock
     *
     * @param string $productId Product ID to lock
     * @param int $scraperRunId Scraper run ID
     * @param int $processId Process ID (PID)
     * @param int $timeoutSeconds Lock timeout in seconds (for cleaning stale locks)
     * @return bool True if lock acquired, false if item already locked
     */
    public function tryLockItem($productId, $scraperRunId, $processId, $timeoutSeconds = 180)
    {
        // First, try to clean any stale lock for this specific product
        $this->cleanStaleLocksForProduct($productId, $timeoutSeconds);

        // Try to insert a new lock (will fail if item is already locked)
        $sql = "INSERT INTO item_locks (product_id, scraper_run_id, process_id, locked_at)
                VALUES (:product_id, :run_id, :pid, NOW())";

        try {
            $this->db->execute($sql, [
                ':product_id' => $productId,
                ':run_id' => $scraperRunId,
                ':pid' => $processId
            ]);

            Logger::debug("Item lock acquired", [
                'product_id' => $productId,
                'run_id' => $scraperRunId,
                'pid' => $processId
            ]);
            return true;

        } catch (\PDOException $e) {
            // Check if it's a duplicate key error (1062)
            if ($e->getCode() == 23000 || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                Logger::debug("Item already locked by another scraper", [
                    'product_id' => $productId,
                    'our_run_id' => $scraperRunId
                ]);
                return false;
            }

            // Some other error
            Logger::error("Failed to acquire item lock", [
                'product_id' => $productId,
                'run_id' => $scraperRunId,
                'error' => $e->getMessage(),
                'code' => $e->getCode()
            ]);
            return false;
        } catch (\Exception $e) {
            Logger::error("Failed to acquire item lock", [
                'product_id' => $productId,
                'run_id' => $scraperRunId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Release a specific item lock
     *
     * @param string $productId Product ID to unlock
     * @return bool Success
     */
    public function releaseLock($productId)
    {
        $sql = "DELETE FROM item_locks WHERE product_id = :product_id";

        try {
            $this->db->execute($sql, [':product_id' => $productId]);

            Logger::debug("Item lock released", ['product_id' => $productId]);
            return true;
        } catch (\Exception $e) {
            Logger::error("Failed to release item lock", [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Release all locks for a specific scraper run
     *
     * @param int $scraperRunId Scraper run ID
     * @return int Number of locks released
     */
    public function releaseAllLocks($scraperRunId)
    {
        $sql = "DELETE FROM item_locks WHERE scraper_run_id = :run_id";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([':run_id' => $scraperRunId]);

            $count = $stmt->rowCount();

            if ($count > 0) {
                Logger::info("Released all item locks for scraper run", [
                    'run_id' => $scraperRunId,
                    'count' => $count
                ]);
            }

            return $count;
        } catch (\Exception $e) {
            Logger::error("Failed to release all locks", [
                'run_id' => $scraperRunId,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Clean stale locks (older than timeout)
     *
     * @param int $timeoutSeconds Lock timeout in seconds
     * @return int Number of stale locks cleaned
     */
    public function cleanStaleLocks($timeoutSeconds)
    {
        $sql = "DELETE FROM item_locks
                WHERE locked_at < DATE_SUB(NOW(), INTERVAL :timeout SECOND)";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->execute([':timeout' => $timeoutSeconds]);

            $count = $stmt->rowCount();

            if ($count > 0) {
                Logger::info("Cleaned stale item locks", [
                    'count' => $count,
                    'timeout_seconds' => $timeoutSeconds
                ]);
            }

            return $count;
        } catch (\Exception $e) {
            Logger::error("Failed to clean stale locks", [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Clean stale lock for a specific product
     *
     * @param string $productId Product ID
     * @param int $timeoutSeconds Lock timeout in seconds
     * @return bool Success
     */
    private function cleanStaleLocksForProduct($productId, $timeoutSeconds)
    {
        $sql = "DELETE FROM item_locks
                WHERE product_id = :product_id
                AND locked_at < DATE_SUB(NOW(), INTERVAL :timeout SECOND)";

        try {
            $this->db->execute($sql, [
                ':product_id' => $productId,
                ':timeout' => $timeoutSeconds
            ]);
            return true;
        } catch (\Exception $e) {
            Logger::error("Failed to clean stale lock for product", [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Check if an item is currently locked
     *
     * @param string $productId Product ID
     * @param int $timeoutSeconds Lock timeout in seconds (for checking stale locks)
     * @return bool True if locked (and not stale), false otherwise
     */
    public function isLocked($productId, $timeoutSeconds = 180)
    {
        $sql = "SELECT id FROM item_locks
                WHERE product_id = :product_id
                AND locked_at >= DATE_SUB(NOW(), INTERVAL :timeout SECOND)";

        $result = $this->db->fetchOne($sql, [
            ':product_id' => $productId,
            ':timeout' => $timeoutSeconds
        ]);

        return $result !== null;
    }

    /**
     * Get current lock details for an item
     *
     * @param string $productId Product ID
     * @return array|null Lock details or null if not locked
     */
    public function getCurrentLock($productId)
    {
        $sql = "SELECT * FROM item_locks WHERE product_id = :product_id";
        return $this->db->fetchOne($sql, [':product_id' => $productId]);
    }

    /**
     * Get count of currently locked items
     *
     * @param int $timeoutSeconds Lock timeout in seconds
     * @return int Number of locked items
     */
    public function countLockedItems($timeoutSeconds = 180)
    {
        $sql = "SELECT COUNT(*) as total FROM item_locks
                WHERE locked_at >= DATE_SUB(NOW(), INTERVAL :timeout SECOND)";

        $result = $this->db->fetchOne($sql, [':timeout' => $timeoutSeconds]);
        return $result ? (int)$result['total'] : 0;
    }

    /**
     * Get all locked items for a scraper run
     *
     * @param int $scraperRunId Scraper run ID
     * @return array Locked items
     */
    public function getLockedItemsForRun($scraperRunId)
    {
        $sql = "SELECT * FROM item_locks WHERE scraper_run_id = :run_id ORDER BY locked_at";
        return $this->db->fetchAll($sql, [':run_id' => $scraperRunId]);
    }
}
