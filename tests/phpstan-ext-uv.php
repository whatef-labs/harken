<?php

declare(strict_types=1);

// Declarations for static analysis only (phpstan.neon.dist scanFiles):
// ext-uv is optional and not installed where the analysis runs.

/**
 * @param  resource  $loop
 */
function uv_update_time($loop): void {}
