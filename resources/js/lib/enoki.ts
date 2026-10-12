import { createDAppKit } from '@mysten/dapp-kit-react';
import { registerEnokiWallets } from '@mysten/enoki';
import { SuiGrpcClient } from '@mysten/sui/grpc';

const fullnodeUrls = {
    testnet: 'https://fullnode.testnet.sui.io:443',
    mainnet: 'https://fullnode.mainnet.sui.io:443',
    devnet: 'https://fullnode.devnet.sui.io:443',
} as const;

type SuiNetwork = keyof typeof fullnodeUrls;

function configuredNetwork(): SuiNetwork {
    const value = import.meta.env.VITE_ENOKI_NETWORK;

    if (value === 'mainnet' || value === 'devnet' || value === 'testnet') {
        return value;
    }

    return 'testnet';
}

const apiKey = import.meta.env.VITE_ENOKI_API_KEY;
const googleClientId = import.meta.env.VITE_GOOGLE_CLIENT_ID;

export const enokiConfigured = Boolean(apiKey && googleClientId);
export const enokiApiKey = apiKey ?? '';
export const enokiGoogleClientId = googleClientId ?? '';
export const enokiNetwork = configuredNetwork();

function createEnokiDAppKit() {
    const network = configuredNetwork();
    const client = new SuiGrpcClient({
        baseUrl: fullnodeUrls[network],
        network,
    });

    const dAppKit = createDAppKit({
        networks: [network],
        defaultNetwork: network,
        autoConnect: false,
        slushWalletConfig: {
            appName: import.meta.env.VITE_APP_NAME || 'Laravel',
        },
        createClient: () => client,
    });

    if (apiKey && googleClientId) {
        registerEnokiWallets({
            apiKey,
            clients: [client],
            getCurrentNetwork: () => dAppKit.stores.$currentNetwork.get(),
            providers: {
                google: {
                    clientId: googleClientId,
                },
            },
        });
    }

    return dAppKit;
}

export const enokiDAppKit = createEnokiDAppKit();
