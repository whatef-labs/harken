<?php

declare(strict_types=1);

use Whatef\Harken\Runtime\Routing\TopicPattern;

describe('matching', function () {
    it('matches topics as defined by MQTT 3.1.1 section 4.7', function (string $pattern, string $topic, bool $matches) {
        expect((new TopicPattern($pattern))->match($topic) !== null)->toBe($matches);
    })->with([
        // multi-level wildcard
        ['sport/tennis/player1/#', 'sport/tennis/player1', true],
        ['sport/tennis/player1/#', 'sport/tennis/player1/ranking', true],
        ['sport/tennis/player1/#', 'sport/tennis/player1/score/wimbledon', true],
        ['sport/#', 'sport', true],
        ['#', 'sport/tennis', true],
        ['#', '/', true],
        ['sport/tennis/#', 'sport/tennisplayer1', false],
        // single-level wildcard
        ['sport/tennis/+', 'sport/tennis/player1', true],
        ['sport/tennis/+', 'sport/tennis/player2', true],
        ['sport/tennis/+', 'sport/tennis/player1/ranking', false],
        ['sport/+', 'sport', false],
        ['sport/+', 'sport/', true],
        ['+/+', '/finance', true],
        ['/+', '/finance', true],
        ['+', '/finance', false],
        ['+/tennis/#', 'sport/tennis/player1', true],
        // literals are exact and case sensitive
        ['sport/tennis', 'sport/tennis', true],
        ['sport/tennis', 'Sport/tennis', false],
        ['sport/tennis', 'sport/tennis/', false],
        ['sport/tennis', 'sport', false],
        // topics starting with $ never match a wildcard first level
        ['#', '$SYS/monitor/Clients', false],
        ['+/monitor/Clients', '$SYS/monitor/Clients', false],
        ['{id}/monitor/Clients', '$SYS/monitor/Clients', false],
        ['$SYS/#', '$SYS/monitor/Clients', true],
        ['$SYS/monitor/+', '$SYS/monitor/Clients', true],
        // named parameters behave like +
        ['sensors/{id}/temperature', 'sensors/42/temperature', true],
        ['sensors/{id}/temperature', 'sensors/42/humidity', false],
        ['sensors/{id}/temperature', 'sensors/42/7/temperature', false],
        ['sensors/{id}/temperature', 'sensors//temperature', true],
        ['device/{id}/#', 'device/42', true],
    ]);

    it('captures named parameters', function () {
        $pattern = new TopicPattern('site/{site}/device/{id}/+/#');

        expect($pattern->match('site/s1/device/d-9/up/a/b'))->toBe(['site' => 's1', 'id' => 'd-9'])
            ->and($pattern->match('site/s1/device/d-9/up'))->toBe(['site' => 's1', 'id' => 'd-9'])
            ->and($pattern->parameterNames())->toBe(['site', 'id']);
    });

    it('returns an empty parameter list for literal and anonymous wildcard matches', function () {
        expect((new TopicPattern('a/b'))->match('a/b'))->toBe([])
            ->and((new TopicPattern('a/+'))->match('a/b'))->toBe([]);
    });
});

describe('subscription filter', function () {
    it('turns named parameters into single-level wildcards', function (string $pattern, string $filter) {
        expect((new TopicPattern($pattern))->filter())->toBe($filter);
    })->with([
        ['sensors/{id}/temperature', 'sensors/+/temperature'],
        ['site/{site}/device/{id}/#', 'site/+/device/+/#'],
        ['a/+/b', 'a/+/b'],
        ['#', '#'],
        ['plain/topic', 'plain/topic'],
    ]);
});

describe('validation', function () {
    it('rejects invalid patterns', function (string $pattern) {
        new TopicPattern($pattern);
    })->with([
        'empty' => '',
        'hash not last' => 'a/#/b',
        'hash inside level' => 'a/b#',
        'plus inside level' => 'a/b+/c',
        'parameter with text' => 'a/{id}x',
        'unclosed parameter' => 'a/{id',
        'invalid parameter name' => 'a/{1id}',
        'duplicate parameter' => 'a/{id}/b/{id}',
        'shared prefix' => '$share/group/a/b',
        'nul byte' => "a/\0",
    ])->throws(InvalidArgumentException::class);
});
