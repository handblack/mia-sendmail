<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $service->mail_subject ?: config('facturanet.mail.default_subject') }}</title>
</head>
<body style="margin:0;padding:0;background:#ffffff;color:#292929;font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.55;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">
    <tr>
        <td style="padding:28px 30px;">
            <p style="margin:0 0 20px;">
                Estimad@:
                <strong>{{ $remoteDocument->customerName ?: 'Cliente' }}</strong>,
                informamos que su comprobante electrónico ha sido emitido exitosamente.
            </p>

            <p style="margin:0 0 20px;">Los datos de su comprobante electrónico son:</p>

            <table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin-left:82px;">
                <tr>
                    <td style="padding:2px 12px 2px 0;"><strong>Razón social:</strong></td>
                    <td style="padding:2px 0;">{{ $service->company_name ?: $service->name }}</td>
                </tr>
                @if($service->phone)
                    <tr>
                        <td style="padding:2px 12px 2px 0;"><strong>Teléfono:</strong></td>
                        <td style="padding:2px 0;">{{ $service->phone }}</td>
                    </tr>
                @endif
                @if($remoteDocument->issueDate)
                    <tr>
                        <td style="padding:2px 12px 2px 0;"><strong>Fecha de emisión:</strong></td>
                        <td style="padding:2px 0;">{{ $remoteDocument->issueDate->format('d/m/Y') }}</td>
                    </tr>
                @endif
                <tr>
                    <td style="padding:2px 12px 2px 0;"><strong>Nro. de comprobante:</strong></td>
                    <td style="padding:2px 0;">{{ $remoteDocument->identifier() }}</td>
                </tr>
                @if($remoteDocument->total !== null)
                    <tr>
                        <td style="padding:2px 12px 2px 0;"><strong>Total:</strong></td>
                        <td style="padding:2px 0;">
                            {{ number_format((float) $remoteDocument->total, 2, '.', ',') }}
                            {{ $remoteDocument->currencyTypeId }}
                        </td>
                    </tr>
                @endif
            </table>

            <p style="margin:26px 0 0;color:#555;">
                Se adjuntan el PDF y XML del comprobante{{ $documentAttachments->cdr ? ', junto con su CDR' : '' }}.
            </p>
        </td>
    </tr>
</table>
</body>
</html>
