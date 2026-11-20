<?php
declare(strict_types=1);

use MatchApi\Api;
use MatchApi\Database;
use MatchApi\Http;

require dirname(__DIR__) . '/src/bootstrap.php';

Http::configureCors();

try {
    $api = new Api(new Database());
    $api->dispatch();
} catch (Throwable $exception) {
    error_log((string) $exception);
    Http::problem(
        'An unexpected error occurred.',
        500,
        getenv('APP_DEBUG') === 'true' ? $exception->getMessage() : null
    );
}
