<?php

declare(strict_types=1);

namespace Infaris\Monitoring\Tests;

use Infaris\Monitoring\Config;
use Infaris\Monitoring\Transport;

final class RecordingTransport extends Transport
{
    /** @var list<array{path: string, body: array<string, mixed>}> */
    public array $posts = [];

    public function __construct()
    {
        parent::__construct(new Config('test'));
    }

    public function post(string $path, array $body): bool
    {
        $this->posts[] = ['path' => $path, 'body' => $body];

        return true;
    }
}
