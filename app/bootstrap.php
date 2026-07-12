<?php
declare(strict_types=1);

/**
 * App-level bootstrap: wires the framework's injectable resolver hooks to
 * this app's real models. Required once per request by App::bootApp() when
 * present (see App.php's boot order docblock).
 */

use App\Core\Auth;
use App\Core\Lang;
use App\Models\UserModel;

Auth::resolveUserUsing(fn (int $id): ?array => (new UserModel())->find($id));

if (class_exists(Lang::class)) {
    Lang::persistUserLocaleUsing(function (int $id, string $locale): void {
        (new UserModel())->setLocale($id, $locale);
    });
}
