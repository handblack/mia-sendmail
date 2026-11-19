<?php

namespace App\Services;

use App\Data\RemoteDocumentData;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

class RemoteDocumentRepository
{
    public function __construct(private readonly RemoteConnectionManager $connections) {}

    public function findEligible(Service $service, int $remoteId): ?RemoteDocumentData
    {
        $row = $this->baseQuery($service)
            ->where('d.id', $remoteId)
            ->whereIn('d.state_type_id', config('facturanet.eligible_states'))
            ->whereIn('d.document_type_id', config('facturanet.eligible_document_types'))
            ->whereDate('d.date_of_issue', '>=', config('facturanet.minimum_issue_date'))
            ->first();

        return $row ? $this->map($row) : null;
    }

    /** @return Collection<int, RemoteDocumentData> */
    public function eligibleAfter(Service $service, int $afterId, int $limit): Collection
    {
        $query = $this->baseQuery($service)
            ->where('d.id', '>', $afterId)
            ->whereIn('d.state_type_id', config('facturanet.eligible_states'))
            ->whereIn('d.document_type_id', config('facturanet.eligible_document_types'))
            ->whereDate('d.date_of_issue', '>=', config('facturanet.minimum_issue_date'));

        if ($service->sync_from_id !== null) {
            $query->where('d.id', '>=', $service->sync_from_id);
        }

        if ($service->sync_from_date !== null) {
            $query->whereDate('d.date_of_issue', '>=', $service->sync_from_date->toDateString());
        }

        return $query->orderBy('d.id')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): RemoteDocumentData => $this->map($row));
    }

    private function baseQuery(Service $service): Builder
    {
        return $this->connections->for($service)
            ->table('documents as d')
            ->join('cat_document_types as ct', 'ct.id', '=', 'd.document_type_id')
            ->leftJoin('persons as p', 'p.id', '=', 'd.customer_id')
            ->select([
                'd.id',
                'd.external_id',
                'd.document_type_id',
                'ct.description as document_type',
                'd.series',
                'd.number',
                'd.date_of_issue',
                'd.total',
                'd.currency_type_id',
                'd.state_type_id',
                'd.customer',
                'p.name as current_customer_name',
                'p.email as current_email',
            ]);
    }

    private function map(object $row): RemoteDocumentData
    {
        $snapshot = json_decode((string) ($row->customer ?? ''), true);
        $snapshot = is_array($snapshot) ? $snapshot : [];

        return new RemoteDocumentData(
            id: (int) $row->id,
            uuid: (string) $row->external_id,
            documentTypeId: (string) $row->document_type_id,
            documentType: (string) $row->document_type,
            series: (string) $row->series,
            number: (int) $row->number,
            issueDate: $row->date_of_issue ? CarbonImmutable::parse($row->date_of_issue, 'America/Lima') : null,
            customerName: $this->clean($snapshot['name'] ?? null)
                ?: $this->clean($row->current_customer_name ?? null),
            currentEmail: $this->clean($row->current_email ?? null),
            snapshotEmail: $this->clean($snapshot['email'] ?? null),
            total: $row->total !== null ? (string) $row->total : null,
            currencyTypeId: $this->clean($row->currency_type_id ?? null),
            stateTypeId: (string) $row->state_type_id,
        );
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== '' ? $value : null;
    }
}
