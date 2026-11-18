<?php

declare(strict_types=1);

namespace App\Domains\Push\Http\Controllers;

use App\Domains\Audit\Audit;
use App\Domains\Push\Models\PushSubscription;
use App\Domains\Push\PushSender;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Turning app notifications on and off on this phone, and the test
 * notification (Sprint D).
 */
final class PushSubscriptionController extends Controller
{
    /**
     * The push services of the browsers staff use. Only these may be
     * stored: the server sends to these addresses, so an arbitrary one
     * would let anyone make it call any URL.
     */
    private const SERVICES = '/^(fcm\.googleapis\.com|android\.googleapis\.com|updates\.push\.services\.mozilla\.com|[a-z0-9.-]+\.notify\.windows\.com|web\.push\.apple\.com|[a-z0-9.-]+\.push\.apple\.com)$/i';

    public function store(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:1000'],
            'keys.p256dh' => ['required', 'string', 'max:200', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'keys.auth' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9_-]+={0,2}$/'],
            'device' => ['nullable', 'string', 'max:80'],
        ]);

        if (preg_match(self::SERVICES, (string) parse_url($data['endpoint'], PHP_URL_HOST)) !== 1) {
            throw ValidationException::withMessages(['endpoint' => __('This browser\'s notification service is not supported. Use Chrome, Firefox, Edge or Safari.')]);
        }

        // The same phone signed in by someone else now belongs to them.
        $subscription = PushSubscription::query()->updateOrCreate(['endpoint_hash' => hash('sha256', $data['endpoint'])], [
            'user_id' => $user->getKey(),
            'endpoint' => $data['endpoint'],
            'p256dh' => $data['keys']['p256dh'],
            'auth' => $data['keys']['auth'],
            'device' => $data['device'] ?? null,
            'failures' => 0,
        ]);

        if ($subscription->wasRecentlyCreated) {
            Audit::record('push.subscribed', 'App notifications turned on on a phone', causer: $user, properties: ['device' => $subscription->device]);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint', '');
        $deleted = PushSubscription::query()->where('user_id', $user->getKey())->where('endpoint_hash', hash('sha256', $endpoint))->delete();

        if ($deleted > 0) {
            Audit::record('push.unsubscribed', 'App notifications turned off on a phone', causer: $user);
        }

        return response()->json(['ok' => true]);
    }

    public function test(PushSender $push, #[CurrentUser] User $user): JsonResponse
    {
        $sent = $push->toUser($user, [
            'title' => (string) __('Test notification'),
            'body' => (string) __('Notifications work on this phone. New requests and messages will arrive like this.'),
            'tag' => 'test',
            'url' => route('hotel.my-alerts'),
        ]);

        return response()->json(['sent' => $sent]);
    }
}
