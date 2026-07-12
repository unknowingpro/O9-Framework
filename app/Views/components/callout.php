<?php
/**
 * A reusable prop-driven partial (see Core\View::component()).
 * @var string $title
 * @var string $slot inner HTML passed by the caller
 */
use App\Core\View;
?>
<div style="border:1px solid #dfe3e8;border-radius:8px;padding:16px 20px;background:#fff;margin:20px 0">
    <strong><?= View::e($title) ?></strong>
    <div style="margin-top:8px"><?= $slot ?></div>
</div>
