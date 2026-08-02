#!/usr/bin/env bash
#
# Build the Control Panel bundle and refuse an empty or unsafe result.
#
# The bundle is not committed: it reaches consumers through the GitHub release
# (`extra.download-dist` in composer.json, built by
# .github/workflows/release-dist.yml). This script is what both that workflow
# and a developer run, so the two cannot drift.

set -euo pipefail

cd "$(dirname "$0")/.."

npm run build

if [ ! -f resources/dist/build/manifest.json ]; then
    echo "No manifest at resources/dist/build/manifest.json — the build produced nothing."
    exit 1
fi

if [ -f resources/dist/hot ]; then
    echo "A hot file is present. It must never ship: it points an installing site's CP at a dev server."
    exit 1
fi

echo "Control Panel bundle built."
