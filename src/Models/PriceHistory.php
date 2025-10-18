<?php

namespace PriceGrabber\Models;

use PriceGrabber\Core\Database;
use PriceGrabber\Core\Logger;

class PriceHistory
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        $sql = "INSERT INTO price_history (product_id, price, uvp, currency, seller, site_status, availability)
                VALUES (:product_id, :price, :uvp, :currency, :seller, :site_status, :availability)";

        try {
            $this->db->execute($sql, [
                ':product_id' => $data['product_id'],
                ':price' => $data['price'],
                ':uvp' => $data['uvp'] ?? null,
                ':currency' => $data['currency'] ?? 'EUR',
                ':seller' => $data['seller'] ?? null,
                ':site_status' => $data['site_status'] ?? null,
                ':availability' => $data['availability'] ?? 'unknown',
            ]);

            Logger::debug('Price history entry created', [
                'product_id' => $data['product_id'],
                'price' => $data['price']
            ]);

            return $this->db->lastInsertId();
        } catch (\Exception $e) {
            Logger::error('Failed to create price history entry', [
                'product_id' => $data['product_id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function getByProduct($productId, $limit = 50)
    {
        $sql = "SELECT * FROM price_history
                WHERE product_id = :product_id
                ORDER BY fetched_at DESC
                LIMIT :limit";

        $stmt = $this->db->getConnection()->prepare($sql);
        $stmt->bindValue(':product_id', $productId, \PDO::PARAM_STR);
        $stmt->bindValue(':limit', (int)$limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function getLatestByProduct($productId)
    {
        $sql = "SELECT * FROM price_history
                WHERE product_id = :product_id
                ORDER BY fetched_at DESC
                LIMIT 1";

        return $this->db->fetchOne($sql, [':product_id' => $productId]);
    }

    public function getPriceChanges($productId, $days = 30)
    {
        $sql = "SELECT DATE(fetched_at) as date,
                       MIN(price) as min_price,
                       MAX(price) as max_price,
                       AVG(price) as avg_price,
                       MIN(uvp) as min_uvp,
                       MAX(uvp) as max_uvp,
                       AVG(uvp) as avg_uvp
                FROM price_history
                WHERE product_id = :product_id
                  AND fetched_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
                GROUP BY DATE(fetched_at)
                ORDER BY date DESC";

        return $this->db->fetchAll($sql, [
            ':product_id' => $productId,
            ':days' => $days
        ]);
    }

    public function getPriceStatistics($productId)
    {
        $sql = "SELECT
                    COUNT(*) as total_entries,
                    MIN(price) as lowest_price,
                    MAX(price) as highest_price,
                    AVG(price) as average_price,
                    MIN(uvp) as lowest_uvp,
                    MAX(uvp) as highest_uvp,
                    AVG(uvp) as average_uvp,
                    MIN(fetched_at) as first_tracked,
                    MAX(fetched_at) as last_tracked
                FROM price_history
                WHERE product_id = :product_id";

        return $this->db->fetchOne($sql, [':product_id' => $productId]);
    }

    public function deleteOldEntries($days = 90)
    {
        $sql = "DELETE FROM price_history
                WHERE fetched_at < DATE_SUB(NOW(), INTERVAL :days DAY)";

        try {
            $stmt = $this->db->execute($sql, [':days' => $days]);
            $count = $stmt->rowCount();
            Logger::info("Deleted old price history entries", ['count' => $count, 'older_than_days' => $days]);
            return $count;
        } catch (\Exception $e) {
            Logger::error('Failed to delete old price history entries', ['error' => $e->getMessage()]);
            throw $e;
        }
    }
}
