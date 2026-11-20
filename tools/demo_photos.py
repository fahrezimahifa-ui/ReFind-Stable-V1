"""Representative synthetic item photos for the persistent localhost demo (flat illustrations, no people).

render(kind, seed, portrait=False, private_caption=None) -> JPEG bytes (<= 240 KB, longest side <= 1000 px),
inside the app's prepared-photo limits (250 KB, 1200 px). Every image carries a small "Demo photo" label.
"""
import io, math, random
from PIL import Image, ImageDraw, ImageFilter, ImageFont

SS = 2  # supersampling factor for smooth edges


def font(size, bold=False):
    for name in (('segoeuib.ttf', 'arialbd.ttf') if bold else ('segoeui.ttf', 'arial.ttf')):
        try:
            return ImageFont.truetype('C:/Windows/Fonts/' + name, size)
        except OSError:
            continue
    return ImageFont.load_default(size)


def tone(c, f):
    """Lighten (f > 0) or darken (f < 0) an RGB colour."""
    return tuple(max(0, min(255, int(v + (255 - v) * f if f > 0 else v * (1 + f)))) for v in c[:3])


def bezier(pts, n=40):
    out = []
    for i in range(n + 1):
        t = i / n; q = list(pts)
        while len(q) > 1:
            q = [(a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t) for a, b in zip(q, q[1:])]
        out.append(q[0])
    return out


def rr(d, box, r, fill, outline=None, width=0):
    d.rounded_rectangle([min(box[0], box[2]), min(box[1], box[3]), max(box[0], box[2]), max(box[1], box[3])], radius=max(1, r), fill=fill, outline=outline, width=width)


def star(d, cx, cy, r, fill):
    pts = [(cx + (r if i % 2 == 0 else r * 0.45) * math.sin(i * math.pi / 5), cy - (r if i % 2 == 0 else r * 0.45) * math.cos(i * math.pi / 5)) for i in range(10)]
    d.polygon(pts, fill=fill)


