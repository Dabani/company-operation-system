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
];
