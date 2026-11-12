@extends('layouts.guest')
@section('title', 'Reset password')
@section('content')
<form method="POST" action="{{ route('password.update') }}">
    @csrf
    <input type="hidden" name="token" value="{{ $token }}">
    <div class="field"><label>E-mail</label><input type="email" name="email" value="{{ old('email', $email) }}" required></div>
    <div class="field"><label>New password</label><input type="password" name="password" required autocomplete="new-password"><div class="hint">At least 8 characters with letters and numbers.</div></div>
    <div class="field"><label>Confirm password</label><input type="password" name="password_confirmation" required></div>
    <button class="btn" style="width:100%">Reset password</button>
</form>
@endsection
