<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;
use ZipArchive;

class Update
{
    public static function begin()
    {
        Terminal::text("[color=yellow]downloading...[/color]");
        $download = base_path() . "/update-zFramework-main.zip";
        file_put_contents($download, file_get_contents("https://github.com/mustafaomereser/zFramework/archive/refs/heads/main.zip"));
        Terminal::text("[color=green]downloaded.[/color]");


        Terminal::text("[color=yellow]unzipping...[/color]");
        $zip = new ZipArchive;
        $zip->open($download);
        $folder_name = rtrim($zip->getNameIndex(0), '/');
        $zip->extractTo(base_path());
        $zip->close();
        unlink($download);
        Terminal::text("[color=green]unzipped.[/color]");


        // echo base_path($folder_name . "\\zFramework\\Core");
    }
}
