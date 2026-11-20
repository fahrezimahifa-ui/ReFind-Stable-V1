"""Seed the persistent localhost demo ONCE (profile `demo`, http://127.0.0.1:8030). Synthetic data only.

    python -B tools/demo-seed.py seed      first seed after `phase6-control.py init|grants|start --profile demo`
    python -B tools/demo-seed.py check     sign in with every demo account (read-only)

Everything goes through the application's own HTTP API (registration, accounts, reports, claims, BM decisions,
pickups, report status, read-marking), so every row, notification and history entry is produced by the real
workflows. Two deliberate exceptions, applied once in the demo schema only by tools/demo-data.php: the provisioning
fixtures are removed before seeding, and seeding times are mapped onto a realistic four-week campus timeline
(pickups the story completed, released or let pass get appointment times in the past, which the API cannot accept).

Passwords are generated here and written only to state/demo/demo-accounts.json and DEMO-ACCOUNTS.md (private,
outside Git and outside the served folder). They are never printed. The seed refuses to run twice; the baseline
snapshot and reset-demo.ps1 return to the seeded state.
"""
from pathlib import Path
from datetime import date, datetime, time as dtime, timedelta, timezone
import argparse, http.cookiejar, json, math, os, secrets, subprocess, sys, time, urllib.error, urllib.parse, urllib.request

sys.path.insert(0, str(Path(__file__).resolve().parent))
import demo_photos as P  # noqa: E402

ROOT = Path('C:/ReFind-Laravel'); APP = Path(__file__).resolve().parent.parent
PROFILE = 'demo'; STATE = ROOT / 'state' / PROFILE; BASE = 'http://127.0.0.1:8030'
PHP = ROOT / 'runtime/php85/php.exe'; INI = ROOT / 'runtime/php85/php.ini'
WIB = timezone(timedelta(hours=7)); UTC = timezone.utc
ACCOUNTS = STATE / 'demo-accounts.json'; GUIDE = STATE / 'DEMO-ACCOUNTS.md'; SEED = STATE / 'demo-seed.json'
WORDS = ['maple', 'river', 'cedar', 'lotus', 'amber', 'coral', 'delta', 'ember', 'falcon', 'harbor', 'island', 'jasmine', 'kettle', 'lantern', 'meadow', 'orchid', 'pebble', 'quartz', 'saffron', 'tulip', 'violet', 'willow']
# Areas (campus_locations, kind AREA) and active categories from database/seed-reference.sql.
A_GA, A_GB, A_GC, A_GD, A_PARK, A_FIELD = 35, 36, 37, 38, 39, 40
ELEC, WALLET, CLOTH, STAT, ACC, OTHER = 1, 3, 4, 6, 7, 8
PEOPLE = {  # key: (display name, role, how the account is created)
    'student': ('Demo Student', 'STUDENT', 'provisioned student_a'), 'finder': ('Demo Finder', 'STUDENT', 'provisioned student_b'),
    'bm': ('Demo Building Manager', 'BUILDING_MANAGEMENT', 'provisioned bm'), 'rina': ('Demo Rina', 'STUDENT', 'registration page'),
    'bayu': ('Demo Bayu', 'STUDENT', 'registration page'), 'citra': ('Demo Citra', 'STUDENT', 'registration page'), 'new': ('Demo New Student', 'STUDENT', 'registration page'),
}
REGISTERED_EMAILS = {'rina': 'rina@refind-poc.invalid', 'bayu': 'bayu@refind-poc.invalid', 'citra': 'citra@refind-poc.invalid', 'new': 'new-student@refind-poc.invalid'}


class Failure(RuntimeError):
    pass


class Client:
    def __init__(self, label):
        self.label = label; self.token = ''
        self.http = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
        self.token = self.call('/api/session.php')['csrf']

    def call(self, path, data=None, files=None, expect=(200,)):
        expect = (expect,) if isinstance(expect, int) else expect
        h = {}; body = None
        if data is not None:
            if not files:
                body = urllib.parse.urlencode(data).encode(); h['Content-Type'] = 'application/x-www-form-urlencoded'
            else:
                boundary = 'demo' + secrets.token_hex(12); chunks = []
                for k, v in data.items():
                    chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + k + '"\r\n\r\n' + str(v) + '\r\n').encode())
                for field, name, payload in files:
                    chunks.append(('--' + boundary + '\r\nContent-Disposition: form-data; name="' + field + '"; filename="' + name + '"\r\nContent-Type: image/jpeg\r\n\r\n').encode() + payload + b'\r\n')
                chunks.append(('--' + boundary + '--\r\n').encode()); body = b''.join(chunks); h['Content-Type'] = 'multipart/form-data; boundary=' + boundary
            h['X-CSRF-Token'] = self.token
        req = urllib.request.Request(BASE + path, data=body, headers=h, method='POST' if data is not None else 'GET')
        try:
            r = self.http.open(req, timeout=60)
        except urllib.error.HTTPError as e:
            r = e
        raw = r.read(); obj = json.loads(raw) if raw and 'application/json' in r.headers.get('Content-Type', '') else None
        if r.code not in expect:
            raise Failure(self.label + ' ' + path.split('?')[0] + ' returned ' + str(r.code) + ': ' + json.dumps(obj)[:400])
        if isinstance(obj, dict) and isinstance(obj.get('csrf'), str):
            self.token = obj['csrf']
        return obj

    def login(self, email, password):
        self.call('/api/auth.php', dict(action='login', email=email, password=password))
        return self


