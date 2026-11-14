<?php

namespace App\Services\Hr;

use App\Models\Language;
use App\Models\LookupValue;
use App\Models\Offer;
use App\Models\Organization;
use App\Services\OrganizationContext;
use Dompdf\Dompdf;

/** Offer letter in the candidate's language (en, fr, rw, sw). Same engine and fonts as the invoice PDF. */
class OfferPdf
{
    public function render(Offer $offer, string $locale): string
    {
        $offer = Offer::with(['applicant', 'position', 'department', 'category.translations'])->findOrFail($offer->id);
        $langId = Language::where('code', $locale)->value('id');
        $contract = $offer->contract_type_id ? LookupValue::with('translations')->find($offer->contract_type_id)?->label($langId) : null;
        $org = app(OrganizationContext::class)->organization() ?? Organization::findOrFail($offer->organization_id);
        $html = view('pdf.offer', ['offer' => $offer, 'org' => $org, 'locale' => $locale, 'title' => $offer->position?->title ?? $offer->applicant->vacancy()->first()?->title ?? '',
            'category' => $offer->category->label($langId), 'contract' => $contract])->render();
        $pdf = new Dompdf(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false]);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        return $pdf->output();
    }
}
