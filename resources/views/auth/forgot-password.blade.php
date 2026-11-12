@extends('layouts.guest')
@section('title', 'Forgot password')
@section('content')
<p class="muted">Enter your account e-mail and we will send a reset link.</p>
<form method="POST" action="{{ route('password.email') }}">
    @csrf
    <div class="field"><label for="email">E-mail</label><input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus></div>
    <button class="btn" style="width:100%">Send reset link</button>
    <p class="right"><a href="{{ route('login') }}">Back to sign in</a></p>
</form>
@endsection