def php(*args):
    env = dict(os.environ, REFIND_PROFILE=PROFILE, PHP_INI_SCAN_DIR=str(ROOT / 'runtime/empty-ini-scan'))
    p = subprocess.run([str(PHP), '-c', str(INI), str(APP / 'tools/demo-data.php'), *args], cwd=APP, env=env, capture_output=True, text=True, timeout=300)
    if p.returncode:
        raise Failure('demo-data.php ' + args[0] + ' failed: ' + (p.stderr or p.stdout)[-400:])
    return json.loads(p.stdout)


class Story:
    """Steps on a campus timeline. Each step starts on a fresh wall-clock second, recorded as a marker."""

    def __init__(self, anchor):
        self.anchor = anchor; self.markers = []; self.appointments = []; self.photo_seed = 0

    def at(self, days_ago, hhmm):
        now = time.time(); time.sleep(math.ceil(now) - now + 0.02)
        wall = datetime.fromtimestamp(math.floor(time.time()), UTC)
        sim = self.when(days_ago, hhmm)
        if self.markers and sim <= self.markers[-1][1]:
            raise Failure('Story steps must move forward in time')
        self.markers.append((wall, sim))

    def when(self, days_ago, hhmm):
        return datetime.combine(self.anchor - timedelta(days=days_ago), dtime.fromisoformat(hhmm), WIB).astimezone(UTC)

    def day(self, days_ago):
        return (self.anchor - timedelta(days=days_ago)).isoformat()

    def photo(self, kind, colour, surface='desk', portrait=False, caption=None, zoom=1.0):
        self.photo_seed += 1
        return P.render(kind, 7000 + self.photo_seed, colour, surface=surface, portrait=portrait, private_caption=caption, zoom=zoom)


def slot(days, hhmm):
    """A real future campus time for the API (pickups must be scheduled in the future)."""
    return (datetime.now(WIB) + timedelta(days=days)).strftime('%Y-%m-%d') + 'T' + hhmm


