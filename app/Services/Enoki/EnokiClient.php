<?php

namespace App\Services\Enoki;

use App\Exceptions\EnokiUnavailableException;
use App\Exceptions\InvalidZkLoginTokenException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class EnokiClient
{
    /**
     * Ask Enoki for the Sui address bound to a zkLogin JWT.
     *
     * Enoki checks the token signature. A rejected token never yields an address.
     */
    public function addressForJwt(string $jwt): string
    {
        $apiKey = config('services.enoki.key');

        if (! is_string($apiKey) || $apiKey === '') {
            throw new EnokiUnavailableException('Enoki is not configured.');
        }

        try {
            $response = Http::baseUrl('https://api.enoki.mystenlabs.com/v1')
                ->withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->retry([200, 500], 0, function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && ($exception->response->serverError() || $exception->response->status() === 429);
                })
                ->withHeaders([
                    'zklogin-jwt' => $jwt,
                ])
                ->get('zklogin');
        } catch (ConnectionException $exception) {
            throw new EnokiUnavailableException('Enoki could not be reached.', previous: $exception);
        } catch (RequestException $exception) {
            $status = $exception->response->status();

            if ($status === 429 || $status >= 500) {
                throw new EnokiUnavailableException('Enoki could not be reached.', previous: $exception);
            }

            throw new InvalidZkLoginTokenException('Enoki rejected the token.', previous: $exception);
        }

        $address = $response->json('data.address');

        if (! is_string($address) || preg_match('/^0x[a-fA-F0-9]{64}$/', $address) !== 1) {
            throw new InvalidZkLoginTokenException('Enoki did not return a Sui address.');
        }

        return strtolower($address);
    }
}
