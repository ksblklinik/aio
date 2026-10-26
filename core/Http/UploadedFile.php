<?php

declare(strict_types=1);

namespace Core\Http;

class UploadedFile
{
    public function __construct(
        private string $name,
        private string $type,
        private string $tmpName,
        private int $error,
        private int $size
    ) {
    }

    public function getClientOriginalName(): string
    {
        return $this->name;
    }

    public function getClientOriginalExtension(): string
    {
        return pathinfo($this->name, PATHINFO_EXTENSION);
    }

    public function getClientMimeType(): string
    {
        return $this->type;
    }

    public function getTmpName(): string
    {
        return $this->tmpName;
    }

    public function getError(): int
    {
        return $this->error;
    }

    public function hasError(): bool
    {
        return $this->error !== UPLOAD_ERR_OK;
    }

    public function isValid(): bool
    {
        return $this->error === UPLOAD_ERR_OK && (file_exists($this->tmpName) || is_uploaded_file($this->tmpName));
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function getContent(): string
    {
        if (!file_exists($this->tmpName)) {
            return '';
        }

        return (string) file_get_contents($this->tmpName);
    }

    public function moveTo(string $destinationPath): bool
    {
        $dir = dirname($destinationPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        if (is_uploaded_file($this->tmpName)) {
            return move_uploaded_file($this->tmpName, $destinationPath);
        }

        return copy($this->tmpName, $destinationPath);
    }
}
