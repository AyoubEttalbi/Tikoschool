/**
 * Laravel Echo, created on demand.
 *
 * This module used to construct an Echo instance at import time, and bootstrap.js imports
 * it unconditionally — so laravel-echo + pusher-js were pulled into the eager entry chunk
 * and a websocket was opened on every page, including /login where nobody is authenticated.
 *
 * `getEcho()` builds the client the first time something actually needs realtime, and
 * dynamically imports the libraries so they land in their own chunk.
 */

let echoPromise = null;

export async function getEcho() {
    if (echoPromise) {
        return echoPromise;
    }

    echoPromise = (async () => {
        const [{ default: Echo }, { default: Pusher }] = await Promise.all([
            import("laravel-echo"),
            import("pusher-js"),
        ]);

        window.Pusher = Pusher;

        window.Echo = new Echo({
            broadcaster: "reverb",
            key: import.meta.env.VITE_REVERB_APP_KEY,
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: import.meta.env.VITE_REVERB_PORT ?? 80,
            wssPort: import.meta.env.VITE_REVERB_PORT ?? 443,
            forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? "https") === "https",
            enabledTransports: ["ws", "wss"],
        });

        return window.Echo;
    })();

    return echoPromise;
}

/** Already-connected instance, or null. For cleanup paths that must not force a connect. */
export function currentEcho() {
    return window.Echo ?? null;
}
