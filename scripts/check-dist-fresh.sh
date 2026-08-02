#!/usr/bin/env bash
#
# Rebuild the Control Panel bundle and fail if the result differs from what is
# committed.
#
# Consumers install this addon with Composer and have no Node toolchain, so
# `resources/dist` is what actually reaches their browser. A source change
# committed without a rebuild passes every other job in CI and ships a stale
# bundle — silently, because nothing errors: the old JavaScript simply keeps
# running.

set -euo pipefail

cd "$(dirname "$0")/.."

npm run build

if ! git diff --quiet -- resources/dist; then
    echo
    echo "resources/dist is out of date. Run 'npm run build' and commit the result."
    echo
    git --no-pager diff --stat -- resources/dist
    exit 1
fi

echo "resources/dist is current."
