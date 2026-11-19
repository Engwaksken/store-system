@extends('layouts.app')
@section('title', 'Sign-in & security')
@section('content')
<div style="max-width:720px;margin:0 auto">
<div class="page-head"><h1>Sign-in &amp; security</h1></div>

<x-tabs id="security">
    <x-tab title="Password" icon="fa-key" name="password">
        <form method="POST" action="{{ route('password.change.update') }}" style="max-width:480px">
            @csrf @method('PUT')
            <div class="field"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
            <div class="field"><label>New password</label><input type="password" name="password" required autocomplete="new-password"><div class="hint">At least 8 characters with letters and numbers. Phones using fingerprint sign-in will need your new password once.</div></div>
            <div class="field"><label>Confirm new password</label><input type="password" name="password_confirmation" required></div>
            <button class="btn">Update password</button>
        </form>
    </x-tab>

    <x-tab title="Passkeys" icon="fa-fingerprint" name="passkeys" :count="$passkeys->count()">
        <p class="muted">Sign in to this website with your fingerprint, face or device PIN instead of your password. The passkey stays on your device (or your Google / Apple / Microsoft account); this site only keeps a public key.</p>

        @if($passkeys->isEmpty())
            <p class="empty">No passkeys yet.</p>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Passkey</th><th>Added</th><th>Last used</th><th></th></tr></thead>
                <tbody>
                @foreach($passkeys as $pk)
                    <tr>
                        <td>{{ $pk->name }}</td>
                        <td>{{ $pk->created_at->format('d M Y') }}</td>
                        <td>{{ $pk->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                        <td class="right">
                            <form method="POST" action="{{ route('passkeys.destroy', $pk) }}" class="inline-form">@csrf @method('DELETE')
                                <button class="btn small danger" data-confirm="Remove the passkey &quot;{{ $pk->name }}&quot;? It will no longer sign you in."><i class="fas fa-trash"></i> Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif

        <div id="passkey-add" style="margin-top:1rem;max-width:480px">
            <p class="hint" data-passkey-unsupported hidden>This browser cannot create passkeys here. Use an up-to-date browser on a device with a fingerprint reader, face unlock or a screen lock, over https.</p>
            <div data-passkey-form hidden>
                <div class="field"><label for="pk-password">Current password</label><input id="pk-password" type="password" autocomplete="current-password"></div>
                <div class="field"><label for="pk-name">Name for this device <span class="muted">(optional)</span></label><input id="pk-name" type="text" maxlength="100" placeholder="e.g. Office laptop"></div>
                <button type="button" class="btn" data-passkey-add><i class="fas fa-plus"></i> Add fingerprint / face sign-in</button>
                <div class="alert bad" role="alert" data-passkey-error hidden style="margin-top:8px"></div>
            </div>
        </div>
    </x-tab>

    <x-tab title="Phones" icon="fa-mobile-screen" name="phones" :count="$devices->count()">
        <p class="muted">Phones with fingerprint sign-in turned on in the mobile app (Profile &rarr; Edit profile). Remove a phone you no longer have.</p>
        @if($devices->isEmpty())
            <p class="empty">None.</p>
        @else
            <div class="table-wrap"><table>
                <thead><tr><th>Phone</th><th>Turned on</th><th>Last used</th><th></th></tr></thead>
                <tbody>
                @foreach($devices as $d)
                    <tr>
                        <td>{{ $d->name }}</td>
                        <td>{{ $d->created_at->format('d M Y') }}</td>
                        <td>{{ $d->last_used_at?->diffForHumans() ?? 'Never' }}</td>
                        <td class="right">
                            <form method="POST" action="{{ route('devices.destroy', $d) }}" class="inline-form">@csrf @method('DELETE')
                                <button class="btn small danger" data-confirm="Turn off fingerprint sign-in for {{ $d->name }}?"><i class="fas fa-trash"></i> Remove</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table></div>
        @endif
    </x-tab>
</x-tabs>
</div>

@include('partials.passkey-js')
<script>
(function () {
    const box = document.getElementById('passkey-add');
    if (!window.Passkey.supported) { box.querySelector('[data-passkey-unsupported]').hidden = false; return; }
    const form = box.querySelector('[data-passkey-form]');
    const btn = box.querySelector('[data-passkey-add]');
    const err = box.querySelector('[data-passkey-error]');
    form.hidden = false;
    btn.addEventListener('click', async function () {
        err.hidden = true;
        const password = document.getElementById('pk-password').value;
        if (!password) { err.textContent = 'Enter your current password.'; err.hidden = false; return; }
        btn.disabled = true;
        try {
            await window.Passkey.register(@json(route('passkeys.options')), @json(route('passkeys.store')), password, document.getElementById('pk-name').value);
            window.location.reload();
        } catch (e) {
            err.textContent = window.Passkey.friendly(e);
            err.hidden = false;
            btn.disabled = false;
        }
    });
})();
</script>
@endsection
