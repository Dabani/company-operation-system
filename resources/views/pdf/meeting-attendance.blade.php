<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2933; }
  h1 { font-size: 17px; margin: 0 0 2px; color: #0b5c5c; }
  .muted { color: #6b7785; }
  table.sheet { width: 100%; border-collapse: collapse; margin-top: 14px; }
  table.sheet th { text-align: left; background: #eef2f5; padding: 5px 6px; border-bottom: 1px solid #c9d1d9; }
  table.sheet td { padding: 7px 6px; border-bottom: 1px solid #e3e7ec; vertical-align: bottom; }
  .sig { width: 22%; }
</style>
</head>
<body>
  <h1>{{ $org->name }} · {{ __('meetings.att_title', [], $locale) }}</h1>
  <div><b>{{ $m->title }}</b> ({{ $m->code }})</div>
  <div class="muted">{{ $m->starts_at->setTimezone($tz)->format('Y-m-d H:i') }} – {{ $m->ends_at->setTimezone($tz)->format('H:i') }} ({{ $tz }})
    @if($m->chair) · {{ __('meetings.att_chair', [], $locale) }}: {{ $m->chair->name }} @endif
    @if($m->secretary) · {{ __('meetings.att_secretary', [], $locale) }}: {{ $m->secretary->name }} @endif</div>
  <div class="muted">{{ __('meetings.att_summary', ['attended' => $summary['attended'], 'total' => $summary['total']], $locale) }}@if($summary['rate'] !== null) ({{ $summary['rate'] }}%)@endif</div>

  <table class="sheet">
    <tr>
      <th>{{ __('meetings.att_col_name', [], $locale) }}</th><th>{{ __('meetings.att_col_type', [], $locale) }}</th><th>{{ __('meetings.att_col_department', [], $locale) }}</th>
      <th>{{ __('meetings.att_col_status', [], $locale) }}</th><th>{{ __('meetings.att_col_arrived', [], $locale) }}</th><th class="sig">{{ __('meetings.att_signature', [], $locale) }}</th>
    </tr>
    @foreach($lines as $l)
      <tr><td>{{ $l['name'] }}</td><td>{{ $l['type'] }}</td><td>{{ $l['department'] }}</td><td>{{ $l['status'] }}</td><td>{{ $l['arrived'] }}</td><td class="sig">{{ $l['acknowledged'] }}</td></tr>
    @endforeach
  </table>
  <p class="muted" style="margin-top:16px">{{ __('meetings.att_generated', ['date' => now()->setTimezone($tz)->format('Y-m-d H:i')], $locale) }}</p>
</body>
</html>
