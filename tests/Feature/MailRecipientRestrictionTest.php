<?php

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

test('all mail is sent only to the testing support address', function () {
    config([
        'mail.default' => 'array',
        'mail.mailers.array' => ['transport' => 'array'],
    ]);

    Mail::purge('array');

    Mail::raw('Documento adjunto', function (Message $message) {
        $message
            ->to('cliente@empresa.com')
            ->cc('copia@empresa.com')
            ->bcc('oculto@empresa.com')
            ->subject('Documentos electrónicos');
    });

    $message = Mail::getSymfonyTransport()
        ->messages()
        ->sole()
        ->getOriginalMessage();

    expect(array_map(
        fn ($address) => $address->getAddress(),
        $message->getTo(),
    ))->toBe(['soporte@miasoftware.net'])
        ->and($message->getCc())->toBe([])
        ->and($message->getBcc())->toBe([]);
});
