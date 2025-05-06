<script>
    currentModal.find('.modal-title').addClass('w-100').css('font-size', '.9rem');
    currentModal.find('.modal-header').css('padding', '10px');
    modalTitle(currentModal, `
        <div class="nav nav-pills gap-2" role="tablist">
            <div class="nav-item" style="flex: auto;" role="presentation">
                <button class="nav-link active w-100" data-bs-toggle="pill" data-bs-target="#tab-signin" type="button"
                    role="tab" aria-selected="true">
                    {{ _l('lang.signin') }}
                </button>
            </div>
            <div class="nav-item" style="flex: auto;" role="presentation">
                <button class="nav-link w-100" data-bs-toggle="pill" data-bs-target="#tab-signup" type="button" role="tab">
                    {{ _l('lang.signup') }}
                </button>
            </div>
        </div>    
    `);
</script>
<div class="modal-body">
    <div class="tab-content">
        <div class="tab-pane fade show active" id="tab-signin" role="tabpanel" aria-labelledby="pills-home-tab">
            <form id="signin-form">
                {{ csrf() }}
                <div class="form-group mb-2">
                    <input type="email" class="form-control" name="email"
                        placeholder="{{ _l('lang.email') }}" required>
                </div>
                <div class="form-group">
                    <input type="password" class="form-control" name="password"
                        placeholder="{{ _l('lang.password') }}" required>
                </div>

                <div class="my-3">
                    <input type="checkbox" name="keep-logged-in" id="keep-logged-in" class="form-check-input">
                    <label for="keep-logged-in">{{ _l('lang.keep-logged-in') }}</label>
                </div>

                <div class="form-group">
                    <button type="submit"
                        class="btn btn-sm btn-primary w-100">{{ _l('lang.signin') }}</button>
                </div>
            </form>
        </div>

        <div class="tab-pane fade" id="tab-signup" role="tabpanel" aria-labelledby="pills-profile-tab">
            <form id="signup-form">
                {{ csrf() }}
                <div class="form-group mb-2">
                    <input type="text" class="form-control" name="username"
                        placeholder="{{ _l('lang.username') }}" required>
                </div>

                <div class="form-group mb-2">
                    <input type="email" class="form-control" name="email"
                        placeholder="{{ _l('lang.email') }}" required>
                </div>

                <div class="row">
                    <div class="col-6">
                        <div class="form-group">
                            <input type="password" class="form-control" name="password"
                                placeholder="{{ _l('lang.password') }}" required>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-group">
                            <input type="password" class="form-control" name="re-password"
                                placeholder="{{ _l('lang.re-password') }}" required>
                        </div>
                    </div>
                </div>

                <div class="my-3">
                    <input type="checkbox" name="terms" id="terms" class="form-check-input">
                    <label for="terms">{{ _l('lang.terms') }}</label>
                </div>

                <div class="form-group">
                    <button type="submit"
                        class="btn btn-sm btn-primary w-100">{{ _l('lang.signup') }}</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    $('#signin-form').sbmt((form, btn) => {
        $.core.btn.spin(btn);
        $.post(`{{ route('sign-in') }}`, $.core.SToA(form), e => {
            if (!e.status) {
                $.core.btn.unset(btn);
            } else {
                currentModal.modal('hide');
                $.system.loadAuthContent();
            }
            $.showAlerts(e.alerts);
        });
    });

    $('#signup-form').sbmt((form, btn) => {
        $.core.btn.spin(btn);
        $.post(`{{ route('sign-up') }}`, $.core.SToA(form), e => {
            if (!e.status) $.core.btn.unset(btn);
            else currentModal.reopen();
            $.showAlerts(e.alerts);
        });
    });
</script>