<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use WebSocket\Client;

final class WebsocketTest extends TestCase
{
    public function testCanBeCreatedFromValidEmail()
    {
        $server = new Process(['php', '-f', 'websocket.php', '--', '0.0.0.0', '40000']);

        $server->start();

        sleep(1);

        $this->assertEquals('{"message":"Server started on 0.0.0.0:40000."}', trim($server->getOutput()));

        $client = new Client('ws://0.0.0.0:40000/');

        $client->send(json_encode([
            'user' => 'user',
            'app' => 'app',
            'session' => md5('user|app'),
        ]));

        $response = $client->receive();

        $this->assertStringContainsString('Welcome to the PHP WebSocket Server', $response);

        $server->stop();
    }
}

