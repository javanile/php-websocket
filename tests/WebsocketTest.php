<?php

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class WebsocketTest extends TestCase
{
    public function testCanBeCreatedFromValidEmail()
    {
        $process = new Process(['php', '-f', 'websocket.php', '--', '0.0.0.0', '40000']);

        $process->start();

        $this->assertEquals('Cavallo', $process->getOutput());

        $process->stop();
    }
}

