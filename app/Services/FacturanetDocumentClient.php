<?php

namespace App\Services;

use App\Data\DocumentAttachments;
use App\Exceptions\DocumentDownloadException;
use App\Models\Service;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class FacturanetDocumentClient
{
    public function download(Service $service, string $uuid): DocumentAttachments
    {
        if (! Str::isUuid($uuid)) {
            throw new DocumentDownloadException('El UUID remoto no es válido.');
        }

        $directory = storage_path('app/private/document-mail/'.Str::uuid());
        File::makeDirectory($directory, 0700, true);

        try {
            $pdf = $this->downloadRequired($this->url($service, "documents/print/{$uuid}/a4"), "{$directory}/document.pdf", 'pdf');
            $xml = $this->downloadRequired($this->url($service, "documents/download/{$uuid}/xml/documents"), "{$directory}/document.xml", 'xml');
            $cdr = $this->downloadOptional($this->url($service, "documents/download/{$uuid}/cdr/documents"), "{$directory}/cdr.zip", 'zip');

            return new DocumentAttachments($directory, $pdf, $xml, $cdr);
        } catch (Throwable $exception) {
            File::deleteDirectory($directory);
            throw $exception;
        }
    }

    private function downloadRequired(string $url, string $path, string $type): string
    {
        $response = $this->request($url, $path);

        if (! $response->successful()) {
            throw new DocumentDownloadException(
                "No se pudo descargar el archivo obligatorio {$type} (HTTP {$response->status()}).",
                $response->serverError() || $response->status() === 429
            );
        }

        $this->validateFile($path, $type);

        return $path;
    }

    private function downloadOptional(string $url, string $path, string $type): ?string
    {
        $response = $this->request($url, $path);

        if (in_array($response->status(), [404, 410], true)) {
            File::delete($path);

            return null;
        }

        if (! $response->successful()) {
            throw new DocumentDownloadException(
                "No se pudo consultar el CDR (HTTP {$response->status()}).",
                $response->serverError() || $response->status() === 429
            );
        }

        try {
            $this->validateFile($path, $type);
        } catch (DocumentDownloadException) {
            File::delete($path);

            return null;
        }

        return $path;
    }

    private function request(string $initialUrl, string $path): Response
    {
        $url = $initialUrl;
        $maxRedirects = (int) config('facturanet.http.max_redirects', 2);

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            $this->assertAllowedUrl($url);

            try {
                $maxBytes = (int) config('facturanet.http.max_bytes');
                $response = Http::connectTimeout((int) config('facturanet.http.connect_timeout'))
                    ->timeout((int) config('facturanet.http.timeout'))
                    ->withOptions([
                        'allow_redirects' => false,
                        'on_headers' => static function ($response) use ($maxBytes): void {
                            $length = (int) $response->getHeaderLine('Content-Length');
                            if ($length > $maxBytes) {
                                throw new RuntimeException('El archivo remoto excede el límite permitido.');
                            }
                        },
                        'progress' => static function ($total, $downloaded) use ($maxBytes): void {
                            if ($downloaded > $maxBytes) {
                                throw new RuntimeException('El archivo remoto excede el límite permitido.');
                            }
                        },
                    ])
                    ->sink($path)
                    ->get($url);
            } catch (ConnectionException $exception) {
                throw new DocumentDownloadException('No fue posible conectar con Facturanet.', true);
            } catch (Throwable $exception) {
                throw new DocumentDownloadException('Falló la descarga segura del archivo remoto.', true);
            }

            if (! $response->redirect()) {
                return $response;
            }

            $location = $response->header('Location');
            if (! $location || $redirects === $maxRedirects) {
                throw new DocumentDownloadException('Facturanet devolvió una redirección no válida.');
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        throw new DocumentDownloadException('Se excedió el máximo de redirecciones.');
    }

    private function url(Service $service, string $path): string
    {
        $base = rtrim((string) $service->url, '/');
        $this->assertAllowedUrl($base);

        return $base.'/'.ltrim($path, '/');
    }

    private function assertAllowedUrl(string $url): void
    {
        $parts = parse_url($url);
        $allowed = array_map('strtolower', config('facturanet.http.allowed_hosts', []));

        if (($parts['scheme'] ?? null) !== 'https'
            || empty($parts['host'])
            || ! in_array(strtolower($parts['host']), $allowed, true)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw new DocumentDownloadException('La URL remota no pertenece a un dominio HTTPS autorizado.');
        }
    }

    private function validateFile(string $path, string $type): void
    {
        if (! is_file($path) || ($size = filesize($path)) === false || $size === 0) {
            throw new DocumentDownloadException("El archivo {$type} está vacío.");
        }

        if ($size > (int) config('facturanet.http.max_bytes')) {
            throw new DocumentDownloadException("El archivo {$type} excede el límite permitido.");
        }

        $handle = fopen($path, 'rb');
        $prefix = $handle ? fread($handle, 16) : false;
        if (is_resource($handle)) {
            fclose($handle);
        }

        $valid = match ($type) {
            'pdf' => is_string($prefix) && str_starts_with($prefix, '%PDF-'),
            'xml' => $this->isValidXml($path),
            'zip' => is_string($prefix) && preg_match('/^PK[\x03\x05\x07][\x04\x06\x08]/', $prefix) === 1,
            default => false,
        };

        if (! $valid) {
            throw new DocumentDownloadException("El contenido del archivo {$type} no es válido.");
        }
    }

    private function isValidXml(string $path): bool
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $loaded = $document->load($path, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded && $document->documentElement !== null;
    }
}
