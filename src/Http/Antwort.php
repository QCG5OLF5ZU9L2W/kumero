<?php
// SPDX-License-Identifier: AGPL-3.0-or-later
declare(strict_types=1);

namespace Kumero\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

final class Antwort
{
    public static function json(mixed $daten, int $status = 200, array $kopf = []): JsonResponse
    {
        $a = new JsonResponse($daten, $status, $kopf);
        $a->setEncodingOptions(JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        return $a;
    }

    public static function leer(int $status = 204): JsonResponse
    {
        $a = new JsonResponse(null, $status);
        $a->setContent('');
        return $a;
    }
}
