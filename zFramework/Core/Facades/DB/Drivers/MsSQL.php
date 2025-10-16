<?php

namespace zFramework\Core\Facades\DB\Drivers;

class MsSQL
{
    protected $parent;
    public function __construct($parent)
    {
        $this->parent = $parent;
    }


    /**
     * Build SQL
     * @param string $type
     * @return string
     */
    public function build(string $type): string
    {
        print_r($type);
        print_r($this->parent->buildQuery);

        return "";
    }
}
