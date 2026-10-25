<?php

// Notification texts. Placeholders: :ref :title :user :priority :status :app
return [
    'open' => 'Fungua kwenye jukwaa',
    'footer' => 'Ujumbe huu umetumwa kiotomatiki. Tafadhali usijibu.',
    'chat_escalated' => 'Ninakuunganisha na wakala wa msaada. Tafadhali subiri kidogo.',
    'case_created_user' => [
        'title' => 'Tumepokea kesi yako :ref',
        'body' => 'Kesi yako ":title" imesajiliwa. Tutakujulisha maendeleo.',
    ],
    'case_created_admin' => [
        'title' => 'Kesi mpya :ref',
        'body' => '(:priority) :user amewasilisha ":title".',
        'sms' => ':app: kesi mpya :ref kutoka :user (:priority). Tafadhali angalia jukwaa.',
    ],
    'case_status' => [
        'title' => 'Kesi :ref imesasishwa',
        'body' => 'Hali ya kesi yako sasa ni: :status.',
    ],
    'case_comment' => [
        'title' => 'Maoni mapya kwenye :ref',
        'body' => ':user ametoa maoni kwenye ":title".',
    ],
    'case_assigned' => [
        'title' => 'Kesi :ref umekabidhiwa',
        'body' => 'Umekabidhiwa kesi ":title".',
    ],
    'chat_waiting' => [
        'title' => 'Gumzo linahitaji wakala',
        'body' => ':user anasubiri msaada.',
    ],
    'file_delete_requested' => [
        'title' => 'Ombi la kufuta faili',
        'body' => ':user ameomba kufuta faili ":file". Ifungue ili ukubali au ukatae.',
    ],
    'file_delete_decided' => [
        'title' => 'Ombi la kufuta :decision',
        'body' => 'Ombi la kufuta ":file" limeshughulikiwa na :user: :decision.',
    ],
    'movement_requested' => [
        'title' => 'Uhamisho wa vifaa :ref',
        'body' => 'Ombi :ref la :user (kwenda :dest) linasubiri idhini yako.',
    ],
    'movement_decided' => [
        'title' => 'Uhamisho :ref :decision',
        'body' => 'Ombi lako la kuhamisha vifaa :ref limeshughulikiwa na :user: :decision.',
    ],
    'movement_overdue' => [
        'title' => 'Vifaa vimechelewa :ref',
        'body' => 'Vifaa vya :ref (:user) vilitakiwa kurudishwa tarehe :date.',
    ],
    'incident_reported' => [
        'title' => 'Tukio :ref (:priority)',
        'body' => '(:priority) :user ameripoti « :title ».',
        'sms' => ':app: TUKIO :ref (:priority) kutoka :user. Tafadhali angalia jukwaa.',
    ],
    'incident_sla_containment' => [
        'title' => 'Udhibiti umechelewa :ref',
        'body' => 'Tukio :ref « :title » halijadhibitiwa ndani ya muda lengwa.',
        'sms' => 'Udhibiti umechelewa kwa :ref. Chukua hatua sasa.',
    ],
    'incident_sla_rca' => [
        'title' => 'Uchambuzi wa chanzo umechelewa :ref',
        'body' => 'Tukio :ref « :title » halina uchambuzi wa chanzo uliokamilika ndani ya muda lengwa.',
    ],
    'incident_contained' => [
        'title' => 'Tukio :ref limedhibitiwa',
        'body' => 'Tukio « :title » limedhibitiwa na :user.',
    ],
    'access_granted' => [
        'title' => 'Ufikiaji umetolewa',
        'body' => 'Umepewa ufikiaji (:level) wa :res na :user.',
    ],
    'access_revoked' => [
        'title' => 'Ufikiaji umeondolewa',
        'body' => 'Ufikiaji wako wa :res umeondolewa na :user.',
    ],
    'record_transfer_due' => [
        'title' => 'Rekodi zimechelewa kuhamishwa',
        'body' => ':count rekodi zisizotumika zimepita muda wa kuhamishiwa hifadhini.',
    ],
    'record_retention_due' => [
        'title' => 'Rekodi tayari kukaguliwa kwa uharibifu',
        'body' => ':count rekodi zilizohifadhiwa zimefikia mwisho wa muda wa uhifadhi.',
    ],
    'archive_access_requested' => [
        'title' => 'Ombi la ufikiaji wa hifadhi :ref',
        'body' => ':user anaomba kufikia rekodi :ref.',
    ],
    'archive_access_decided' => [
        'title' => 'Ufikiaji wa hifadhi :decision',
        'body' => ':user: ombi lako la rekodi :ref limeshughulikiwa: :decision.',
    ],
    'inventory_discrepancy' => [
        'title' => 'Tofauti ya hesabu: :title',
        'body' => ':missing rekodi hazikupatikana katika hesabu « :title » (imekaguliwa na :user).',
    ],
    'disposal_proposed' => [
        'title' => 'Kundi la uharibifu :ref',
        'body' => ':user amependekeza rekodi :count ziharibiwe. Idhini yako inahitajika.',
    ],
    'disposal_decided' => [
        'title' => 'Kundi la uharibifu :ref :decision',
        'body' => 'Pendekezo lako la uharibifu :ref limeshughulikiwa na :user: :decision.',
    ],
    'backup_issue' => [
        'title' => 'Uhakiki wa nakala rudufu: :result',
        'body' => ':user amehakiki nakala ya :date (:loc): :result. Hatua inahitajika.',
    ],
    'management_report' => [
        'title' => 'Ripoti kwa uongozi: :title',
        'body' => ':user amewasilisha ripoti :title (:period).',
    ],
    'training_invited' => [
        'title' => 'Mafunzo: :title',
        'body' => 'Umealikwa kwenye mafunzo « :title » tarehe :date.',
    ],
    'audit_finding_assigned' => [
        'title' => 'Matokeo ya ukaguzi umekabidhiwa (:ref)',
        'body' => ':user amekukabidhi matokeo « :title ».',
    ],
    'obligation_due' => [
        'title' => 'Jukumu la utiifu limefika: :title',
        'body' => '« :title » ilikuwa inatakiwa tarehe :date.',
    ],
];
