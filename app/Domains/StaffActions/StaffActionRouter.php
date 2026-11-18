<?php

declare(strict_types=1);

namespace App\Domains\StaffActions;

use App\Domains\Audit\Audit;
use App\Domains\Push\Models\PushSubscription;
use App\Domains\Push\PushSender;
use App\Domains\StaffActions\Models\StaffAction;
use App\Domains\StaffAlerts\StaffAlertSettings;
use App\Domains\StaffAlerts\WhatsAppDirect;
use App\Domains\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Receives staff button taps from the WhatsApp webhook (Sprint B).
 *
 * A tap counts only if its code is signed for this hotel, names the
 * person whose alert phone sent it, and that person is still an active
 * member. Taps never become guest conversations. Decisions are scheduled
 * with a one-minute undo window, then carried out by RunStaffAction.
 */
final class StaffActionRouter
{
    public const UNDO_SECONDS = 60;

    /** Below this time left on the subject, decisions are carried out at once. */
    public const MIN_SECONDS_LEFT_FOR_UNDO = 90;

    /** @var list<array{text: string, buttons: list<array{id: string, title: string}>}>|null replies of a tap from an app notification */
    private ?array $collected = null;

    public function __construct(
        private readonly TenantContext $context,
        private readonly WhatsAppDirect $whatsapp,
        private readonly PushSender $push,
    ) {}

    /**
     * A tap on an app notification button (Sprint D). The signed code is the
     * proof: it names the person, who must still be an active member with
     * the app on a phone in this hotel. The replies come back to the phone
     * in the answer, not by WhatsApp.
     *
     * @return list<array{text: string, buttons: list<array{id: string, title: string}>}>
     */
    public function handlePush(string $payload): array
    {
        $previous = app()->getLocale();
        $this->collected = [];

        try {
            $token = ActionToken::parse($this->context->id(), $payload);
            $settings = $token === null ? null : StaffAlertSettings::query()->with('user')->where('user_id', $token->userId)->first();
            $user = $settings?->user;

            if ($token === null || $settings === null || ! $user instanceof User || ! $this->isActiveMember($user) || ! PushSubscription::query()->where('user_id', $user->getKey())->exists()) {
                Audit::record('staff_actions.refused', 'A notification button tap was refused', subject: $this->context->hotel(), properties: ['reason' => $token === null ? 'bad_signature' : 'not_a_member_with_the_app', 'channel' => 'push']);

                return [];
            }

            app()->setLocale($user->preferred_locale ?: $this->context->hotel()->default_locale);
            $this->act($token, $user, $settings);

            return $this->collected ?? [];
        } finally {
            $this->collected = null;
            app()->setLocale($previous);
        }
    }

    /**
     * The button code in an incoming message, if it is a staff button.
     *
     * @param  array<string, mixed>  $message
     */
    public static function payloadOf(array $message): ?string
    {
        $payload = match ((string) ($message['type'] ?? '')) {
            'interactive' => $message['interactive']['button_reply']['id'] ?? $message['interactive']['list_reply']['id'] ?? null,
            'button' => $message['button']['payload'] ?? null,
            default => null,
        };

        return is_string($payload) && ActionToken::looksLikeOne($payload) ? $payload : null;
    }

    public function route(string $from, string $payload, string $wamid): void
    {
        $previous = app()->getLocale();

        try {
            $this->handleTap($from, $payload, $wamid);
        } finally {
            app()->setLocale($previous);
        }
    }

    private function handleTap(string $from, string $payload, string $wamid): void
    {
        // Meta can send the same notification twice: one tap, one effect.
        if ($wamid !== '' && ! Cache::add('staff-action-tap:'.$wamid, true, now()->addDay())) {
            return;
        }

        $hotelId = $this->context->id();
        $token = ActionToken::parse($hotelId, $payload);
        $phone = '+'.ltrim($from, '+');
        $settings = $token === null ? null : StaffAlertSettings::query()->with('user')->where('user_id', $token->userId)->where('alert_phone', $phone)->first();
        $user = $settings?->user;

        if ($token === null || $settings === null || ! $user instanceof User || ! $this->isActiveMember($user)) {
            Audit::record('staff_actions.refused', 'A WhatsApp button tap was refused', subject: $this->context->hotel(), properties: [
                'phone' => Str::mask($phone, '*', 4, -2),
                'reason' => $token === null ? 'bad_signature' : 'wrong_phone_or_member',
            ]);

            return;
        }

        $settings->forceFill(['whatsapp_inbound_at' => now()])->save();
        app()->setLocale($user->preferred_locale ?: $this->context->hotel()->default_locale);

        $this->act($token, $user, $settings);
    }

    private function act(ActionToken $token, User $user, StaffAlertSettings $settings): void
    {
        if ($token->expired()) {
            $this->reply($settings, (string) __('This button has expired. Open the dashboard to see what is waiting: :url', ['url' => route('hotel.today')]));

            return;
        }

        if ($token->action === 'undo') {
            $this->undo($token, $user, $settings);

            return;
        }

        $handler = $this->handler($token->prefix());

        if ($handler === null) {
            return;
        }

        $handler->tap($token, $user, $settings);
    }

