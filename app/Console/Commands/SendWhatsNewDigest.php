<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domains\Access\SystemRole;
use App\Domains\Product\Mail\WhatsNewDigestMail;
use App\Domains\Product\Models\ReleaseNote;
use App\Domains\Tenancy\Enums\HotelStatus;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

#[Signature('whats-new:digest')]
#[Description('Email last month’s release notes to owners and managers who have not turned product emails off')]
final class SendWhatsNewDigest extends Command
{
    public function handle(): int
    {
        $ids = ReleaseNote::query()->published()->where('published_at', '>=', now()->subMonthNoOverflow()->startOfMonth())->where('published_at', '<', now()->startOfMonth())->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        if ($ids === []) {
            $this->components->info('No release notes last month.');

            return self::SUCCESS;
        }

        $roles = DB::table('roles')->whereIn('name', [SystemRole::Owner->value, SystemRole::Manager->value])->pluck('id');
        $users = User::query()->where('product_emails', true)->whereNull('deactivated_at')
            ->whereIn('id', DB::table('model_has_roles')->whereIn('role_id', $roles)->where('model_type', (new User)->getMorphClass())->select('model_id'))
            ->whereIn('id', DB::table('hotel_user')->join('hotels', 'hotels.id', '=', 'hotel_user.hotel_id')->where('hotels.is_demo', false)
                ->whereIn('hotels.status', [HotelStatus::Active->value, HotelStatus::Trial->value])->select('hotel_user.user_id'))
            ->get();

        foreach ($users as $user) {
            Mail::to($user->email)->queue(new WhatsNewDigestMail($user->id, $ids));
        }
        $this->components->info(count($ids).' note(s) sent to '.$users->count().' people.');

        return self::SUCCESS;
    }
}
