@if (session('status'))
    <div class="alert ok" role="status">{{ session('status') }}</div>
@endif
@if ($errors->any())
    <div class="alert bad" role="alert">
        <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif
