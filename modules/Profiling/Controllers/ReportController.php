<?php

namespace Modules\Profiling\Controllers;

use Modules\Profiling\Recorder;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Config;

class ReportController extends Controller
{
    /**
     * Every recorded run, grouped by url.
     *
     * @return string
     */
    public function index()
    {
        return view('Profiling.views.report', [
            'summary'  => Recorder::summary(),
            'recent'   => array_slice(Recorder::all(), 0, 25),
            'enabled'  => (bool) Config::framework('profiling.enabled'),
            'rate'     => Config::framework('profiling.rate'),
            'keep'     => (int) (Config::framework('profiling.keep') ?? 200),
            'total'    => count(Recorder::all()),
        ]);
    }

    /**
     * Throw the records away and start over - after a deploy, when the numbers
     * before it are answering a question about code that is gone.
     *
     * @return mixed
     */
    public function clear()
    {
        Recorder::clear();

        return redirect(route('profiling.index'));
    }
}
