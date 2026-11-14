<!DOCTYPE html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<style>
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; color: #1f2933; }
  h1 { font-size: 20px; margin: 0 0 4px; color: #0b5c5c; }
  .muted { color: #6b7785; }
  table.facts { width: 100%; border-collapse: collapse; margin: 18px 0; }
  table.facts td { padding: 6px 8px; border-bottom: 1px solid #e3e7ec; vertical-align: top; }
  table.facts td.k { width: 38%; color: #6b7785; }
  .terms { margin-top: 12px; line-height: 1.5; }
  .sign { width: 100%; margin-top: 56px; }
  .sign td { width: 50%; padding-right: 30px; }
  .line { border-top: 1px solid #1f2933; margin-top: 44px; padding-top: 4px; }
</style>
</head>
<body>
  <h1>{{ $org->name }}</h1>
  <div class="muted">{{ __('offer.t', [], $locale) }} · {{ __('offer.ref', [], $locale) }}: {{ $offer->code }} · {{ __('offer.date', [], $locale) }}: {{ ($offer->issued_at ?? now())->toDateString() }}</div>

  <p style="margin-top:22px">{{ __('offer.dear', ['name' => $offer->applicant->first_name . ' ' . $offer->applicant->last_name], $locale) }}</p>
  <p>{{ __('offer.intro', ['position' => $title, 'org' => $org->name], $locale) }}</p>

  <table class="facts">
    <tr><td class="k">{{ __('offer.pos', [], $locale) }}</td><td>{{ $title }}</td></tr>
    @if($offer->department)<tr><td class="k">{{ __('offer.dept', [], $locale) }}</td><td>{{ $offer->department->name }}</td></tr>@endif
    <tr><td class="k">{{ __('offer.cat', [], $locale) }}</td><td>{{ $category }}</td></tr>
    @if($contract)<tr><td class="k">{{ __('offer.ct', [], $locale) }}</td><td>{{ $contract }}</td></tr>@endif
    <tr><td class="k">{{ __('offer.start', [], $locale) }}</td><td>{{ $offer->start_date->toDateString() }}</td></tr>
    @if($offer->probation_months > 0)<tr><td class="k">{{ __('offer.prob', [], $locale) }}</td><td>{{ $offer->probation_months }}</td></tr>@endif
    @if($offer->contract_end_on)<tr><td class="k">{{ __('offer.end', [], $locale) }}</td><td>{{ $offer->contract_end_on->toDateString() }}</td></tr>@endif
    <tr><td class="k">{{ __('offer.sal', [], $locale) }}</td><td><strong>{{ number_format((float) $offer->salary_amount, 2) }} {{ $offer->salary_currency }}</strong> {{ __($offer->salary_period === 'annual' ? 'offer.annual' : 'offer.monthly', [], $locale) }}</td></tr>
  </table>

  @if($offer->valid_until)<p>{{ __('offer.valid', ['date' => $offer->valid_until->toDateString()], $locale) }}</p>@endif
  @if($offer->terms)<div class="terms"><strong>{{ __('offer.terms', [], $locale) }}</strong><br>{!! nl2br(e($offer->terms)) !!}</div>@endif
  <p style="margin-top:18px">{{ __('offer.sign', [], $locale) }}</p>

  <table class="sign"><tr>
    <td><div class="line">{{ __('offer.sig1', [], $locale) }}</div></td>
    <td><div class="line">{{ __('offer.sig2', [], $locale) }}</div></td>
  </tr></table>
</body>
</html>
