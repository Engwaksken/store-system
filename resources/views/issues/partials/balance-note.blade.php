{{-- Partial issue: what is still to issue on the request after this voucher. --}}
@php($pending = $issue->lines->filter(fn ($l) => (float) $l->balanceAfter() > 0))
@if($pending->isNotEmpty())
    <div @class(['alert warn', 'no-print' => $noPrint ?? false])><i class="fas fa-hourglass-half"></i>
        <strong>Partial issue.</strong> Balance remaining on {{ $issue->request->request_no }}:
        @foreach($pending as $l){{ $l->item->name }} <strong>{{ qty($l->balanceAfter()) }}</strong> {{ $l->item->unit->abbreviation }}@if(! $loop->last), @endif @endforeach.
        It stays outstanding on the request and is issued when stock is available.
    </div>
@endif