def run(S, people):
    st, fd, bm, ri, ba, ci = (people[k] for k in ('student', 'finder', 'bm', 'rina', 'bayu', 'citra'))

    def report(c, kind, title, desc, cat, area, detail, event_days, event_time, clue, photos=(), private=()):
        data = dict(action='create', type=kind, title=title, description=desc, category_id=cat, location_id=area, location_detail=detail, date=S.day(event_days), time=event_time, clue=clue)
        files = [('photos[]', 'photo%d.jpg' % i, b) for i, b in enumerate(photos)] + [('ownershipPhotos[]', 'private%d.jpg' % i, b) for i, b in enumerate(private)]
        return c.call('/api/items.php', data, files, expect=201)['id']

    def claim(c, found, answer, lost='', photos=()):
        files = [('ownershipPhotos[]', 'proof%d.jpg' % i, b) for i, b in enumerate(photos)]
        return c.call('/api/claims.php', dict(action='submit', itemId=found, lostItemId=lost, answer=answer), files, expect=201)['id']

    def decide(cid, decision, note):
        rev = bm.call('/api/staff.php?resource=claims&id=' + str(cid))['claim']['revision']
        return bm.call('/api/staff.php', dict(action='review', id=cid, decision=decision, note=note, revision=rev, confirm='1'))

    def pickup(hid, action, **extra):
        rev = bm.call('/api/handovers.php?id=' + str(hid))['handovers'][0]['revision']
        bm.call('/api/handovers.php', dict(action=action, id=hid, revision=rev, confirm='1', **extra))
        return bm.call('/api/handovers.php?id=' + str(hid))['handovers'][0] if action in ('schedule', 'reschedule') else None

    def appointment(cid, scheduled_iso, days_ago, hhmm):
        S.appointments.append({'claim_id': int(cid), 'old': scheduled_iso, 'new': S.when(days_ago, hhmm).strftime('%Y-%m-%dT%H:%M:%SZ')})

    def status(item, code, note):
        rev = bm.call('/api/staff.php?resource=reports&id=' + str(item))['report']['revision']
        bm.call('/api/staff.php', dict(action='status', id=item, status=code, note=note, revision=rev, confirm='1'))

    read_all = lambda c: c.call('/api/notifications.php', dict(action='read_all'))

    # Week 4 ago ---------------------------------------------------------------------------------------------------
    S.at(27, '14:10')
    lunch = report(ri, 'FOUND', 'Plastic lunch box', 'Rectangular lunch box with a blue lid and two clips, left on a table at lunchtime.', OTHER, A_GB, 'Kantin B, Lantai 1', 27, '12:30',
                   'A cartoon cat sticker on the lid; one clip is cracked.', [S.photo('lunch_box', (60, 120, 210), 'tile')])
    S.at(26, '16:10')
    scarf = report(ba, 'FOUND', 'Grey knitted scarf', 'Long grey knitted scarf with fringed ends, found folded on a bench.', CLOTH, A_FIELD, 'Bench beside the running track', 26, '07:15',
                   'Care label says hand wash only; a red thread is tied to one end.', [S.photo('scarf', (140, 140, 146), 'grass')])
    S.at(24, '10:05')
    charger_l = report(st, 'LOST', 'Black laptop charger', 'Black USB-C laptop charger with a braided cable. I left it plugged in under the desk.', ELEC, A_GC, 'Windows Lab, Lantai 6, row 3', 24, '09:00',
                       '65 W USB-C brick with a yellow IS556 label stuck on one side.', [S.photo('charger', (38, 40, 46), 'desk')],
                       [S.photo('charger', (38, 40, 46), 'desk', caption='Yellow IS556 label', zoom=1.25)])
    S.at(24, '13:20')
    charger_f = report(fd, 'FOUND', 'Laptop charger, black USB-C', 'Black laptop charger with a braided USB-C cable, still plugged in under a lab desk.', ELEC, A_GC, 'Windows Lab, Lantai 6', 24, '11:30',
                       'The brick has a small label stuck on one side; the cable is tied with velcro.', [S.photo('charger', (34, 36, 42), 'mat')])
    S.at(23, '09:30')
    c_charger = claim(st, charger_f, 'It is a 65 W USB-C charger with a yellow IS556 label on the brick and a velcro cable tie.', charger_l, [S.photo('receipt', (0, 0, 0), 'bench', portrait=True, caption='Purchase receipt')])
    S.at(22, '10:15')
    h_charger = decide(c_charger, 'approved', 'The label and wattage match the finder\'s private clue.')['handover_id']
    S.at(22, '10:40')
    p = pickup(h_charger, 'schedule', pickup_location_id=A_GC, scheduled_at=slot(2, '10:00'))
    appointment(c_charger, p['scheduled_at'], 21, '10:00')
    S.at(21, '10:12')
    pickup(h_charger, 'complete'); read_all(st)
    S.at(20, '11:00')
    holder = report(ba, 'FOUND', 'Student ID card holder', 'Navy card holder on a lanyard, found at the counter.', WALLET, A_GA, 'Student Services counter, Lantai 1', 20, '10:30',
                    'A metro card is inside; the strap has a small UMN pin.', [S.photo('card_holder', (40, 60, 110), 'mat', portrait=True)])
    S.at(19, '14:00')
    c_holder = claim(ri, holder, 'Navy card holder with my metro card inside and a UMN pin on the strap.')
    S.at(18, '09:20')
    hoodie_f = report(fd, 'FOUND', 'Grey hoodie', 'Light grey hoodie left in the changing room.', CLOTH, A_GD, 'Sport Hall & Gym changing room', 18, '08:00',
                      'Size L; there is a paint mark on the left cuff.', [S.photo('hoodie', (150, 154, 160), 'fabric', portrait=True), S.photo('hoodie', (150, 154, 160), 'bench')])
    S.at(18, '15:30')
    hoodie_l = report(ci, 'LOST', 'Grey hoodie with paint mark', 'Light grey hoodie, size L, probably left after futsal practice.', CLOTH, A_GD, 'Sport Hall & Gym', 18, '07:30',
                      'Blue paint mark on the left cuff; the name tag was cut out.')
    S.at(17, '09:10')
    h_holder = decide(c_holder, 'approved', 'The metro card and pin match the finder\'s private clue.')['handover_id']
    S.at(17, '09:25')
    p = pickup(h_holder, 'schedule', pickup_location_id=A_GA, scheduled_at=slot(2, '13:00'))
    appointment(c_holder, p['scheduled_at'], 16, '13:00')
    S.at(17, '10:00')
    c_hoodie = claim(ci, hoodie_f, 'Light grey size L hoodie; the blue paint on the left cuff is from an art class.', hoodie_l, [S.photo('hoodie', (150, 154, 160), 'fabric', caption='Paint mark on the cuff', zoom=1.2)])
    S.at(16, '13:05')
    pickup(h_holder, 'complete')
    S.at(16, '15:40')
    umbrella = report(fd, 'FOUND', 'Black folding umbrella', 'Black folding umbrella with a wooden hook handle, left at the entrance.', OTHER, A_GA, 'Function Hall entrance', 16, '14:45',
                      'One rib is bent near the tip.', [S.photo('umbrella', (30, 30, 34), 'tile', portrait=True)])
    S.at(15, '09:30')
    h_hoodie = decide(c_hoodie, 'approved', 'The paint mark and size match the finder\'s private clue.')['handover_id']
    p = pickup(h_hoodie, 'schedule', pickup_location_id=A_GD, scheduled_at=slot(2, '11:00'))
    appointment(c_hoodie, p['scheduled_at'], 14, '11:00')
    S.at(15, '11:00')
    c_umbrella = claim(ba, umbrella, 'Black umbrella with a wooden hook handle; one rib bends near the tip.')
    S.at(14, '11:10')
    pickup(h_hoodie, 'complete')
    S.at(14, '14:20')
    h_umbrella = decide(c_umbrella, 'approved', 'The bent rib matches the finder\'s private clue.')['handover_id']
    p = pickup(h_umbrella, 'schedule', pickup_location_id=A_GA, scheduled_at=slot(2, '10:00'))
    appointment(c_umbrella, p['scheduled_at'], 13, '10:00')
    # Weeks 2-1 ago -------------------------------------------------------------------------------------------------
    S.at(13, '08:50')
    report(fd, 'FOUND', 'Wired white earphones', 'White wired earphones with a 3.5 mm jack, left on a waiting-area chair.', ELEC, A_GA, 'Admission Office waiting area', 13, '08:20',
           'The left earbud has a small crack and the cable has a knot near the jack.', [S.photo('earphones', (245, 245, 245), 'tile')])
    S.at(12, '09:40')
    report(ba, 'FOUND', 'Tumbler with mountain sticker', 'Stainless steel tumbler with a black lid and a mountain sticker.', OTHER, A_GC, 'Skystar Ventures lounge, Lantai 12', 12, '09:00',
           'There is a dent near the base.', [S.photo('tumbler', (180, 184, 190), 'bench', portrait=True)])
    S.at(12, '16:30')
    pickup(h_umbrella, 'release', note='The owner did not come at the agreed time and did not reply to two messages.')
    S.at(11, '10:20')
    pencil = report(ri, 'FOUND', 'Red pencil case', 'Red zip pencil case found on a corridor bench.', STAT, A_GA, 'Kantor Rektorat corridor, Lantai 7', 11, '09:30',
                    'Two 2B pencils and an eraser shaped like a cat are inside.', [S.photo('pencil_case', (200, 40, 40), 'bench')])
    S.at(11, '13:00')
    claim(st, pencil, 'Red zip pencil case with 2B pencils and a cat-shaped eraser.')
    S.at(10, '09:00')
    st.call('/api/claims.php', dict(action='withdraw', itemId=pencil))
    S.at(10, '11:15')
    mouse = report(fd, 'FOUND', 'White wireless mouse', 'White wireless mouse left next to a lab keyboard.', ELEC, A_GC, 'Macintosh Lab, Lantai 2', 10, '10:40',
                   'The scroll wheel is scratched and the battery cover has a small sticker; no USB receiver was found.', [S.photo('mouse', (238, 238, 240), 'mat')])
    S.at(10, '15:00')
    c_mouse = claim(ba, mouse, 'White mouse that I use in the lab; the USB receiver is stored inside it.')
    S.at(9, '09:30')
    decide(c_mouse, 'rejected', 'The description does not match the finder\'s private clue: no receiver was found with the mouse.')
    S.at(9, '10:30')
    earbuds = report(ri, 'FOUND', 'Wireless earbuds case', 'White wireless earbuds charging case found on a study desk.', ELEC, A_GB, 'Perpustakaan UMN, Lantai 2, quiet zone', 9, '10:00',
                     'A small star sticker is on the back; the left earbud is missing.', [S.photo('earbuds_case', (240, 240, 242), 'desk')])
    S.at(9, '14:10')
    report(ba, 'LOST', 'Grey zip-up jacket', 'Grey zip-up jacket, probably left on a seat after a lecture.', CLOTH, A_GD, 'Lecture Theater', 9, '13:00',
           'A bus card is in the inside pocket.')
    S.at(8, '09:45')
    calc_f = report(ci, 'FOUND', 'Scientific calculator', 'Grey scientific calculator found under a seat.', STAT, A_GD, 'Lecture Theater, row F', 8, '09:15',
                    'A name was scratched off the back and the solar panel is cracked.', [S.photo('calculator', (90, 94, 100), 'tile', portrait=True)])
    S.at(8, '11:30')
    calc_l = report(fd, 'LOST', 'Scientific calculator', 'Grey scientific calculator, lost during the morning lecture.', STAT, A_GD, 'Lecture Theater', 8, '08:30',
                    'The solar panel is cracked and the back has a scratched-off name.')
    S.at(8, '15:00')
    c_earbuds = claim(st, earbuds, 'White earbuds case with a star sticker; only the right earbud is inside because I lost the left one.', photos=[S.photo('receipt', (0, 0, 0), 'bench', portrait=True, caption='Purchase receipt')])
    S.at(7, '10:00')
    c_calc = claim(fd, calc_f, 'Grey calculator; the solar panel is cracked and the back has a scratched-off name.', calc_l)
    S.at(7, '13:20')
    glasses = report(ba, 'FOUND', 'Prescription glasses in a brown case', 'Black-framed prescription glasses in a brown hard case.', ACC, A_GB, 'Libro Cafe, table near the window', 7, '12:40',
                     'Initials are embossed inside the case.', [S.photo('glasses', (20, 20, 24), 'desk')])
    S.at(7, '15:00')
    h_earbuds = decide(c_earbuds, 'approved', 'The star sticker and the missing left earbud match the finder\'s private clue.')['handover_id']
    read_all(fd)
    S.at(6, '09:20')
    p = pickup(h_earbuds, 'schedule', pickup_location_id=A_GB, scheduled_at=slot(1, '14:00'))
    appointment(c_earbuds, p['scheduled_at'], 2, '14:00')  # this pickup's time has now passed (manual release stays the BM's call)
    S.at(6, '10:00')
    bottle_l = report(st, 'LOST', 'Blue water bottle', 'Blue insulated water bottle, left near the basketball court.', OTHER, A_FIELD, 'Near the basketball court', 6, '07:30',
                      'The lid is dented and a strip of white tape marked "A." is on the bottom.', [S.photo('bottle', (40, 110, 190), 'grass', portrait=True)],
                      [S.photo('bottle', (40, 110, 190), 'bench', portrait=True, caption='Tape marked A. on the bottom', zoom=1.2)])
    S.at(6, '11:30')
    bottle_f = report(ba, 'FOUND', 'Blue insulated water bottle', 'Blue insulated bottle found on the court bench.', OTHER, A_FIELD, 'Basketball court bench', 6, '08:00',
                      'The lid is dented and there is tape with a letter on the bottom.', [S.photo('bottle', (36, 100, 180), 'grass', portrait=True)])
    S.at(6, '14:00')
    c_glasses = claim(ci, glasses, 'Black prescription frames; the case has my initials C.L. embossed inside.')
    S.at(5, '09:30')
    h_calc = decide(c_calc, 'approved', 'The cracked solar panel and scratched name match the finder\'s private clue.')['handover_id']
    S.at(5, '10:00')
    pickup(h_calc, 'schedule', pickup_location_id=A_GD, scheduled_at=slot(2, '13:00'))
    S.at(5, '11:00')
    c_bottle = claim(st, bottle_f, 'Blue insulated bottle with a dented lid and white tape marked "A." on the bottom.', bottle_l)
    read_all(st)
    S.at(5, '14:30')
    denim = report(ri, 'FOUND', 'Denim jacket', 'Blue denim jacket left on a motorbike seat.', CLOTH, A_PARK, 'Motorbike parking, row B', 5, '13:50',
                   'Two pin badges on the collar and a receipt in the chest pocket.', [S.photo('jacket', (70, 100, 150), 'tile', portrait=True)])
    S.at(4, '09:10')
    h_glasses = decide(c_glasses, 'approved', 'The embossed initials match the finder\'s private clue.')['handover_id']
    pickup(h_glasses, 'schedule', pickup_location_id=A_GB, scheduled_at=slot(2, '11:00'))
    S.at(4, '10:30')
    watch = report(ba, 'FOUND', 'Silver smartwatch', 'Silver smartwatch with a grey band, found near the lockers.', ELEC, A_GD, 'Sport Hall & Gym, near the lockers', 4, '07:45',
                   'The lock screen shows a cat photo; the band has a small tear near the buckle.', [S.photo('smartwatch', (190, 194, 200), 'mat', portrait=True)])
    S.at(4, '13:00')
    pickup(h_calc, 'reschedule', pickup_location_id=A_GD, scheduled_at=slot(3, '14:00'), note='The owner has a lab session at the first time.')
    S.at(4, '15:30')
    report(ci, 'LOST', 'Silver bracelet', 'Thin silver chain bracelet, lost during an evening event.', ACC, A_GA, 'Function Hall', 5, '19:00', 'A small moon charm hangs from the clasp.')
    S.at(4, '16:00')
    pickup(h_glasses, 'cancel_schedule', note='The Gedung B desk is closed for building maintenance this week.')
    # This week -----------------------------------------------------------------------------------------------------
    S.at(3, '09:00')
    keys_f = report(fd, 'FOUND', 'Key ring with two keys', 'Key ring with a brass key and a silver key, found at the gate.', ACC, A_PARK, 'Car park entrance gate', 3, '08:30',
                    'A green plastic tag with the number 14.', [S.photo('keys', (0, 0, 0), 'bench')])
    S.at(3, '10:00')
    keys_l = report(ba, 'LOST', 'Key ring with green tag', 'Two keys on a ring with a green tag.', ACC, A_PARK, 'Car park', 3, '07:50', 'The tag is numbered 14; one key is brass and one is silver.')
    S.at(3, '11:00')
    phones = report(ci, 'FOUND', 'Black over-ear headphones', 'Black over-ear headphones left on a mixing desk.', ELEC, A_GB, 'Sound Design Lab, Lantai 6', 3, '10:15',
                    'The right ear cushion is peeling and there is a sticker inside the headband.', [S.photo('headphones', (30, 30, 34), 'desk'), S.photo('headphones', (30, 30, 34), 'mat', portrait=True)])
    S.at(3, '13:30')
    claim(st, phones, 'Black over-ear headphones; the right cushion is peeling and there is a red sticker inside the headband.')
    S.at(3, '14:30')
    c_watch = claim(st, watch, 'Silver smartwatch with a grey band.')
    S.at(3, '15:00')
    c_keys = claim(ba, keys_f, 'A green tag numbered 14 with one brass and one silver key.', keys_l)
    for c in (ri, ba, ci):
        read_all(c)
    S.at(2, '09:00')
    h_bottle = decide(c_bottle, 'approved', 'The tape mark and dented lid match the finder\'s private clue.')['handover_id']
    pickup(h_bottle, 'schedule', pickup_location_id=A_GB, scheduled_at=slot(1, '10:00'))
    S.at(2, '09:20')
    decide(c_watch, 'need_more_info', 'Please describe the lock screen picture or any mark on the band.')
    S.at(2, '10:00')
    decide(c_keys, 'approved', 'The tag number and the two keys match the finder\'s private clue.')
    S.at(2, '11:00')
    claim(ba, phones, 'Black headphones that I left in the lab yesterday afternoon.')
    S.at(2, '13:00')
    c_denim = claim(fd, denim, 'Blue denim jacket with pin badges on the collar.')
    S.at(2, '15:00')
    decide(c_denim, 'need_more_info', 'Please describe the pin badges and what is in the chest pocket.')
    S.at(1, '09:00')
    fd.call('/api/claims.php', dict(action='resubmit', itemId=denim, lostItemId='', answer='Two pin badges: a yellow smiley and a black camera. The chest pocket has a coffee shop receipt.', keep_photo_ids='[]'),
            [('ownershipPhotos[]', 'proof0.jpg', S.photo('jacket', (70, 100, 150), 'fabric', portrait=True, caption='Pin badges on the collar', zoom=1.2))])
    S.at(1, '09:40')
    wallet_f = report(ri, 'FOUND', 'Brown leather wallet', 'Brown leather bifold wallet found on a rooftop bench.', WALLET, A_GC, 'Rooftop Parks, Lantai 3, bench', 2, '17:30',
                      'A library card and a laundry receipt are inside; the stitching is loose on one corner.', [S.photo('wallet', (120, 78, 46), 'bench'), S.photo('wallet', (120, 78, 46), 'desk', portrait=True)])
    S.at(1, '10:30')
    wallet_l = report(st, 'LOST', 'Brown leather wallet', 'Brown leather bifold wallet, lost after sitting in the rooftop garden.', WALLET, A_GC, 'Rooftop Parks', 2, '17:00',
                      'My library card and a laundry receipt are inside; the stitching is loose on the bottom corner.', [S.photo('wallet', (124, 80, 48), 'desk')],
                      [S.photo('wallet', (124, 80, 48), 'desk', caption='Loose stitching, bottom corner', zoom=1.3)])
    S.at(1, '11:00')
    claim(st, wallet_f, 'Brown leather wallet with my library card and a laundry receipt; the stitching is loose on the bottom corner.', wallet_l,
          [S.photo('wallet', (124, 80, 48), 'bench', caption='Library card inside', zoom=1.2)])
    S.at(1, '12:00')
    usb = report(ci, 'LOST', 'USB flash drive 64 GB', 'Black 64 GB USB flash drive on a red lanyard.', ELEC, A_PARK, 'Motorbike parking, row A', 1, '08:15', 'The drive holds a thesis draft folder named "Bab 3".')
    S.at(1, '13:30')
    status(usb, 'UNDER_REVIEW', 'Checking with the parking office for a returned drive.')
    S.at(1, '14:00')
    fob = report(fd, 'FOUND', 'Car key fob', 'Black two-button car key fob on a ring with a leather tag.', ACC, A_PARK, 'Car park level B1, near the lift', 1, '13:20',
                 'The leather tag has a stitched letter on it.', [S.photo('key_fob', (26, 26, 30), 'bench', portrait=True)])
    S.at(1, '15:00')
    status(fob, 'UNDER_REVIEW', 'Checking the parking office log for a matching report.')
    S.at(1, '16:00')
    status(scarf, 'UNCLAIMED_CLOSED', 'Held for the collection period; no owner came forward.')
    status(lunch, 'UNCLAIMED_CLOSED', 'Held for the collection period; no owner came forward.')
    read_all(bm)
    S.at(0, '08:30')
    report(ri, 'LOST', 'Student ID card', 'Student photo ID card on a blue lanyard clip.', WALLET, A_GD, 'Ruang Kelas D601', 1, '15:00', 'A blue lanyard clip is attached to the card.')
    S.at(0, '08:50')
    report(fd, 'LOST', 'Library book: Interaction Design', 'Library copy of an interaction design textbook.', STAT, A_GB, 'Perpustakaan UMN, Lantai 2', 1, '16:00',
           'Library barcode on the spine and a green sticky note on page 40.', [S.photo('book', (30, 120, 120), 'desk', portrait=True)])
    S.at(0, '09:15')
    report(ba, 'FOUND', 'Highlighter set', 'Set of four highlighters held together with a rubber band.', STAT, A_GD, 'Ruang Kelas D401', 0, '08:40',
           'Four colours; the pink one has lost its cap.', [S.photo('highlighters', (0, 0, 0), 'mat')])
    S.at(0, '09:40')
    report(fd, 'FOUND', 'Power bank 10000 mAh', 'Dark blue 10000 mAh power bank found on a lounge sofa.', ELEC, A_GC, 'Skystar Ventures, Lantai 12', 0, '09:00',
           'A small dent on one corner and a rocket sticker.', [S.photo('power_bank', (30, 50, 90), 'fabric', portrait=True)])
    S.at(0, '10:00')
    report(ba, 'LOST', 'Black cap', 'Plain black baseball cap.', CLOTH, A_FIELD, 'Football field stands', 1, '16:30', 'Initials are embroidered on the back strap.', [S.photo('cap', (28, 28, 32), 'desk')])
    S.at(0, '10:20')
    report(ci, 'FOUND', 'Black baseball cap', 'Black baseball cap found on the stands.', CLOTH, A_FIELD, 'Football field stands', 0, '07:30', 'Initials embroidered on the back strap.', [S.photo('cap', (28, 28, 32), 'grass')])
    S.at(0, '10:45')
    report(fd, 'FOUND', 'USB-C cable, white', 'White braided USB-C cable left at a charging station.', ELEC, A_GB, 'Perpustakaan UMN, Lantai 2, charging station', 0, '09:50',
           'About 1 m long with a tiny knot near one end.', [S.photo('cable', (245, 245, 245), 'mat')])
    report(ri, 'LOST', 'Phone charger cable, white', 'White braided USB-C charging cable.', ELEC, A_GB, 'Perpustakaan UMN, Lantai 2', 1, '17:00', 'Braided, about 1 m, with a knot near one end.')
    S.at(0, '11:00')
    report(ri, 'FOUND', 'Sunglasses', 'Tortoiseshell sunglasses found on a motorbike seat.', ACC, A_PARK, 'Motorbike parking, row D', 0, '10:15',
           'One nose pad is missing.', [S.photo('sunglasses', (110, 70, 40), 'bench')])


