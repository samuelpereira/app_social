<?php

namespace App\Services\Slush;

use App\Exceptions\InvalidSlushSignatureException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class SuiZkLoginVerifier
{
    private const QUERY = <<<'GRAPHQL'
        query verifyZkLoginSignature($bytes: Base64!, $signature: Base64!, $intentScope: ZkLoginIntentScope!, $author: SuiAddress!) {
            verifyZkLoginSignature(bytes: $bytes, signature: $signature, intentScope: $intentScope, author: $author) {
                success
            }
        }
        GRAPHQL;

    /**
     * Confirm a Slush zkLogin personal-message signature and return its address.
     *
     * The Sui network checks the proof. A rejected proof never yields an address.
     */
    public function address(string $message, string $serializedSignature): string
    {
        $addresses = ZkLoginSignature::addresses($serializedSignature);
        $reachable = false;

        foreach ($this->networks() as $network) {
            foreach ($addresses as $address) {
                $verified = $this->verify($network, $message, $serializedSignature, $address);

                if ($verified === null) {
                    continue;
                }

                $reachable = true;

                if ($verified) {
                    return $address;
                }
            }
        }

        throw new InvalidSlushSignatureException(
            $reachable
                ? 'Slush sign-in could not be verified.'
                : 'Slush sign-in is unavailable. Try again.',
        );
    }

    /**
     * @return list<string>
     */
    private function networks(): array
    {
        $configured = config('services.enoki.network');
        $configured = is_string($configured) && isset($this->endpoints()[$configured])
            ? $configured
            : 'testnet';

        $networks = [$configured];

        foreach (['mainnet', 'testnet'] as $network) {
            if (! in_array($network, $networks, true)) {
                $networks[] = $network;
            }
        }

        return $networks;
    }

    /**
     * @return array<string, string>
     */
    private function endpoints(): array
    {
        return [
            'testnet' => 'https://graphql.testnet.sui.io/graphql',
            'mainnet' => 'https://graphql.mainnet.sui.io/graphql',
            'devnet' => 'https://graphql.devnet.sui.io/graphql',
        ];
    }

    private function verify(string $network, string $message, string $serializedSignature, string $address): ?bool
    {
        try {
            $response = Http::acceptJson()
                ->asJson()
                ->connectTimeout(3)
                ->timeout(10)
                ->retry([200, 500], 0, function (Throwable $exception): bool {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }

                    return $exception instanceof RequestException
                        && ($exception->response->serverError() || $exception->response->status() === 429);
                })
                ->post($this->endpoints()[$network], [
                    'query' => self::QUERY,
                    'variables' => [
                        'bytes' => base64_encode($message),
                        'signature' => $serializedSignature,
                        'intentScope' => 'PERSONAL_MESSAGE',
                        'author' => $address,
                    ],
                ]);
        } catch (ConnectionException) {
            return null;
        } catch (RequestException $exception) {
            if ($exception->response->serverError() || $exception->response->status() === 429) {
                return null;
            }

            return false;
        }

        if ($response->serverError() || $response->status() === 429) {
            return null;
        }

        return $response->json('data.verifyZkLoginSignature.success') === true;
    }
}
