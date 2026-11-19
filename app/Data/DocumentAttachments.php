<?php

namespace App\Data;

use Illuminate\Support\Facades\File;

final class DocumentAttachments
{
    public function __construct(
        public readonly string $directory,
        public readonly string $pdf,
        public readonly string $xml,
        public readonly ?string $cdr,
    ) {}

    public function cleanup(): void
    {
        if (is_dir($this->directory)) {
            File::deleteDirectory($this->directory);
        }
    }
}
