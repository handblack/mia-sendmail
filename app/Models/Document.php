<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Document extends Model
{
    protected $fillable = [
        'service_id',
        'remote_id',
        'remote_uuid',
        'document_type_id',
        'series',
        'number',
        'issue_date',
        'total',
        'currency_type_id',
        'recipient',
        'recipient_source',
        'status',
        'attempts',
        'last_attempt_at',
        'processing_started_at',
        'locked_until',
        'sent_at',
        'last_error',
        'audit_metadata',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'issue_date' => 'date',
            'total' => 'decimal:2',
            'last_attempt_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'locked_until' => 'datetime',
            'sent_at' => 'datetime',
            'audit_metadata' => 'array',
        ];
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [DocumentStatus::Sent, DocumentStatus::Uncertain], true);
    }
}
