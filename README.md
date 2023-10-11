# php-websocket


## Apache Configuration

```apacheconf
ProxyPass "/websocket" "ws://localhost:52000/websocket"
ProxyPassReverse "/websocket" "ws://localhost:52000/websocket"
```
