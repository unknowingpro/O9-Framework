<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database;
use App\Core\Security\Jwt;
use App\Core\StorageManager;
use App\Entitlements\EntitlementService;
use App\I18n\Translatable;
use App\Identity\Rbac;
use App\Models\MediaModel;
use App\Models\UserModel;
use App\Payments\Dto\PaymentRequest;
use App\Payments\Gateway\SandboxGateway;
use App\Payments\PaymentGatewayFactory;
use App\Payments\PaymentService;
use App\Services\I18n\LanguageService;
use App\Services\MigrationsService;
use App\Services\SettingsService;
use App\Storage\LocalDriver;
use App\Subscriptions\SubscriptionService;
use PHPUnit\Framework\TestCase;

/**
 * Every other test in this suite hand-rolls its own schema for speed and
 * isolation — which is exactly why setup/database/migrations/010_jobs and
 * 011_jwt_revocations shipped for a while with no migration creating their
 * table at all (Core\Queue crashed every fresh install; Core\Security\Jwt's
 * revocation silently never activated) despite QueueTest and JwtTest being
 * green the whole time. This test is the deliberate exception: it applies
 * the REAL migrations directory — the exact files setup.php / `console
 * migrate` run in production — and exercises each real consumer's primary
 * write+read path against the schema those files actually produce. Any
 * future drift between a migration and the class that reads it fails here,
 * not in a fresh install three months from now.
 */
final class RealMigrationsIntegrationTest extends TestCase
{
    /** @var list<string> every table any migration in setup/database/migrations creates */
    private const TABLES = [
        'languages', 'roles', 'permissions', 'role_permissions', 'user_roles',
        'user_subscriptions', 'entitlement_overrides', 'payment_intents',
        'store_webhook_events', 'content_translations', 'users', 'media',
        'settings', 'jobs', 'jwt_revocations', 'migrations',
    ];

    private string $storageRoot;

    protected function setUp(): void
    {
        $pdo = Database::getInstance()->pdo();
        foreach (self::TABLES as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }

        (new MigrationsService())->applyAll(); // the real setup/database/migrations directory

        $this->storageRoot = sys_get_temp_dir() . '/o9-real-migrations-' . bin2hex(random_bytes(4));
        StorageManager::reset();
        StorageManager::instance()->setDriver('local', new LocalDriver(['root' => $this->storageRoot]));

        LanguageService::reset();
        SettingsService::reset();
        PaymentService::reset();
        PaymentGatewayFactory::reset();
        SubscriptionService::reset();
        Jwt::reset();
    }

    protected function tearDown(): void
    {
        StorageManager::reset();
        $this->rrmdir($this->storageRoot); // MediaModel stores uploads under a {userId}/ subdirectory

        $pdo = Database::getInstance()->pdo();
        foreach (self::TABLES as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
        LanguageService::reset();
        SettingsService::reset();
        PaymentService::reset();
        PaymentGatewayFactory::reset();
        SubscriptionService::reset();
        Jwt::reset();
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function testEveryMigrationAppliesCleanlyInOrder(): void
    {
        $svc = new MigrationsService();
        $this->assertSame([], $svc->pending());
        $this->assertNotEmpty($svc->applied());
    }

    public function testLanguagesTableSeedsTheDefaultActiveRegistry(): void
    {
        $langs = LanguageService::getInstance()->getActiveLangs();
        $this->assertNotEmpty($langs);
        $codes = array_column($langs, 'code');
        $this->assertContains('en', $codes);
        $this->assertContains('fa', $codes);
    }

    public function testUserModelRegistersAndFindsAgainstTheRealUsersTable(): void
    {
        $model = new UserModel();
        $id = $model->register('real-migration@example.com', 'secret-pass', 'admin');
        $row = $model->findByEmail('real-migration@example.com');
        $this->assertSame($id, (int) $row['id']);
    }

    public function testMediaModelStoresAgainstTheRealMediaTable(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'o9-upload-');
        file_put_contents($tmp, 'file contents');
        try {
            $model = new MediaModel();
            $id = $model->storeUpload($tmp, 'photo.jpg', 7);
            $this->assertNotEmpty($model->forUser(7));
            $this->assertSame([$id], array_map(static fn (array $r): int => (int) $r['id'], $model->forUser(7)));
        } finally {
            @unlink($tmp);
        }
    }

    public function testSettingsServiceRoundTripsAgainstTheRealSettingsTable(): void
    {
        SettingsService::set('site.title', 'Real Migration Test');
        $this->assertSame('Real Migration Test', SettingsService::get('site.title'));
    }

    public function testRbacAssignsAndChecksAgainstTheRealRoleTables(): void
    {
        // roles/permissions ship empty by design (app-defined policy, not
        // framework seed data) — a role must exist before it's assignable.
        Database::getInstance()->raw('INSERT INTO roles (name, created_at) VALUES (?, ?)', ['editor', gmdate('Y-m-d H:i:s')]);
        Rbac::assign(1, 'editor');
        $this->assertContains('editor', Rbac::rolesFor(['id' => 1]));
    }

    public function testEntitlementServiceReadsTierAgainstTheRealSubscriptionsTable(): void
    {
        $svc = new EntitlementService();
        $svc->setTier(1, 'pro', 99, 'manual');
        $this->assertSame('pro', $svc->tierOf(1));
    }

    public function testSubscriptionServiceSubscribesAgainstTheRealTables(): void
    {
        $svc = new SubscriptionService(new SandboxGateway());
        $row = $svc->subscribe(1, 'pro', 'month');
        $this->assertSame('pro', $row['tier'] ?? null);
    }

    public function testPaymentServiceDepositsAgainstTheRealPaymentIntentsTable(): void
    {
        $svc = new PaymentService(new SandboxGateway());
        $intent = $svc->deposit(new PaymentRequest(1, 500, 'real-migration-dep-1'));
        $this->assertSame(500, (int) $intent['amount_cents']);
    }

    public function testTranslatablePersistsAgainstTheRealContentTranslationsTable(): void
    {
        Translatable::put('product', 1, 'name', 'fa', 'محصول واقعی');
        $this->assertSame('محصول واقعی', Translatable::text('product', 1, 'Base', 'name', 'fa'));
    }

    public function testJwtRevocationWorksAgainstTheRealJwtRevocationsTable(): void
    {
        $token = Jwt::encode(['sub' => 1], 3600);
        $this->assertNotNull(Jwt::decode($token));
        Jwt::revoke($token);
        $this->assertNull(Jwt::decode($token));
    }

    public function testQueuePushAndReserveWorkAgainstTheRealJobsTable(): void
    {
        $id = \App\Core\Queue::push(\App\Jobs\DispatchEventJob::class, ['event' => 'x', 'payload' => []]);
        $job = \App\Core\Queue::reserve();
        $this->assertNotNull($job);
        $this->assertSame($id, (int) $job['id']);
    }
}
