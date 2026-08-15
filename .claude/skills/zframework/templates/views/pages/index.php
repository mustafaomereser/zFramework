@extends('app.main')

{{--
    Copy to resource/views/<app>/pages/<resource>/index.php

    The directory name matches the Route::resource url, so the route() names line up.
    $posts comes from ->paginate(): items, item_count, current_page, links (a callable).
--}}

@section('body')
<div class="my-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h3><?= _l('lang.posts') ?></h3>
        <a href="<?= route('posts.create') ?>" class="btn btn-sm btn-outline-success">
            <i class="fa fa-plus"></i> Add
        </a>
    </div>

    <table class="table">
        <thead>
            <tr>
                <th>#</th>
                <th>Title</th>
                <th class="text-end">Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($posts['items'] as $post) : ?>
                <tr>
                    <td><?= $post['id'] ?></td>
                    <td><?= e($post['title']) ?></td>
                    <td class="text-end">
                        <a href="<?= route('posts.edit', ['id' => $post['id']]) ?>" class="btn btn-sm btn-outline-primary">
                            <i class="fa fa-pen"></i>
                        </a>
                        <button class="btn btn-sm btn-outline-danger" onclick="deletePost(<?= $post['id'] ?>)">
                            <i class="fa fa-trash"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach ?>

            <?php if (!$posts['item_count']) : ?>
                <tr>
                    <td colspan="3" class="text-center">No records yet.</td>
                </tr>
            <?php endif ?>
        </tbody>
    </table>

    <?= $posts['links']() ?>
</div>
@endsection

@section('footer')
<script>
    // DELETE is not a link. Post the spoofed method with a csrf token.
    function deletePost(id) {
        $.ask.do({
            onAccept: () => {
                $.post(`<?= route('posts.delete') ?>`.replace('{id}', id), {
                    _method: 'DELETE',
                    _token: csrf
                }, e => {
                    $.showAlerts(e.alerts);
                    location.reload();
                });
            }
        });
    }
</script>
@endsection
