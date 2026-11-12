@extends('layouts.guest')
@section('content')
<form method="POST" action="{{ route('login') }}">
    @csrf
    <div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"></div>
    <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
    <div class="field"><label class="check"><input type="checkbox" name="remember" value="1"> Keep me signed in</label></div>
    <button class="btn" style="width:100%">Sign in</button>
    <p class="right"><a href="{{ route('password.request') }}">Forgot password?</a></p>
</form>
@endsection
