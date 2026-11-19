<?php

namespace App\Services;

use App\Data\RemoteDocumentData;
use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Service;

class DocumentRegistrar
{
    public function register(Service $service, RemoteDocumentData $remote): Document
    {
        return Document::query()->createOrFirst(
            [
                'service_id' => $service->id,
                'remote_id' => $remote->id,
            ],
            [
                'remote_uuid' => $remote->uuid,
                'document_type_id' => $remote->documentTypeId,
                'series' => $remote->series,
                'number' => $remote->number,
                'issue_date' => $remote->issueDate,
                'total' => $remote->total,
                'currency_type_id' => $remote->currencyTypeId,
                'status' => DocumentStatus::Pending,
                'attempts' => 0,
                'audit_metadata' => [
                    'remote_state' => $remote->stateTypeId,
                    'registered_at' => now()->toIso8601String(),
                ],
            ]
        );
    }
}
