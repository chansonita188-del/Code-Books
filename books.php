<?php
// ============================================================
//  Book Manager — Single File  (mysqli version)
//  DB : 127.0.0.1:3306 | database: books | table: books
//  Columns: id, title, isbn, category, page_number, unit_price
// ============================================================
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();

// ── DB Config ────────────────────────────────────────────────
define('DB_HOST', '127.0.0.1');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'books');
define('DB_PORT', 3306);
define('CATEGORIES', ['IT', 'Programming', 'Database', 'Web', 'Other']);

// ── mysqli Connection ─────────────────────────────────────────
function db(): mysqli {
    static $conn = null;
    if ($conn === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $conn->set_charset('utf8mb4');
    }
    return $conn;
}

// ── Helpers ──────────────────────────────────────────────────
function e(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function flash(string $type, string $msg): void {
    $_SESSION['flash'] = compact('type', 'msg');
}
function getFlash(): ?array {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}
function buildQuery(array $ov = []): string {
    $base = [
        'search' => $_GET['search'] ?? '',
        'per'    => $_GET['per']    ?? 10,
        'page'   => $_GET['page']   ?? 1,
    ];
    return http_build_query(array_filter(array_merge($base, $ov),
                            fn($v) => $v !== '' && $v !== null));
}
function categoryBadge(string $cat): string {
    $map = ['IT'=>'badge-it','Programming'=>'badge-prog',
            'Database'=>'badge-db','Web'=>'badge-web','Other'=>'badge-other'];
    return '<span class="badge '.($map[$cat]??'badge-other').'">'.e($cat).'</span>';
}
function paginate(int $cur, int $total, array $q = []): string {
    if ($total <= 1) return '';
    $btn = function(int $p, string $lbl, bool $dis=false, bool $act=false) use ($q): string {
        $cls = 'page-btn'.($dis?' disabled':'').($act?' active':'');
        if ($dis) return "<span class=\"$cls\">$lbl</span>";
        return '<a class="'.$cls.'" href="?'.http_build_query(array_merge($q,['page'=>$p])).'">'.$lbl.'</a>';
    };
    $out = '<div class="pagination">';
    $out .= $btn(1,'«',$cur<=1).$btn($cur-1,'‹',$cur<=1);
    for ($p = max(1,$cur-2); $p <= min($total,$cur+2); $p++)
        $out .= $btn($p,(string)$p,false,$p===$cur);
    $out .= $btn($cur+1,'›',$cur>=$total).$btn($total,'»',$cur>=$total);
    return $out.'</div>';
}

// ── Validation ───────────────────────────────────────────────
function validateBook(array $post): array {
    $d = array_map(fn($v) => is_string($v) ? trim($v) : $v, $post);
    $e = [];
    if (empty($d['title']))                                                    $e['title']       = 'Title is required.';
    elseif (strlen($d['title']) > 255)                                         $e['title']       = 'Title max 255 chars.';
    if (empty($d['isbn']))                                                     $e['isbn']        = 'ISBN is required.';
    if (empty($d['category']) || !in_array($d['category'], CATEGORIES, true)) $e['category']    = 'Please select a valid category.';
    if (!ctype_digit((string)($d['page_number']??'')) || (int)$d['page_number'] < 1)
                                                                               $e['page_number'] = 'Page number must be a positive integer.';
    if (!is_numeric($d['unit_price']??'') || (float)$d['unit_price'] <= 0)    $e['unit_price']  = 'Unit price must be a positive number.';
    return [$d, $e];
}

// ── DB Queries ───────────────────────────────────────────────
function getBooks(int $page, int $per, string $search): array {
    $off  = ($page - 1) * $per;
    $conn = db();
    if ($search !== '') {
        $like = '%' . $search . '%';
        $st   = $conn->prepare(
            "SELECT * FROM books
             WHERE title LIKE ? OR isbn LIKE ? OR category LIKE ?
             ORDER BY id ASC LIMIT ? OFFSET ?"
        );
        $st->bind_param('sssii', $like, $like, $like, $per, $off);
    } else {
        $st = $conn->prepare("SELECT * FROM books ORDER BY id ASC LIMIT ? OFFSET ?");
        $st->bind_param('ii', $per, $off);
    }
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}

function countBooks(string $search): int {
    $conn = db();
    if ($search !== '') {
        $like = '%' . $search . '%';
        $st   = $conn->prepare(
            "SELECT COUNT(*) FROM books WHERE title LIKE ? OR isbn LIKE ? OR category LIKE ?"
        );
        $st->bind_param('sss', $like, $like, $like);
        $st->execute();
        return (int)$st->get_result()->fetch_row()[0];
    }
    return (int)$conn->query("SELECT COUNT(*) FROM books")->fetch_row()[0];
}

function findBook(int $id): ?array {
    $st = db()->prepare("SELECT * FROM books WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}

function isbnExists(string $isbn, int $excludeId = 0): bool {
    $st = db()->prepare("SELECT COUNT(*) FROM books WHERE isbn = ? AND id != ?");
    $st->bind_param('si', $isbn, $excludeId);
    $st->execute();
    return (int)$st->get_result()->fetch_row()[0] > 0;
}

function createBook(array $d): void {
    $st = db()->prepare(
        "INSERT INTO books (title, isbn, category, page_number, unit_price)
         VALUES (?, ?, ?, ?, ?)"
    );
    $pages = (int)$d['page_number'];
    $price = (float)$d['unit_price'];
    $st->bind_param('sssid', $d['title'], $d['isbn'], $d['category'], $pages, $price);
    $st->execute();
}

function updateBook(int $id, array $d): void {
    $st = db()->prepare(
        "UPDATE books
         SET title=?, isbn=?, category=?, page_number=?, unit_price=?
         WHERE id=?"
    );
    $pages = (int)$d['page_number'];
    $price = (float)$d['unit_price'];
    $st->bind_param('sssidi', $d['title'], $d['isbn'], $d['category'], $pages, $price, $id);
    $st->execute();
}

function deleteBook(int $id): void {
    $st = db()->prepare("DELETE FROM books WHERE id = ?");
    $st->bind_param('i', $id);
    $st->execute();
}

// ── Router ───────────────────────────────────────────────────
$action = $_GET['action'] ?? 'index';
$method = $_SERVER['REQUEST_METHOD'];
$book   = null;
$old    = [];
$errors = [];
$flash  = getFlash();

// DELETE
if ($action === 'delete' && $method === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id) { deleteBook($id); flash('success', 'Book deleted successfully.'); }
    redirect('?' . buildQuery());
}

// STORE (insert)
if ($action === 'store' && $method === 'POST') {
    [$old, $errors] = validateBook($_POST);
    if (empty($errors) && isbnExists($old['isbn']))
        $errors['isbn'] = 'This ISBN already exists.';
    if (empty($errors)) {
        createBook($old);
        flash('success', 'Book added successfully.');
        redirect('?' . buildQuery(['page' => 1]));
    }
    $action = 'create';
}

// EDIT — load record
if ($action === 'edit') {
    $book = findBook((int)($_GET['id'] ?? 0));
    if (!$book) { flash('error', 'Book not found.'); redirect('?' . buildQuery()); }
}

// UPDATE
if ($action === 'update' && $method === 'POST') {
    $id   = (int)($_POST['id'] ?? 0);
    $book = findBook($id);
    if (!$book) { flash('error', 'Book not found.'); redirect('?' . buildQuery()); }
    [$old, $errors] = validateBook($_POST);
    if (empty($errors) && isbnExists($old['isbn'], $id))
        $errors['isbn'] = 'This ISBN already exists.';
    if (empty($errors)) {
        updateBook($id, $old);
        flash('success', 'Book updated successfully.');
        redirect('?' . buildQuery());
    }
    $action = 'edit';
}

// LIST vars
$search  = trim($_GET['search'] ?? '');
$perPage = max(1, min(100, (int)($_GET['per'] ?? 10)));
$total   = countBooks($search);
$maxPage = max(1, (int)ceil($total / $perPage));
$page    = max(1, min($maxPage, (int)($_GET['page'] ?? 1)));
$books   = in_array($action, ['index', 'delete']) ? getBooks($page, $perPage, $search) : [];

$showForm = in_array($action, ['create', 'edit']);
$isEdit   = $action === 'edit';
$val      = fn(string $f, mixed $def = '') => e($old[$f] ?? ($book[$f] ?? $def));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Book Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400&display=swap" rel="stylesheet">
  <style>
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    :root{
      --navy:#1e2433;--navy2:#253045;
      --blue:#4d7cfe;--blue-lt:#eef2ff;
      --green:#22c55e;--red:#ef4444;--red-lt:#fee2e2;
      --orange:#f59e0b;--orange-lt:#fef3c7;
      --bg:#f0f2f5;--white:#fff;--border:#e5e8ef;
      --text:#1a2337;--muted:#7c8fa6;
      --radius:10px;--radius-sm:6px;
      --shadow:0 2px 16px rgba(26,35,55,.09);
    }
    html{font-size:14px}
    body{font-family:'Inter',system-ui,sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.5;-webkit-font-smoothing:antialiased}
    a{text-decoration:none;color:inherit}
    button,input,select,textarea{font-family:inherit}

    /* Navbar */
    .navbar{background:var(--navy);height:52px;display:flex;align-items:center;padding:0 24px;position:sticky;top:0;z-index:200;box-shadow:0 2px 8px rgba(0,0,0,.25)}
    .navbar-brand{display:flex;align-items:center;gap:8px}
    .brand-logo{width:28px;height:28px;border-radius:7px;background:linear-gradient(135deg,#4d7cfe,#7c3aed);display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(77,124,254,.45)}
    .brand-text{font-size:.95rem;font-weight:700;color:#fff}
    .brand-light{font-weight:300;color:#8a9bb5}
    .nav-links{display:flex;margin-left:16px}
    .nav-link{color:#8a9bb5;font-size:.82rem;font-weight:500;padding:5px 12px;border-radius:5px;transition:background .15s,color .15s}
    .nav-link:hover,.nav-link.active{background:var(--navy2);color:#fff}

    /* Layout */
    .page{max-width:1200px;margin:30px auto;padding:0 20px}
    .card{background:var(--white);border-radius:var(--radius);box-shadow:var(--shadow);padding:24px 26px}

    /* Alert */
    .alert{display:flex;align-items:center;gap:8px;padding:10px 16px;border-radius:var(--radius-sm);font-size:.84rem;font-weight:500;margin-bottom:16px;animation:alertIn .22s ease}
    @keyframes alertIn{from{opacity:0;transform:translateY(-6px)}}
    .alert-success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}
    .alert-error{background:var(--red-lt);color:#b91c1c;border:1px solid #fecaca}

    /* Page header */
    .page-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px}
    .page-title{font-size:1.1rem;font-weight:700;display:flex;align-items:center;gap:10px}
    .page-title::before{content:'';width:4px;height:20px;background:var(--blue);border-radius:3px;flex-shrink:0}

    /* Buttons */
    .btn{display:inline-flex;align-items:center;gap:5px;padding:8px 18px;border:none;border-radius:var(--radius-sm);font-size:.82rem;font-weight:600;cursor:pointer;transition:filter .15s,transform .1s;white-space:nowrap;line-height:1.4}
    .btn:active{transform:scale(.97)}
    .btn-primary{background:var(--blue);color:#fff;box-shadow:0 2px 8px rgba(77,124,254,.30)}
    .btn-primary:hover{filter:brightness(1.08)}
    .btn-success{background:var(--green);color:#fff}
    .btn-success:hover{filter:brightness(.92)}
    .btn-secondary{background:#e9ecf2;color:var(--text)}
    .btn-secondary:hover{background:#dde1ea}
    .btn-danger{background:var(--red);color:#fff}
    .btn-danger:hover{filter:brightness(.92)}
    .btn-sm{padding:5px 10px;font-size:.75rem;border-radius:5px}
    .btn-edit{background:#e8f0fe;color:#2563eb;border:1px solid #c7d8fc}
    .btn-edit:hover{background:#d1e2fd}
    .btn-copy{background:var(--orange-lt);color:#b45309;border:1px solid #fde68a}
    .btn-copy:hover{background:#fde68a}
    .btn-del{background:var(--red-lt);color:#dc2626;border:1px solid #fecaca}
    .btn-del:hover{background:#fecaca}

    /* Toolbar */
    .toolbar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;flex-wrap:wrap}
    .toolbar-left{display:flex;align-items:center;gap:7px;font-size:.82rem;color:var(--muted)}
    .toolbar-right{display:flex;align-items:center;gap:8px;font-size:.82rem;color:var(--muted)}
    .select-sm{padding:5px 8px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:.82rem;color:var(--text);background:#f7f8fb;cursor:pointer;outline:none}
    .select-sm:focus{border-color:var(--blue)}
    .search-box{display:flex;align-items:center;gap:6px;border:1px solid var(--border);border-radius:var(--radius-sm);padding:6px 10px;background:#f7f8fb;transition:border-color .15s,background .15s}
    .search-box:focus-within{border-color:var(--blue);background:#fff}
    .search-icon{color:#9aaabb;flex-shrink:0}
    .search-input{border:none;background:transparent;outline:none;font-size:.82rem;color:var(--text);width:195px}
    .search-input::placeholder{color:#9aaabb}

    /* Table */
    .table-wrap{overflow-x:auto}
    table{width:100%;border-collapse:collapse;font-size:.84rem}
    thead tr{background:var(--navy)}
    thead th{padding:11px 14px;text-align:left;font-size:.75rem;font-weight:600;color:#d1d8e8;letter-spacing:.05em;text-transform:uppercase;white-space:nowrap}
    thead th:first-child{border-radius:7px 0 0 7px}
    thead th:last-child{border-radius:0 7px 7px 0}
    tbody tr{border-bottom:1px solid var(--border);transition:background .1s}
    tbody tr:last-child{border-bottom:none}
    tbody tr:hover{background:#f5f8ff}
    tbody td{padding:10px 14px;vertical-align:middle}
    .td-id{color:var(--muted);font-size:.8rem;width:44px}
    .td-isbn{font-family:'JetBrains Mono',monospace;font-size:.78rem;color:#5a6d85}
    .td-price{font-weight:600}
    .td-act{white-space:nowrap}
    .act-btns{display:flex;gap:5px;align-items:center}

    /* Badges */
    .badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:.74rem;font-weight:600;white-space:nowrap}
    .badge-it{background:#dbeafe;color:#1d4ed8}
    .badge-prog{background:#d1fae5;color:#065f46}
    .badge-db{background:#fef9c3;color:#92400e}
    .badge-web{background:#ede9fe;color:#5b21b6}
    .badge-other{background:#f3f4f6;color:#374151}

    /* Empty state */
    .empty-state{text-align:center;padding:48px 24px;color:var(--muted)}
    .empty-state svg{margin:0 auto 12px;opacity:.35}
    .empty-state p{font-size:.88rem}

    /* Pagination */
    .pagination-row{display:flex;align-items:center;justify-content:space-between;margin-top:18px;flex-wrap:wrap;gap:10px}
    .pagination-info{font-size:.79rem;color:var(--muted)}
    .pagination{display:flex;gap:3px}
    .page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:33px;height:33px;padding:0 6px;border:1px solid var(--border);border-radius:var(--radius-sm);font-size:.8rem;color:var(--text);transition:all .14s;font-weight:500}
    a.page-btn:hover{background:var(--bg);border-color:var(--blue);color:var(--blue)}
    .page-btn.active{background:var(--blue);color:#fff;border-color:var(--blue);font-weight:700}
    .page-btn.disabled{color:#cdd3dd;pointer-events:none;background:none}

    /* Modal */
    .modal-backdrop{position:fixed;inset:0;background:rgba(10,15,30,.45);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;z-index:500;animation:fadeIn .18s ease}
    @keyframes fadeIn{from{opacity:0}}
    .modal{background:var(--white);border-radius:var(--radius);width:100%;max-width:480px;box-shadow:0 20px 60px rgba(0,0,0,.22);animation:slideUp .22s cubic-bezier(.22,1,.36,1)}
    @keyframes slideUp{from{transform:translateY(20px);opacity:0}}
    .modal-header{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border)}
    .modal-header h2{font-size:.95rem;font-weight:700}
    .modal-close{width:28px;height:28px;border:none;background:var(--bg);border-radius:50%;cursor:pointer;font-size:1.1rem;color:var(--muted);display:flex;align-items:center;justify-content:center;transition:all .15s}
    .modal-close:hover{background:var(--border);color:var(--text)}
    .modal-body{padding:20px}
    .modal-footer{padding:14px 20px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px}

    /* Form */
    .form-group{margin-bottom:15px}
    .form-label{display:block;font-size:.78rem;font-weight:600;color:#3d4f68;margin-bottom:5px}
    .form-label .req{color:var(--red)}
    .form-control{width:100%;padding:8px 11px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:.85rem;color:var(--text);background:#fff;outline:none;transition:border-color .18s,box-shadow .18s}
    .form-control:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(77,124,254,.12)}
    .form-control.is-invalid{border-color:var(--red)}
    .form-control.is-invalid:focus{box-shadow:0 0 0 3px rgba(239,68,68,.12)}
    .invalid-msg{font-size:.74rem;color:var(--red);margin-top:4px;font-weight:500}
    .form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}

    /* Delete confirm */
    .confirm-wrap{text-align:center}
    .confirm-icon{width:52px;height:52px;background:var(--red-lt);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.5rem;margin:0 auto 14px}
    .confirm-title{font-size:.95rem;font-weight:700;margin-bottom:6px}
    .confirm-desc{font-size:.84rem;color:var(--muted);line-height:1.6}
    .confirm-desc strong{color:var(--text)}

    /* Copy toast */
    .toast{position:fixed;bottom:24px;right:24px;background:var(--navy);color:#fff;padding:10px 18px;border-radius:var(--radius-sm);font-size:.82rem;font-weight:500;box-shadow:0 4px 20px rgba(0,0,0,.2);z-index:999;opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s;pointer-events:none}
    .toast.show{opacity:1;transform:none}

    @media(max-width:680px){
      .form-row{grid-template-columns:1fr}
      .toolbar{flex-direction:column;align-items:stretch}
      .search-input{width:100%}
      .act-btns{flex-wrap:wrap}
    }
  </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar">
  <a class="navbar-brand" href="?">
    <div class="brand-logo">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5">
        <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
        <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
      </svg>
    </div>
    <span class="brand-text">Book <span class="brand-light">Manager</span></span>
  </a>
  <div class="nav-links">
    <a class="nav-link active" href="?">Books</a>
  </div>
</nav>

<div class="page">

  <!-- Flash message -->
  <?php if ($flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?>">
      <?= $flash['type'] === 'success'
        ? '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>'
        : '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' ?>
      <?= e($flash['msg']) ?>
    </div>
  <?php endif; ?>

  <?php if ($showForm): ?>
  <!-- ════════════════════════════════════════
       ADD / EDIT FORM
  ════════════════════════════════════════ -->
  <div class="card" style="max-width:600px;margin:0 auto">
    <div class="page-header">
      <h1 class="page-title"><?= $isEdit ? 'Edit Book' : 'Add New Book' ?></h1>
      <a class="btn btn-secondary" href="?<?= buildQuery() ?>">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
          <line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/>
        </svg>
        Back
      </a>
    </div>

    <form method="post" action="?action=<?= $isEdit ? 'update' : 'store' ?>&<?= buildQuery() ?>">
      <?php if ($isEdit): ?>
        <input type="hidden" name="id" value="<?= (int)$book['id'] ?>">
      <?php endif; ?>

      <!-- Title -->
      <div class="form-group">
        <label class="form-label" for="f_title">Title <span class="req">*</span></label>
        <input id="f_title" type="text" name="title"
               class="form-control <?= isset($errors['title']) ? 'is-invalid' : '' ?>"
               value="<?= $val('title') ?>"
               placeholder="Enter book title" maxlength="255" required autofocus>
        <?php if (isset($errors['title'])): ?>
          <div class="invalid-msg"><?= e($errors['title']) ?></div>
        <?php endif; ?>
      </div>

      <!-- ISBN -->
      <div class="form-group">
        <label class="form-label" for="f_isbn">ISBN <span class="req">*</span></label>
        <input id="f_isbn" type="text" name="isbn"
               class="form-control <?= isset($errors['isbn']) ? 'is-invalid' : '' ?>"
               value="<?= $val('isbn') ?>"
               placeholder="e.g. 9780000000001" maxlength="50" required>
        <?php if (isset($errors['isbn'])): ?>
          <div class="invalid-msg"><?= e($errors['isbn']) ?></div>
        <?php endif; ?>
      </div>

      <!-- Category -->
      <div class="form-group">
        <label class="form-label" for="f_category">Category <span class="req">*</span></label>
        <select id="f_category" name="category"
                class="form-control <?= isset($errors['category']) ? 'is-invalid' : '' ?>" required>
          <option value="">— Select a category —</option>
          <?php foreach (CATEGORIES as $cat): ?>
            <option value="<?= e($cat) ?>" <?= ($val('category') === $cat ? 'selected' : '') ?>>
              <?= e($cat) ?>
            </option>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['category'])): ?>
          <div class="invalid-msg"><?= e($errors['category']) ?></div>
        <?php endif; ?>
      </div>

      <!-- Page Number + Unit Price -->
      <div class="form-row">
        <div class="form-group">
          <label class="form-label" for="f_pages">Page Number <span class="req">*</span></label>
          <input id="f_pages" type="number" name="page_number"
                 class="form-control <?= isset($errors['page_number']) ? 'is-invalid' : '' ?>"
                 value="<?= $val('page_number') ?>"
                 min="1" placeholder="e.g. 250" required>
          <?php if (isset($errors['page_number'])): ?>
            <div class="invalid-msg"><?= e($errors['page_number']) ?></div>
          <?php endif; ?>
        </div>
        <div class="form-group">
          <label class="form-label" for="f_price">Unit Price ($) <span class="req">*</span></label>
          <input id="f_price" type="number" name="unit_price"
                 class="form-control <?= isset($errors['unit_price']) ? 'is-invalid' : '' ?>"
                 value="<?= $val('unit_price') ?>"
                 min="0.01" step="0.01" placeholder="e.g. 19.99" required>
          <?php if (isset($errors['unit_price'])): ?>
            <div class="invalid-msg"><?= e($errors['unit_price']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Buttons -->
      <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:6px">
        <a class="btn btn-secondary" href="?<?= buildQuery() ?>">Cancel</a>
        <button type="submit" class="btn <?= $isEdit ? 'btn-success' : 'btn-primary' ?>">
          <?= $isEdit ? '✓ Update Book' : '+ Save Book' ?>
        </button>
      </div>
    </form>
  </div>

  <?php else: ?>
  <!-- ════════════════════════════════════════
       BOOK LIST
  ════════════════════════════════════════ -->
  <div class="card">

    <!-- Header -->
    <div class="page-header">
      <h1 class="page-title">List of Books</h1>
      <a class="btn btn-primary" href="?action=create&<?= buildQuery() ?>">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
          <line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>
        </svg>
        Add New Book
      </a>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="toolbar-left">
        <form method="get" style="display:flex;align-items:center;gap:7px">
          <input type="hidden" name="search" value="<?= e($search) ?>">
          <select id="perPage" name="per" class="select-sm">
            <?php foreach ([5, 10, 25, 50] as $n): ?>
              <option value="<?= $n ?>" <?= $n === $perPage ? 'selected' : '' ?>><?= $n ?></option>
            <?php endforeach; ?>
          </select>
          entries per page
        </form>
      </div>
      <div class="toolbar-right">
        <span>Search:</span>
        <form method="get">
          <input type="hidden" name="per" value="<?= e($perPage) ?>">
          <div class="search-box">
            <svg class="search-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input id="searchInput" type="text" name="search" class="search-input"
                   value="<?= e($search) ?>" placeholder="Search books..." autocomplete="off">
          </div>
        </form>
      </div>
    </div>

    <!-- Table -->
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>ID ▲</th>
            <th>Title</th>
            <th>ISBN</th>
            <th>Category</th>
            <th>Page Number</th>
            <th>Unit Price</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($books)): ?>
            <tr><td colspan="7">
              <div class="empty-state">
                <svg width="42" height="42" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4">
                  <path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/>
                  <path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>
                </svg>
                <p>No books found<?= $search !== '' ? ' for "<strong>'.e($search).'</strong>"' : '' ?>.</p>
              </div>
            </td></tr>
          <?php else: ?>
            <?php foreach ($books as $b): ?>
              <tr>
                <td class="td-id"><?= e($b['id']) ?></td>
                <td><?= e($b['title']) ?></td>
                <td class="td-isbn"><?= e($b['isbn']) ?></td>
                <td><?= categoryBadge($b['category']) ?></td>
                <td><?= e($b['page_number']) ?></td>
                <td class="td-price">$<?= number_format((float)$b['unit_price'], 2) ?></td>
                <td class="td-act">
                  <div class="act-btns">
                    <!-- Edit -->
                    <a class="btn btn-sm btn-edit"
                       href="?action=edit&id=<?= (int)$b['id'] ?>&<?= buildQuery() ?>">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>
                        <path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/>
                      </svg>
                      Edit
                    </a>
                    <!-- Copy -->
                    <button class="btn btn-sm btn-copy"
                            onclick="copyBook(<?= htmlspecialchars(json_encode([
                              'title'       => $b['title'],
                              'isbn'        => $b['isbn'],
                              'category'    => $b['category'],
                              'page_number' => $b['page_number'],
                              'unit_price'  => $b['unit_price'],
                            ]), ENT_QUOTES) ?>)">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"/>
                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                      </svg>
                      Copy
                    </button>
                    <!-- Delete -->
                    <button class="btn btn-sm btn-del"
                            onclick="openDel(<?= (int)$b['id'] ?>, <?= json_encode($b['title']) ?>)">
                      <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>
                        <path d="M10 11v6M14 11v6"/>
                        <path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>
                      </svg>
                      Delete
                    </button>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <div class="pagination-row">
      <div class="pagination-info">
        <?php
          $from = $total > 0 ? ($page-1)*$perPage+1 : 0;
          $to   = min($page*$perPage, $total);
          echo "Showing $from to $to of $total entries";
        ?>
      </div>
      <?= paginate($page, $maxPage, ['search'=>$search,'per'=>$perPage]) ?>
    </div>

  </div><!-- /.card -->

  <!-- Delete Confirm Modal -->
  <div id="delModal" class="modal-backdrop" style="display:none">
    <div class="modal">
      <div class="modal-header">
        <h2>Delete Book</h2>
        <button class="modal-close" onclick="closeDel()">&times;</button>
      </div>
      <form method="post" action="?action=delete">
        <input type="hidden" name="id" id="delId">
        <div class="modal-body">
          <div class="confirm-wrap">
            <div class="confirm-icon">🗑️</div>
            <div class="confirm-title">Are you sure?</div>
            <div class="confirm-desc">
              You are about to delete <strong id="delTitle"></strong>.<br>
              This action <strong>cannot be undone</strong>.
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" onclick="closeDel()">Cancel</button>
          <button type="submit" class="btn btn-danger">Delete</button>
        </div>
      </form>
    </div>
  </div>

  <?php endif; ?>

</div><!-- /.page -->

<!-- Copy toast -->
<div id="toast" class="toast">✓ Copied to clipboard</div>

<script>
  /* Delete modal */
  const dm = document.getElementById('delModal');
  function openDel(id, title) {
    document.getElementById('delId').value = id;
    document.getElementById('delTitle').textContent = '"' + title + '"';
    dm.style.display = 'flex';
  }
  function closeDel() { dm.style.display = 'none'; }
  dm?.addEventListener('click', e => { if (e.target === dm) closeDel(); });
  document.addEventListener('keydown', e => { if (e.key === 'Escape') closeDel(); });

  /* Copy to clipboard */
  function copyBook(data) {
    const text =
      'Title: '       + data.title       + '\n' +
      'ISBN: '        + data.isbn        + '\n' +
      'Category: '    + data.category    + '\n' +
      'Page Number: ' + data.page_number + '\n' +
      'Unit Price: $' + parseFloat(data.unit_price).toFixed(2);
    navigator.clipboard.writeText(text)
      .then(() => showToast('✓ Copied to clipboard'))
      .catch(() => {
        const ta = document.createElement('textarea');
        ta.value = text;
        ta.style.cssText = 'position:fixed;opacity:0';
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
        showToast('✓ Copied to clipboard');
      });
  }
  function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 2200);
  }

  /* Auto-dismiss flash */
  const fl = document.querySelector('.alert');
  if (fl) setTimeout(() => {
    fl.style.transition = 'opacity .4s,transform .35s';
    fl.style.opacity = '0';
    fl.style.transform = 'translateY(-6px)';
    setTimeout(() => fl.remove(), 400);
  }, 3500);

  /* Per-page auto-submit */
  document.getElementById('perPage')?.addEventListener('change', function () {
    this.closest('form').submit();
  });

  /* Search debounce */
  let st;
  document.getElementById('searchInput')?.addEventListener('input', function () {
    clearTimeout(st);
    st = setTimeout(() => this.closest('form').submit(), 380);
  });
</script>
</body>
</html>