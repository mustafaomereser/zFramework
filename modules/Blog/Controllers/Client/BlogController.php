<?php

namespace modules\Blog\Controllers\Client;

use modules\Blog\Models\Blogs;
use modules\Blog\Models\Categories;
use zFramework\Core\Abstracts\Controller;

#[\AllowDynamicProperties]
class BlogController extends Controller
{

    public function __construct()
    {
        $this->posts      = new Blogs;
        $this->categories = new Categories;
    }

    /** Index page | GET: /
     * @return mixed
     */
    public function index()
    {
        $title          = 'Blog & News';
        $posts          = $this->posts->where('publish', 1)->where('featured_post', 0)->orderBy(['id' => 'DESC'])->paginate();
        $featured_posts = $this->posts->where('publish', 1)->where('featured_post', 1)->orderBy(['updated_at' => 'DESC'])->get();
        return view('Blog.views.client.pages.index', compact('title', 'posts', 'featured_posts'));
    }

    /** Show page | GET: /id
     * @param integer $id
     * @return mixed
     */
    public function show($id)
    {
        $post       = $this->posts->where('slug', $id);
        $post       = $post->firstOrFail('This blog is not exists');
        $author     = $post['author']();
        $categories = $post['categories']()->get();

        return view('Blog.views.client.pages.show', compact('post', 'author', 'categories'));
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
