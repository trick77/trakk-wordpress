<?php

/**
 * In Trakk's place: keeps every batch, answers as told. /control?status=…&sleep=…&retry=…&other=…, /reset, /records.
 */

const MODE = '/tmp/mode.json';
const RECORDS = '/tmp/records';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

switch ($path) {
    case '/control':
        file_put_contents(MODE, json_encode($_GET));
        echo '{}';
        break;
    case '/reset':
        @unlink(MODE);
        @unlink(RECORDS);
        echo '{}';
        break;
    case '/records':
        echo '[' . implode(',', is_file(RECORDS) ? file(RECORDS, FILE_IGNORE_NEW_LINES) : []) . ']';
        break;
    case '/trakk.js':
        header('Content-Type: text/javascript');
        break;
    case '/api/feed':
        $mode = is_file(MODE) ? json_decode(file_get_contents(MODE), true) : [];
        $status = (int) ($mode['status'] ?? 204);
        $body = file_get_contents('php://input');
        if (!empty($mode['other']) && isset(json_decode($body, true)['site'])) {
            $status = 400; // the key is another site's
        }
        $record = [
            'status' => $status,
            'auth' => $_SERVER['HTTP_AUTHORIZATION'] ?? '',
            'ua' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'type' => $_SERVER['CONTENT_TYPE'] ?? '',
            'bytes' => strlen($body),
            'body' => json_decode($body, true),
        ];
        file_put_contents(RECORDS, json_encode($record) . "\n", FILE_APPEND | LOCK_EX);
        if (!empty($mode['sleep'])) {
            sleep((int) $mode['sleep']);
        }
        if (!empty($mode['retry'])) {
            header('Retry-After: ' . $mode['retry']);
        }
        http_response_code($status);
        if ($status !== 204) {
            echo json_encode(['detail' => 'the stub says so']);
        }
        break;
    default:
        http_response_code(404);
}
