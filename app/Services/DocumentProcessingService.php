<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Exceptions\DocumentDownloadException;
use App\Exceptions\DocumentProcessingException;
use App\Mail\DocumentMail;
use App\Models\Document;
use App\Models\Service;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

class DocumentProcessingService
{
    public function __construct(
        private readonly RemoteDocumentRepository $repository,
        private readonly FacturanetDocumentClient $client,
        private readonly RecipientResolver $recipients,
    ) {}

    public function process(int $serviceId, int $remoteDocumentId): void
    {
        $startedAt = microtime(true);
        $document = $this->acquire($serviceId, $remoteDocumentId);

        if (! $document) {
            return;
        }

        $service = Service::find($serviceId);
        if (! $service || $service->isactive !== 'Y') {
            $this->markFailed($document, 'El servicio no existe o está inactivo.');

            return;
        }

        Log::info('Inicio de procesamiento de documento electrónico.', $this->context($document));

        $attachments = null;

        try {
            $remote = $this->repository->findEligible($service, $remoteDocumentId);
            if (! $remote) {
                throw new DocumentProcessingException('El documento remoto no existe o dejó de ser elegible.');
            }

            $recipient = $this->recipients->resolve($remote);
            $document->update([
                'remote_uuid' => $remote->uuid,
                'document_type_id' => $remote->documentTypeId,
                'series' => $remote->series,
                'number' => $remote->number,
                'issue_date' => $remote->issueDate,
                'total' => $remote->total,
                'currency_type_id' => $remote->currencyTypeId,
                'recipient' => $recipient['address'],
                'recipient_source' => $recipient['source'],
            ]);

            $attachments = $this->client->download($service, $remote->uuid);
            Log::info('Adjuntos validados.', $this->context($document) + [
                'cdr_present' => $attachments->cdr !== null,
            ]);

            try {
                Mail::to($recipient['address'])->send(new DocumentMail($service, $remote, $attachments));
            } catch (TransportExceptionInterface $exception) {
                $this->markUncertain($document, $exception);

                return;
            } catch (Throwable $exception) {
                // Once mail delivery begins, an arbitrary failure cannot prove non-acceptance by SMTP.
                $this->markUncertain($document, $exception);

                return;
            }

            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $document->update([
                'status' => DocumentStatus::Sent,
                'sent_at' => now(),
                'locked_until' => null,
                'last_error' => null,
                'audit_metadata' => $this->audit($document, [
                    'last_result' => 'sent',
                    'last_duration_ms' => $duration,
                    'cdr_attached' => $attachments->cdr !== null,
                ]),
            ]);

            Log::info('Documento electrónico enviado.', $this->context($document) + [
                'duration_ms' => $duration,
            ]);
        } catch (DocumentDownloadException $exception) {
            $this->markFailed($document, $exception->getMessage());

            if ($exception->transient) {
                throw $exception;
            }
        } catch (DocumentProcessingException $exception) {
            $this->markFailed($document, $exception->getMessage());
        } catch (Throwable $exception) {
            $this->markFailed($document, 'Error técnico durante el procesamiento.');
            throw $exception;
        } finally {
            $attachments?->cleanup();
        }
    }

    private function acquire(int $serviceId, int $remoteDocumentId): ?Document
    {
        return DB::transaction(function () use ($serviceId, $remoteDocumentId): ?Document {
            $document = Document::query()
                ->where('service_id', $serviceId)
                ->where('remote_id', $remoteDocumentId)
                ->lockForUpdate()
                ->first();

            if (! $document || $document->isFinal()) {
                return null;
            }

            if ($document->status === DocumentStatus::Processing
                && $document->locked_until?->isFuture()) {
                return null;
            }

            $document->update([
                'status' => DocumentStatus::Processing,
                'attempts' => $document->attempts + 1,
                'last_attempt_at' => now(),
                'processing_started_at' => now(),
                'locked_until' => now()->addMinutes((int) config('facturanet.processing.lock_minutes')),
                'last_error' => null,
            ]);

            return $document->fresh();
        });
    }

    private function markFailed(Document $document, string $message): void
    {
        $document->update([
            'status' => DocumentStatus::Failed,
            'locked_until' => null,
            'last_error' => $this->safeError($message),
            'audit_metadata' => $this->audit($document, ['last_result' => 'failed']),
        ]);

        Log::warning('Falló el procesamiento de documento electrónico.', $this->context($document));
    }

    private function markUncertain(Document $document, Throwable $exception): void
    {
        $document->update([
            'status' => DocumentStatus::Uncertain,
            'locked_until' => null,
            'last_error' => $this->safeError($exception->getMessage()),
            'audit_metadata' => $this->audit($document, ['last_result' => 'uncertain']),
        ]);

        Log::error('El resultado SMTP del documento es incierto; no se reintentará automáticamente.', $this->context($document));
    }

    /** @return array<string, mixed> */
    private function audit(Document $document, array $values): array
    {
        return array_merge($document->audit_metadata ?? [], $values, [
            'last_processed_at' => now()->toIso8601String(),
        ]);
    }

    /** @return array{service_id: int, document_id: int, remote_id: int} */
    private function context(Document $document): array
    {
        return [
            'service_id' => (int) $document->service_id,
            'document_id' => (int) $document->id,
            'remote_id' => (int) $document->remote_id,
        ];
    }

    private function safeError(string $message): string
    {
        return Str::limit(preg_replace('/\s+/', ' ', $message) ?: 'Error no especificado.', 1000);
    }
}
