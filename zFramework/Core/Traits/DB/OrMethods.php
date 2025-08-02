<?php

namespace zFramework\Core\Traits\DB;

trait OrMethods
{
    /**
     * First a row or fail.
     * @param mixed
     * @return mixed
     */
    public function firstOrFail(mixed $exception = null)
    {
        if ($exception == null) $exception = $this->_not_found;

        $row = $this->first();

        if (!count($row)) {
            if (is_string($exception)) abort(404, $exception);
            if (is_object($exception)) $exception();
        }
        return $row;
    }

    /**
     * Update or insert.
     * @param mixed
     * @return mixed
     */
    public function updateOrInsert(array $sets = [])
    {
        $find = $this->first();
        if (count($find)) $process = $this->update($sets);
        else $process = $this->insert($sets);
        return $process;
    }
}
