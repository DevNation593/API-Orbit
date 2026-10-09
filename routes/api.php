<?php

use App\Http\Controllers\Api\ActivityController;
use App\Http\Controllers\Api\ApprovalDelegationController;
use App\Http\Controllers\Api\ApprovalProcessController;
use App\Http\Controllers\Api\ApprovalRequestController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\BundleController;
use App\Http\Controllers\Api\CalendarConnectionController;
use App\Http\Controllers\Api\CannedResponseController;
use App\Http\Controllers\Api\ContactController;
use App\Http\Controllers\Api\ContactDuplicateController;
use App\Http\Controllers\Api\ConversationController;
use App\Http\Controllers\Api\ConversationMessageController;
use App\Http\Controllers\Api\CpqRuleController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\Customer360Controller;
use App\Http\Controllers\Api\DealController;
use App\Http\Controllers\Api\DiscountController;
use App\Http\Controllers\Api\EmailAccountController;
use App\Http\Controllers\Api\EmailTemplateController;
use App\Http\Controllers\Api\EmailTrackingController;
use App\Http\Controllers\Api\EntityDefinitionController;
use App\Http\Controllers\Api\EntityRecordController;
use App\Http\Controllers\Api\EntityRelationController;
use App\Http\Controllers\Api\ErpSyncController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\FieldDefinitionController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\ForecastController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\GlobalSearchController;
use App\Http\Controllers\Api\GoalController;
use App\Http\Controllers\Api\GoalTargetController;
use App\Http\Controllers\Api\ImportController;
use App\Http\Controllers\Api\InboxChannelController;
use App\Http\Controllers\Api\InboxController;
use App\Http\Controllers\Api\IncomingWebhookController;
use App\Http\Controllers\Api\IntegrationController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\LeadRoutingRuleController;
use App\Http\Controllers\Api\LeadScoreController;
use App\Http\Controllers\Api\MeetingBookingController;
use App\Http\Controllers\Api\MeetingTypeController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\OrganizationDuplicateController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\PlaybookController;
use App\Http\Controllers\Api\PlaybookExecutionController;
use App\Http\Controllers\Api\PriceListController;
use App\Http\Controllers\Api\ProductCategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductDependencyController;
use App\Http\Controllers\Api\ProductVariantController;
use App\Http\Controllers\Api\PublicFormController;
use App\Http\Controllers\Api\PublicMeetingController;
use App\Http\Controllers\Api\PublicQuoteController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SalesAnalyticsController;
use App\Http\Controllers\Api\SalesTeamController;
use App\Http\Controllers\Api\SavedViewController;
use App\Http\Controllers\Api\ScoringModelController;
use App\Http\Controllers\Api\ScoringRuleController;
use App\Http\Controllers\Api\SequenceController;
use App\Http\Controllers\Api\SequenceEnrollmentController;
use App\Http\Controllers\Api\TagAssignmentController;
use App\Http\Controllers\Api\TagController;
use App\Http\Controllers\Api\TaskController;
use App\Http\Controllers\Api\TaxController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\TenantInvitationController;
use App\Http\Controllers\Api\TerritoryAssignmentController;
use App\Http\Controllers\Api\TerritoryController;
use App\Http\Controllers\Api\TerritoryRuleController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\WebhookEndpointController;
use App\Http\Controllers\Api\WhatsAppAccountController;
use App\Http\Controllers\Api\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

foreach ([
    'contact', 'organization', 'lead', 'deal', 'pipeline', 'task', 'activity', 'field_definition',
    'entity_definition', 'automation', 'integration', 'endpoint', 'file', 'importBatch', 'exportBatch', 'role',
    'user', 'relation', 'entityDefinition', 'savedView', 'tag', 'assignment',
    'inbox', 'channel', 'conversation', 'cannedResponse',
    'sequence', 'enrollment', 'meetingType', 'connection', 'booking',
    'currency', 'category', 'product', 'variant', 'tax', 'discount', 'bundle', 'priceList',
    'rule', 'dependency', 'process', 'approval', 'delegation', 'quote', 'sync',
    'branch', 'team', 'territory', 'territoryRule', 'territoryAssignment', 'goal', 'target',
    'playbook', 'execution',
] as $parameter) {
    Route::pattern($parameter, '[0-9]+');
}

