<?php
require_once __DIR__ . '/base.php';

function setup_wizards_all(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    $files = [
        'google_cse.php'   => 'GoogleCseWizard',
        'resend.php'       => 'ResendWizard',
        'reddit_app.php'   => 'RedditAppWizard',
        'linkedin_app.php' => 'LinkedInAppWizard',
        'twitter_app.php'  => 'TwitterAppWizard',
        'gsc_app.php'      => 'GscAppWizard',
    ];
    foreach ($files as $file => $class) {
        require_once __DIR__ . '/' . $file;
        if (class_exists($class)) {
            $cache[(new $class())->id()] = new $class();
        }
    }
    return $cache;
}

function setup_wizard(string $id): ?SetupWizard
{
    $all = setup_wizards_all();
    return $all[$id] ?? null;
}
