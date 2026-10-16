<?php

declare(strict_types=1);

/*
 * Runs after Larastan booted its application: registers the package's view
 * namespace, as HarkenServiceProvider does, so view names like
 * "harken::pulse.messages" are known to the analysis. The provider itself is
 * not booted here, so container bindings keep their declared types.
 */

app('view')->addNamespace('harken', dirname(__DIR__).'/resources/views');
