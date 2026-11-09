<?php

// COPS Phase 4a: HR messages. rw / sw are first-pass: have native speakers review them.
return [
    'employment' => [
        'pending_exists' => 'Uyu mukozi afite impinduka itegereje kwemezwa.',
        'date_order' => 'Itariki ntangarugero igomba kuba nyuma y\'impinduka iheruka kwandikwa.',
        'salary_required' => 'Guhindura umushahara bisaba umubare mushya w\'umushahara.',
        'currency_required' => 'Umushahara usaba ifaranga.',
        'nothing_changed' => 'Nta kintu cyahinduka.',
        'confirmation_needs_category' => 'Kwemezwa mu kazi bisaba kugena icyiciro gishya cy\'umukozi.',
        'use_change' => 'Iki gice gifite amateka afite amatariki: andika impinduka mu kazi aho kukihindura.',
        'approval_title' => [
            'promotion' => 'Kuzamurwa mu ntera: :name',
            'confirmation' => 'Kwemezwa mu kazi: :name',
            'salary_change' => 'Guhindura umushahara: :name',
            'category_change' => 'Guhindura icyiciro: :name',
            'transfer' => 'Kwimurirwa: :name',
            'manager_change' => 'Guhindura umuyobozi: :name',
        ],
    ],
    'criteria' => [
        'must_be_higher' => 'Ikiciro kigenewe kigomba kuruta icyo utangiriyeho.',
        'exists' => 'Iri tegeko ryo kuzamurwa risanzwe ririho.',
    ],
    'review' => [
        'cycle_closed' => 'Iki gihe cy\'isuzuma cyarafunzwe.',
        'rating_required' => 'Hitamo amanota mbere yo kohereza.',
    ],
    'reminder' => [
        'probation' => ['title' => 'Igihe cy\'igeragezwa kigiye kurangira: :name', 'body' => 'Igihe cy\'igeragezwa cya :name kirangira ku wa :date (hasigaye iminsi :days). Fata icyemezo cyo kumwemeza cyangwa kukongera.'],
        'contract' => ['title' => 'Amasezerano agiye kurangira: :name', 'body' => 'Amasezerano ya :name arangira ku wa :date (hasigaye iminsi :days). Yongere cyangwa utegure gusezera.'],
    ],
];
