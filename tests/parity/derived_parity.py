"""Record the static preview's derived results so the PHP ports can be checked against them.

`capture` runs the preview's own build (build_site.build) on published.json with its output
directed to a scratch directory, and records the exact arguments and results of every call into
team_groups.build_groups, athlete_ids.build, events.event_of and events.value. The PHP parity
test (DerivedFullParityTest) reads inputs.json and asserts it reproduces expected.json.

`fixtures` cuts small, committable cases from a capture into tests/fixtures/derived/ and records
the Python results for them. Athlete names on club rosters are replaced by stable pseudonyms
(roster rules use only set overlap), and athlete joining uses a synthetic set.

    python3 -I tests/parity/derived_parity.py capture --meets-root DIR --out DIR
    python3 -I tests/parity/derived_parity.py fixtures --meets-root DIR --capture DIR --out tests/fixtures/derived
"""
import argparse
import collections
import concurrent.futures
import difflib
import hashlib
import json
import os
import pathlib
import random
import subprocess
import sys
import tempfile
import unicodedata

RACE_SEP = '\x1f'


def load_modules(meets_root):
    scripts = pathlib.Path(meets_root).resolve() / 'scripts'
    sys.path.insert(0, str(scripts))
    import athlete_ids
    import build_site
    import events
    import team_groups
    return build_site, team_groups, athlete_ids, events


def race_id(race):
    path, key = race
    if not isinstance(path, str) or not isinstance(key, str):
        raise TypeError(f'unexpected race id {race!r}')
    return path + RACE_SEP + key


def capture(args):
    build_site, team_groups, athlete_ids, events = load_modules(args.meets_root)
    rec = {}
    orig_groups, orig_build, orig_event_of, orig_value = team_groups.build_groups, athlete_ids.build, events.event_of, events.value

    def build_groups(counts, slug, overrides=None, manual=(), separate=(), rosters=None):
        mapping, groups = orig_groups(counts, slug, overrides, manual, separate, rosters)
        rec.setdefault('clubs', []).append({
            'input': {'counts': [[k, n] for k, n in counts.items()],
                      'rosters': {k: sorted(v) for k, v in (rosters or {}).items()},
                      'overrides': dict(overrides or {}), 'manual': [list(x) for x in manual], 'separate': list(separate)},
            'expected': {'name_to_group': [[k, v] for k, v in mapping.items()], 'groups': [[k, v] for k, v in groups.items()],
                         'base': [[k, team_groups.base(k)] for k in counts]},
        })
        return mapping, groups

    def build(entries, slug, team_of, manual=(), separate=()):
        entries = list(entries)
        out, evidence = orig_build(entries, slug, team_of, manual, separate)
        names = list(dict.fromkeys(e[0] for e in entries))
        rec.setdefault('athletes', []).append({
            'input': {'entries': [[n, c, s, race_id(r)] for n, c, s, r in entries], 'team_of': dict(team_of),
                      'manual': [list(x) for x in manual], 'separate': list(separate)},
            'expected': {'map': [[k, v] for k, v in out.items()], 'evidence': evidence,
                         'canon': [[n, athlete_ids.canon(n)] for n in names]},
        })
        return out, evidence

    seen_event, seen_value = {}, {}

    def event_of(sport, label, meet_title):
        r = orig_event_of(sport, label, meet_title)
        seen_event.setdefault((sport, label, meet_title), r)
        return r

    def value(event, mark):
        r = orig_value(event, mark)
        seen_value.setdefault((event, mark), list(r))
        return r

    team_groups.build_groups, athlete_ids.build, events.event_of, events.value = build_groups, build, event_of, value
    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(dir=out) as site:
        build_site.SITE = pathlib.Path(site) / 'site'
        build_site.build()
    rec['events'] = {'input': {'event_of': [list(k) for k in seen_event], 'value': [list(k) for k in seen_value]},
                     'expected': {'event_of': list(seen_event.values()), 'value': list(seen_value.values())}}
    unassigned = sorted({f'U+{ord(ch):04X}' for text in iter_text(rec) for ch in text if unicodedata.category(ch) == 'Cn'})
    inputs = {k: [c['input'] for c in v] if isinstance(v, list) else v['input'] for k, v in rec.items()}
    expected = {k: [c['expected'] for c in v] if isinstance(v, list) else v['expected'] for k, v in rec.items()}
    expected['unassigned_code_points'] = unassigned
    scripts = pathlib.Path(args.meets_root).resolve() / 'scripts'
    expected['sources'] = {f: hashlib.sha256((scripts / f).read_bytes()).hexdigest()
                           for f in ('build_site.py', 'events.py', 'team_groups.py', 'athlete_ids.py', 'points_view.py')}
    write(out / 'inputs.json', inputs)
    expected['order_dependent'] = order_dependent(args, out / 'inputs.json')
    write(out / 'expected.json', expected)
    print(json.dumps({'club_calls': [[len(c['counts']), len(e['groups'])] for c, e in zip(inputs['clubs'], expected['clubs'])],
                      'athlete_calls': [[len(c['entries']), len(e['map']), len(e['evidence'])] for c, e in zip(inputs['athletes'], expected['athletes'])],
                      'event_of': len(seen_event), 'value': len(seen_value),
                      'order_dependent': expected['order_dependent'],
                      'unassigned_code_points': unassigned}))


