<?php

// COPS Phase 4a: HR messages. rw / sw are first-pass: have native speakers review them.
return [
    'employment' => [
        'pending_exists' => 'Mfanyakazi huyu ana mabadiliko yanayosubiri idhini.',
        'date_order' => 'Tarehe ya kuanza lazima iwe baada ya badiliko la mwisho lililorekodiwa.',
        'salary_required' => 'Mabadiliko ya mshahara yanahitaji kiasi kipya cha mshahara.',
        'currency_required' => 'Mshahara unahitaji sarafu.',
        'nothing_changed' => 'Hakuna kitakachobadilika.',
        'confirmation_needs_category' => 'Uthibitisho lazima uweke kundi jipya la mfanyakazi.',
        'use_change' => 'Sehemu hii ina historia yenye tarehe: rekodi badiliko la ajira badala ya kuihariri.',
        'approval_title' => [
            'promotion' => 'Kupandishwa cheo: :name',
            'confirmation' => 'Uthibitisho: :name',
            'salary_change' => 'Mabadiliko ya mshahara: :name',
            'category_change' => 'Mabadiliko ya kundi: :name',
            'transfer' => 'Kuhamishwa: :name',
            'manager_change' => 'Mabadiliko ya msimamizi: :name',
        ],
    ],
    'criteria' => [
        'must_be_higher' => 'Daraja lengwa lazima liwe juu kuliko daraja la kuanzia.',
        'exists' => 'Kanuni hii ya maendeleo tayari ipo.',
    ],
    'review' => [
        'cycle_closed' => 'Mzunguko huu wa tathmini umefungwa.',
        'rating_required' => 'Chagua alama kabla ya kuwasilisha.',
    ],
    'reminder' => [
        'probation' => ['title' => 'Majaribio yanakaribia kuisha: :name', 'body' => 'Majaribio ya :name yanaisha tarehe :date (siku :days zimebaki). Amua kuthibitisha au kuongeza.'],
        'contract' => ['title' => 'Mkataba unakaribia kuisha: :name', 'body' => 'Mkataba wa :name unaisha tarehe :date (siku :days zimebaki). Upya au panga kuondoka.'],
    ],
];
