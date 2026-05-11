// Firebase Messaging Service Worker
// Xử lý push notification khi trình duyệt đóng hoặc ở background

importScripts('https://www.gstatic.com/firebasejs/12.12.1/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/12.12.1/firebase-messaging-compat.js');

firebase.initializeApp({
    apiKey:            'AIzaSyDZQjQNuNmhiumNFM43GgbMUxIT5SXMwvU',
    authDomain:        'ittriet.firebaseapp.com',
    projectId:         'ittriet',
    storageBucket:     'ittriet.firebasestorage.app',
    messagingSenderId: '811008242226',
    appId:             '1:811008242226:web:e47169f406189fa585c22b',
});

const messaging = firebase.messaging();

// Background message handler
messaging.onBackgroundMessage(function (payload) {
    const title = payload.notification?.title || 'Thông báo mới';
    const body  = payload.notification?.body  || '';

    self.registration.showNotification(title, {
        body:    body,
        icon:    '/favicon.ico',
        badge:   '/favicon.ico',
        tag:     'order-notification',
        renotify: true,
        data:    payload.data || {},
    });
});

// Click vào notification → mở trang admin
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = event.notification.data?.url || '/admin';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (clientList) {
            for (const client of clientList) {
                if (client.url.includes('/admin') && 'focus' in client) {
                    return client.focus();
                }
            }
            return clients.openWindow(url);
        })
    );
});
