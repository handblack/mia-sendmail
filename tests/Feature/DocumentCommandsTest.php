<?php

use App\Jobs\SendDocumentJob;
use App\Models\Service;
use App\Services\RemoteDocumentRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('manual command registers the document and dispatches its job', function () {
    Queue::fake();
    $service = processingService();
    $remote = remoteDocument();

    $repository = Mockery::mock(RemoteDocumentRepository::class);
    $repository->shouldReceive('findEligible')
        ->once()
        ->withArgs(fn (Service $given, int $id) => $given->is($service) && $id === $remote->id)
        ->andReturn($remote);
    app()->instance(RemoteDocumentRepository::class, $repository);

    $this->artisan('documents:send', [
        'serviceId' => $service->id,
        'documentId' => $remote->id,
    ])->assertSuccessful();

    $this->assertDatabaseHas('documents', [
        'service_id' => $service->id,
        'remote_id' => $remote->id,
        'status' => 'pending',
    ]);
    Queue::assertPushed(SendDocumentJob::class, fn (SendDocumentJob $job) => $job->serviceId === $service->id && $job->remoteDocumentId === $remote->id
    );
});

test('automatic sync stays disabled without an explicit starting point', function () {
    Queue::fake();
    $service = processingService(['automatic_enabled' => false]);
    $repository = Mockery::mock(RemoteDocumentRepository::class);
    $repository->shouldNotReceive('eligibleAfter');
    app()->instance(RemoteDocumentRepository::class, $repository);

    $this->artisan('documents:sync', ['--service' => $service->id])
        ->expectsOutputToContain('envío automático deshabilitado')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('automatic sync uses the confirmed global cutoff once the service is enabled', function () {
    Queue::fake();
    $service = processingService([
        'automatic_enabled' => true,
    ]);
    $remote = remoteDocument();
    $repository = Mockery::mock(RemoteDocumentRepository::class);
    $repository->shouldReceive('eligibleAfter')
        ->once()
        ->andReturn(collect([$remote]));
    app()->instance(RemoteDocumentRepository::class, $repository);

    $this->artisan('documents:sync', [
        '--service' => $service->id,
        '--chunk' => 100,
    ])->assertSuccessful();

    Queue::assertPushed(SendDocumentJob::class, 1);
});
