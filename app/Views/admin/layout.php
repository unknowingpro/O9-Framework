<?php
/**
 * @var string $content
 */
use App\Core\Seo;
use App\Core\Session;
use App\Core\View;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e(Seo::title() ?? 'Admin') ?> — <?= View::e(config('app.name', 'O9')) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 0; background: #f4f5f7; color: #1a1a1a; }
        .admin-nav { background: #111827; color: #fff; padding: 12px 24px; display: flex; gap: 16px; align-items: center; }
        .admin-nav a { color: #d1d5db; text-decoration: none; }
        .admin-nav a:hover { color: #fff; }
        .admin-main { max-width: 960px; margin: 0 auto; padding: 32px 24px; }
        .flash { padding: 12px 16px; border-radius: 6px; margin-bottom: 16px; }
        .flash-ok { background: #e6f4ea; color: #1e7e34; }
        .flash-error { background: #fdecea; color: #b3261e; }
        table { border-collapse: collapse; width: 100%; }
        th, td { text-align: left; padding: 8px 12px; border-bottom: 1px solid #e5e7eb; }
    </style>
</head>
<body>
    <nav class="admin-nav">
        <strong><?= View::e(config('app.name', 'O9')) ?> Admin</strong>
        <a href="/admin">Dashboard</a>
    </nav>
    <div class="admin-main">
        <?php foreach (Session::takeFlash() as $flash): ?>
        <div class="flash flash-<?= View::e($flash['type']) ?>"><?= View::e($flash['msg']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </div>
</body>
</html>
