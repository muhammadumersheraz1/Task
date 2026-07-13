<?php
/**
 * Shared application configuration.
 * Credentials come from .env (preferred), then config.local.php overrides.
 */
declare(strict_types=1);

require_once __DIR__ . '/env.php';

Env::load(dirname(__DIR__) . '/.env');

$local = __DIR__ . '/config.local.php';
$overrides = is_file($local) ? require $local : [];

return array_replace_recursive([
    'db' => [
        'host'    => Env::get('DB_HOST', '127.0.0.1'),
        'port'    => (int) Env::get('DB_PORT', '3306'),
        'name'    => Env::get('DB_NAME', 'inquiry_tracker'),
        'user'    => Env::get('DB_USER', 'task'),
        'pass'    => Env::get('DB_PASS', 'TaskPass123!'),
        'charset' => Env::get('DB_CHARSET', 'utf8mb4'),
    ],
    'imap' => [
        'host'        => Env::get('IMAP_HOST', 'imap.gmail.com'),
        'port'        => (int) Env::get('IMAP_PORT', '993'),
        'encryption'  => Env::get('IMAP_ENCRYPTION', 'ssl'),
        'username'    => Env::get('IMAP_USERNAME', ''),
        // Gmail app passwords are often shown with spaces — strip them
        'password'    => str_replace(' ', '', (string) Env::get('IMAP_PASSWORD', '')),
        'mailbox'     => Env::get('IMAP_MAILBOX', 'INBOX'),
        'fetch_limit' => (int) Env::get('IMAP_FETCH_LIMIT', '20'),
    ],
    'app' => [
        'timezone' => Env::get('APP_TIMEZONE', 'UTC'),
        'per_page' => (int) Env::get('APP_PER_PAGE', '20'),
    ],
], $overrides);