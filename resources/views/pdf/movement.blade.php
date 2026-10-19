<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 28mm 18mm 22mm 18mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
  h1 { font-size: 17px; color: #3773b8; margin: 0 0 2px; }
  .org { font-size: 12px; font-weight: bold; color: #1c9d67; }
  .ref { float: right; font-size: 13px; font-weight: bold; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th, td { border: 1px solid #cbd5e1; padding: 5px 6px; text-align: left; vertical-align: top; }
  th { background: #eef4fb; width: 28%; }
  table.items th { width: auto; }
  .sig td { height: 62px; width: 33%; }
  .muted { color: #6b7280; font-size: 9px; }
  .head { border-bottom: 3px solid #3773b8; padding-bottom: 6px; margin-bottom: 8px; }
</style>
</head>
<body>
  <div class="head">
    <span class="ref">{{ $m->reference }}</span>
    <div class="org">{{ $org->name }}</div>
    <h1>{{ __('pdf.title') }}</h1>
  </div>

  <table>
    <tr><th>{{ __('pdf.status') }}</th><td>{{ $m->status->label() }}</td></tr>
    <tr><th>{{ __('pdf.requested_by') }}</th><td>{{ $m->requester->name }}@if($department) — {{ $department }}@endif</td></tr>
    <tr><th>{{ __('pdf.destination') }}</th><td>{{ $m->destination }}</td></tr>
    <tr><th>{{ __('pdf.departure') }}</th><td>{{ $m->departure_date->toDateString() }}</td></tr>
    <tr><th>{{ __('pdf.expected_return') }}</th><td>{{ $m->expected_return_date?->toDateString() ?? '—' }}</td></tr>
    <tr><th>{{ __('pdf.purpose') }}</th><td>{{ $purpose ?: '—' }}</td></tr>
  </table>

  <table class="items">
    <tr><th>#</th><th>{{ __('pdf.tag') }}</th><th>{{ __('pdf.description') }}</th><th>{{ __('pdf.serial') }}</th></tr>
    @foreach($m->assets as $i => $a)
      <tr><td>{{ $i + 1 }}</td><td>{{ $a->asset_tag }}</td><td>{{ $a->name }}</td><td>{{ $a->serial_number ?: '—' }}</td></tr>
    @endforeach
  </table>

  <table class="sig">
    <tr>
      <th>{{ __('pdf.requested_by') }}</th>
      <th>{{ __('pdf.approved_by') }}</th>
      <th>{{ __('pdf.it_check') }}</th>
    </tr>
    <tr>
      <td>{{ $m->requester->name }}<br><span class="muted">{{ __('pdf.date') }}: {{ $m->created_at->toDateString() }}</span><br><br><span class="muted">{{ __('pdf.signature') }}:</span></td>
      <td>@if($m->approver){{ $m->approver->name }}<br><span class="muted">{{ __('pdf.date') }}: {{ $m->decided_at?->toDateString() }}</span>@else<span class="muted">&nbsp;</span>@endif<br><br><span class="muted">{{ __('pdf.signature') }}:</span></td>
      <td><span class="muted">{{ __('pdf.name') }} / {{ __('pdf.date') }}:</span><br><br><span class="muted">{{ __('pdf.signature') }}:</span></td>
    </tr>
  </table>
  <p class="muted">{{ __('pdf.footer', ['date' => now()->toDateTimeString()]) }}</p>
</body>
</html>
