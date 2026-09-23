<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\CustomerPortal;
use App\Models\PortalInvitation;
use App\Models\PortalUser;
use App\Models\User;
use App\Notifications\PortalInvitationNotification;
use App\Support\AuditService;
use App\Support\PortalEmail;
use App\Support\PortalToken;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use LogicException;
use stdClass;
use Throwable;

class PortalInvitationService
{
    public function __construct(private readonly AuditService $audit) {}

    /**
     * @return array{invitation: PortalInvitation, notification_sent: bool, activation_url: string}
     */
    public function invite(Contact $contact, User $actor): array
    {
        $this->assertNoAmbientTransaction();
        $plainToken = PortalToken::issue();
        $result = DB::transaction(function () use ($contact, $actor, $plainToken): array {
            $portal = CustomerPortal::query()
                ->lockForUpdate()
                ->first();
            abort_if(
                $portal === null || ! $portal->is_active,
                409,
                'Activate the customer portal before inviting contacts.',
            );
            $locked = Contact::query()->lockForUpdate()->findOrFail($contact->id);

            $email = PortalEmail::normalize($locked->email);
            throw_if($email === null, ValidationException::withMessages([
                'contact_id' => ['The selected contact needs a valid email address.'],
            ]));

            $accountExists = PortalUser::query()
                ->where(function (Builder $query) use ($locked, $email): void {
                    $query->where('contact_id', $locked->id)->orWhere('email', $email);
                })->exists();
            abort_if(
                $accountExists,
                409,
                'A portal account already exists for this contact or email.',
            );

            PortalInvitation::query()
                ->where('contact_id', $locked->id)
                ->where('status', PortalInvitation::STATUS_PENDING)
                ->lockForUpdate()
                ->get()
                ->each(function (PortalInvitation $invitation): void {
                    $invitation->update(['status' => PortalInvitation::STATUS_REVOKED]);
                });

            $tokenHash = PortalToken::hash($plainToken);
            $invitation = PortalInvitation::create([
                'contact_id' => $locked->id,
                'invited_by' => $actor->id,
                'email' => $email,
                'token_hash' => $tokenHash,
                'status' => PortalInvitation::STATUS_PENDING,
                'expires_at' => now()->addDays(7),
            ]);
            DB::table('portal_invitation_locators')->insert([
                'token_hash' => $tokenHash,
                'invitation_id' => $invitation->id,
                'tenant_id' => $invitation->tenant_id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->audit->record(
                'portal.user.invited',
                $invitation,
                newValues: [
                    'contact_id' => $locked->id,
                    'expires_at' => $invitation->expires_at,
                ],
                actor: $actor,
            );

            return [
                'portal' => $portal,
                'contact' => $locked,
                'invitation' => $invitation,
                'activation_url' => url('/api/v1/portal/invitations/'.$plainToken),
            ];
        });

        $notificationSent = true;
        try {
            Notification::route('mail', $result['invitation']->email)->notify(
                new PortalInvitationNotification(
                    $result['portal']->title,
                    $this->contactName($result['contact']),
                    $result['activation_url'],
                ),
            );
        } catch (Throwable) {
            $notificationSent = false;
        }

        return [
            'invitation' => $result['invitation'],
            'notification_sent' => $notificationSent,
            'activation_url' => $result['activation_url'],
        ];
    }

    public function inspect(string $token): array
    {
        return $this->withLocatedInvitation(
            $token,
            function (stdClass $locator, string $tokenHash): array {
                $result = DB::transaction(function () use ($locator, $tokenHash): array {
                    $invitation = $this->lockedInvitation($locator, $tokenHash);
                    if ($invitation === null) {
                        return $this->errorResult(404, 'Resource not found.');
                    }

                    if ($stateError = $this->invitationStateError($invitation)) {
                        return $stateError;
                    }

                    $portal = CustomerPortal::forTenant((int) $locator->tenant_id)
                        ->where('is_active', true)
                        ->first();
                    if ($portal === null) {
                        return $this->errorResult(
                            409,
                            'The customer portal is not active.',
                        );
                    }

                    $contactResult = $this->invitationContactResult($locator, $invitation);
                    if (isset($contactResult['error_status'])) {
                        return $contactResult;
                    }
                    $contact = $contactResult['contact'];

                    return ['profile' => [
                        'portal_public_id' => $portal->public_id,
                        'portal_title' => $portal->title,
                        'email' => PortalEmail::mask($invitation->email),
                        'contact_name' => $this->contactName($contact),
                        'expires_at' => $invitation->expires_at,
                    ]];
                });

                $this->throwResultError($result);

                return $result['profile'];
            },
        );
    }

    /**
     * @return array{portal: CustomerPortal, user: PortalUser, access_token: string, expires_at: CarbonImmutable}
     */
    public function accept(string $token, array $data): array
    {
        $this->assertNoAmbientTransaction();
        $result = $this->withLocatedInvitation(
            $token,
            function (stdClass $locator, string $tokenHash) use ($data): array {
                $result = DB::transaction(function () use ($locator, $tokenHash, $data): array {
                    $portal = CustomerPortal::forTenant((int) $locator->tenant_id)
                        ->lockForUpdate()
                        ->first();
                    $invitation = $this->lockedInvitation($locator, $tokenHash);
                    if ($invitation === null) {
                        return $this->errorResult(404, 'Resource not found.');
                    }

                    if ($stateError = $this->invitationStateError($invitation)) {
                        return $stateError;
                    }

                    if ($portal === null || ! $portal->is_active) {
                        return $this->errorResult(
                            409,
                            'The customer portal is not active.',
                        );
                    }

                    $contactResult = $this->invitationContactResult(
                        $locator,
                        $invitation,
                        lockForUpdate: true,
                    );
                    if (isset($contactResult['error_status'])) {
                        return $contactResult;
                    }
                    $contact = $contactResult['contact'];
                    $currentEmail = $contactResult['email'];

                    try {
                        $portalUser = PortalUser::create([
                            'contact_id' => $contact->id,
                            'email' => $currentEmail,
                            'password' => $data['password'],
                            'status' => PortalUser::STATUS_ACTIVE,
                            'email_verified_at' => now(),
                        ]);
                    } catch (QueryException $exception) {
                        if (! $this->isPortalUserIdentityConflict($exception)) {
                            throw $exception;
                        }

                        abort(
                            409,
                            'A portal account already exists for this contact or email.',
                        );
                    }
                    $portalUser->setRelation('contact', $contact);
                    $invitation->update([
                        'status' => PortalInvitation::STATUS_ACCEPTED,
                        'accepted_at' => now(),
                    ]);
                    $this->audit->record(
                        'portal.invitation.accepted',
                        $invitation,
                        newValues: [
                            'contact_id' => $contact->id,
                            'email' => $currentEmail,
                            'status' => PortalInvitation::STATUS_ACCEPTED,
                            'accepted_at' => $invitation->accepted_at,
                        ],
                        actor: $portalUser,
                    );

                    return ['portal' => $portal, 'user' => $portalUser];
                });

                $this->throwResultError($result);

                return $result;
            },
        );

        $expiresAt = CarbonImmutable::now()->addDays(30);
        $deviceName = filled($data['device_name'] ?? null)
            ? (string) $data['device_name']
            : 'portal';
        $accessToken = $result['user']
            ->createToken($deviceName, ['portal'], $expiresAt)
            ->plainTextToken;

        return [
            'portal' => $result['portal'],
            'user' => $result['user'],
            'access_token' => $accessToken,
            'expires_at' => $expiresAt,
        ];
    }

    public function revoke(PortalInvitation $invitation, User $actor): PortalInvitation
    {
        return DB::transaction(function () use ($invitation, $actor): PortalInvitation {
            $locked = PortalInvitation::query()
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();
            abort_if(
                $locked->status === PortalInvitation::STATUS_ACCEPTED,
                409,
                'An accepted invitation cannot be revoked.',
            );

            if ($locked->status !== PortalInvitation::STATUS_PENDING) {
                return $locked;
            }

            $locked->update(['status' => PortalInvitation::STATUS_REVOKED]);
            $this->audit->record(
                'portal.invitation.revoked',
                $locked,
                newValues: [
                    'contact_id' => $locked->contact_id,
                    'status' => PortalInvitation::STATUS_REVOKED,
                ],
                actor: $actor,
            );

            return $locked->refresh();
        });
    }

    private function lockedInvitation(stdClass $locator, string $tokenHash): ?PortalInvitation
    {
        return PortalInvitation::forTenant((int) $locator->tenant_id)
            ->whereKey((int) $locator->invitation_id)
            ->where('token_hash', $tokenHash)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @return array{contact: Contact, email: string}|array{error_status: int, error_message: string, error_field: string}
     */
    private function invitationContactResult(
        stdClass $locator,
        PortalInvitation $invitation,
        bool $lockForUpdate = false,
    ): array {
        $query = Contact::withTrashed()
            ->forTenant((int) $locator->tenant_id)
            ->whereKey($invitation->contact_id);
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $contact = $query->first();
        $currentEmail = PortalEmail::normalize($contact?->email);
        if (
            $contact === null
            || $contact->trashed()
            || $currentEmail === null
            || ! hash_equals($invitation->email, $currentEmail)
        ) {
            return $this->errorResult(
                422,
                'The invitation contact or email is no longer valid.',
                'contact_id',
            );
        }

        return ['contact' => $contact, 'email' => $currentEmail];
    }

    /** @return array{error_status: int, error_message: string, error_field?: string}|null */
    private function invitationStateError(PortalInvitation $invitation): ?array
    {
        if (
            $invitation->status === PortalInvitation::STATUS_PENDING
            && $invitation->expires_at->lessThanOrEqualTo(now())
        ) {
            $invitation->update(['status' => PortalInvitation::STATUS_EXPIRED]);

            return $this->errorResult(410, 'This invitation has expired.');
        }

        return match ($invitation->status) {
            PortalInvitation::STATUS_PENDING => null,
            PortalInvitation::STATUS_EXPIRED => $this->errorResult(
                410,
                'This invitation has expired.',
            ),
            PortalInvitation::STATUS_ACCEPTED => $this->errorResult(
                409,
                'This invitation has already been accepted.',
            ),
            PortalInvitation::STATUS_REVOKED => $this->errorResult(
                409,
                'This invitation has been revoked.',
            ),
            default => $this->errorResult(409, 'This invitation cannot be used.'),
        };
    }

    /** @return array{error_status: int, error_message: string, error_field?: string} */
    private function errorResult(int $status, string $message, ?string $field = null): array
    {
        $result = ['error_status' => $status, 'error_message' => $message];
        if ($field !== null) {
            $result['error_field'] = $field;
        }

        return $result;
    }

    private function throwResultError(array $result): void
    {
        if (! isset($result['error_status'])) {
            return;
        }

        if ($result['error_status'] === 422) {
            $field = $result['error_field'] ?? 'invitation';
            throw ValidationException::withMessages([
                $field => [$result['error_message']],
            ]);
        }

        abort($result['error_status'], $result['error_message']);
    }

    private function isPortalUserIdentityConflict(QueryException $exception): bool
    {
        if (! $exception instanceof UniqueConstraintViolationException) {
            return false;
        }

        if (preg_match(
            '/\Ainsert\s+into\s+(?:"portal_users"|`portal_users`|\[portal_users\]|portal_users)\s*\(/i',
            trim($exception->getSql()),
        ) !== 1) {
            return false;
        }

        if (in_array($exception->index, [
            'portal_users_contact_unique',
            'portal_users_email_unique',
        ], true)) {
            return true;
        }

        return $exception->index === null && in_array($exception->columns, [
            ['tenant_id', 'contact_id'],
            ['tenant_id', 'email'],
        ], true);
    }

    private function contactName(Contact $contact): string
    {
        return trim($contact->first_name.' '.$contact->last_name);
    }

    private function assertNoAmbientTransaction(): void
    {
        $connectionName = DB::connection()->getName();
        $hasAmbientTransaction = app('db.transactions')
            ->callbackApplicableTransactions()
            ->contains(
                fn ($transaction): bool => $transaction->connection === $connectionName,
            );
        if ($hasAmbientTransaction) {
            throw new LogicException(
                'Portal invitation use cases cannot run inside an ambient transaction.',
            );
        }
    }

    private function withLocatedInvitation(string $token, Closure $callback): mixed
    {
        abort_unless(
            preg_match('/\A[A-Za-z0-9]{64}\z/', $token) === 1,
            404,
            'Resource not found.',
        );
        $tokenHash = PortalToken::hash($token);
        $locator = DB::table('portal_invitation_locators')
            ->where('token_hash', $tokenHash)
            ->first();
        abort_if(
            $locator === null
            || ! ctype_digit((string) $locator->invitation_id)
            || ! ctype_digit((string) $locator->tenant_id)
            || (int) $locator->invitation_id < 1
            || (int) $locator->tenant_id < 1,
            404,
            'Resource not found.',
        );

        $context = app(TenantContext::class);
        $previousTenant = $context->id();
        $context->set((int) $locator->tenant_id);

        try {
            return $callback($locator, $tokenHash);
        } finally {
            $previousTenant === null
                ? $context->clear()
                : $context->set($previousTenant);
        }
    }
}
