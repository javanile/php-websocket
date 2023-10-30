
require:
	@composer require --dev phrity/websocket
	@composer require --dev phpunit/phpunit ^10
	@composer require --dev symfony/process

update:
	@composer update

test:
	@php -f websocket.php

test-websocket:
	@./vendor/bin/phpunit tests/WebsocketTest.php