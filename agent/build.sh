#!/usr/bin/env bash
#
# Cross-compile the My Mate agent for every target and package a release archive per
# platform (static binary + the right service file + install.sh). Distro-agnostic: CGO is
# off, so each binary is a single static executable with no libc/runtime dependency.
#
#   ./build.sh                 # build all platforms -> dist/
#   VERSION=v1.2.0 ./build.sh
#   ./build.sh host            # just build for this machine -> bin/mymate-agent
#
set -euo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"
export CGO_ENABLED=0 GOFLAGS="${GOFLAGS:--buildvcs=false}"

VERSION="${VERSION:-dev}"
MODULE="github.com/AthenaNetworks/mymate/agent"
LDFLAGS="-s -w -X ${MODULE}/internal/agent.Version=${VERSION}"
PLATFORMS="linux/amd64 linux/arm64 linux/arm darwin/arm64 darwin/amd64"

if [ "${1:-}" = "host" ]; then
    go build -ldflags "$LDFLAGS" -o bin/mymate-agent .
    echo "built bin/mymate-agent ($VERSION)"
    exit 0
fi

rm -rf dist && mkdir -p dist
for p in $PLATFORMS; do
    os="${p%/*}"; arch="${p#*/}"
    name="mymate-agent_${VERSION}_${os}_${arch}"
    out="dist/$name"
    mkdir -p "$out"

    arm=""; [ "$arch" = "arm" ] && arm="GOARM=7"
    echo "==> $os/$arch"
    env GOOS="$os" GOARCH="$arch" $arm go build -ldflags "$LDFLAGS" -o "$out/mymate-agent" .

    cp packaging/agent.env.example packaging/install.sh README.md "$out/"
    if [ "$os" = "darwin" ]; then
        cp packaging/com.athenanetworks.mymate-agent.plist "$out/"
    else
        cp packaging/mymate-agent.service "$out/"
    fi

    ( cd dist && tar czf "$name.tar.gz" "$name" && rm -rf "$name" )
done

( cd dist && sha256sum ./*.tar.gz > SHA256SUMS )
echo "==> artifacts:"
ls -1 dist