def order_dependent(args, inputs_path):
    """Printed club names whose group changes with PYTHONHASHSEED, with every group seen.

    build_groups breaks some ties by set iteration order, which Python randomizes per process,
    so the reference itself varies for these names; the PHP port must give one of the seen results."""
    def run(seed):
        env = dict(os.environ, PYTHONHASHSEED=str(seed))
        cmd = [sys.executable, '-I', __file__, 'regroup', '--meets-root', args.meets_root, '--inputs', str(inputs_path)]
        return json.loads(subprocess.run(cmd, env=env, check=True, capture_output=True, text=True).stdout)
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.seeds) as pool:
        runs = list(pool.map(run, range(args.seeds)))
    result = []
    for i in range(len(runs[0])):
        seen = collections.defaultdict(set)
        for r in runs:
            for name, group in r[i].items():
                seen[name].add(group)
        result.append({name: sorted(gs) for name, gs in seen.items() if len(gs) > 1})
    return result


def regroup(args):
    build_site, team_groups, _, _ = load_modules(args.meets_root)
    inputs = json.loads(pathlib.Path(args.inputs).read_text(encoding='utf-8'))
    out = []
    for c in inputs['clubs']:
        mapping, _ = team_groups.build_groups(dict(map(tuple, c['counts'])), build_site.slug, c['overrides'], c['manual'], c['separate'],
                                              {k: set(v) for k, v in c['rosters'].items()})
        out.append(mapping)
    json.dump(out, sys.stdout)


def iter_text(obj):
    if isinstance(obj, str):
        yield obj
    elif isinstance(obj, dict):
        for k, v in obj.items():
            yield from iter_text(k)
            yield from iter_text(v)
    elif isinstance(obj, (list, tuple)):
        for v in obj:
            yield from iter_text(v)


def write(path, data):
    path.write_text(json.dumps(data, ensure_ascii=False, separators=(',', ':')) + '\n', encoding='utf-8')


def pseudonym(name):
    return 'athlete-' + hashlib.sha256(name.encode()).hexdigest()[:10]