    /**
     * Schedule a decision with the undo window (or at once when the subject
     * expires too soon for one), and tell the person.
     */
    public function schedule(StaffActionHandler $handler, string $subjectId, string $action, ?string $reason, User $user, StaffAlertSettings $settings, string $confirmation, int $secondsLeft): StaffAction
    {
        $withUndo = $secondsLeft >= self::MIN_SECONDS_LEFT_FOR_UNDO;

        $staffAction = StaffAction::query()->create([
            'user_id' => $user->getKey(),
            'kind' => $handler->kind(),
            'subject_id' => $subjectId,
            'action' => $action,
            'reason' => $reason,
            'status' => 'scheduled',
            'phone' => $this->collected === null ? $settings->alert_phone : null, // null: the result goes to the app
            'execute_at' => now()->addSeconds($withUndo ? self::UNDO_SECONDS : 0),
        ]);

        if ($withUndo) {
            $this->reply($settings, $confirmation."\n".__('Tap Undo within one minute if this was a mistake.'), [[
                'id' => ActionToken::make($this->context->id(), 'undo', $staffAction->id, (string) $user->getKey(), now()->addSeconds(self::UNDO_SECONDS)),
                'title' => (string) __('Undo'),
            ]]);
            RunStaffAction::dispatch($staffAction->id)->delay(now()->addSeconds(self::UNDO_SECONDS));
        } else {
            RunStaffAction::dispatch($staffAction->id);
        }

        return $staffAction;
    }

    /**
     * Carry out a scheduled decision once: the first worker to claim it
     * runs it, any other copy of the job does nothing.
     */
    public function execute(string $staffActionId): void
    {
        $claimed = StaffAction::query()->whereKey($staffActionId)->where('status', 'scheduled')->where('execute_at', '<=', now()->addSeconds(5))->update(['status' => 'running', 'updated_at' => now()]);

        if ($claimed !== 1) {
            return;
        }

        $action = StaffAction::query()->with('user')->findOrFail($staffActionId);
        $handler = $this->handler(explode('_', $action->action, 2)[0]) ?? $this->handlerForKind($action->kind);
        $user = $action->user;

        if ($handler === null || $user === null) {
            $action->forceFill(['status' => 'failed', 'executed_at' => now(), 'result' => 'no_handler_or_user'])->save();

            return;
        }

        $previous = app()->getLocale();
        app()->setLocale($user->preferred_locale ?: $this->context->hotel()->default_locale);

        try {
            $message = $handler->execute($action, $user);
            $status = 'done';
        } catch (StaffActionFailed $e) {
            $message = $e->getMessage();
            $status = 'failed';
        } catch (Throwable $e) {
            report($e);
            $message = (string) __('Something went wrong. Open the dashboard to finish: :url', ['url' => route('hotel.today')]);
            $status = 'failed';
        }

        $action->forceFill(['status' => $status, 'executed_at' => now(), 'result' => mb_substr($message, 0, 500)])->save();

        $settings = StaffAlertSettings::query()->with('user')->where('user_id', $user->getKey())->first();
        if ($action->phone === null) {
            $this->push->toUser($user, ['title' => $this->context->hotel()->name, 'body' => $message, 'tag' => 'staff-action:'.$action->id]);
        } elseif ($settings !== null) {
            $this->reply($settings, $message);
        }
        app()->setLocale($previous);
    }

    /**
     * A free message to the person (their window is open: they just tapped).
     *
     * @param  list<array{id: string, title: string}>  $buttons
     */
    public function reply(StaffAlertSettings $settings, string $text, array $buttons = []): void
    {
        if ($this->collected !== null) {
            $this->collected[] = ['text' => $text, 'buttons' => $buttons];

            return;
        }

        if ($settings->alert_phone === null) {
            return;
        }

        $locale = $settings->user?->preferred_locale ?: $this->context->hotel()->default_locale;
        $id = (string) Str::ulid();

        // No template: a reply outside the window is not worth paying for.
        $buttons === []
            ? $this->whatsapp->send($settings->alert_phone, $text, '', [], $locale, $locale, $id)
            : $this->whatsapp->sendButtons($settings->alert_phone, $text, $buttons, '', [], $locale, $locale, $id);
    }

    /**
     * @return list<array{id: string, title: string}>
     */
    public function buttons(string $subjectId, User $user, int $validSeconds, string ...$actionsAndTitles): array
    {
        $buttons = [];
        foreach (array_chunk($actionsAndTitles, 2) as [$action, $title]) {
            $buttons[] = ['id' => ActionToken::make($this->context->id(), $action, $subjectId, (string) $user->getKey(), now()->addSeconds(max(60, $validSeconds))), 'title' => $title];
        }

        return $buttons;
    }

    private function undo(ActionToken $token, User $user, StaffAlertSettings $settings): void
    {
        $undone = DB::transaction(function () use ($token, $user): bool {
            $action = StaffAction::query()->whereKey($token->subject)->lockForUpdate()->first();

            if ($action === null || $action->user_id !== $user->getKey() || $action->status !== 'scheduled') {
                return false;
            }

            $action->forceFill(['status' => 'undone', 'executed_at' => now()])->save();

            return true;
        });

        if ($undone) {
            Audit::record('staff_actions.undone', 'A WhatsApp decision was undone', subject: $this->context->hotel(), causer: $user, properties: ['action' => $token->subject]);
        }

        $this->reply($settings, $undone
            ? (string) __('Undone. Nothing was changed; the request is still waiting.')
            : (string) __('Too late to undo: it is already done. Open the dashboard to change it: :url', ['url' => route('hotel.today')]));
    }

    private function handler(string $prefix): ?StaffActionHandler
    {
        foreach (app()->tagged('staff-actions.handlers') as $handler) {
            if ($handler instanceof StaffActionHandler && $handler->prefix() === $prefix) {
                return $handler;
            }
        }

        return null;
    }

    private function handlerForKind(string $kind): ?StaffActionHandler
    {
        foreach (app()->tagged('staff-actions.handlers') as $handler) {
            if ($handler instanceof StaffActionHandler && $handler->kind() === $kind) {
                return $handler;
            }
        }

        return null;
    }

    private function isActiveMember(User $user): bool
    {
        return $this->context->hotel()->members()->wherePivot('status', 'active')->whereKey($user->getKey())->exists();
    }
}
