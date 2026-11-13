<?php

/*
 * Router for `php -S`, standing in for a token issuer's status endpoint in
 * TokenStatusClientHttpTest. Each path answers one way the real endpoint or
 * something in front of it could.
 */

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

switch ($path) {
    case '/status/inactive':
        header('Content-Type: application/json');
        echo '{"active":false}';
        break;

    case '/status/inflating':
        // A few hundred bytes on the wire that decode to about 100 KB.
        header('Content-Type: application/json');
        header('Content-Encoding: gzip');
        echo gzencode('{"active":false,"pad":"'.str_repeat('x', 100000).'"}');
        break;

    case '/status/large':
        $body = '{"active":false,"pad":"'.str_repeat('x', 5000).'"}';
        header('Content-Type: application/json');
        header('Content-Length: '.strlen($body));
        echo $body;
        break;

    case '/status/stall':
        sleep(5);
        header('Content-Type: application/json');
        echo '{"active":false}';
        break;

    case '/status/redirect':
        header('Location: /status/inactive', true, 302);
        break;

    default:
        http_response_code(404);
}
