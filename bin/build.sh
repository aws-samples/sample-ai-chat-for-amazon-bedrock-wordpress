#!/usr/bin/env bash
# Copyright Amazon.com, Inc. or its affiliates. All Rights Reserved.
# SPDX-License-Identifier: MIT-0
#
# Builds an installable plugin package: dist/ai-chat-for-amazon-bedrock.zip
# Installs the locked runtime dependencies (AWS SDK for PHP and its dependencies) without dev packages,
# keeps only the Bedrock Runtime and STS service models, and writes a third-party license summary.
#
#   bin/build.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SLUG=ai-chat-for-amazon-bedrock
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

git -C "$ROOT" archive HEAD "$SLUG" | tar -x -C "$WORK"
( cd "$WORK/$SLUG" && COMPOSER_ROOT_VERSION=1.0.0 composer install --no-dev --no-interaction --no-progress --prefer-dist --optimize-autoloader --classmap-authoritative )
( cd "$WORK/$SLUG" && COMPOSER_ROOT_VERSION=1.0.0 composer licenses --no-dev --format=text > THIRD-PARTY-LICENSES.txt )
rm -f "$WORK/$SLUG/composer.lock"

mkdir -p "$ROOT/dist"
rm -f "$ROOT/dist/$SLUG.zip"
( cd "$WORK" && zip -qr "$ROOT/dist/$SLUG.zip" "$SLUG" -x '*/.DS_Store' )
echo "Built $ROOT/dist/$SLUG.zip"
