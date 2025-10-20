<?php

namespace PriceGrabber\Models;

use PriceGrabber\Core\Database;
use PriceGrabber\Core\Logger;

class ScraperRun
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Start a new scraper run
     *
     * @param int|null $limit Limit parameter if provided
     * @return int Run ID
     */
    public function startRun($limit = null)
    {
        $pid = getmypid();
        $hostname = gethostname();

        $sql = "INSERT INTO scraper_runs (process_id, hostname, limit_parameter, status)
                VALUES (:pid, :hostname, :limit, 'running')";

        try {
            $this->db->execute($sql, [
                ':pid' => $pid,
                ':hostname' => $hostname,
                ':limit' => $limit
            ]);

            $runId = $this->db->lastInsertId();

            Logger::info('Scraper run started', [
                'run_id' => $runId,
                'pid' => $pid,
                'limit' => $limit
            ]);

            return $runId;
        } catch (\Exception $e) {
            Logger::error('Failed to start scraper run', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Complete a scraper run
     *
     * @param int $runId Run ID
     * @param int $itemsProcessed Number of items successfully processed
     * @param int $itemsFailed Number of items that failed
     * @param int $itemsTotal Total items attempted
     * @param int $botChallenges Number of bot/WAF challenges encountered
     * @param int $successfulBypasses Number of successful Chrome bypasses
     * @return bool Success
     */
    public function completeRun($runId, $itemsProcessed, $itemsFailed, $itemsTotal, $botChallenges = 0, $successfulBypasses = 0)
    {
        $sql = "UPDATE scraper_runs
                SET ended_at = NOW(),
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
                    items_processed = :items_processed,
                    items_failed = :items_failed,
                    items_total = :items_total,
                    bot_challenges = :bot_challenges,
                    successful_bypasses = :successful_bypasses,
                    status = 'completed'
                WHERE id = :run_id";

        try {
            $this->db->execute($sql, [
                ':run_id' => $runId,
                ':items_processed' => $itemsProcessed,
                ':items_failed' => $itemsFailed,
                ':items_total' => $itemsTotal,
                ':bot_challenges' => $botChallenges,
                ':successful_bypasses' => $successfulBypasses
            ]);

            Logger::info('Scraper run completed', [
                'run_id' => $runId,
                'items_processed' => $itemsProcessed,
                'items_failed' => $itemsFailed,
                'items_total' => $itemsTotal,
                'bot_challenges' => $botChallenges,
                'successful_bypasses' => $successfulBypasses
            ]);

            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to complete scraper run', [
                'run_id' => $runId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Mark a scraper run as failed
     *
     * @param int $runId Run ID
     * @param string $errorMessage Error message
     * @return bool Success
     */
    public function failRun($runId, $errorMessage)
    {
        $sql = "UPDATE scraper_runs
                SET ended_at = NOW(),
                    duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
                    status = 'failed',
                    error_message = :error_message
                WHERE id = :run_id";

        try {
            $this->db->execute($sql, [
                ':run_id' => $runId,
                ':error_message' => $errorMessage
            ]);

            Logger::info('Scraper run marked as failed', [
                'run_id' => $runId,
                'error' => $errorMessage
            ]);

            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to mark scraper run as failed', [
                'run_id' => $runId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Get all scraper runs with pagination
     *
     * @param int $limit Number of runs to fetch
     * @param int $offset Offset for pagination
     * @return array Scraper runs
     */
    public function getAll($limit = 50, $offset = 0)
    {
        $sql = "SELECT * FROM scraper_runs
                ORDER BY started_at DESC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Get total count of scraper runs
     *
     * @return int Total count
     */
    public function count()
    {
        $sql = "SELECT COUNT(*) as total FROM scraper_runs";
        $result = $this->db->fetchOne($sql);
        return $result ? (int)$result['total'] : 0;
    }

    /**
     * Get scraper run by ID
     *
     * @param int $runId Run ID
     * @return array|null Run data or null if not found
     */
    public function findById($runId)
    {
        $sql = "SELECT * FROM scraper_runs WHERE id = :run_id";
        return $this->db->fetchOne($sql, [':run_id' => $runId]);
    }

    /**
     * Get scraper run statistics
     *
     * @return array Statistics
     */
    public function getStatistics()
    {
        $sql = "SELECT
                    COUNT(*) as total_runs,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_runs,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed_runs,
                    SUM(items_processed) as total_items_processed,
                    SUM(items_failed) as total_items_failed,
                    SUM(bot_challenges) as total_bot_challenges,
                    SUM(successful_bypasses) as total_successful_bypasses,
                    AVG(duration_seconds) as avg_duration_seconds,
                    AVG(CASE WHEN duration_seconds > 0 THEN items_processed / (duration_seconds / 60.0) ELSE 0 END) as avg_items_per_minute
                FROM scraper_runs
                WHERE status IN ('completed', 'failed')";

        return $this->db->fetchOne($sql);
    }

    /**
     * Clean up old scraper runs
     *
     * @param int $daysToKeep Number of days to keep runs
     * @return int Number of runs deleted
     */
    public function cleanOldRuns($daysToKeep = 30)
    {
        $sql = "DELETE FROM scraper_runs
                WHERE started_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        try {
            $stmt = $this->db->getConnection()->prepare($sql);
            $stmt->bindValue(':days', (int)$daysToKeep, \PDO::PARAM_INT);
            $stmt->execute();

            $deleted = $stmt->rowCount();

            Logger::info('Cleaned old scraper runs', [
                'days_to_keep' => $daysToKeep,
                'deleted' => $deleted
            ]);

            return $deleted;
        } catch (\Exception $e) {
            Logger::error('Failed to clean old scraper runs', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }
}
