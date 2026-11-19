<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Jobs\SendDocumentJob;
use App\Models\Service;
use App\Services\DocumentRegistrar;
use App\Services\FacturanetDocumentClient;
use App\Services\RemoteDocumentRepository;
use Illuminate\Console\Command;

class SendDocumentCommand extends Command
{
    protected $signature = 'documents:send
        {serviceId : ID local del servicio}
        {documentId : ID remoto del documento}
        {--dry-run : Descargar y validar adjuntos sin enviar correo}';

    protected $description = 'Valida y despacha manualmente un documento electrónico';

    public function handle(
        RemoteDocumentRepository $repository,
        DocumentRegistrar $registrar,
        FacturanetDocumentClient $client,
    ): int {
        $service = Service::query()->active()->find((int) $this->argument('serviceId'));
        if (! $service) {
            $this->error('El servicio no existe o está inactivo.');

            return self::FAILURE;
        }

        $remoteId = (int) $this->argument('documentId');
        $remote = $repository->findEligible($service, $remoteId);
        if (! $remote) {
            $this->error('El documento no existe o no cumple fecha mínima 2026-11-18, estado 05 y tipo 01, 03 o 07.');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $attachments = $client->download($service, $remote->uuid);
            try {
                $this->info('Simulación correcta: PDF y XML válidos; CDR '.($attachments->cdr ? 'disponible.' : 'no disponible.'));
            } finally {
                $attachments->cleanup();
            }

            return self::SUCCESS;
        }

        $document = $registrar->register($service, $remote);

        if ($document->status === DocumentStatus::Sent) {
            $this->warn('El documento ya fue enviado; no se reenviará.');

            return self::SUCCESS;
        }

        if ($document->status === DocumentStatus::Uncertain) {
            $this->error('El documento tiene resultado SMTP incierto y requiere revisión manual.');

            return self::FAILURE;
        }

        if ($document->status === DocumentStatus::Processing && $document->locked_until?->isFuture()) {
            $this->warn('El documento ya está siendo procesado.');

            return self::SUCCESS;
        }

        if ($document->status === DocumentStatus::Failed) {
            $document->update([
                'status' => DocumentStatus::Pending,
                'last_error' => null,
                'locked_until' => null,
            ]);
        }

        SendDocumentJob::dispatch($service->id, $remote->id);
        $this->info('Documento registrado y Job despachado. Destinatario de seguridad: soporte@miasoftware.net.');

        return self::SUCCESS;
    }
}
