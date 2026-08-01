<?php

namespace zFramework\Core;

/**
 * Where the framework reports how long a stage took, if anything is listening.
 *
 * Nothing listens by default: $collector is null, mark() returns immediately,
 * and the call sites cost one comparison. The Profiling module sets a collector
 * during boot when framework.profiling is enabled.
 *
 *   Profiler::listen(fn($stage, $ns) => ...);
 *
 * The framework knows nothing about who is collecting, so removing the module
 * removes the profiling with it.
 */
class Profiler
{
    /**
     * Called with (string $stage, float $nanoseconds) for each measured stage.
     */
    private static ?\Closure $collector = null;

    /**
     * Start collecting. Pass null to stop.
     *
     * @param \Closure|null $collector
     * @return void
     */
    public static function listen(?\Closure $collector): void
    {
        self::$collector = $collector;
    }

    /**
     * Is anything collecting? Check this before timing, so a stage costs nothing
     * to leave instrumented while profiling is off.
     *
     * @return bool
     */
    public static function active(): bool
    {
        return self::$collector !== null;
    }

    /**
     * Report a stage's duration.
     *
     * @param string $stage
     * @param float  $nanoseconds
     * @return void
     */
    public static function mark(string $stage, float $nanoseconds): void
    {
        if (self::$collector === null) return;

        (self::$collector)($stage, $nanoseconds);
    }

    /**
     * @return void
     */
    public static function flushRequestState(): void
    {
        self::$collector = null;
    }
}
