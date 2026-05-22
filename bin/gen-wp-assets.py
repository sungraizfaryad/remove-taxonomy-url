#!/usr/bin/env python3
"""
Generate WP.org plugin assets via Gemini Imagen 4 Fast.

Outputs (saved to .wordpress-org/):
  icon-256x256.png      — 1:1 plugin icon (also serves as 128x128 source)
  icon-128x128.png      — downscaled from the 256
  banner-1544x500.png   — 16:9 generation cropped to 1544x500
  banner-772x250.png    — downscaled from the 1544

Usage:
  GEMINI_API_KEY=... python3 bin/gen-wp-assets.py [--only icon|banner]

Cost:
  2 sync Imagen 4 Fast calls × $0.02 = $0.04.
"""
from __future__ import annotations

import argparse
import io
import os
import sys
from pathlib import Path

try:
    from google import genai
    from google.genai import types
except ImportError:
    sys.exit("Missing dependency: pip3 install --break-system-packages google-genai Pillow")

try:
    from PIL import Image
except ImportError:
    sys.exit("Missing dependency: pip3 install --break-system-packages Pillow")


PLUGIN_DIR = Path(__file__).resolve().parent.parent
ASSETS_DIR = PLUGIN_DIR / ".wordpress-org"

ICON_PROMPT = (
    "Square WordPress plugin icon, 1:1 aspect ratio, ENTIRELY filled with a "
    "vibrant teal-to-deep-blue diagonal gradient background covering 100% of "
    "the canvas edge to edge. Centered on top of this gradient: a single bold "
    "white minimalist scissors cutting a URL path symbol /a/b/ also rendered "
    "in white with high contrast. Flat geometric design. No transparent areas. "
    "No white background. No text. No realistic textures. Looks like a polished "
    "app icon. The gradient must be the dominant visual."
)

BANNER_PROMPT = (
    "Wide horizontal banner for a WordPress plugin called Remove Taxonomy URL. "
    "Soft teal-to-deep-blue diagonal gradient background. Left third: large flat "
    "illustration of a URL path /category/term/ being trimmed by a friendly minimalist "
    "scissors icon, with the redundant /category/ slug fading away. Right two thirds: "
    "negative space for plugin name text overlay (do not include text, just clean space). "
    "Geometric flat design, no photo, no realistic faces, no real brand logos. "
    "Composition leaves room for headline placement on the right. "
    "16:9 aspect ratio, high resolution, clean and professional."
)


def generate_image(client: genai.Client, prompt: str, aspect_ratio: str) -> bytes:
    response = client.models.generate_images(
        model="imagen-4.0-fast-generate-001",
        prompt=prompt,
        config=types.GenerateImagesConfig(
            number_of_images=1,
            aspect_ratio=aspect_ratio,
        ),
    )
    if not response.generated_images:
        sys.exit("Empty response from Gemini.")
    return response.generated_images[0].image.image_bytes


def crop_watermark(img: Image.Image, fraction: float = 0.93) -> Image.Image:
    """Trim Gemini's bottom watermark stamp."""
    w, h = img.size
    return img.crop((0, 0, w, int(h * fraction)))


def make_icon(client: genai.Client) -> None:
    print("[icon] generating 1:1 at ~1024x1024…")
    raw = generate_image(client, ICON_PROMPT, "1:1")
    img = Image.open(io.BytesIO(raw)).convert("RGB")
    img = crop_watermark(img)
    # Square-crop the post-watermark result.
    side = min(img.size)
    left = (img.width - side) // 2
    top = (img.height - side) // 2
    img = img.crop((left, top, left + side, top + side))

    icon_256 = img.resize((256, 256), Image.LANCZOS)
    icon_128 = img.resize((128, 128), Image.LANCZOS)
    out_256 = ASSETS_DIR / "icon-256x256.png"
    out_128 = ASSETS_DIR / "icon-128x128.png"
    icon_256.save(out_256, format="PNG", optimize=True)
    icon_128.save(out_128, format="PNG", optimize=True)
    print(f"[icon] saved {out_256.relative_to(PLUGIN_DIR)} + {out_128.relative_to(PLUGIN_DIR)}")


def make_banner(client: genai.Client) -> None:
    print("[banner] generating 16:9 at ~1456x816…")
    raw = generate_image(client, BANNER_PROMPT, "16:9")
    img = Image.open(io.BytesIO(raw)).convert("RGB")
    img = crop_watermark(img)
    # Target ratio is 1544:500 = 3.088:1. Crop horizontally centered.
    target_ratio = 1544 / 500
    img_ratio = img.width / img.height
    if img_ratio > target_ratio:
        # Image is wider than target — keep full height, crop sides.
        new_w = int(img.height * target_ratio)
        left = (img.width - new_w) // 2
        img = img.crop((left, 0, left + new_w, img.height))
    else:
        # Image is taller than target — keep full width, crop top+bottom.
        new_h = int(img.width / target_ratio)
        top = (img.height - new_h) // 2
        img = img.crop((0, top, img.width, top + new_h))

    banner_1544 = img.resize((1544, 500), Image.LANCZOS)
    banner_772 = img.resize((772, 250), Image.LANCZOS)
    out_1544 = ASSETS_DIR / "banner-1544x500.png"
    out_772 = ASSETS_DIR / "banner-772x250.png"
    banner_1544.save(out_1544, format="PNG", optimize=True)
    banner_772.save(out_772, format="PNG", optimize=True)
    print(f"[banner] saved {out_1544.relative_to(PLUGIN_DIR)} + {out_772.relative_to(PLUGIN_DIR)}")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--only", choices=["icon", "banner"], help="Generate just one asset.")
    args = parser.parse_args()

    api_key = os.environ.get("GEMINI_API_KEY")
    if not api_key:
        sys.exit("GEMINI_API_KEY not set.")

    ASSETS_DIR.mkdir(exist_ok=True)
    client = genai.Client(api_key=api_key)

    if args.only != "banner":
        make_icon(client)
    if args.only != "icon":
        make_banner(client)

    print("\nDone. Files in .wordpress-org/ — these go to WP.org SVN /assets/ on release.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
