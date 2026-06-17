<?php

namespace zFramework\Core\Abstracts;

abstract class Observer
{
    /**
     * Observer Router to methods
     * @param string $function
     * @param array $args
     * @return self|void
     */
    public function router(string $function, mixed $args = [])
    {
        $call = "on$function";
        if ($call && method_exists($this, $call)) return $this->$call($args);
    }
}