Route::prefix('v1')->group(function (): void {
    require __DIR__.'/knowledge.php';
    require __DIR__.'/marketing.php';
    require __DIR__.'/support.php';
    require __DIR__.'/customer_portal.php';

    Route::prefix('auth')->middleware('throttle:auth')->group(function (): void {
        Route::post('register', [AuthController::class, 'register']);
        Route::post('login', [AuthController::class, 'login']);
        Route::post('forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('reset-password', [AuthController::class, 'resetPassword']);
        Route::get('invitations/{token}', [TenantInvitationController::class, 'show'])->where('token', '[A-Za-z0-9]{64}');
        Route::post('invitations/{token}/accept', [TenantInvitationController::class, 'accept'])->where('token', '[A-Za-z0-9]{64}');
    });

    Route::post('webhooks/incoming/{endpointId}/{token}', [IncomingWebhookController::class, 'receive'])
        ->middleware('throttle:webhooks')
        ->whereNumber('endpointId')
        ->where('token', '[A-Fa-f0-9]{64}');
    Route::get('webhooks/whatsapp', [WhatsAppWebhookController::class, 'verify'])->middleware('throttle:webhooks');
    Route::post('webhooks/whatsapp', [WhatsAppWebhookController::class, 'receive'])->middleware('throttle:webhooks');
    Route::get('track/email/open/{token}.gif', [EmailTrackingController::class, 'open'])
        ->middleware('throttle:webhooks')->where('token', '[A-Za-z0-9]{64}');
    Route::get('track/email/click/{token}', [EmailTrackingController::class, 'click'])
        ->middleware('throttle:webhooks')->where('token', '[A-Za-z0-9]{64}');
    Route::get('public/forms/{publicId}', [PublicFormController::class, 'show'])
        ->middleware('throttle:public-forms')->whereUuid('publicId');
    Route::post('public/forms/{publicId}/submit', [PublicFormController::class, 'submit'])
        ->middleware('throttle:public-forms')->whereUuid('publicId');
    Route::get('public/meetings/{publicId}', [PublicMeetingController::class, 'show'])
        ->middleware('throttle:public-meetings')->whereUuid('publicId');
    Route::get('public/meetings/{publicId}/availability', [PublicMeetingController::class, 'availability'])
        ->middleware('throttle:public-meetings')->whereUuid('publicId');
    Route::post('public/meetings/{publicId}/book', [PublicMeetingController::class, 'book'])
        ->middleware('throttle:public-meetings')->whereUuid('publicId');
    Route::post('public/meeting-bookings/{bookingPublicId}/cancel', [PublicMeetingController::class, 'cancel'])
        ->middleware('throttle:public-meetings')->whereUuid('bookingPublicId');
    Route::post('public/meeting-bookings/{bookingPublicId}/reschedule', [PublicMeetingController::class, 'reschedule'])
        ->middleware('throttle:public-meetings')->whereUuid('bookingPublicId');
    Route::get('public/quotes/{publicId}', [PublicQuoteController::class, 'show'])
        ->middleware('throttle:public-quotes')->whereUuid('publicId');
    Route::get('public/quotes/{publicId}/pdf', [PublicQuoteController::class, 'pdf'])
        ->middleware('throttle:public-quotes')->whereUuid('publicId');
    Route::post('public/quotes/{publicId}/accept', [PublicQuoteController::class, 'accept'])
        ->middleware('throttle:public-quotes')->whereUuid('publicId');
    Route::post('public/quotes/{publicId}/reject', [PublicQuoteController::class, 'reject'])
        ->middleware('throttle:public-quotes')->whereUuid('publicId');

    Route::middleware(['auth:sanctum', 'tenant.context'])->group(function (): void {
        Route::get('openapi.yaml', fn () => response()->file(base_path('docs/openapi.yaml'), ['Content-Type' => 'application/yaml']));

        Route::prefix('auth')->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
            Route::post('logout', [AuthController::class, 'logout']);
            Route::put('password', [AuthController::class, 'updatePassword']);
        });

        Route::get('tenants', [TenantController::class, 'index']);
        Route::get('tenant', [TenantController::class, 'current']);
        Route::post('tenants', [TenantController::class, 'store'])->middleware('permission:settings.manage');

        Route::get('search', GlobalSearchController::class);

        Route::get('notifications', [NotificationController::class, 'index']);
        Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount']);
        Route::patch('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::patch('notifications/{notification}/read', [NotificationController::class, 'markRead'])->whereUuid('notification');
        Route::patch('notifications/{notification}/unread', [NotificationController::class, 'markUnread'])->whereUuid('notification');
        Route::delete('notifications/{notification}', [NotificationController::class, 'destroy'])->whereUuid('notification');
        Route::get('notification-preferences', [NotificationPreferenceController::class, 'index']);
        Route::put('notification-preferences', [NotificationPreferenceController::class, 'update']);

        Route::get('inboxes', [InboxController::class, 'index']);
        Route::post('inboxes', [InboxController::class, 'store']);
        Route::get('inboxes/{inbox}', [InboxController::class, 'show'])->whereNumber('inbox');
        Route::patch('inboxes/{inbox}', [InboxController::class, 'update'])->whereNumber('inbox');
        Route::post('inboxes/{inbox}/channels', [InboxChannelController::class, 'store'])->whereNumber('inbox');
        Route::patch('inbox-channels/{channel}', [InboxChannelController::class, 'update'])->whereNumber('channel');
        Route::delete('inbox-channels/{channel}', [InboxChannelController::class, 'destroy'])->whereNumber('channel');

        Route::get('conversations', [ConversationController::class, 'index']);
        Route::post('conversations', [ConversationController::class, 'store']);
        Route::get('conversations/{conversation}', [ConversationController::class, 'show'])->whereNumber('conversation');
        Route::patch('conversations/{conversation}/assign', [ConversationController::class, 'assign'])->whereNumber('conversation');
        Route::patch('conversations/{conversation}/status', [ConversationController::class, 'status'])->whereNumber('conversation');
        Route::patch('conversations/{conversation}/read', [ConversationController::class, 'markRead'])->whereNumber('conversation');
        Route::get('conversations/{conversation}/messages', [ConversationMessageController::class, 'index'])->whereNumber('conversation');
        Route::post('conversations/{conversation}/messages', [ConversationMessageController::class, 'store'])->whereNumber('conversation');
        Route::get('canned-responses', [CannedResponseController::class, 'index']);
        Route::post('canned-responses', [CannedResponseController::class, 'store']);
        Route::patch('canned-responses/{cannedResponse}', [CannedResponseController::class, 'update'])->whereNumber('cannedResponse');
        Route::delete('canned-responses/{cannedResponse}', [CannedResponseController::class, 'destroy'])->whereNumber('cannedResponse');

        Route::get('email/accounts', [EmailAccountController::class, 'index']);
        Route::post('email/accounts', [EmailAccountController::class, 'store']);
        Route::get('email/accounts/{account}', [EmailAccountController::class, 'show'])->whereNumber('account');
        Route::patch('email/accounts/{account}', [EmailAccountController::class, 'update'])->whereNumber('account');
        Route::post('email/accounts/{account}/connect', [EmailAccountController::class, 'connect'])->whereNumber('account');
        Route::post('email/accounts/{account}/disconnect', [EmailAccountController::class, 'disconnect'])->whereNumber('account');
        Route::post('email/accounts/{account}/sync', [EmailAccountController::class, 'sync'])->whereNumber('account');
        Route::get('email/templates', [EmailTemplateController::class, 'index']);
        Route::post('email/templates', [EmailTemplateController::class, 'store']);
        Route::get('email/templates/{template}', [EmailTemplateController::class, 'show'])->whereNumber('template');
        Route::patch('email/templates/{template}', [EmailTemplateController::class, 'update'])->whereNumber('template');
        Route::delete('email/templates/{template}', [EmailTemplateController::class, 'destroy'])->whereNumber('template');
        Route::get('whatsapp/accounts', [WhatsAppAccountController::class, 'index']);
        Route::post('whatsapp/accounts', [WhatsAppAccountController::class, 'store']);
        Route::get('whatsapp/accounts/{account}', [WhatsAppAccountController::class, 'show'])->whereNumber('account');
        Route::patch('whatsapp/accounts/{account}', [WhatsAppAccountController::class, 'update'])->whereNumber('account');
        Route::get('whatsapp/accounts/{account}/templates', [WhatsAppAccountController::class, 'templates'])->whereNumber('account');
        Route::post('whatsapp/accounts/{account}/templates/sync', [WhatsAppAccountController::class, 'syncTemplates'])->whereNumber('account');

        Route::post('contacts/duplicate-check', [ContactDuplicateController::class, 'check']);
        Route::post('contacts/{contact}/merge', [ContactDuplicateController::class, 'merge'])->whereNumber('contact');
        Route::get('contacts/{contact}/overview', [Customer360Controller::class, 'overview'])->whereNumber('contact');
        Route::get('contacts/{contact}/timeline', [Customer360Controller::class, 'timeline'])->whereNumber('contact');
        Route::post('organizations/duplicate-check', [OrganizationDuplicateController::class, 'check']);
        Route::post('organizations/{organization}/merge', [OrganizationDuplicateController::class, 'merge'])->whereNumber('organization');
        Route::post('companies/duplicate-check', [OrganizationDuplicateController::class, 'check']);
        Route::post('companies/{organization}/merge', [OrganizationDuplicateController::class, 'merge'])->whereNumber('organization');

        Route::get('forms', [FormController::class, 'index']);
        Route::post('forms', [FormController::class, 'store']);
        Route::get('forms/{form}', [FormController::class, 'show'])->whereNumber('form');
        Route::patch('forms/{form}', [FormController::class, 'update'])->whereNumber('form');
        Route::delete('forms/{form}', [FormController::class, 'destroy'])->whereNumber('form');
        Route::post('forms/{form}/rotate-public-id', [FormController::class, 'rotatePublicId'])->whereNumber('form');
        Route::get('forms/{form}/submissions', [FormController::class, 'submissions'])->whereNumber('form');
        Route::get('forms/{form}/submissions/{submission}', [FormController::class, 'submission'])
            ->whereNumber('form')->whereNumber('submission');

        Route::get('lead-routing-rules', [LeadRoutingRuleController::class, 'index']);
        Route::post('lead-routing-rules', [LeadRoutingRuleController::class, 'store']);
        Route::get('lead-routing-rules/{rule}', [LeadRoutingRuleController::class, 'show'])->whereNumber('rule');
        Route::patch('lead-routing-rules/{rule}', [LeadRoutingRuleController::class, 'update'])->whereNumber('rule');
        Route::delete('lead-routing-rules/{rule}', [LeadRoutingRuleController::class, 'destroy'])->whereNumber('rule');
        Route::post('leads/{lead}/route', [LeadRoutingRuleController::class, 'routeLead'])->whereNumber('lead');
        Route::get('leads/{lead}/routing-executions', [LeadRoutingRuleController::class, 'executions'])->whereNumber('lead');

        Route::get('scoring-models', [ScoringModelController::class, 'index']);
        Route::post('scoring-models', [ScoringModelController::class, 'store']);
        Route::get('scoring-models/{model}', [ScoringModelController::class, 'show'])->whereNumber('model');
        Route::patch('scoring-models/{model}', [ScoringModelController::class, 'update'])->whereNumber('model');
        Route::delete('scoring-models/{model}', [ScoringModelController::class, 'destroy'])->whereNumber('model');
        Route::post('scoring-models/{model}/rules', [ScoringRuleController::class, 'store'])->whereNumber('model');
        Route::patch('scoring-rules/{rule}', [ScoringRuleController::class, 'update'])->whereNumber('rule');
        Route::delete('scoring-rules/{rule}', [ScoringRuleController::class, 'destroy'])->whereNumber('rule');
        Route::get('leads/{lead}/scores', [LeadScoreController::class, 'index'])->whereNumber('lead');
        Route::post('leads/{lead}/scores/recalculate', [LeadScoreController::class, 'recalculate'])->whereNumber('lead');
        Route::post('leads/{lead}/score-events', [LeadScoreController::class, 'event'])->whereNumber('lead');

        Route::get('sequences', [SequenceController::class, 'index']);
        Route::post('sequences', [SequenceController::class, 'store']);
        Route::get('sequences/{sequence}', [SequenceController::class, 'show'])->whereNumber('sequence');
        Route::patch('sequences/{sequence}', [SequenceController::class, 'update'])->whereNumber('sequence');
        Route::delete('sequences/{sequence}', [SequenceController::class, 'destroy'])->whereNumber('sequence');
        Route::post('sequences/{sequence}/enrollments', [SequenceEnrollmentController::class, 'store'])->whereNumber('sequence');
        Route::get('sequence-enrollments', [SequenceEnrollmentController::class, 'index']);
        Route::get('sequence-enrollments/{enrollment}', [SequenceEnrollmentController::class, 'show'])->whereNumber('enrollment');
        Route::post('sequence-enrollments/{enrollment}/pause', [SequenceEnrollmentController::class, 'pause'])->whereNumber('enrollment');
        Route::post('sequence-enrollments/{enrollment}/resume', [SequenceEnrollmentController::class, 'resume'])->whereNumber('enrollment');
        Route::post('sequence-enrollments/{enrollment}/stop', [SequenceEnrollmentController::class, 'stop'])->whereNumber('enrollment');
        Route::get('sequence-enrollments/{enrollment}/executions', [SequenceEnrollmentController::class, 'executions'])->whereNumber('enrollment');

        Route::get('meeting-types', [MeetingTypeController::class, 'index']);
        Route::post('meeting-types', [MeetingTypeController::class, 'store']);
        Route::get('meeting-types/{meetingType}', [MeetingTypeController::class, 'show'])->whereNumber('meetingType');
        Route::patch('meeting-types/{meetingType}', [MeetingTypeController::class, 'update'])->whereNumber('meetingType');
        Route::delete('meeting-types/{meetingType}', [MeetingTypeController::class, 'destroy'])->whereNumber('meetingType');
        Route::post('meeting-types/{meetingType}/rotate-public-id', [MeetingTypeController::class, 'rotatePublicId'])->whereNumber('meetingType');
        Route::get('calendar-connections', [CalendarConnectionController::class, 'index']);
        Route::post('calendar-connections', [CalendarConnectionController::class, 'store']);
        Route::get('calendar-connections/{connection}', [CalendarConnectionController::class, 'show'])->whereNumber('connection');
        Route::patch('calendar-connections/{connection}', [CalendarConnectionController::class, 'update'])->whereNumber('connection');
        Route::delete('calendar-connections/{connection}', [CalendarConnectionController::class, 'destroy'])->whereNumber('connection');
        Route::get('meeting-bookings', [MeetingBookingController::class, 'index']);
        Route::get('meeting-bookings/{booking}', [MeetingBookingController::class, 'show'])->whereNumber('booking');
        Route::post('meeting-bookings/{booking}/cancel', [MeetingBookingController::class, 'cancel'])->whereNumber('booking');
        Route::post('meeting-bookings/{booking}/sync', [MeetingBookingController::class, 'sync'])->whereNumber('booking');

        Route::get('currencies', [CurrencyController::class, 'index']);
        Route::post('currencies', [CurrencyController::class, 'store']);
        Route::get('currencies/{currency}', [CurrencyController::class, 'show'])->whereNumber('currency');
        Route::patch('currencies/{currency}', [CurrencyController::class, 'update'])->whereNumber('currency');
        Route::delete('currencies/{currency}', [CurrencyController::class, 'destroy'])->whereNumber('currency');
        Route::get('product-categories', [ProductCategoryController::class, 'index']);
        Route::post('product-categories', [ProductCategoryController::class, 'store']);
        Route::get('product-categories/{category}', [ProductCategoryController::class, 'show'])->whereNumber('category');
        Route::patch('product-categories/{category}', [ProductCategoryController::class, 'update'])->whereNumber('category');
        Route::delete('product-categories/{category}', [ProductCategoryController::class, 'destroy'])->whereNumber('category');
        Route::get('products', [ProductController::class, 'index']);
        Route::post('products', [ProductController::class, 'store']);
        Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product');
        Route::patch('products/{product}', [ProductController::class, 'update'])->whereNumber('product');
        Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product');
        Route::post('products/{product}/sync-erp', [ProductController::class, 'sync'])->whereNumber('product');
        Route::post('products/{product}/variants', [ProductVariantController::class, 'store'])->whereNumber('product');
        Route::patch('products/{product}/variants/{variant}', [ProductVariantController::class, 'update'])->whereNumber('product')->whereNumber('variant');
        Route::delete('products/{product}/variants/{variant}', [ProductVariantController::class, 'destroy'])->whereNumber('product')->whereNumber('variant');
        Route::get('taxes', [TaxController::class, 'index']);
        Route::post('taxes', [TaxController::class, 'store']);
        Route::get('taxes/{tax}', [TaxController::class, 'show'])->whereNumber('tax');
        Route::patch('taxes/{tax}', [TaxController::class, 'update'])->whereNumber('tax');
        Route::delete('taxes/{tax}', [TaxController::class, 'destroy'])->whereNumber('tax');
        Route::get('discounts', [DiscountController::class, 'index']);
        Route::post('discounts', [DiscountController::class, 'store']);
        Route::get('discounts/{discount}', [DiscountController::class, 'show'])->whereNumber('discount');
        Route::patch('discounts/{discount}', [DiscountController::class, 'update'])->whereNumber('discount');
        Route::delete('discounts/{discount}', [DiscountController::class, 'destroy'])->whereNumber('discount');
        Route::get('bundles', [BundleController::class, 'index']);
        Route::post('bundles', [BundleController::class, 'store']);
        Route::get('bundles/{bundle}', [BundleController::class, 'show'])->whereNumber('bundle');
        Route::patch('bundles/{bundle}', [BundleController::class, 'update'])->whereNumber('bundle');
        Route::delete('bundles/{bundle}', [BundleController::class, 'destroy'])->whereNumber('bundle');
        Route::get('price-lists', [PriceListController::class, 'index']);
        Route::post('price-lists', [PriceListController::class, 'store']);
        Route::get('price-lists/{priceList}', [PriceListController::class, 'show'])->whereNumber('priceList');
        Route::patch('price-lists/{priceList}', [PriceListController::class, 'update'])->whereNumber('priceList');
        Route::delete('price-lists/{priceList}', [PriceListController::class, 'destroy'])->whereNumber('priceList');
        Route::get('cpq/rules/{ruleType}', [CpqRuleController::class, 'index'])->whereIn('ruleType', ['pricing', 'discount', 'bundle', 'approval']);
        Route::post('cpq/rules/{ruleType}', [CpqRuleController::class, 'store'])->whereIn('ruleType', ['pricing', 'discount', 'bundle', 'approval']);
        Route::get('cpq/rules/{ruleType}/{rule}', [CpqRuleController::class, 'show'])->whereIn('ruleType', ['pricing', 'discount', 'bundle', 'approval'])->whereNumber('rule');
        Route::patch('cpq/rules/{ruleType}/{rule}', [CpqRuleController::class, 'update'])->whereIn('ruleType', ['pricing', 'discount', 'bundle', 'approval'])->whereNumber('rule');
        Route::delete('cpq/rules/{ruleType}/{rule}', [CpqRuleController::class, 'destroy'])->whereIn('ruleType', ['pricing', 'discount', 'bundle', 'approval'])->whereNumber('rule');
        Route::get('product-dependencies', [ProductDependencyController::class, 'index']);
        Route::post('product-dependencies', [ProductDependencyController::class, 'store']);
        Route::patch('product-dependencies/{dependency}', [ProductDependencyController::class, 'update'])->whereNumber('dependency');
        Route::delete('product-dependencies/{dependency}', [ProductDependencyController::class, 'destroy'])->whereNumber('dependency');

        Route::get('approval-processes', [ApprovalProcessController::class, 'index']);
        Route::post('approval-processes', [ApprovalProcessController::class, 'store']);
        Route::get('approval-processes/{process}', [ApprovalProcessController::class, 'show'])->whereNumber('process');
        Route::patch('approval-processes/{process}', [ApprovalProcessController::class, 'update'])->whereNumber('process');
        Route::delete('approval-processes/{process}', [ApprovalProcessController::class, 'destroy'])->whereNumber('process');
        Route::post('approval-processes/{process}/versions', [ApprovalProcessController::class, 'version'])->whereNumber('process');
        Route::get('approval-requests', [ApprovalRequestController::class, 'index']);
        Route::get('approval-requests/{approval}', [ApprovalRequestController::class, 'show'])->whereNumber('approval');
        Route::post('approval-requests/{approval}/decide', [ApprovalRequestController::class, 'decide'])->whereNumber('approval');
        Route::post('approval-requests/{approval}/cancel', [ApprovalRequestController::class, 'cancel'])->whereNumber('approval');
        Route::get('approval-delegations', [ApprovalDelegationController::class, 'index']);
        Route::post('approval-delegations', [ApprovalDelegationController::class, 'store']);
        Route::patch('approval-delegations/{delegation}', [ApprovalDelegationController::class, 'update'])->whereNumber('delegation');
        Route::delete('approval-delegations/{delegation}', [ApprovalDelegationController::class, 'destroy'])->whereNumber('delegation');

        Route::get('quotes', [QuoteController::class, 'index']);
        Route::post('quotes', [QuoteController::class, 'store']);
        Route::get('quotes/{quote}', [QuoteController::class, 'show'])->whereNumber('quote');
        Route::patch('quotes/{quote}', [QuoteController::class, 'update'])->whereNumber('quote');
        Route::delete('quotes/{quote}', [QuoteController::class, 'destroy'])->whereNumber('quote');
        Route::post('quotes/{quote}/duplicate', [QuoteController::class, 'duplicate'])->whereNumber('quote');
        Route::post('quotes/{quote}/revise', [QuoteController::class, 'revise'])->whereNumber('quote');
        Route::post('quotes/{quote}/submit', [QuoteController::class, 'submit'])->whereNumber('quote');
        Route::post('quotes/{quote}/approve', [QuoteController::class, 'approve'])->whereNumber('quote');
        Route::post('quotes/{quote}/reject-approval', [QuoteController::class, 'rejectApproval'])->whereNumber('quote');
        Route::post('quotes/{quote}/send', [QuoteController::class, 'send'])->whereNumber('quote');
        Route::post('quotes/{quote}/accept', [QuoteController::class, 'accept'])->whereNumber('quote');
        Route::post('quotes/{quote}/reject', [QuoteController::class, 'reject'])->whereNumber('quote');
        Route::post('quotes/{quote}/cancel', [QuoteController::class, 'cancel'])->whereNumber('quote');
        Route::post('quotes/{quote}/pdf', [QuoteController::class, 'generatePdf'])->whereNumber('quote');
        Route::get('quotes/{quote}/pdf', [QuoteController::class, 'downloadPdf'])->whereNumber('quote');
        Route::get('quotes/{quote}/activities', [QuoteController::class, 'activities'])->whereNumber('quote');
        Route::post('quotes/{quote}/sync-erp', [QuoteController::class, 'syncErp'])->whereNumber('quote');

        Route::get('branches', [BranchController::class, 'index']);
        Route::post('branches', [BranchController::class, 'store']);
        Route::get('branches/{branch}', [BranchController::class, 'show'])->whereNumber('branch');
        Route::patch('branches/{branch}', [BranchController::class, 'update'])->whereNumber('branch');
        Route::delete('branches/{branch}', [BranchController::class, 'destroy'])->whereNumber('branch');
        Route::get('branches/{branch}/members', [BranchController::class, 'members'])->whereNumber('branch');
        Route::post('branches/{branch}/members', [BranchController::class, 'saveMember'])->whereNumber('branch');
        Route::patch('branches/{branch}/members/{user}', [BranchController::class, 'updateMember'])->whereNumber('branch')->whereNumber('user');
        Route::delete('branches/{branch}/members/{user}', [BranchController::class, 'removeMember'])->whereNumber('branch')->whereNumber('user');

        Route::get('sales-teams', [SalesTeamController::class, 'index']);
        Route::post('sales-teams', [SalesTeamController::class, 'store']);
        Route::get('sales-teams/{team}', [SalesTeamController::class, 'show'])->whereNumber('team');
        Route::patch('sales-teams/{team}', [SalesTeamController::class, 'update'])->whereNumber('team');
        Route::delete('sales-teams/{team}', [SalesTeamController::class, 'destroy'])->whereNumber('team');
        Route::get('sales-teams/{team}/members', [SalesTeamController::class, 'members'])->whereNumber('team');
        Route::post('sales-teams/{team}/members', [SalesTeamController::class, 'saveMember'])->whereNumber('team');
        Route::patch('sales-teams/{team}/members/{user}', [SalesTeamController::class, 'updateMember'])->whereNumber('team')->whereNumber('user');
        Route::delete('sales-teams/{team}/members/{user}', [SalesTeamController::class, 'removeMember'])->whereNumber('team')->whereNumber('user');

        Route::get('territories', [TerritoryController::class, 'index']);
        Route::post('territories', [TerritoryController::class, 'store']);
        Route::get('territories/{territory}', [TerritoryController::class, 'show'])->whereNumber('territory');
        Route::patch('territories/{territory}', [TerritoryController::class, 'update'])->whereNumber('territory');
        Route::delete('territories/{territory}', [TerritoryController::class, 'destroy'])->whereNumber('territory');
        Route::get('territories/{territory}/members', [TerritoryController::class, 'members'])->whereNumber('territory');
        Route::post('territories/{territory}/members', [TerritoryController::class, 'saveMember'])->whereNumber('territory');
        Route::patch('territories/{territory}/members/{user}', [TerritoryController::class, 'updateMember'])->whereNumber('territory')->whereNumber('user');
        Route::delete('territories/{territory}/members/{user}', [TerritoryController::class, 'removeMember'])->whereNumber('territory')->whereNumber('user');
        Route::get('territory-rules', [TerritoryRuleController::class, 'index']);
        Route::post('territory-rules', [TerritoryRuleController::class, 'store']);
        Route::get('territory-rules/{rule}', [TerritoryRuleController::class, 'show'])->whereNumber('rule');
        Route::patch('territory-rules/{rule}', [TerritoryRuleController::class, 'update'])->whereNumber('rule');
        Route::delete('territory-rules/{rule}', [TerritoryRuleController::class, 'destroy'])->whereNumber('rule');
        Route::get('territory-assignments', [TerritoryAssignmentController::class, 'index']);
        Route::post('territory-assignments', [TerritoryAssignmentController::class, 'store']);
        Route::delete('territory-assignments/{assignment}', [TerritoryAssignmentController::class, 'destroy'])->whereNumber('assignment');

        Route::get('goals', [GoalController::class, 'index']);
        Route::post('goals', [GoalController::class, 'store']);
        Route::get('goals/{goal}', [GoalController::class, 'show'])->whereNumber('goal');
        Route::patch('goals/{goal}', [GoalController::class, 'update'])->whereNumber('goal');
        Route::delete('goals/{goal}', [GoalController::class, 'destroy'])->whereNumber('goal');
        Route::post('goals/{goal}/refresh', [GoalController::class, 'refresh'])->whereNumber('goal');
        Route::post('goals/{goal}/targets', [GoalTargetController::class, 'store'])->whereNumber('goal');
        Route::patch('goals/{goal}/targets/{target}', [GoalTargetController::class, 'update'])->whereNumber('goal')->whereNumber('target');
        Route::delete('goals/{goal}/targets/{target}', [GoalTargetController::class, 'destroy'])->whereNumber('goal')->whereNumber('target');
        Route::post('goals/{goal}/targets/{target}/refresh', [GoalTargetController::class, 'refresh'])->whereNumber('goal')->whereNumber('target');

        Route::get('forecast', [ForecastController::class, 'summary']);
        Route::get('forecast/users', [ForecastController::class, 'users']);
        Route::get('forecast/teams', [ForecastController::class, 'teams']);
        Route::get('forecast/snapshots', [ForecastController::class, 'snapshots']);
        Route::post('forecast/snapshots', [ForecastController::class, 'snapshot']);
        Route::get('sales-analytics', SalesAnalyticsController::class);

        Route::get('playbooks', [PlaybookController::class, 'index']);
        Route::post('playbooks', [PlaybookController::class, 'store']);
        Route::get('playbooks/{playbook}', [PlaybookController::class, 'show'])->whereNumber('playbook');
        Route::patch('playbooks/{playbook}', [PlaybookController::class, 'update'])->whereNumber('playbook');
        Route::delete('playbooks/{playbook}', [PlaybookController::class, 'destroy'])->whereNumber('playbook');
        Route::post('playbooks/{playbook}/versions', [PlaybookController::class, 'version'])->whereNumber('playbook');
        Route::get('playbook-executions', [PlaybookExecutionController::class, 'index']);
        Route::post('playbook-executions', [PlaybookExecutionController::class, 'store']);
        Route::get('playbook-executions/{execution}', [PlaybookExecutionController::class, 'show'])->whereNumber('execution');
        Route::post('playbook-executions/{execution}/answers', [PlaybookExecutionController::class, 'answer'])->whereNumber('execution');
        Route::post('playbook-executions/{execution}/complete', [PlaybookExecutionController::class, 'complete'])->whereNumber('execution');
        Route::post('playbook-executions/{execution}/cancel', [PlaybookExecutionController::class, 'cancel'])->whereNumber('execution');

        Route::get('erp/syncs', [ErpSyncController::class, 'index']);
        Route::get('erp/syncs/{sync}', [ErpSyncController::class, 'show'])->whereNumber('sync');
        Route::post('erp/syncs/{sync}/retry', [ErpSyncController::class, 'retry'])->whereNumber('sync');

        Route::apiResource('contacts', ContactController::class)->except(['create', 'edit']);
        Route::apiResource('organizations', OrganizationController::class)->except(['create', 'edit']);
        Route::apiResource('leads', LeadController::class)->except(['create', 'edit']);
        Route::post('leads/{lead}/convert', [LeadController::class, 'convert'])->whereNumber('lead');
        Route::apiResource('deals', DealController::class)->except(['create', 'edit']);
        Route::apiResource('pipelines', PipelineController::class)->except(['create', 'edit']);
        Route::apiResource('tasks', TaskController::class)->except(['create', 'edit']);
        Route::apiResource('activities', ActivityController::class)->only(['index', 'store']);

        Route::get('saved-views', [SavedViewController::class, 'index']);
        Route::post('saved-views', [SavedViewController::class, 'store']);
        Route::get('saved-views/{savedView}', [SavedViewController::class, 'show'])->whereNumber('savedView');
        Route::patch('saved-views/{savedView}', [SavedViewController::class, 'update'])->whereNumber('savedView');
        Route::delete('saved-views/{savedView}', [SavedViewController::class, 'destroy'])->whereNumber('savedView');
        Route::get('tags', [TagController::class, 'index']);
        Route::post('tags', [TagController::class, 'store']);
        Route::get('tags/{tag}', [TagController::class, 'show'])->whereNumber('tag');
        Route::patch('tags/{tag}', [TagController::class, 'update'])->whereNumber('tag');
        Route::delete('tags/{tag}', [TagController::class, 'destroy'])->whereNumber('tag');
        Route::get('tags/{tag}/assignments', [TagAssignmentController::class, 'index'])->whereNumber('tag');
        Route::post('tags/{tag}/assignments', [TagAssignmentController::class, 'store'])->whereNumber('tag');
        Route::delete('tags/{tag}/assignments/{assignment}', [TagAssignmentController::class, 'destroy'])
            ->whereNumber('tag')->whereNumber('assignment');

        Route::apiResource('field-definitions', FieldDefinitionController::class)->except(['create', 'edit']);
        Route::apiResource('entity-definitions', EntityDefinitionController::class)->except(['create', 'edit']);
        Route::get('entities/{entityDefinition}/records', [EntityRecordController::class, 'index'])->whereNumber('entityDefinition');
        Route::post('entities/{entityDefinition}/records', [EntityRecordController::class, 'store'])->whereNumber('entityDefinition');
        Route::get('entities/{entityDefinition}/records/{id}', [EntityRecordController::class, 'show'])->whereNumber('id');
        Route::patch('entities/{entityDefinition}/records/{id}', [EntityRecordController::class, 'update'])->whereNumber('id');
        Route::delete('entities/{entityDefinition}/records/{id}', [EntityRecordController::class, 'destroy'])->whereNumber('id');
        Route::get('relations/options', [EntityRelationController::class, 'options']);
        Route::apiResource('relations', EntityRelationController::class)->only(['index', 'store', 'destroy']);

        Route::apiResource('automations', AutomationController::class)->except(['create', 'edit']);
        Route::apiResource('webhooks/endpoints', WebhookEndpointController::class)->only(['index', 'store', 'update', 'destroy']);
        Route::apiResource('files', FileController::class)->only(['index', 'store', 'destroy']);
        Route::get('files/{file}/download', [FileController::class, 'download'])->whereNumber('file');
        Route::post('imports', [ImportController::class, 'store']);
        Route::get('imports/{importBatch}', [ImportController::class, 'show'])->whereNumber('importBatch');
        Route::post('exports', [ExportController::class, 'store'])->middleware('throttle:exports');
        Route::get('exports/{exportBatch}', [ExportController::class, 'show'])->whereNumber('exportBatch');
        Route::get('integrations/providers', [IntegrationController::class, 'providers']);
        Route::post('integrations/{integration}/health', [IntegrationController::class, 'health'])->whereNumber('integration');
        Route::post('integrations/{integration}/connect', [IntegrationController::class, 'connect'])->whereNumber('integration');
        Route::post('integrations/{integration}/disconnect', [IntegrationController::class, 'disconnect'])->whereNumber('integration');
        Route::apiResource('integrations', IntegrationController::class)->except(['create', 'edit']);

        Route::get('roles', [RoleController::class, 'index']);
        Route::post('roles', [RoleController::class, 'store']);
        Route::patch('roles/{role}', [RoleController::class, 'update'])->whereNumber('role');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->whereNumber('role');
        Route::get('users', [UserController::class, 'index']);
        Route::patch('users/{user}/role', [UserController::class, 'updateRole'])->whereNumber('user');
        Route::get('users/invitations', [TenantInvitationController::class, 'index']);
        Route::post('users/invitations', [TenantInvitationController::class, 'store']);
        Route::delete('users/invitations/{invitation}', [TenantInvitationController::class, 'destroy'])->whereNumber('invitation');
        Route::get('audit-logs', [AuditController::class, 'index']);
    });
});
