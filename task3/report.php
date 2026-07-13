<?php
/**
 * Task 3 — Simple report: count of inquiries grouped by status.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$pdo = Database::pdo();
$rows = $pdo->query(
    "SELECT status, COUNT(*) AS total
     FROM inquiries
     GROUP BY status
     ORDER BY FIELD(status, 'new', 'in-progress', 'closed')"
)->fetchAll();

$byStatus = array_column($rows, 'total', 'status');
$grand = array_sum(array_map('intval', $byStatus));

render_header('Report');
?>
<h1>Inquiries by status</h1>
<p class="muted">Total inquiries: <?= (int) $grand ?></p>
<div class="report-grid">
  <?php foreach (statuses() as $s): ?>
    <div class="stat">
      <div class="muted"><?= h($s) ?></div>
      <div class="n"><?= (int) ($byStatus[$s] ?? 0) ?></div>
    </div>
  <?php endforeach; ?>
</div>
<table style="margin-top:1.5rem">
  <thead><tr><th>Status</th><th>Count</th><th>%</th></tr></thead>
  <tbody>
  <?php foreach (statuses() as $s):
      $n = (int) ($byStatus[$s] ?? 0);
      $pct = $grand > 0 ? round($n / $grand * 100, 1) : 0;
  ?>
    <tr>
      <td><?= h($s) ?></td>
      <td><?= $n ?></td>
      <td><?= $pct ?>%</td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
<?php render_footer(); ?>