<?php

declare(strict_types=1);

namespace Whatef\Harken\Runtime\Connection;

use RuntimeException;
use Whatef\Harken\Protocol\ReasonCode;

/**
 * The broker refused one or more subscriptions. Harken does not run with
 * fewer subscriptions than its routes need, so this ends the connection.
 */
final class SubscriptionRefused extends RuntimeException
{
    /**
     * @param  array<string, ReasonCode>  $refused  Filter => reason code.
     */
    public function __construct(public readonly array $refused, string $hint = '')
    {
        $parts = [];

        foreach ($refused as $filter => $code) {
            $parts[] = "[{$filter}] {$code->describe()}";
        }

        parent::__construct('The broker refused the subscription to '.implode(', ', $parts).'.'.($hint === '' ? '' : " {$hint}"));
    }

    /**
     * @param  list<string>  $filters
     */
    public static function unavailable(array $filters, ReasonCode $reasonCode, string $feature): self
    {
        return new self(array_fill_keys($filters, $reasonCode), "The broker announced that it does not support {$feature}.");
    }
}
