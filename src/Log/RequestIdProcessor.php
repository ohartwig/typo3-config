<?php

declare(strict_types=1);

/*
 * SPDX-License-Identifier: GPL-3.0-or-later
 * Copyright (C) 2026 Moselwal Digitalagentur GmbH
 */

namespace Moselwal\Log;

use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Processor\AbstractProcessor;

/**
 * Attaches the edge-generated request id to every log record, so an entry here
 * can be tied to the matching lines in the Traefik and Caddy logs.
 *
 * The id is assigned by the Traefik request-id plugin at the outermost hop and
 * travels unchanged through Caddy. Without this processor the application log
 * is the one place the thread breaks, and correlation falls back to matching
 * timestamps by hand.
 *
 * A caller-supplied id is deliberately NOT trusted for its shape. The edge
 * overwrites any incoming X-Request-Id, so in production the value is ours —
 * but this code also runs where there is no edge in front of it (local compose,
 * console context), and there the header is whatever the caller sent. An
 * unbounded string would be written verbatim into log files and into sys_log,
 * which is a log-injection and log-bloat vector for the price of one header.
 * Only a UUID-shaped value is accepted; anything else is treated as absent.
 */
final class RequestIdProcessor extends AbstractProcessor
{
    private const HEADER = 'X-Request-Id';

    private const UUID = '/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/';

    public function processLogRecord(LogRecord $logRecord): LogRecord
    {
        $requestId = $this->requestId();
        if (null === $requestId) {
            return $logRecord;
        }

        $logRecord->addData(['requestId' => $requestId]);

        return $logRecord;
    }

    private function requestId(): ?string
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof \Psr\Http\Message\ServerRequestInterface) {
            // CLI, scheduler, early boot: there is no request and therefore no
            // id. Not an error — the record simply carries nothing extra.
            return null;
        }

        $value = trim($request->getHeaderLine(self::HEADER));

        return 1 === preg_match(self::UUID, $value) ? $value : null;
    }
}
