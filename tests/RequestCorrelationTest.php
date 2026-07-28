<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use Moselwal\Log\RequestIdProcessor;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Log\LogRecord;

/**
 * The processor's job is to make one identifier reappear in the application log
 * so a request can be followed across Traefik, Caddy and TYPO3. The rules that
 * matter are what it accepts and what it refuses.
 */
class RequestCorrelationTest extends ConfigTestCase
{
    private const VALID = '019fa55e-9485-7e35-a15a-55144975e4c3';

    protected function tearDown(): void
    {
        unset($GLOBALS['TYPO3_REQUEST']);
        parent::tearDown();
    }

    #[Test]
    public function theRequestIdIsAttachedToTheRecord(): void
    {
        $record = $this->process(self::VALID);

        self::assertSame(self::VALID, $record->getData()['requestId'] ?? null);
    }

    #[Test]
    public function aCallerSuppliedValueThatIsNotAUuidIsIgnored(): void
    {
        // In production the edge overwrites any incoming X-Request-Id, so the
        // value is ours. This code also runs where no edge sits in front of it,
        // and there the header is whatever the caller sent — straight into log
        // files and sys_log if it were taken at face value.
        foreach (['nicht-uuid', str_repeat('a', 5000), "019fa55e\ninjected", '', '../../etc/passwd'] as $hostile) {
            $record = $this->process($hostile);

            self::assertArrayNotHasKey('requestId', $record->getData(), sprintf('accepted %s', substr($hostile, 0, 20)));
        }
    }

    #[Test]
    public function withoutARequestTheRecordIsUntouched(): void
    {
        // CLI, scheduler, early boot. Absent is not an error.
        unset($GLOBALS['TYPO3_REQUEST']);

        $record = (new RequestIdProcessor())->processLogRecord(new LogRecord('component', \Psr\Log\LogLevel::DEBUG, 'message'));

        self::assertArrayNotHasKey('requestId', $record->getData());
    }

    #[Test]
    public function existingRecordDataSurvives(): void
    {
        $record = new LogRecord('component', \Psr\Log\LogLevel::DEBUG, 'message', ['existing' => 'value']);
        $GLOBALS['TYPO3_REQUEST'] = $this->requestWithHeader(self::VALID);

        $result = (new RequestIdProcessor())->processLogRecord($record);

        self::assertSame('value', $result->getData()['existing'] ?? null);
        self::assertSame(self::VALID, $result->getData()['requestId'] ?? null);
    }

    private function process(string $headerValue): LogRecord
    {
        $GLOBALS['TYPO3_REQUEST'] = $this->requestWithHeader($headerValue);

        return (new RequestIdProcessor())->processLogRecord(new LogRecord('component', \Psr\Log\LogLevel::DEBUG, 'message'));
    }

    private function requestWithHeader(string $value): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getHeaderLine')->with('X-Request-Id')->willReturn($value);

        return $request;
    }
}