def fixtures(args):
    build_site, team_groups, athlete_ids, events = load_modules(args.meets_root)
    cap = pathlib.Path(args.capture)
    inputs = json.loads((cap / 'inputs.json').read_text(encoding='utf-8'))
    expected = json.loads((cap / 'expected.json').read_text(encoding='utf-8'))
    rng = random.Random(20261101)
    out = pathlib.Path(args.out)
    out.mkdir(parents=True, exist_ok=True)

    # Events: every distinct derived event once, plus a seeded sample of the rest.
    ev_in, ev_out = inputs['events'], expected['events']
    pairs = list(zip(ev_in['event_of'], ev_out['event_of']))
    by_result = {}
    for args_, r in pairs:
        by_result.setdefault(r, []).append(args_)
    picked = [rng.choice(v) for k, v in sorted(by_result.items(), key=lambda kv: -len(kv[1]))[:200]]
    rest = [a for a, _ in pairs if a not in picked]
    picked += rng.sample(rest, min(len(rest), 100))
    vals = list(zip(ev_in['value'], ev_out['value']))
    by_kind = {}
    for (event, mark), r in vals:
        kind = (r[1], type(r[0]).__name__, bool(event) and bool(events.FIELD.search(event)), r[0] is None and events.secs(mark) is not None)
        by_kind.setdefault(kind, []).append([event, mark])
    vpicked = []
    for k in sorted(by_kind, key=repr):
        vpicked += rng.sample(by_kind[k], min(len(by_kind[k]), 40))
    extra_marks = ['10.5', '1:02:03.45', '4:05', '17-04.50', "17' 4.5\"", '5.23m', '5.23', '17ft 4in', '6543', 'DNF', 'NH', '',
                   '0', '0.0', '12.3q', '9:59.9*', '20-00', ' 13.2 ', '1:00:00', '45.10w', '29:48:00', '23:20', '3:20:00']
    for ev in ['100m', 'Long Jump', 'Decathlon', 'Shot Put (Midget)', '10K', 'Marathon', '4K XC', '2.5 Mile XC', None, '']:
        for mk in extra_marks:
            vpicked.append([ev, mk])
    extra_labels = [['Track & Field', '1500 Meter Run Midget Girls', 'X'], ['Youth Track & Field', '80 Meter Hurdles Midget Girls', 'X'],
                    ['Youth Track & Field', 'Shot Put Bantam Boys', 'X'], ['Track & Field', '1 Mile Run -A Section', 'X'],
                    ['Track & Field', '4 x 100 Meter Relay Varsity', 'X'], ['Track & Field', '110 Hurdles Frosh-Soph Boys', 'X'],
                    ['Road', 'Men 40-49', 'Half Marathon'], ['Ultra', 'Overall', 'American River 50 Mile'], ['Cross Country', 'Open Men', 'Golden Gate 10 km'],
                    ['Youth Cross Country', 'Girls 2 mile', 'Champs'], ['Youth Cross Country', '3000m', 'Meet'], ['Race Walk', '5K', '5K walk'],
                    ['Track & Field', '', ''], ['Road', '', '']]
    picked += extra_labels
    write(out / 'events.json', {
        'event_of': [[*a, events.event_of(*a)] for a in picked],
        'value': [[e, m, list(events.value(e, m))] for e, m in vpicked],
        'road_distance': [[t, events.road_distance(t)] for t in ['Half Marathon', '13.1 mi', '1/2 Marathon', 'Marathon', '50-mile', '50 K', '30k',
                                                                  '10-Mile', '10 k', '8k', '5 Mile', '5K', 'Mile', 'Turkey Trot', '']],
        'secs': [[m, events.secs(m)] for m in extra_marks],
        'distance': [[m, events.distance(m)] for m in extra_marks],
    })

    # Clubs: base() on a sample of printed names; build_groups on every name of a sample of
    # multi-variant groups plus single-name groups, with pseudonymous rosters.
    club = inputs['clubs'][0]
    counts = dict(map(tuple, club['counts']))
    n2g = dict(map(tuple, expected['clubs'][0]['name_to_group']))
    by_group = {}
    for name in counts:
        by_group.setdefault(n2g.get(name), []).append(name)
    multi = [g for g, ns in by_group.items() if g and len(ns) > 1]
    single = [g for g, ns in by_group.items() if g and len(ns) == 1]
    gsel = set(rng.sample(multi, min(len(multi), 60)) + rng.sample(single, min(len(single), 40)))
    for override_core in club['overrides']:
        for name in counts:
            if team_groups.base(name).rstrip('s') == override_core.rstrip('s'):
                gsel.add(n2g.get(name))
    for src, dst in club['manual'][:15]:
        for name in (src, dst):
            if name in n2g:
                gsel.add(n2g[name])
    unat = [n for n in counts if n not in n2g][:10]
    names = [n for n in counts if n2g.get(n) in gsel] + unat
    sub_counts = [[n, counts[n]] for n in names]
    sub_rosters = {n: sorted(pseudonym(a) for a in club['rosters'][n]) for n in names if n in club['rosters']}
    rosters_sets = {n: set(v) for n, v in sub_rosters.items()}
    mapping, groups = team_groups.build_groups(dict(map(tuple, sub_counts)), build_site.slug, club['overrides'], club['manual'], club['separate'], rosters_sets)
    base_names = rng.sample(list(counts), min(len(counts), 400)) + [
        'Los GatosTC', 'Pleasanton H,Pleasa', '38-West Valley', '38 PH', "Strider 'A'", 'Strider B', 'Strider B Team', 'StridersB',
        'Run T & F', 'Club $1,250.00', 'WV J&S', 'Nor Cal #1', 'Empire Track Fi', 'Empire TF Clu', 'Empire Athele', 'Empire Track C',
        '3M TC', 'M.P.Striders', 'MP Striders', 'Unattached', 'Café Racers', '', ' ', 'B']
    write(out / 'clubs.json', {
        'base': [[n, team_groups.base(n)] for n in base_names],
        'build': {'counts': sub_counts, 'rosters': sub_rosters, 'overrides': club['overrides'], 'manual': club['manual'], 'separate': club['separate'],
                  'name_to_group': [[k, v] for k, v in mapping.items()], 'groups': [[k, v] for k, v in groups.items()]},
        'ratio': [[a, b, difflib.SequenceMatcher(None, a, b).ratio()] for a, b in ratio_cases(rng, list(counts), team_groups)],
    })

    # Athletes: a synthetic set exercising format joins, nickname/initial joins, same-race and
    # sex distinctness, separate and manual decisions, plus a handful of printed names.
    entries = synthetic_athletes()
    team_of = {'Strider TC': 'strider', 'Striders': 'strider', 'Empire': 'empire', 'Heat': 'heat'}
    manual = [['Ann Lee', 'Annie Lee'], ['Nobody Here', 'Ann Lee']]
    separate = ['Rob Kiernan']
    amap, evidence = athlete_ids.build([tuple(e[:3]) + (e[3],) for e in entries], build_site.slug, team_of, manual, separate)
    printed = ['Kiernan, Russ', 'RUSS KIERNAN', 'Russ J. Kiernan', 'José Núñez-García', "O'Brien, Pat Jr.", 'Smith - Jones, Mary (W40)',
               'Ｊｏｈｎ　Ｓｍｉｔｈ', 'Æsir Øster', 'li [CHN]', 'Madonna', '', '-', 'van der Berg, Jan III', 'Mc Donald,  Ronald', 'ŁUKASZ Żak']
    write(out / 'athletes.json', {
        'parts': [[n, list(athlete_ids.parts(n)) if athlete_ids.parts(n) else None] for n in printed + [e[0] for e in entries]],
        'build': {'entries': entries, 'team_of': team_of, 'manual': manual, 'separate': separate,
                  'map': [[k, v] for k, v in amap.items()], 'evidence': evidence},
        'slug': [[s, build_site.slug(s)] for s in printed + ['Café Racers', 'A' * 100, '--x--', 'İstanbul', 'ẞtraße']],
    })


