<?php

namespace App\Services;

use App\Models\JobLog;

class DatabaseLogger
{
    public static function info(string $jobClass, string $message, array $context = [], ?string $jobId = null): void
    {
        self::log('info', $jobClass, $message, $context, $jobId);
    }

    public static function error(string $jobClass, string $message, array $context = [], ?string $jobId = null): void
    {
        self::log('error', $jobClass, $message, $context, $jobId);
    }

    public static function warning(string $jobClass, string $message, array $context = [], ?string $jobId = null): void
    {
        self::log('warning', $jobClass, $message, $context, $jobId);
    }

    private static function log(string $level, string $jobClass, string $message, array $context = [], ?string $jobId = null): void
    {
        JobLog::create([
            'job_class' => $jobClass,
            'job_id' => $jobId,
            'level' => $level,
            'message' => $message,
            'context' => $context,
            'extra' => [
                'timestamp' => now(),
                'memory_usage' => memory_get_usage(true),
            ]
        ]);
    }
}