<?php
/**
 * A reusable prop-driven partial (see Core\View::component()).
 * @var string $title
 * @var string $slot inner HTML passed by the caller
 */
use App\Core\View;
?>
<div class="callout">
    <strong><?= View::e($title) ?></strong>
    <div class="callout-body"><?= $slot ?></div>
</div>
