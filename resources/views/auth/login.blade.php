@extends('layouts.guest')
@section('content')
<form method="POST" action="{{ route('login') }}">
    @csrf
    <div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></div>
    <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
    <div class="field"><label class="check"><input type="checkbox" name="remember" value="1" id="remember"> Keep me signed in</label></div>
    <button class="btn" style="width:100%">Sign in</button>
    <div id="passkey-signin" hidden>
        <p class="hint" style="text-align:center;margin:12px 0 8px">or</p>
        <button type="button" class="btn secondary" style="width:100%" data-passkey-signin><i class="fas fa-fingerprint" aria-hidden="true"></i> Sign in with fingerprint or face</button>
        <div class="alert bad" role="alert" data-passkey-error hidden style="margin-top:8px"></div>
    </div>
    <p class="right"><a href="{{ route('password.request') }}">Forgot password?</a></p>
</form>
@include('partials.passkey-js')
<script>
(function () {
    if (!window.Passkey.supported) return;
    const box = document.getElementById('passkey-signin');
    const btn = box.querySelector('[data-passkey-signin]');
    const err = box.querySelector('[data-passkey-error]');
    box.hidden = false;
    btn.addEventListener('click', async function () {
        err.hidden = true;
        btn.disabled = true;
        try {
            const res = await window.Passkey.signIn(@json(route('passkey.login.options')), @json(route('passkey.login')), document.getElementById('remember').checked);
            window.location.assign(res.redirect);
        } catch (e) {
            err.textContent = window.Passkey.friendly(e);
            err.hidden = false;
            btn.disabled = false;
        }
    });
})();
</script>
@endsection
