<!doctype html>
<html lang="{{ app()->getLocale() }}">
<body style="margin:0;background:#f4f6f9;font-family:Verdana,Geneva,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;overflow:hidden;">
      <tr><td style="background:#3773b8;padding:18px 24px;color:#fff;font-size:16px;font-weight:bold;">{{ config('app.name') }}</td></tr>
      <tr><td style="padding:24px;font-size:14px;line-height:1.6;">
        <h2 style="margin:0 0 12px;font-size:18px;color:#3773b8;">{{ __('invitation.title') }}</h2>
        <p style="margin:0 0 12px;">{{ __('invitation.hello', ['name' => $name]) }}</p>
        <p style="margin:0 0 20px;">
          @if($inviter) {{ __('invitation.intro', ['inviter' => $inviter, 'org' => $organization, 'app' => config('app.name')]) }}
          @else {{ __('invitation.intro_nobody', ['org' => $organization, 'app' => config('app.name')]) }} @endif
        </p>
        <p style="margin:0 0 20px;"><a href="{{ $url }}" style="display:inline-block;background:#1c9d67;color:#fff;text-decoration:none;padding:11px 20px;border-radius:6px;font-weight:bold;">{{ __('invitation.button') }}</a></p>
        <p style="margin:0 0 8px;font-size:12px;color:#6b7280;">{{ __('invitation.expiry', ['days' => $days]) }}</p>
        <p style="margin:0 0 4px;font-size:12px;color:#6b7280;">{{ __('invitation.help') }}</p>
        <p style="margin:0 0 16px;font-size:11px;word-break:break-all;"><a href="{{ $url }}" style="color:#3773b8;">{{ $url }}</a></p>
        <p style="margin:0;font-size:12px;color:#6b7280;">{{ __('invitation.ignore') }}</p>
      </td></tr>
    </table>
  </td></tr></table>
</body>
</html>
