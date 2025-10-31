<?php

namespace zFramework\Core\Helpers\cPanel;

class Cron
{
    public static function list(): ?array
    {
        return API::request("Cron/listcron");
    }

    public static function create(string $time, string $command): ?array
    {
        return API::request("Cron/add_line", [
            "command" => $command,
            "linekey" => $time
        ]);
    }

    public static function delete(int $lineKey): ?array
    {
        return API::request("Cron/remove_line", compact('lineKey'));
    }
}
