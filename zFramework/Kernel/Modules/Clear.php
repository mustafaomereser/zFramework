<?php

namespace zFramework\Kernel\Modules;

use zFramework\Kernel\Terminal;

class Clear
{
    /**
     * Clear the terminal.
     */
    public static function begin()
    {
        Terminal::clear();
    }
}
