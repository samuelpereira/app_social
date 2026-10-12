import { EnokiClient, type EnokiNetwork } from '@mysten/enoki';
import { Ed25519Keypair } from '@mysten/sui/keypairs/ed25519';

import { login } from '@/routes';

const popupFeatures = 'popup,width=480,height=720';

function googleRedirectUri(): string {
    return new URL(login.url(), window.location.origin).href;
}

export function openGoogleSignInWindow(): Window | null {
    return window.open('about:blank', 'google-zklogin', popupFeatures);
}

export async function requestGoogleZkLoginJwt(
    popup: Window,
    options: {
        apiKey: string;
        clientId: string;
        network: EnokiNetwork;
    },
): Promise<string> {
    const redirectUri = googleRedirectUri();
    const { nonce } = await new EnokiClient({
        apiKey: options.apiKey,
    }).createZkLoginNonce({
        network: options.network,
        ephemeralPublicKey: new Ed25519Keypair().getPublicKey(),
    });

    if (popup.closed) {
        throw new Error('Popup closed');
    }

    const params = new URLSearchParams({
        client_id: options.clientId,
        redirect_uri: redirectUri,
        response_type: 'id_token',
        scope: 'openid email profile',
        nonce,
    });

    popup.location.href = `https://accounts.google.com/o/oauth2/v2/auth?${params}`;

    return waitForIdToken(popup, redirectUri);
}

function waitForIdToken(popup: Window, redirectUri: string): Promise<string> {
    return new Promise((resolve, reject) => {
        const timer = window.setInterval(() => {
            if (popup.closed) {
                window.clearInterval(timer);
                reject(
                    new Error(
                        `Google sign-in was closed before it finished. Allow this redirect URI in Google Cloud: ${redirectUri}`,
                    ),
                );

                return;
            }

            let hash = '';

            try {
                hash = popup.location.hash;
            } catch {
                return;
            }

            if (!hash) {
                return;
            }

            const params = new URLSearchParams(hash.slice(1));
            const oauthError =
                params.get('error_description') ?? params.get('error');

            if (oauthError) {
                window.clearInterval(timer);
                popup.close();
                reject(new Error(oauthError));

                return;
            }

            const jwt = params.get('id_token');

            if (!jwt) {
                return;
            }

            window.clearInterval(timer);
            popup.close();
            resolve(jwt);
        }, 50);
    });
}
