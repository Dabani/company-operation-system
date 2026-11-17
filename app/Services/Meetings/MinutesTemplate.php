<?php

namespace App\Services\Meetings;

/**
 * The starting text of the minutes, built from the agenda and the attendance sheet (Guide 6.6): attendance and apologies, one block per
 * agenda item, resolutions, actions, closing. Pure: the caller passes the data and the labels, every value is escaped here.
 */
final class MinutesTemplate
{
    /**
     * @param array{title:string,code:string,when:string,where:string,chair:?string,secretary:?string,present:string[],apologies:string[],absent:string[],
     *              agenda:array<int,array{title:string,presenter:?string,expected_decision:?string}>} $d
     * @param array<string,string> $l labels: attendance, apologies, absent, none, chair, secretary, agenda, discussion, decision, resolutions, actions, closing, presenter
     */
    public static function build(array $d, array $l): string
    {
        $e = fn (?string $s) => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $list = fn (array $names) => $names ? '<p>' . implode(', ', array_map($e, $names)) . '</p>' : '<p><em>' . $e($l['none']) . '</em></p>';
        $h = '<h2>' . $e($d['title']) . '</h2>';
        $h .= '<p>' . $e($d['code']) . ' · ' . $e($d['when']) . ($d['where'] !== '' ? ' · ' . $e($d['where']) : '') . '</p>';
        $roles = array_filter([$d['chair'] ? $e($l['chair']) . ': ' . $e($d['chair']) : null, $d['secretary'] ? $e($l['secretary']) . ': ' . $e($d['secretary']) : null]);
        if ($roles) {
            $h .= '<p>' . implode(' · ', $roles) . '</p>';
        }
        $h .= '<h3>' . $e($l['attendance']) . '</h3>' . $list($d['present']);
        $h .= '<h3>' . $e($l['apologies']) . '</h3>' . $list($d['apologies']);
        if ($d['absent']) {
            $h .= '<h3>' . $e($l['absent']) . '</h3>' . $list($d['absent']);
        }
        $h .= '<h3>' . $e($l['agenda']) . '</h3>';
        foreach (array_values($d['agenda']) as $i => $item) {
            $h .= '<h4>' . ($i + 1) . '. ' . $e($item['title']) . '</h4>';
            if (!empty($item['presenter'])) {
                $h .= '<p><em>' . $e($l['presenter']) . ': ' . $e($item['presenter']) . '</em></p>';
            }
            $h .= '<p><strong>' . $e($l['discussion']) . ':</strong> </p>';
            $h .= '<p><strong>' . $e($l['decision']) . ':</strong> ' . $e($item['expected_decision'] ?? '') . '</p>';
        }
        $h .= '<h3>' . $e($l['resolutions']) . '</h3><p></p>';
        $h .= '<h3>' . $e($l['actions']) . '</h3><p></p>';
        $h .= '<h3>' . $e($l['closing']) . '</h3><p></p>';
        return $h;
    }
}
