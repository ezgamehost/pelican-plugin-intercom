<?php

namespace EzGameHostLlc\Intercom\Services;

class IntercomBootPayload
{
    /**
     * @return array<string, mixed>|null
     */
    public static function forCurrentUser(): ?array
    {
        $user = auth()->user();
        $appId = config('intercom.app_id');
        $secret = config('intercom.identity_secret');

        if (!$user || !$appId || !$secret) {
            return null;
        }

        return [
            'app_id' => $appId,
            'user_id' => $user->uuid,
            'user_hash' => hash_hmac('sha256', (string) $user->uuid, (string) $secret),
            'email' => $user->email,
            'name' => $user->username,
            'created_at' => $user->created_at?->timestamp,
            'language_override' => $user->language,
            'timezone' => $user->timezone,
        ];
    }
}
