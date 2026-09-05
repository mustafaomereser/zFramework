@extends('app.main')
@section('body')
<div class="my-4">
    <div class="d-flex align-items-end justify-content-between flex-wrap gap-2 mb-4">
        <div>
            <h1 class="h2 mb-1">Examples</h1>
            <p class="text-secondary mb-0">Each one runs here and shows the code that runs it. Remove <code>App/Controllers/ExamplesController.php</code>, <code>resource/views/app/pages/examples/</code> and the routes when you no longer want them.</p>
        </div>
        <a href="/" class="btn btn-sm border"><i class="fad fa-arrow-left me-1"></i> Home</a>
    </div>

    <div class="row g-3">
        <div class="col-md-6 col-lg-4">
            <a href="{{ route('pusher.examples') }}" class="card zf-card h-100 text-decoration-none">
                <div class="card-body">
                    <div class="zf-icon"><i class="fad fa-broadcast-tower"></i></div>
                    <h5 class="card-title">Pusher Channels</h5>
                    <p class="card-text text-secondary">Live chat, a ping everyone hears, a progress bar fed by the server, private and presence channels.</p>
                    <span class="badge text-bg-light border">Pusher::</span>
                    <span class="badge text-bg-light border">LivePusher</span>
                </div>
            </a>
        </div>

        <div class="col-md-6 col-lg-4">
            <div class="card zf-card h-100 zf-card-muted">
                <div class="card-body">
                    <div class="zf-icon"><i class="fad fa-bell"></i></div>
                    <h5 class="card-title">Push notifications</h5>
                    <p class="card-text text-secondary">Reach a user whose tab is closed. Needs a VAPID key pair - <code>php terminal push-notification keys app</code>.</p>
                    <span class="badge text-bg-light border">README §21</span>
                </div>
            </div>
        </div>

        <div class="col-md-6 col-lg-4">
            <div class="card zf-card h-100 zf-card-muted">
                <div class="card-body">
                    <div class="zf-icon"><i class="fad fa-vial"></i></div>
                    <h5 class="card-title">Tests</h5>
                    <p class="card-text text-secondary">The framework's own runner: <code>php terminal tests</code>. Plain PHP files in <code>tests/</code>, one process each.</p>
                    <span class="badge text-bg-light border">README §14.4</span>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
