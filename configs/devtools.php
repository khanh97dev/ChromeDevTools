<?php
// config/devtools.php

return [
    /*
     |--------------------------------------------------------------------------
     | Chrome DevTools WebSocket URL
     |--------------------------------------------------------------------------
     |
     | The WebSocket URL used to connect to the running instance of the headless
     | Chrome/Chromium browser.
     | Default is the standard address for local connections.
     |
     */
    'websocket_url' => env('CHROME_DEVTOOLS_URL', 'ws://127.0.0.1:9222/devtools/browser'),

    /*
     |--------------------------------------------------------------------------
     | WebSocket Timeout
     |--------------------------------------------------------------------------
     |
     | The timeout (in seconds) for the WebSocket client connection.
     |
     */
    'timeout' => 15,
];
