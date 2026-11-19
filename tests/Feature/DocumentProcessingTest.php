<?php

use App\Data\DocumentAttachments;
use App\Data\RemoteDocumentData;
use App\Enums\DocumentStatus;
use App\Jobs\SendDocumentJob;
use App\Mail\DocumentMail;
use App\Models\Document;
use App\Models\Service;
use App\Services\DocumentProcessingService;
use App\Services\FacturanetDocumentClient;
use App\Services\RecipientResolver;
use App\Services\RemoteDocumentRepository;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Mockery\MockInterface;
use Symfony\Component\Mailer\Exception\TransportException;

uses(RefreshDatabase::class);

function processingService(array $attributes = []): Service
{
    return Service::create(array_merge([
        'name' => 'Anticipate',
        'company_name' => 'MIA SOFTWARE NETWORKS S.A.C.',
        'phone' => '987265551',
        'profile' => 'anticipate',
        'url' => 'https://anticipate.facturanet.pe',
        'remote_connection' => 'facturanet',
        'isactive' => 'Y',
    ], $attributes));
}

function remoteDocument(array $attributes = []): RemoteDocumentData
{
    return new RemoteDocumentData(...array_merge([
        'id' => 14031,
        'uuid' => 'e1d9966d-0aec-4839-ab38-31b14b9863e8',
        'documentTypeId' => '03',
        'documentType' => 'BOLETA DE VENTA ELECTRÓNICA',
        'series' => 'BV01',
        'number' => 13815,
        'issueDate' => CarbonImmutable::parse('2026-11-18', 'America/Lima'),
        'customerName' => 'Cliente de prueba',
        'currentEmail' => 'cliente@empresa.test',
        'snapshotEmail' => 'anterior@empresa.test',
        'total' => '100.50',
        'currencyTypeId' => 'PEN',
        'stateTypeId' => '05',
    ], $attributes));
}

function localDocument(Service $service, DocumentStatus $status = DocumentStatus::Pending, array $attributes = []): Document
{
    return Document::create(array_merge([
        'service_id' => $service->id,
        'remote_id' => 14031,
        'remote_uuid' => 'e1d9966d-0aec-4839-ab38-31b14b9863e8',
        'document_type_id' => '03',
        'series' => 'BV01',
        'number' => 13815,
        'status' => $status,
        'attempts' => 0,
    ], $attributes));
}

function testAttachments(bool $withCdr = true): DocumentAttachments
{
    $directory = storage_path('framework/testing/document-'.uniqid());
    File::makeDirectory($directory, 0700, true);
    File::put("{$directory}/document.pdf", '%PDF-1.7 test');
    File::put("{$directory}/document.xml", '<?xml version="1.0"?><Invoice/>');
    if ($withCdr) {
        File::put("{$directory}/cdr.zip", "PK\x03\x04test");
    }

    return new DocumentAttachments(
        $directory,
        "{$directory}/document.pdf",
        "{$directory}/document.xml",
        $withCdr ? "{$directory}/cdr.zip" : null,
    );
}

/** @return array{0: DocumentProcessingService, 1: MockInterface, 2: MockInterface} */
function processorWith(RemoteDocumentData $remote, DocumentAttachments $attachments): array
{
    $repository = Mockery::mock(RemoteDocumentRepository::class);
    $repository->shouldReceive('findEligible')->andReturn($remote);
    $client = Mockery::mock(FacturanetDocumentClient::class);
    $client->shouldReceive('download')->andReturn($attachments);

    return [new DocumentProcessingService($repository, $client, new RecipientResolver), $repository, $client];
}

test('sends a valid document with PDF XML and CDR only to support', function () {
    Mail::fake();
    $service = processingService();
    $document = localDocument($service);
    [$processor] = processorWith(remoteDocument(), testAttachments());

    $processor->process($service->id, $document->remote_id);

    $document->refresh();
    expect($document->status)->toBe(DocumentStatus::Sent)
        ->and($document->recipient)->toBe('soporte@miasoftware.net')
        ->and($document->recipient_source)->toBe('safety_override')
        ->and($document->sent_at)->not->toBeNull();

    Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail) => $mail->hasTo('soporte@miasoftware.net'));
});

test('sends a valid document without CDR', function () {
    Mail::fake();
    $service = processingService();
    $document = localDocument($service);
    [$processor] = processorWith(remoteDocument(), testAttachments(false));

    $processor->process($service->id, $document->remote_id);

    expect($document->fresh()->status)->toBe(DocumentStatus::Sent);
    Mail::assertSent(DocumentMail::class);
});

