<?php

namespace App\Services\Residential;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

final class InventoryCsv
{
    public const MAX_ROWS = 1000;

    public const COLUMNS = ['external_id', 'name', 'block_id', 'entrance_id', 'layout_id', 'number', 'position_on_floor', 'rooms', 'bathrooms', 'floor', 'area', 'living_area', 'kitchen_area', 'ceiling_height', 'pricing_basis', 'total_price', 'price_per_sqm', 'price_on_request', 'availability_status', 'publication_status', 'finishing', 'window_view', 'description', 'data_verified_at'];

    private const INTEGER_COLUMNS = ['block_id', 'entrance_id', 'layout_id', 'position_on_floor', 'rooms', 'bathrooms', 'floor'];

    private const DECIMAL_COLUMNS = ['area', 'living_area', 'kitchen_area', 'ceiling_height', 'total_price', 'price_per_sqm'];

    public function parse(UploadedFile $file, string $delimiter = ';'): array
    {
        if (! in_array($delimiter, [',', ';'], true)) {
            $this->invalid('Разделитель должен быть запятой или точкой с запятой.');
        }
        $content = file_get_contents($file->getRealPath());
        if ($content === false || strlen($content) > 5 * 1024 * 1024 || ! mb_check_encoding($content, 'UTF-8') || str_contains($content, "\0")) {
            $this->invalid('Требуется CSV UTF-8 без нулевых байтов, размером не более 5 МБ.');
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $content));
        rewind($stream);
        try {
            $header = fgetcsv($stream, 0, $delimiter, '"', '');
            if (! is_array($header)) {
                $this->invalid('CSV пуст.');
            }
            $header = array_map(static fn ($value) => trim((string) $value), $header);
            if (count(array_unique($header)) !== count($header) || array_diff($header, self::COLUMNS) || ! in_array('external_id', $header, true)) {
                $this->invalid('Укажите external_id и только поддерживаемые уникальные заголовки. Используйте шаблон CSV и правильный разделитель.');
            }
            $rows = [];
            $line = 1;
            while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
                $line++;
                if (count(array_filter($cells, static fn ($cell) => trim((string) $cell) !== '')) === 0) {
                    continue;
                }
                if (count($rows) >= self::MAX_ROWS) {
                    $this->invalid('Не более '.self::MAX_ROWS.' записей за один импорт. Разделите большой фонд на файлы.');
                }
                $errors = [];
                $data = [];
                if (count($cells) !== count($header)) {
                    $errors['csv'] = ['Количество столбцов не совпадает с заголовком.'];
                } else {
                    foreach ($header as $index => $key) {
                        $value = trim((string) $cells[$index]);
                        if (mb_strlen($value) > 1000) {
                            $errors[$key] = ['В CSV значение не должно превышать 1000 символов.'];

                            continue;
                        }
                        if ($value === '') {
                            $data[$key] = null;

                            continue;
                        }
                        if ($key === 'price_on_request') {
                            if (! in_array(strtolower($value), ['0', '1', 'true', 'false'], true)) {
                                $errors[$key] = ['Используйте 1/0 или true/false.'];
                            } else {
                                $data[$key] = in_array(strtolower($value), ['1', 'true'], true);
                            }
                        } elseif (in_array($key, self::INTEGER_COLUMNS, true)) {
                            if (! preg_match('/^[0-9]{1,12}$/', $value)) {
                                $errors[$key] = ['Требуется целое неотрицательное число.'];
                            } else {
                                $data[$key] = (int) $value;
                            }
                        } elseif (in_array($key, self::DECIMAL_COLUMNS, true)) {
                            $data[$key] = str_replace(',', '.', $value);
                        } else {
                            $data[$key] = $value;
                        }
                    }
                }
                if (! isset($data['external_id']) || $data['external_id'] === '' || mb_strlen((string) $data['external_id']) > 100) {
                    $errors['external_id'] = ['Для каждой строки нужен стабильный внешний ID до 100 символов.'];
                }
                $rows[] = ['line' => $line, 'data' => $data, 'errors' => $errors];
            }
            if (! $rows) {
                $this->invalid('В CSV нет записей.');
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
