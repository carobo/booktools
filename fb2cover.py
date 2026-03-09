#!/usr/bin/env -S uv run

# /// script
# dependencies = ["fb2reader"]
# ///

import sys
from fb2reader import fb2book
from os.path import dirname

basedir = dirname(sys.argv[1])
book = fb2book(sys.argv[1])

# Check if book has a cover
cover = book.get_cover_image()
if cover:
    # Save cover image
    result = book.save_cover_image(output_dir=dirname(basedir))
    if result:
        image_name, image_type = result
        print(f"{basedir}/{image_name}.{image_type}")
