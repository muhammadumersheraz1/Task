<?php
/**
 * Task 3 — List inquiries with search + status filter.
 * Status changes via AJAX (update_status.php) — no full page reload.
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$q = trim((string) ($_GET['q'] ?? ''));
$status = trim((string) ($_GET['status'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$where = [];
$params = [];
if ($q !== '') {
    $where[] = '(customer_name LIKE :q OR email LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if ($status !== '' && in_array($status, statuses(), true)) {
    $where[] = 'status = :status';
    $params[':status'] = $status;
} else {
    $status = '';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$pdo = Database::pdo();
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM inquiries {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$lastPage = max(1, (int) ceil($total / $perPage));
$page = min($page, $lastPage);
$offset = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT id, customer_name, email, phone, status, notes, created_at
     FROM inquiries {$whereSql}
     ORDER BY created_at DESC
     LIMIT {$perPage} OFFSET {$offset}"
);
$stmt->execute($params);
$rows = $stmt->fetchAll();

render_header('Inquiries');
?>
<h1>Inquiries</h1>
<?php if (!empty($_GET['created'])): ?>
  <div class="flash ok">Inquiry created.</div>
<?php endif; ?>

<form class="filters" method="get">
  <div>
    <label for="q">Search (name / email)</label>
    <input id="q" type="search" name="q" value="<?= h($q) ?>">
  </div>
  <div>
    <label for="status">Status</label>
    <select id="status" name="status">
      <option value="">All</option>
      <?php foreach (statuses() as $s): ?>
        <option value="<?= h($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= h($s) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div>
    <button type="submit">Filter</button>
  </div>
</form>

<p class="muted"><?= (int) $total ?> result(s) — page <?= (int) $page ?> / <?= (int) $lastPage ?></p>

<table>
  <thead>
    <tr>
      <th>ID</th>
      <th>Customer</th>
      <th>Email</th>
      <th>Phone</th>
      <th>Status</th>
      <th>Notes</th>
      <th>Created</th>
    </tr>
  </thead>
  <tbody>
  <?php if (!$rows): ?>
    <tr><td colspan="7">No inquiries match.</td></tr>
  <?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <tr data-id="<?= (int) $row['id'] ?>">
      <td><?= (int) $row['id'] ?></td>
      <td><?= h($row['customer_name']) ?></td>
      <td><?= h($row['email']) ?></td>
      <td><?= h((string) $row['phone']) ?></td>
      <td>
        <select class="status-select" data-id="<?= (int) $row['id'] ?>">
          <?php foreach (statuses() as $s): ?>
            <option value="<?= h($s) ?>" <?= $row['status'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
          <?php endforeach; ?>
        </select>
        <span class="status-msg muted"></span>
      </td>
      <td><?= h((string) $row['notes']) ?></td>
      <td><?= h($row['created_at']) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>

<p class="muted" style="margin-top:1rem">
  <?php if ($page > 1): ?>
    <a href="?<?= h(http_build_query(['q' => $q, 'status' => $status, 'page' => $page - 1])) ?>">← Prev</a>
  <?php endif; ?>
  <?php if ($page < $lastPage): ?>
    <a href="?<?= h(http_build_query(['q' => $q, 'status' => $status, 'page' => $page + 1])) ?>">Next →</a>
  <?php endif; ?>
</p>

<?php
$js = <<<'JS'
document.querySelectorAll('.status-select').forEach(function (el) {
  el.addEventListener('change', async function () {
    var id = el.getAttribute('data-id');
    var msg = el.parentElement.querySelector('.status-msg');
    el.classList.add('saving');
    msg.textContent = 'Saving…';
    try {
      var res = await fetch('update_status.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
        body: JSON.stringify({ id: Number(id), status: el.value })
      });
      var data = await res.json();
      if (!res.ok) {
        msg.textContent = data.error || 'Error';
        return;
      }
      msg.textContent = 'Saved';
      setTimeout(function () { msg.textContent = ''; }, 1500);
    } catch (e) {
      msg.textContent = 'Network error';
    } finally {
      el.classList.remove('saving');
    }
  });
});
JS;
render_footer($js);