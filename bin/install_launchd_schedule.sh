#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="/Applications/XAMPP/xamppfiles/htdocs/miss"
PHP_BIN="/Applications/XAMPP/xamppfiles/bin/php"
LABEL="com.mistool.autoimport"
PLIST="$HOME/Library/LaunchAgents/${LABEL}.plist"

mkdir -p "$HOME/Library/LaunchAgents"

cat > "$PLIST" <<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
  <key>Label</key>
  <string>${LABEL}</string>
  <key>ProgramArguments</key>
  <array>
    <string>${PHP_BIN}</string>
    <string>${APP_ROOT}/bin/run_auto_import.php</string>
  </array>
  <key>StartInterval</key>
  <integer>900</integer>
  <key>WorkingDirectory</key>
  <string>${APP_ROOT}</string>
  <key>StandardOutPath</key>
  <string>${APP_ROOT}/storage/auto-import-launchd.log</string>
  <key>StandardErrorPath</key>
  <string>${APP_ROOT}/storage/auto-import-launchd.err.log</string>
</dict>
</plist>
PLIST

launchctl unload "$PLIST" >/dev/null 2>&1 || true
launchctl load "$PLIST"
echo "Installed ${LABEL}. The scheduler checks due MIS auto-import jobs every 15 minutes."
