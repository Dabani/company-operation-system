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
    'movement_requested' => [
        'title' => 'Gusohora ibikoresho :ref',
        'body' => 'Ubusabe :ref bwa :user (bijya :dest) butegereje kwemezwa nawe.',
    ],
    'movement_decided' => [
        'title' => 'Gusohora :ref :decision',
        'body' => 'Ubusabe bwawe bwo gusohora ibikoresho :ref bwasuzumwe na :user: :decision.',
    ],
    'movement_overdue' => [
        'title' => 'Ibikoresho byatinze :ref',
        'body' => 'Ibikoresho bya :ref (:user) byagombaga kugaruka ku wa :date.',
    ],
    'incident_reported' => [
        'title' => 'Ikibazo cyabaye :ref (:priority)',
        'body' => '(:priority) :user yatanze raporo ya « :title ».',
        'sms' => ':app: IKIBAZO :ref (:priority) cya :user. Reba ku rubuga.',
    ],
    'incident_sla_containment' => [
        'title' => 'Gukumira byatinze :ref',
        'body' => 'Ikibazo :ref « :title » ntikirakumirwa mu gihe cyagenwe.',
        'sms' => 'Gukumira byatinze kuri :ref. Kora ubu.',
    ],
    'incident_sla_rca' => [
        'title' => 'Isesengura ry\'intandaro ryatinze :ref',
        'body' => 'Ikibazo :ref « :title » nta sesengura ry\'intandaro rirarangira mu gihe cyagenwe.',
    ],
    'incident_contained' => [
        'title' => 'Ikibazo :ref cyakumiriwe',
        'body' => 'Ikibazo « :title » cyakumiriwe na :user.',
    ],
    'access_granted' => [
        'title' => 'Wahawe uburenganzira',
        'body' => 'Wahawe uburenganzira (:level) kuri :res na :user.',
    ],
    'access_revoked' => [
        'title' => 'Uburenganzira bwakuweho',
        'body' => 'Uburenganzira bwawe kuri :res bwakuweho na :user.',
    ],
    'record_transfer_due' => [
        'title' => 'Inyandiko zatinze kwimurirwa mu bubiko',
        'body' => ':count inyandiko zidakora zarengeje igihe cyo kwimurirwa mu bubiko.',
    ],
    'record_retention_due' => [
        'title' => 'Inyandiko zigomba gusuzumwa ngo zisibwe',
        'body' => ':count inyandiko zabitswe zageze ku iherezo ry\'igihe cyo kubikwa.',
    ],
    'archive_access_requested' => [
        'title' => 'Ubusabe bwo kwinjira mu bubiko :ref',
        'body' => ':user arasaba kubona inyandiko :ref.',
    ],
    'archive_access_decided' => [
        'title' => 'Kwinjira mu bubiko :decision',
        'body' => ':user: ubusabe bwawe ku nyandiko :ref bwasuzumwe: :decision.',
    ],
    'inventory_discrepancy' => [
        'title' => 'Itandukaniro mu ibarura: :title',
        'body' => ':missing inyandiko ntizabonetse mu ibarura « :title » (ryagenzuwe na :user).',
    ],
    'disposal_proposed' => [
        'title' => 'Itsinda ry\'isibwa :ref',
        'body' => ':user yatanze inyandiko :count ngo zisibwe. Hakenewe uburenganzira bwawe.',
    ],
    'disposal_decided' => [
        'title' => 'Itsinda ry\'isibwa :ref :decision',
        'body' => 'Icyifuzo cyawe cy\'isibwa :ref cyasuzumwe na :user: :decision.',
    ],
    'backup_issue' => [
        'title' => 'Kugenzura kopi: :result',
        'body' => ':user yagenzuye kopi yo ku wa :date kuri :loc: :result. Hakenewe igikorwa.',
    ],
    'management_report' => [
        'title' => 'Raporo ku buyobozi: :title',
        'body' => ':user yatanze raporo :title (:period).',
    ],
    'training_invited' => [
        'title' => 'Amahugurwa: :title',
        'body' => 'Watumiwe mu mahugurwa « :title » ku wa :date.',
    ],
    'audit_finding_assigned' => [
        'title' => 'Ikibazo cy\'igenzura wahawe (:ref)',
        'body' => ':user yaguhaye ikibazo « :title ».',
    ],
    'obligation_due' => [
        'title' => 'Inshingano igeze igihe: :title',
        'body' => '« :title » yagombaga gukorwa ku wa :date.',
    ],
];
