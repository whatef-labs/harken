#!/usr/bin/env sh
# Creates a throwaway private CA plus broker and client certificates for local
# TLS tests. Never use these outside a test environment.
#
# Usage: sh docker/certs/generate.sh [SAN ...]
#   e.g. sh docker/certs/generate.sh IP:192.168.10.20 DNS:mqtt.lan
set -eu

cd "$(dirname "$0")"

SAN="DNS:localhost,DNS:emqx,IP:127.0.0.1"
for extra in "$@"; do
    SAN="$SAN,$extra"
done

openssl req -x509 -newkey rsa:2048 -nodes -sha256 -days 3650 \
    -keyout ca.key -out ca.crt -subj "/CN=Harken Test CA"

openssl req -newkey rsa:2048 -nodes -sha256 \
    -keyout server.key -out server.csr -subj "/CN=localhost"
printf 'subjectAltName=%s\nextendedKeyUsage=serverAuth\n' "$SAN" > server.ext
openssl x509 -req -sha256 -days 825 -in server.csr -CA ca.crt -CAkey ca.key \
    -CAcreateserial -out server.crt -extfile server.ext

openssl req -newkey rsa:2048 -nodes -sha256 \
    -keyout client.key -out client.csr -subj "/CN=harken-client"
printf 'extendedKeyUsage=clientAuth\n' > client.ext
openssl x509 -req -sha256 -days 825 -in client.csr -CA ca.crt -CAkey ca.key \
    -CAcreateserial -out client.crt -extfile client.ext

rm -f ./*.csr ./*.ext ./*.srl
# The EMQX container runs as a non-root user and must be able to read the keys.
chmod 644 ./*.crt ./*.key

echo "Certificates written to $(pwd) (SAN: $SAN)"
