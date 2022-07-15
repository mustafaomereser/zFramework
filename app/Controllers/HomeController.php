<?php

namespace App\Controllers;

use App\Models\User;
use zFramework\Core\Abstracts\Controller;

class HomeController extends Controller
{

    public function __construct()
    {
        //
    }

    /** Index page | GET: /
     * @return mixed
     */
    public function index()
    {
        echo "<pre>";
        $users = new User;
        $users = $users->get();
        print_r($users);
        echo "</pre>";
        
        // return view('welcome');
    }

    /** Show page | GET: /id
     * @param integer $id
     * @return mixed
     */
    public function show($id)
    {
        abort(404);
    }

    /** Create page | GET: /create
     * @return mixed
     */
    public function create()
    {
        abort(404);
    }

    /** Edit page | GET: /id/edit
     * @param integer $id
     * @return mixed
     */
    public function edit($id)
    {
        abort(404);
    }

    /** POST page | POST: /
     * @return mixed
     */
    public function store()
    {
        abort(404);
    }

    /** Update page | PATCH/PUT: /id
     * @return mixed
     */
    public function update($id)
    {
        abort(404);
    }

    /** Delete page | DELETE: /id
     * @return mixed
     */
    public function delete($id)
    {
        abort(404);
    }
}
