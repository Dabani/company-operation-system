<?php

// COPS Phase 6c: payroll export messages (sw). First-pass, native review needed.
return [
    'err' => [
        'verify_not_author' => 'Muundo lazima uthibitishwe na mtu mwingine asiyeutayarisha.',
        'already_verified' => 'Muundo huu umeshathibitishwa.',
        'verified_is_final' => 'Muundo uliothibitishwa haubadilishwi wala kufutwa: uzime na utengeneze mpya.',
        'delimiter_required' => 'Muundo uliotenganishwa unahitaji kitenganishi.',
        'source_not_allowed' => 'Sehemu ":source" haipatikani kwa faili ya :kind.',
        'scheme_required' => 'Sehemu hii inahitaji mpango.',
        'component_required' => 'Sehemu hii inahitaji kipengele cha mshahara.',
        'constant_required' => 'Safu isiyobadilika inahitaji thamani yake.',
        'width_required' => 'Safu ya upana usiobadilika inahitaji upana.',
        'profile_not_verified' => 'Muundo huu haujathibitishwa (au umezimwa): hakuna kinachoweza kuzalishwa.',
        'run_state' => 'Faili ya :kind inaweza kuzalishwa tu malipo yakiwa: :allowed.',
        'adjustment_unsupported' => 'Malipo ya marekebisho yanaunga mkono faili ya benki tu.',
        'segregation' => 'Mgawanyo wa majukumu: aliyetayarisha au kuhesabu malipo hawezi kuzalisha faili yake ya benki.',
        'accounts_missing' => 'Akaunti za jarida hazijawekwa kwa: :keys.',
        'journal_unbalanced' => 'Jarida halilingani: halikuzalishwa.',
        'bank_account_missing' => 'Hakuna akaunti ya benki iliyorekodiwa ya: :names.',
        'no_rows' => 'Hakuna cha kusafirisha kwa malipo haya.',
        'filename_placeholder' => 'Kishikilia kisichojulikana {:name} kwenye jina la faili.',
    ],
    'format' => [
        'no_columns' => 'Muundo hauna safu.',
        'not_numeric' => 'Nambari ilitarajiwa: :detail',
        'delimiter_in_value' => 'Thamani ina kitenganishi na muundo hauna alama ya nukuu: :detail',
        'width_required' => 'Safu ya upana usiobadilika haina upana: :detail',
        'overflow' => 'Thamani haitoshi kwenye safu yake (hakuna kinachokatwa kimya): :detail',
        'unknown_placeholder' => 'Kishikilia kisichojulikana kwenye kichwa au mwisho: {:detail}',
        'encoding' => 'Herufi haiwezi kuwakilishwa kwenye usimbaji uliochaguliwa: :detail',
    ],
];
