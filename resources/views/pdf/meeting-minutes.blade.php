<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2933; line-height: 1.45; }
  .head { border-bottom: 2px solid #0b5c5c; padding-bottom: 6px; margin-bottom: 12px; }
  .org { color: #0b5c5c; font-weight: bold; font-size: 13px; }
  .muted { color: #6b7785; font-size: 10px; }
  h2 { font-size: 17px; margin: 8px 0 4px; } h3 { font-size: 13px; margin: 14px 0 4px; color: #0b5c5c; } h4 { font-size: 11.5px; margin: 10px 0 2px; }
  .sign { margin-top: 26px; border-top: 1px solid #c9d1d9; padding-top: 8px; }
  .fix { background: #fff7e6; border: 1px solid #f0d9a8; padding: 6px 8px; margin-bottom: 10px; font-size: 10px; }
</style>
</head>
<body>
  <div class="head">
    <div class="org">{{ $org->name }}</div>
    <div class="muted">{{ __('meetings.min_title', [], $locale) }} · {{ $m->code }} · {{ __('meetings.min_version', ['n' => $v->version_no], $locale) }}</div>
  </div>
  @if($v->correction_reason)
    <div class="fix"><b>{{ __('meetings.min_correction', [], $locale) }}:</b> {{ $v->correction_reason }}</div>
  @endif
  {!! $v->body !!}
  <div class="sign muted">
    @if($v->approver){{ __('meetings.min_approved_by', ['name' => $v->approver->name, 'date' => $v->approved_at?->setTimezone($tz)->format('Y-m-d H:i')], $locale) }}<br>@endif
    {{ __('meetings.min_published', ['date' => now()->setTimezone($tz)->format('Y-m-d H:i')], $locale) }}
  </div>
</body>
</html>