def password():
    return 'demo-' + secrets.choice(WORDS) + '-' + str(secrets.randbelow(90) + 10)


def write_accounts(accounts, anchor):
    data = {'url': BASE + '/', 'profile': PROFILE, 'note': 'Synthetic demo accounts for the persistent localhost demo only. Private: outside Git and outside the served folder.',
            'created_at': datetime.now(UTC).isoformat(timespec='seconds'), 'story_anchor_day': anchor.isoformat(), 'accounts': accounts}
    ACCOUNTS.write_text(json.dumps(data, indent=2), encoding='utf-8')
    lines = ['# ReFind localhost demo: accounts (private)', '', 'Open ' + BASE + '/ and sign in with one of these synthetic accounts.', '',
             '| Account | Role | Email | Password | In the demo story |', '|---|---|---|---|---|']
    for a in accounts:
        lines.append('| ' + ' | '.join([a['name'], a['role_label'], a['email'], a['password'], a['story']]) + ' |')
    lines += ['', 'This file is private: it sits in C:\\ReFind-Laravel\\state\\demo, outside Git and outside the served folder.',
              'A reset (reset-demo) restores these same passwords. Changing a password in the app is kept until the next reset.']
    GUIDE.write_text('\n'.join(lines) + '\n', encoding='utf-8')


