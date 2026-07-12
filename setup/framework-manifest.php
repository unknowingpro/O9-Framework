<?php
declare(strict_types=1);

/**
 * The framework-owned file list — the sync contract between this repo
 * (the canonical O9 tree) and projects built on it.
 *
 * Everything listed here is generic framework code with no app-specific
 * logic; `setup/scripts/sync-framework.php` copies it byte-for-byte into a
 * target project. Projects must never hand-edit a manifest path directly —
 * fix the bug or add the feature here in phpframe, then re-sync so every
 * project picks it up the same way.
 *
 * Everything NOT listed here (Controllers/, Models/, Resources/, Views/,
 * Lang/*.php translation files, config/, routes/, the sample Services, the
 * Payments regional gateways beyond the sandbox) is app layer: projects own
 * it outright and sync-framework.php never touches it.
 *
 * 'dirs'  => synced recursively — every file under the path, relative
 *            structure preserved, target subdirectories created as needed.
 * 'files' => synced as single files.
 *
 * Paths are relative to the repository root, forward-slash, no leading '/'.
 */
return [
    'dirs' => [
        'app/Core',
        'app/Middleware',
        'app/Storage',
        'app/Console',
        'app/Mail',
        'app/Jobs',
        'app/Entitlements',
        'app/I18n',
        'app/Identity',
        'app/Payments',
        'app/Subscriptions',
        'app/Support',
        'app/Services/I18n',
    ],
    'files' => [
        'app/Services/MigrationsService.php',
        'app/Services/SettingsService.php',
        'public/index.php',
        'setup/scripts/sync-framework.php',
    ],
];
