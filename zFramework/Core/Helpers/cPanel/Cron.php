<?php

namespace zFramework\Core\Helpers\cPanel;

/**
 * cPanel cron job management via UAPI.
 */
class Cron
{
    /**
     * List all cron jobs on the account.
     * Each entry contains: linekey, command, minute, hour, day, month, weekday.
     */
    public static function list(): ?array
    {
        return API::request("Cron/listcron");
    }

    /**
     * Add a new cron job.
     *
     * @param string $time     Cron schedule string in "m h d M wd" format (e.g. "0 * * * *" for every hour).
     *                         Passed as the linekey field to the UAPI.
     * @param string $command  Shell command to execute (e.g. "/usr/bin/php /home/user/public_html/artisan schedule:run")
     */
    public static function create(string $time, string $command): ?array
    {
        return API::request("Cron/add_line", [
            "command" => $command,
            "linekey" => $time
        ]);
    }

    /**
     * Edit an existing cron job.
     *
     * @param int    $lineKey  Line key of the cron job to edit (from list() response)
     * @param string $time     New cron schedule string in "m h d M wd" format
     * @param string $command  New shell command
     */
    public static function edit(int $lineKey, string $time, string $command): ?array
    {
        return API::request("Cron/edit_line", [
            "linekey" => $lineKey,
            "newlinekey" => $time,
            "command" => $command
        ]);
    }

    /**
     * Delete a cron job by its line key.
     *
     * @param int $lineKey  Line key of the cron job to delete (from list() response)
     */
    public static function delete(int $lineKey): ?array
    {
        return API::request("Cron/remove_line", compact('lineKey'));
    }
}
