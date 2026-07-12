# Migrating a project onto O9

This guide is for pulling an existing project's bespoke `App\Core\*` (and
friends) onto the canonical O9 tree in this repo, and for keeping it in sync
afterwards. It applies to any project already using the `App\` namespace and
the `{ok,data,error,meta}` response convention — in practice, the three
source applications this framework was extracted from.

Order matters: migrate the project with the most drift first, so the sync
tooling and the fix-up checklist below are proven before touching the two
closer-to-canonical projects. Do one project at a time — branch, migrate,
test, deploy, burn in — before starting the next.

## What gets synced, and what doesn't

`setup/framework-manifest.php` is the sync contract: it lists every
framework-owned path in this repo. `setup/scripts/sync-framework.php` reads
it and copies exactly those paths into a target project, byte-for-byte.

Framework-owned (synced): `app/Core/**`, `app/Middleware/**`,
`app/Storage/**`, `app/Console/**`, `app/Mail/**`, `app/Jobs/**`,
`app/Entitlements/**`, `app/I18n/**`, `app/Identity/**`, `app/Payments/**`
(contracts, DTOs, factory, sandbox gateway — regional gateways stay
project-owned until they've generalized enough to promote here),
`app/Subscriptions/**`, `app/Support/**`, `app/Services/I18n/**`,
`app/Services/MigrationsService.php`, `app/Services/SettingsService.php`,
`public/index.php`, and `setup/scripts/sync-framework.php` itself.

Everything else — `app/Controllers/**`, `app/Models/**`, `app/Resources/**`,
`app/Views/**`, `app/Lang/*.php` translation files, `config/**`,
`routes/**`, and any project-specific service or domain code (gym's
`Nutrition/` math, for example) — is project layer. The sync script never
touches it. After a sync, a project's `app/Core` etc. should be byte-identical
to phpframe's; everything outside the manifest is the project's own.

Once a project is on O9, you fix bugs and add features in phpframe first,
then re-run the sync script in each project to pick up the change — never
patch a manifest path inside a project directly, since the next sync would
silently overwrite the patch.

## Steps

1. **Branch.** Create a migration branch in the target project.

2. **Dry-run the sync** to see the size of the change before touching
   anything:

   ```
   php /path/to/phpframe/setup/scripts/sync-framework.php /path/to/project --dry-run --diff
   ```

   `--diff` prints a unified diff for every file that would be updated (new
   files just print as `CREATE`, nothing to diff yet). Skim it — this is
   where you'll find the drifted method signatures you need to fix call
   sites for.

3. **Run the sync for real:**

   ```
   php /path/to/phpframe/setup/scripts/sync-framework.php /path/to/project
   ```

4. **Fix call sites** where the project's drifted copy of a class differed
   from the canonical one. How much work this is depends entirely on how far
   the project's own `Core/` had drifted:
   - Projects whose core was already close to the "which-copy-wins" source
     for most files need close to no changes.
   - A project with a substantially rewritten core needs its call sites
     walked file by file — run `composer stan` and `composer test` after the
     sync and work through the resulting errors; they're a reliable map of
     every signature that changed.

5. **Regroup controllers into `Admin/ Api/ Bot/ Web/`** if the project
   doesn't already use that layout (e.g. flat controllers, or route-prefix
   folders like `Me/`, `Auth/`). Move each controller into the surface it
   actually serves — an authenticated user-facing endpoint goes to `Api/`,
   an HTML admin panel controller to `Admin/`, a Telegram bot handler to
   `Bot/`, and so on — then update `routes/*.php` to match. This step is
   specific to whichever project didn't already have the surface layout;
   skip it for a project that's already grouped this way.

6. **Delete project Core extras that the framework now covers**, and move
   anything left over that's genuinely project-specific (domain math, a
   one-off integration) out of `Core/` and into its own directory — it was
   never framework code, it was just living there.

7. **Verify:** `composer test`, `composer stan`, then a manual smoke pass
   through the app's main flows (the automated suite can't exercise routes
   whose controllers respond via `Response::*`, since those methods `exit`
   the process — see the framework's own test suite for the same
   constraint).

8. **Deploy to staging, burn in, then to production.** Rollback is reverting
   the migration branch — the sync only ever touched manifest paths, so a
   revert restores the project's prior `Core/` exactly.

## Keeping a migrated project in sync later

Once a project is migrated, pulling a later phpframe fix or feature is just
step 2 and 3 above run again, plus step 4 if the change touched a signature
the project calls directly. Steps 1, 5, 6, 7 and 8 still apply — branch,
verify, deploy — but there's no more regrouping or extra-deletion work to do
once a project is already aligned.
