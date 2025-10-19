<?php

namespace PriceGrabber\Models;

use PriceGrabber\Core\Database;
use PriceGrabber\Core\Logger;

class Product
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        $sql = "INSERT INTO products (product_id, parent_id, sku, ean, site, site_product_id,
                price, uvp, site_status, url, name, description, image_url)
                VALUES (:product_id, :parent_id, :sku, :ean, :site, :site_product_id,
                :price, :uvp, :site_status, :url, :name, :description, :image_url)";

        try {
            $this->db->execute($sql, [
                ':product_id' => $data['product_id'],
                ':parent_id' => $data['parent_id'] ?? null,
                ':sku' => $data['sku'] ?? null,
                ':ean' => $data['ean'] ?? null,
                ':site' => $data['site'] ?? null,
                ':site_product_id' => $data['site_product_id'] ?? null,
                ':price' => $data['price'] ?? null,
                ':uvp' => $data['uvp'] ?? null,
                ':site_status' => $data['site_status'] ?? null,
                ':url' => $data['url'],
                ':name' => $data['name'] ?? null,
                ':description' => $data['description'] ?? null,
                ':image_url' => $data['image_url'] ?? null,
            ]);

            Logger::info('Product created', ['product_id' => $data['product_id']]);
            return $this->db->lastInsertId();
        } catch (\Exception $e) {
            Logger::error('Failed to create product', [
                'product_id' => $data['product_id'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function update($productId, $data)
    {
        $fields = [];
        $params = [':product_id' => $productId];

        $allowedFields = ['parent_id', 'sku', 'ean', 'site', 'site_product_id',
                         'price', 'uvp', 'site_status', 'url', 'name', 'description', 'image_url'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        // Always update the updated_at timestamp
        $fields[] = "updated_at = NOW()";

        $sql = "UPDATE products SET " . implode(', ', $fields) . " WHERE product_id = :product_id";

        try {
            $this->db->execute($sql, $params);
            Logger::info('Product updated', ['product_id' => $productId]);
            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to update product', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function findById($id)
    {
        $sql = "SELECT * FROM products WHERE id = :id";
        return $this->db->fetchOne($sql, [':id' => $id]);
    }

    public function findByProductId($productId)
    {
        $sql = "SELECT * FROM products WHERE product_id = :product_id";
        return $this->db->fetchOne($sql, [':product_id' => $productId]);
    }

    public function findByUrl($url)
    {
        $sql = "SELECT * FROM products WHERE url = :url";
        return $this->db->fetchOne($sql, [':url' => $url]);
    }

    public function findBySku($sku)
    {
        $sql = "SELECT * FROM products WHERE sku = :sku";
        return $this->db->fetchOne($sql, [':sku' => $sku]);
    }

    public function findByEan($ean)
    {
        $sql = "SELECT * FROM products WHERE ean = :ean";
        return $this->db->fetchOne($sql, [':ean' => $ean]);
    }

    public function getAll($filters = [])
    {
        $sql = "SELECT p.*,
                       parent.product_id as parent_product_id,
                       parent.name as parent_name,
                       (SELECT COUNT(*) FROM products WHERE parent_id = p.product_id) as child_count
                FROM products p
                LEFT JOIN products parent ON p.parent_id = parent.product_id";

        $params = [];

        // Handle seller filter with subquery using named parameters
        if (!empty($filters['sellers']) && is_array($filters['sellers'])) {
            $sellerPlaceholders = [];
            foreach ($filters['sellers'] as $index => $seller) {
                $placeholder = ":seller_{$index}";
                $sellerPlaceholders[] = $placeholder;
                $params[$placeholder] = $seller;
            }

            $sql .= " INNER JOIN (
                        SELECT DISTINCT product_id
                        FROM price_history
                        WHERE seller IN (" . implode(',', $sellerPlaceholders) . ")
                        AND fetched_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                      ) ph ON p.product_id = ph.product_id";
        }

        $sql .= " WHERE 1=1";

        if (!empty($filters['search'])) {
            $searchValue = '%' . $filters['search'] . '%';
            $sql .= " AND (p.name LIKE :search1 OR p.description LIKE :search2 OR p.product_id LIKE :search3 OR p.sku LIKE :search4 OR p.ean LIKE :search5)";
            $params[':search1'] = $searchValue;
            $params[':search2'] = $searchValue;
            $params[':search3'] = $searchValue;
            $params[':search4'] = $searchValue;
            $params[':search5'] = $searchValue;
        }

        if (isset($filters['parent_id'])) {
            if ($filters['parent_id'] === 'null') {
                $sql .= " AND p.parent_id IS NULL";
            } else {
                $sql .= " AND p.parent_id = :parent_id";
                $params[':parent_id'] = $filters['parent_id'];
            }
        }

        if (!empty($filters['site'])) {
            $sql .= " AND p.site = :site";
            $params[':site'] = $filters['site'];
        }

        if (!empty($filters['site_status'])) {
            $sql .= " AND p.site_status = :site_status";
            $params[':site_status'] = $filters['site_status'];
        }

        $sql .= " ORDER BY p.created_at DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT :limit";
            $params[':limit'] = (int)$filters['limit'];
        }

        return $this->db->fetchAll($sql, $params);
    }

    public function getChildren($parentProductId)
    {
        $sql = "SELECT * FROM products WHERE parent_id = :parent_id ORDER BY name";
        return $this->db->fetchAll($sql, [':parent_id' => $parentProductId]);
    }

    public function delete($productId)
    {
        $sql = "DELETE FROM products WHERE product_id = :product_id";
        try {
            $result = $this->db->execute($sql, [':product_id' => $productId]);
            Logger::info('Product deleted', ['product_id' => $productId]);
            return $result;
        } catch (\Exception $e) {
            Logger::error('Failed to delete product', [
                'product_id' => $productId,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function getSites()
    {
        $sql = "SELECT DISTINCT site FROM products WHERE site IS NOT NULL ORDER BY site";
        return $this->db->fetchAll($sql);
    }

    public function getSiteStatuses()
    {
        $sql = "SELECT DISTINCT site_status FROM products WHERE site_status IS NOT NULL ORDER BY site_status";
        return $this->db->fetchAll($sql);
    }
}
