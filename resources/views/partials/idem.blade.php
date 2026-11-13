{{-- One-time key so a double-click or resubmit cannot post the same document twice (see App\Support\Idempotency). --}}
<input type="hidden" name="idempotency_key" value="{{ old('idempotency_key', (string) \Illuminate\Support\Str::uuid()) }}">
