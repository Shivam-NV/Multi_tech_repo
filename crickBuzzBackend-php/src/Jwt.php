<?php
declare(strict_types=1);

namespace MatchApi;

use RuntimeException;

final class Jwt
{
    public static function create(string $id, string $email, string $role): string
    {
        $key = getenv('JWT_KEY') ?: '';
        if (strlen($key) < 32) {
            throw new RuntimeException('JWT_KEY must be configured with at least 32 characters.');
        }

        $now = time();
        $header = self::encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = self::encode([
            'sub' => $id,
            'email' => $email,
            'role' => $role,
            'iss' => getenv('JWT_ISSUER') ?: 'MatchApi',
            'aud' => getenv('JWT_AUDIENCE') ?: 'MatchApiUsers',
            'iat' => $now,
            'exp' => $now + 7200,
        ]);
        $unsigned = $header . '.' . $payload;
        return $unsigned . '.' . self::base64Url(hash_hmac('sha256', $unsigned, $key, true));
    }

    public static function requireSuperAdmin(): array
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
            Http::problem('A bearer token is required.', 401);
        }
        $parts = explode('.', $matches[1]);
        $key = getenv('JWT_KEY') ?: '';
        if (count($parts) !== 3 || strlen($key) < 32) {
            Http::problem('Invalid bearer token.', 401);
        }
        $unsigned = $parts[0] . '.' . $parts[1];
        $signature = self::base64Url(hash_hmac('sha256', $unsigned, $key, true));
        if (!hash_equals($signature, $parts[2])) {
            Http::problem('Invalid bearer token.', 401);
        }
        $payload = json_decode(self::decode($parts[1]), true);
        if (!is_array($payload)
            || ($payload['iss'] ?? null) !== (getenv('JWT_ISSUER') ?: 'MatchApi')
            || ($payload['aud'] ?? null) !== (getenv('JWT_AUDIENCE') ?: 'MatchApiUsers')
            || !isset($payload['exp'])
            || (int) $payload['exp'] <= time()) {
            Http::problem('Expired or invalid bearer token.', 401);
        }
        if (($payload['role'] ?? null) !== 'SuperAdmin') {
            Http::problem('SuperAdmin role is required.', 403);
        }
        return $payload;
    }

    private static function encode(array $value): string
    {
        return self::base64Url(json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private static function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
