<?php

namespace App\Http\Responses;

use Closure;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/** Reauthorize a generated temporary file when the server starts sending it. */
final class CheckedTemporaryDownload extends BinaryFileResponse
{
    public function __construct(BinaryFileResponse $download, private readonly Closure $authorize)
    {
        parent::__construct($download->getFile(), $download->getStatusCode(), $download->headers->all(), false);
        $this->deleteFileAfterSend(true);
    }

    public function sendContent(): static
    {
        try {
            ($this->authorize)();
        } catch (\Throwable $error) {
            $path = $this->getFile()->getPathname();
            if (is_file($path)) unlink($path);
            throw $error;
        }

        return parent::sendContent();
    }
}
