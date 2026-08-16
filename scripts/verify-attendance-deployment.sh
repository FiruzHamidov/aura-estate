#!/usr/bin/env bash

set -euo pipefail

base_url="${ATTENDANCE_BASE_URL:-https://backend.aura.tj}"
serial_number="${ATTENDANCE_TEST_SERIAL:-}"
communication_key="${ATTENDANCE_TEST_COMM_KEY:-}"
admin_token="${ATTENDANCE_ADMIN_TOKEN:-}"

base_url="${base_url%/}"

if [[ -z "$serial_number" ]]; then
    echo "ATTENDANCE_TEST_SERIAL is required." >&2
    exit 2
fi
if [[ ! "$serial_number" =~ ^[A-Za-z0-9._-]+$ ]]; then
    echo "ATTENDANCE_TEST_SERIAL may contain only letters, numbers, dot, underscore and dash." >&2
    exit 2
fi

headers_file="$(mktemp)"
body_file="$(mktemp)"
trap 'rm -f "$headers_file" "$body_file"' EXIT

request_headers=()
if [[ -n "$communication_key" ]]; then
    request_headers+=(-H "X-Communication-Key: $communication_key")
fi

assert_plain_text() {
    if ! awk 'BEGIN { IGNORECASE=1 } /^Content-Type:[[:space:]]*text\/plain/ { found=1 } END { exit !found }' "$headers_file"; then
        echo "Expected text/plain response." >&2
        sed -n '1,20p' "$headers_file" >&2
        exit 1
    fi
}

status="$(curl -sS -D "$headers_file" -o "$body_file" -w '%{http_code}' \
    "$base_url/iclock/cdata?SN=CODEX-UNKNOWN-PROBE&options=all")"
if [[ "$status" != "403" ]] || ! grep -Fq 'ERROR: UNKNOWN DEVICE' "$body_file"; then
    echo "Unknown-device protection failed: HTTP $status" >&2
    cat "$body_file" >&2
    exit 1
fi
assert_plain_text

status="$(curl -sS -D "$headers_file" -o "$body_file" -w '%{http_code}' \
    "${request_headers[@]}" \
    "$base_url/iclock/cdata?SN=$serial_number&options=all")"
if [[ "$status" != "200" ]]; then
    echo "Device handshake failed: HTTP $status" >&2
    cat "$body_file" >&2
    exit 1
fi
assert_plain_text
grep -Fq "GET OPTION FROM: $serial_number" "$body_file"
grep -Fq 'TransFlag=AttLog' "$body_file"

if [[ -n "$admin_token" ]]; then
    status="$(curl -sS -o "$body_file" -w '%{http_code}' \
        -H "Authorization: Bearer $admin_token" \
        -H 'Accept: application/json' \
        "$base_url/api/attendance/devices?per_page=100")"
    if [[ "$status" != "200" ]] || ! grep -Fq "\"serial_number\":\"$serial_number\"" "$body_file"; then
        echo "Internal device API verification failed: HTTP $status" >&2
        cat "$body_file" >&2
        exit 1
    fi
fi

echo "Attendance deployment verification passed for $base_url and device $serial_number."
