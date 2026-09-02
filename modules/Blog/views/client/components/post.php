<?php $author = $post['author']() ?>
<div class="card mb-2">
    <div class="card-body">
        <div class="row align-items-center">
            <div class="col-md-2 col-12 mb-2">
                <img src="<?= $post['image'] ?>" style="object-fit: cover" class="w-100 rounded" alt="Blog item">
            </div>
            <div class="col-md-10 col-12">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <small class="text-muted"><?= \zFramework\Core\Helpers\Date::format($post['created_at'], "F j, Y, g:i a") ?></small>
                    <?php foreach ($post['categories']()->get() as $category) : $category = $category['category'](); ?>
                        <a href="<?= route('blog.categories.show', ['id' => $category['slug']]) ?>" class="badge border"><?= $category['title'] ?></a>
                    <?php endforeach ?>
                </div>

                <a href="<?= route('blog.show', ['id' => $post['slug']]) ?>" class="text-light text-decoration-none">
                    <div class="title mb-3"><?= $post['title'] ?></div>
                    <div class="content text-muted">
                        <?= \zFramework\Core\Facades\Str::limit(strip_tags($post['content']), 50) ?>
                    </div>
                </a>

                <div class="divider my-2"></div>

                <div class="row align-items-center">
                    <div class="col-6">
                        <div class="d-flex align-items-center gap-2">
                            <div style="line-height: 15px;">
                                <div class="mb-1"><?= $author['username'] ?></div>
                                <small class="text-muted">Author</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-6 text-end">
                        <a href="<?= route('blog.show', ['id' => $post['slug']]) ?>">Read full article →</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>