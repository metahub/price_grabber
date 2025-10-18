<?php

namespace PriceGrabber\Models;

use PriceGrabber\Core\Database;

class ScraperConfig
{
    private $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function create($data)
    {
        $sql = "INSERT INTO scraper_config (hostname, price_selector, seller_selector,
                availability_selector, name_selector, image_selector, currency,
                selector_type, active, notes)
                VALUES (:hostname, :price_selector, :seller_selector, :availability_selector,
                :name_selector, :image_selector, :currency, :selector_type, :active, :notes)";

        $this->db->execute($sql, [
            ':hostname' => $data['hostname'],
            ':price_selector' => $data['price_selector'],
            ':seller_selector' => $data['seller_selector'] ?? null,
            ':availability_selector' => $data['availability_selector'] ?? null,
            ':name_selector' => $data['name_selector'] ?? null,
            ':image_selector' => $data['image_selector'] ?? null,
            ':currency' => $data['currency'] ?? 'USD',
            ':selector_type' => $data['selector_type'] ?? 'css',
            ':active' => $data['active'] ?? true,
            ':notes' => $data['notes'] ?? null,
        ]);

        return $this->db->lastInsertId();
    }

    public function update($id, $data)
    {
        $fields = [];
        $params = [':id' => $id];

        $allowedFields = ['hostname', 'price_selector', 'seller_selector', 'availability_selector',
                         'name_selector', 'image_selector', 'currency', 'selector_type', 'active', 'notes'];

        foreach ($allowedFields as $field) {
            if (isset($data[$field])) {
                $fields[] = "{$field} = :{$field}";
                $params[":{$field}"] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $sql = "UPDATE scraper_config SET " . implode(', ', $fields) . " WHERE id = :id";
        $this->db->execute($sql, $params);

        return true;
    }

    public function findByHostname($hostname)
    {
        $sql = "SELECT * FROM scraper_config WHERE hostname = :hostname AND active = 1";
        return $this->db->fetchOne($sql, [':hostname' => $hostname]);
    }

    public function getAll($activeOnly = true)
    {
        $sql = "SELECT * FROM scraper_config";

        if ($activeOnly) {
            $sql .= " WHERE active = 1";
        }

        $sql .= " ORDER BY hostname";

        return $this->db->fetchAll($sql);
    }

    public function delete($id)
    {
        $sql = "DELETE FROM scraper_config WHERE id = :id";
        return $this->db->execute($sql, [':id' => $id]);
    }
}
