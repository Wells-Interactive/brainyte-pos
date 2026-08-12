/* Firebase config is intentionally deployed separately as /assets/js/firebase-config.js. */
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.14.1/firebase-messaging-compat.js');
importScripts('/assets/js/firebase-config.js');
if (self.BRAINYTE_FIREBASE_CONFIG && self.BRAINYTE_FIREBASE_CONFIG.apiKey !== 'REPLACE_ME') {
    firebase.initializeApp(self.BRAINYTE_FIREBASE_CONFIG);
    firebase.messaging();
}
