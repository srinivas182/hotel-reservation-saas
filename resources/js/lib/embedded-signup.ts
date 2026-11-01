/**
 * WhatsApp Embedded Signup (v4) with Meta's JavaScript SDK.
 *
 * The flow returns two things separately: a code (in the FB.login
 * callback) and the WhatsApp account and number IDs (in a message from the
 * Meta popup). Both are needed, so this waits for both.
 */
type SignupResult = { code: string; wabaId: string; phoneNumberId: string };

type FacebookSdk = {
    init: (options: Record<string, unknown>) => void;
    login: (
        callback: (response: {
            authResponse?: { code?: string } | null;
        }) => void,
        options: Record<string, unknown>,
    ) => void;
};

declare global {
    interface Window {
        FB?: FacebookSdk;
        fbAsyncInit?: () => void;
    }
}

let sdkPromise: Promise<FacebookSdk> | null = null;

function loadSdk(appId: string, version: string): Promise<FacebookSdk> {
    if (sdkPromise) {
        return sdkPromise;
    }

    sdkPromise = new Promise((resolve, reject) => {
        window.fbAsyncInit = () => {
            window.FB?.init({
                appId,
                autoLogAppEvents: true,
                xfbml: false,
                version,
            });

            if (window.FB) {
                resolve(window.FB);
            } else {
                reject(new Error('Meta SDK unavailable'));
            }
        };

        const script = document.createElement('script');
        script.src = 'https://connect.facebook.net/en_US/sdk.js';
        script.async = true;
        script.defer = true;
        script.crossOrigin = 'anonymous';
        script.onerror = () =>
            reject(new Error('Meta SDK could not be loaded'));
        document.body.appendChild(script);
    });

    return sdkPromise;
}

export function launchEmbeddedSignup(options: {
    appId: string;
    configId: string;
    version: string;
    coexistence: boolean;
}): Promise<SignupResult> {
    return loadSdk(options.appId, options.version).then(
        (FB) =>
            new Promise<SignupResult>((resolve, reject) => {
                let code: string | null = null;
                let ids: { wabaId: string; phoneNumberId: string } | null =
                    null;

                const finish = () => {
                    if (code && ids) {
                        window.removeEventListener('message', onMessage);
                        resolve({ code, ...ids });
                    }
                };

                const onMessage = (event: MessageEvent) => {
                    if (!event.origin.endsWith('facebook.com')) {
                        return;
                    }

                    try {
                        const data =
                            typeof event.data === 'string'
                                ? JSON.parse(event.data)
                                : event.data;

                        if (data?.type !== 'WA_EMBEDDED_SIGNUP') {
                            return;
                        }

                        if (
                            typeof data.event === 'string' &&
                            data.event.startsWith('FINISH') &&
                            data.data?.phone_number_id &&
                            data.data?.waba_id
                        ) {
                            ids = {
                                wabaId: String(data.data.waba_id),
                                phoneNumberId: String(
                                    data.data.phone_number_id,
                                ),
                            };
                            finish();
                        } else if (
                            data.event === 'CANCEL' ||
                            data.event === 'ERROR'
                        ) {
                            window.removeEventListener('message', onMessage);
                            reject(
                                new Error(
                                    data.data?.error_message ?? 'cancelled',
                                ),
                            );
                        }
                    } catch {
                        // Messages that are not JSON are not from Embedded Signup.
                    }
                };

                window.addEventListener('message', onMessage);

                FB.login(
                    (response) => {
                        code = response.authResponse?.code ?? null;

                        if (!code) {
                            window.removeEventListener('message', onMessage);
                            reject(new Error('cancelled'));

                            return;
                        }

                        finish();
                    },
                    {
                        config_id: options.configId,
                        response_type: 'code',
                        override_default_response_type: true,
                        extras: {
                            setup: {},
                            sessionInfoVersion: '3',
                            ...(options.coexistence
                                ? {
                                      featureType:
                                          'whatsapp_business_app_onboarding',
                                  }
                                : {}),
                        },
                    },
                );
            }),
    );
}
