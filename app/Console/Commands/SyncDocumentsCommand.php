<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Jobs\SendDocumentJob;
use App\Models\Service;
use App\Services\DocumentRegistrar;
use App\Services\RemoteDocumentRepository;
use Illuminate\Console\Command;

class SyncDocumentsCommand extends Command
{
    protected $signature = 'documents:sync
        {--service= : Procesar únicamente este servicio}
        {--chunk=100 : Cantidad de documentos por lote}
        {--dry-run : Consultar sin registrar ni despachar Jobs}';

    protected $description = 'Sincroniza documentos elegibles de Facturanet y despacha su envío';

    public function handle(RemoteDocumentRepository $repository, DocumentRegistrar $registrar): int
    {
        $chunk = max(1, min(500, (int) $this->option('chunk')));
        $services = Service::query()->active()
            ->when($this->option('service'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        if ($services->isEmpty()) {
            $this->warn('No se encontraron servicios activos.');

            return self::SUCCESS;
        }

        foreach ($services as $service) {
            if (! $service->isAutomaticReady()) {
                $this->warn("Servicio {$service->id}: envío automático deshabilitado o sin punto inicial.");

                continue;
            }

            $cursor = max(0, ((int) ($service->sync_from_id ?? 1)) - 1);
            $found = 0;
            $dispatched = 0;

            do {
                $documents = $repository->eligibleAfter($service, $cursor, $chunk);

                foreach ($documents as $remote) {
                    $cursor = $remote->id;
                    $found++;

                    if ($this->option('dry-run')) {
                        continue;
                    }

                    $local = $registrar->register($service, $remote);
                    if ($local->status === DocumentStatus::Pending) {
                        SendDocumentJob::dispatch($service->id, $remote->id);
                        $dispatched++;
                    }
                }
            } while ($documents->count() === $chunk);

            $message = "Servicio {$service->id}: {$found} elegibles";
            $message .= $this->option('dry-run') ? ' (simulación).' : ", {$dispatched} Jobs despachados.";
            $this->info($message);
        }

        return self::SUCCESS;
    }
}
