@extends('app.main')

<?php

/**
 * Copy to resource/views/<app>/pages/<resource>/edit-or-create.php
 *
 * ONE file serves both create and edit — do not split it into create.php + edit.php.
 * The controller passes $post on edit and passes nothing on create, so every field reads
 * through ?? and the form flips its action and method off $editing.
 */

$editing = isset($post['id']);
?>

@section('body')
<div class="my-4">
    <h3><?= $editing ? 'Edit Post' : 'Add Post' ?></h3>

    <form action="<?= route('posts.' . ($editing ? 'update' : 'store'), ['id' => $post['id'] ?? null]) ?>" method="POST">
        <?= csrf() ?>
        <?= $editing ? inputMethod('PATCH') : null ?>

        <div class="form-group mb-2">
            <label for="title">Title</label>
            <input type="text" class="form-control" name="title" id="title"
                value="<?= e($post['title'] ?? '') ?>" required>
        </div>

        <div class="form-group mb-2">
            <label for="content">Content</label>
            <textarea class="form-control" name="content" id="content"><?= e($post['content'] ?? '') ?></textarea>
        </div>

        <div class="form-group mb-2">
            <label for="category_id">Category</label>
            <select class="form-control" name="category_id" id="category_id">
                <?php foreach ($categories ?? [] as $category) : ?>
                    <option value="<?= $category['id'] ?>" <?= ($post['category_id'] ?? null) == $category['id'] ? 'selected' : '' ?>>
                        <?= e($category['title']) ?>
                    </option>
                <?php endforeach ?>
            </select>
        </div>

        <div class="text-end">
            <button class="btn btn-outline-success"><i class="fa fa-save"></i> Save</button>
        </div>
    </form>
</div>
@endsection
