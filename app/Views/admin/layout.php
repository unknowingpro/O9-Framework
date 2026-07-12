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
    <link rel="stylesheet" href="<?= asset('assets/css/admin.css') ?>">
    <script type="module" src="<?= asset_module('assets/js/modules/core.js') ?>"></script>
    <script type="module" src="<?= asset_module('assets/js/modules/ui.js') ?>"></script>
    <script type="module" src="<?= asset_module('assets/js/modules/admin.js') ?>"></script>
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
