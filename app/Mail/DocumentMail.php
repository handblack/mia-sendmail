<?php

namespace App\Mail;

use App\Data\DocumentAttachments;
use App\Data\RemoteDocumentData;
use App\Models\Service;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Service $service,
        public readonly RemoteDocumentData $remoteDocument,
        public readonly DocumentAttachments $documentAttachments,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                (string) config('mail.from.address'),
                (string) config('facturanet.mail.sender_name')
            ),
            subject: $this->service->mail_subject
                ?: (string) config('facturanet.mail.default_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.document');
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $identifier = $this->remoteDocument->identifier();
        $attachments = [
            Attachment::fromPath($this->documentAttachments->pdf)
                ->as("{$identifier}.pdf")
                ->withMime('application/pdf'),
            Attachment::fromPath($this->documentAttachments->xml)
                ->as("{$identifier}.xml")
                ->withMime('application/xml'),
        ];

        if ($this->documentAttachments->cdr) {
            $attachments[] = Attachment::fromPath($this->documentAttachments->cdr)
                ->as("R-{$identifier}.zip")
                ->withMime('application/zip');
        }

        return $attachments;
    }
}
