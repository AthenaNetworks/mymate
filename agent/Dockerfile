# Agent-only image for small container hosts, mainly MikroTik RouterOS 7's container feature
# (see agent/ROUTEROS.md). The full app image needs Postgres and Redis, this one is
# just the static agent binary plus CA roots so it fits on a router's flash.
#
# Multi-arch, cross-compiled on the build host (no qemu needed):
#
#   docker buildx build --platform linux/arm64 --build-arg VERSION=v1.2.0 \
#       --output type=docker,dest=mymate-agent-arm64.tar -t mymate-agent:v1.2.0 agent/
#
# Config is all env vars, same as everywhere else: MYMATE_URL and MYMATE_AGENT_TOKEN are
# required, MYMATE_AGENT_NAME / MYMATE_AGENT_LOG / MYMATE_AGENT_INSECURE are optional. The
# agent keeps no state on disk so there is nothing to mount.

FROM --platform=$BUILDPLATFORM golang:1.25-alpine AS build
ARG TARGETOS
ARG TARGETARCH
ARG TARGETVARIANT
ARG VERSION=dev
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
# TARGETVARIANT is "v7" for linux/arm/v7 and GOARM wants just the 7. Empty on amd64/arm64.
RUN CGO_ENABLED=0 GOOS=$TARGETOS GOARCH=$TARGETARCH GOARM=${TARGETVARIANT#v} \
    go build -trimpath -buildvcs=false \
        -ldflags "-s -w -X github.com/AthenaNetworks/mymate/agent/internal/agent.Version=${VERSION}" \
        -o /out/mymate-agent .

FROM scratch
# CA roots so wss:// to a real certificate verifies. Nothing else comes over from the build stage.
COPY --from=build /etc/ssl/certs/ca-certificates.crt /etc/ssl/certs/ca-certificates.crt
COPY --from=build /out/mymate-agent /mymate-agent
# Runs as root on purpose. RouterOS containers run as root anyway, and the agent's ping falls
# back to a raw ICMP socket there (the unprivileged ping socket is normally refused by
# ping_group_range), which needs root / CAP_NET_RAW.
ENTRYPOINT ["/mymate-agent"]
CMD ["run"]
