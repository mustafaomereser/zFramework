<?php

namespace zFramework\Core\Helpers\cPanel;

/**
 * cPanel cron job management.
 *
 * Through API 2: UAPI has no Cron module, and the `Cron/...` UAPI calls this
 * class used to make were answered with an error every time. Replies come in
 * UAPI's shape all the same (API::api2()).
 */
class Cron
{
    /**
     * List all cron jobs on the account.
     * Each entry in `data`: linekey, command, minute, hour, day, month, weekday, count
     * (count is the job's line number - what delete() takes).
     */
    public static function list(): ?array
    {
        $result = API::api2("Cron", "listcron");

        # API 2 ends the list with a row holding only the total: {"count": 3}.
        if (is_array($result['data'] ?? null)) $result['data'] = array_values(array_filter($result['data'], fn($row) => isset($row['command'])));

        return $result;
    }

    /**
     * Add a new cron job.
     *
     * @param string $time     Cron schedule string in "m h d M wd" format (e.g. "0 * * * *" for every hour).
     * @param string $command  Shell command to execute (e.g. "/usr/bin/php /home/user/public_html/terminal schedule")
     */
    public static function create(string $time, string $command): ?array
    {
        return API::api2("Cron", "add_line", self::schedule($time) + ['command' => $command]);
    }

    /**
     * Edit an existing cron job.
     *
     * @param string $linekey  linekey of the cron job to edit (from list())
     * @param string $time     New cron schedule string in "m h d M wd" format
     * @param string $command  New shell command
     */
    public static function edit(string $linekey, string $time, string $command): ?array
    {
        return API::api2("Cron", "edit_line", ['linekey' => $linekey] + self::schedule($time) + ['command' => $command]);
    }

    /**
     * Delete a cron job. API 2 takes the line number only, not the linekey.
     *
     * @param int $line  `count` of the cron job in list(); the numbers shift after a delete
     */
    public static function delete(int $line): ?array
    {
        return API::api2("Cron", "remove_line", ['line' => $line]);
    }

    /**
     * "m h d M wd" as the five fields API 2 asks for.
     * @param string $time
     * @return array{minute: string, hour: string, day: string, month: string, weekday: string}
     */
    private static function schedule(string $time): array
    {
        $fields = preg_split('/\s+/', trim($time));
        if (count($fields) !== 5) throw new \InvalidArgumentException("Cron schedule must be five fields \"m h d M wd\", got \"$time\".");

        return array_combine(['minute', 'hour', 'day', 'month', 'weekday'], $fields);
    }
}
