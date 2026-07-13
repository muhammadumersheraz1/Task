<?php
/**
 * Task 1 — List stored emails with search by sender or subject.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$q = trim((string) ($_GET['q'] ?? ''));
$pdo = Database::pdo();

$sql = 'SELECT id, message_id, from_address, subject, email_date, body_preview, attachments, fetched_at
        FROM emails';
$params = [];
if ($q !== '') {
    $sql .= ' WHERE from_address LIKE :q OR subject LIKE :q';
    $params[':q'] = '%' . $q . '%';
}
$sql .= ' ORDER BY COALESCE(email_date, fetched_at) DESC LIMIT 100';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Stored Emails</title>
  <style>
    body { font-family: ui-sans-serif, system-ui, sans-serif; margin: 2rem; color: #1a1a1a; }
    form { margin-bottom: 1rem; }
    input[type=search] { width: min(420px, 100%); padding: .4rem .6rem; }
    table { border-collapse: collapse; width: 100%; font-size: 14px; }
    th, td { border: 1px solid #ddd; padding: .5rem .6rem; vertical-align: top; text-align: left; }
    th { background: #f5f5f5; }
    .preview { color: #555; max-width: 360px; }
    .muted { color: #777; font-size: 13px; }
  </style>
</head>
<body>
  <h1>Stored Emails</h1>
  <p class="muted">Search by sender or subject. Duplicates are skipped on fetch via unique <code>message_id</code>.</p>
  <form method="get">
    <input type="search" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>" placeholder="sender or subject…">
    <button type="submit">Search</button>
    <?php if ($q !== ''): ?><a href="?">Clear</a><?php endif; ?>
  </form>
  <p class="muted"><?= count($rows) ?> result(s)</p>
  <table>
    <thead>
      <tr>
        <th>Date</th>
        <th>From</th>
        <th>Subject</th>
        <th>Preview</th>
        <th>Attachments</th>
      </tr>
    </thead>
    <tbody>
    <?php if (!$rows): ?>
      <tr><td colspan="5">No emails stored yet. Run <code>php task1/fetch_emails.php</code>.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $row):
        $atts = json_decode((string) $row['attachments'], true) ?: [];
    ?>
      <tr>
        <td><?= htmlspecialchars((string) ($row['email_date'] ?? $row['fetched_at']), ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($row['from_address'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= htmlspecialchars($row['subject'], ENT_QUOTES, 'UTF-8') ?></td>
        <td class="preview"><?= htmlspecialchars((string) $row['body_preview'], ENT_QUOTES, 'UTF-8') ?></td>
        <td><?= $atts ? htmlspecialchars(implode(', ', $atts), ENT_QUOTES, 'UTF-8') : '—' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</body>
</html>