STORY_ROLES = {'student': 'Main Student: lost reports, claims in every state, two pickups (one upcoming, one past its time)', 'finder': 'Reports most Found items; one rescheduled pickup',
               'bm': 'Reviews claims, schedules pickups, sets report status', 'rina': 'Finder and claimant (returned card holder)', 'bayu': 'Finder; rejected and released claims, pickup awaiting a time',
               'citra': 'Finder and claimant (returned hoodie, cancelled pickup)', 'new': 'Fresh account created through the registration page; no activity yet'}


def seed():
    if APP.name != 'laravel-demo':
        raise Failure('Run the demo seed from the laravel-demo checkout only')
    if not (STATE / 'provisioned.json').is_file():
        raise Failure('Provision the demo first: phase6-control.py init --profile demo')
    if SEED.exists() or ACCOUNTS.exists():
        raise Failure('The demo is already seeded. Its data is kept; use reset-demo.ps1 to return to the seeded baseline.')
    guest = Client('guest')
    session = guest.call('/api/session.php')
    if session.get('registration_enabled') is not True:
        raise Failure('Registration is not enabled on the demo worker; check the demo grants and stages')
    creds = json.loads((STATE / 'credentials.json').read_text(encoding='utf-8'))
    print('fixtures:', php('clear-fixtures')['removed_media'], 'provisioning media removed')
    now = datetime.now(WIB)
    anchor = now.date() if now.time() >= dtime(12, 15) else now.date() - timedelta(days=1)
    S = Story(anchor)
    S.at(28, '09:00')  # accounts: names, passwords, avatars and three registered helpers
    people, accounts = {}, []
    for key, base in (('student', 'student_a'), ('finder', 'student_b'), ('bm', 'bm')):
        c = Client(key).login(creds[base]['email'], creds[base]['password'])
        pw = password()
        c.call('/api/account.php', dict(action='update_profile', name=PEOPLE[key][0]))
        c.call('/api/account.php', dict(action='change_password', current_password=creds[base]['password'], new_password=pw, confirm_password=pw))
        people[key] = Client(key).login(creds[base]['email'], pw)
        accounts.append({'key': key, 'email': creds[base]['email'], 'password': pw})
    for key, initials, colour in (('student', 'DS', (16, 118, 120)), ('finder', 'DF', (196, 98, 32)), ('bm', 'BM', (40, 64, 120))):
        people[key].call('/api/account.php', dict(action='update_avatar'), [('avatar', 'avatar.jpg', P.avatar(initials, colour))])
    for key in ('rina', 'bayu', 'citra'):
        c = Client(key); pw = password()
        c.call('/api/register.php', dict(name=PEOPLE[key][0], email=REGISTERED_EMAILS[key], password=pw, confirm_password=pw), expect=201)
        people[key] = c; accounts.append({'key': key, 'email': REGISTERED_EMAILS[key], 'password': pw})
    run(S, people)
    S.at(0, '11:30')
    c = Client('new'); pw = password()
    c.call('/api/register.php', dict(name=PEOPLE['new'][0], email=REGISTERED_EMAILS['new'], password=pw, confirm_password=pw), expect=201)
    accounts.append({'key': 'new', 'email': REGISTERED_EMAILS['new'], 'password': pw})
    plan = STATE / 'tmp' / 'demo-timeline.json'
    plan.write_text(json.dumps({'anchor_day': anchor.isoformat(), 'scale': 60, 'markers': [[w.isoformat(), s.isoformat()] for w, s in S.markers], 'appointments': S.appointments}, indent=2), encoding='utf-8')
    result = php('timeline', str(plan))
    print('timeline:', len(S.markers), 'steps mapped onto', anchor - timedelta(days=28), '..', anchor, '|', result['rows_updated'], 'rows |', len(result.get('appointments', [])), 'appointments')
    for a in accounts:
        name, role, how = PEOPLE[a['key']]
        a.update(name=name, role=role, role_label='Building Management' if role == 'BUILDING_MANAGEMENT' else 'Student', created_by=how, story=STORY_ROLES[a['key']])
    write_accounts(accounts, anchor)
    data = json.loads(SEED.read_text(encoding='utf-8')); data.update(state='seeded', seeded_at=datetime.now(UTC).isoformat(timespec='seconds'), accounts=len(accounts))
    SEED.write_text(json.dumps(data, indent=2), encoding='utf-8')
    check()
    status = php('status')
    print('seeded:', json.dumps(status['counts']))
    print('accounts: ' + str(len(accounts)) + ' (credentials only in ' + str(GUIDE) + ')')


def check():
    data = json.loads(ACCOUNTS.read_text(encoding='utf-8'))
    for a in data['accounts']:
        c = Client(a['key']).login(a['email'], a['password'])
        who = c.call('/api/session.php')
        if not who.get('authenticated') or who['user']['role'] != a['role']:
            raise Failure('Demo account ' + a['key'] + ' did not sign in with the expected role')
    print('sign-in check: ' + str(len(data['accounts'])) + ' demo accounts OK')


if __name__ == '__main__':
    ap = argparse.ArgumentParser()
    ap.add_argument('action', choices=['seed', 'check'])
    a = ap.parse_args()
    try:
        seed() if a.action == 'seed' else check()
    except Failure as e:
        print('ERROR: ' + str(e), file=sys.stderr); sys.exit(1)
