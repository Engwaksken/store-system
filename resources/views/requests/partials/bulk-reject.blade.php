{{-- expects: $id (template id). Rejects the ticked requests with one reason. --}}
<template id="{{ $id }}" data-title="Reject selected requests">
    <form method="POST" action="{{ route('requests.bulk') }}" data-bulk-form>@csrf
        <input type="hidden" name="action" value="reject">
        <p class="muted" style="margin-top:0">The reason is sent to each requester. Requests not waiting for your decision are skipped and listed.</p>
        <div class="field"><label for="{{ $id }}_reason">Reason</label><textarea id="{{ $id }}_reason" name="reason" required maxlength="1000" placeholder="e.g. Quantities exceed the BOQ; revise and resubmit"></textarea></div>
        <button class="btn danger"><i class="fas fa-ban"></i> Reject selected</button>
    </form>
</template>
