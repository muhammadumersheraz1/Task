<?php
/**
 * Task 1 — Fetch latest emails from IMAP and store in MySQL.
 *
 * Usage:
 *   php task1/fetch_emails.php
 *
 * Safe for cron: flock lockfile, INSERT IGNORE on message_id, short timeouts.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';
require_once __DIR__ . '/lib/ImapClient.php';
require_once __DIR__ . '/lib/MailParser.php';

date_default_timezone_set(Database::config()['app']['timezone'] ?? 'UTC');

$lockDir = dirname(__DIR__) . '/storage/locks';
if (!is_dir($lockDir)) {
    mkdir($lockDir, 0775, true);
}
$lockFile = $lockDir . '/fetch_emails.lock';
$lockFp = fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another fetch is already running — exiting.\n");
    exit(0);
}

set_time_limit(90);

$cfg = Database::config()['imap'];
if ($cfg['username'] === '' || $cfg['password'] === '') {
    fwrite(STDERR, "Set IMAP credentials in config/config.local.php (see config.local.php.example).\n");
    exit(1);
}

$pdo = Database::pdo();
$insert = $pdo->prepare(
    'INSERT IGNORE INTO emails (message_id, from_address, subject, email_date, body_preview, attachments)
     VALUES (:message_id, :from_address, :subject, :email_date, :body_preview, :attachments)'
);

$client = new ImapClient($cfg['host'], (int) $cfg['port'], $cfg['encryption'] ?? 'ssl');
$stats = ['fetched' => 0, 'inserted' => 0, 'skipped' => 0, 'errors' => 0];

try {
    $client->connect(25);
    $client->login($cfg['username'], $cfg['password']);
    $client->select($cfg['mailbox'] ?? 'INBOX');

    $limit = (int) ($cfg['fetch_limit'] ?? 20);
    $uids = $client->latestUids($limit);
    $stats['fetched'] = count($uids);

    echo "Found " . count($uids) . " message(s) to process.\n";

    foreach ($uids as $uid) {
        try {
            $raw = $client->fetchRaw($uid);
            if ($raw === '') {
                echo "UID {$uid}: empty body, skipping\n";
                $stats['errors']++;
                continue;
            }
            $mail = MailParser::parse($raw);
            $insert->execute([
                ':message_id'   => $mail['message_id'],
                ':from_address' => $mail['from'],
                ':subject'      => $mail['subject'],
                ':email_date'   => $mail['date'],
                ':body_preview' => $mail['preview'],
                ':attachments'  => json_encode($mail['attachments'], JSON_UNESCAPED_UNICODE),
            ]);
            if ($insert->rowCount() > 0) {
                $stats['inserted']++;
                $att = $mail['attachments'] ? implode(', ', $mail['attachments']) : '(none)';
                echo "+ {$mail['subject']} | from={$mail['from']} | attachments={$att}\n";
            } else {
                $stats['skipped']++;
                echo "= duplicate message-id {$mail['message_id']}\n";
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            echo "! UID {$uid}: {$e->getMessage()}\n";
        }
    }

    $client->logout();
} catch (Throwable $e) {
    fwrite(STDERR, 'Fatal: ' . $e->getMessage() . "\n");
    $client->close();
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(1);
}

flock($lockFp, LOCK_UN);
fclose($lockFp);

echo json_encode(['ok' => true, 'stats' => $stats], JSON_PRETTY_PRINT) . "\n";
exit(0);