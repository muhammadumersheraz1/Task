<?php
/**
 * Task 2 — JSON REST API front controller (vanilla PHP).
 *
 * Built-in server:
 *   php -S localhost:8090 -t task2/public task2/public/index.php
 *
 * Endpoints:
 *   GET    /inquiries
 *   POST   /inquiries
 *   PATCH  /inquiries/{id}
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$uri = rtrim($uri, '/') ?: '/';

try {
    if ($method === 'GET' && $uri === '/inquiries') {
        handleList(Database::pdo());
        exit;
    }

    if ($method === 'POST' && $uri === '/inquiries') {
        handleCreate(Database::pdo());
        exit;
    }

    if ($method === 'PATCH' && preg_match('#^/inquiries/(\d+)$#', $uri, $m)) {
        handlePatch(Database::pdo(), (int) $m[1]);
        exit;
    }

    if ($method === 'GET' && $uri === '/') {
        jsonResponse(200, [
            'name' => 'Inquiries API',
            'endpoints' => [
                'GET /inquiries' => 'search, status, page, per_page, sort, order',
                'POST /inquiries' => 'create',
                'PATCH /inquiries/{id}' => 'partial update',
            ],
        ]);
        exit;
    }

    jsonResponse(404, ['error' => 'Not found', 'path' => $uri]);
} catch (InvalidArgumentException $e) {
    jsonResponse(400, ['error' => $e->getMessage()]);
} catch (Throwable $e) {
    jsonResponse(500, ['error' => 'Server error', 'detail' => $e->getMessage()]);
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || trim($raw) === '') {
        throw new InvalidArgumentException('Request body is required');
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new InvalidArgumentException('Body must be valid JSON object');
    }
    return $data;
}

function handleList(PDO $pdo): void
{
    $search = trim((string) ($_GET['search'] ?? $_GET['q'] ?? ''));
    $status = trim((string) ($_GET['status'] ?? ''));
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = min(100, max(1, (int) ($_GET['per_page'] ?? 20)));
    $sort = strtolower((string) ($_GET['sort'] ?? 'created_at'));
    $order = strtolower((string) ($_GET['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    $allowedSort = [
        'id' => 'id',
        'customer_name' => 'customer_name',
        'email' => 'email',
        'status' => 'status',
        'created_at' => 'created_at',
    ];
    if (!isset($allowedSort[$sort])) {
        jsonResponse(400, ['error' => 'Invalid sort field', 'allowed' => array_keys($allowedSort)]);
        return;
    }
    $sortCol = $allowedSort[$sort];

    $allowedStatus = ['new', 'in-progress', 'closed'];
    if ($status !== '' && !in_array($status, $allowedStatus, true)) {
        jsonResponse(400, ['error' => 'Invalid status', 'allowed' => $allowedStatus]);
        return;
    }

    $where = [];
    $params = [];

    if ($status !== '') {
        $where[] = 'status = :status';
        $params[':status'] = $status;
    }

    if ($search !== '') {
        // Prefer FULLTEXT when the query is usable; else prefix/contains LIKE on indexed cols.
        if (isFulltextUsable($search)) {
            $where[] = 'MATCH(customer_name, email, notes) AGAINST (:ft IN BOOLEAN MODE)';
            $params[':ft'] = fulltextBooleanQuery($search);
        } else {
            $where[] = '(customer_name LIKE :q OR email LIKE :q OR notes LIKE :q)';
            $params[':q'] = '%' . $search . '%';
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
    $offset = ($page - 1) * $perPage;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM inquiries {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    // Sorting + LIMIT/OFFSET entirely in SQL
    $sql = "SELECT id, customer_name, email, phone, status, notes, created_at
            FROM inquiries
            {$whereSql}
            ORDER BY {$sortCol} {$order}
            LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    jsonResponse(200, [
        'data' => $data,
        'meta' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'sort' => $sort,
            'order' => strtolower($order),
            'search' => $search,
            'status' => $status !== '' ? $status : null,
        ],
    ]);
}

function isFulltextUsable(string $search): bool
{
    // InnoDB FULLTEXT default ft_min_token_size is 3
    $tokens = preg_split('/\s+/', trim($search)) ?: [];
    foreach ($tokens as $t) {
        if (mb_strlen(preg_replace('/[^\p{L}\p{N}]+/u', '', $t) ?? '') >= 3) {
            return true;
        }
    }
    return false;
}

function fulltextBooleanQuery(string $search): string
{
    $parts = [];
    foreach (preg_split('/\s+/', trim($search)) ?: [] as $token) {
        $clean = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $token) ?? '';
        if (mb_strlen($clean) < 3) {
            continue;
        }
        $parts[] = '+' . $clean . '*';
    }
    return implode(' ', $parts);
}

function handleCreate(PDO $pdo): void
{
    $data = readJsonBody();
    $errors = validateInquiry($data, false);
    if ($errors) {
        jsonResponse(422, ['error' => 'Validation failed', 'details' => $errors]);
        return;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO inquiries (customer_name, email, phone, status, notes)
         VALUES (:customer_name, :email, :phone, :status, :notes)'
    );
    $stmt->execute([
        ':customer_name' => trim((string) $data['customer_name']),
        ':email'         => trim((string) $data['email']),
        ':phone'         => isset($data['phone']) ? trim((string) $data['phone']) : null,
        ':status'        => $data['status'] ?? 'new',
        ':notes'         => isset($data['notes']) ? trim((string) $data['notes']) : null,
    ]);

    $id = (int) $pdo->lastInsertId();
    $row = $pdo->query('SELECT id, customer_name, email, phone, status, notes, created_at FROM inquiries WHERE id = ' . $id)->fetch();
    jsonResponse(201, ['data' => $row]);
}

function handlePatch(PDO $pdo, int $id): void
{
    $check = $pdo->prepare('SELECT id FROM inquiries WHERE id = ?');
    $check->execute([$id]);
    if (!$check->fetch()) {
        jsonResponse(404, ['error' => 'Inquiry not found', 'id' => $id]);
        return;
    }

    $data = readJsonBody();
    if ($data === []) {
        throw new InvalidArgumentException('No fields to update');
    }

    $errors = validateInquiry($data, true);
    if ($errors) {
        jsonResponse(422, ['error' => 'Validation failed', 'details' => $errors]);
        return;
    }

    $map = [
        'customer_name' => 'customer_name',
        'email' => 'email',
        'phone' => 'phone',
        'status' => 'status',
        'notes' => 'notes',
    ];
    $sets = [];
    $params = [':id' => $id];
    foreach ($map as $key => $col) {
        if (array_key_exists($key, $data)) {
            $sets[] = "{$col} = :{$col}";
            $val = $data[$key];
            $params[":{$col}"] = is_string($val) ? trim($val) : $val;
        }
    }
    if (!$sets) {
        throw new InvalidArgumentException('No updatable fields provided');
    }

    $sql = 'UPDATE inquiries SET ' . implode(', ', $sets) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);

    $row = $pdo->prepare('SELECT id, customer_name, email, phone, status, notes, created_at FROM inquiries WHERE id = ?');
    $row->execute([$id]);
    jsonResponse(200, ['data' => $row->fetch()]);
}

/**
 * @param array<string,mixed> $data
 * @return array<string,string>
 */
function validateInquiry(array $data, bool $partial): array
{
    $errors = [];
    $statuses = ['new', 'in-progress', 'closed'];

    if (!$partial || array_key_exists('customer_name', $data)) {
        $name = trim((string) ($data['customer_name'] ?? ''));
        if ($name === '') {
            $errors['customer_name'] = 'customer_name is required';
        } elseif (mb_strlen($name) > 150) {
            $errors['customer_name'] = 'customer_name must be <= 150 characters';
        }
    }

    if (!$partial || array_key_exists('email', $data)) {
        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'email is required';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'email is invalid';
        } elseif (mb_strlen($email) > 255) {
            $errors['email'] = 'email must be <= 255 characters';
        }
    }

    if (array_key_exists('status', $data)) {
        if (!in_array($data['status'], $statuses, true)) {
            $errors['status'] = 'status must be one of: ' . implode(', ', $statuses);
        }
    } elseif (!$partial) {
        // default ok
    }

    if (array_key_exists('phone', $data) && $data['phone'] !== null) {
        if (mb_strlen((string) $data['phone']) > 50) {
            $errors['phone'] = 'phone must be <= 50 characters';
        }
    }

    return $errors;
}