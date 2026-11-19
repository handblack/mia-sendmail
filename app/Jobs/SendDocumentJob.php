<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\DocumentProcessingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendDocumentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 900;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $serviceId,
        public readonly int $remoteDocumentId,
    ) {
        $this->onQueue((string) config('facturanet.processing.queue', 'documents'));
    }

    public function uniqueId(): string
    {
        return "{$this->serviceId}:{$this->remoteDocumentId}";
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->uniqueId()))
                ->shared()
                ->releaseAfter(30)
                ->expireAfter(600),
        ];
    }

    public function handle(DocumentProcessingService $processor): void
    {
        $processor->process($this->serviceId, $this->remoteDocumentId);
    }

    public function failed(?Throwable $exception): void
    {
        Document::query()
            ->where('service_id', $this->serviceId)
            ->where('remote_id', $this->remoteDocumentId)
            ->whereNotIn('status', [DocumentStatus::Sent->value, DocumentStatus::Uncertain->value])
            ->update([
                'status' => DocumentStatus::Failed->value,
                'locked_until' => null,
                'last_error' => 'Se agotaron los reintentos del worker.',
                'updated_at' => now(),
            ]);
    }
}
