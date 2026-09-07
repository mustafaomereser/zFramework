<?php

namespace App\Observers;

use zFramework\Core\Abstracts\Observer;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Str;

class UserObserver extends Observer
{
    /**
     * Insert before run that
     */
    public function oninsert(array $sets)
    {
        echo "inserting";

        if (!isset($sets['api_token'])) $sets['api_token'] = Str::rand(60);
        if (isset($sets['username'])) $sets['username'] = ucfirst(strip_tags($sets['username']));
        if (isset($sets['password'])) $sets['password'] = Auth::encodePassword($sets['password']);

        return $sets;
    }

    /**
     * Insert after run that
     */
    public function oninserted(array $args)
    {
        echo "inserted: ";
        print_r($args);
    }

    /**
     * Update before run that
     */
    public function onupdate(array $sets)
    {
        echo "updating:";

        if (isset($sets['username'])) $sets['username'] = ucfirst(strip_tags($sets['username']));
        if (isset($sets['password'])) $sets['password'] = Auth::encodePassword($sets['password']);

        return $sets;
    }

    /**
     * Update after run that
     */
    public function onupdated(array $args)
    {
        echo "updated:";
        var_dump($args);
    }

    /**
     * Delete before run that
     */
    public function ondelete(array $args)
    {
        echo "deleting:";
        var_dump($args);
    }

    /**
     * Delete after run that
     */
    public function ondeleted(array $args)
    {
        echo "deleted:";
        var_dump($args);
    }
}
