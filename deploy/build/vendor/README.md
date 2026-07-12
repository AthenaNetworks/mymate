# Vendored binaries

## fping (5.5, linux/amd64)
The fping build with JSON-Lines output (`--json`) that `FpingRunner` parses. Debian 13
ships only fping 5.2, which lacks `--json`, so `build-deb.sh` bundles this binary into the
package at `/usr/local/sbin/fping`.

- Built from the upstream fping 5.5 release (https://fping.org), stripped.
- Dynamically links only `libc` -> runs on any Debian 13 amd64 target.
- Regenerate (e.g. for another release or arch) with:
      OUT=deploy/build/vendor/fping deploy/build/build-fping.sh
