<?php

// COPS Phase 6c: payroll export messages (rw). First-pass, native review needed.
return [
    'err' => [
        'verify_not_author' => 'Imiterere igomba kwemezwa n\'undi muntu utari uwayiteguye.',
        'already_verified' => 'Iyi miterere yamaze kwemezwa.',
        'verified_is_final' => 'Imiterere yemejwe ntihindurwa cyangwa ngo isibwe: iyifunge ukore indi nshya.',
        'delimiter_required' => 'Imiterere ritandukanyijwe isaba ikimenyetso gitandukanya.',
        'source_not_allowed' => 'Umwanya ":source" ntuboneka ku dosiye :kind.',
        'scheme_required' => 'Uyu mwanya usaba uburyo.',
        'component_required' => 'Uyu mwanya usaba igice cy\'umushahara.',
        'constant_required' => 'Inkingi ihoraho isaba agaciro kayo.',
        'width_required' => 'Inkingi y\'ubugari buhoraho isaba ubugari.',
        'profile_not_verified' => 'Iyi miterere ntiyemejwe (cyangwa irafunze): nta kintu gishobora kuyivamo.',
        'run_state' => 'Dosiye :kind ikorwa gusa ishyirwa riri: :allowed.',
        'adjustment_unsupported' => 'Ishyirwa ryo gukosora rishyigikira dosiye ya banki gusa.',
        'segregation' => 'Gutandukanya inshingano: uwateguye cyangwa wabaze ishyirwa ntashobora gukora dosiye yaryo ya banki.',
        'accounts_missing' => 'Konti z\'ibitabo ntizashyizweho kuri: :keys.',
        'journal_unbalanced' => 'Ibitabo ntibingana: ntibyakozwe.',
        'bank_account_missing' => 'Nta konti ya banki yanditswe ya: :names.',
        'no_rows' => 'Nta kintu cyo kohereza kuri iri shyirwa.',
        'filename_placeholder' => 'Ikimenyetso kitazwi {:name} mu izina rya dosiye.',
    ],
    'format' => [
        'no_columns' => 'Imiterere nta nkingi ifite.',
        'not_numeric' => 'Hari hategerejwe umubare: :detail',
        'delimiter_in_value' => 'Agaciro karimo ikimenyetso gitandukanya kandi nta kimenyetso cy\'amagambo gihari: :detail',
        'width_required' => 'Inkingi y\'ubugari buhoraho nta bugari: :detail',
        'overflow' => 'Agaciro ntikajyana mu nkingi yako (nta kintu gicibwa bucece): :detail',
        'unknown_placeholder' => 'Ikimenyetso kitazwi mu mutwe cyangwa mu nsi: {:detail}',
        'encoding' => 'Inyuguti ntishobora kwandikwa mu buryo bwatoranyijwe: :detail',
    ],
];
