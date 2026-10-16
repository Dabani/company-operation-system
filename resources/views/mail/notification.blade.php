<!doctype html>
<html lang="{{ $mailLocale }}">
<body style="margin:0;background:#f4f6f9;font-family:Verdana,Geneva,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:10px;overflow:hidden;">
      <tr><td style="background:#3773b8;padding:18px 24px;color:#fff;font-size:16px;font-weight:bold;">{{ config('app.name') }}</td></tr>
      <tr><td style="padding:24px;font-size:14px;line-height:1.6;">
        <h2 style="margin:0 0 12px;font-size:18px;color:#3773b8;">{{ $subjectLine }}</h2>
        <p style="margin:0 0 20px;">{{ $bodyText }}</p>
        @if($actionUrl)
          <a href="{{ $actionUrl }}" style="display:inline-block;background:#1c9d67;color:#fff;text-decoration:none;padding:10px 18px;border-radius:6px;font-weight:bold;">{{ __('notify.open') }}</a>
        @endif
      </td></tr>
      <tr><td style="padding:14px 24px;font-size:11px;color:#6b7280;border-top:1px solid #eef0f3;">{{ __('notify.footer') }}</td></tr>
    </table>
  </td></tr></table>
</body>
</html>
