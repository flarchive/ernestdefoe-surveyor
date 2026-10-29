<?php

namespace Ernestdefoe\Surveyor\Google;

use RuntimeException;

/** The signed assertion a service account trades for an access token (RFC 7523), RS256. */
final class Jwt
{
    public static function assertion(ServiceAccount $account, string $scope, int $now): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss' => $account->email,
            'scope' => $scope,
            'aud' => $account->tokenUri,
            'iat' => $now,
            'exp' => $now + 3600,
        ];

        $input = self::b64(json_encode($header, JSON_UNESCAPED_SLASHES)).'.'.self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signature = '';

        if (! openssl_sign($input, $signature, $account->privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('The service account key could not sign.');
        }

        return $input.'.'.self::b64($signature);
    }

    public static function b64(string|false $data): string
    {
        return rtrim(strtr(base64_encode((string) $data), '+/', '-_'), '=');
    }
}
