@extends('app.main')
@section('body')
<?php $editing = isset($post['id']) ?>
<div class="clearfix mb-3 mt-5">
    <div class="float-start">
        <div class="d-flex align-items-center gap-2">
            <?php if ($editing) : ?>
                <h3>Edit Blog</h3>
            <?php else : ?>
                <h3>Add Blog</h3>
            <?php endif ?>
        </div>
    </div>
    <div class="float-end">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <a href="<?= route('admin.blog.index') ?>" class="btn btn-sm btn-outline-light">
                <i class="fa fa-lg fa-arrow-left me-1"></i> Back to list
            </a>
        </div>
    </div>
</div>

<form action="<?= route('admin.blog.' . ($editing ? 'update' : 'store'), ['id' => @$post['id']]) ?>" method="POST" enctype="multipart/form-data">
    <div class="row">
        <div class="col-7">
            <div class="card">
                <div class="card-body">
                    <?= csrf() . ($editing ? inputMethod('PATCH') : null) ?>

                    <div class="form-group mb-2">
                        <label for="title">Title</label>
                        <input type="text" class="form-control" name="title" id="title" placeholder="Title" value="<?= @$post['title'] ?>" required>
                    </div>

                    <div class="mb-2">
                        <div class="title mb-2">
                            <span>Blog Cover Image</span>
                            <?php if ($editing) : ?>
                                <a href="<?= $post['image'] ?>" target="_blank"><i class="far fa-lg fa-eye"></i></a>
                            <?php endif ?>
                        </div>
                        <div class="file-drop-area">
                            <span class="choose-file-button">Choose files</span>
                            <span class="file-message">or drag and drop files here</span>
                            <input class="file-input" type="file" name="image" accept=".png,.jpg,.jpeg,.gif,.webp">
                        </div>
                        <small class="text-muted">Sizes: 1440x810 (recommended)</small>
                    </div>

                    <div class="form-group mb-2">
                        <textarea name="content" id="content" class="form-control" rows="10"><?= @$post['content'] ?></textarea>
                    </div>

                    <div class="d-flex align-items-center justify-content-end gap-2">
                        <?php if ($editing) : ?>
                            <a href="<?= route('blog.show', ['id' => $post['slug']]) ?>" target="_blank" class="btn btn-outline-warning"><i class="fa fa-eye me-1"></i> Preview</a>
                        <?php endif ?>
                        <button type="submit" class="btn btn-outline-success"><i class="fa fa-save me-1"></i> Save</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-5">
            <div class="card mb-2">
                <div class="card-header">
                    <h4>Categories</h4>
                </div>
                <div class="card-body">
                    <div class="overflow-auto mb-2">
                        <div class="tree no-select">
                            <?= Modules\Blog\Helpers\BlogCategories::list() ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-body">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="publish" name="publish" data-check="<?= @$post['publish'] ? 1 : null ?>">
                        <label class="form-check-label" for="publish">Publish</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" role="switch" id="featured_post" name="featured_post" data-check="<?= @$post['featured_post'] ? 1 : null ?>">
                        <label class="form-check-label" for="featured_post">Featured Post</label>
                    </div>

                    <div class="form-group">
                        <label for="description">Description</label>
                        <input type="text" class="form-control" name="description" id="description" value="<?= @$post['description'] ?>" maxlength="200" required>
                    </div>
                    <div class="form-group">
                        <label for="keywords">Keywords</label>
                        <input type="text" class="form-control" name="keywords" id="keywords" value="<?= @$post['keywords'] ?>" placeholder="Post Keywords">
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@section('footer')
<?php if ($editing) : ?>
    <?php foreach ($categories as $category) : ?>
        <script>
            $('#category-<?= $category['category_id'] ?>').prop('checked', true)
        </script>
    <?php endforeach ?>
<?php endif ?>
@endsection