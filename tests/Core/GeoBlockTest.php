<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Auth;
use App\Core\Database;
use App\Core\GeoBlock;
use App\Core\HttpResponse;
use App\Core\Request;
use App\Services\SettingsService;
use PHPUnit\Framework\TestCase;

final class GeoBlockTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $serverBackup;

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
        Auth::reset();
        SettingsService::reset();
        $_SESSION = [];

        $db = Database::getInstance();
        $db->pdo()->exec('DROP TABLE IF EXISTS settings');
        $db->pdo()->exec('CREATE TABLE settings (key_name TEXT PRIMARY KEY, value TEXT, updated_by INTEGER, updated_at TEXT NOT NULL)');
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        Auth::reset();
        SettingsService::reset();
        $_SESSION = [];
    }

    private function request(string $path = '/', ?string $country = null, string $header = 'CF-IPCountry', bool $json = false): Request
    {
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['HTTP_ACCEPT'] = $json ? 'application/json' : 'text/html';

        unset($_SERVER['HTTP_CF_IPCOUNTRY'], $_SERVER['HTTP_X_GEO_COUNTRY'], $_SERVER['HTTP_X_COUNTRY']);
        if ($country !== null) {
            $_SERVER['HTTP_' . strtoupper(str_replace('-', '_', $header))] = $country;
        }

        return new Request();
    }

    private function enable(string ...$countries): void
    {
        SettingsService::set('security.geo_blocking', '1');
        SettingsService::set('security.geo_blocked_countries', json_encode(array_values($countries)));
    }

    // ── off by default ──────────────────────────────────────────────────────

    public function testOffByDefault(): void
    {
        $this->assertFalse(GeoBlock::isOn());
        $this->assertFalse(GeoBlock::shouldBlock($this->request('/', 'RU')));
    }

    public function testDisabledMeansEvenABlockedCountryPasses(): void
    {
        SettingsService::set('security.geo_blocking', '0');
        SettingsService::set('security.geo_blocked_countries', json_encode(['RU']));

        $this->assertFalse(GeoBlock::shouldBlock($this->request('/', 'RU')));
    }

    // ── blocking ────────────────────────────────────────────────────────────

    public function testABlockedCountryIsBlocked(): void
    {
        $this->enable('RU', 'KP');

        $this->assertTrue(GeoBlock::shouldBlock($this->request('/', 'RU')));
        $this->assertTrue(GeoBlock::shouldBlock($this->request('/', 'kp')), 'the header must be matched case-insensitively');
    }

    public function testAnUnblockedCountryPasses(): void
    {
        $this->enable('RU');

        $this->assertFalse(GeoBlock::shouldBlock($this->request('/', 'DE')));
    }

    public function testAnEmptyBlocklistBlocksNobody(): void
    {
        SettingsService::set('security.geo_blocking', '1');
        SettingsService::set('security.geo_blocked_countries', json_encode([]));

        $this->assertFalse(GeoBlock::shouldBlock($this->request('/', 'RU')));
    }

    // ── fail open ───────────────────────────────────────────────────────────

    public function testUnknownCountryFailsOpen(): void
    {
        $this->enable('RU');

        $this->assertNull(GeoBlock::countryFor($this->request('/')));
        $this->assertFalse(GeoBlock::shouldBlock($this->request('/')), 'a missing header must never lock the site out');
    }

    /** Cloudflare sends XX for unknown and T1 for Tor — neither is a country. */
    public function testCloudflareSentinelValuesAreTreatedAsUnknown(): void
    {
        $this->enable('RU');

        $this->assertNull(GeoBlock::countryFor($this->request('/', 'XX')));
        $this->assertNull(GeoBlock::countryFor($this->request('/', 'T1')));
        $this->assertFalse(GeoBlock::shouldBlock($this->request('/', 'XX')));
    }

    public function testMalformedCountryHeaderIsTreatedAsUnknown(): void
    {
        $this->enable('RU');

        $this->assertNull(GeoBlock::countryFor($this->request('/', 'RUS')));
        $this->assertNull(GeoBlock::countryFor($this->request('/', '1')));
    }

    public function testTheGenericHeadersAreAlsoRead(): void
    {
        $this->assertSame('DE', GeoBlock::countryFor($this->request('/', 'DE', 'X-Geo-Country')));
        $this->assertSame('DE', GeoBlock::countryFor($this->request('/', 'DE', 'X-Country')));
    }

    // ── never lock the operator out ─────────────────────────────────────────

    public function testAdminsAlwaysPass(): void
    {
        $this->enable('RU');
        Auth::resolveUserUsing(static fn (int $id): array => ['id' => $id, 'roles' => 'admin']);
        $_SESSION['user_id'] = 1;

        $this->assertFalse(GeoBlock::shouldBlock($this->request('/', 'RU')));
    }

    /** @return list<array{string}> */
    public static function allowedPaths(): array
    {
        return [['/assets/app.css'], ['/admin'], ['/login'], ['/logout'], ['/auth/token'], ['/health']];
    }

    /** @dataProvider allowedPaths */
    public function testAdminAuthAndAssetPathsStayReachable(string $path): void
    {
        $this->enable('RU');

        $this->assertFalse(GeoBlock::shouldBlock($this->request($path, 'RU')), "{$path} must stay reachable");
    }

    // ── blocklist parsing ───────────────────────────────────────────────────

    public function testBlocklistIsNormalizedAndDeduplicated(): void
    {
        SettingsService::set('security.geo_blocking', '1');
        SettingsService::set('security.geo_blocked_countries', json_encode([' ru ', 'RU', 'de', 'bad', '']));

        $this->assertSame(['RU', 'DE'], GeoBlock::blockedCountries());
    }

    public function testGarbageBlocklistIsEmptyRatherThanFatal(): void
    {
        SettingsService::set('security.geo_blocking', '1');
        SettingsService::set('security.geo_blocked_countries', 'not-json');

        $this->assertSame([], GeoBlock::blockedCountries());
    }

    // ── the response ────────────────────────────────────────────────────────

    public function testServeThrowsA451JsonEnvelopeForApiClients(): void
    {
        try {
            GeoBlock::serve($this->request('/api/v1/x', 'RU', 'CF-IPCountry', true));
            $this->fail('expected HttpResponse');
        } catch (HttpResponse $r) {
            $this->assertSame(451, $r->status);
            $this->assertIsArray($r->payload);
            $this->assertSame('geo_blocked', $r->payload['error']['code']);
            $this->assertFalse($r->payload['ok']);
        }
    }

    public function testServeThrowsA451HtmlPageForBrowsers(): void
    {
        try {
            GeoBlock::serve($this->request('/', 'RU'));
            $this->fail('expected HttpResponse');
        } catch (HttpResponse $r) {
            $this->assertSame(451, $r->status);
            $this->assertSame('text/html; charset=utf-8', $r->headers['Content-Type']);
            $this->assertIsString($r->payload);
            $this->assertStringContainsString('451', $r->payload);
        }
    }
}
