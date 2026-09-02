<?php

namespace modules\Blog\Controllers\Admin;

use modules\Blog\Helpers\BlogCategories;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Response;
use zFramework\Core\Facades\Str;
use zFramework\Core\Validator;

#[\AllowDynamicProperties]
class BlogCategoriesController extends Controller
{

    public function __construct()
    {
    }

    /** Index page | GET: /
     * @return mixed
     */
    public function index()
    {
        return view('blog.views.admin.pages.categories.index');
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
        return view('blog.views.admin.pages.categories.edit-or-create');
    }

    /** Edit page | GET: /id/edit
     * @param integer $id
     * @return mixed
     */
    public function edit($id)
    {
        $category = BlogCategories::$categories->where('id', $id)->firstOrFail('This category not exists.');
        return view('blog.views.admin.pages.categories.edit-or-create', compact('category'));
    }

    /** POST page | POST: /
     * @return mixed
     */
    public function store()
    {
        $validate = Validator::validate($_REQUEST, [
            'parent_id'   => ['required'],
            'title'       => ['required'],
            'description' => ['required'],
        ]);

        $validate['slug'] = Str::slug($validate['title']);

        BlogCategories::$categories->insert($validate);
        Alerts::success('Category added.');
        return back();
    }

    /** Update page | PATCH/PUT: /id
     * @param integer $id
     * @return mixed
     */
    public function update($id)
    {
        $validate = Validator::validate($_REQUEST, [
            'title'       => ['required'],
            'description' => ['required'],
        ]);

        $validate['slug'] = Str::slug($validate['title']);

        BlogCategories::$categories->where('id', $id)->update($validate);
        Alerts::success('Category edited.');
        return back();
    }

    /** Delete page | DELETE: /id
     * @param integer $id
     * @return mixed
     */
    public function delete($id)
    {
        BlogCategories::$categories->where('id', $id)->delete();
        Alerts::success('Category deleted.');
        return Response::json(['status' => 1]);
    }
}
