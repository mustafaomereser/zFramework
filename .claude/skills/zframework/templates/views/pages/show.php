@extends('app.main')

{{-- Copy to resource/views/<app>/pages/<resource>/show.php --}}

@section('body')
<div class="my-4">
    <h3><?= e($post['title']) ?></h3>

    <small class="text-muted">
        <?= $post['author']()['username'] ?? '-' ?> &middot;
        <?= \zFramework\Core\Helpers\Date::format($post['created_at'], "F j, Y, g:i a") ?>
    </small>

    <div class="mt-3">
        <?= nl2br(e($post['content'])) ?>
    </div>

    <div class="mt-4">
        <a href="<?= route('posts.index') ?>">&larr; Back</a>
    </div>
</div>
@endsection
