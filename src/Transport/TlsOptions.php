<?php

declare(strict_types=1);

namespace Whatef\Harken\Transport;

use SensitiveParameter;

final readonly class TlsOptions
{
    public function __construct(
        public ?string $caFile = null,
        public ?string $certFile = null,
        public ?string $keyFile = null,
        #[SensitiveParameter] public ?string $passphrase = null,
        public bool $verifyPeer = true,
        public bool $verifyPeerName = true,
        public ?string $peerName = null,
    ) {}

    /**
     * @param  array<string, mixed>  $config  The `tls` block of a connection in config/harken.php.
     */
    public static function fromArray(array $config): self
    {
        $string = static fn (string $key): ?string => isset($config[$key]) && is_string($config[$key]) && $config[$key] !== ''
            ? $config[$key]
            : null;

        return new self(
            caFile: $string('cafile'),
            certFile: $string('local_cert'),
            keyFile: $string('local_pk'),
            passphrase: $string('passphrase'),
            verifyPeer: (bool) ($config['verify_peer'] ?? true),
            verifyPeerName: (bool) ($config['verify_peer_name'] ?? true),
            peerName: $string('peer_name'),
        );
    }

    /**
     * PHP `ssl` stream context options. TLS 1.2 is the minimum.
     *
     * @return array<string, mixed>
     */
    public function toStreamContext(): array
    {
        return array_filter([
            'cafile' => $this->caFile,
            'local_cert' => $this->certFile,
            'local_pk' => $this->keyFile,
            'passphrase' => $this->passphrase,
            'verify_peer' => $this->verifyPeer,
            'verify_peer_name' => $this->verifyPeerName,
            'peer_name' => $this->peerName,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['passphrase' => $this->passphrase === null ? null : '********'] + get_object_vars($this);
    }
}
