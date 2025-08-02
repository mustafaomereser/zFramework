@extends('app.main')
@section('body')
<div class="container my-5">
    <div class="text-center">
        <h1><?= $category['title'] ?></h1>
        <small><?= $category['description'] ?></small>
    </div>

    <?php if (count($posts['items'])) : ?>
        <div style="margin-top: 100px;">
            <div class="mb-2">
                <h4>Category's Posts</h4>
            </div>
            <div class="row">
                <div class="col-md-8 col-12 mb-2">
                    <?php foreach ($posts['items'] as $post) :
                        $post   = $post['post']();
                        echo view('blog.views.client.components.post', compact('post'))
                    ?>
                    <?php endforeach ?>
                    <div class="mt-4">
                        <?= $posts['links']() ?>
                    </div>
                </div>
                <div class="col-md-4 col-12">
                    <?= view('blog.views.client.components.news-letter') ?>
                </div>
            </div>
        </div>
    <?php endif ?>
</div>
<div class="page-space"></div>
@endsection