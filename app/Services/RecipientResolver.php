<?php

namespace App\Services;

use App\Data\RemoteDocumentData;
use App\Exceptions\DocumentProcessingException;

class RecipientResolver
{
    /** @return array{address: string, source: string} */
    public function resolve(RemoteDocumentData $document): array
    {
        if (! config('facturanet.mail.allow_real_recipients', false)) {
            return [
                'address' => (string) config('facturanet.mail.safety_recipient'),
                'source' => 'safety_override',
            ];
        }

        foreach ([
            'current' => $document->currentEmail,
            'snapshot' => $document->snapshotEmail,
        ] as $source => $email) {
            if ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return ['address' => $email, 'source' => $source];
            }
        }

        throw new DocumentProcessingException('El documento no tiene un correo válido.');
    }
}
