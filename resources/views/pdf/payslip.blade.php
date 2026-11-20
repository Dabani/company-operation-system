<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2933; }
  h1 { font-size: 18px; margin: 0 0 2px; color: #0b5c5c; }
  h2 { font-size: 11px; margin: 16px 0 4px; text-transform: uppercase; letter-spacing: .04em; color: #0b5c5c; }
  .muted { color: #6b7785; }
  table { width: 100%; border-collapse: collapse; }
  td, th { padding: 4px 6px; border-bottom: 1px solid #e3e7ec; vertical-align: top; text-align: left; }
  th { color: #6b7785; font-weight: normal; font-size: 9.5px; }
  .r { text-align: right; }
  .head td { border: 0; padding: 2px 6px; }
  .total td { font-weight: bold; border-top: 2px solid #1f2933; border-bottom: 0; }
  .net td { font-size: 13px; font-weight: bold; background: #eef6f6; border: 0; }
  .note { margin-top: 18px; font-size: 9px; color: #6b7785; }
</style>
</head>
<body>
  <h1>{{ $org->name }}</h1>
  <div class="muted">{{ __('payrun.slip.title', [], $locale) }} · {{ $slip['run']['code'] }} · {{ $slip['run']['period_from'] }} → {{ $slip['run']['period_to'] }}</div>

  <table class="head" style="margin-top:12px">
    <tr><td class="muted" width="22%">{{ __('payrun.slip.employee', [], $locale) }}</td><td><b>{{ $slip['employee']['name'] }}</b></td><td class="muted" width="22%">{{ __('payrun.slip.number', [], $locale) }}</td><td>{{ $slip['employee']['number'] }}</td></tr>
    @if($slip['proration']['factor'] !== null && (float) $slip['proration']['factor'] < 1)
    <tr><td class="muted">{{ __('payrun.slip.days', [], $locale) }}</td><td colspan="3">{{ $slip['proration']['worked_days'] }} / {{ $slip['proration']['period_days'] }}</td></tr>
    @endif
  </table>

  <h2>{{ __('payrun.slip.earnings', [], $locale) }}</h2>
  <table>
    @foreach($slip['earnings'] as $l)<tr><td>{{ $l['name'] }}</td><td class="r">{{ number_format((float) $l['amount'], 2, '.', ' ') }}</td></tr>@endforeach
    <tr class="total"><td>{{ __('payrun.slip.gross', [], $locale) }}</td><td class="r">{{ number_format((float) $slip['totals']['gross'], 2, '.', ' ') }}</td></tr>
  </table>

  @if(count($slip['contributions']) || count($slip['deductions']))
  <h2>{{ __('payrun.slip.deductions', [], $locale) }}</h2>
  <table>
    <tr><th>{{ __('payrun.slip.item', [], $locale) }}</th><th class="r">{{ __('payrun.slip.base', [], $locale) }}</th><th class="r">%</th><th class="r">{{ __('payrun.slip.amount', [], $locale) }}</th></tr>
    @foreach($slip['contributions'] as $l)
      <tr><td>{{ $l['name'] }}</td><td class="r">{{ $l['base'] !== null ? number_format((float) $l['base'], 0, '.', ' ') : '' }}</td><td class="r">{{ $l['rate'] !== null ? rtrim(rtrim($l['rate'], '0'), '.') : '' }}</td><td class="r">{{ number_format((float) $l['amount'], 2, '.', ' ') }}</td></tr>
      @foreach($l['bands'] as $b)<tr><td class="muted" style="padding-left:18px">{{ number_format((float) $b['lower'], 0, '.', ' ') }} – {{ $b['upper'] !== null ? number_format((float) $b['upper'], 0, '.', ' ') : '…' }}</td><td class="r muted">{{ number_format((float) $b['part'], 0, '.', ' ') }}</td><td class="r muted">{{ rtrim(rtrim($b['rate'], '0'), '.') }}</td><td class="r muted">{{ number_format((float) $b['amount'], 2, '.', ' ') }}</td></tr>@endforeach
    @endforeach
    @foreach($slip['deductions'] as $l)<tr><td>{{ $l['name'] }}</td><td></td><td></td><td class="r">{{ number_format((float) $l['amount'], 2, '.', ' ') }}</td></tr>@endforeach
    <tr class="total"><td colspan="3">{{ __('payrun.slip.total_deductions', [], $locale) }}</td><td class="r">{{ number_format((float) bcadd($slip['totals']['employee_contributions'], $slip['totals']['deductions'], 2), 2, '.', ' ') }}</td></tr>
  </table>
  @endif

  <table style="margin-top:14px"><tr class="net"><td>{{ __('payrun.slip.net', [], $locale) }} ({{ $slip['currency'] }})</td><td class="r">{{ number_format((float) $slip['totals']['net'], 2, '.', ' ') }}</td></tr></table>

  @if(count($slip['employer']))
  <h2>{{ __('payrun.slip.employer', [], $locale) }}</h2>
  <table>@foreach($slip['employer'] as $l)<tr><td>{{ $l['name'] }}</td><td class="r">{{ number_format((float) $l['amount'], 2, '.', ' ') }}</td></tr>@endforeach</table>
  <div class="note">{{ __('payrun.slip.employer_note', [], $locale) }}</div>
  @endif
  @if($slip['run']['paid_on'])<div class="note">{{ __('payrun.slip.paid_on', [], $locale) }}: {{ $slip['run']['paid_on'] }}</div>@endif
</body>
</html>
