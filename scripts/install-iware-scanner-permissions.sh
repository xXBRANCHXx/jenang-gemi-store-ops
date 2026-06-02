#!/usr/bin/env bash
set -euo pipefail

RULE_PATH="/etc/udev/rules.d/70-iware-scanner.rules"
RULE='SUBSYSTEM=="tty", ATTRS{idVendor}=="0c2e", ATTRS{idProduct}=="0900", GROUP="dialout", MODE="0666", SYMLINK+="iware-scanner"'

if [[ "${EUID}" -ne 0 ]]; then
  echo "Run with sudo: sudo $0" >&2
  exit 1
fi

printf '%s\n' "${RULE}" > "${RULE_PATH}"
udevadm control --reload-rules
udevadm trigger

echo "Installed ${RULE_PATH}"
echo "Reconnect the IWARE scanner, then Store Ops should see /dev/iware-scanner or /dev/ttyACM0 as readable."
