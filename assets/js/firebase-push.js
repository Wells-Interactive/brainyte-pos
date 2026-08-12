/** Registers this signed-in browser with Firebase Cloud Messaging when configured. */
export async function registerFirebaseWebPush() {
    if (!window.BRAINYTE_FIREBASE_CONFIG) {
        try { await import('/assets/js/firebase-config.js'); } catch (_) { return; }
    }
    const config = window.BRAINYTE_FIREBASE_CONFIG;
    if (!config || !config.apiKey || config.apiKey === 'REPLACE_ME' || !('Notification' in window)) return;
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return;
    const [{ initializeApp }, { getMessaging, getToken }] = await Promise.all([
        import('https://www.gstatic.com/firebasejs/10.14.1/firebase-app.js'),
        import('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging.js'),
    ]);
    const registration = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
    const messaging = getMessaging(initializeApp(config));
    const token = await getToken(messaging, { vapidKey: config.vapidKey, serviceWorkerRegistration: registration });
    if (!token) return;
    await fetch('/API/v1/operations/index.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'push.subscribe', platform: 'web', token }),
    });
}
