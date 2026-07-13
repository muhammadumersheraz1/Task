<?php
/**
 * Task 3 — Add inquiry (server-side validation).
 */
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

$errors = [];
$old = [
    'customer_name' => '',
    'email' => '',
    'phone' => '',
    'status' => 'new',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    if ($old['customer_name'] === '') {
        $errors['customer_name'] = 'Customer name is required.';
    } elseif (mb_strlen($old['customer_name']) > 150) {
        $errors['customer_name'] = 'Customer name must be 150 characters or fewer.';
    }

    if ($old['email'] === '') {
        $errors['email'] = 'Email is required.';
    } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    }

    if ($old['phone'] !== '' && mb_strlen($old['phone']) > 50) {
        $errors['phone'] = 'Phone must be 50 characters or fewer.';
    }

    if (!in_array($old['status'], statuses(), true)) {
        $errors['status'] = 'Invalid status.';
    }

    if (!$errors) {
        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO inquiries (customer_name, email, phone, status, notes)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $old['customer_name'],
            $old['email'],
            $old['phone'] !== '' ? $old['phone'] : null,
            $old['status'],
            $old['notes'] !== '' ? $old['notes'] : null,
        ]);
        header('Location: list.php?created=1');
        exit;
    }
}

render_header('Add Inquiry');
?>
<h1>Add Inquiry</h1>
<?php if ($errors): ?>
  <div class="errors">
    <ul>
      <?php foreach ($errors as $msg): ?>
        <li><?= h($msg) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<form method="post" class="card-form" novalidate>
  <label for="customer_name">Customer name *</label>
  <input id="customer_name" name="customer_name" required maxlength="150" value="<?= h($old['customer_name']) ?>">

  <label for="email">Email *</label>
  <input id="email" type="email" name="email" required maxlength="255" value="<?= h($old['email']) ?>">

  <label for="phone">Phone</label>
  <input id="phone" name="phone" maxlength="50" value="<?= h($old['phone']) ?>">

  <label for="status">Status</label>
  <select id="status" name="status">
    <?php foreach (statuses() as $s): ?>
      <option value="<?= h($s) ?>" <?= $old['status'] === $s ? 'selected' : '' ?>><?= h($s) ?></option>
    <?php endforeach; ?>
  </select>

  <label for="notes">Notes</label>
  <textarea id="notes" name="notes"><?= h($old['notes']) ?></textarea>

  <p style="margin-top:1rem">
    <button type="submit">Save inquiry</button>
    <a class="btn secondary" href="list.php" style="margin-left:.5rem;padding:.45rem .7rem">Cancel</a>
  </p>
</form>
<?php render_footer(); ?>