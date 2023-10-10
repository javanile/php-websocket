<?php

define('WEBSOCKET_HOST', $argv[1] ?? '0.0.0.0');
define('WEBSOCKET_PORT', $argv[2] ?? 8081);

class WebSocketServer
{
    protected $clients;
    protected $clientSockets;

    protected function send($message, $to = ['broadcast' => true])
    {
        if (is_array($message) || is_object($message)) {
            $message = $this->seal(json_encode($message));
        }

        $messageLength = strlen($message);
        foreach ($this->clientSockets as $clientSocket) {
            if (
                (isset($to['broadcast']) && $to['broadcast']) ||
                (isset($to['socket']) && $to['socket'] == $clientSocket)
            ) {
                @socket_write($clientSocket, $message, $messageLength);
            }
        }

        return true;
    }

    function unseal($socketData) {
        $length = ord($socketData[1]) & 127;
        if($length == 126) {
            $masks = substr($socketData, 4, 4);
            $data = substr($socketData, 8);
        }
        elseif($length == 127) {
            $masks = substr($socketData, 10, 4);
            $data = substr($socketData, 14);
        }
        else {
            $masks = substr($socketData, 2, 4);
            $data = substr($socketData, 6);
        }
        $socketData = "";
        for ($i = 0; $i < strlen($data); ++$i) {
            $socketData .= $data[$i] ^ $masks[$i%4];
        }
        return $socketData;
    }

    function seal($socketData) {
        $b1 = 0x80 | (0x1 & 0x0f);
        $length = strlen($socketData);

        if($length <= 125)
            $header = pack('CC', $b1, $length);
        elseif($length > 125 && $length < 65536)
            $header = pack('CCn', $b1, 126, $length);
        elseif($length >= 65536)
            $header = pack('CCNN', $b1, 127, $length);
        return $header.$socketData;
    }

    function doHandshake($received_header,$client_socket_resource, $host_name, $port) {
        $headers = array();
        $lines = preg_split("/\r\n/", $received_header);
        foreach($lines as $line)
        {
            $line = chop($line);
            if(preg_match('/\A(\S+): (.*)\z/', $line, $matches))
            {
                $headers[$matches[1]] = $matches[2];
            }
        }

        $secKey = $headers['Sec-WebSocket-Key'];
        $secAccept = base64_encode(pack('H*', sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $buffer  = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "WebSocket-Origin: $host_name\r\n" .
            "WebSocket-Location: ws://$host_name:$port/demo/shout.php\r\n".
            "Sec-WebSocket-Accept:$secAccept\r\n\r\n";
        socket_write($client_socket_resource,$buffer,strlen($buffer));
    }

    function newConnectionACK($client_ip_address)
    {
        $message = 'New client ' . $client_ip_address.' joined';
        $messageArray = array('message'=>$message,'message_type'=>'chat-connection-ack');
        $ACK = $this->seal(json_encode($messageArray));
        return $ACK;
    }

    function connectionDisconnectACK($client_ip_address)
    {
        $message = 'Client ' . $client_ip_address.' disconnected';
        $messageArray = array('message'=>$message,'message_type'=>'chat-connection-ack');
        $ACK = $this->seal(json_encode($messageArray));
        return $ACK;
    }

    protected function prepare($socketMessage)
    {
        $message = null;
        if ($socketMessage && is_string($socketMessage) && $socketMessage[0] == '{') {
            $message = json_decode($socketMessage, true);
        }
        if (empty($message)) {
            $message = [
                'message' => $socketMessage,
            ];
        }

        return $this->seal(json_encode($message));
    }

    protected function add($socket, $greeting)
    {
        $socketId = md5(spl_object_hash($socket));

        $this->clients[$socketId] = [
            'id' => $socketId,
            'socket' => $socket,
            'greeting' => $greeting
        ];
    }

    public function run()
    {
        $socketResource = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($socketResource, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socketResource, 0, WEBSOCKET_PORT);
        socket_listen($socketResource);

        $null = null;
        $this->clientSockets = array($socketResource);
        while (true) {
            $newSocketArray = $this->clientSockets;
            socket_select($newSocketArray, $null, $null, 0, 10);

            if (in_array($socketResource, $newSocketArray)) {
                $newSocket = socket_accept($socketResource);
                $this->clientSockets[] = $newSocket;

                $header = socket_read($newSocket, 1024);
                $this->doHandshake($header, $newSocket, WEBSOCKET_HOST, WEBSOCKET_PORT);

                socket_getpeername($newSocket, $client_ip_address);
                $connectionACK = $this->newConnectionACK($client_ip_address);

                $this->send($connectionACK, ['socket' => $newSocket]);

                $newSocketIndex = array_search($socketResource, $newSocketArray);
                unset($newSocketArray[$newSocketIndex]);
            }

            foreach ($newSocketArray as $newSocketArrayResource) {
                while (socket_recv($newSocketArrayResource, $socketData, 1024, 0) >= 1) {
                    $socketMessage = $this->unseal($socketData);
                    $socketId = md5(spl_object_hash($newSocketArrayResource));
                    if (empty($this->clients[$socketId])) {
                        $this->add($newSocketArrayResource, $socketMessage);
                        $this->send(['welcome' => $socketId], ['socket' => $newSocketArrayResource]);
                    } else {
                        $jsonMessage = $this->prepare($socketMessage);
                        $this->send($jsonMessage);
                    }
                    break 2;
                }

                // Handle disconnected client
                $socketData = @socket_read($newSocketArrayResource, 1024, PHP_NORMAL_READ);
                if ($socketData === false) {
                    socket_getpeername($newSocketArrayResource, $client_ip_address);
                    // The following 2 lines send notification about disconnected connection
                    //$connectionACK = $this->connectionDisconnectACK($client_ip_address);
                    //$this->send($connectionACK);
                    $newSocketIndex = array_search($newSocketArrayResource, $this->clientSockets);
                    unset($this->clientSockets[$newSocketIndex]);
                }
            }
        }

        socket_close($socketResource);
    }
}

$server = new WebSocketServer();
$server->run();
