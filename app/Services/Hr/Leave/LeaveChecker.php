<?php

namespace App\Services\Hr\Leave;

use DateTimeImmutable;

/**
 * Every rule a leave request must satisfy, in one pure function so the same answer drives the preview, the submission and the tests.
 * A blocker stops the request; a warning is shown to the requester and to the approver (Guide 5.4.1: "conflict warnings").
 * Messages are translation keys under leave.* with the returned params.
 */
final class LeaveChecker
{
    /**
     * @param array{
     *   start:string,end:string,days:string,today:string,privileged:bool,hired_on:?string,ended_on:?string,
     *   uses_balance:bool,ignores_blackout:bool,allow_retroactive:bool,requires_document:bool,
     *   policy:array<string,mixed>,balance_available:?string,taken_this_year:string,service_months:int,
     *   overlap:bool,blackout:?string,validated_month:bool,team_peak:int
     * } $in  "privileged" = HR entering it for someone: notice and retroactive limits do not apply to them
     * @return array{blockers:array<int,array{code:string,params:array}>,warnings:array<int,array{code:string,params:array}>}
     */
    public static function check(array $in): array
    {
        $p = $in['policy'];
        $b = [];
        $w = [];
        $add = function (array &$list, string $code, array $params = []) { $list[] = ['code' => $code, 'params' => $params]; };

        if ($in['end'] < $in['start']) {
            $add($b, 'dates_inverted');
        }
        if (bccomp($in['days'], '0', 2) <= 0) {
            $add($b, 'no_days');
        }
        if ($in['hired_on'] && $in['start'] < $in['hired_on']) {
            $add($b, 'before_hired');
        }
        if ($in['ended_on'] && $in['end'] > $in['ended_on']) {
            $add($b, 'after_ended');
        }
        if ($in['overlap']) {
            $add($b, 'overlap');
        }
        if ($in['validated_month']) {
            $add($b, 'period_validated');
        }
        if ($in['blackout'] !== null && !$in['ignores_blackout'] && !$in['privileged']) {
            $add($b, 'blackout', ['name' => $in['blackout']]);
        }
        if ($in['start'] < $in['today']) {
            if (!$in['allow_retroactive'] && !$in['privileged']) {
                $add($b, 'retroactive');
            }
        } elseif (($p['notice_days'] ?? null) !== null && !$in['privileged']) {
            $lead = (int) (new DateTimeImmutable($in['today']))->diff(new DateTimeImmutable($in['start']))->days;
            if ($lead < (int) $p['notice_days']) {
                $add($b, 'notice', ['days' => (int) $p['notice_days']]);
            }
        }
        if (($p['min_service_months_to_take'] ?? null) !== null && $in['service_months'] < (int) $p['min_service_months_to_take']) {
            $add($b, 'min_service', ['months' => (int) $p['min_service_months_to_take']]);
        }
        if (($p['max_days_per_request'] ?? null) !== null && bccomp($in['days'], $p['max_days_per_request'], 2) > 0) {
            $add($b, 'max_per_request', ['max' => $p['max_days_per_request']]);
        }
        if (($p['min_days_per_request'] ?? null) !== null && bccomp($in['days'], $p['min_days_per_request'], 2) < 0) {
            $add($b, 'min_per_request', ['min' => $p['min_days_per_request']]);
        }
        if (($p['max_days_per_year'] ?? null) !== null && bccomp(bcadd($in['taken_this_year'], $in['days'], 2), $p['max_days_per_year'], 2) > 0) {
            $add($b, 'max_per_year', ['max' => $p['max_days_per_year']]);
        }
        if ($in['uses_balance'] && $in['balance_available'] !== null && empty($p['allow_negative_balance']) && bccomp($in['days'], $in['balance_available'], 2) > 0) {
            $add($b, 'insufficient_balance', ['available' => $in['balance_available']]);
        }

        if (($p['max_simultaneous_absences'] ?? null) !== null && $in['team_peak'] + 1 > (int) $p['max_simultaneous_absences']) {
            $add($w, 'team_conflict', ['max' => (int) $p['max_simultaneous_absences'], 'away' => $in['team_peak']]);
        }
        if ($in['requires_document'] || (($p['requires_document_after_days'] ?? null) !== null && bccomp($in['days'], $p['requires_document_after_days'], 2) > 0)) {
            $add($w, 'document_required');
        }
        return ['blockers' => $b, 'warnings' => $w];
    }
}
