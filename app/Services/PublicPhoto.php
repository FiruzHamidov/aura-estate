<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Intervention\Image\Exceptions\DecoderException;
use Intervention\Image\ImageManager;
use RuntimeException;

final class PublicPhoto
{
    public static function store(UploadedFile $file, string $directory, string $field = 'photo'): string
    {
        $dimensions = @getimagesize($file->getRealPath());
        if (!$dimensions || $dimensions[0] * $dimensions[1] > 16000000) {
            throw ValidationException::withMessages([$field => 'Некорректное или слишком большое изображение.']);
        }

        try {
            $photo = ImageManager::gd(decodeAnimation: false)->read($file->getRealPath());
        } catch (DecoderException) {
            throw ValidationException::withMessages([$field => 'Не удалось прочитать изображение.']);
        }

        $path = $directory.'/'.Str::uuid().'.webp';
        if (!Storage::disk('public')->put($path, (string) $photo->toWebp(85))) {
            throw new RuntimeException('Не удалось сохранить изображение.');
        }

        return $path;
    }
}
