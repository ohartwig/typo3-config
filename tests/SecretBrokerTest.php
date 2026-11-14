<?php

declare(strict_types=1);

namespace Moselwal\Tests;

use phpmock\phpunit\PHPMock;
use PHPUnit\Framework\Attributes\Test;

/**
 * resolveSecret() asks the pod's secret broker first when
 * KOH_SECRET_BROKER_SOCKET is set, and falls back to files and environment
 * on any failure. The broker here is a real unix socket served by a second
 * PHP process (tests/Fixtures/fake-secret-broker.php).
 */
class SecretBrokerTest extends ConfigTestCase
{
    use PHPMock;

    /** @var resource|null */
    private $process = null;
    private string $socket = '';

    protected function tearDown(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
        if ($this->socket !== '') {
            @unlink($this->socket);
            @unlink($this->socket . '.ready');
        }
        parent::tearDown();
    }

    /**
     * @param array<string, string> $answers name => raw answer
     */
    private function startBroker(array $answers): string
    {
        // sun_path is 104 bytes on macOS: keep the path short.
        $this->socket = sys_get_temp_dir() . '/sb-' . bin2hex(random_bytes(4)) . '.sock';
        $this->process = proc_open(
            [PHP_BINARY, __DIR__ . '/Fixtures/fake-secret-broker.php', $this->socket, json_encode($answers)],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        for ($i = 0; $i < 200 && !file_exists($this->socket . '.ready'); $i++) {
            usleep(10_000);
        }
        self::assertFileExists($this->socket . '.ready', 'fake broker did not start');

        return $this->socket;
    }

    /**
     * @param array<string, string> $env
     */
    private function mockEnv(array $env, ?string $defaultFile = null, string $fileValue = ''): void
    {
        $this->getFunctionMock('Moselwal', 'getenv')->expects(self::any())
            ->willReturnCallback(static fn (string $key) => $env[$key] ?? false);
        $this->getFunctionMock('Moselwal', 'is_readable')->expects(self::any())
            ->willReturnCallback(static fn (string $path) => $path === $defaultFile);
        $this->getFunctionMock('Moselwal', 'file_get_contents')->expects(self::any())
            ->willReturn($fileValue);
    }

    #[Test]
    public function brokerValueWinsOverTheSecretFile(): void
    {
        $socket = $this->startBroker(['encryption_key' => "OK 11\nfrom-broker"]);
        $this->mockEnv(['KOH_SECRET_BROKER_SOCKET' => $socket], '/run/secrets/encryption_key', 'from-file');

        $config = TestableConfig::initializeWithVersion(14);
        self::assertSame('from-broker', $config->testResolveSecret('ENCRYPTION_KEY'));
    }

    #[Test]
    public function aValueEndingInANewlineIsTrimmedLikeAFile(): void
    {
        $socket = $this->startBroker(['gitlab_oauth_app_id' => "OK 6\nabc12\n"]);
        $this->mockEnv(['KOH_SECRET_BROKER_SOCKET' => $socket]);

        $config = TestableConfig::initializeWithVersion(14);
        self::assertSame('abc12', $config->testResolveSecret('GITLAB_OAUTH_APP_ID'));
    }

    #[Test]
    public function aNameTheBrokerDoesNotServeFallsBackToTheFile(): void
    {
        $socket = $this->startBroker([]);
        $this->mockEnv(['KOH_SECRET_BROKER_SOCKET' => $socket], '/run/secrets/db_password', 'from-file');

        $config = TestableConfig::initializeWithVersion(14);
        self::assertSame('from-file', $config->testResolveSecret('DB_PASSWORD'));
    }

    #[Test]
    public function aMissingSocketFallsBackToTheEnvironment(): void
    {
        $this->mockEnv(['KOH_SECRET_BROKER_SOCKET' => sys_get_temp_dir() . '/no-such-broker.sock', 'MAIL_PASSWORD' => 'from-env']);

        $config = TestableConfig::initializeWithVersion(14);
        self::assertSame('from-env', $config->testResolveSecret('MAIL_PASSWORD'));
    }

    #[Test]
    public function aMalformedOrShortAnswerIsIgnored(): void
    {
        $socket = $this->startBroker([
            'encryption_key' => "OK 50\nshort",
            'mail_password' => "garbage\n",
            'mail_username' => "OK 0\n",
        ]);
        $this->mockEnv(['KOH_SECRET_BROKER_SOCKET' => $socket]);

        $config = TestableConfig::initializeWithVersion(14);
        self::assertSame('fb1', $config->testResolveSecret('ENCRYPTION_KEY', 'fb1'));
        self::assertSame('fb2', $config->testResolveSecret('MAIL_PASSWORD', 'fb2'));
        self::assertSame('fb3', $config->testResolveSecret('MAIL_USERNAME', 'fb3'));
    }

    #[Test]
    public function withoutTheVariableNoSocketIsContacted(): void
    {
        $this->mockEnv([], '/run/secrets/encryption_key', 'from-file');
        $this->getFunctionMock('Moselwal', 'stream_socket_client')->expects(self::never());

        $config = TestableConfig::initializeWithVersion(14);
        self::assertSame('from-file', $config->testResolveSecret('ENCRYPTION_KEY'));
    }
}
