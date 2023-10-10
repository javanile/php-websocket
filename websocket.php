<?php

define('WEBSOCKET_HOST', $argv[1] ?? '0.0.0.0');
define('WEBSOCKET_PORT', $argv[2] ?? 8081);

class WebSocketServer
{
    protected $host;
    protected $port;
    protected $clients;
    protected $clientSockets;

    public function __construct($host, $port)
    {
        $this->host = $host;
        $this->port = $port;
    }

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

    protected function handshake($received_header, $client_socket_resource, $host_name, $port)
    {
        $headers = array();
        $lines = preg_split("/\r\n/", $received_header);
        foreach ($lines as $line) {
            $line = chop($line);
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
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

    protected function id($socket)
    {
        return md5(spl_object_hash($socket));
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

        return $message;
    }

    protected function add($socket, $identity)
    {
        $socketId = $this->id($socket);

        $client = [
            'id' => $socketId,
            'socket' => $socket,
        ];

        if ($identity && is_array($identity)) {
            $client = array_merge($identity, $client);
        }

        $this->clients[$socketId] = $client;
    }

    protected function remove($newSocketArrayResource)
    {
        $newSocketIndex = array_search($newSocketArrayResource, $this->clientSockets);
        unset($this->clientSockets[$newSocketIndex]);

        $socketId = md5(spl_object_hash($newSocketArrayResource));
        unset($this->clients[$socketId]);
    }

    protected function welcome($socket, $info)
    {
        $welcomeMessage = [
            'message' => 'Welcome to the PHP WebSocket Server, your address is ' . $info['address'] . ':' . $info['port'] . '.',
        ];

        $this->send($welcomeMessage, ['socket' => $socket]);
    }

    protected function identify($socket, $message)
    {
        return [
            'forward' => true,
        ];
    }

    protected function receive($socket, $message)
    {
        $this->send($message, ['broadcast' => true]);
    }

    public function run()
    {
        $socketResource = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);

        socket_set_option($socketResource, SOL_SOCKET, SO_REUSEADDR, 1);
        socket_bind($socketResource, 0, $this->port);
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
                $this->handshake($header, $newSocket, $this->host, $this->port);

                $socketId = md5(spl_object_hash($newSocket));
                socket_getpeername($newSocket, $address, $port);
                $this->welcome($newSocket, ['id' => $socketId, 'address' => $address, 'port' => $port]);

                $newSocketIndex = array_search($socketResource, $newSocketArray);
                unset($newSocketArray[$newSocketIndex]);
            }

            foreach ($newSocketArray as $newSocketArrayResource) {
                while (socket_recv($newSocketArrayResource, $socketData, 1024, 0) >= 1) {
                    $socketId = $this->id($newSocketArrayResource);
                    $jsonMessage = $this->prepare($this->unseal($socketData));
                    if (empty($this->clients[$socketId])) {
                        $identify = $this->identify($newSocketArrayResource, $jsonMessage);
                        if ($identify) {
                            $this->add($newSocketArrayResource, $identify);
                            if (isset($identify['forward']) && $identify['forward']) {
                                $this->receive($newSocketArrayResource, $jsonMessage);
                            }
                        }
                    } else {
                        $this->receive($newSocketArrayResource, $jsonMessage);
                    }
                    break 2;
                }

                // Handle disconnected client
                $socketData = @socket_read($newSocketArrayResource, 1024, PHP_NORMAL_READ);
                if ($socketData === false) {
                    socket_getpeername($newSocketArrayResource, $client_ip_address);
                    // The following line send notification about disconnected connection
                    //$this->send(['message' => 'Client ' . $client_ip_address . ' disconnected'], ['broadcast' => true]);
                    $this->remove($newSocketArrayResource);
                }
            }
        }

        socket_close($socketResource);
    }
}

$server = new class(WEBSOCKET_HOST, WEBSOCKET_PORT) extends WebSocketServer {

    protected function welcome($socket, $info)
    {
        $info['message'] = 'Welcome to the PHP WebSocket Server, your address is ' . $info['address'] . ':' . $info['port'] . '.';

        $this->send($info, ['socket' => $socket]);
    }

    protected function identify($socket, $message)
    {
        if (empty($message['session'])) {
            $this->send(['error' => 'Session is required'], ['socket' => $socket]);

            return false;
        }

        $this->send(['message' => 'Successfully identified'], ['socket' => $socket]);

        return true;
    }
};

$server->run();
