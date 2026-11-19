<?php

use App\Exceptions\DocumentDownloadException;
use App\Models\Service;
use App\Services\FacturanetDocumentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function downloadService(): Service
{
    return Service::create([
        'name' => 'Anticipate',
        'company_name' => 'Anticipate',
        'profile' => 'anticipate',
        'url' => 'https://anticipate.facturanet.pe',
        'remote_connection' => 'facturanet',
        'isactive' => 'Y',
    ]);
}

function fakeDocumentFiles(bool $withCdr = true): void
{
    Http::fake(function ($request) use ($withCdr) {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/print/') => Http::response('%PDF-1.7 test', 200, ['Content-Type' => 'application/pdf']),
            str_contains($url, '/xml/') => Http::response('<?xml version="1.0"?><Invoice/>', 200, ['Content-Type' => 'text/xml']),
            str_contains($url, '/cdr/') && $withCdr => Http::response("PK\x03\x04test", 200, ['Content-Type' => 'application/zip']),
            default => Http::response('', 404),
        };
    });
}

test('downloads and validates PDF XML and CDR', function () {
    fakeDocumentFiles();

    $files = app(FacturanetDocumentClient::class)->download(
        downloadService(),
        'ae6bae42-9540-4e6b-b5e8-3a51f8c1b7ba'
    );

    expect($files->pdf)->toBeFile()
        ->and($files->xml)->toBeFile()
        ->and($files->cdr)->toBeFile();

    $directory = $files->directory;
    $files->cleanup();
    expect($directory)->not->toBeDirectory();
});

test('allows an absent CDR', function () {
    fakeDocumentFiles(false);

    $files = app(FacturanetDocumentClient::class)->download(
        downloadService(),
        'ae6bae42-9540-4e6b-b5e8-3a51f8c1b7ba'
    );

    expect($files->pdf)->toBeFile()
        ->and($files->xml)->toBeFile()
        ->and($files->cdr)->toBeNull();

    $files->cleanup();
});

test('rejects a missing PDF', function () {
    Http::fake([
        '*/print/*' => Http::response('', 404),
    ]);

    app(FacturanetDocumentClient::class)->download(downloadService(), 'ae6bae42-9540-4e6b-b5e8-3a51f8c1b7ba');
})->throws(DocumentDownloadException::class, 'archivo obligatorio pdf');

test('rejects a missing XML', function () {
    Http::fake(function ($request) {
        return str_contains($request->url(), '/print/')
            ? Http::response('%PDF-1.7 test', 200)
            : Http::response('', 404);
    });

    app(FacturanetDocumentClient::class)->download(downloadService(), 'ae6bae42-9540-4e6b-b5e8-3a51f8c1b7ba');
})->throws(DocumentDownloadException::class, 'archivo obligatorio xml');

test('classifies an HTTP 500 as transient', function () {
    Http::fake(['*' => Http::response('', 500)]);

    try {
        app(FacturanetDocumentClient::class)->download(downloadService(), 'ae6bae42-9540-4e6b-b5e8-3a51f8c1b7ba');
        $this->fail('The exception was not thrown.');
    } catch (DocumentDownloadException $exception) {
        expect($exception->transient)->toBeTrue();
    }
});

test('classifies a remote timeout as transient', function () {
    Http::fake(fn () => throw new ConnectionException('timeout'));

    try {
        app(FacturanetDocumentClient::class)->download(downloadService(), 'ae6bae42-9540-4e6b-b5e8-3a51f8c1b7ba');
        $this->fail('The exception was not thrown.');
    } catch (DocumentDownloadException $exception) {
        expect($exception->transient)->toBeTrue();
    }
});

test('rejects invalid content even when the response is successful', function () {
    Http::fake(['*' => Http::response('<html>login</html>', 200)]);

    app(FacturanetDocumentClient::class)->download(downloadService(), 'ae6bae42-9540-4e6b-b5e8-3a51f8c1b7ba');
})->throws(DocumentDownloadException::class, 'contenido del archivo pdf no es válido');

test('rejects a base URL outside the allowlist', function () {
    $service = downloadService();
    $service->update(['url' => 'https://127.0.0.1']);

    app(FacturanetDocumentClient::class)->download($service, 'ae6bae42-9540-4e6b-b5e8-3a51f8c1b7ba');
})->throws(DocumentDownloadException::class, 'dominio HTTPS autorizado');
