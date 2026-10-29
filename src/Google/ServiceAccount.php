<?php

namespace Ernestdefoe\Surveyor\Google;

/** A Google service account key file, checked. Never logged, never shown. */
final class ServiceAccount
{
    private function __construct(
        public readonly string $email,
        public readonly string $privateKey,
        public readonly string $tokenUri,
    ) {
    }

    /** @return self|string the account, or an error code */
    public static function parse(string $json): self|string
    {
        if (strlen($json) > 20000) {
            return 'too_big';
        }

        $data = json_decode($json, true);

        if (! is_array($data) || ($data['type'] ?? null) !== 'service_account') {
            return 'not_service_account';
        }

        $email = (string) ($data['client_email'] ?? '');
        $key = (string) ($data['private_key'] ?? '');
        $tokenUri = (string) ($data['token_uri'] ?? 'https://oauth2.googleapis.com/token');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'no_email';
        }

        // Only Google's own token endpoint: a key file cannot send our signed request anywhere else.
        if (! preg_match('~^https://oauth2\.googleapis\.com/token$~', $tokenUri)) {
            return 'token_uri';
        }

        if (openssl_pkey_get_private($key) === false) {
            return 'private_key';
        }

        return new self($email, $key, $tokenUri);
    }
}
