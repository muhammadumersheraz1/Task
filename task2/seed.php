<?php
/**
 * Seed inquiries with 100,000 rows (batched inserts).
 *
 *   php task2/seed.php
 *   php task2/seed.php --count=100000 --truncate
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$opts = getopt('', ['count::', 'truncate']);
$count = isset($opts['count']) ? max(1, (int) $opts['count']) : 100_000;
$truncate = array_key_exists('truncate', $opts);

$pdo = Database::pdo();
$pdo->exec('SET NAMES utf8mb4');

if ($truncate) {
    echo "Truncating inquiries…\n";
    $pdo->exec('TRUNCATE TABLE inquiries');
}

$existing = (int) $pdo->query('SELECT COUNT(*) FROM inquiries')->fetchColumn();
if ($existing >= $count && !$truncate) {
    echo "Already have {$existing} rows (>= {$count}). Use --truncate to reseeds.\n";
    exit(0);
}

$need = $truncate ? $count : max(0, $count - $existing);
echo "Inserting {$need} row(s)…\n";

$firstNames = ['Alex', 'Sam', 'Jordan', 'Taylor', 'Casey', 'Riley', 'Morgan', 'Avery', 'Quinn', 'Jamie',
    'Chris', 'Pat', 'Dana', 'Cameron', 'Reese', 'Skyler', 'Drew', 'Harper', 'Rowan', 'Logan'];
$lastNames = ['Smith', 'Johnson', 'Lee', 'Garcia', 'Brown', 'Wilson', 'Martin', 'Clark', 'Lewis', 'Walker',
    'Young', 'King', 'Wright', 'Lopez', 'Hill', 'Green', 'Baker', 'Adams', 'Nelson', 'Carter'];
$domains = ['example.com', 'mail.test', 'inbox.dev', 'customer.org', 'demo.io'];
$statuses = ['new', 'in-progress', 'closed'];
$notesPool = [
    'Interested in pricing',
    'Please call back tomorrow',
    'Wants a product demo',
    'Billing question',
    'Follow-up from website form',
    'Requested brochure',
    null,
];

$batchSize = 1000;
$sqlPrefix = 'INSERT INTO inquiries (customer_name, email, phone, status, notes, created_at) VALUES ';
$started = microtime(true);
$inserted = 0;

$pdo->beginTransaction();
try {
    for ($i = 0; $i < $need; $i += $batchSize) {
        $n = min($batchSize, $need - $i);
        $values = [];
        $params = [];
        for ($j = 0; $j < $n; $j++) {
            $idx = $existing + $inserted + $j + 1;
            $name = $firstNames[$idx % count($firstNames)] . ' ' . $lastNames[($idx * 7) % count($lastNames)];
            $email = strtolower(str_replace(' ', '.', $name)) . '+' . $idx . '@' . $domains[$idx % count($domains)];
            $phone = sprintf('+1%03d%03d%04d', ($idx % 800) + 200, ($idx * 3) % 1000, $idx % 10000);
            $status = $statuses[$idx % 3];
            $notes = $notesPool[$idx % count($notesPool)];
            // Spread created_at over ~180 days
            $created = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('-' . ($idx % 180) . ' days')
                ->modify('-' . ($idx % 86400) . ' seconds')
                ->format('Y-m-d H:i:s');

            $base = $j * 6;
            $values[] = '(?,?,?,?,?,?)';
            array_push($params, $name, $email, $phone, $status, $notes, $created);
        }

        $stmt = $pdo->prepare($sqlPrefix . implode(',', $values));
        $stmt->execute($params);
        $inserted += $n;

        if ($inserted % 10000 === 0 || $inserted === $need) {
            echo "  {$inserted}/{$need}\n";
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, 'Seed failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$elapsed = round(microtime(true) - $started, 2);
$total = (int) $pdo->query('SELECT COUNT(*) FROM inquiries')->fetchColumn();
echo "Done. Inserted {$inserted} in {$elapsed}s. Table total: {$total}\n";

// Warm / show index usage hint
echo "\nIndex rationale:\n";
echo "  idx_inquiries_status_created — status filter + created_at sort (common list query)\n";
echo "  idx_inquiries_created        — sort-only lists without status filter\n";
echo "  idx_inquiries_email / name   — exact/prefix lookups\n";
echo "  ft_inquiries_search          — keyword search via MATCH…AGAINST (avoids leading % LIKE)\n";