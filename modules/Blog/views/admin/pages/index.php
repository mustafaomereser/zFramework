@extends('app.main')

@section('body')
<div class="container my-5">
    <div class="header clearfix mb-3">
        <div class="float-start">
            <h3 class="fw-bold">Blogs</h3>
        </div>
        <div class="float-end">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <a href="<?= route('admin.blog.categories.index') ?>" class="btn btn-sm btn-outline-primary">
                    <i class="fa fa-list me-2"></i>
                    <span>Categories</span>
                </a>
                <a href="<?= route('admin.blog.create') ?>" class="btn btn-sm btn-outline-success">
                    <i class="fa fa-plus me-2"></i>
                    <span>Add Blog</span>
                </a>
            </div>
        </div>
    </div>

    <table class="table table-bordered">
        <thead>
            <tr>
                <th width="10">#</th>
                <th>Title</th>
                <th>Author</th>
                <th>Publish</th>
                <th>Featured Post</th>
                <th>Created At</th>
                <th>Updated At</th>
                <th width="10"></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($posts['items'] as $post) : $author = $post['author'](); ?>
                <tr>
                    <td><?= $posts['start']++ ?></td>
                    <td><?= $post['title'] ?></td>
                    <td><?= $author['username'] ?></td>
                    <td><?= _l('blog.status.' . $post['publish']) ?></td>
                    <td><?= _l('blog.status.' . $post['featured_post']) ?></td>
                    <td><?= \zFramework\Core\Helpers\Date::format($post['created_at']) ?></td>
                    <td><?= \zFramework\Core\Helpers\Date::format($post['updated_at']) ?></td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <a href="<?= route('admin.blog.edit', ['id' => $post['id']]) ?>" class="text-warning"><i class="fa fa-lg fa-pencil"></i></a>
                        </div>
                    </td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>
    <div class="text-center my-2">
        <?= $posts['links']() ?>
    </div>
</div>
@endsection

@section('footer')

@endsection