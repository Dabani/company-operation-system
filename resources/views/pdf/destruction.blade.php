<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8">
<style>
  @page { margin: 26mm 16mm 20mm 16mm; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1f2937; }
  h1 { font-size: 17px; color: #3773b8; margin: 0 0 2px; }
  .org { font-size: 12px; font-weight: bold; color: #1c9d67; }
  .ref { float: right; font-size: 13px; font-weight: bold; }
  .head { border-bottom: 3px solid #3773b8; padding-bottom: 6px; margin-bottom: 8px; }
  table { width: 100%; border-collapse: collapse; margin-top: 10px; }
  th, td { border: 1px solid #cbd5e1; padding: 4px 5px; text-align: left; vertical-align: top; }
  th { background: #eef4fb; }
  table.meta th { width: 26%; }
  .sig td { height: 58px; width: 33%; }
  .muted { color: #6b7280; font-size: 9px; }
</style>
</head>
<body>
  <div class="head">
    <span class="ref">{{ $b->reference }}</span>
    <div class="org">{{ $org->name }}</div>
    <h1>{{ __('pdf.cert_title') }}</h1>
  </div>

  <p>{{ __('pdf.cert_text') }}</p>

  <table class="meta">
    <tr><th>{{ __('pdf.method') }}</th><td>{{ $b->method }}</td></tr>
    <tr><th>{{ __('pdf.witness') }}</th><td>{{ $b->witness ?: '—' }}</td></tr>
    <tr><th>{{ __('pdf.date') }}</th><td>{{ $b->executed_at?->toDateString() }}</td></tr>
    <tr><th>{{ __('pdf.records_count') }}</th><td>{{ $records->count() }}</td></tr>
  </table>

  <table>
    <tr><th>#</th><th>{{ __('pdf.reference') }}</th><th>{{ __('pdf.index_code') }}</th><th>{{ __('pdf.record_title') }}</th><th>{{ __('pdf.category') }}</th><th>{{ __('pdf.retention_until') }}</th></tr>
    @foreach($records as $i => $r)
      <tr><td>{{ $i + 1 }}</td><td>{{ $r->reference }}</td><td>{{ $r->index_code }}</td><td>{{ $r->title }}</td><td>{{ $r->category->label() }}</td><td>{{ $r->retention_until?->toDateString() }}</td></tr>
    @endforeach
  </table>

  <table class="sig">
    <tr><th>{{ __('pdf.proposed_by') }}</th><th>{{ __('pdf.approved_by') }}</th><th>{{ __('pdf.executed_by') }}</th></tr>
    <tr>
      <td>{{ $b->proposer?->name }}<br><span class="muted">{{ $b->created_at->toDateString() }}</span><br><br><span class="muted">{{ __('pdf.signature') }}:</span></td>
      <td>{{ $b->decider?->name }}<br><span class="muted">{{ $b->decided_at?->toDateString() }}</span><br><br><span class="muted">{{ __('pdf.signature') }}:</span></td>
      <td>{{ $b->executor?->name }}<br><span class="muted">{{ $b->executed_at?->toDateString() }}</span><br><br><span class="muted">{{ __('pdf.signature') }}:</span></td>
    </tr>
  </table>
  <p class="muted">{{ __('pdf.footer', ['date' => now()->toDateTimeString()]) }}</p>
</body>
</html>
