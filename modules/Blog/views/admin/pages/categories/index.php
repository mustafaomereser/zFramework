@extends('app.main')

@section('body')
<div class="clearfix mb-3 mt-5">
    <div class="float-start">
        <div class="d-flex align-items-center gap-2">
            <h3>Blog Categories</h3>
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

<div class="list-content">
    <div class="mb-2">
        <button class="btn btn-outline-success" onclick="addCategory(0)"><i class="fa fa-plus"></i> Add Category</button>
    </div>
    <div class="tree no-select">
        <?= modules\Blog\Helpers\BlogCategories::list(0, 1) ?>
    </div>
</div>
@endsection

@section('footer')
<script>
    function addCategory(parent_id = 0) {
        loadModal(`<?= route('admin.blog.categories.create') ?>?parent_id=${parent_id}`)
    }

    function editCategory(id = 0) {
        loadModal(`<?= route('admin.blog.categories.edit') ?>`.replace('{id}', id));
    }

    function deleteCategory(id = 0) {
        $.ask.do({
            onAccept: () => {
                $.post(`<?= route('admin.blog.categories.delete') ?>`.replace('{id}', id), {
                    _method: 'DELETE',
                    _token: csrf
                }, e => {
                    $.showAlerts(e.alerts);
                    location.reload();
                })
            }
        });
    }
</script>
@endsection