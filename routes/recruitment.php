<?php

use App\Http\Controllers\Api\V1 as V1;
use Illuminate\Support\Facades\Route;

/*
 | COPS Phase 4b routes (recruitment). Required once from routes/api.php next to hr.php, inside the authenticated "org.context" group.
 */

// ---- requisitions -----------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.requisition.create,hr.requisition.approve,hr.recruitment.manage')->get('requisitions', [V1\RecruitmentController::class, 'requisitions']);
Route::middleware('perm:hr.requisition.create,hr.recruitment.manage')->group(function () {
    Route::post('requisitions', [V1\RecruitmentController::class, 'storeRequisition']);
    Route::post('requisitions/{id}/cancel', [V1\RecruitmentController::class, 'cancelRequisition']);
});

// ---- recruitment (HR) ---------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.recruitment.manage')->group(function () {
    Route::get('vacancies', [V1\RecruitmentController::class, 'vacancies']);
    Route::post('requisitions/{id}/vacancy', [V1\RecruitmentController::class, 'openVacancy']);
    Route::patch('vacancies/{id}', [V1\RecruitmentController::class, 'updateVacancy']);
    Route::post('vacancies/{id}/close', [V1\RecruitmentController::class, 'closeVacancy']);
    Route::get('hr/settings', [V1\RecruitmentController::class, 'settings']);
    Route::put('hr/settings', [V1\RecruitmentController::class, 'updateSettings']);

    Route::get('applicants', [V1\ApplicantController::class, 'index']);
    Route::post('vacancies/{id}/applicants', [V1\ApplicantController::class, 'store']);
    Route::get('applicants/{id}', [V1\ApplicantController::class, 'show']);
    Route::patch('applicants/{id}', [V1\ApplicantController::class, 'update']);
    Route::post('applicants/{id}/stage', [V1\ApplicantController::class, 'stage']);
    Route::post('applicants/{id}/reject', [V1\ApplicantController::class, 'reject']);
    Route::post('applicants/{id}/selection', [V1\ApplicantController::class, 'selection']);
    Route::post('applicants/{id}/convert', [V1\ApplicantController::class, 'convert']);
    Route::post('applicants/{id}/references', [V1\ApplicantController::class, 'addReference']);
    Route::patch('references/{id}', [V1\ApplicantController::class, 'updateReference']);
    Route::post('applicants/{id}/extend-retention', [V1\ApplicantController::class, 'extendRetention']);
    Route::post('applicants/{id}/anonymize', [V1\ApplicantController::class, 'anonymize']);

    Route::post('applicants/{id}/interviews', [V1\InterviewController::class, 'store']);
    Route::post('interviews/{id}/status', [V1\InterviewController::class, 'status']);

    Route::get('offers', [V1\OfferController::class, 'index']);
    Route::post('applicants/{id}/offers', [V1\OfferController::class, 'store']);
    Route::post('offers/{id}/issue', [V1\OfferController::class, 'issue']);
    Route::post('offers/{id}/respond', [V1\OfferController::class, 'respond']);
    Route::post('offers/{id}/withdraw', [V1\OfferController::class, 'withdraw']);
    Route::get('offers/{id}/pdf', [V1\OfferController::class, 'pdf']);

    Route::get('hr/onboarding-template', [V1\OnboardingController::class, 'template']);
    Route::post('hr/onboarding-template', [V1\OnboardingController::class, 'storeTemplate']);
    Route::patch('hr/onboarding-template/{id}', [V1\OnboardingController::class, 'updateTemplate']);
    Route::delete('hr/onboarding-template/{id}', [V1\OnboardingController::class, 'destroyTemplate']);
    Route::patch('onboarding-items/{id}', [V1\OnboardingController::class, 'updateItem']);
});

// ---- panel members -------------------------------------------------------------------------------------------------------------
Route::middleware('perm:hr.interview.score,hr.recruitment.manage')->group(function () {
    Route::get('interviews', [V1\InterviewController::class, 'index']);
    Route::get('interviews/{id}', [V1\InterviewController::class, 'show']);
});
Route::post('interviews/{id}/scorecard', [V1\InterviewController::class, 'scorecard'])->middleware('perm:hr.interview.score');

// ---- onboarding checklist (HR, line management, or the employee) --------------------------------------------------------------
Route::get('employees/{id}/onboarding', [V1\OnboardingController::class, 'forEmployee'])->middleware('perm:hr.self.view,hr.history.view,hr.recruitment.manage');
Route::post('onboarding-items/{id}/complete', [V1\OnboardingController::class, 'complete'])->middleware('perm:hr.self.view,hr.recruitment.manage');
