<?php

$maxRetries = 30;
$host = getenv('DB_HOST') ?: 'db';
$db = getenv('DB_DATABASE') ?: 'polymarket_bot';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: 'secret';

for ($i = 0; $i < $maxRetries; $i++) {
    try {
        new PDO("mysql:host={$host};dbname={$db}", $user, $pass);
        echo "Database ready.\n";
        exit(0);
    } catch (Exception $e) {
        echo "Waiting for database... ({$e->getMessage()})\n";
        sleep(1);
    }
}

echo "Database not available after {$maxRetries} seconds.\n";
exit(1);
