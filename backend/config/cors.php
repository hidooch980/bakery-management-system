<?php

/*
 * Who may call this API from a browser.
 *
 * Laravel's default is `*`, which is what this shop was serving. With
 * bearer-token authentication that is not directly exploitable — a hostile
 * page cannot read a response it has no token for, and cookies are not
 * sent cross-origin while supports_credentials stays false — but nothing
 * needs it either. The phones are not browsers and send no Origin at all;
 * the panel is same-origin. So the wildcard bought nothing and advertised
 * an opening.
 *
 * A browser tool that genuinely needs to call this from elsewhere gets its
 * origin added here by name.
 */
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://37.32.21.125',
        'http://37.32.21.125:8000',
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 3600,

    // Cookies stay same-origin. The panel's session must never be usable
    // from another site, and turning this on is what would make the
    // origins above into that.
    'supports_credentials' => false,

];
