#!/usr/bin/env python3
"""Genere des lockups 'commandes.lps' adaptes aux 3 emplacements de logo de GLPI 11."""
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen

ARIAL = r"C:/Windows/Fonts/arial.ttf"
ARIALBD = r"C:/Windows/Fonts/arialbd.ttf"
_cache = {}

def _font(p):
    if p not in _cache:
        f = TTFont(p)
        _cache[p] = (f, f["head"].unitsPerEm, f.getBestCmap(), f.getGlyphSet(), f["hmtx"])
    return _cache[p]

def text_width(text, ttf, fs):
    f, upm, cmap, gs, hmtx = _font(ttf)
    return sum(hmtx[cmap[ord(c)]][0] for c in text if ord(c) in cmap) * fs / upm

def text_paths(text, ttf, fs, x, baseline, color, opacity=1.0):
    f, upm, cmap, gs, hmtx = _font(ttf)
    sc = fs / upm
    out = []
    for c in text:
        g = cmap.get(ord(c))
        if g is None:
            continue
        pen = SVGPathPen(gs)
        gs[g].draw(pen)
        d = pen.getCommands()
        if d:
            op = f' fill-opacity="{opacity}"' if opacity != 1.0 else ""
            out.append(f'<path transform="translate({x:.2f} {baseline:.2f}) scale({sc:.5f} {-sc:.5f})" fill="{color}"{op} d="{d}"/>')
        x += hmtx[g][0] * sc
    return out, x

def cart(x, y, s, color, sw):
    return (f'<g transform="translate({x} {y}) scale({s})" fill="none" stroke="{color}" '
            f'stroke-width="{sw}" stroke-linecap="round" stroke-linejoin="round">'
            f'<path d="M2 3 h5 l4.5 20 h15 l4-14 H10"/>'
            f'<circle cx="13" cy="30" r="2.6" fill="{color}" stroke="none"/>'
            f'<circle cx="26" cy="30" r="2.6" fill="{color}" stroke="none"/></g>')

BLUE, ORANGE, GREY = "#1f3a56", "#e8833a", "#5b7285"

def lockup(fname, W, H, main_color, lps_color, dot_color, cart_color, lps_op=1.0, with_cart=True):
    """Lockup 2 lignes centrees : 'commandes' (grand) / [panier] .lps (petit)."""
    pad = W * 0.06
    # ligne 1 : 'commandes' ajustee a la largeur dispo
    avail = W - 2 * pad
    f1 = 100.0
    f1 = f1 * avail / text_width("commandes", ARIALBD, f1)
    f1 = min(f1, H * (0.46 if not with_cart else 0.42))
    w_com = text_width("commandes", ARIALBD, f1)
    f2 = f1 * (0.62 if with_cart else 0.72)
    # ligne 2 : panier + ".lps"
    cart_s = f2 / 34.0 if with_cart else 0.0
    cart_w = (30 * cart_s + f2 * 0.35) if with_cart else 0.0
    w_dot = text_width(".", ARIALBD, f2)
    w_l = text_width("lps", ARIAL, f2)
    l2w = cart_w + w_dot + w_l
    gap = f1 * 0.26
    block_h = f1 + gap + f2
    top = (H - block_h) / 2
    base1 = top + f1 * 0.80
    base2 = base1 + gap + f2
    x1 = (W - w_com) / 2
    x2 = (W - l2w) / 2
    p_com, _ = text_paths("commandes", ARIALBD, f1, x1, base1, main_color)
    parts = list(p_com)
    if with_cart:
        parts.append(cart(x2, base2 - f2 * 0.86, cart_s, cart_color, 3.6))
    p_dot, xx = text_paths(".", ARIALBD, f2, x2 + cart_w, base2, dot_color)
    p_lps, _ = text_paths("lps", ARIAL, f2, xx, base2, lps_color, lps_op)
    body = "\n  ".join(parts + p_dot + p_lps)
    svg = f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {W} {H}" role="img" aria-label="commandes.lps">\n  {body}\n</svg>\n'
    open(fname, "w", encoding="utf-8").write(svg)
    print("wrote", fname)

def square(fname, bg, cart_color, filled=True):
    if filled:
        inner = f'<rect width="64" height="64" rx="14" fill="{bg}"/>'
        cc = cart_color
    else:
        inner = ""
        cc = cart_color
    svg = (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" role="img" aria-label="commandes.lps">'
           f'{inner}'
           f'<g transform="translate(14,13)" fill="none" stroke="{cc}" stroke-width="4" stroke-linecap="round" stroke-linejoin="round">'
           f'<path d="M3 4 h6 l5.5 24 h18 l5-17 H12"/>'
           f'<circle cx="16" cy="36" r="3" fill="{cc}" stroke="none"/>'
           f'<circle cx="32" cy="36" r="3" fill="{cc}" stroke="none"/></g></svg>\n')
    open(fname, "w", encoding="utf-8").write(svg)
    print("wrote", fname)

OUT = "./"
# en-tete (barre sombre -> texte blanc ; barre claire -> texte bleu)
lockup(OUT + "lk-white.svg", 200, 110, "#ffffff", "#ffffff", ORANGE, "#ffffff", lps_op=0.72)
lockup(OUT + "lk-dark.svg",  200, 110, BLUE,      GREY,      ORANGE, BLUE)
lockup(OUT + "lk-white-nocart.svg", 200, 110, "#ffffff", "#ffffff", ORANGE, "#ffffff", lps_op=0.72, with_cart=False)
lockup(OUT + "lk-dark-nocart.svg",  200, 110, BLUE,      GREY,      ORANGE, BLUE, with_cart=False)
# carre menu replie
square(OUT + "sq-white.svg", None, "#ffffff", filled=False)
square(OUT + "sq-dark.svg",  None, BLUE,      filled=False)
square(OUT + "sq-blue.svg",  "#2b5c8a", "#ffffff", filled=True)
