#!/bin/bash

# Directory structure to search for PHP files
SEARCH_DIR="packages"

# Function to add or replace the license annotation
add_or_replace_license() {
    local file="$1"

    # Check if the file contains a class, interface, abstract class, or final class definition
    if grep -qE 'class |interface |abstract class |final class ' "$file"; then
        # Check if there is already a docblock above the class, interface, abstract class, or final class definition
        if grep -qE '/\*\*.*(class|interface|abstract class|final class) ' "$file"; then
            # Replace the existing docblock with the new one
            sed -i '/\/\*\*/,/\*\// {s/\(@licence .*\)/@licence Apache-2.0/;}' "$file"
        else
            # Add a new docblock above the class, interface, abstract class, or final class definition
            sed -i '/^\s*\(class\|interface\|abstract class\|final class\) / i\
/**\
 * licence Apache-2.0\
 */' "$file"
        fi
    fi
}

# Find all PHP files and apply the function
find "$SEARCH_DIR" -type f -name "*.php" | while read -r file; do
    add_or_replace_license "$file"
done

echo "License annotations added or replaced successfully."