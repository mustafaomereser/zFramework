@extends('app.main')
@section('body')
<div class="my-5">
    <div class="text-center mb-4">
        <h1><?= _l('lang.welcome') ?></h1>
    </div>
    <div class="card rounded-0">
        <pre class="card-body" style="height: 500px; overflow-y: auto;" id="terminal-body">you can read for more information documention.</pre>
    </div>
    <div class="form-group">
        <form id="terminal-form">
            <?= csrf() ?>
            <input type="text" name="command" class="form-control rounded-0" placeholder="Command to Helper Terminal.">
        </form>
    </div>
</div>
@endsection

@section('footer')
<script>
    $('#terminal-form').sbmt((form, btn) => {
        $('[name="command"]').attr('disabled', 'true').addClass('disabled');
        $('#terminal-body').html(`<div class="d-flex align-items-center justify-content-center h-100 w-100"><div><i class="fa fa-spin fa-spinner me-2"></i> <?= _l('lang.loading') ?></div></div>`);
        $.post('<?= route("store") ?>', $.core.SToA(form), e => ($('[name="command"]').removeAttr('disabled').removeClass('disabled').val(null).focus(), $('#terminal-body').html(e).scrollTop(99999999999))).error_callback = e => $('#terminal-body').html(JSON.parse(e.responseText).message);
    }).trigger('submit');
</script>
@endsection