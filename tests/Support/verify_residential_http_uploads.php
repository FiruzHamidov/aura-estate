<?php

// Local HTTP acceptance check. Only the disposable browser fixture is allowed.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$database = config('database.connections.sqlite.database');
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || config('database.default') !== 'sqlite'
    || ! is_string($database) || ! preg_match('#^/(?:private/)?tmp/aura-residential-qa\.[A-Za-z0-9]+/database\.sqlite$#', $database)
    || ! is_file($database) || is_link($database)) {
    throw new LogicException('Use only an existing disposable aura-residential-qa SQLite fixture.');
}
$actor = App\Models\User::query()->where('name', 'QA консультант Aura')->where('phone', '000000001')->firstOrFail();
$building = App\Models\NewBuilding::query()->where('title', 'Тестовый ЖК — только локальная проверка')->firstOrFail();
$image = dirname($database).'/fixture.png';
if (! is_file($image) || is_link($image) || filesize($image) > 256 * 1024) {
    throw new LogicException('Expected the small synthetic PNG from the browser fixture builder.');
}
$token = $actor->createToken('local-http-upload-qa', ['*'], now()->addMinutes(10));
$client = new GuzzleHttp\Client([
    'base_uri' => 'http://localhost:8000/api/',
    'http_errors' => false,
    'timeout' => 30,
    'allow_redirects' => false,
    'headers' => ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token->plainTextToken],
]);
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$json = function (Psr\Http\Message\ResponseInterface $response): array {
    return json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
};

try {
    $photoPath = 'new-buildings/'.$building->id.'/photos';
    $photoCount = $building->photos()->count();
    $invalid = $client->post($photoPath, ['multipart' => [
        ['name' => 'file', 'contents' => '<svg xmlns="http://www.w3.org/2000/svg"/>', 'filename' => 'not-an-image.png', 'headers' => ['Content-Type' => 'image/png']],
    ]]);
    $assert($invalid->getStatusCode() === 422, 'A disguised SVG must be rejected.');
    $assert($building->photos()->count() === $photoCount, 'Rejected upload wrote a photo.');

    $valid = $client->post($photoPath, ['multipart' => [
        ['name' => 'file', 'contents' => file_get_contents($image), 'filename' => 'qa-http-fixture.png', 'headers' => ['Content-Type' => 'image/png']],
        ['name' => 'kind', 'contents' => 'photo'],
        ['name' => 'alt', 'contents' => 'QA HTTP multipart — синтетическое изображение'],
    ]]);
    $assert($valid->getStatusCode() === 201, 'Valid PNG upload failed: HTTP '.$valid->getStatusCode());
    $photo = $json($valid);
    $assert($building->photos()->count() === $photoCount + 1, 'Expected exactly one added photo.');
    $publicImage = $client->get('residential-media/building-photos/'.$photo['id'].'/preview', ['headers' => ['Authorization' => '']]);
    $bytes = (string) $publicImage->getBody();
    $size = getimagesizefromstring($bytes);
    $assert($publicImage->getStatusCode() === 200 && $size !== false && $size['mime'] === 'image/webp', 'Public image must be a re-encoded WebP.');
    $assert($size[0] === 1000 && $size[1] === 600, 'Image dimensions changed unexpectedly.');

    $prefix = 'qa-http-'.bin2hex(random_bytes(4));
    $csv = "\xEF\xBB\xBFexternal_id;name;area;rooms;total_price;publication_status;availability_status\n"
        .$prefix."-1;QA HTTP лот 1;41.50;1;300000.55;draft;available\n"
        .$prefix."-2;QA HTTP лот 2;52.25;2;450000.75;draft;available\n";
    $unitCount = $building->units()->count();
    $preview = $client->post('admin/new-buildings/'.$building->id.'/imports/preview', ['multipart' => [
        ['name' => 'mode', 'contents' => 'csv'],
        ['name' => 'delimiter', 'contents' => 'semicolon'],
        ['name' => 'file', 'contents' => $csv, 'filename' => 'qa-http-units.csv', 'headers' => ['Content-Type' => 'text/csv']],
    ]]);
    $assert($preview->getStatusCode() === 201, 'CSV preview failed: HTTP '.$preview->getStatusCode());
    $report = $json($preview);
    $assert($building->units()->count() === $unitCount, 'Preview must not create lots.');
    $assert(($report['counts']['created'] ?? null) === 2 && ($report['counts']['errors'] ?? null) === 0, 'Expected two valid prospective lots.');

    echo json_encode([
        'invalid_upload_http' => $invalid->getStatusCode(),
        'valid_upload_http' => $valid->getStatusCode(),
        'photo_id' => $photo['id'],
        'image_mime' => $size['mime'], 'image_dimensions' => [$size[0], $size[1]], 'image_bytes' => strlen($bytes),
        'csv_preview_http' => $preview->getStatusCode(),
        'batch_id' => $report['id'], 'counts' => $report['counts'],
        'external_id_prefix' => $prefix, 'unit_count_unchanged' => $unitCount,
        'applied' => false,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
} catch (Throwable $error) {
    $failure = $error;
} finally {
    $token->accessToken->delete();
}
if (isset($failure)) {
    fwrite(STDERR, 'HTTP acceptance check failed: '.(get_class($failure) === RuntimeException::class ? $failure->getMessage() : get_class($failure)).PHP_EOL);
    exit(1);
}
