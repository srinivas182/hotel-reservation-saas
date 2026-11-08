<?php

declare(strict_types=1);

namespace App\Support\Logging;

use Sentry\Event;
use Sentry\EventHint;

/**
 * Removes personal data from error reports before they leave the server:
 * no request body, cookies or auth headers, no user details beyond the id,
 * and phone numbers, emails and secrets redacted from messages.
 */
final class SentryScrubber
{
    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $request = $event->getRequest();
        if ($request !== []) {
            unset($request['data'], $request['cookies']);
            if (isset($request['headers']) && is_array($request['headers'])) {
                $request['headers'] = array_diff_key(array_change_key_case($request['headers']), array_flip(['cookie', 'authorization', 'x-xsrf-token', 'x-csrf-token', 'x-hub-signature-256', 'x-paystack-signature', 'verif-hash']));
            }
            if (isset($request['query_string']) && is_string($request['query_string'])) {
                $request['query_string'] = RedactSensitiveData::redact($request['query_string']);
            }
            $event->setRequest($request);
        }

        $user = $event->getUser();
        if ($user !== null) {
            $id = $user->getId();
            $event->setUser($id === null ? null : \Sentry\UserDataBag::createFromUserIdentifier($id));
        }

        if (($message = $event->getMessage()) !== null) {
            $event->setMessage(RedactSensitiveData::redact($message));
        }

        $exceptions = $event->getExceptions();
        foreach ($exceptions as $exception) {
            $exception->setValue(RedactSensitiveData::redact($exception->getValue()));
        }
        $event->setExceptions($exceptions);

        $event->setExtra(RedactSensitiveData::redactArray($event->getExtra()));

        return $event;
    }
}
