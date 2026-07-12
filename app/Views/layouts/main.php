<?php
/**
 * @var string $content
 * @var array<string, mixed> $data available to every page rendered through this layout
 */
use App\Core\Seo;
use App\Core\Session;
use App\Core\View;
?>
<!doctype html>
<html lang="<?= View::e(config('app.default_locale', 'en')) ?>" dir="<?= \App\Core\Lang::direction() ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= View::e(Seo::title() ?? config('app.name', 'O9')) ?></title>
    <?php if (Seo::description() !== null): ?>
    <meta name="description" content="<?= View::e(Seo::description()) ?>">
    <?php endif; ?>
    <?php if (Seo::jsonLdJson() !== null): ?>
    <script type="application/ld+json"><?= Seo::jsonLdJson() ?></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= asset('assets/css/main.css') ?>">
    <script type="module" src="<?= asset_module('assets/js/modules/core.js') ?>"></script>
    <script type="module" src="<?= asset_module('assets/js/modules/ui.js') ?>"></script>
    <?= View::stack('head') ?>
</head>
<body>
    <div class="container">
        <?php foreach (Session::takeFlash() as $flash): ?>
        <div class="flash flash-<?= View::e($flash['type']) ?>"><?= View::e($flash['msg']) ?></div>
        <?php endforeach; ?>
        <?= $content ?>
    </div>
    <?= View::stack('scripts') ?>
</body>
</html>
