<?php

namespace modules\Profiling\Controllers;

use modules\Profiling\Queries;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Config;
use zFramework\Core\Facades\Session;

class QueriesController extends Controller
{
    /**
     * What the analyzer recorded, grouped by query shape.
     *
     * Only with debug on. This page says which tables exist, what is queried and from
     * where, and /profiling has no login: records left from a staging run must not
     * be readable once debug is off.
     *
     * @return string
     */
    public function index()
    {
        if (!Config::debug()) return view('Profiling.views.queries', ['locked' => true]);

        $error = null;
        try {
            $records = Queries::all();
        } catch (\Throwable $e) {
            $records = [];
            $error   = $e->getMessage();
        }

        $summary = Queries::summary($records);
        $small   = isset($_GET['small']);
        $every   = Queries::suggestions($summary, true);

        $applied = Session::get('profiling-applied') ?? [];
        Session::delete('profiling-applied');

        return view('Profiling.views.queries', [
            'locked'      => false,
            'summary'     => $summary,
            'suggestions' => $small ? $every : array_filter($every, fn($suggestion) => !$suggestion['small']),
            'hidden'      => count(array_filter($every, fn($suggestion) => $suggestion['small'])),
            'applied'     => $applied,
            'small'       => $small,
            'records'     => count($records),
            'limit'       => Queries::LIMIT,
            'store'       => Queries::store(),
            'analyzing'   => Queries::analyzing(),
            'full'        => Queries::full(),
            'keep'        => (int) (Config::framework('profiling.keep') ?? 200),
            'error'       => $error,
        ]);
    }

    /**
     * Act on the suggestions ticked on the page: write them to their migrations, or
     * create them now.
     *
     * @return mixed
     */
    public function apply()
    {
        if (Config::debug()) {
            $statements = array_filter((array) ($_POST['statements'] ?? []), 'is_string');
            Session::set('profiling-applied', Queries::apply($statements, ($_POST['how'] ?? '') === 'now' ? 'now' : 'migration'));
        }

        return redirect(route('profiling.queries'));
    }

    /**
     * Start over - after adding the indexes, when what is recorded describes a
     * database that is gone.
     *
     * @return mixed
     */
    public function clear()
    {
        if (Config::debug()) Queries::clear();

        return redirect(route('profiling.queries'));
    }
}
