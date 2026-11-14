@if (session('status'))
    <div class="alert ok" role="status" data-flash><i class="fas fa-circle-check"></i> {{ session('status') }}</div>
@endif
@if ($errors->any() && ! old('_modal'))
    <div class="alert bad" role="alert" data-flash>
        <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
