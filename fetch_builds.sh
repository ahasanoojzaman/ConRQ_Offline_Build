#!/bin/bash

# Exit on any error
set -e

echo "🚀 Fetching latest CI/CD builds via GitHub CLI..."

# Explicitly set paths
PROJECT_DIR="/Users/ahsanoojzaman/Documents/Krenx-group/APPS-MY BUILD/Conrq/ConRQ_App_V1"
TARGET_DIR="/Users/ahsanoojzaman/Documents/Krenx-group/APPS-MY BUILD/Conrq"

cd "$PROJECT_DIR"

# Create a clean temporary directory for downloads
rm -rf temp_builds
mkdir -p temp_builds
cd temp_builds

echo "📥 Downloading Windows Executable artifact..."
# Ignore failure if the workflow hasn't finished yet
gh run download --name "ConRQ-Windows-Executable" || echo "⚠️ Warning: Could not download Windows artifact. It may still be building or hasn't run."

echo "📥 Downloading macOS DMG artifact..."
gh run download --name "ConRQ-macOS-DMG" || echo "⚠️ Warning: Could not download macOS artifact. It may still be building or hasn't run."

echo "📦 Moving binaries to the target directory: $TARGET_DIR"

# Move Windows .exe if it exists
if [ -f "ConRQ-Windows-Executable/ConRQ_App.exe" ]; then
    mv "ConRQ-Windows-Executable/ConRQ_App.exe" "$TARGET_DIR/"
    echo "✅ Successfully moved ConRQ_App.exe"
fi

# Move macOS .dmg if it exists (Electron builder dynamically names it, so we use a wildcard)
if [ -d "ConRQ-macOS-DMG" ]; then
    find "ConRQ-macOS-DMG" -type f -name "*.dmg" -exec mv {} "$TARGET_DIR/" \;
    echo "✅ Successfully moved macOS .dmg bundle"
fi

echo "🧹 Cleaning up temporary artifact files..."
cd "$PROJECT_DIR"
rm -rf temp_builds

echo "🎉 Fetch process complete!"
echo "You can view your compiled binaries in: $TARGET_DIR"
