#!/usr/bin/env python3
"""Build a minimal but format-valid The Dude dude.db for testing the extractor.

Encodes real M2 object blobs the way dude-extract.py expects to decode them,
so we can drive the full extract->import pipeline without a real Dude install.

Usage: make_dude_db.py OUT.db [--no-outages] [--no-charts] [--charts-partial]
"""
import sqlite3, struct, socket, sys

out = sys.argv[1]
NO_OUTAGES = '--no-outages' in sys.argv
NO_CHARTS = '--no-charts' in sys.argv
CHARTS_PARTIAL = '--charts-partial' in sys.argv   # only chart_values_raw exists

def obj(classid, selfid, name=None, fields=None):
    """Encode one M2 object blob."""
    b = bytearray()
    b += b'\x4d\x32\x01\x00'          # magic
    b += b'\xff\x88\x01\x00'          # constant
    b += struct.pack('<I', classid)   # classid
    # builtins
    b += b'\xfe\x08' + struct.pack('<i', selfid)
    if name is not None:
        nb = name.encode('utf8')
        b += b'\xfe\x21' + bytes([len(nb)]) + nb
    for fid, (typ, val) in (fields or {}).items():
        b += bytes([fid & 0xff, (fid >> 8) & 0xff, 0x10, typ])
        if typ == 0x00 or typ == 0x01:
            pass
        elif typ == 0x08:             # int32 LE
            b += struct.pack('<i', val)
        elif typ == 0x09:             # uint8
            b += bytes([val])
        elif typ == 0x10:             # int64
            b += struct.pack('<q', val)
        elif typ == 0x21 or typ == 0x31:   # pstring / bytes (1-byte len)
            vb = val.encode('utf8') if isinstance(val, str) else val
            b += bytes([len(vb)]) + vb
        elif typ == 0x88:             # count-prefixed array of raw 4-byte ints
            b += struct.pack('<H', len(val))
            for item in val:
                b += item
        else:
            raise ValueError(f'unhandled type {typ}')
    return bytes(b)

def ip4(s):
    return socket.inet_aton(s)

objs = {}

# ServerConfig (obj 10000) - global default snmp profile ref 0xfa9
objs[10000] = obj(3, 10000, 'ServerConfig', {0xfa9: (0x08, 58)})

# SNMP profile (class 58): version v2c(1), community, port, timeout
objs[58] = obj(58, 58, 'v2-public', {0x3c68: (0x08, 1), 0x3c69: (0x21, 'public'),
                                     0x3c6a: (0x08, 161), 0x3c72: (0x08, 5000)})

# Device types (class 14) - 17 builtins (unnamed) then done
for i, tid in enumerate(range(100, 117)):
    objs[tid] = obj(14, tid)   # unnamed builtins

# Devices (class 15)
# router: type=Router(builtin idx2 -> tid 102), snmp via global default
objs[1] = obj(15, 1, 'router1', {
    0x1f40: (0x88, [ip4('10.0.0.1')]),
    0x1f41: (0x21, 'router1.lan'),
    0x1f4c: (0x08, 102),   # Router builtin
})
# switch: parent = router1
objs[2] = obj(15, 2, 'switch1', {
    0x1f40: (0x88, [ip4('10.0.0.2')]),
    0x1f4c: (0x08, 103),   # Switch builtin
    0x1f4d: (0x08, 1),     # parent device
})

# Network map (class 10)
objs[500] = obj(10, 500, 'Main')

# Map elements (class 74) node placements
objs[600] = obj(74, 600, None, {0x5dc0: (0x08, 500), 0x5dc4: (0x08, 1),
                                0x5dc5: (0x08, 100), 0x5dc6: (0x08, 100), 0x5ddf: (0x09, 1)})
objs[601] = obj(74, 601, None, {0x5dc0: (0x08, 500), 0x5dc4: (0x08, 2),
                                0x5dc5: (0x08, 130), 0x5dc6: (0x08, 120), 0x5ddf: (0x09, 1)})

db = sqlite3.connect(out)
db.execute('DROP TABLE IF EXISTS objs')
db.execute('CREATE TABLE objs (id INTEGER PRIMARY KEY, obj BLOB)')
for oid, blob in objs.items():
    db.execute('INSERT INTO objs (id, obj) VALUES (?, ?)', (oid, blob))

if not NO_OUTAGES:
    db.execute('CREATE TABLE outages (serviceID INT, deviceID INT, mapID INT, time INT, status TEXT, duration INT)')
    db.execute("INSERT INTO outages VALUES (0, 1, 500, 1750000000, 'down', 120)")

if not NO_CHARTS:
    tables = ['chart_values_raw'] if CHARTS_PARTIAL else \
             ['chart_values_raw', 'chart_values_10min', 'chart_values_2hour', 'chart_values_1day']
    for t in tables:
        db.execute(f'CREATE TABLE {t} (sourceIDandTime INT, value REAL)')

db.commit()
db.close()
print(f'wrote {out}  ({len(objs)} objs, outages={not NO_OUTAGES}, charts={"partial" if CHARTS_PARTIAL else not NO_CHARTS})')
