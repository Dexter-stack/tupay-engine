<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidWebhookSignatureException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    /**
     * @throws InvalidWebhookSignatureException
     */
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Tupay-Signature');

        if (! $signature) {
            return response()->json(['message' => 'Missing webhook signature.'], Response::HTTP_UNAUTHORIZED);
        }

        $secret = config('services.tupay.webhook_secret');
        $rawBody = $request->getContent();
        $expected = hash_hmac('sha256', $rawBody, $secret);

        if (! hash_equals($expected, $signature)) {
            return response()->json(['message' => 'Invalid webhook signature.'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
