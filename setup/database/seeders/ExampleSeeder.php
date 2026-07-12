<?php
declare(strict_types=1);

namespace App\Database\Seeders;

use App\Core\Database;
use App\Core\Seeder;

/**
 * Example seeder — inserts a default admin user and a language row.
 * Run: php setup/bin/console db:seed --class=ExampleSeeder
 */
final class ExampleSeeder extends Seeder
{
    public function run(array $args = []): void
    {
        if ($this->isFresh($args)) {
            $this->truncate('users');
            $this->truncate('languages');
        }

        $db = Database::getInstance();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');

        // Insert a default admin if not already present
        $existing = $db->raw('SELECT id FROM users WHERE email = ?', ['admin@example.com'])->fetch();
        if ($existing === false) {
            $db->raw(
                'INSERT INTO users (email, password_hash, roles, locale, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                ['admin@example.com', password_hash('admin123', PASSWORD_BCRYPT), 'admin', 'en', $now, $now],
            );
        }

        // Insert a default language if not already present
        $lang = $db->raw("SELECT id FROM languages WHERE code = 'en'")->fetch();
        if ($lang === false) {
            $db->raw(
                "INSERT INTO languages (code, name, locale, is_active, sort_order, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                ['en', 'English', 'en_US', 1, 0, $now],
            );
        }
    }
}
