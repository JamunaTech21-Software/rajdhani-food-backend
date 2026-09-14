<?php

declare(strict_types=1);

namespace Rajdhani\Support;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Stringable;

/**
 * PSR-3 logger writing one file per day to storage/logs (doc 5.3, 16.5).
 *
 * Rotation is by filename rather than by size, and pruning is a cron job
 * (RTPP-84), not something this class does. That split is deliberate: shared
 * hosting has a disk quota, and a log that grows without bound will fill it and
 * take the whole site down — but deleting files is not something a request
 * handler should ever be doing on the side.
 */
final class Logger extends AbstractLogger
{
    private const LEVEL_ORDER = [
        LogLevel::DEBUG     => 0,
        LogLevel::INFO      => 1,
        LogLevel::NOTICE    => 2,
        LogLevel::WARNING   => 3,
        LogLevel::ERROR     => 4,
        LogLevel::CRITICAL  => 5,
        LogLevel::ALERT     => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private int $threshold;

    public function __construct(
        private readonly string $directory,
        string $minimumLevel = LogLevel::INFO,
    ) {
        $this->threshold = self::LEVEL_ORDER[$minimumLevel] ?? self::LEVEL_ORDER[LogLevel::INFO];
    }

    /**
     * @param array<string,mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $levelName = is_string($level) ? $level : LogLevel::INFO;

        if ((self::LEVEL_ORDER[$levelName] ?? 1) < $this->threshold) {
            return;
        }

        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            // Logging must never be the thing that breaks a request. If the
            // directory cannot be created there is nowhere to report that to.
            return;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $file = sprintf('%s/api-%s.log', $this->directory, $now->format('Y-m-d'));

        $line = sprintf(
            "[%s] %s: %s%s\n",
            $now->format('Y-m-d\TH:i:s.vP'),
            strtoupper($levelName),
            $this->interpolate((string) $message, $context),
            $context === [] ? '' : ' ' . $this->encodeContext($context),
        );

        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /** @param array<string,mixed> $context */
    private function interpolate(string $message, array $context): string
    {
        $replacements = [];

        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value instanceof Stringable || $value === null) {
                $replacements['{' . $key . '}'] = (string) $value;
            }
        }

        return strtr($message, $replacements);
    }

    /** @param array<string,mixed> $context */
    private function encodeContext(array $context): string
    {
        // Exceptions arrive as objects; keep the useful parts and drop the rest
        // rather than failing to encode.
        array_walk_recursive($context, static function (mixed &$value): void {
            if ($value instanceof \Throwable) {
                $value = sprintf('%s: %s @ %s:%d', $value::class, $value->getMessage(), $value->getFile(), $value->getLine());
            } elseif (is_object($value)) {
                $value = $value::class;
            }
        });

        $json = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? '{}' : $json;
    }
}
