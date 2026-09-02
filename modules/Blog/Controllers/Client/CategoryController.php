<?php

namespace modules\Blog\Controllers\Client;

use modules\Blog\Models\Categories;
use zFramework\Core\Abstracts\Controller;

#[\AllowDynamicProperties]
class CategoryController extends Controller
{


    public function __construct()
    {
        $this->category = new Categories;
    }

    /** Index page | GET: /
     * @return mixed
     */
    public function index()
    {
        abort(404);
    }

    /** Show page | GET: /id
     * @param integer $id
     * @return mixed
     */
    public function show($id)
    {
        $category = $this->category->where('slug', $id)->firstOrFail('This category not exists.');
        $posts    = $category['posts']()->paginate();
        return view('blog.views.client.pages.categories.show', compact('category', 'posts'));
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
     * @param integer $id
     * @return mixed
     */
    public function update($id)
    {
        abort(404);
    }

    /** Delete page | DELETE: /id
     * @param integer $id
     * @return mixed
     */
    public function delete($id)
    {
        abort(404);
    }
}
