@extends('layouts.app')
@section('title', 'Change password')
@section('content')
<div class="page-head"><h1>Change password</h1></div>
<div class="card" style="max-width:480px">
<form method="POST" action="{{ route('password.change.update') }}">
    @csrf @method('PUT')
    <div class="field"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
    <div class="field"><label>New password</label><input type="password" name="password" required autocomplete="new-password"><div class="hint">At least 8 characters with letters and numbers.</div></div>
    <div class="field"><label>Confirm new password</label><input type="password" name="password_confirmation" required></div>
    <button class="btn">Update password</button>
</form>
</div>
@endsection
