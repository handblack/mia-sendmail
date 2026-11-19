<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            if (! Schema::hasColumn('services', 'name')) {
                $table->string('name')->nullable();
            }
            if (! Schema::hasColumn('services', 'company_name')) {
                $table->string('company_name')->nullable();
            }
            if (! Schema::hasColumn('services', 'phone')) {
                $table->string('phone', 50)->nullable();
            }
            if (! Schema::hasColumn('services', 'profile')) {
                $table->string('profile', 100)->nullable();
            }
            if (! Schema::hasColumn('services', 'url')) {
                $table->string('url')->nullable();
            }
            if (! Schema::hasColumn('services', 'remote_connection')) {
                $table->string('remote_connection')->default('facturanet');
            }
            if (! Schema::hasColumn('services', 'mail_subject')) {
                $table->string('mail_subject')->nullable();
            }
            if (! Schema::hasColumn('services', 'automatic_enabled')) {
                $table->boolean('automatic_enabled')->default(false);
            }
            if (! Schema::hasColumn('services', 'sync_from_id')) {
                $table->unsignedBigInteger('sync_from_id')->nullable();
            }
            if (! Schema::hasColumn('services', 'sync_from_date')) {
                $table->date('sync_from_date')->nullable();
            }
            if (! Schema::hasColumn('services', 'settings')) {
                $table->json('settings')->nullable();
            }
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('service_id')->after('id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('remote_id')->after('service_id');
            $table->uuid('remote_uuid')->after('remote_id');
            $table->string('document_type_id', 10)->after('remote_uuid');
            $table->string('series', 20)->after('document_type_id');
            $table->unsignedBigInteger('number')->after('series');
            $table->date('issue_date')->nullable()->after('number');
            $table->decimal('total', 14, 2)->nullable()->after('issue_date');
            $table->string('currency_type_id', 10)->nullable()->after('total');
            $table->string('recipient')->nullable()->after('currency_type_id');
            $table->string('recipient_source', 30)->nullable()->after('recipient');
            $table->string('status', 20)->default('pending')->after('recipient_source');
            $table->unsignedInteger('attempts')->default(0)->after('status');
            $table->timestamp('last_attempt_at')->nullable()->after('attempts');
            $table->timestamp('processing_started_at')->nullable()->after('last_attempt_at');
            $table->timestamp('locked_until')->nullable()->after('processing_started_at');
            $table->timestamp('sent_at')->nullable()->after('locked_until');
            $table->text('last_error')->nullable()->after('sent_at');
            $table->json('audit_metadata')->nullable()->after('last_error');

            $table->unique(['service_id', 'remote_id'], 'documents_service_remote_unique');
            $table->unique(['service_id', 'remote_uuid'], 'documents_service_uuid_unique');
            $table->index(['service_id', 'status'], 'documents_service_status_index');
            $table->index('locked_until');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->dropUnique('documents_service_remote_unique');
            $table->dropUnique('documents_service_uuid_unique');
            $table->dropIndex('documents_service_status_index');
            $table->dropIndex(['locked_until']);
            $table->dropColumn([
                'service_id', 'remote_id', 'remote_uuid', 'document_type_id', 'series', 'number',
                'issue_date', 'total', 'currency_type_id', 'recipient', 'recipient_source', 'status',
                'attempts', 'last_attempt_at', 'processing_started_at', 'locked_until', 'sent_at',
                'last_error', 'audit_metadata',
            ]);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn([
                'name', 'company_name', 'phone', 'profile', 'remote_connection', 'mail_subject',
                'automatic_enabled', 'sync_from_id', 'sync_from_date', 'settings',
            ]);
        });
    }
};
