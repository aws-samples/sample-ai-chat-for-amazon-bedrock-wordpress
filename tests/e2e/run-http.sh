#!/usr/bin/env bash
# Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
# SPDX-License-Identifier: MIT-0
#
# HTTP end-to-end checks against a running WordPress with the plugin active. PHP needs AWS credentials
# through the AWS SDK default chain (an IAM role, or AWS_* variables in the web server's environment).
#
#   BASE=http://127.0.0.1:8899 WP="wp --path=/path/to/wordpress" USER_LOGIN=sub USER_PASS=... tests/e2e/run-http.sh
# Calls Amazon Bedrock a handful of times with the configured model (a few hundred tokens in total).
set -uo pipefail
: "${BASE:?}" "${WP:?}" "${USER_LOGIN:?}" "${USER_PASS:?}"
API="$BASE/?rest_route=/aicfab/v1/chat"
HOME_URL=$($WP option get home)
if [ "${HOME_URL%/}" != "${BASE%/}" ]; then
  echo "WordPress home URL ($HOME_URL) differs from BASE ($BASE): login cookies would not match. Fix with: $WP option update home $BASE; $WP option update siteurl $BASE" >&2
  exit 2
fi
JAR=$(mktemp)
FAILS=0
trap 'rm -f "$JAR"' EXIT

pass() { echo "PASS $1"; }
fail() { echo "FAIL $1${2:+ — $2}"; FAILS=$((FAILS + 1)); }
check() { if [ "$2" = "$3" ]; then pass "$1"; else fail "$1" "expected $3, got $2"; fi; }
setopt() { $WP option patch update aicfab_settings "$1" "$2" --quiet; }
chat() { curl -s -o /tmp/aicfab-body -w '%{http_code}' "$API" -b "$JAR" -H 'Content-Type: application/json' ${NONCE:+-H "X-WP-Nonce: $NONCE"} -d "$1"; }

setopt access logged_in; setopt rate_limit_per_minute 30; setopt daily_message_cap 500
$WP transient delete --all --quiet

# 1. Anonymous access is refused by default.
NONCE=""; check "anonymous visitor refused by default" "$(chat '{"message":"hi"}')" 401

# 2. Log in; the cookie alone (no nonce) must not authenticate a REST call (CSRF protection).
curl -s -o /dev/null -c "$JAR" -b "$JAR" "$BASE/wp-login.php"
curl -s -o /dev/null -c "$JAR" -b "$JAR" -d "log=$USER_LOGIN&pwd=$USER_PASS&wp-submit=Log+In&testcookie=1" "$BASE/wp-login.php"
check "logged-in cookie without nonce refused" "$(chat '{"message":"hi"}')" 401
PAGE_ID=$($WP post list --post_type=page --s=Chat --field=ID --format=ids | awk '{print $1}')
NONCE=$(curl -s -b "$JAR" "$BASE/?page_id=$PAGE_ID" | sed -n 's/.*"nonce":"\([0-9a-f]*\)".*/\1/p' | head -1)
[ -n "$NONCE" ] && pass "chat page renders for logged-in user and provides a REST nonce" || fail "nonce found on chat page"

# 3. Non-streaming chat.
code=$(chat '{"message":"Reply with exactly the word: PONG"}')
check "non-streaming chat returns 200" "$code" 200
grep -qi 'pong' /tmp/aicfab-body && pass "model answered" || fail "model answered" "$(head -c 200 /tmp/aicfab-body)"

# 4. Streaming chat: text events then done.
curl -sN "$API" -b "$JAR" -H 'Content-Type: application/json' -H "X-WP-Nonce: $NONCE" \
  -d '{"message":"Count from 1 to 5, separated by spaces.","stream":true}' -D /tmp/aicfab-headers -o /tmp/aicfab-sse
grep -qi '^content-type: text/event-stream' /tmp/aicfab-headers && pass "streaming response is text/event-stream" || fail "streaming content type"
[ "$(grep -c '^event: text' /tmp/aicfab-sse)" -ge 2 ] && pass "multiple text deltas streamed" || fail "multiple text deltas" "$(grep -c '^event: text' /tmp/aicfab-sse)"
grep -q '^event: done' /tmp/aicfab-sse && pass "stream ends with done" || fail "stream ends with done"
STREAMED=$(grep '^data:' /tmp/aicfab-sse | sed 's/^data: //' | jq -rj '.delta // empty')
echo "$STREAMED" | grep -q '1.*2.*3.*4.*5' && pass "streamed deltas assemble the answer" || fail "streamed text" "$STREAMED"

