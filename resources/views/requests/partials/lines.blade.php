{{-- Request lines table; with $deciding the approver gets a quantity column (the surrounding decision form submits it). --}}
<div class="table-wrap"><table>
    <thead><tr><th>Item</th><th class="num">Requested</th><th class="num">PM approved</th><th class="num">Final approved</th><th class="num">Issued</th><th class="num">Outstanding</th><th class="num">In store now</th>@if($deciding)<th class="num">{{ $canPm ? 'Approve qty' : 'Final qty' }}</th>@endif</tr></thead>
    <tbody>@foreach($req->lines as $l)
        <tr><td>{{ $l->item->name }} <small class="muted">{{ $l->item->unit->abbreviation }}</small>@if($l->notes)<br><small class="muted">{{ $l->notes }}</small>@endif</td>
            <td class="num">{{ qty($l->requested_qty) }}</td>
            <td class="num">{{ $l->pm_approved_qty !== null ? qty($l->pm_approved_qty) : '-' }}</td>
            <td class="num">{{ $l->final_approved_qty !== null ? qty($l->final_approved_qty) : '-' }}</td>
            <td class="num">{{ qty($l->issued_qty) }}</td>
            <td class="num">{{ qty($l->outstanding()) }}</td>
            <td class="num">{{ qty($l->item->onHand()) }}</td>
            @if($deciding)
                @php($max = $canPm ? $l->requested_qty : $l->pm_approved_qty)
                <td class="num"><input type="number" name="qty[{{ $l->id }}]" step="0.001" min="0" max="{{ $max + 0 }}" value="{{ old('qty.'.$l->id, $max + 0) }}"></td>
            @endif
        </tr>
    @endforeach</tbody>
</table></div>
