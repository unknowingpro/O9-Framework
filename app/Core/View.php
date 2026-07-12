<?php
declare(strict_types=1);

namespace App\Core;

/**
 * View — Blade-like server-side templating (composer-less, plain PHP).
 *
 * Pages: render('pages/home', $data, 'layouts/main') captures the template as
 * $content and renders it inside the layout. Templates live in app/Views.
 * Builds on the standalone view() helper in Core/helpers.php, adding the
 * pieces that need shared state across a single render:
 *
 *   • Components — reusable, prop-driven partials in app/Views/components/*,
 *     rendered with component('tabs', [...], $slotHtml). The component sees
 *     its props as locals plus $slot.
 *   • Sections   — startSection('x') … endSection() (or section('x', $html));
 *     the layout emits them with yieldSection('x'). Lets a page inject into
 *     named regions of its layout (Blade's @section/@yield).
 *   • Stacks     — push('scripts', $html) from anywhere; the layout flushes
 *     them with stack('scripts') (Blade's @push/@stack), e.g. partial-local
 *     <script> tags.
 *
 * Always emits via HttpResponse so App::run() handles output uniformly.
 */
final class View
{
    /** @var array<string, string> named sections set by templates */
    private static array $sections = [];
    /** @var list<string> open section buffers (LIFO) */
    private static array $openSections = [];
    /** @var array<string, list<string>> named push stacks */
    private static array $stacks = [];

    /**
     * Render a template (optionally wrapped in a layout) → throws HttpResponse(200,html).
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/main'): never
    {
        // Fresh section/stack state per render (one render per request).
        self::$sections = [];
        self::$openSections = [];
        self::$stacks = [];

        $content = self::capture($template, $data);
        $html = $layout !== null
            ? self::capture($layout, array_merge($data, ['content' => $content]))
            : $content;
        throw new HttpResponse(200, $html, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    /** A 302 redirect. */
    public static function redirect(string $to): never
    {
        throw new HttpResponse(302, '', ['Location' => $to]);
    }

    /**
     * Render a template to a string (for partials / layout composition).
     *
     * @param array<string, mixed> $data
     */
    public static function capture(string $template, array $data = []): string
    {
        $file = base_path('app/Views/' . ltrim($template, '/') . '.php');
        if (!is_file($file)) {
            throw new \RuntimeException("View not found: {$template}");
        }
        extract($data, EXTR_SKIP);
        ob_start();
        include $file;
        return (string) ob_get_clean();
    }

    // ── Components ───────────────────────────────────────────────────────

    /**
     * Render a reusable component from app/Views/components/{name}.php with its
     * props as locals and an optional $slot (inner HTML). Returns the HTML.
     *
     * @param array<string, mixed> $props
     */
    public static function component(string $name, array $props = [], string $slot = ''): string
    {
        return self::capture('components/' . $name, $props + ['slot' => $slot]);
    }

    // ── Sections (layout inheritance: @section / @yield) ──────────────────

    /** Begin buffering a named section. */
    public static function startSection(string $name): void
    {
        self::$openSections[] = $name;
        ob_start();
    }

    /** Finish the most recently opened section. */
    public static function endSection(): void
    {
        $name = array_pop(self::$openSections);
        if ($name !== null) {
            self::$sections[$name] = (string) ob_get_clean();
        }
    }

    /** Set a section directly (no buffering). */
    public static function section(string $name, string $value): void
    {
        self::$sections[$name] = $value;
    }

    /** Emit a section's content (or a default) — used in layouts. */
    public static function yieldSection(string $name, string $default = ''): string
    {
        return self::$sections[$name] ?? $default;
    }

    // ── Stacks (@push / @stack) ────────────────────────────────────────────

    /** Append HTML to a named stack (e.g. per-partial scripts). */
    public static function push(string $stack, string $html): void
    {
        self::$stacks[$stack][] = $html;
    }

    /** Flush a stack's accumulated HTML — used in layouts. */
    public static function stack(string $name): string
    {
        return implode("\n", self::$stacks[$name] ?? []);
    }

    /** HTML-escape helper for use inside templates. */
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
    }

    /** @internal test reset */
    public static function reset(): void
    {
        self::$sections = [];
        self::$openSections = [];
        self::$stacks = [];
    }
}
