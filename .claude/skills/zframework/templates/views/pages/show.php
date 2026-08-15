@extends('app.main')

{{-- Copy to resource/views/<app>/pages/<resource>/show.php --}}

@section('body')
<div class="my-4">
    <h3><?= e($post['title']) ?></h3>

    <small class="text-muted">
        {{-- A relation is a callable on the row: $post['author']() runs the query.
             Only after the model declares it — calling one that is not declared is a
             fatal, so this line stays commented until Post::author() exists. --}}
        <?php // echo $post['author']()['username'] ?? '-';
        ?>
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
