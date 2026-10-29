<?php

namespace Ernestdefoe\Surveyor\Google;

use Ernestdefoe\Surveyor\Http\ApiClient;
use Ernestdefoe\Surveyor\Http\ApiLog;
use Ernestdefoe\Surveyor\Security\Secrets;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Talks to Search Console as the service account. Access tokens are cached
 * until shortly before they expire; a failure pauses every Google call (see
 * ApiLog::pauseFor) so a wrong key or a quota is not hammered.
 */
class Google
{
    public const READ = 'https://www.googleapis.com/auth/webmasters.readonly';
    /** Sitemap submission needs the read-write scope. */
    public const WRITE = 'https://www.googleapis.com/auth/webmasters';

    public const API = 'https://www.googleapis.com/webmasters/v3';
    public const INSPECT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    public function __construct(
        protected ApiClient $http,
        protected Secrets $secrets,
        protected Cache $cache,
        protected ApiLog $log,
    ) {
    }

    public function account(): ?ServiceAccount
    {
        $json = $this->secrets->get(Secrets::GOOGLE);
        $account = $json === null ? null : ServiceAccount::parse($json);

        return $account instanceof ServiceAccount ? $account : null;
    }

    /** @return array{status: int, data: array<string, mixed>, error: string|null} */
    public function call(string $action, string $method, string $url, ?array $body = null, string $scope = self::READ): array
    {
        if ($this->log->pausedUntil('google') !== null) {
            return ['status' => 0, 'data' => [], 'error' => 'paused'];
        }

        $token = $this->token($scope);
        if ($token === null) {
            return ['status' => 0, 'data' => [], 'error' => 'no_token'];
        }

        $response = $this->http->send($method, $url, [
            'Authorization' => 'Bearer '.$token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ], $body === null ? null : (string) json_encode($body, JSON_UNESCAPED_SLASHES), 30);

        $data = json_decode($response['body'], true);
        $data = is_array($data) ? $data : [];
        $status = $response['status'];
        $error = $status >= 200 && $status < 300 ? null : self::message($data, $response['body']);

        $this->log->record('google', $action, $status, $error);

        if ($error !== null) {
            if ($status === 401) {
                $this->cache->forget($this->tokenKey($scope));
            }
            $this->log->pauseFor('google', $status, isset($response['headers']['retry-after']) ? (int) $response['headers']['retry-after'] : null);
        }

        return ['status' => $status, 'data' => $data, 'error' => $error];
    }

    /** An access token for the scope, from the cache or a new exchange. */
    public function token(string $scope = self::READ): ?string
    {
        $key = $this->tokenKey($scope);

        if ($cached = $this->cache->get($key)) {
            return (string) $cached;
        }

        $account = $this->account();
        if ($account === null) {
            return null;
        }

        $response = $this->http->send('POST', $account->tokenUri, ['Content-Type' => 'application/x-www-form-urlencoded'], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => Jwt::assertion($account, $scope, time()),
        ]), 15);

        $data = json_decode($response['body'], true);
        $token = is_array($data) ? ($data['access_token'] ?? null) : null;

        if (! is_string($token) || $response['status'] !== 200) {
            $this->log->record('google', 'token', $response['status'], is_array($data) ? self::message($data, '') : 'invalid response');
            $this->log->pauseFor('google', $response['status'] === 400 ? 403 : $response['status']);

            return null;
        }

        $ttl = max(60, (int) ($data['expires_in'] ?? 3600) - 120);
        $this->cache->put($key, $token, $ttl);

        return $token;
    }

    public function forgetTokens(): void
    {
        $this->cache->forget($this->tokenKey(self::READ));
        $this->cache->forget($this->tokenKey(self::WRITE));
        $this->log->resume('google');
    }

    public static function siteUrl(string $property): string
    {
        return rawurlencode($property);
    }

    private function tokenKey(string $scope): string
    {
        return 'ernestdefoe-surveyor.google.token.'.sha1($scope);
    }

    /** @param array<string, mixed> $data */
    private static function message(array $data, string $raw): string
    {
        $error = $data['error'] ?? null;
        $message = is_array($error) ? ($error['message'] ?? $error['status'] ?? '') : (string) ($data['error_description'] ?? $error ?? '');

        return mb_substr(trim(strip_tags((string) ($message ?: $raw))), 0, 250) ?: 'error';
    }
}
