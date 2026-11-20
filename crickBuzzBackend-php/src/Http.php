<?php
declare(strict_types=1);

namespace MatchApi;

final class Http
{
    public static function configureCors(): void
    {
        $origins = array_filter(array_map('trim', explode(',', getenv('CORS_ALLOWED_ORIGINS') ?: 'http://localhost:3000,http://localhost:5173')));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        if ($origin !== '' && in_array($origin, $origins, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Vary: Origin');
        }
        header('Access-Control-Allow-Headers: Authorization, Content-Type');
        header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(204);
            exit;
        }
    }

    public static function json(mixed $body, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }

    public static function problem(string $message, int $status, ?string $detail = null): never
    {
        $body = [
            'type' => 'about:blank',
            'title' => $message,
            'status' => $status,
        ];
        if ($detail !== null) {
            $body['detail'] = $detail;
        }
        self::json($body, $status);
    }

    public static function body(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            self::problem('Request body must be valid JSON.', 400);
        }

        if (!is_array($value)) {
            self::problem('Request body must be a JSON object.', 400);
        }
        return $value;
    }
}