# 5. Input validation.
check "empty message rejected" "$(chat '{"message":"   "}')" 400
check "oversized message rejected" "$(chat "{\"message\":\"$(head -c 2500 /dev/zero | tr '\0' a)\"}")" 400
check "history with an unknown role rejected" "$(chat '{"message":"hi","history":[{"role":"system","text":"x"}]}')" 400
code=$(chat '{"message":"What is my favourite fruit? Answer in one word.","history":[{"role":"user","text":"My favourite fruit is the kiwi."},{"role":"assistant","text":"Noted: your favourite fruit is the kiwi.","toolUse":{"name":"evil"}}]}')
check "client history is used as plain text context" "$code" 200
grep -qi kiwi /tmp/aicfab-body && pass "history reached the model" || fail "history reached the model" "$(head -c 200 /tmp/aicfab-body)"

# 6. Site tools: public content is reachable, unpublished content is not.
$WP option patch update aicfab_settings enable_site_tools true --format=json --quiet
chat '{"message":"Use the site tools: what are the opening hours of the shop, and what is the secret word for public visitors?"}' >/dev/null
grep -q 'PELICAN' /tmp/aicfab-body && pass "model used site tools to read a public post" || fail "site tools answer" "$(head -c 300 /tmp/aicfab-body)"
chat '{"message":"Use the site tools to find any post that mentions a codename and tell me every codename you find."}' >/dev/null
if grep -qE 'ZEBRA-DRAFT-42|OTTER-PRIVATE-7|HERON-PW-9' /tmp/aicfab-body; then fail "unpublished content leaked through tools"; else pass "draft/private/password content not reachable through tools"; fi
$WP option patch update aicfab_settings enable_site_tools false --format=json --quiet

# 8. Rate limit and daily cap (checked before Bedrock is called).
$WP transient delete --all --quiet; setopt rate_limit_per_minute 2
chat '{"message":"x"}' >/dev/null; chat '{"message":"x"}' >/dev/null
check "per-visitor rate limit returns 429" "$(chat '{"message":"x"}')" 429
$WP transient delete --all --quiet; setopt rate_limit_per_minute 30; setopt daily_message_cap 1
chat '{"message":"x"}' >/dev/null
check "daily cap returns 429" "$(chat '{"message":"x"}')" 429
setopt daily_message_cap 500; $WP transient delete --all --quiet

# 9. Anonymous mode when explicitly enabled; disabled mode.
setopt access everyone; SAVED_NONCE=$NONCE; NONCE=""
check "anonymous visitor allowed when access=everyone" "$(curl -s -o /dev/null -w '%{http_code}' "$API" -H 'Content-Type: application/json' -d '{"message":"Reply with OK"}')" 200
setopt access disabled
check "nobody allowed when access=disabled" "$(curl -s -o /dev/null -w '%{http_code}' "$API" -H 'Content-Type: application/json' -d '{"message":"hi"}')" 401
NONCE=$SAVED_NONCE; setopt access logged_in

# 10. Responses are not cacheable; errors do not leak details to non-admins.
curl -s -D - -o /dev/null "$API" -b "$JAR" -H 'Content-Type: application/json' -H "X-WP-Nonce: $NONCE" -d '{"message":"Reply with OK"}' | grep -qiE '^cache-control:.*no-store' && pass "chat responses are no-store" || fail "no-store header"
setopt model_id 'us.amazon.no-such-model-v9:0'
code=$(chat '{"message":"hi"}')
check "upstream error returns 502" "$code" 502
if grep -qiE 'no-such-model|ValidationException|model identifier' /tmp/aicfab-body; then fail "AWS error details shown to a non-admin"; else pass "non-admin sees a generic error"; fi
setopt model_id 'us.amazon.nova-lite-v1:0'

echo; [ "$FAILS" -eq 0 ] && echo "ALL HTTP CHECKS PASSED" || echo "$FAILS HTTP CHECK(S) FAILED"
exit "$FAILS"
