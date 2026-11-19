<?php

use App\Models\Service;
use App\Services\RemoteDocumentRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('manual and automatic repository queries exclude documents before 2026-11-18', function () {
    config(['database.connections.facturanet_test' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    DB::purge('facturanet_test');

    Schema::connection('facturanet_test')->create('persons', function (Blueprint $table) {
        $table->id();
        $table->string('name')->nullable();
        $table->string('email')->nullable();
    });
    Schema::connection('facturanet_test')->create('cat_document_types', function (Blueprint $table) {
        $table->string('id')->primary();
        $table->string('description');
    });
    Schema::connection('facturanet_test')->create('documents', function (Blueprint $table) {
        $table->unsignedBigInteger('id')->primary();
        $table->uuid('external_id');
        $table->string('document_type_id');
        $table->string('series');
        $table->unsignedBigInteger('number');
        $table->date('date_of_issue');
        $table->decimal('total', 12, 2);
        $table->string('currency_type_id');
        $table->string('state_type_id');
        $table->unsignedBigInteger('customer_id');
        $table->text('customer');
    });

    DB::connection('facturanet_test')->table('persons')->insert([
        'id' => 1,
        'name' => 'Cliente',
        'email' => 'cliente@example.test',
    ]);
    DB::connection('facturanet_test')->table('cat_document_types')->insert([
        'id' => '03',
        'description' => 'BOLETA DE VENTA ELECTRÓNICA',
    ]);
    DB::connection('facturanet_test')->table('documents')->insert([
        [
            'id' => 100,
            'external_id' => '11111111-1111-4111-8111-111111111111',
            'document_type_id' => '03',
            'series' => 'BV01',
            'number' => 100,
            'date_of_issue' => '2026-11-17',
            'total' => 10,
            'currency_type_id' => 'PEN',
            'state_type_id' => '05',
            'customer_id' => 1,
            'customer' => json_encode(['name' => 'Cliente', 'email' => 'cliente@example.test']),
        ],
        [
            'id' => 101,
            'external_id' => '22222222-2222-4222-8222-222222222222',
            'document_type_id' => '03',
            'series' => 'BV01',
            'number' => 101,
            'date_of_issue' => '2026-11-18',
            'total' => 20,
            'currency_type_id' => 'PEN',
            'state_type_id' => '05',
            'customer_id' => 1,
            'customer' => json_encode(['name' => 'Cliente', 'email' => 'cliente@example.test']),
        ],
    ]);

    $service = Service::create([
        'name' => 'Prueba',
        'url' => 'https://anticipate.facturanet.pe',
        'remote_connection' => 'facturanet_test',
        'isactive' => 'Y',
        'automatic_enabled' => true,
    ]);
    $repository = app(RemoteDocumentRepository::class);

    expect($repository->findEligible($service, 100))->toBeNull()
        ->and($repository->findEligible($service, 101)?->id)->toBe(101)
        ->and($repository->eligibleAfter($service, 0, 100)->pluck('id')->all())->toBe([101]);
});
