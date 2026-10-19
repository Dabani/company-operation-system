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
];
