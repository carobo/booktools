#!/bin/bash

EPUB=$(realpath "$1")
TMPDIR=$(mktemp -d)
ZIP="7z a -mx9"
QUALITY=8
cd "$TMPDIR" || exit 1

# Remove MP4 files
unzip -l "$EPUB" | awk '/\.mp4$/ { print $4 }' | xargs zip -d "$EPUB"

# Compress JPEG files
JPEGS=$(unzip -l "$EPUB" | awk 'NF == 4 && /\.[Jj][Pp][Ee]?[Gg]$/ { print $4 }')
if [ -n "$JPEGS" ]
then
    unzip "$EPUB" $JPEGS
    jpegoptim -s -m $QUALITY $JPEGS
    $ZIP "$EPUB" $JPEGS
    rm $JPEGS
fi

# Compress PNG files
PNGS=$(unzip -l "$EPUB" | awk 'NF == 4 && /\.[Pp][Nn][Gg]$/ { print $4 }')
if [ -n "$PNGS" ]
then
    unzip "$EPUB" $PNGS
    pngquant --strip --quality $QUALITY --ext .png --force $PNGS
    optipng -o2 $PNGS
    $ZIP "$EPUB" $PNGS
    rm $PNGS
fi

# Recompress large files
LARGE=$(unzip -l "$EPUB" | awk 'NF == 4 && $1 ~ /[0-9]/ && $1 > 10000 && /[^gG]$/ { print $4 }')
if [ -n "$LARGE" ]
then
    unzip "$EPUB" $LARGE
    $ZIP "$EPUB" $LARGE
fi

rm -r "$TMPDIR"
ls -lh "$EPUB"