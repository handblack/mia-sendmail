<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class RemoteDocumentData
{
    public function __construct(
        public int $id,
        public string $uuid,
        public string $documentTypeId,
        public string $documentType,
        public string $series,
        public int $number,
        public ?CarbonImmutable $issueDate,
        public ?string $customerName,
        public ?string $currentEmail,
        public ?string $snapshotEmail,
        public ?string $total,
        public ?string $currencyTypeId,
        public string $stateTypeId,
    ) {}

    public function identifier(): string
    {
        return sprintf('%s-%d', $this->series, $this->number);
    }
}
