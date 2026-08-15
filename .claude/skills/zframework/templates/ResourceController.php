<?php

/**
 * Resource controller skeleton.
 *
 * DO NOT hand-write this file — run:
 *     php terminal make controller PostController --resource
 * which emits the same shape from zFramework/Kernel/Includes/make/Controller_resource.
 * This copy exists so you can see what a filled-in one looks like.
 *
 * Rules this file demonstrates, all of them load-bearing:
 *
 *  - The ONLY base class is zFramework\Core\Abstracts\Controller, and it is empty by design.
 *    Do not invent AbstractCrudController, do not add an interface, do not build a shared
 *    CRUD parent. Nothing is inherited and there is no container.
 *  - Method names are fixed by Route::resource: index, show, create, edit, store, update,
 *    delete. It is delete(), NOT destroy().
 *  - __construct() is where models get instantiated, on $this. It also receives the name of
 *    the method about to run as a string argument, which you may accept or ignore.
 *  - A type-hinted Request parameter is instantiated automatically with a bare `new`.
 *    Route parameters arrive as ordinary arguments, in url order, before it.
 *  - create and edit render the SAME view, edit-or-create.
 */

namespace App\Controllers;

use App\Models\Post;
use App\Requests\Post\StoreRequest;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Response;

#[\AllowDynamicProperties]
class PostController extends Controller
{
    public function __construct()
    {
        $this->posts = new Post;
    }

    /** Index page | GET: / */
    public function index()
    {
        $posts = $this->posts->orderBy(['id' => 'DESC'])->paginate();

        return view('app.pages.posts.index', compact('posts'));
    }

    /** Show page | GET: /id */
    public function show($id)
    {
        $post = $this->posts->where('id', $id)->firstOrFail('This post does not exist.');

        return view('app.pages.posts.show', compact('post'));
    }

    /** Create page | GET: /create */
    public function create()
    {
        return view('app.pages.posts.edit-or-create');
    }

    /** Edit page | GET: /id/edit */
    public function edit($id)
    {
        $post = $this->posts->where('id', $id)->firstOrFail('This post does not exist.');

        return view('app.pages.posts.edit-or-create', compact('post'));
    }

    /** POST page | POST: / */
    public function store(StoreRequest $request)
    {
        $post = $this->posts->insert($request->validated());

        Alerts::success('Posted.');
        return redirect(route('posts.edit', ['id' => $post['id']]));
    }

    /** Update page | PATCH/PUT: /id */
    public function update($id, StoreRequest $request)
    {
        $this->posts->where('id', $id)->update($request->validated());

        Alerts::success('Post updated.');
        return back();
    }

    /** Delete page | DELETE: /id */
    public function delete($id)
    {
        $this->posts->where('id', $id)->delete();

        Alerts::success('Post deleted.');
        return Response::json(['alerts' => Alerts::get()]);
    }
}
