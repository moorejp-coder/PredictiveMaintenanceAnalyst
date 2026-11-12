<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing
|--------------------------------------------------------------------------
|
| Laravel ships a framework default that allows every origin, every method and
| every header on api/*. This file replaces it. PRISM's own pages call the API
| from the same origin, which CORS does not govern, so the allowlist only
| matters for pages on other sites. Only PRISM's own site is listed.
|
| An origin is scheme and host (and port). It never holds a path, so the
| /login page is covered by the host entry below.
|
| Credentials stay off. A browser refuses credentialed requests when the
| allowed origin is '*', and no PRISM page needs to send cookies from another
| site. The session cookie is SameSite=Lax as well.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE'],

    'allowed_origins' => [env('CORS_ALLOWED_ORIGIN', 'https://prismcua.duckdns.org')],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Content-Type', 'X-CSRF-TOKEN', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
