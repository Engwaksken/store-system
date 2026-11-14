@props(['action', 'scope' => ''])
{{-- Bulk action bar for a table inside [data-bulk-scope]: hidden until a row is ticked (public/js/app.js initBulk).
     Buttons in the slot submit name="action"; every action is re-checked per record on the server. --}}
<form method="POST" action="{{ $action }}" class="bulk-bar" data-bulk-bar data-bulk-form="{{ $scope }}" hidden>@csrf
    <span class="bulk-count"><i class="fas fa-square-check"></i> <strong data-bulk-count>0</strong> selected</span>
    <span class="actions">
        {{ $slot }}
        <button type="button" class="btn small link" data-bulk-clear>Clear</button>
    </span>
</form>
