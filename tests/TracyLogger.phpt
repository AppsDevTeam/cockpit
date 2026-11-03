<?php

declare(strict_types=1);

use ADT\Cockpit\TracyLogger;
use Tester\Assert;
use Tracy\Debugger;
use Tracy\ILogger;

require __DIR__ . '/bootstrap.php';


$logger = new class implements ILogger {
	/** @var list<array{mixed, string}> */
	public array $logged = [];


	public function log(mixed $value, string $level = self::INFO): void
	{
		$this->logged[] = [$value, $level];
	}
};
Debugger::setLogger($logger);


test('4xx is logged as an exception, unavailability as one line into the channel', function () use ($logger) {
	$mock = new CockpitMock(json(['error' => 'Authentication failed'], 412), json([], 503), json([], 503));
	$cockpit = $mock->createCockpit();
	$cockpit->setOnError([new TracyLogger]);

	Assert::exception(fn() => $cockpit->getEntries('Career'), ADT\Cockpit\Exception\RequestException::class);
	Assert::exception(fn() => $cockpit->getEntries('Article'), ADT\Cockpit\Exception\UnavailableException::class);

	Assert::type(ADT\Cockpit\Exception\RequestException::class, $logger->logged[0][0]);
	Assert::same(ILogger::EXCEPTION, $logger->logged[0][1]);
	Assert::same(['Cockpit responded 503: https://cockpit.example.com/api/content/items/Article', 'cockpit'], $logger->logged[1]);
});
