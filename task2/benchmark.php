<?php
/**
 * Quick benchmark for GET /inquiries against a large table.
 * Run with the API server up, or call DB directly:
 *
 *   php task2/benchmark.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$pdo = Database::pdo();
$total = (int) $pdo->query('SELECT COUNT(*) FROM inquiries')->fetchColumn();
echo "Rows: {$total}\n\n";

$queries = [
    'filter+sort' => [
        'sql' => "SELECT id, customer_name, email, phone, status, notes, created_at
                  FROM inquiries WHERE status = 'new'
                  ORDER BY created_at DESC LIMIT 20 OFFSET 0",
        'params' => [],
    ],
    'fulltext search' => [
        'sql' => "SELECT id, customer_name, email, status, created_at
                  FROM inquiries
                  WHERE MATCH(customer_name, email, notes) AGAINST ('+Alex* +Smith*' IN BOOLEAN MODE)
                  ORDER BY created_at DESC LIMIT 20",
        'params' => [],
    ],
    'count filtered' => [
        'sql' => "SELECT COUNT(*) FROM inquiries WHERE status = 'closed'",
        'params' => [],
    ],
];

foreach ($queries as $label => $q) {
    $t0 = microtime(true);
    $stmt = $pdo->prepare($q['sql']);
    $stmt->execute($q['params']);
    $stmt->fetchAll();
    $ms = round((microtime(true) - $t0) * 1000, 2);
    echo str_pad($label, 20) . " {$ms} ms\n";

    // MySQL 8.0.16+ EXPLAIN may return tree format in 'EXPLAIN' column
    $explain = $pdo->prepare('EXPLAIN FORMAT=TRADITIONAL ' . $q['sql']);
    $explain->execute($q['params']);
    $plan = $explain->fetch();
    $key = $plan['key'] ?? $plan['possible_keys'] ?? 'n/a';
    $rows = $plan['rows'] ?? '?';
    echo "  key={$key} rows≈{$rows}\n";
}