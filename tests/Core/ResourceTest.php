<?php
declare(strict_types=1);

namespace Tests\Core;

use App\Core\Resource;
use App\Resources\PublicUserResource;
use App\Resources\UserResource;
use PHPUnit\Framework\TestCase;

/** @extends Resource */
final class SampleResource extends Resource
{
    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['id' => (int) ($this->data['id'] ?? 0)];
    }
}

final class ResourceTest extends TestCase
{
    /** @return array<string, mixed> */
    private function userRow(): array
    {
        return [
            'id'              => 7,
            'email'           => 'ada@example.com',
            'password_hash'   => '$2y$10$secret',
            'roles'           => 'admin,member',
            'locale'          => 'en',
            'force_logout_at' => '2026-01-01 00:00:00',
            'last_seen_at'    => '2026-07-12 10:00:00',
            'created_at'      => '2026-01-01 00:00:00',
        ];
    }

    public function testMakeReturnsTheSubclass(): void
    {
        $r = SampleResource::make(['id' => 3]);
        $this->assertInstanceOf(SampleResource::class, $r);
        $this->assertSame(['id' => 3], $r->toArray());
    }

    public function testCollectionTransformsEveryItem(): void
    {
        $out = SampleResource::collection([['id' => 1], ['id' => 2]]);
        $this->assertSame([['id' => 1], ['id' => 2]], $out);
    }

    public function testCollectionOfAnEmptyListIsAnEmptyList(): void
    {
        $this->assertSame([], SampleResource::collection([]));
    }

    public function testCollectionAcceptsAnyIterable(): void
    {
        $gen = (static function (): \Generator {
            yield ['id' => 1];
            yield ['id' => 2];
        })();

        $this->assertSame([['id' => 1], ['id' => 2]], SampleResource::collection($gen));
    }

    // ── the point of the whole class: secrets never leak ─────────────────────

    public function testUserResourceNeverExposesThePasswordHash(): void
    {
        $out = UserResource::make($this->userRow())->toArray();

        $this->assertArrayNotHasKey('password_hash', $out);
        $this->assertArrayNotHasKey('force_logout_at', $out);
        $this->assertSame(7, $out['id']);
        $this->assertSame('ada@example.com', $out['email']);
    }

    public function testUserResourceSplitsRolesIntoAnArray(): void
    {
        $out = UserResource::make($this->userRow())->toArray();
        $this->assertSame(['admin', 'member'], $out['roles']);
    }

    public function testUserResourceHandlesEmptyRoles(): void
    {
        $out = UserResource::make(['id' => 1, 'email' => 'a@b.c', 'roles' => ''])->toArray();
        $this->assertSame([], $out['roles']);
    }

    public function testPublicUserResourceHidesTheEmailAddress(): void
    {
        $out = PublicUserResource::make($this->userRow())->toArray();

        $this->assertArrayNotHasKey('email', $out);
        $this->assertArrayNotHasKey('password_hash', $out);
        $this->assertArrayNotHasKey('locale', $out);
        $this->assertSame(['id' => 7, 'name' => 'ada', 'roles' => ['admin']], $out);
    }

    public function testPublicUserResourceOnlyShowsBadgeRoles(): void
    {
        $out = PublicUserResource::make(['id' => 1, 'email' => 'a@b.c', 'roles' => 'member,billing'])->toArray();
        $this->assertSame([], $out['roles'], 'internal roles must not be advertised publicly');
    }

    public function testAnUnknownColumnIsInvisibleUntilTheResourceOptsIn(): void
    {
        $row = $this->userRow() + ['secret_api_token' => 'sk_live_123'];
        $out = UserResource::make($row)->toArray();

        $this->assertArrayNotHasKey('secret_api_token', $out);
    }
}
