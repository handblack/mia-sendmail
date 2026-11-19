<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class RemoteConnectionManager
{
    public function for(Service $service): ConnectionInterface
    {
        $configuredName = $service->remoteConnectionName();
        $base = config("database.connections.{$configuredName}");

        if (! is_array($base)) {
            throw new \InvalidArgumentException("La conexión remota [{$configuredName}] no está configurada.");
        }

        $hasOverrides = $service->dbhost || $service->dbname || $service->dbuser;
        if (! $hasOverrides) {
            return DB::connection($configuredName);
        }

        $runtimeName = "facturanet_service_{$service->id}";
        config([
            "database.connections.{$runtimeName}" => array_merge($base, array_filter([
                'host' => $service->dbhost,
                'port' => $service->dbport,
                'database' => $service->dbname,
                'username' => $service->dbuser,
                'password' => $service->dbpass,
            ], static fn ($value): bool => $value !== null && $value !== '')),
        ]);

        DB::purge($runtimeName);

        return DB::connection($runtimeName);
    }
}
