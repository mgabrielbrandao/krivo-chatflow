#!/usr/bin/env python3
"""Generate the PROVISIONAL Krivo ChatFlow logo set.

Concept: a chat bubble whose negative space forms a K. The left edge of the K stem is
the same vertical line as the left edge of the bubble tail, so the stem "continues"
into the tail. Flat colour, no gradients, one compound path per file (works as a CSS
mask, as a WordPress admin-menu icon and in a single colour).

Two optical sizes, as in any logo system:
  * main  - for 32 px and above
  * small - for 24 px and below: bolder, taller K so stem, arm and leg stay distinct

This is a stand-in until the official Krivo symbol is supplied. It is an original
drawing. The only third-party artwork is the wordmark, outlined from Liberation Sans
(SIL Open Font License 1.1) so the SVG does not depend on installed fonts.

Usage (from the repository root):
    python3 -m venv .venv && .venv/bin/pip install shapely fonttools pillow
    .venv/bin/python brand/tools/build_logo.py build      # writes brand/*.svg
    .venv/bin/python brand/tools/build_logo.py preview    # writes brand/previews and brand/export (needs Chromium)

Development tool only: it is never shipped inside the plugin ZIP.
"""
import os
import subprocess
import sys

from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from shapely.geometry import LineString
from shapely.ops import unary_union

HERE = os.path.dirname(os.path.abspath(__file__))
BRAND = os.path.dirname(HERE)

# --- Krivo palette (docs/04-frontend-admin-api.md, section 4.4) ------------------------
PRIMARY = "#7B2FF7"   # 5.85:1 on white, AA-safe for graphics and text
TEXT = "#171519"
WHITE = "#FFFFFF"

# --- Construction grid: 96 x 96 box ------------------------------------------------------
BODY_L, BODY_R, BODY_T, BODY_B, RADIUS = 9.5, 88.0, 8.0, 74.0, 22.0
TAIL_X, TAIL_TIP_Y = 31.5, 92.0            # tail's vertical edge == K stem left edge (main)
SHIFT_X = 48.0 - (BODY_L + BODY_R) / 2     # bounding box centred in the 96 box
SHIFT_Y = 48.0 - (BODY_T + TAIL_TIP_Y) / 2

# K geometry per optical size: stroke width, stem y0..y1, join y on the stem, arm/leg end points.
K_MAIN = dict(stroke=9.0, y0=24.0, y1=58.0, jy=41.0, arm=(60.0, 22.0), leg=(60.0, 60.0))
K_SMALL = dict(stroke=12.0, y0=20.0, y1=62.0, jy=41.0, arm=(63.0, 19.0), leg=(63.0, 63.0))

FONT_DIR = "/usr/share/fonts/truetype/liberation"
CHROMIUM = os.environ.get("CHROMIUM", "/opt/pw-browsers/chromium-1194/chrome-linux/chrome")
SMALL_MAX_PX = 24                           # use the small optical size up to this rendered size


def fmt(v):
    s = f"{v:.2f}".rstrip("0").rstrip(".")
    return "0" if s == "-0" else s


def bubble_path():
    """Bubble body + tail as one outline (true arcs, one quadratic curve for the tail)."""
    r, dx, dy = RADIUS, SHIFT_X, SHIFT_Y
    L, R, T, B = BODY_L + dx, BODY_R + dx, BODY_T + dy, BODY_B + dy
    tx, ty = TAIL_X + dx, TAIL_TIP_Y + dy
    return (
        f"M{fmt(tx)} {fmt(T)}H{fmt(R - r)}A{fmt(r)} {fmt(r)} 0 0 1 {fmt(R)} {fmt(T + r)}"
        f"V{fmt(B - r)}A{fmt(r)} {fmt(r)} 0 0 1 {fmt(R - r)} {fmt(B)}"
        f"H{fmt(52 + dx)}Q{fmt(44 + dx)} {fmt(81 + dy)} {fmt(tx)} {fmt(ty)}"
        f"V{fmt(B)}A{fmt(r)} {fmt(r)} 0 0 1 {fmt(L)} {fmt(B - r)}"
        f"V{fmt(T + r)}A{fmt(r)} {fmt(r)} 0 0 1 {fmt(tx)} {fmt(T)}Z"
    )


