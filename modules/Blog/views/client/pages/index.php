@extends('app.main')
@section('body')
<div class="container my-5">
    <div class="text-center">
        <h1><?= $title ?></h1>
        <small>Your ultimate destination for the latest gaming news, useful tips, and comprehensive guides.</small>
    </div>

    <?php if (count($featured_posts)) : ?>
        <div style="margin-top: 100px;">
            <div class="mb-2">
                <h4>Featured Posts</h4>
            </div>
            <div>
                <div class="row">
                    <?php foreach ($featured_posts as $key => $post) : $author = $post['author']() ?>
                        <div class="col-md-4 col-12 mb-2">
                            <div class="blog-item-2" style="--scale: <?= $key == 1 ? 1 : .9 ?>">
                                <a href="<?= route('blog.show', ['id' => $post['slug']]) ?>" class="text-light">
                                    <img src="<?= $post['image'] ?>" class="image" alt="<?= $post['title'] ?>">
                                    <div class="background"></div>
                                    <div class="content">
                                        <div class="d-flex align-items-center gap-2 mb-2">
                                            <small class="text-muted"><?= \zFramework\Core\Helpers\Date::format($post['created_at'], "F j, Y, g:i a") ?></small>
                                            <span>•</span>
                                            <div class="d-flex align-items-center gap-2">
                                                <div style="line-height: 15px;">
                                                    <div><?= $author['username'] ?></div>
                                                    <small class="text-muted">Author</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="text-break">
                                            <h5><?= $post['title'] ?></h5>
                                        </div>
                                    </div>
                                </a>
                            </div>
                        </div>
                    <?php endforeach ?>
                </div>
            </div>
        </div>
    <?php endif ?>

    <?php if (count($posts['items'])) : ?>
        <div style="margin-top: 100px;">
            <div class="mb-2">
                <h4>Latest Posts</h4>
            </div>
            <div class="row">
                <div class="col-md-8 col-12 mb-2">
                    <?php foreach ($posts['items'] as $post) echo view('blog.views.client.components.post', compact('post')) ?>
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
@endsection