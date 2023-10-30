


test:
	@php -f websocket.php

require:
	@composer require --dev phrity/websocket
	@composer require --dev phpunit/phpunit ^10
	@composer require --dev symfony/process

test-websocket:
	@./vendor/bin/phpunit tests/WebsocketTest.php