test('never processes a document already sent', function () {
    Mail::fake();
    $service = processingService();
    $document = localDocument($service, DocumentStatus::Sent, ['sent_at' => now()]);
    $repository = Mockery::mock(RemoteDocumentRepository::class);
    $repository->shouldNotReceive('findEligible');
    $client = Mockery::mock(FacturanetDocumentClient::class);
    $client->shouldNotReceive('download');

    (new DocumentProcessingService($repository, $client, new RecipientResolver))
        ->process($service->id, $document->remote_id);

    Mail::assertNothingSent();
    expect($document->fresh()->attempts)->toBe(0);
});

test('a document without customer email still goes only to the authorized support address', function () {
    Mail::fake();
    $service = processingService();
    $document = localDocument($service);
    [$processor] = processorWith(
        remoteDocument(['currentEmail' => null, 'snapshotEmail' => null]),
        testAttachments()
    );

    $processor->process($service->id, $document->remote_id);

    expect($document->fresh()->recipient)->toBe('soporte@miasoftware.net');
    Mail::assertSent(DocumentMail::class, fn (DocumentMail $mail) => $mail->hasTo('soporte@miasoftware.net'));
});

test('an SMTP error leaves the document uncertain and prevents automatic retry', function () {
    $service = processingService();
    $document = localDocument($service);
    [$processor] = processorWith(remoteDocument(), testAttachments());

    Mail::shouldReceive('to')->once()->with('soporte@miasoftware.net')->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new TransportException('SMTP connection lost'));

    $processor->process($service->id, $document->remote_id);

    expect($document->fresh()->status)->toBe(DocumentStatus::Uncertain)
        ->and($document->fresh()->locked_until)->toBeNull();
});

test('a failed document can be retried safely', function () {
    Mail::fake();
    $service = processingService();
    $document = localDocument($service, DocumentStatus::Failed, ['attempts' => 1]);
    [$processor] = processorWith(remoteDocument(), testAttachments());

    $processor->process($service->id, $document->remote_id);

    expect($document->fresh()->status)->toBe(DocumentStatus::Sent)
        ->and($document->fresh()->attempts)->toBe(2);
});

test('a second worker cannot process a document with an active lock', function () {
    Mail::fake();
    $service = processingService();
    $document = localDocument($service, DocumentStatus::Processing, [
        'locked_until' => now()->addMinutes(5),
        'attempts' => 1,
    ]);
    $repository = Mockery::mock(RemoteDocumentRepository::class);
    $repository->shouldNotReceive('findEligible');
    $client = Mockery::mock(FacturanetDocumentClient::class);
    $client->shouldNotReceive('download');

    (new DocumentProcessingService($repository, $client, new RecipientResolver))
        ->process($service->id, $document->remote_id);

    expect($document->fresh()->attempts)->toBe(1);
    Mail::assertNothingSent();
});

test('an expired worker lock is recovered', function () {
    Mail::fake();
    $service = processingService();
    $document = localDocument($service, DocumentStatus::Processing, [
        'locked_until' => now()->subMinute(),
        'attempts' => 1,
    ]);
    [$processor] = processorWith(remoteDocument(), testAttachments());

    $processor->process($service->id, $document->remote_id);

    expect($document->fresh()->status)->toBe(DocumentStatus::Sent)
        ->and($document->fresh()->attempts)->toBe(2);
});

test('the queue failure callback releases processing state', function () {
    $service = processingService();
    $document = localDocument($service, DocumentStatus::Processing, [
        'locked_until' => now()->addMinutes(5),
    ]);

    (new SendDocumentJob($service->id, $document->remote_id))->failed(new RuntimeException('worker stopped'));

    expect($document->fresh()->status)->toBe(DocumentStatus::Failed)
        ->and($document->fresh()->locked_until)->toBeNull();
});

test('uncertain documents are never automatically processed again', function () {
    Mail::fake();
    $service = processingService();
    $document = localDocument($service, DocumentStatus::Uncertain);
    $repository = Mockery::mock(RemoteDocumentRepository::class);
    $repository->shouldNotReceive('findEligible');
    $client = Mockery::mock(FacturanetDocumentClient::class);
    $client->shouldNotReceive('download');

    (new DocumentProcessingService($repository, $client, new RecipientResolver))
        ->process($service->id, $document->remote_id);

    expect($document->fresh()->status)->toBe(DocumentStatus::Uncertain);
    Mail::assertNothingSent();
});

test('the same remote document is isolated between services', function () {
    $first = processingService(['name' => 'Empresa A']);
    $second = processingService(['name' => 'Empresa B']);

    $firstDocument = localDocument($first);
    $secondDocument = localDocument($second);

    expect($firstDocument->remote_id)->toBe($secondDocument->remote_id)
        ->and(Document::count())->toBe(2)
        ->and($firstDocument->service_id)->not->toBe($secondDocument->service_id);
});
