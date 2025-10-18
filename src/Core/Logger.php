<?php

namespace PriceGrabber\Core;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class Logger
{
    private static $instance = null;
    private $logger;

    private function __construct()
    {
        Config::load();

        $this->logger = new MonologLogger('price_grabber');

        // Create logs directory if it doesn't exist
        $logDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        // Configure log format
        $dateFormat = "Y-m-d H:i:s";
        $output = "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n";
        $formatter = new LineFormatter($output, $dateFormat);

        // Add rotating file handler (keeps 30 days of logs)
        $fileHandler = new RotatingFileHandler(
            $logDir . '/app.log',
            30,
            MonologLogger::DEBUG
        );
        $fileHandler->setFormatter($formatter);
        $this->logger->pushHandler($fileHandler);

        // Add console handler for CLI if in debug mode
        if (Config::get('APP_DEBUG', 'false') === 'true' && php_sapi_name() === 'cli') {
            $streamHandler = new StreamHandler('php://stdout', MonologLogger::DEBUG);
            $streamHandler->setFormatter($formatter);
            $this->logger->pushHandler($streamHandler);
        }
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function getLogger()
    {
        return $this->logger;
    }

    // Convenience methods
    public static function debug($message, array $context = [])
    {
        self::getInstance()->getLogger()->debug($message, $context);
    }

    public static function info($message, array $context = [])
    {
        self::getInstance()->getLogger()->info($message, $context);
    }

    public static function warning($message, array $context = [])
    {
        self::getInstance()->getLogger()->warning($message, $context);
    }

    public static function error($message, array $context = [])
    {
        self::getInstance()->getLogger()->error($message, $context);
    }

    public static function critical($message, array $context = [])
    {
        self::getInstance()->getLogger()->critical($message, $context);
    }
}
