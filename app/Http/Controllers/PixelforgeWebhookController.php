<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\HandlePixelforgeWebhook;
use App\Actions\VerifyPixelforgeSignature;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final readonly class PixelforgeWebhookController
{
    public function __invoke(Request $request, VerifyPixelforgeSignature $verify, HandlePixelforgeWebhook $handle): Response
    {
        $payload = $request->getContent();

        $isValid = $verify->handle(
            $payload,
            (string) $request->header('Pixelforge-Signature', ''),
            config()->string('services.pixelforge.webhook_secret'),
            config()->integer('services.pixelforge.webhook_tolerance_seconds'),
        );

        abort_unless($isValid, 400, 'Invalid Pixelforge signature.');

        $event = json_decode($payload, true);

        abort_unless(is_array($event), 400, 'Invalid JSON.');

        $handle->handle($event);

        return response()->noContent();
    }
}
