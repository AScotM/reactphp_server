<?php
require __DIR__ . '/vendor/autoload.php';

use React\Http\Message\Response;
use React\EventLoop\Loop;
use Psr\Http\Message\ServerRequestInterface;

$TCP_STATES = [
    '01' => 'ESTABLISHED',
    '02' => 'SYN_SENT',
    '03' => 'SYN_RECV',
    '04' => 'FIN_WAIT1',
    '05' => 'FIN_WAIT2',
    '06' => 'TIME_WAIT',
    '07' => 'CLOSE',
    '08' => 'CLOSE_WAIT',
    '09' => 'LAST_ACK',
    '0A' => 'LISTEN',
    '0B' => 'CLOSING'
];

// Cache TCP states to avoid blocking reads on every request
$cachedStates = ['timestamp' => time(), 'tcp_states' => []];

// Background update every 2 seconds
Loop::addPeriodicTimer(2, function () use (&$cachedStates, $TCP_STATES) {
    $cachedStates = [
        'timestamp' => time(),
        'tcp_states' => parse_tcp_states($TCP_STATES)
    ];
});

function parse_tcp_states($TCP_STATES) {
    $stateCount = array_fill_keys(array_values($TCP_STATES), 0);
    
    try {
        $lines = @file('/proc/net/tcp');
        if ($lines === false) throw new Exception("Cannot read /proc/net/tcp");
        
        array_shift($lines); // Skip header
        
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            $stateCode = $parts[3] ?? '';
            $stateName = $TCP_STATES[$stateCode] ?? 'UNKNOWN';
            $stateCount[$stateName]++;
        }
    } catch (Exception $e) {
        error_log("TCP State Error: " . $e->getMessage());
    }
    
    return $stateCount;
}

$http = new React\Http\HttpServer(function (ServerRequestInterface $request) use (&$cachedStates) {
    $path = $request->getUri()->getPath();
    
    if ($path === '/tcpstates') {
        return Response::json($cachedStates);
    }
    
    return new Response(404, ['Content-Type' => 'text/plain'], "Not Found");
});

$socket = new React\Socket\SocketServer('0.0.0.0:3333');
$http->listen($socket);

echo "Server running on http://0.0.0.0:3333\n";
echo "Endpoint: /tcpstates\n";

Loop::run();
