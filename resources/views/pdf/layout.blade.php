<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>@yield('title')</title>
<style>
    @page { margin: 22mm 14mm 18mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 9.5pt; color: #1f2937; }
    h1 { font-size: 15pt; margin: 0; color: #12243E; } h2 { font-size: 11pt; margin: 0 0 4px; color: #12243E; }
    .head { width: 100%; border-bottom: 2px solid #D4A33A; padding-bottom: 6px; margin-bottom: 10px; }
    .head td { vertical-align: top; } .muted { color: #6b7280; } .right { text-align: right; }
    table.grid { width: 100%; border-collapse: collapse; margin-top: 8px; }
    table.grid th { background: #12243E; color: #fff; font-weight: bold; text-align: left; padding: 4px 5px; font-size: 8.5pt; }
    table.grid td { border-bottom: 1px solid #e5e7eb; padding: 3px 5px; }
    table.grid tfoot td { font-weight: bold; border-top: 2px solid #12243E; }
    .num { text-align: right; white-space: nowrap; }
    .meta td { padding: 1px 8px 1px 0; vertical-align: top; } .meta td.k { color: #6b7280; width: 28%; }
    .sign { width: 100%; margin-top: 40px; } .sign td { width: 33%; padding-top: 28px; border-top: 1px solid #333; font-size: 8.5pt; }
    .foot { position: fixed; bottom: -10mm; left: 0; right: 0; font-size: 7.5pt; color: #6b7280; text-align: center; }
    .note { background: #FEF3C7; padding: 5px 7px; margin: 6px 0; font-size: 8.5pt; }
</style></head>
<body>
<table class="head"><tr>
    <td>@if($logoData)<img src="{{ $logoData }}" alt="" style="height:38px"><br>@endif<h2>{{ $company['name'] }}</h2>
        <span class="muted">{{ $company['address'] }} {{ $company['phone'] }} {{ $company['email'] }}</span></td>
    <td class="right">@yield('doc-head')</td>
</tr></table>
@yield('body')
<div class="foot">{{ $company['footer'] }} · Generated {{ now()->format('d M Y H:i') }}@auth by {{ auth()->user()->name }}@endauth</div>
</body></html>
