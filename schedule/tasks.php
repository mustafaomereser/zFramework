<?php

/**
 * Scheduled tasks.
 *
 * Every .php file under schedule/ is loaded, so split this by subject or by
 * module as it grows - the same arrangement route/ has.
 *
 * One crontab line drives all of it:
 *
 *   * * * * * cd /path/to/app && php terminal schedule run >> /dev/null 2>&1
 *
 * `php terminal schedule list` shows what is registered and when each one next
 * runs. `php terminal schedule run` runs whatever is due now, which is also how
 * you test a task without waiting for its hour.
 *
 * A task that is still running from the previous tick is skipped rather than
 * started again, and one that throws is logged and does not stop the others.
 *
 * Only the terminal reads these files, so nothing here costs a served request.
 */

use zFramework\Core\Facades\Schedule;

# Schedule::everyMinute(fn() => ..., 'name');
# Schedule::everyMinutes(5, fn() => ..., 'name');
# Schedule::hourly(15, fn() => ..., 'name');            # every hour at :15
# Schedule::daily('03:00', fn() => ..., 'name');
# Schedule::weekly(1, '09:00', fn() => ..., 'name');    # Mondays, 0 = Sunday
# Schedule::monthly(1, '00:30', fn() => ..., 'name');   # the 1st
# Schedule::cron('*/5 9-17 * * 1-5', fn() => ..., 'name');

# Removes yesterday's page cache entries on a schedule rather than on deploy.
# Delete it once there is something real here.
Schedule::daily('04:00', fn() => \zFramework\Core\Facades\Page::clear(), 'page-cache-nightly');
