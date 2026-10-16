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
}
