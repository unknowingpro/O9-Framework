<?php
declare(strict_types=1);

/*
 * .env bootstrap — parses the project .env once (idempotent). Server
 * environment variables always take precedence; secrets belong in .env,
 * never in tracked config files.
 */

\App\Core\Env::load(base_path('.env'));
