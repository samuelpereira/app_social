import { router, usePage } from '@inertiajs/react';
import {
    getWalletUniqueIdentifier,
    useDAppKit,
    type UiWallet,
} from '@mysten/dapp-kit-react';
import { getWalletFeature } from '@wallet-standard/ui';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { challenge, store } from '@/routes/login/slush';

const slushExtensionId = 'com.mystenlabs.suiwallet';

function findSlushWallet(wallets: readonly UiWallet[]): UiWallet | null {
    return (
        wallets.find(
            (wallet) => getWalletUniqueIdentifier(wallet) === slushExtensionId,
        ) ??
        wallets.find((wallet) => wallet.name === 'Slush') ??
        null
    );
}

async function revokeSlushAccounts(wallet: UiWallet): Promise<void> {
    if (!wallet.features.includes('standard:disconnect')) {
        return;
    }

    try {
        const feature = getWalletFeature(wallet, 'standard:disconnect');

        if (
            typeof feature === 'object' &&
            feature !== null &&
            'disconnect' in feature &&
            typeof feature.disconnect === 'function'
        ) {
            await feature.disconnect();
        }
    } catch {
        // The wallet may have no permission to revoke yet.
    }
}

export function SlushSignInButton() {
    const dAppKit = useDAppKit();
    const signatureError = usePage().props.errors.signature;
    const [processing, setProcessing] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);

    const signIn = (): void => {
        const wallet = findSlushWallet(dAppKit.stores.$wallets.get());

        if (!wallet) {
            setLocalError('Slush is not available in this browser.');

            return;
        }

        setProcessing(true);
        setLocalError(null);

        void revokeSlushAccounts(wallet)
            .then(() => dAppKit.connectWallet({ wallet }))
            .then(async ({ accounts }) => {
                if (accounts.length !== 1) {
                    throw new Error(
                        accounts.length === 0
                            ? 'Slush did not share an account.'
                            : 'Approve one account in the Slush window.',
                    );
                }

                const account = accounts[0];

                const response = await fetch(challenge.url(), {
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                });

                if (!response.ok) {
                    throw new Error('Slush sign-in could not be started.');
                }

                const body = (await response.json()) as { message?: unknown };

                if (typeof body.message !== 'string' || body.message === '') {
                    throw new Error('Slush sign-in could not be started.');
                }

                const signed = await dAppKit.signPersonalMessage({
                    account,
                    message: new TextEncoder().encode(body.message),
                });

                router.post(
                    store.url(),
                    { signature: signed.signature },
                    {
                        onFinish: () => setProcessing(false),
                    },
                );
            })
            .catch((error: unknown) => {
                setLocalError(
                    error instanceof Error
                        ? error.message
                        : 'Slush sign-in could not be completed.',
                );
                setProcessing(false);
            });
    };

    return (
        <div className="grid gap-2">
            <Button
                type="button"
                variant="outline"
                className="w-full"
                disabled={processing}
                onClick={signIn}
                data-test="slush-sign-in-button"
            >
                {processing && <Spinner />}
                Sign in with Slush
            </Button>
            <InputError message={localError ?? signatureError} />
        </div>
    );
}
