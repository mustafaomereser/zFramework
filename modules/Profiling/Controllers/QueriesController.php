<?php

namespace modules\Profiling\Controllers;

use modules\Profiling\Queries;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Config;

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

        return view('Profiling.views.queries', [
            'locked'      => false,
            'summary'     => $summary,
            'suggestions' => Queries::suggestions($summary, $small),
            'hidden'      => count(Queries::suggestions($summary, true)) - count(Queries::suggestions($summary)),
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
