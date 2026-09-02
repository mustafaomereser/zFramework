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
        # first() runs a query and prepare() ends in reset(), so the where that found
        # the row is gone by the time update() would read it - and an update with no
        # where rewrites the table. The same snapshot paginate() takes: what was built
        # before the lookup is put back for the write, so every row the caller's
        # conditions match is updated, whether or not the table has a primary key.
        $snapshot = $this->buildQuery;

        if (count($this->first())) {
            $this->buildQuery = $snapshot;
            return $this->update($sets);
        }

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
