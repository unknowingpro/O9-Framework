<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Shared controller helpers: validation, view rendering, and access to
 * the authenticated user.
 */
abstract class BaseController
{
    /**
     * Validate request input against pipe rules and return the (coerced) values; on failure emits
     * the 422 `validation_failed` envelope. Rules are handled by the standalone Core\Validator
     * (also usable directly from services for non-HTTP validation).
     *
     * @param array<string,mixed> $input
     * @param array<string,string|list<string>> $rules
     * @return array<string,mixed>
     */
    protected function validate(array $input, array $rules): array
    {
        $result = Validator::check($input, $rules);
        if (!$result['valid']) {
            Response::error('validation_failed', __('validation.failed'), 422, $result['errors']);
        }
        return $result['data'];
    }

    /**
     * Emit a `?page=&per_page=` paginated envelope over an already-fetched list. For bounded
     * per-user collections where slicing in PHP is fine; the full count gives clients total + pages.
     *
     * @param list<array<string,mixed>> $all
     * @param array<string,mixed> $meta extra top-level meta
     */
    protected function paginate(array $all, Request $request, int $defaultPer = 50, int $maxPer = 100, array $meta = []): never
    {
        $per  = min($maxPer, max(1, (int) $request->query('per_page', $defaultPer)));
        $page = max(1, (int) $request->query('page', 1));
        Response::paginated(array_slice($all, ($page - 1) * $per, $per), $page, $per, count($all), $meta);
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], ?string $layout = 'layouts/main'): never
    {
        // Authenticated pages carry user-specific data and must never be cached
        // by the browser or a shared cache (CDN/proxies) — otherwise a user can
        // be served a stale (or another user's) page.
        if ($this->userId() !== null && !headers_sent()) {
            header('Cache-Control: private, no-store, max-age=0');
        }
        Response::html(view($template, $data, $layout));
    }

    /** @return array<string,mixed>|null */
    protected function user(): ?array
    {
        return class_exists(Auth::class) ? Auth::user() : null;
    }

    protected function userId(): ?int
    {
        return class_exists(Auth::class) ? Auth::id() : null;
    }
}
