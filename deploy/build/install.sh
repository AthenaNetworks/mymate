#!/usr/bin/env bash
#
# My Mate - installer (Debian 13 / Ubuntu 22.04+). One self-contained .deb: the app plus
# a postinst that provisions PostgreSQL, Redis, nginx, php-fpm and the background engine.
# This wrapper checks the environment (adding the PHP 8.4 repo on Ubuntu), then hands the
# .deb to apt, which pulls every system dependency from the distro and runs the setup.
#
#   sudo ./install.sh
#
set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[32m%s\033[0m\n' "$*"; }
warn() { printf '\033[33m!! \033[0m%s\n' "$*"; }
say()  { printf '\033[36m==>\033[0m %s\n' "$*"; }

# --- checks ---------------------------------------------------------------
[ "$(id -u)" -eq 0 ] || { red "Please run as root:  sudo ./install.sh"; exit 1; }
command -v apt-get >/dev/null 2>&1 || { red "apt-get not found - this needs Debian/Ubuntu."; exit 1; }

case "$(dpkg --print-architecture)" in
    amd64) : ;;
    *) red "This build is amd64 only (found $(dpkg --print-architecture))."; exit 1 ;;
esac

APPDEB="$(ls "$HERE"/mymate_*.deb 2>/dev/null | head -1 || true)"
[ -n "$APPDEB" ] || { red "No mymate_*.deb found next to this script."; exit 1; }

export DEBIAN_FRONTEND=noninteractive
. /etc/os-release 2>/dev/null || true

# --- ensure PHP 8.4 is installable ---------------------------------------
# The package depends on php8.4-*. Debian 13 has it natively; Ubuntu needs Ondřej Surý's
# PPA. Other distros: the deps must be resolvable or the apt step below will say so.
case "${ID:-}" in
    debian)
        [ "${VERSION_ID:-}" = "13" ] || warn "Built for Debian 13; found ${VERSION_ID:-?} - continuing."
        ;;
    ubuntu)
        say "Ubuntu ${VERSION_ID:-} detected"
        apt-get update -qq || true
        if ! apt-cache show php8.4-fpm >/dev/null 2>&1; then
            say "Adding the Ondřej Surý PHP PPA (provides PHP 8.4 on Ubuntu)"
            apt-get install -y --no-install-recommends software-properties-common ca-certificates gnupg
            add-apt-repository -y ppa:ondrej/php
        fi
        ;;
    *)
        warn "Untested distro '${ID:-unknown}'. Needs PHP 8.4, PostgreSQL, Redis, nginx available to apt."
        ;;
esac

# --- install --------------------------------------------------------------
say "Refreshing package lists"
apt-get update -qq || warn "apt update failed - dependency install may fail"

say "Installing $(basename "$APPDEB") + all dependencies"
# apt resolves the .deb's Depends (php-fpm, postgresql, redis, nginx, ...) and runs the
# postinst, which does the full first-boot provisioning.
apt-get install -y "$APPDEB"

grn ""
grn "My Mate installed. Follow the on-screen next steps above to create your admin login"
grn "and open the console. Free and open source - no limits, nothing to unlock."
