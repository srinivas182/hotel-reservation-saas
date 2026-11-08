<?php

declare(strict_types=1);

namespace App\Domains\Support\Http\Controllers;

use App\Domains\Platform\PlatformAbility;
use App\Domains\Support\Models\SupportTicket;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Screenshots attached to support requests: the hotel's own staff, or the
 * platform's support team.
 */
final class SupportAttachmentController extends Controller
{
    public function __invoke(int $ticket, string $file, #[CurrentUser] User $user): StreamedResponse
    {
        $found = SupportTicket::query()->findOrFail($ticket);
        $allowed = $user->platformCan(PlatformAbility::Support)
            || ($user->hotels()->whereKey($found->hotel_id)->exists() && $user->can('support.contact'));
        abort_unless($allowed && preg_match('/^[a-f0-9-]+\.(png|jpe?g|webp)$/', $file) === 1, 404);

        $path = 'support/'.$found->id.'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; img-src 'self'; sandbox"]);
    }
}