def ratio_cases(rng, names, team_groups):
    cores = sorted({team_groups.base(n) for n in names} - {''})
    cases = [('greyhounds', 'greyounds'), ('lightening', 'lightning'), ('', ''), ('a', ''), ('abc', 'abc'), ('abxcd', 'abcd')]
    for _ in range(150):
        a, b = rng.sample(cores, 2)
        cases.append((a, b))
    for c in rng.sample(cores, 30):
        cases.append((c, c[: max(1, len(c) - 2)] + 'x'))
    # Autojunk applies when the second sequence has 200+ elements.
    long_b = ''.join(rng.choice('aabbbcdeefghhiijkllmnooppqrssttuvwxyz') for _ in range(260))
    cases += [(long_b[:120], long_b), (long_b, long_b[30:]), ('e' * 10 + long_b[:50], long_b), (long_b[::-1], long_b)]
    return cases


def synthetic_athletes():
    """(printed name, printed club, sex, race id) rows."""
    rows = [
        ['Kiernan, Russell', 'Strider TC', 'Men', 'r1'], ['Russ Kiernan', 'Striders', 'Men', 'r2'], ['R. Kiernan', 'Strider TC', None, 'r3'],
        ['RUSSELL KIERNAN', 'Strider TC', 'Men', 'r4'], ['Rob Kiernan', 'Strider TC', 'Men', 'r5'], ['Robert Kiernan', 'Strider TC', 'Men', 'r6'],
        ['Mike Lane', 'Empire', 'Men', 'r1'], ['Michael Lane', 'Empire', 'Men', 'r1'], ['Mick Lane', 'Empire', None, 'r7'],
        ['Chris Ford', 'Heat', 'Women', 'r2'], ['Christine Ford', 'Heat', 'Women', 'r3'], ['Christopher Ford', 'Heat', 'Men', 'r4'],
        ['Ann Lee', 'Heat', 'Women', 'r1'], ['Annie Lee', 'Empire', 'Women', 'r2'], ['Anne Lee', 'Heat', 'Women', 'r3'],
        ['Jo Park', 'Heat', None, 'r1'], ['Joseph Park', 'Heat', 'Men', 'r2'], ['Joanne Park', 'Heat', 'Women', 'r3'],
        ['Dan Ortiz', '', 'Men', 'r1'], ['Daniel Ortiz', '', 'Men', 'r2'], ['Dave Smith', 'Unknown Club', 'Men', 'r1'], ['David Smith', 'Unknown Club', 'Men', 'r2'],
        ['Relay Team A', 'Heat', None, 'r9'], ['Heat', 'Heat', None, 'r9'], ['Kiernan, Russell', 'Strider TC', 'Men', 'r8'],
        ['Sam Jones', 'Empire', 'Women', 'r1'], ['Samantha Jones', 'Empire', 'Women', 'r2'], ['Samuel Jones', 'Empire', 'Men', 'r3'],
        ['T. Nguyen', 'Heat', None, 'r1'], ['Tim Nguyen', 'Heat', None, 'r2'], ['Thomas Nguyen', 'Heat', None, 'r3'],
        ['Kat Brown', 'Heat', None, 'r1'], ['Katherine Brown', 'Heat', None, 'r2'], ['Kathleen Brown', 'Heat', None, 'r3'], ['Kate Brown', 'Heat', None, 'r4'],
        ['José Núñez', 'Empire', 'Men', 'r1'], ['Jose Nunez', 'Empire', 'Men', 'r2'], ['NUNEZ, JOSE', 'Empire', 'Men', 'r3'],
        ['12', 'Heat', None, 'r1'], ['007', 'Heat', None, 'r2'],
    ]
    return rows


def main():
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest='cmd', required=True)
    c = sub.add_parser('capture')
    c.add_argument('--meets-root', required=True)
    c.add_argument('--out', required=True)
    c.add_argument('--seeds', type=int, default=8, help='hash seeds tried to find order-dependent club names')
    g = sub.add_parser('regroup')
    g.add_argument('--meets-root', required=True)
    g.add_argument('--inputs', required=True)
    f = sub.add_parser('fixtures')
    f.add_argument('--meets-root', required=True)
    f.add_argument('--capture', required=True)
    f.add_argument('--out', required=True)
    a = p.parse_args()
    {'capture': capture, 'regroup': regroup, 'fixtures': fixtures}[a.cmd](a)


if __name__ == '__main__':
    main()
