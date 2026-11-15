<?php

namespace App\Support;

use Stevebauman\Purify\Facades\Purify;

/** Server-side HTML purifier for all rich text (Tiptap) input. */
class Html
{
    public static function clean(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }
        return Purify::clean($html);
    }

    /** Visible text of an HTML value (tags removed, entities decoded, white space collapsed): for length checks, audit entries and notifications. */
    public static function plain(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        $text = preg_replace('~</(p|div|li|h[1-6]|blockquote)>|<br\s*/?>~i', ' ', $html);
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
