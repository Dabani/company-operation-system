<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 22mm 15mm 18mm 15mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2937; }
  h1 { font-size: 18px; color: #3773b8; margin: 0; letter-spacing: 1px; }
  .org { font-size: 12px; font-weight: bold; color: #1c9d67; }
  .head { border-bottom: 3px solid #3773b8; padding-bottom: 8px; margin-bottom: 12px; }
  .right { text-align: right; }
  table { width: 100%; border-collapse: collapse; }
  table.lines th, table.lines td { border-bottom: 1px solid #cbd5e1; padding: 5px 4px; text-align: left; vertical-align: top; }
  table.lines th { background: #eef4fb; font-size: 9px; }
  td.n, th.n { text-align: right; white-space: nowrap; }
  table.totals { width: 46%; margin-left: 54%; margin-top: 10px; }
  table.totals td { padding: 3px 4px; }
  table.totals tr.grand td { font-weight: bold; font-size: 12px; border-top: 2px solid #3773b8; }
  .muted { color: #6b7280; font-size: 9px; }
  .draft { position: fixed; top: 38%; left: 12%; font-size: 90px; color: #e5e7eb; transform: rotate(-30deg); z-index: -1; }
  .annex { page-break-before: always; }
</style>
</head>
<body>
@if($draft)<div class="draft">{{ __('billing.pdf.draft') }}</div>@endif
<div class="head">
  <table><tr>
    <td><div class="org">{{ $org->name }}</div><h1>{{ __('billing.pdf.invoice') }}</h1></td>
    <td class="right"><b>{{ __('billing.pdf.number') }}</b> {{ $i->number ?? '—' }}<br>
      <b>{{ __('billing.pdf.date') }}</b> {{ $i->issue_date?->toDateString() ?? '—' }}<br>
      <b>{{ __('billing.pdf.due') }}</b> {{ $i->due_date?->toDateString() ?? '—' }}</td>
  </tr></table>
</div>

<table><tr>
  <td style="width:55%"><b>{{ __('billing.pdf.bill_to') }}</b><br>{{ $i->client_name ?? $i->client?->name }}<br>{!! nl2br(e($i->client_address ?? $i->client?->address)) !!}
    @if($i->client_tax_number ?? $i->client?->tax_number)<br>{{ __('billing.pdf.tax_no') }} {{ $i->client_tax_number ?? $i->client?->tax_number }}@endif</td>
  <td><b>{{ __('billing.pdf.project') }}</b><br>{{ $i->project?->code }} — {{ $i->project?->name }}</td>
</tr></table>

<table class="lines" style="margin-top:14px">
  <thead><tr><th style="width:5%">#</th><th>{{ __('billing.pdf.description') }}</th><th class="n">{{ __('billing.pdf.quantity') }}</th><th class="n">{{ __('billing.pdf.unit_price') }}</th><th class="n">{{ __('billing.pdf.amount') }} ({{ $i->currency_code }})</th></tr></thead>
  <tbody>
  @foreach($i->lines as $l)
    <tr><td>{{ $l->line_no }}</td><td>{{ $l->description }}</td><td class="n">{{ rtrim(rtrim(number_format((float) $l->quantity, 4, '.', ' '), '0'), '.') }} {{ $l->unit }}</td>
      <td class="n">{{ number_format((float) $l->unit_price, 2, '.', ' ') }}</td><td class="n">{{ number_format((float) $l->amount, 2, '.', ' ') }}</td></tr>
  @endforeach
  </tbody>
</table>

<table class="totals">
  <tr><td>{{ __('billing.pdf.subtotal') }}</td><td class="n">{{ number_format((float) $i->subtotal, 2, '.', ' ') }}</td></tr>
  <tr><td>@if($i->tax_exempt || $i->tax_percent === null){{ __('billing.pdf.no_tax') }}@else{{ $i->tax_name }} ({{ rtrim(rtrim(number_format((float) $i->tax_percent, 4, '.', ''), '0'), '.') }}%)@endif</td><td class="n">{{ number_format((float) $i->tax_total, 2, '.', ' ') }}</td></tr>
  <tr class="grand"><td>{{ __('billing.pdf.total') }} {{ $i->currency_code }}</td><td class="n">{{ number_format((float) $i->total, 2, '.', ' ') }}</td></tr>
  @if((float) $i->credited_total != 0)<tr><td>{{ __('billing.pdf.credited') }}</td><td class="n">-{{ number_format((float) $i->credited_total, 2, '.', ' ') }}</td></tr>@endif
  @if((float) $i->paid_total != 0)<tr><td>{{ __('billing.pdf.paid') }}</td><td class="n">-{{ number_format((float) $i->paid_total, 2, '.', ' ') }}</td></tr>@endif
  <tr><td><b>{{ __('billing.pdf.balance_due') }}</b></td><td class="n"><b>{{ number_format((float) $i->balance, 2, '.', ' ') }}</b></td></tr>
</table>

<p class="muted" style="margin-top:14px">{{ __('billing.pdf.terms', ['days' => $i->payment_terms_days]) }}
  @if($i->lines->contains(fn($l) => $l->kind->code === 'time')) {{ __('billing.pdf.rounding', ['minutes' => $i->rounding_minutes, 'mode' => $i->rounding_mode]) }}@endif</p>
@if($i->notes)<p><b>{{ __('billing.pdf.notes') }}</b><br>{!! nl2br(e($i->notes)) !!}</p>@endif

@if($annex->isNotEmpty())
<div class="annex">
  <h1 style="font-size:14px">{{ __('billing.pdf.annex') }} — {{ $i->number ?? '' }}</h1>
  <p class="muted">{{ __('billing.pdf.annex_note') }}</p>
  <table class="lines">
    <thead><tr><th>{{ __('billing.pdf.date_col') }}</th><th>{{ __('billing.pdf.person') }}</th><th>{{ __('billing.pdf.task') }}</th><th class="n">{{ __('billing.pdf.hours') }}</th></tr></thead>
    <tbody>@foreach($annex as $a)<tr><td>{{ $a['date'] }}</td><td>{{ $a['person'] }}</td><td>{{ $a['task'] }}</td><td class="n">{{ $a['hours'] }}</td></tr>@endforeach</tbody>
  </table>
</div>
@endif
</body>
</html>
