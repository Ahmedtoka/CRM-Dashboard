<?php

namespace App\Media;

final readonly class FetchedMedia
{
    public function __construct(
        public string $bytes,
        public ?string $mime,
        public ?string $filename,
    ) {}
}
