"""Every named colour in AppColors against both grounds, measured.

    python tool/contrast_audit.py        # from mobile-app/

Run it after changing any colour in `lib/theme/app_theme.dart`.

A colour change is a change to everything drawn on that colour, and none
of it appears in a diff. Moving the accent from a mid-orange to the yellow
took the app's logo from 2.4:1 to 1.63:1 and made the spinner inside seven
buttons invisible — without a line of code changing. Eyeballing found
those two; measuring found two more that eyeballing had walked past.

Colours meant for one ground only are declared below and are not reported.
A tool that flags what it was told to expect gets ignored, and then it is
no use on the day something is actually wrong.

WCAG: 4.5 for body text, 3.0 for large text and icons.

Reads the palette out of the source, so it cannot drift from it.
"""
import io
import re
import sys

# --- what each colour is for, so the report only speaks when it should ---

# Never drawn on anything — these *are* the grounds.
GROUNDS = {
    'iron', 'ironSurface', 'ironCard', 'ironLine',
    'ash', 'ashSurface', 'ashLine',
}

# Meant for one ground only, and the code picks between them. Reporting
# these would be reporting the design.
NIGHT_ONLY = {'signal', 'emberHot', 'successDark', 'dangerDark', 'emberPale'}
DAY_ONLY = {'signalInk', 'crust', 'success', 'danger', 'emberCool'}

# Only ever sits on top of a fill, never on a ground.
ON_FILL_ONLY = {'onSignal'}

# Every place the app paints text or an icon on a coloured fill, with the
# fill it sits on. Add a row here whenever a new one is written.
ON_FILL = [
    ('onSignal', 'signal', 'button label, logo mark'),
    ('FFFFFF', 'success', 'the tick on the done screen'),
    ('iron', 'successDark', 'station rail, done — dark theme'),
    ('FFFFFF', 'success', 'station rail, done — light theme'),
    ('FFFFFF', 'moneyIn', 'filled money chips'),
    ('FFFFFF', 'moneyOut', 'filled money chips'),
    ('FFFFFF', 'stock', 'record sheet, intake'),
    ('FFFFFF', 'partner', 'record sheet, consignment'),
    ('FFFFFF', 'attention', 'filled warnings'),
]


def luminance(hex6):
    parts = [int(hex6[i:i + 2], 16) / 255 for i in (0, 2, 4)]
    parts = [c / 12.92 if c <= 0.03928 else ((c + 0.055) / 1.055) ** 2.4 for c in parts]
    return 0.2126 * parts[0] + 0.7152 * parts[1] + 0.0722 * parts[2]


def ratio(a, b):
    hi, lo = sorted([luminance(a), luminance(b)], reverse=True)
    return (hi + 0.05) / (lo + 0.05)


source = io.open('lib/theme/app_theme.dart', encoding='utf8').read()

named = {}
for m in re.finditer(r'static const Color (\w+) = Color\(0x[Ff][Ff]([0-9A-Fa-f]{6})\)', source):
    named[m.group(1)] = m.group(2).upper()

# Aliases written as `static const Color a = b;`
for m in re.finditer(r'static const Color (\w+) = (\w+);', source):
    if m.group(2) in named:
        named[m.group(1)] = named[m.group(2)]

missing = [n for n in GROUNDS | NIGHT_ONLY | DAY_ONLY | ON_FILL_ONLY if n not in named]
if missing:
    print('these are declared here but no longer in the palette:', ', '.join(sorted(missing)))
    print('update the lists at the top of this file.')
    sys.exit(1)

NIGHT, CARD = named['iron'], named['ironCard']
DAY, WHITE = named['ash'], named['ashSurface']

problems = []

print(f'{"colour":<16}{"hex":<9}{"night":>8}{"card":>8}{"day":>8}{"white":>8}   ')
print('-' * 62)

for name, hexv in named.items():
    if name in GROUNDS or name in ON_FILL_ONLY:
        continue

    night = max(ratio(hexv, NIGHT), ratio(hexv, CARD))
    day = max(ratio(hexv, DAY), ratio(hexv, WHITE))

    thin = []
    if night < 3.0 and name not in DAY_ONLY:
        thin.append('night')
    if day < 3.0 and name not in NIGHT_ONLY:
        thin.append('day')

    note = ''
    if name in NIGHT_ONLY:
        note = '(night only)'
    elif name in DAY_ONLY:
        note = '(day only)'

    if thin:
        note = 'THIN ON ' + ' & '.join(thin).upper()
        problems.append(f'{name} (#{hexv}) is {note.lower()}')

    print(
        f'{name:<16}#{hexv:<8}'
        f'{ratio(hexv, NIGHT):>8.2f}{ratio(hexv, CARD):>8.2f}'
        f'{ratio(hexv, DAY):>8.2f}{ratio(hexv, WHITE):>8.2f}   {note}'
    )

print()
print('--- drawn on a fill ---')

for fg, bg, where in ON_FILL:
    f = named.get(fg, fg)
    b = named.get(bg, bg)
    r = ratio(f, b)

    if r >= 4.5:
        mark = 'ok'
    elif r >= 3.0:
        mark = 'large only'
    else:
        mark = 'FAILS'
        problems.append(f'{fg} on {bg} is {r:.2f}:1 — {where}')

    print(f'  {fg:<10} on {bg:<13}{r:>7.2f}   {mark:<11} {where}')

print()

if problems:
    print('to fix:')
    for line in problems:
        print('  ', line)
    sys.exit(1)

print('every colour reads on the ground it is used against.')
