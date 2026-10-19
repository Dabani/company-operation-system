<?php

// Notification texts. Placeholders: :ref :title :user :priority :status :app
return [
    'open' => 'Fungura ku rubuga',
    'footer' => 'Iyi mesaje yoherejwe mu buryo bwikora. Ntukayisubize.',
    'chat_escalated' => 'Ngiye kukugeza ku mukozi w\'ubufasha. Tegereza gato.',
    'case_created_user' => [
        'title' => 'Twakiriye ikibazo cyawe :ref',
        'body' => 'Ikibazo cyawe ":title" cyanditswe. Tuzakumenyesha uko gikomeza.',
    ],
    'case_created_admin' => [
        'title' => 'Ikibazo gishya :ref',
        'body' => '(:priority) :user yohereje ":title".',
        'sms' => ':app: ikibazo gishya :ref cya :user (:priority). Reba ku rubuga.',
    ],
    'case_status' => [
        'title' => 'Ikibazo :ref cyavuguruwe',
        'body' => 'Imiterere y\'ikibazo cyawe ubu ni: :status.',
    ],
    'case_comment' => [
        'title' => 'Igitekerezo gishya kuri :ref',
        'body' => ':user yanditse igitekerezo kuri ":title".',
    ],
    'case_assigned' => [
        'title' => 'Ikibazo :ref cyaguhawe',
        'body' => 'Wahawe ikibazo ":title".',
    ],
    'chat_waiting' => [
        'title' => 'Ikiganiro gikeneye umukozi',
        'body' => ':user ategereje ubufasha.',
    ],
    'file_delete_requested' => [
        'title' => 'Hasabwe gusiba dosiye',
        'body' => ':user yasabye gusiba dosiye « :file ». Yifungure wemeze cyangwa wange.',
    ],
    'file_delete_decided' => [
        'title' => 'Ubusabe bwo gusiba :decision',
        'body' => 'Ubusabe bwo gusiba « :file » bwarasuzumwe na :user.',
    ],
];
