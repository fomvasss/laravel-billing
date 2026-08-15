<?php

declare(strict_types=1);

namespace Fomvasss\Billing\Support;

use Illuminate\Http\Request;

/**
 * The webhook body as the gateway actually sent it, not as PHP's form parser mangled it.
 *
 * WayForPay POSTs raw JSON under Content-Type: application/x-www-form-urlencoded — PHP then
 * parses the whole JSON string into a single garbled form KEY, so $request->all() is useless
 * for it (confirmed against dropshop's production WayForPay handler, which json_decode()s the
 * first array key back). Sniffing the raw body for JSON handles that regardless of the declared
 * content type; everything else falls back to the parsed form body.
 *
 * Query-string params (webhookUrlParams routing hints) are deliberately NOT merged in — unlike
 * $request->all()/input(), which would poison payload-wide signature schemes like Hutko's and
 * pollute the stored payload.
 */
final class WebhookPayload
{
    public static function fromRequest(Request $request): array
    {
        $content = ltrim($request->getContent());

        if (str_starts_with($content, '{') || str_starts_with($content, '[')) {
            $decoded = json_decode($content, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $request->post();
    }
}
