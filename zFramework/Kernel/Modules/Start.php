<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;

class Start
{
    public static function begin()
    {
        Terminal::text("[color=red]Terminal | v" . FRAMEWORK_VERSION . "
        ______                                             __  
 ____  / ____/________ _____ ___  ___ _      ______  _____/ /__
/_  / / /_  / ___/ __ `/ __ `__ \/ _ \ | /| / / __ \/ ___/ //_/
 / /_/ __/ / /  / /_/ / / / / / /  __/ |/ |/ / /_/ / /  / ,<   
/___/_/   /_/   \__,_/_/ /_/ /_/\___/|__/|__/\____/_/  /_/|_|  
[/color]");
        Terminal::text('Do you need help? Type just "help".');
        echo PHP_EOL;
        Terminal::text('e.g. use module: db migrate --fresh --seed');
    }
}
