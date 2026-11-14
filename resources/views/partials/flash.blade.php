@if (session('status'))
    <div class="alert ok" role="status" data-flash><i class="fas fa-circle-check"></i> {{ session('status') }}</div>
@endif
@if (session('credentials'))
    {{-- Shown once, only when the sign-in e-mail could not be sent (see UserController::sendCredentials). --}}
    <div class="alert bad" role="alert"><i class="fas fa-envelope-circle-check"></i>
        The sign-in e-mail could not be sent (check the mail settings). Give these details to the user in person; they must change the password at first sign-in. This is shown only once.<br>
        <strong>E-mail:</strong> {{ session('credentials.email') }} &nbsp; <strong>Temporary password:</strong> <code>{{ session('credentials.password') }}</code>
    </div>
@endif
@if ($errors->any() && ! old('_modal'))
    <div class="alert bad" role="alert" data-flash>
        <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
