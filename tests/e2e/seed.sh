#!/usr/bin/env bash
# Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
# SPDX-License-Identifier: MIT-0
#
# Seeds a DISPOSABLE WordPress test site for the end-to-end checks: a chat page, one public post and
# three non-public posts (draft, private, password-protected) whose codenames must never reach the model,
# plus a subscriber account. Do not run it against a real site; run-http.sh also changes plugin settings.
#
#   WP="wp --path=/path/to/wordpress" SUB_PASS=... tests/e2e/seed.sh
set -euo pipefail
: "${WP:?}" "${SUB_PASS:?}"

$WP plugin activate ai-chat-for-amazon-bedrock --quiet
$WP post create --post_type=page --post_status=publish --post_title='Chat' --post_content='[ai_chat_bedrock]' --porcelain
$WP post create --post_status=publish --post_title='Opening hours' \
  --post_content='Our shop on Harbour Street is open Monday to Friday from 9:00 to 17:30. The secret word for public visitors is PELICAN.' --quiet
$WP post create --post_status=draft --post_title='Draft launch plan' --post_content='Unannounced product codename ZEBRA-DRAFT-42.' --quiet
$WP post create --post_status=private --post_title='Private memo' --post_content='Internal codename OTTER-PRIVATE-7.' --quiet
$WP post create --post_status=publish --post_password=pw --post_title='Protected note' --post_content='Protected codename HERON-PW-9.' --quiet
$WP user create sub sub@example.com --role=subscriber --user_pass="$SUB_PASS" --quiet
echo "Seeded. The number printed above is the chat page ID (PAGE_ID for browser-check.mjs)."
