<?php

// Autoloader override for GuzzleHttp\Client to inject our Test Double
spl_autoload_register(function ($class) {
    if ($class === 'GuzzleHttp\Client') {
        require __DIR__ . '/MockGuzzleClient.php';
        return true;
    }
    return false;
}, true, true);

// Include composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';
