<?php
/**
 * Task 3 — AJAX endpoint: update inquiry status (JSON in/out).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string) $raw, true);
if (!is_array($data)) {
    // Also accept form-encoded fallback
    $data = $_POST;
}

$id = (int) ($data['id'] ?? 0);
$status = trim((string) ($data['status'] ?? ''));
$allowed = ['new', 'in-progress', 'closed'];

if ($id < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid id']);
    exit;
}
if (!in_array($status, $allowed, true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid status', 'allowed' => $allowed]);
    exit;
}

$pdo = Database::pdo();
$check = $pdo->prepare('SELECT id FROM inquiries WHERE id = ?');
$check->execute([$id]);
if (!$check->fetch()) {
    http_response_code(404);
    echo json_encode(['error' => 'Inquiry not found']);
    exit;
}

$upd = $pdo->prepare('UPDATE inquiries SET status = ? WHERE id = ?');
$upd->execute([$status, $id]);

echo json_encode(['ok' => true, 'id' => $id, 'status' => $status]);