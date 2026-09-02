<?php $duzenle = isset($category['id']) ?>
<div class="modal-body">
    <div class="mb-2">
        <h4><?= $duzenle ? 'Edit Category' : 'Add Category' ?></h4>
    </div>
    <form action="<?= route('admin.blog.categories.' . ($duzenle ? 'update' : 'store'), ['id' => @$category['id']]) ?>" method="POST">
        <?= csrf() . ($duzenle ? inputMethod('PATCH') : null) ?>
        <?php if (!$duzenle) : ?>
            <input type="hidden" name="parent_id" id="parent_id" value="<?= request('parent_id') ?>" required>
        <?php endif ?>
        <div class="form-group mb-2">
            <label for="title">Title</label>
            <input type="text" class="form-control" name="title" id="title" value="<?= @$category['title'] ?>" placeholder="Category Title" required>
        </div>
        <div class="form-group mb-2">
            <label for="description">Description</label>
            <textarea class="form-control" name="description" id="description" placeholder="Category Description"><?= @$category['description'] ?></textarea>
        </div>

        <div class="text-end">
            <button class="btn btn-outline-success"><i class="fa fa-save"></i> Save</button>
        </div>
    </form>
</div>