<?php

namespace PriceGrabber\Models;

use PriceGrabber\Core\Database;
use PriceGrabber\Core\Logger;

class Settings
{
    private $db;
    private static $cache = [];

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    public function get($key, $default = null)
    {
        // Check cache first
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $sql = "SELECT value, type FROM settings WHERE `key` = :key";
        $result = $this->db->fetchOne($sql, [':key' => $key]);

        if (!$result) {
            return $default;
        }

        // Convert value based on type
        $value = $this->convertValue($result['value'], $result['type']);

        // Cache the result
        self::$cache[$key] = $value;

        return $value;
    }

    public function set($key, $value)
    {
        $sql = "UPDATE settings SET value = :value, updated_at = NOW() WHERE `key` = :key";

        try {
            $this->db->execute($sql, [
                ':key' => $key,
                ':value' => $value
            ]);

            // Clear cache for this key
            unset(self::$cache[$key]);

            Logger::info('Setting updated', ['key' => $key]);
            return true;
        } catch (\Exception $e) {
            Logger::error('Failed to update setting', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function getAll()
    {
        $sql = "SELECT * FROM settings ORDER BY category, `key`";
        return $this->db->fetchAll($sql);
    }

    public function getByCategory($category)
    {
        $sql = "SELECT * FROM settings WHERE category = :category ORDER BY `key`";
        return $this->db->fetchAll($sql, [':category' => $category]);
    }

    public function getAllCategories()
    {
        $sql = "SELECT DISTINCT category FROM settings ORDER BY category";
        return $this->db->fetchAll($sql);
    }

    private function convertValue($value, $type)
    {
        switch ($type) {
            case 'boolean':
                return in_array(strtolower($value), ['true', '1', 'yes', 'on']);
            case 'integer':
                return (int)$value;
            case 'string':
            default:
                return $value;
        }
    }

    public function create($data)
    {
        $sql = "INSERT INTO settings (`key`, value, description, type, category)
                VALUES (:key, :value, :description, :type, :category)";

        try {
            $this->db->execute($sql, [
                ':key' => $data['key'],
                ':value' => $data['value'],
                ':description' => $data['description'] ?? null,
                ':type' => $data['type'] ?? 'string',
                ':category' => $data['category'] ?? 'general'
            ]);

            Logger::info('Setting created', ['key' => $data['key']]);
            return $this->db->lastInsertId();
        } catch (\Exception $e) {
            Logger::error('Failed to create setting', [
                'key' => $data['key'] ?? 'unknown',
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function delete($key)
    {
        $sql = "DELETE FROM settings WHERE `key` = :key";

        try {
            $result = $this->db->execute($sql, [':key' => $key]);

            // Clear cache
            unset(self::$cache[$key]);

            Logger::info('Setting deleted', ['key' => $key]);
            return $result;
        } catch (\Exception $e) {
            Logger::error('Failed to delete setting', [
                'key' => $key,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public static function clearCache()
    {
        self::$cache = [];
    }
}
