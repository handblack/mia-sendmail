<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    protected $fillable = [
        'name',
        'company_name',
        'phone',
        'profile',
        'url',
        'remote_connection',
        'mail_subject',
        'isactive',
        'automatic_enabled',
        'sync_from_id',
        'sync_from_date',
        'settings',
        'dbhost',
        'dbport',
        'dbuser',
        'dbpass',
        'dbname',
    ];

    protected $hidden = [
        'dbpass',
        'smtp_pass',
    ];

    protected function casts(): array
    {
        return [
            'automatic_enabled' => 'boolean',
            'sync_from_id' => 'integer',
            'sync_from_date' => 'date',
            'settings' => 'array',
            'dbpass' => 'encrypted',
            'smtp_pass' => 'encrypted',
        ];
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('isactive', 'Y');
    }

    public function isAutomaticReady(): bool
    {
        return $this->automatic_enabled
            && (config('facturanet.minimum_issue_date')
                || $this->sync_from_id !== null
                || $this->sync_from_date !== null);
    }

    public function remoteConnectionName(): string
    {
        return $this->remote_connection ?: 'facturanet';
    }
}
