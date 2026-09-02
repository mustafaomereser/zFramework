<?php

namespace modules\Blog\Controllers\Admin;

use modules\Blog\Models\Blogs;
use modules\Blog\Models\BlogToCategories;
use zFramework\Core\Abstracts\Controller;
use zFramework\Core\Facades\Alerts;
use zFramework\Core\Facades\Auth;
use zFramework\Core\Facades\Str;
use zFramework\Core\Helpers\File;
use zFramework\Core\Validator;

#[\AllowDynamicProperties]
class BlogController extends Controller
{

    public function __construct()
    {
        $this->posts          = new Blogs;
        $this->postToCategory = new BlogToCategories;
    }

    /** Index page | GET: /
     * @return mixed
     */
    public function index()
    {
        $posts = $this->posts->orderBy(['id' => 'DESC'])->paginate();
        return view('blog.views.admin.pages.index', compact('posts'));
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
        return view('blog.views.admin.pages.edit-or-create');
    }

    /** Edit page | GET: /id/edit
     * @param integer $id
     * @return mixed
     */
    public function edit($id)
    {
        $post       = $this->posts->where('id', $id)->firstOrFail('This blog is not exists.');
        $categories = $post['categories']()->get();
        return view('blog.views.admin.pages.edit-or-create', compact('post', 'categories'));
    }

    public function setAll()
    {
        $validate = Validator::validate($_REQUEST, [
            'title'         => ['required'],
            'keywords'      => ['required'],
            'description'   => ['required'],
            'content'       => ['required'],
            'publish'       => ['nullable'],
            'featured_post' => ['nullable'],
        ]);

        $validate['publish']       = $validate['publish'] ? 1 : 0;
        $validate['featured_post'] = $validate['featured_post'] ? 1 : 0;

        $validate['slug']          = Str::slug($validate['title']);

        $validate['user_id']       = Auth::id();

        if (isset($_FILES['image']['name']) && strlen($_FILES['image']['name'])) $validate['image'] = File::upload('/uploads/blog', $_FILES['image']);

        return $validate;
    }

    public function setCategories($id)
    {
        $categories = Validator::validate($_REQUEST, [
            'category' => ['nullable']
        ])['category'];

        $this->postToCategory->where('post_id', $id)->delete();
        foreach ($categories as $category) $this->postToCategory->insert([
            'post_id'     => $id,
            'category_id' => $category
        ]);
    }

    /** POST page | POST: /
     * @return mixed
     */
    public function store()
    {
        $post = $this->posts->insert($this->setAll());
        $this->setCategories($post['id']);
        Alerts::success('Posted.');
        return redirect(route('admin.blog.edit', ['id' => $post['id']]));
    }

    /** Update page | PATCH/PUT: /id
     * @param integer $id
     * @return mixed
     */
    public function update($id)
    {
        $this->posts->where('id', $id)->update($this->setAll());
        $this->setCategories($id);
        Alerts::success('Post updated.');
        return back();
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