def k_path(k):
    """The K as one polygon: a stem plus a chevron (arm and leg) that meet on the stem."""
    r = k["stroke"] / 2
    stem_x = TAIL_X + r
    strokes = [
        LineString([(stem_x, k["y0"]), (stem_x, k["y1"])]),
        LineString([(stem_x, k["jy"]), k["arm"]]),
        LineString([(stem_x, k["jy"]), k["leg"]]),
    ]
    poly = unary_union([s.buffer(r, cap_style=1, join_style=1, resolution=24) for s in strokes])
    poly = poly.simplify(0.01, preserve_topology=True)
    pts = [(x + SHIFT_X, y + SHIFT_Y) for x, y in list(poly.exterior.coords)[:-1]]
    return "M" + "L".join(f"{fmt(x)} {fmt(y)}" for x, y in pts) + "Z"


def symbol_d(small=False):
    return bubble_path() + k_path(K_SMALL if small else K_MAIN)


def svg_symbol(fill, small=False, root_fill=None):
    paint = f' fill="{root_fill}"' if root_fill else ""
    path_fill = "" if root_fill else f' fill="{fill}"'
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" width="96" height="96"'
        f'{paint} role="img" aria-labelledby="t"><title id="t">Krivo ChatFlow</title>'
        f'<path{path_fill} fill-rule="evenodd" d="{symbol_d(small)}"/></svg>\n'
    )


def svg_menu_icon():
    """WordPress admin menu icon (20 px, small optical size). Root fill = WP core icon grey so the
    admin colour scheme can repaint it. No <title>: the menu item already has a text label."""
    return (
        '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96" width="20" height="20" fill="#a7aaad">'
        f'<path fill-rule="evenodd" d="{symbol_d(small=True)}"/></svg>\n'
    )


def svg_icon(tile=PRIMARY):
    """App icon (plugin icon): purple rounded tile, white bubble, K shows the tile colour."""
    scale, size = 1.8, 256
    off = (size - 96 * scale) / 2
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {size} {size}" width="{size}" height="{size}" '
        'role="img" aria-labelledby="t"><title id="t">Krivo ChatFlow</title>'
        f'<rect width="{size}" height="{size}" rx="58" fill="{tile}"/>'
        f'<path fill="{WHITE}" fill-rule="evenodd" transform="translate({fmt(off)} {fmt(off)}) scale({scale})" '
        f'd="{symbol_d()}"/></svg>\n'
    )


def outline_text(text, font_file, size, x, baseline, tracking=0.0):
    font = TTFont(os.path.join(FONT_DIR, font_file))
    glyphs, cmap, upm = font.getGlyphSet(), font.getBestCmap(), font["head"].unitsPerEm
    scale, cursor, parts = size / upm, x, []
    for ch in text:
        name = cmap[ord(ch)]
        pen = SVGPathPen(glyphs, ntos=lambda v: fmt(v))
        glyphs[name].draw(TransformPen(pen, (scale, 0, 0, -scale, cursor, baseline)))
        d = pen.getCommands()
        if d:
            parts.append(d)
        cursor += glyphs[name].width * scale + tracking * size
    return "".join(parts), cursor


def svg_lockup(dark=False):
    sym, txt = (WHITE, WHITE) if dark else (PRIMARY, TEXT)
    size, baseline, x0 = 46.0, 56.0, 110.0
    krivo, x1 = outline_text("Krivo", "LiberationSans-Bold.ttf", size, x0, baseline, tracking=-0.012)
    chat, x2 = outline_text("ChatFlow", "LiberationSans-Regular.ttf", size, x1 + size * 0.26, baseline, tracking=-0.012)
    width = int(x2 + 8)
    return (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {width} 96" width="{width}" height="96" '
        'role="img" aria-labelledby="t"><title id="t">Krivo ChatFlow</title>'
        f'<path fill="{sym}" fill-rule="evenodd" d="{symbol_d()}"/>'
        f'<path fill="{txt}" d="{krivo}{chat}"/></svg>\n'
    )


FILES = {
    "krivo-chatflow-symbol.svg": lambda: svg_symbol(PRIMARY),
    "krivo-chatflow-symbol-small.svg": lambda: svg_symbol(PRIMARY, small=True),
    "krivo-chatflow-symbol-white.svg": lambda: svg_symbol(WHITE),
    "krivo-chatflow-symbol-white-small.svg": lambda: svg_symbol(WHITE, small=True),
    "krivo-chatflow-symbol-mono.svg": lambda: svg_symbol(None, root_fill="currentColor"),
    "krivo-chatflow-symbol-mono-small.svg": lambda: svg_symbol(None, small=True, root_fill="currentColor"),
    "krivo-chatflow-icon.svg": svg_icon,
    "krivo-chatflow-logo.svg": lambda: svg_lockup(False),
    "krivo-chatflow-logo-white.svg": lambda: svg_lockup(True),
    "krivo-chatflow-menu-icon.svg": svg_menu_icon,
}


def build():
    for name, make in FILES.items():
        data = make()
        with open(os.path.join(BRAND, name), "w", encoding="utf-8") as fh:
            fh.write(data)
        print(f"{name:42s} {len(data):6d} bytes")


# ----------------------------------------------------------------------------------------
# Previews (Chromium headless)
# ----------------------------------------------------------------------------------------
def shot(html_path, png_path, w, h, transparent=False):
    """Screenshot an HTML page and crop to exactly w x h.

    Headless Chromium ("new" mode) loses ~90px of viewport height to browser chrome and
    enforces a minimum width, so the window is oversized and the result cropped."""
    from PIL import Image

    cmd = [CHROMIUM, "--headless=new", "--no-sandbox", "--disable-gpu", "--hide-scrollbars",
           "--force-device-scale-factor=1", f"--window-size={max(w, 600)},{h + 160}", f"--screenshot={png_path}"]
    if transparent:
        cmd.append("--default-background-color=00000000")
    subprocess.run(cmd + [f"file://{html_path}"], check=True, capture_output=True)
    Image.open(png_path).convert("RGBA" if transparent else "RGB").crop((0, 0, w, h)).save(png_path)


def src(name):
    return os.path.join(BRAND, name)


def pick(main, small, px):
    """Optical-size rule: the small drawing up to SMALL_MAX_PX, the main drawing above it."""
    return small if px <= SMALL_MAX_PX else main


def preview():
    from PIL import Image

    out, exp = os.path.join(BRAND, "previews"), os.path.join(BRAND, "export")
    os.makedirs(out, exist_ok=True)
    os.makedirs(exp, exist_ok=True)
    sizes = [16, 24, 32, 64, 128, 512]

    # 1) Contact sheet at the real pixel sizes, optical-size rule applied.
    rows = [("#FFFFFF", "krivo-chatflow-symbol", "#171519", "colour"),
            (TEXT, "krivo-chatflow-symbol-white", "#FFFFFF", "white"),
            ("#F7F4FC", "krivo-chatflow-symbol-mono", "#171519", "mono")]
    html = ['<html><body style="margin:0;font:12px/1 sans-serif">']
    for bg, base, fg, label in rows:
        html.append(f'<div style="background:{bg};color:{fg};padding:16px 20px;display:flex;align-items:flex-end;gap:28px">'
                    f'<span style="width:48px">{label}</span>')
        for s in sizes:
            f = src(pick(base + ".svg", base + "-small.svg", s))
            html.append('<span style="display:flex;flex-direction:column;align-items:center;gap:6px">'
                        f'<img src="file://{f}" width="{s}" height="{s}"><i style="font-style:normal;opacity:.7">{s}</i></span>')
        html.append("</div>")
    html.append("</body></html>")
    page = os.path.join(out, "_sheet.html")
    open(page, "w").write("".join(html))
    shot(page, os.path.join(out, "size-test.png"), 1000, 3 * 562)
    os.remove(page)

    # 2) Real-pixel renders at 16/20/24 (small) and 32 (main), magnified with nearest neighbour.
    #    Left column: the main drawing at the same sizes, to show why the small drawing exists.
    cases = [("krivo-chatflow-symbol.svg", s) for s in (16, 20, 24)] + \
            [("krivo-chatflow-symbol-small.svg", s) for s in (16, 20, 24)] + [("krivo-chatflow-symbol.svg", 32)]
    page = os.path.join(out, "_zoom.html")
    open(page, "w").write('<html><body style="margin:0;background:#fff">' + "".join(
        f'<img src="file://{src(f)}" width="{s}" height="{s}" style="display:block">' for f, s in cases) + "</body></html>")
    raw = os.path.join(out, "_zoom.png")
    shot(page, raw, 600, sum(s for _, s in cases))
    z, y, tiles = Image.open(raw).convert("RGB"), 0, []
    for _, s in cases:
        tiles.append(z.crop((0, y, s, y + s)).resize((s * 8, s * 8), Image.NEAREST))
        y += s
    pad, cols = 16, [tiles[0:3], tiles[3:6], tiles[6:7]]
    width = sum(max(t.width for t in c) + pad for c in cols) + pad
    height = max(sum(t.height + pad for t in c) for c in cols) + pad
    canvas = Image.new("RGB", (width, height), "#ffffff")
    x = pad
    for c in cols:
        yy = pad
        for t in c:
            canvas.paste(t, (x, yy))
            yy += t.height + pad
        x += max(t.width for t in c) + pad
    canvas.save(os.path.join(out, "small-sizes-zoom.png"))
    os.remove(page)
    os.remove(raw)

    # 3) Lockups on light and dark.
    page = os.path.join(out, "_lock.html")
    open(page, "w").write(
        '<html><body style="margin:0"><div style="background:#F7F4FC;padding:40px 48px">'
        f'<img src="file://{src("krivo-chatflow-logo.svg")}" height="96"></div>'
        '<div style="background:#171519;padding:40px 48px">'
        f'<img src="file://{src("krivo-chatflow-logo-white.svg")}" height="96"></div></body></html>')
    shot(page, os.path.join(out, "logo-lockup.png"), 560, 352)
    os.remove(page)

    # 4) Exports: WordPress.org plugin icon sizes and a 512 px symbol (transparent).
    for s in (128, 256):
        page = os.path.join(out, "_icon.html")
        open(page, "w").write('<html><body style="margin:0;background:transparent">'
                              f'<img src="file://{src("krivo-chatflow-icon.svg")}" width="{s}" height="{s}" style="display:block"></body></html>')
        shot(page, os.path.join(exp, f"icon-{s}x{s}.png"), s, s, transparent=True)
        os.remove(page)
    page = os.path.join(out, "_sym.html")
    open(page, "w").write('<html><body style="margin:0;background:transparent">'
                          f'<img src="file://{src("krivo-chatflow-symbol.svg")}" width="512" height="512" style="display:block"></body></html>')
    shot(page, os.path.join(exp, "symbol-512.png"), 512, 512, transparent=True)
    os.remove(page)
    # 5) The WordPress admin menu icon (20 px) in the admin's real colours: idle, hover, current.
    #    The SVG is inlined (CSS masks and <img> from file:// are blocked as cross-origin).
    menu_svg = open(src("krivo-chatflow-menu-icon.svg"), encoding="utf-8").read()
    rows = [("#a7aaad", "Normal"), ("#72aee6", "Hover"), ("#ffffff", "Current")]
    items = "".join(
        f'<div style="display:flex;align-items:center;gap:8px;padding:8px 12px;color:{c};font:13px/1.4 sans-serif">'
        f'{menu_svg.replace("#a7aaad", c)}Krivo ChatFlow <i style="opacity:.5;font-style:normal;margin-left:auto">{n}</i></div>'
        for c, n in rows)
    page = os.path.join(out, "_menu.html")
    open(page, "w").write(f'<html><body style="margin:0;background:#1d2327"><div style="width:200px">{items}</div></body></html>')
    shot(page, os.path.join(out, "admin-menu-20px.png"), 200, 3 * 40)
    os.remove(page)
    print("previews/ and export/ written")


if __name__ == "__main__":
    {"build": build, "preview": preview}[sys.argv[1] if len(sys.argv) > 1 else "build"]()
