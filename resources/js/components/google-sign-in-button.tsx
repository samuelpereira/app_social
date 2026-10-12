import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import {
    enokiApiKey,
    enokiConfigured,
    enokiGoogleClientId,
    enokiNetwork,
} from '@/lib/enoki';
import {
    openGoogleSignInWindow,
    requestGoogleZkLoginJwt,
} from '@/lib/google-zk-login';
import { store } from '@/routes/login/google';

export function GoogleSignInButton() {
    if (!enokiConfigured) {
        return null;
    }

    return <GoogleSignIn />;
}

function GoogleSignIn() {
    const jwtError = usePage().props.errors.jwt;
    const [processing, setProcessing] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);

    const signIn = (): void => {
        // The popup has to open inside the click. Enoki's connectWallet waits
        // on storage first, and the browser then blocks the window.
        const popup = openGoogleSignInWindow();

        if (!popup) {
            setLocalError('The browser blocked the Google sign-in window.');

            return;
        }

        setProcessing(true);
        setLocalError(null);

        void requestGoogleZkLoginJwt(popup, {
            apiKey: enokiApiKey,
            clientId: enokiGoogleClientId,
            network: enokiNetwork,
        })
            .then((jwt) => {
                router.post(
                    store.url(),
                    { jwt },
                    {
                        onFinish: () => setProcessing(false),
                    },
                );
            })
            .catch((error: unknown) => {
                setLocalError(
                    error instanceof Error
                        ? error.message
                        : 'Google sign-in could not be completed.',
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
                data-test="google-sign-in-button"
            >
                {processing && <Spinner />}
                Sign in with Google
            </Button>
            <InputError message={localError ?? jwtError} />
        </div>
    );
}
