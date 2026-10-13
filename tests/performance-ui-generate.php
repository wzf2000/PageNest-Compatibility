<?php
/** Generate synthetic states in separate PHP processes so constants stay isolated. */
foreach (
    [
        'disabled',
        'config-error',
        'empty',
        'queued',
        'running',
        'expired-running',
        'cancel-notice',
        'completed',
        'failed',
        'history',
        'incomparable',
        'budget',
    ]
    as $scenario
) {
    passthru(
        escapeshellarg(PHP_BINARY) .
            ' ' .
            escapeshellarg(__DIR__ . '/performance-ui-fixture.php') .
            ' ' .
            escapeshellarg($scenario),
        $status,
    );
    if ($status !== 0) {
        exit($status);
    }
}
