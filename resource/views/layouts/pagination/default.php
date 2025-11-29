<ul class="pagination pagination-separated pagination-sm mb-0 justify-content-center">
    <li class="page-item <?= $current_page == 1 ? 'disabled' : null ?>">
        <a href="<?= str_replace("change_page_$uniqueID", 1, $url) ?>" class="page-link"><i class="fa fa-angle-double-left"></i></a>
    </li>

    <li class="page-item <?= $current_page == 1 ? 'disabled' : null ?>">
        <a href="<?= str_replace("change_page_$uniqueID", ($current_page - 1), $url) ?>" class="page-link"><i class="fa fa-angle-left"></i></a>
    </li>

    <?php foreach ($pages as $key => $page) : ?>
        <?php if ($page['type'] == 'page') : ?>
            <li class="page-item <?= $page['current'] ? 'active' : null ?>">
                <a class="page-link" href="<?= $page['url'] ?>">
                    <?= $page['page'] ?>
                </a>
            </li>
        <?php elseif ($page['type'] == 'dot') : ?>
            <li><span>...</span></li>
        <?php endif ?>
    <?php endforeach ?>

    <li class="page-item <?= $current_page == $page_count ? 'disabled' : null ?>">
        <a href="<?= str_replace("change_page_$uniqueID", ($current_page + 1), $url) ?>" class="page-link"><i class="fa fa-angle-right"></i></a>
    </li>

    <li class="page-item <?= $current_page == $page_count  ? 'disabled' : null ?>">
        <a href="<?= str_replace("change_page_$uniqueID", $page_count, $url) ?>" class="page-link"><i class="fa fa-angle-double-right"></i></a>
    </li>
</ul>