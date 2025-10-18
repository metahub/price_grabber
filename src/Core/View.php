<?php

namespace PriceGrabber\Core;

use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class View
{
    private static $twig = null;

    /**
     * Initialize Twig environment
     *
     * @return Environment
     */
    public static function getTwig()
    {
        if (self::$twig === null) {
            $loader = new FilesystemLoader(__DIR__ . '/../../templates');
            self::$twig = new Environment($loader, [
                'cache' => __DIR__ . '/../../cache/twig',
                'auto_reload' => true,
                'debug' => true
            ]);
        }

        return self::$twig;
    }

    /**
     * Render a template
     *
     * @param string $template Template name
     * @param array $data Data to pass to template
     * @return string Rendered HTML
     */
    public static function render($template, $data = [])
    {
        $twig = self::getTwig();
        return $twig->render($template, $data);
    }

    /**
     * Display a template (echo render)
     *
     * @param string $template Template name
     * @param array $data Data to pass to template
     */
    public static function display($template, $data = [])
    {
        // Automatically add current user info if logged in
        try {
            $auth = Auth::getInstance();
            if ($auth->isLoggedIn()) {
                $data['current_user'] = [
                    'id' => $auth->getUserId(),
                    'email' => $auth->getEmail(),
                    'username' => $auth->getUsername()
                ];
            }
        } catch (\Exception $e) {
            // Auth not available, skip
        }

        echo self::render($template, $data);
    }
}
