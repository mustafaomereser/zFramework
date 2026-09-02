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
        $row = $this->first();

        # Not $this->update(): first() ran a query and prepare() ends in reset(), so the
        # where that found the row is already gone and the update would rewrite the
        # whole table. The row carries its own update closure, keyed on the primary.
        if (count($row)) return $row['update']($sets);

        return $this->insert($sets);
    }

    /**
     * Find or fail row by primary key
     * @param string $value
     * @return array 
     */
    public function findOrFail(string $value): array
    {
        return $this->where($this->getPrimary(), $value)->firstOrFail();
    }
}
