#!/usr/bin/env bash
# The only crontab line the app needs — runs whatever's due this minute
# (App\Console\Schedule::define()). Add to crontab with:
#
#   * * * * * /path/to/project/setup/scripts/cron.sh >> /path/to/project/storage/logs/cron.log 2>&1
#
# Resolves the project root from this script's own location, so it works
# regardless of cron's working directory or how the crontab line is written.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
exec php "$DIR/setup/bin/console" schedule:run
