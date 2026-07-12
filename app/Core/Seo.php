<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Per-request SEO / Open-Graph bag. A page template calls Seo::set(...) before
 * the layout renders (View::render() renders the page first, then the layout,
 * so values set during the page render are visible to the layout). The layout
 * reads these with app-level fallbacks. Unset → site defaults.
 *
 * This avoids threading meta through every controller's $data: shareable pages
 * (a profile, a public listing) just declare their own title/description/image
 * at the top of the template.
 */
final class Seo
{
    private static ?string $title = null;
    private static ?string $description = null;
    private static ?string $image = null;
    private static ?string $type = null;
    private static ?string $url = null;
    /** @var list<array<string, mixed>> schema.org objects emitted as JSON-LD */
    private static array $jsonLd = [];

    public static function set(
        ?string $title = null,
        ?string $description = null,
        ?string $image = null,
        ?string $type = null,
        ?string $url = null,
    ): void {
        if ($title !== null)       self::$title = $title;
        if ($description !== null) self::$description = $description;
        if ($image !== null)       self::$image = $image;
        if ($type !== null)        self::$type = $type;
        if ($url !== null)         self::$url = $url;
    }

    /**
     * Add a schema.org structured-data object (rendered as <script ld+json> in
     * the head). Pass the object WITHOUT the @context — it's added on output.
     * Call multiple times to emit several objects.
     *
     * @param array<string, mixed> $data e.g. ['@type' => 'Person', 'name' => '…']
     */
    public static function jsonLd(array $data): void
    {
        if ($data !== []) {
            self::$jsonLd[] = $data;
        }
    }

    /**
     * The JSON-LD block for the head, or null when none set. One object emits a
     * single node; several emit an @graph. Slashes/unicode left intact — except
     * every literal '<' is escaped to the JSON unicode form, since this is
     * echoed directly inside a <script type="application/ld+json"> tag
     * (layouts/main.php) and JSON_UNESCAPED_SLASHES means a value containing
     * "</script>" (e.g. a title or description pulled from user content once
     * a real page starts calling jsonLd() with dynamic data) would otherwise
     * close that tag early and let anything after it run as raw HTML. The
     * escaped form decodes back to '<' identically on the JS side — this
     * only changes the script tag's literal text, not the JSON's semantic
     * content.
     */
    public static function jsonLdJson(): ?string
    {
        if (self::$jsonLd === []) {
            return null;
        }
        if (count(self::$jsonLd) === 1) {
            $doc = ['@context' => 'https://schema.org'] + self::$jsonLd[0];
        } else {
            $doc = ['@context' => 'https://schema.org', '@graph' => self::$jsonLd];
        }
        $json = (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return str_replace('<', '\\u003C', $json);
    }

    /** Clear all values — used between requests/tests so static state doesn't bleed. */
    public static function reset(): void
    {
        self::$title = self::$description = self::$image = self::$type = self::$url = null;
        self::$jsonLd = [];
    }

    public static function title(): ?string       { return self::$title; }
    public static function description(): ?string { return self::$description; }
    public static function image(): ?string        { return self::$image; }
    public static function type(): ?string          { return self::$type; }
    public static function url(): ?string           { return self::$url; }
}
