<?php

declare(strict_types=1);

// A stand-in for devops/images/secret-broker: answers each "GET <name>"
// with the raw answer configured for that name, "ERR unknown" otherwise.
// Usage: php fake-secret-broker.php <socket> <json name => raw answer>

[, $socket, $answers] = $argv;
$map = json_decode($answers, true, 512, JSON_THROW_ON_ERROR);
@unlink($socket);
$server = stream_socket_server('unix://' . $socket, $errno, $errstr);
if ($server === false) {
    fwrite(STDERR, $errstr . "\n");
    exit(1);
}
touch($socket . '.ready');
while (($client = @stream_socket_accept($server, 10)) !== false) {
    $line = (string)fgets($client, 256);
    $name = str_starts_with($line, 'GET ') ? trim(substr($line, 4)) : '';
    fwrite($client, $map[$name] ?? "ERR unknown\n");
    fclose($client);
}
