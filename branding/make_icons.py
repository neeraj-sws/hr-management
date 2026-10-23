"""Builds every app icon from the company logo (branding/sws-logo.png).

    python branding/make_icons.py      (needs Pillow: pip install pillow)

Run it again after replacing sws-logo.png. It writes:
  mobile/assets/      icon.png (iOS / store, full square), android-icon-foreground/background/monochrome.png
                      (Android adaptive icon), splash-icon.png, favicon.png
  python/sws_desktop/assets/  icon.ico (exe, installer, window), icon.png (tray, window)
  public/             favicon.ico, apple-touch-icon.png, images/sws-logo.png (website)
"""
from __future__ import annotations

from pathlib import Path

from PIL import Image, ImageChops, ImageDraw

ROOT = Path(__file__).resolve().parent.parent
SOURCE = ROOT / "branding" / "sws-logo.png"
TOP, BOTTOM = (4, 98, 148), (0, 46, 80)  # the logo's blue, top to bottom


def gradient(size: int) -> Image.Image:
    img = Image.new("RGBA", (size, size))
    draw = ImageDraw.Draw(img)
    for y in range(size):
        t = y / max(1, size - 1)
        draw.line([(0, y), (size, y)], fill=tuple(round(a + (b - a) * t) for a, b in zip(TOP, BOTTOM)) + (255,))
    return img


def tile() -> Image.Image:
    """The rounded-square logo, cropped to its edges and made square (transparent corners kept)."""
    logo = Image.open(SOURCE).convert("RGBA")
    box = logo.split()[3].point(lambda a: 255 if a > 128 else 0).getbbox()
    logo = logo.crop(box)
    side = max(logo.size)
    square = Image.new("RGBA", (side, side), (0, 0, 0, 0))
    square.paste(logo, ((side - logo.width) // 2, (side - logo.height) // 2))
    return square


def full_square(t: Image.Image, size: int) -> Image.Image:
    """No transparent corners (iOS and app stores round the icon themselves)."""
    img = gradient(t.width)
    img.alpha_composite(t)
    return img.resize((size, size), Image.LANCZOS)


def emblem(t: Image.Image) -> Image.Image:
    """Only the white / light-blue drawing, on transparent: for the Android adaptive icon."""
    bg = full_square(t, t.width).convert("RGB")
    grad = gradient(t.width).convert("RGB")
    px, gp = bg.load(), grad.load()
    out = Image.new("RGBA", bg.size)
    op = out.load()
    for y in range(bg.height):
        for x in range(bg.width):
            p, g = px[x, y], gp[x, y]
            a = max(max(0, p[i] - g[i]) / max(1, 255 - g[i]) for i in range(3))
            a = 0 if a < 0.12 else min(1.0, a)
            if a:
                op[x, y] = tuple(min(255, round(g[i] + (p[i] - g[i]) / a)) for i in range(3)) + (round(a * 255),)
    # Keep the drawing only (not the soft edge of the rounded square).
    inset = round(t.width * 0.09)
    mask = Image.new("L", out.size, 0)
    ImageDraw.Draw(mask).rectangle((inset, inset, out.width - inset, out.height - inset), fill=255)
    out.putalpha(ImageChops.multiply(out.split()[3], mask))
    return out.crop(out.split()[3].getbbox())


def centered(img: Image.Image, size: int, share: float) -> Image.Image:
    """img scaled to `share` of a transparent size x size canvas, centred."""
    scale = share * size / max(img.size)
    small = img.resize((max(1, round(img.width * scale)), max(1, round(img.height * scale))), Image.LANCZOS)
    canvas = Image.new("RGBA", (size, size), (0, 0, 0, 0))
    canvas.alpha_composite(small, ((size - small.width) // 2, (size - small.height) // 2))
    return canvas


def main() -> None:
    t = tile()
    mobile, desk, web = ROOT / "mobile" / "assets", ROOT / "python" / "sws_desktop" / "assets", ROOT / "public"
    desk.mkdir(parents=True, exist_ok=True)
    (web / "images").mkdir(parents=True, exist_ok=True)

    full_square(t, 1024).convert("RGB").save(mobile / "icon.png", optimize=True)
    e = emblem(t)
    centered(e, 1024, 0.56).save(mobile / "android-icon-foreground.png", optimize=True)  # inside Android's safe zone
    gradient(1024).save(mobile / "android-icon-background.png", optimize=True)
    mono = Image.new("RGBA", e.size, (255, 255, 255, 255))
    mono.putalpha(e.split()[3])
    centered(mono, 1024, 0.56).save(mobile / "android-icon-monochrome.png", optimize=True)
    centered(t, 1024, 0.8).save(mobile / "splash-icon.png", optimize=True)
    t.resize((48, 48), Image.LANCZOS).save(mobile / "favicon.png", optimize=True)

    t.resize((256, 256), Image.LANCZOS).save(desk / "icon.png", optimize=True)
    t.resize((256, 256), Image.LANCZOS).save(desk / "icon.ico", sizes=[(16, 16), (24, 24), (32, 32), (48, 48), (64, 64), (128, 128), (256, 256)])

    t.resize((48, 48), Image.LANCZOS).save(web / "favicon.ico", sizes=[(16, 16), (32, 32), (48, 48)])
    full_square(t, 180).convert("RGB").save(web / "apple-touch-icon.png", optimize=True)
    t.resize((192, 192), Image.LANCZOS).save(web / "images" / "sws-logo.png", optimize=True)
    t.resize((512, 512), Image.LANCZOS).save(web / "images" / "sws-logo-512.png", optimize=True)
    print("Icons written.")


if __name__ == "__main__":
    main()
