<?php

namespace App\Services\Meetings;

use Carbon\CarbonInterface;

/**
 * iCalendar (RFC 5545) text for one meeting. Pure: no database, no framework (unit-tested).
 * METHOD:REQUEST for an invitation or an update (SEQUENCE rises with every change), METHOD:CANCEL when the meeting is cancelled.
 * Instants are written in UTC, text is escaped, and long lines are folded at 75 octets without cutting a UTF-8 character.
 */
final class IcsBuilder
{
    /**
     * @param array{uid:string, sequence:int, method:string, status:string, summary:string, description?:?string, location?:?string, url?:?string,
     *              start:CarbonInterface, end:CarbonInterface, stamp:CarbonInterface, organizer?:array{name:string,email:string}|null,
     *              attendees?:array<int,array{name:string,email:string,partstat:string}>, reminder_minutes?:?int} $e
     */
    public static function build(array $e): string
    {
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//COPS//Meetings//EN', 'CALSCALE:GREGORIAN', 'METHOD:' . $e['method'],
            'BEGIN:VEVENT',
            'UID:' . $e['uid'],
            'DTSTAMP:' . self::utc($e['stamp']),
            'SEQUENCE:' . (int) $e['sequence'],
            'DTSTART:' . self::utc($e['start']),
            'DTEND:' . self::utc($e['end']),
            'STATUS:' . $e['status'],
            'SUMMARY:' . self::escape($e['summary']),
        ];
        foreach (['description' => 'DESCRIPTION', 'location' => 'LOCATION', 'url' => 'URL'] as $key => $name) {
            if (!empty($e[$key])) {
                $lines[] = $name . ':' . ($key === 'url' ? $e[$key] : self::escape($e[$key]));
            }
        }
        if (!empty($e['organizer']['email'])) {
            $lines[] = 'ORGANIZER;CN=' . self::param($e['organizer']['name']) . ':mailto:' . $e['organizer']['email'];
        }
        foreach ($e['attendees'] ?? [] as $a) {
            if (!empty($a['email'])) {
                $lines[] = 'ATTENDEE;CN=' . self::param($a['name']) . ';PARTSTAT=' . $a['partstat'] . ';ROLE=REQ-PARTICIPANT:mailto:' . $a['email'];
            }
        }
        if (!empty($e['reminder_minutes'])) {
            array_push($lines, 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::escape($e['summary']), 'TRIGGER:-PT' . (int) $e['reminder_minutes'] . 'M', 'END:VALARM');
        }
        array_push($lines, 'END:VEVENT', 'END:VCALENDAR');
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    public static function utc(CarbonInterface $t): string
    {
        return $t->copy()->utc()->format('Ymd\THis\Z');
    }

    /** RFC 5545 TEXT escaping. */
    public static function escape(string $s): string
    {
        $s = str_replace(["\r\n", "\r"], "\n", $s);
        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $s);
    }

    /** A parameter value (CN=...): quoted, with quotes and control characters removed. */
    private static function param(string $s): string
    {
        return '"' . str_replace(['"', "\r", "\n"], ['', ' ', ' '], $s) . '"';
    }

    /** Fold a content line at 75 octets; continuation lines start with one space. */
    public static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            if (strlen($current) + strlen($ch) > $limit) {
                $out .= $current . "\r\n ";
                $current = '';
                $limit = 74;                                   // the leading space counts as one octet
            }
            $current .= $ch;
        }
        return $out . $current;
    }
}
