<?php

use App\Http\Controllers\Api\AudienceController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\ConsentController;
use App\Http\Controllers\Api\JourneyController;
use App\Http\Controllers\Api\PublicConsentController;
use App\Http\Controllers\Api\SegmentController;
use Illuminate\Support\Facades\Route;

foreach (['segment', 'audience', 'campaign', 'consentLink', 'journey', 'journeyEnrollment'] as $parameter) {
    Route::pattern($parameter, '[0-9]+');
}

Route::get('public/preferences/{token}', [PublicConsentController::class, 'show'])
    ->middleware('throttle:public-preferences')->where('token', '[A-Za-z0-9]{64}');
Route::post('public/preferences/{token}', [PublicConsentController::class, 'update'])
    ->middleware('throttle:public-preferences')->where('token', '[A-Za-z0-9]{64}');

Route::middleware(['auth:sanctum', 'tenant.context'])->group(function (): void {
    Route::get('segments/fields', [SegmentController::class, 'fields'])->middleware('permission:segments.view');
    Route::post('segments/preview', [SegmentController::class, 'preview'])->middleware('permission:segments.view')->name('marketing.segments.preview');
    Route::get('segments', [SegmentController::class, 'index'])->middleware('permission:segments.view');
    Route::post('segments', [SegmentController::class, 'store'])->middleware('permission:segments.manage');
    Route::get('segments/{segment}', [SegmentController::class, 'show'])->middleware('permission:segments.view');
    Route::patch('segments/{segment}', [SegmentController::class, 'update'])->middleware('permission:segments.manage');
    Route::delete('segments/{segment}', [SegmentController::class, 'destroy'])->middleware('permission:segments.manage');
    Route::get('audiences', [AudienceController::class, 'index'])->middleware('permission:audiences.view');
    Route::post('audiences', [AudienceController::class, 'store'])->middleware('permission:audiences.manage');
    Route::get('audiences/{audience}', [AudienceController::class, 'show'])->middleware('permission:audiences.view');
    Route::patch('audiences/{audience}', [AudienceController::class, 'update'])->middleware('permission:audiences.manage');
    Route::delete('audiences/{audience}', [AudienceController::class, 'destroy'])->middleware('permission:audiences.manage');
    Route::get('campaigns', [CampaignController::class, 'index'])->middleware('permission:campaigns.view');
    Route::post('campaigns', [CampaignController::class, 'store'])->middleware('permission:campaigns.manage');
    Route::get('campaigns/{campaign}', [CampaignController::class, 'show'])->middleware('permission:campaigns.view');
    Route::patch('campaigns/{campaign}', [CampaignController::class, 'update'])->middleware('permission:campaigns.manage');
    Route::delete('campaigns/{campaign}', [CampaignController::class, 'destroy'])->middleware('permission:campaigns.manage');
    Route::get('journeys', [JourneyController::class, 'index'])->middleware('permission:journeys.view');
    Route::post('journeys', [JourneyController::class, 'store'])->middleware('permission:journeys.manage');
    Route::get('journeys/{journey}', [JourneyController::class, 'show'])->middleware('permission:journeys.view');
    Route::patch('journeys/{journey}', [JourneyController::class, 'update'])->middleware('permission:journeys.manage');
    Route::delete('journeys/{journey}', [JourneyController::class, 'destroy'])->middleware('permission:journeys.manage');
    Route::get('segments/{segment}/members', [SegmentController::class, 'members'])->middleware('permission:segments.view');
    Route::post('segments/{segment}/refresh', [SegmentController::class, 'refresh'])->middleware('permission:segments.manage');
    Route::get('audiences/{audience}/members', [AudienceController::class, 'members'])->middleware('permission:audiences.view');
    Route::post('audiences/{audience}/refresh', [AudienceController::class, 'refresh'])->middleware('permission:audiences.manage');
    Route::post('audiences/{audience}/members', [AudienceController::class, 'changeMembers'])->middleware('permission:audiences.manage');
    Route::delete('audiences/{audience}/members', [AudienceController::class, 'changeMembers'])->middleware('permission:audiences.manage');
    Route::get('consents', [ConsentController::class, 'index'])->middleware('permission:consent.view');
    Route::get('consents/preferences', [ConsentController::class, 'preferences'])->middleware('permission:consent.view');
    Route::post('consents', [ConsentController::class, 'store'])->middleware('permission:consent.manage');
    Route::post('consent-links', [ConsentController::class, 'link'])->middleware('permission:consent.manage');
    Route::delete('consent-links/{consentLink}', [ConsentController::class, 'revokeLink'])->middleware('permission:consent.manage');
    Route::get('campaigns/{campaign}/members', [CampaignController::class, 'members'])->middleware('permission:campaigns.view');
    Route::get('campaigns/{campaign}/events', [CampaignController::class, 'events'])->middleware('permission:campaigns.view');
    Route::get('campaigns/{campaign}/metrics', [CampaignController::class, 'metrics'])->middleware('permission:campaigns.view');
    Route::post('campaigns/{campaign}/events', [CampaignController::class, 'recordEvent'])->middleware('permission:campaigns.manage');
    Route::post('campaigns/{campaign}/launch', [CampaignController::class, 'transition'])->defaults('action', 'launch')->middleware('permission:campaigns.send');
    Route::post('campaigns/{campaign}/pause', [CampaignController::class, 'transition'])->defaults('action', 'pause')->middleware('permission:campaigns.send');
    Route::post('campaigns/{campaign}/resume', [CampaignController::class, 'transition'])->defaults('action', 'resume')->middleware('permission:campaigns.send');
    Route::post('campaigns/{campaign}/cancel', [CampaignController::class, 'transition'])->defaults('action', 'cancel')->middleware('permission:campaigns.send');
    Route::post('journeys/{journey}/versions', [JourneyController::class, 'version'])->middleware('permission:journeys.manage');
    Route::post('journeys/{journey}/publish', [JourneyController::class, 'publish'])->middleware('permission:journeys.manage');
    Route::patch('journeys/{journey}/status', [JourneyController::class, 'status'])->middleware('permission:journeys.manage');
    Route::get('journeys/{journey}/enrollments', [JourneyController::class, 'enrollments'])->middleware('permission:journeys.view');
    Route::post('journeys/{journey}/enrollments', [JourneyController::class, 'enroll'])->middleware('permission:journeys.enroll');
    Route::get('journeys/{journey}/enrollments/{journeyEnrollment}', [JourneyController::class, 'enrollment'])->middleware('permission:journeys.view');
    Route::post('journeys/{journey}/enrollments/{journeyEnrollment}/pause', [JourneyController::class, 'transition'])->defaults('action', 'pause')->middleware('permission:journeys.enroll');
    Route::post('journeys/{journey}/enrollments/{journeyEnrollment}/resume', [JourneyController::class, 'transition'])->defaults('action', 'resume')->middleware('permission:journeys.enroll');
    Route::post('journeys/{journey}/enrollments/{journeyEnrollment}/cancel', [JourneyController::class, 'transition'])->defaults('action', 'cancel')->middleware('permission:journeys.enroll');
});
