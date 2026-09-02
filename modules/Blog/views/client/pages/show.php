@extends('app.main')
@section('header')
<style>
    img {
        width: 100%;
        height: auto;
        border-radius: 5px;
    }
</style>
@endsection

@section('body')
<div class="container my-5">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?= route('blog.index') ?>">Blog</a></li>
            <?php foreach ($categories as $category) : $category = $category['category'](); ?>
                <li class="breadcrumb-item"><a href="<?= route('blog.categories.show', ['id' => $category['slug']]) ?>"><?= $category['title'] ?></a></li>
            <?php endforeach ?>
            <li class="breadcrumb-item active" aria-current="page"><?= $post['title'] ?></li>
        </ol>
    </nav>

    <div class="mb-2">
        <h1><?= $post['title'] ?></h1>
    </div>

    <div class="mb-3">
        <img src="<?= $post['image'] ?>" class="rounded w-100" alt="Blog item">
    </div>

    <div class="d-flex align-items-center gap-2 mb-3 text-muted">
        <div>
            <i class="fad fa-user-tie"></i> By <?= $author['username'] ?>
        </div>
        <span>•</span>
        <div>
            <i class="fad fa-calendar-alt"></i> <?= \zFramework\Core\Helpers\Date::format($post['created_at'], "F j, Y, g:i a") ?>
        </div>
    </div>

    <div class="mt-3">
        <div class="bg-theme-3 border-0 rounded-4 card">
            <div class="card-body">
                <?= $post['content'] ?>
            </div>
        </div>
    </div>

    <hr />
    <div class="mb-3">
        <div class="clearfix">
            <div class="float-start">
                <div class="d-flex align-items-center gap-2">
                    <button class="btn border btn-dark"><i class="fab fa-facebook me-1"></i> Share on Facebook</button>
                    <button class="btn border btn-dark"><i class="fab fa-twitter me-1"></i> Tweet Post on Twitter</button>
                </div>
            </div>
            <div class="float-end">
                <button class="btn border btn-dark"><i class="fad fa-link me-1"></i> Copy Link</button>
            </div>
        </div>
    </div>

    <div class="card bg-theme-2 border-0">
        <div class="card-body">
            <?php if (!empty($author['description'])) : ?>
                <div class="mb-3" style="font-size: 13pt">
                    <span>“<?= $author['description'] ?>”</span>
                </div>
            <?php endif ?>
            <div class="clearfix">
                <div class="float-start">
                    <div class="d-flex align-items-center gap-2">
                        <div style="line-height: 15px;">
                            <div><?= $author['username'] ?></div>
                            <small class="text-muted">Author</small>
                        </div>
                    </div>
                </div>
                <div class="float-end">
                    <div class="d-flex align-items-center gap-2">
                        <a class="btn btn-dark" data-toggle="tooltip" title="Facebook"><i class="fab fa-facebook"></i></a>
                        <a class="btn btn-dark" data-toggle="tooltip" title="Instagram"><i class="fab fa-instagram"></i></a>
                        <a class="btn btn-dark" data-toggle="tooltip" title="Twitter"><i class="fab fa-twitter"></i></a>
                        <a class="btn btn-dark" data-toggle="tooltip" title="Youtube"><i class="fab fa-youtube"></i></a>
                        <a class="btn btn-dark" data-toggle="tooltip" title="Tiktok"><i class="fab fa-tiktok"></i></a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection