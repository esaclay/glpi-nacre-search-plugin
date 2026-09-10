#!/usr/bin/env python3
"""Genere le logo 'commandes.lps' (variante B) avec le texte converti en traces vectoriels."""
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen

ARIAL = r"C:/Windows/Fonts/arial.ttf"
ARIALBD = r"C:/Windows/Fonts/arialbd.ttf"

def runs_to_paths(runs, font_size, x0, baseline):
    """runs = [(text, ttf_path, color)] -> liste de <path> SVG, + largeur totale."""
    out = []
    x = x0
    for text, ttf_path, color in runs:
        font = TTFont(ttf_path)
        upm = font["head"].unitsPerEm
        scale = font_size / upm
        cmap = font.getBestCmap()
        glyphset = font.getGlyphSet()
        hmtx = font["hmtx"]
        for ch in text:
            gname = cmap.get(ord(ch))
            if gname is None:
                continue
            pen = SVGPathPen(glyphset)
            glyphset[gname].draw(pen)
            d = pen.getCommands()
            adv = hmtx[gname][0]
            if d:
                out.append(
                    f'<path transform="translate({x:.2f} {baseline:.2f}) scale({scale:.5f} {-scale:.5f})" '
                    f'fill="{color}" d="{d}"/>'
                )
            x += adv * scale
    return out, x - x0

# --- parametres ---
FS = 30
BLUE = "#1f3a56"
ORANGE = "#e8833a"
GREY = "#5b7285"
WHITE = "#ffffff"
WHITE_DIM = "#ffffff"

def make(fname, cart_color, c_color, dot_color, lps_color, lps_opacity=1.0):
    text_x0 = 46
    baseline = 38
    runs = [
        ("commandes", ARIALBD, c_color),
        (".", ARIALBD, dot_color),
        ("lps", ARIAL, lps_color),
    ]
    paths, text_w = runs_to_paths(runs, FS, text_x0, baseline)
    # opacite eventuelle sur "lps" : on l'enveloppe apres coup
    if lps_opacity != 1.0:
        # les 3 derniers paths (l,p,s) recoivent l'opacite
        paths = paths[:-3] + [p.replace('/>', f' fill-opacity="{lps_opacity}"/>') for p in paths[-3:]]
    w = round(text_x0 + text_w + 4)
    h = 52
    cart = (
        f'<g transform="translate(4,11)" fill="none" stroke="{cart_color}" stroke-width="3.4" '
        f'stroke-linecap="round" stroke-linejoin="round">'
        f'<path d="M2 3 h5 l4.5 20 h15 l4-14 H10"/>'
        f'<circle cx="13" cy="30" r="2.6" fill="{cart_color}" stroke="none"/>'
        f'<circle cx="26" cy="30" r="2.6" fill="{cart_color}" stroke="none"/></g>'
    )
    svg = (
        f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 6 {w:.0f} 40" '
        f'role="img" aria-label="commandes.lps">\n  {cart}\n  '
        + "\n  ".join(paths)
        + "\n</svg>\n"
    )
    with open(fname, "w", encoding="utf-8") as fh:
        fh.write(svg)
    print(f"{fname}  ({w:.0f}x{h})")

OUT = r"C:/Users/saen/AppData/Local/Temp/claude/C--Users-saen-Documents-glpi-nacre-search-plugin/4358b86f-67f1-4d7c-9349-1b98c32477a5/scratchpad/"
make(OUT + "commandes-lps.svg",        BLUE,  BLUE,  ORANGE, GREY)
make(OUT + "commandes-lps-blanc.svg",  WHITE, WHITE, ORANGE, WHITE, lps_opacity=0.7)
