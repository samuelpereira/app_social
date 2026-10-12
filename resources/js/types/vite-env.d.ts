/// <reference types="vite/client" />

interface ImportMetaEnv {
    readonly VITE_APP_NAME: string;
    readonly VITE_ENOKI_API_KEY?: string;
    readonly VITE_ENOKI_NETWORK?: string;
    readonly VITE_GOOGLE_CLIENT_ID?: string;
}
