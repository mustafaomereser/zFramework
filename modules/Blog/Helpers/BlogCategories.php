<?php

namespace modules\Blog\Helpers;

use modules\Blog\Models\Categories;

class BlogCategories
{
    static $categories;
    public static function init()
    {
        self::$categories = new Categories;
    }

    public static function list($parent_id = 0, $mode = 0)
    {
        $categories = self::$categories->where('parent_id', $parent_id)->get();
        foreach ($categories as $key => $category) {
            $input_key = "category-" . $category['id'];
?>
            <li data-parent-id="<?= $key ?>" class="no-select">
                <div class="d-flex gap-2">
                    <div>
                        <?php if ($mode == 0) : ?>
                            <input type="checkbox" class="float-start mt-2 me-2 form-check-input" name="category[<?= $category['id'] ?>]" id="<?= $input_key ?>" value="<?= $category['id'] ?>">
                        <?php else : ?>
                            <div class="dropdown">
                                <i class="fa fa-lg fa-ellipsis-h-alt clickable text-warning dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"></i>
                                <div class="dropdown-menu p-1">
                                    <div class="mb-2">
                                        <a href="javascript:;" class="btn btn-sm btn-success w-100" onclick="addCategory(<?= $category['id'] ?>);">
                                            <i class="fa fa-plus me-2"></i> Add Category
                                        </a>
                                    </div>
                                    <div class="mb-2">
                                        <a href="javascript:;" class="btn btn-sm btn-warning w-100" onclick="editCategory(<?= $category['id'] ?>)">
                                            <i class="fa fa-pencil me-2"></i> Edit Category
                                        </a>
                                    </div>
                                    <div>
                                        <a href="javascript:;" class="btn btn-sm btn-danger w-100" onclick="deleteCategory(<?= $category['id'] ?>)">
                                            <i class="fa fa-trash-alt me-2"></i> Delete Category
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endif ?>
                    </div>
                    <div>
                        <label for="<?= $input_key ?>"><span> <?= $category['title'] ?></span></label>
                        <ul childrens style="list-style-type: none; user-select: none;"><?= self::list($category['id'], $mode) ?></ul>
                    </div>
                </div>
            </li>
<?php
        }
    }
}
