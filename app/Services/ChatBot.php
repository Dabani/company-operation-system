<?php

namespace App\Services;

use App\Models\BotIntent;

/** Keyword-scored FAQ bot driven entirely by tables (bot_intents, keywords, responses). No external AI service, no cost. */
class ChatBot
{
    /** @return array{0:string,1:string,2:string} [response text, action code, intent code] */
    public static function reply(string $text, string $locale): array
    {
        $norm = ' ' . trim(preg_replace('/\s+/u', ' ', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', mb_strtolower($text)))) . ' ';

        $intents = BotIntent::with(['action', 'keywords', 'responses'])->where('is_active', true)->orderBy('sort_order')->get();

        $best = null; $bestScore = 0;
        foreach ($intents as $intent) {
            $score = 0;
            foreach ($intent->keywords as $k) {
                $kw = ' ' . mb_strtolower($k->keyword) . ' ';
                if (str_contains($norm, $kw)) {
                    $score += 2;                                   // whole word / phrase
                } elseif (mb_strlen($k->keyword) >= 5 && str_contains($norm, mb_strtolower($k->keyword))) {
                    $score += 1;                                   // partial (covers plurals/conjugations)
                }
            }
            if ($score > $bestScore) { $best = $intent; $bestScore = $score; }
        }

        $intent = $best ?? $intents->firstWhere('code', 'fallback');
        if (!$intent) {
            return [__('notify.chat_escalated', [], $locale), 'escalate', 'none'];
        }

        $langId = \App\Models\Language::where('code', $locale)->value('id');
        $enId = \App\Models\Language::where('code', 'en')->value('id');
        $resp = $intent->responses->firstWhere('language_id', $langId) ?? $intent->responses->firstWhere('language_id', $enId);

        return [$resp?->response ?? '', $intent->action->code, $intent->code];
    }
}
