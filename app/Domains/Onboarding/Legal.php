<?php

declare(strict_types=1);

namespace App\Domains\Onboarding;

use App\Domains\Access\SystemRole;
use App\Domains\Onboarding\Models\LegalAcceptance;
use App\Domains\Onboarding\Models\LegalDocument;
use App\Models\User;

/**
 * Which legal texts a user must accept, and recording acceptances.
 * Hotel owners accept all three; other staff accept the privacy notice.
 */
final class Legal
{
    /**
     * @return list<LegalDocument>
     */
    public function missing(User $user): array
    {
        if ($user->isPlatformStaff()) {
            return [];
        }

        $kinds = $this->isOwner($user) ? LegalDocument::KINDS : ['privacy'];
        $accepted = LegalAcceptance::query()->where('user_id', $user->getKey())->pluck('legal_document_id')->all();
        $missing = [];

        foreach ($kinds as $kind) {
            $current = LegalDocument::current($kind);
            if ($current !== null && ! in_array($current->id, $accepted, true)) {
                $missing[] = $current;
            }
        }

        return $missing;
    }

    /**
     * @param  list<LegalDocument>  $documents
     */
    public function accept(User $user, array $documents, ?string $ip, ?string $hotelId = null): void
    {
        foreach ($documents as $document) {
            LegalAcceptance::query()->firstOrCreate(
                ['user_id' => $user->getKey(), 'legal_document_id' => $document->id],
                ['hotel_id' => $hotelId, 'accepted_at' => now(), 'ip' => $ip],
            );
        }
    }

    private function isOwner(User $user): bool
    {
        return \App\Domains\Tenancy\Models\Hotel::query()->where('owner_user_id', $user->getKey())->exists()
            || Models\PendingSignup::query()->where('user_id', $user->getKey())->exists()
            || \Illuminate\Support\Facades\DB::table('model_has_roles')->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('model_has_roles.model_id', $user->getKey())->where('roles.name', SystemRole::Owner->value)->exists();
    }
}
