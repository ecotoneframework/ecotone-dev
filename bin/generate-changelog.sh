#!/bin/bash
#
# Script to generate CHANGELOG.md from all GitHub releases
# Usage: ./bin/generate-changelog.sh
#
# This script fetches all releases from the ecotoneframework/ecotone-dev repository
# and generates a complete CHANGELOG.md file.
#
# Requirements:
# - GitHub CLI (gh) must be installed and authenticated
#

set -e

REPO="ecotoneframework/ecotone-dev"
OUTPUT_FILE="CHANGELOG.md"

echo "Fetching releases from $REPO..."

# Create the header
cat > "$OUTPUT_FILE" << 'EOF'
# Changelog

All notable changes to Ecotone will be documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

EOF

# Fetch all releases and process them
# The releases are already sorted by date (newest first) from the API
gh release list --repo "$REPO" --limit 500 | while IFS=$'\t' read -r tag type title date; do
    # Skip draft releases
    if [ "$type" = "Draft" ]; then
        continue
    fi
    
    echo "Processing release $tag..."
    
    # Get the release body
    RELEASE_BODY=$(gh release view "$tag" --repo "$REPO" --json body,createdAt -q '.body // "No release notes available"')
    RELEASE_DATE=$(gh release view "$tag" --repo "$REPO" --json createdAt -q '.createdAt' | cut -d'T' -f1)
    
    # Append to changelog
    {
        echo "## [$tag] - $RELEASE_DATE"
        echo ""
        echo "$RELEASE_BODY"
        echo ""
    } >> "$OUTPUT_FILE"
done

echo ""
echo "CHANGELOG.md has been generated successfully!"
echo "Total size: $(wc -l < "$OUTPUT_FILE") lines"