# ---------------------------------------------------------------- backgrounds
def background(kind, w, h, rnd):
    img = Image.new('RGB', (w, h))
    d = ImageDraw.Draw(img)
    base = {'desk': (190, 150, 108), 'mat': (58, 68, 84), 'bench': (204, 204, 198), 'grass': (96, 142, 72), 'tile': (228, 225, 218), 'fabric': (176, 186, 198)}[kind]
    for y in range(h):  # soft vertical light falloff
        f = 0.10 - 0.22 * y / h
        d.line([(0, y), (w, y)], fill=tone(base, f))
    if kind == 'desk':
        for _ in range(70):
            y = rnd.uniform(0, h); amp = rnd.uniform(4, 18) * SS; col = tone(base, rnd.uniform(-0.18, 0.12))
            d.line([(x, y + amp * math.sin(x / (rnd.uniform(90, 220) * SS))) for x in range(0, w + 40, 40)], fill=col, width=rnd.randint(1, 3) * SS)
    elif kind == 'tile':
        step = int(min(w, h) / 3.2)
        for x in range(step // 3, w, step):
            d.line([(x, 0), (x, h)], fill=tone(base, -0.12), width=3 * SS)
        for y in range(step // 4, h, step):
            d.line([(0, y), (w, y)], fill=tone(base, -0.12), width=3 * SS)
    elif kind == 'grass':
        for _ in range(1400):
            x, y = rnd.uniform(0, w), rnd.uniform(0, h); l = rnd.uniform(10, 30) * SS
            d.line([(x, y), (x + rnd.uniform(-6, 6) * SS, y - l)], fill=tone(base, rnd.uniform(-0.25, 0.2)), width=2 * SS)
    else:
        for _ in range(2200 if kind != 'fabric' else 1200):
            x, y = rnd.uniform(0, w), rnd.uniform(0, h); r = rnd.uniform(0.6, 1.8) * SS
            d.ellipse([x - r, y - r, x + r, y + r], fill=tone(base, rnd.uniform(-0.12, 0.10)))
        if kind == 'fabric':
            for y in range(0, h, 7 * SS):
                d.line([(0, y), (w, y)], fill=tone(base, -0.04), width=1)
    return img


# ---------------------------------------------------------------- objects (drawn around (cx, cy) with unit u)
def charger(d, cx, cy, u, c, rnd):
    pts = bezier([(cx + 0.6 * u, cy - 0.2 * u), (cx + 2.6 * u, cy - 1.6 * u), (cx + 2.8 * u, cy + 1.8 * u), (cx + 0.8 * u, cy + 1.6 * u), (cx - 1.6 * u, cy + 1.4 * u), (cx + 1.8 * u, cy - 2.2 * u)])
    d.line(pts, fill=tone(c, 0.12), width=int(0.13 * u), joint='curve')
    ex, ey = pts[-1]
    rr(d, (ex - 0.18 * u, ey - 0.42 * u, ex + 0.18 * u, ey + 0.05 * u), int(0.06 * u), tone(c, 0.2))
    rr(d, (ex - 0.09 * u, ey - 0.62 * u, ex + 0.09 * u, ey - 0.42 * u), int(0.03 * u), (196, 198, 204))
    rr(d, (cx - 1.9 * u, cy - 1.0 * u, cx + 0.7 * u, cy + 0.55 * u), int(0.28 * u), c)
    rr(d, (cx - 1.75 * u, cy - 0.88 * u, cx + 0.55 * u, cy - 0.62 * u), int(0.1 * u), tone(c, 0.18))
    d.ellipse([cx - 0.75 * u, cy - 0.35 * u, cx - 0.45 * u, cy - 0.05 * u], outline=tone(c, 0.35), width=int(0.04 * u))
    return (cx - 1.5 * u, cy + 0.1 * u)  # where a label or mark would sit


def card_holder(d, cx, cy, u, c, rnd):
    d.line(bezier([(cx - 0.6 * u, cy - 1.0 * u), (cx - 1.0 * u, cy - 3.2 * u), (cx + 1.0 * u, cy - 3.2 * u), (cx + 0.6 * u, cy - 1.0 * u)]), fill=tone(c, -0.2), width=int(0.32 * u), joint='curve')
    d.ellipse([cx + 0.45 * u, cy - 2.45 * u, cx + 0.8 * u, cy - 2.1 * u], fill=(214, 178, 64))
    rr(d, (cx - 1.5 * u, cy - 1.05 * u, cx + 1.5 * u, cy + 1.05 * u), int(0.22 * u), c)
    rr(d, (cx - 1.3 * u, cy - 0.8 * u, cx + 1.3 * u, cy + 0.85 * u), int(0.12 * u), (236, 240, 246))
    rr(d, (cx - 1.12 * u, cy - 0.55 * u, cx - 0.35 * u, cy + 0.45 * u), int(0.06 * u), (150, 176, 206))
    for i, wdt in enumerate((1.25, 1.0, 1.15)):
        rr(d, (cx - 0.15 * u, cy - 0.45 * u + i * 0.32 * u, cx - 0.15 * u + wdt * u, cy - 0.33 * u + i * 0.32 * u), int(0.04 * u), (120, 132, 150))
    return (cx + 0.6 * u, cy - 2.3 * u)


def garment(d, cx, cy, u, c, rnd, hood=True, zip_=False):
    if hood:
        d.ellipse([cx - 1.0 * u, cy - 3.0 * u, cx + 1.0 * u, cy - 1.6 * u], fill=tone(c, -0.12))
    body = [(cx - 1.1 * u, cy - 2.3 * u), (cx - 2.0 * u, cy - 2.0 * u), (cx - 3.1 * u, cy + 0.6 * u), (cx - 2.4 * u, cy + 0.9 * u), (cx - 1.8 * u, cy - 0.6 * u),
            (cx - 1.7 * u, cy + 2.6 * u), (cx + 1.7 * u, cy + 2.6 * u), (cx + 1.8 * u, cy - 0.6 * u), (cx + 2.4 * u, cy + 0.9 * u), (cx + 3.1 * u, cy + 0.6 * u), (cx + 2.0 * u, cy - 2.0 * u), (cx + 1.1 * u, cy - 2.3 * u)]
    d.polygon(body, fill=c)
    d.polygon([(cx - 1.1 * u, cy - 2.3 * u), (cx, cy - 1.5 * u), (cx + 1.1 * u, cy - 2.3 * u)], fill=tone(c, -0.25))
    rr(d, (cx - 1.7 * u, cy + 2.3 * u, cx + 1.7 * u, cy + 2.65 * u), int(0.1 * u), tone(c, -0.12))
    for side in (-1, 1):
        rr(d, (cx + side * 2.38 * u - 0.36 * u, cy + 0.55 * u, cx + side * 2.38 * u + 0.36 * u, cy + 0.95 * u), int(0.1 * u), tone(c, -0.12))
    if zip_:
        d.line([(cx, cy - 1.5 * u), (cx, cy + 2.6 * u)], fill=tone(c, -0.35), width=int(0.06 * u))
        for i in range(4):
            d.ellipse([cx + 0.18 * u, cy - 0.9 * u + i * 0.8 * u, cx + 0.34 * u, cy - 0.74 * u + i * 0.8 * u], fill=(200, 200, 205))
    else:
        rr(d, (cx - 1.0 * u, cy + 0.7 * u, cx + 1.0 * u, cy + 1.7 * u), int(0.25 * u), tone(c, -0.1))
        for side in (-1, 1):
            d.line([(cx + side * 0.3 * u, cy - 1.6 * u), (cx + side * 0.36 * u, cy - 0.5 * u)], fill=(236, 236, 236), width=int(0.07 * u))
    return (cx - 2.7 * u, cy + 0.75 * u)


def hoodie(d, cx, cy, u, c, rnd):
    return garment(d, cx, cy, u, c, rnd, hood=True)


def jacket(d, cx, cy, u, c, rnd):
    garment(d, cx, cy, u, c, rnd, hood=False, zip_=True)
    d.polygon([(cx - 1.1 * u, cy - 2.3 * u), (cx - 0.25 * u, cy - 1.0 * u), (cx - 0.9 * u, cy - 1.2 * u)], fill=tone(c, 0.15))
    d.polygon([(cx + 1.1 * u, cy - 2.3 * u), (cx + 0.25 * u, cy - 1.0 * u), (cx + 0.9 * u, cy - 1.2 * u)], fill=tone(c, 0.15))
    d.ellipse([cx - 0.95 * u, cy - 1.95 * u, cx - 0.6 * u, cy - 1.6 * u], fill=(240, 200, 60))
    d.ellipse([cx - 0.55 * u, cy - 1.7 * u, cx - 0.22 * u, cy - 1.37 * u], fill=(80, 80, 90))
    return (cx - 0.6 * u, cy - 1.7 * u)


def bottle(d, cx, cy, u, c, rnd):
    rr(d, (cx - 0.75 * u, cy - 2.2 * u, cx + 0.75 * u, cy + 2.5 * u), int(0.55 * u), c)
    rr(d, (cx - 0.55 * u, cy - 2.9 * u, cx + 0.55 * u, cy - 2.05 * u), int(0.2 * u), tone(c, -0.35))
    rr(d, (cx - 0.5 * u, cy - 1.9 * u, cx - 0.28 * u, cy + 2.1 * u), int(0.1 * u), tone(c, 0.3))
    d.line([(cx - 0.75 * u, cy - 1.4 * u), (cx + 0.75 * u, cy - 1.4 * u)], fill=tone(c, -0.15), width=int(0.08 * u))
    return (cx, cy + 2.2 * u)


def tumbler(d, cx, cy, u, c, rnd):
    d.polygon([(cx - 0.95 * u, cy - 2.0 * u), (cx + 0.95 * u, cy - 2.0 * u), (cx + 0.72 * u, cy + 2.4 * u), (cx - 0.72 * u, cy + 2.4 * u)], fill=c)
    rr(d, (cx - 1.05 * u, cy - 2.6 * u, cx + 1.05 * u, cy - 1.9 * u), int(0.2 * u), (40, 44, 52))
    d.polygon([(cx - 0.55 * u, cy + 0.6 * u), (cx - 0.05 * u, cy - 0.3 * u), (cx + 0.2 * u, cy + 0.15 * u), (cx + 0.35 * u, cy - 0.05 * u), (cx + 0.6 * u, cy + 0.6 * u)], fill=(70, 110, 160))
    d.polygon([(cx - 0.05 * u, cy - 0.3 * u), (cx - 0.17 * u, cy - 0.08 * u), (cx + 0.07 * u, cy - 0.08 * u)], fill=(245, 245, 245))
    rr(d, (cx - 0.7 * u, cy - 1.7 * u, cx - 0.52 * u, cy + 2.1 * u), int(0.08 * u), tone(c, 0.35))
    return (cx + 0.5 * u, cy + 2.2 * u)


def earbuds_case(d, cx, cy, u, c, rnd):
    rr(d, (cx - 1.3 * u, cy - 1.0 * u, cx + 1.3 * u, cy + 1.0 * u), int(0.9 * u), c)
    d.line([(cx - 1.25 * u, cy - 0.3 * u), (cx + 1.25 * u, cy - 0.3 * u)], fill=tone(c, -0.18), width=int(0.05 * u))
    d.ellipse([cx - 0.07 * u, cy + 0.05 * u, cx + 0.07 * u, cy + 0.19 * u], fill=(90, 200, 120))
    rr(d, (cx - 1.0 * u, cy - 0.85 * u, cx + 0.2 * u, cy - 0.55 * u), int(0.12 * u), tone(c, 0.5))
    return (cx + 0.7 * u, cy + 0.45 * u)


def keys(d, cx, cy, u, c, rnd):
    d.ellipse([cx - 0.9 * u, cy - 2.1 * u, cx + 0.3 * u, cy - 0.9 * u], outline=(170, 172, 178), width=int(0.12 * u))
    for dx, ang, col in ((-0.5, -20, (196, 160, 70)), (0.2, 25, (186, 190, 198))):
        k = Image.new('RGBA', (int(6 * u), int(6 * u)), (0, 0, 0, 0)); kd = ImageDraw.Draw(k); m = 3 * u
        kd.ellipse([m - 0.55 * u, m - 2.3 * u, m + 0.55 * u, m - 1.2 * u], fill=col)
        kd.ellipse([m - 0.16 * u, m - 2.0 * u, m + 0.16 * u, m - 1.7 * u], fill=(0, 0, 0, 0))
        kd.rectangle([m - 0.14 * u, m - 1.25 * u, m + 0.14 * u, m + 1.2 * u], fill=col)
        kd.polygon([(m + 0.14 * u, m + 0.2 * u), (m + 0.45 * u, m + 0.3 * u), (m + 0.3 * u, m + 0.55 * u), (m + 0.45 * u, m + 0.75 * u), (m + 0.14 * u, m + 1.15 * u)], fill=col)
        k = k.rotate(ang, resample=Image.BICUBIC)
        d._image.paste(k, (int(cx + dx * u - 3 * u), int(cy - 1.0 * u - 3 * u + 1.2 * u)), k)
    rr(d, (cx + 0.5 * u, cy - 2.4 * u, cx + 1.7 * u, cy - 1.7 * u), int(0.15 * u), (64, 150, 90))
    d.text((cx + 1.1 * u, cy - 2.05 * u), '14', fill=(255, 255, 255), font=font(int(0.42 * u), True), anchor='mm')
    return (cx + 1.1 * u, cy - 2.05 * u)


def calculator(d, cx, cy, u, c, rnd):
    rr(d, (cx - 1.4 * u, cy - 2.4 * u, cx + 1.4 * u, cy + 2.4 * u), int(0.25 * u), c)
    rr(d, (cx - 1.15 * u, cy - 2.15 * u, cx + 1.15 * u, cy - 1.85 * u), int(0.05 * u), (60, 56, 50))
    rr(d, (cx - 1.15 * u, cy - 1.7 * u, cx + 1.15 * u, cy - 0.9 * u), int(0.1 * u), (178, 192, 168))
    d.text((cx + 1.0 * u, cy - 1.3 * u), '3.14159', fill=(40, 50, 40), font=font(int(0.45 * u)), anchor='rm')
    for r_ in range(5):
        for k in range(4):
            x0 = cx - 1.1 * u + k * 0.58 * u; y0 = cy - 0.65 * u + r_ * 0.6 * u
            rr(d, (x0, y0, x0 + 0.44 * u, y0 + 0.4 * u), int(0.08 * u), (226, 120, 60) if (r_, k) == (4, 3) else tone(c, 0.3))
    return (cx, cy - 2.0 * u)


def glasses(d, cx, cy, u, c, rnd, case=True, tint=(60, 70, 80, 90)):
    if case:
        rr(d, (cx - 2.6 * u, cy + 0.2 * u, cx + 2.6 * u, cy + 1.9 * u), int(0.8 * u), (120, 82, 50))
        rr(d, (cx - 2.4 * u, cy + 0.35 * u, cx + 2.4 * u, cy + 1.2 * u), int(0.5 * u), (150, 108, 70))
    for side in (-1, 1):
        x = cx + side * 1.15 * u
        rr(d, (x - 0.95 * u, cy - 1.1 * u, x + 0.95 * u, cy + 0.2 * u), int(0.45 * u), tint, outline=c, width=int(0.14 * u))
        d.line([(x + side * 0.95 * u, cy - 0.85 * u), (x + side * 1.6 * u, cy - 1.2 * u)], fill=c, width=int(0.12 * u))
    d.arc([cx - 0.35 * u, cy - 0.95 * u, cx + 0.35 * u, cy - 0.35 * u], 200, 340, fill=c, width=int(0.12 * u))
    return (cx, cy + 1.0 * u)


def sunglasses(d, cx, cy, u, c, rnd):
    return glasses(d, cx, cy, u, c, rnd, case=False, tint=(40, 30, 20, 220))


def umbrella(d, cx, cy, u, c, rnd):
    d.polygon([(cx, cy - 3.0 * u), (cx + 0.75 * u, cy - 0.5 * u), (cx + 0.5 * u, cy + 1.6 * u), (cx - 0.5 * u, cy + 1.6 * u), (cx - 0.75 * u, cy - 0.5 * u)], fill=c)
    for f in (-0.35, 0.0, 0.35):
        d.line([(cx, cy - 2.9 * u), (cx + f * u, cy + 1.5 * u)], fill=tone(c, 0.18), width=int(0.04 * u))
    rr(d, (cx - 0.62 * u, cy - 0.3 * u, cx + 0.62 * u, cy + 0.05 * u), int(0.08 * u), tone(c, -0.3))
    d.line([(cx, cy + 1.6 * u), (cx, cy + 2.4 * u)], fill=(150, 152, 158), width=int(0.12 * u))
    d.arc([cx - 0.9 * u, cy + 2.0 * u, cx + 0.1 * u, cy + 3.2 * u], 0, 200, fill=(130, 88, 52), width=int(0.3 * u))
    d.line([(cx, cy - 3.0 * u), (cx, cy - 3.4 * u)], fill=(150, 152, 158), width=int(0.1 * u))
    return (cx + 0.3 * u, cy - 2.0 * u)


def wallet(d, cx, cy, u, c, rnd):
    rr(d, (cx - 0.9 * u, cy - 1.65 * u, cx + 0.8 * u, cy - 0.7 * u), int(0.1 * u), (120, 160, 210))
    rr(d, (cx - 2.0 * u, cy - 1.3 * u, cx + 2.0 * u, cy + 1.3 * u), int(0.3 * u), c)
    rr(d, (cx - 1.8 * u, cy - 1.1 * u, cx + 1.8 * u, cy + 1.1 * u), int(0.22 * u), None, outline=tone(c, 0.35), width=int(0.04 * u))
    d.line([(cx - 2.0 * u, cy - 0.2 * u), (cx + 2.0 * u, cy - 0.2 * u)], fill=tone(c, -0.25), width=int(0.06 * u))
    return (cx + 1.7 * u, cy + 1.05 * u)


def notebook(d, cx, cy, u, c, rnd):
    rr(d, (cx - 1.6 * u, cy - 2.2 * u, cx + 1.6 * u, cy + 2.2 * u), int(0.15 * u), c)
    for i in range(9):
        y = cy - 1.9 * u + i * 0.48 * u
        d.ellipse([cx - 1.78 * u, y, cx - 1.42 * u, y + 0.22 * u], outline=(190, 192, 198), width=int(0.06 * u))
    rr(d, (cx + 0.9 * u, cy - 2.2 * u, cx + 1.1 * u, cy + 2.2 * u), 2, (40, 40, 50))
    rr(d, (cx - 0.9 * u, cy - 1.3 * u, cx + 0.6 * u, cy - 0.6 * u), int(0.08 * u), (240, 240, 232))
    return (cx - 0.1 * u, cy - 0.95 * u)


def book(d, cx, cy, u, c, rnd):
    rr(d, (cx - 1.5 * u, cy - 2.2 * u, cx + 1.5 * u, cy + 2.2 * u), int(0.1 * u), c)
    rr(d, (cx - 1.5 * u, cy - 2.2 * u, cx - 1.1 * u, cy + 2.2 * u), int(0.08 * u), tone(c, -0.25))
    rr(d, (cx - 0.9 * u, cy - 1.4 * u, cx + 1.2 * u, cy - 0.5 * u), int(0.05 * u), (244, 236, 210))
    d.text((cx + 0.15 * u, cy - 0.95 * u), 'Interaction', fill=(50, 50, 60), font=font(int(0.32 * u), True), anchor='mm')
    rr(d, (cx - 1.45 * u, cy + 1.0 * u, cx - 1.15 * u, cy + 1.8 * u), 2, (250, 250, 250))
    return (cx - 1.3 * u, cy + 1.4 * u)


def smartwatch(d, cx, cy, u, c, rnd):
    rr(d, (cx - 0.65 * u, cy - 3.0 * u, cx + 0.65 * u, cy + 3.0 * u), int(0.3 * u), (120, 124, 132))
    rr(d, (cx - 1.15 * u, cy - 1.35 * u, cx + 1.15 * u, cy + 1.35 * u), int(0.45 * u), c)
    rr(d, (cx - 0.95 * u, cy - 1.15 * u, cx + 0.95 * u, cy + 1.15 * u), int(0.35 * u), (20, 22, 30))
    d.text((cx, cy - 0.15 * u), '10:24', fill=(140, 210, 255), font=font(int(0.62 * u), True), anchor='mm')
    rr(d, (cx + 1.12 * u, cy - 0.35 * u, cx + 1.3 * u, cy + 0.1 * u), int(0.05 * u), tone(c, -0.2))
    return (cx + 0.3 * u, cy + 2.4 * u)


def mouse(d, cx, cy, u, c, rnd):
    d.ellipse([cx - 1.1 * u, cy - 1.8 * u, cx + 1.1 * u, cy + 1.8 * u], fill=c, outline=tone(c, -0.15), width=int(0.04 * u))
    d.line([(cx, cy - 1.8 * u), (cx, cy - 0.4 * u)], fill=tone(c, -0.2), width=int(0.05 * u))
    rr(d, (cx - 0.12 * u, cy - 1.35 * u, cx + 0.12 * u, cy - 0.75 * u), int(0.1 * u), (90, 94, 104))
    return (cx, cy - 1.05 * u)


def pencil_case(d, cx, cy, u, c, rnd):
    rr(d, (cx - 2.6 * u, cy - 0.9 * u, cx + 2.6 * u, cy + 0.9 * u), int(0.75 * u), c)
    for i in range(34):
        x = cx - 2.2 * u + i * 0.13 * u
        d.line([(x, cy - 0.55 * u), (x, cy - 0.38 * u)], fill=tone(c, -0.35), width=int(0.05 * u))
    rr(d, (cx + 2.15 * u, cy - 0.6 * u, cx + 2.75 * u, cy - 0.3 * u), int(0.1 * u), (200, 200, 205))
    return (cx - 1.4 * u, cy + 0.3 * u)


def headphones(d, cx, cy, u, c, rnd):
    d.arc([cx - 2.0 * u, cy - 2.6 * u, cx + 2.0 * u, cy + 1.4 * u], 190, 350, fill=c, width=int(0.36 * u))
    for side in (-1, 1):
        x = cx + side * 1.9 * u
        rr(d, (x - 0.65 * u, cy - 0.5 * u, x + 0.65 * u, cy + 1.6 * u), int(0.55 * u), c)
        rr(d, (x - 0.5 * u, cy - 0.3 * u, x + 0.5 * u, cy + 1.4 * u), int(0.45 * u), tone(c, 0.12))
    d.ellipse([cx - 0.15 * u, cy - 2.38 * u, cx + 0.15 * u, cy - 2.08 * u], fill=(230, 80, 80))
    return (cx + 1.9 * u, cy + 0.5 * u)


def key_fob(d, cx, cy, u, c, rnd):
    d.ellipse([cx - 0.5 * u, cy - 2.6 * u, cx + 0.5 * u, cy - 1.6 * u], outline=(170, 172, 178), width=int(0.1 * u))
    rr(d, (cx - 0.25 * u, cy - 2.4 * u, cx + 1.2 * u, cy - 0.9 * u), int(0.3 * u), (130, 86, 50))
    rr(d, (cx - 0.9 * u, cy - 1.7 * u, cx + 0.9 * u, cy + 1.7 * u), int(0.7 * u), c)
    for y in (-0.6, 0.5):
        d.ellipse([cx - 0.38 * u, cy + y * u - 0.38 * u, cx + 0.38 * u, cy + y * u + 0.38 * u], fill=tone(c, 0.22))
    return (cx + 0.5 * u, cy - 1.6 * u)


def scarf(d, cx, cy, u, c, rnd):
    top = [(cx - 3.2 * u + i * 0.2 * u, cy - 0.7 * u + 0.35 * u * math.sin(i / 3.0)) for i in range(33)]
    bot = [(x, y + 1.4 * u) for x, y in top]
    d.polygon(top + bot[::-1], fill=c)
    for k in (8, 16, 24):
        d.line([top[k], bot[k]], fill=tone(c, -0.2), width=int(0.16 * u))
    for x0, y0 in (top[0], top[-1]):
        for j in range(6):
            dx = (-1 if x0 < cx else 1) * 0.45 * u
            d.line([(x0, y0 + j * 0.25 * u), (x0 + dx, y0 + j * 0.25 * u + 0.1 * u)], fill=tone(c, -0.1), width=int(0.07 * u))
    return (cx + 3.0 * u, cy)


def lunch_box(d, cx, cy, u, c, rnd):
    rr(d, (cx - 2.2 * u, cy - 1.0 * u, cx + 2.2 * u, cy + 1.6 * u), int(0.35 * u), (232, 236, 240))
    rr(d, (cx - 2.35 * u, cy - 1.5 * u, cx + 2.35 * u, cy - 0.7 * u), int(0.3 * u), c)
    for side in (-1, 1):
        rr(d, (cx + side * 1.4 * u - 0.3 * u, cy - 0.85 * u, cx + side * 1.4 * u + 0.3 * u, cy - 0.35 * u), int(0.08 * u), tone(c, -0.2))
    d.ellipse([cx - 0.45 * u, cy - 0.1 * u, cx + 0.45 * u, cy + 0.8 * u], fill=(250, 210, 80))
    return (cx, cy + 0.35 * u)


def usb_drive(d, cx, cy, u, c, rnd):
    d.line(bezier([(cx - 0.9 * u, cy - 0.2 * u), (cx - 3.0 * u, cy - 1.8 * u), (cx - 3.2 * u, cy + 1.6 * u), (cx - 0.9 * u, cy + 0.2 * u)]), fill=(200, 50, 50), width=int(0.16 * u))
    rr(d, (cx - 1.0 * u, cy - 0.45 * u, cx + 1.3 * u, cy + 0.45 * u), int(0.18 * u), c)
    rr(d, (cx + 1.25 * u, cy - 0.3 * u, cx + 2.0 * u, cy + 0.3 * u), int(0.04 * u), (200, 202, 208))
    return (cx, cy)


def cable(d, cx, cy, u, c, rnd):
    for i in range(4):
        r = 1.5 * u + i * 0.12 * u
        d.ellipse([cx - r + i * 0.1 * u, cy - r * 0.8, cx + r + i * 0.1 * u, cy + r * 0.8], outline=c, width=int(0.12 * u))
    for x, y in ((cx + 1.9 * u, cy - 0.3 * u), (cx - 1.5 * u, cy + 1.4 * u)):
        rr(d, (x - 0.22 * u, y - 0.4 * u, x + 0.22 * u, y + 0.4 * u), int(0.08 * u), tone(c, -0.08))
        rr(d, (x - 0.12 * u, y - 0.62 * u, x + 0.12 * u, y - 0.4 * u), int(0.04 * u), (190, 192, 198))
    return (cx + 1.6 * u, cy)


def cap(d, cx, cy, u, c, rnd):
    d.ellipse([cx - 3.0 * u, cy - 0.35 * u, cx + 0.3 * u, cy + 0.7 * u], fill=tone(c, -0.15))
    d.pieslice([cx - 1.9 * u, cy - 1.7 * u, cx + 1.9 * u, cy + 1.9 * u], 180, 360, fill=c)
    for a in (-0.8, 0.0, 0.8):
        d.line([(cx, cy - 1.7 * u), (cx + a * 1.9 * u, cy + 0.1 * u)], fill=tone(c, 0.15), width=int(0.05 * u))
    d.ellipse([cx - 0.15 * u, cy - 1.85 * u, cx + 0.15 * u, cy - 1.55 * u], fill=tone(c, 0.2))
    return (cx + 1.2 * u, cy - 0.4 * u)


def highlighters(d, cx, cy, u, c, rnd):
    for i, col in enumerate(((246, 222, 60), (120, 210, 90), (250, 160, 60), (240, 110, 170))):
        x = cx - 1.5 * u + i * 1.0 * u
        rr(d, (x - 0.35 * u, cy - 1.6 * u, x + 0.35 * u, cy + 2.2 * u), int(0.2 * u), col)
        if i != 3:
            rr(d, (x - 0.38 * u, cy - 2.4 * u, x + 0.38 * u, cy - 1.3 * u), int(0.18 * u), tone(col, -0.2))
        else:
            d.polygon([(x - 0.2 * u, cy - 1.6 * u), (x + 0.2 * u, cy - 1.6 * u), (x + 0.1 * u, cy - 2.0 * u), (x - 0.1 * u, cy - 2.0 * u)], fill=tone(col, -0.3))
    return (cx + 1.5 * u, cy - 1.8 * u)


def power_bank(d, cx, cy, u, c, rnd):
    rr(d, (cx - 1.2 * u, cy - 2.2 * u, cx + 1.2 * u, cy + 2.2 * u), int(0.35 * u), c)
    for i in range(4):
        d.ellipse([cx - 0.6 * u + i * 0.4 * u, cy - 1.8 * u, cx - 0.45 * u + i * 0.4 * u, cy - 1.65 * u], fill=(90, 200, 255) if i < 2 else tone(c, 0.2))
    d.polygon([(cx, cy - 0.3 * u), (cx + 0.35 * u, cy + 0.5 * u), (cx - 0.35 * u, cy + 0.5 * u)], fill=(240, 240, 240))
    d.polygon([(cx - 0.18 * u, cy + 0.5 * u), (cx + 0.18 * u, cy + 0.5 * u), (cx, cy + 0.9 * u)], fill=(250, 140, 60))
    return (cx, cy + 0.3 * u)


def earphones(d, cx, cy, u, c, rnd):
    jack = (cx + 0.4 * u, cy + 2.4 * u)
    for side in (-1, 1):
        bud = (cx + side * 1.4 * u, cy - 1.4 * u)
        d.line(bezier([(bud[0], bud[1] + 0.5 * u), (bud[0] + side * 0.8 * u, cy + 0.8 * u), (cx - side * 0.6 * u, cy + 0.6 * u), (cx + 0.1 * u, cy + 1.2 * u)]), fill=c, width=int(0.07 * u))
        d.ellipse([bud[0] - 0.42 * u, bud[1] - 0.42 * u, bud[0] + 0.42 * u, bud[1] + 0.42 * u], fill=c, outline=tone(c, -0.15), width=int(0.03 * u))
        rr(d, (bud[0] - 0.1 * u, bud[1] + 0.2 * u, bud[0] + 0.1 * u, bud[1] + 0.7 * u), int(0.06 * u), c)
    d.line([(cx + 0.1 * u, cy + 1.2 * u), jack], fill=c, width=int(0.08 * u))
    rr(d, (jack[0] - 0.12 * u, jack[1] - 0.1 * u, jack[0] + 0.12 * u, jack[1] + 0.45 * u), int(0.05 * u), (196, 198, 204))
    return (cx - 1.4 * u, cy - 1.4 * u)


def bracelet(d, cx, cy, u, c, rnd):
    for i in range(36):
        a = i * math.tau / 36
        x, y = cx + 1.6 * u * math.cos(a), cy + 1.2 * u * math.sin(a)
        d.ellipse([x - 0.12 * u, y - 0.12 * u, x + 0.12 * u, y + 0.12 * u], outline=c, width=int(0.05 * u))
    d.ellipse([cx - 0.3 * u, cy + 1.2 * u, cx + 0.3 * u, cy + 1.8 * u], fill=c)
    d.ellipse([cx - 0.14 * u, cy + 1.16 * u, cx + 0.4 * u, cy + 1.7 * u], fill=(0, 0, 0, 0))
    return (cx, cy + 1.5 * u)


def receipt(d, cx, cy, u, c, rnd):
    rr(d, (cx - 1.5 * u, cy - 2.6 * u, cx + 1.5 * u, cy + 2.6 * u), int(0.05 * u), (250, 250, 246))
    rr(d, (cx - 1.0 * u, cy - 2.25 * u, cx + 1.0 * u, cy - 1.95 * u), 2, (60, 60, 70))
    for i in range(9):
        w = rnd.uniform(1.2, 2.4)
        rr(d, (cx - 1.2 * u, cy - 1.5 * u + i * 0.36 * u, cx - 1.2 * u + w * u, cy - 1.4 * u + i * 0.36 * u), 2, (150, 150, 160))
    rr(d, (cx - 1.2 * u, cy + 1.9 * u, cx + 1.2 * u, cy + 2.1 * u), 2, (60, 60, 70))
    return (cx, cy + 2.0 * u)


ZOOM = {'charger': 1.35, 'earbuds_case': 1.4, 'mouse': 1.2, 'usb_drive': 1.3, 'key_fob': 1.2, 'wallet': 1.15, 'keys': 1.1}
DRAW = {f.__name__: f for f in (charger, card_holder, hoodie, jacket, bottle, tumbler, earbuds_case, keys, calculator, glasses, sunglasses, umbrella, wallet, notebook,
                                book, smartwatch, mouse, pencil_case, headphones, key_fob, scarf, lunch_box, usb_drive, cable, cap, highlighters, power_bank, earphones, bracelet, receipt)}


def render(kind, seed, colour, surface='desk', portrait=False, private_caption=None, zoom=1.0):
    rnd = random.Random(seed)
    w, h = (750, 1000) if portrait else (1000, 750)
    W, H = w * SS, h * SS
    img = background(surface, W, H, rnd)
    layer = Image.new('RGBA', (W, H), (0, 0, 0, 0))
    d = ImageDraw.Draw(layer)
    u = min(W, H) / 8.2 * zoom * ZOOM.get(kind, 1.0)
    mark = DRAW[kind](d, W / 2 + rnd.uniform(-0.25, 0.25) * u, H / 2 + rnd.uniform(-0.2, 0.2) * u, u, colour, rnd)
    angle = rnd.uniform(-9, 9) if kind != 'receipt' else rnd.uniform(-4, 4)
    layer = layer.rotate(angle, resample=Image.BICUBIC, center=(W / 2, H / 2))
    alpha = layer.getchannel('A')
    shadow = Image.new('L', (W, H), 0); shadow.paste(alpha.point(lambda a: 120 if a > 20 else 0), (int(0.06 * u), int(0.1 * u)))
    shadow = shadow.filter(ImageFilter.GaussianBlur(0.14 * u))
    img.paste(Image.new('RGB', (W, H), (20, 18, 16)), (0, 0), shadow)
    img.paste(layer, (0, 0), layer)
    d = ImageDraw.Draw(img)
    if private_caption:
        mx, my = mark
        rad = 0.75 * u
        a = math.radians(-angle); ox, oy = mx - W / 2, my - H / 2
        mx, my = W / 2 + ox * math.cos(a) - oy * math.sin(a), H / 2 + ox * math.sin(a) + oy * math.cos(a)
        d.ellipse([mx - rad, my - rad, mx + rad, my + rad], outline=(255, 214, 64), width=int(0.07 * u))
        f = font(int(30 * SS), True)
        tw = d.textlength(private_caption, font=f)
        rr(d, (W / 2 - tw / 2 - 26 * SS, 24 * SS, W / 2 + tw / 2 + 26 * SS, 84 * SS), int(18 * SS), (20, 24, 32))
        d.text((W / 2, 54 * SS), private_caption, fill=(255, 255, 255), font=f, anchor='mm')
    img = img.resize((w, h), Image.LANCZOS)
    v = Image.new('L', (w, h), 0); vd = ImageDraw.Draw(v)
    vd.ellipse([-w * 0.25, -h * 0.25, w * 1.25, h * 1.25], fill=255)
    img = Image.composite(img, Image.new('RGB', (w, h), (0, 0, 0)), v.filter(ImageFilter.GaussianBlur(min(w, h) / 6)).point(lambda p: 150 + p * 105 // 255))
    d = ImageDraw.Draw(img, 'RGBA')
    f = font(17, True); label = 'Demo photo'
    tw = d.textlength(label, font=f)
    d.rounded_rectangle([14, h - 44, 14 + tw + 24, h - 14], radius=12, fill=(10, 14, 22, 150))
    d.text((26, h - 29), label, fill=(255, 255, 255, 235), font=f, anchor='lm')
    for q in (84, 78, 72, 66, 60):
        b = io.BytesIO(); img.save(b, format='JPEG', quality=q, optimize=True, progressive=True)
        if b.tell() <= 240_000:
            return b.getvalue()
    raise RuntimeError('Demo photo exceeds the prepared-photo size limit')


def avatar(initials, colour):
    """A 512px square JPEG avatar (the account API requires exactly 512x512)."""
    S = 1024
    img = Image.new('RGB', (S, S), tone(colour, 0.75)); d = ImageDraw.Draw(img)
    d.ellipse([112, 112, S - 112, S - 112], fill=colour)
    d.text((S / 2, S / 2 + 10), initials, fill=(255, 255, 255), font=font(330, True), anchor='mm')
    img = img.resize((512, 512), Image.LANCZOS)
    b = io.BytesIO(); img.save(b, format='JPEG', quality=88)
    return b.getvalue()
