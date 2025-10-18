<?php

namespace PriceGrabber\Core;

use PDO;
use PDOException;

class Database
{
    private static $instance = null;
    private $connection;

    private function __construct()
    {
        Config::load();

        $host = Config::get('DB_HOST', 'localhost');
        $port = Config::get('DB_PORT', '3306');
        $socket = Config::get('DB_SOCKET');
        $dbname = Config::get('DB_NAME', 'price_grabber');
        $username = Config::get('DB_USER', 'root');
        $password = Config::get('DB_PASSWORD', '');

        try {
            // Use socket if provided, otherwise use host:port
            if ($socket) {
                $dsn = "mysql:unix_socket={$socket};dbname={$dbname};charset=utf8mb4";
            } else {
                $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";
            }
            $this->connection = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new \Exception("Database connection failed: " . $e->getMessage());
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    public function query($sql, $params = [])
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll($sql, $params = [])
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne($sql, $params = [])
    {
        return $this->query($sql, $params)->fetch();
    }

    public function execute($sql, $params = [])
    {
        return $this->query($sql, $params);
    }

    public function lastInsertId()
    {
        return $this->connection->lastInsertId();
    }
}
