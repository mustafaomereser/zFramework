<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;

class Clear
{
    public static function begin()
    {
        Terminal::clear();
    }
}
