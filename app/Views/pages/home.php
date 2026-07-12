<?php
/** @var string $appName */
use App\Core\View;
?>
<h1><?= View::e($appName) ?></h1>
<p>It runs. This page is rendered by <code>App\Controllers\Web\PageController::home()</code>
   through <code>Core\View</code>, wrapped in <code>layouts/main.php</code>.</p>

<?= View::component('callout', [
    'title' => 'Where to look next',
], '<ul>
    <li><code>routes/web.php</code>, <code>routes/api.php</code>, <code>routes/bot.php</code> — route registration</li>
    <li><code>app/Controllers/{Admin,Api,Bot,Web}</code> — one sample controller per surface</li>
    <li><code>app/Views/components/callout.php</code> — the component this box is</li>
</ul>') ?>

<p><a href="/about">About this app</a></p>
