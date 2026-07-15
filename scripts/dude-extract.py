#!/usr/bin/env python3
"""
Extract data from a MikroTik "The Dude" dude.db database.

Decodes the proprietary `objs` binary blobs and the composite integer keys,
then writes CSVs into ./export/:

  objects.csv     - every object: id, class, name
  devices.csv     - deviceID, name, dns, ip
  placements.csv  - device on each map with X/Y canvas coordinates
  outages.csv     - incident log, names joined in
  chart_values_*  - time-series (only with --charts; large)

Usage:  python3 dude-extract.py [dude.db] [--charts]

Reverse-engineered object format ("M2"):
  bytes 0-3   magic 4d 32 01 00
  bytes 4-7   ff 88 01 00
  bytes 8-11  classid (uint32 LE)
  then fields: <id:2 LE><0x10><type:1><value>, plus 0xfe-prefixed builtins
  types: 00/01=bool, 08=int32, 09=uint8, 21=pstring, 31=bytes,
         88=optional int32 (present,pad,[int32]), a0=long blob (flags2,len2,data)
  builtins: fe08=self-id, fe21=name
Field ids used:
  device:  0x1f40 ip(net-order int)  0x1f41 dns-name  0x1f46 username
           0x1f47 password  0x1f4c device-type ref  0x1f4d parent-device ref
           0x1f4e snmp-profile ref
  snmp(58):0x3c68 version(0=v1,1=v2c,-1=off)  0x3c69 community  0x3c6a port  0x3c72 timeout_ms
  element: 0x5dc0 mapID  0x5dc4 deviceID  0x5dc5 X  0x5dc6 Y  0x5ddf kind(1=node)
"""
import sqlite3, struct, re, csv, os, sys, socket, datetime, collections

DB = sys.argv[1] if len(sys.argv) > 1 and not sys.argv[1].startswith('-') else 'dude_clean.db'
WANT_CHARTS = '--charts' in sys.argv
OUT = 'export'
os.makedirs(OUT, exist_ok=True)

CLASS_NAMES = {3:'ServerConfig',4:'Tool',5:'Setting',9:'ServiceInfo',10:'NetworkMap',
    13:'ProbeType',14:'DeviceType',15:'Device',16:'Subnet/AS',17:'Service',24:'Notification',
    31:'Link',34:'LinkType',41:'Probe',58:'SnmpProfile',59:'AdminUser',74:'MapElement'}

def parse(b):
    """Decode a Dude object blob -> {classid, name, selfid, <fieldid>:value}."""
    f = {}
    if len(b) < 12 or b[:4] != b'\x4d\x32\x01\x00':
        return f
    f['classid'] = struct.unpack_from('<I', b, 8)[0]
    i = 12
    while i < len(b):
        if b[i] == 0xfe:                                   # builtin field
            t = b[i+1]
            if t == 0x08: f['selfid'] = struct.unpack_from('<i', b, i+2)[0]; i += 6
            elif t == 0x21:
                ln = b[i+2]; f['name'] = b[i+3:i+3+ln].decode('utf8','replace'); i += 3+ln
            else: break
            continue
        if i+3 >= len(b): break
        if (b[i+2] & 0xf0) != 0x10:                        # marker is 0x1x; else 0100/1000 separator
            i += 2; continue
        fid = b[i] | (b[i+1] << 8); t = b[i+3]; i += 4
        if t == 0x00: f[fid] = 0
        elif t == 0x01: f[fid] = 1
        elif t == 0x08: f[fid] = struct.unpack_from('<i', b, i)[0]; i += 4
        elif t == 0x09: f[fid] = b[i]; i += 1
        elif t == 0x10: f[fid] = struct.unpack_from('<q', b, i)[0]; i += 8   # int64 (time/0)
        elif t in (0x21, 0x31):
            ln = b[i]; f[fid] = b[i+1:i+1+ln]; i += 1+ln
        elif t == 0x88:                              # count-prefixed array of int32 (raw, net-order)
            cnt = struct.unpack_from('<H', b, i)[0]; i += 2
            f[fid] = [b[i+4*j:i+4*j+4] for j in range(cnt)]; i += 4*cnt
        elif t == 0xa0:                                # present(2) [+ len(2) + data]
            present = b[i:i+2]; i += 2
            if present == b'\x00\x00':
                f[fid] = b''
            else:
                ln = struct.unpack_from('<H', b, i)[0]; i += 2
                f[fid] = b[i:i+ln]; i += ln
        else: break
    return f

def s(v): return v.decode('utf8','replace') if isinstance(v, (bytes, bytearray)) else ''
def ip(v):                                  # 0x88 arrays arrive as [raw4,...]; use first
    if isinstance(v, list): v = v[0] if v else None
    return socket.inet_ntoa(v) if isinstance(v,(bytes,bytearray)) and len(v)==4 else ''
def ts(t):
    try: return datetime.datetime.fromtimestamp(t, datetime.timezone.utc).strftime('%Y-%m-%d %H:%M:%S')
    except Exception: return ''

db = sqlite3.connect(DB)

def has_table(tbl):
    """True if the SQLite DB has this table. The `outages` log and the
    `chart_values_*` history tables are OPTIONAL in The Dude - a server with
    charting/outage-history disabled (or a different Dude version) simply won't
    have them, so every query against them must be guarded or extraction crashes
    on an otherwise-perfectly-importable database."""
    return db.execute(
        "SELECT 1 FROM sqlite_master WHERE type='table' AND name=?", (tbl,)
    ).fetchone() is not None

objs = {oid: parse(blob) for oid, blob in db.execute('SELECT id, obj FROM objs')}
name = {oid: (o.get('name') or '') for oid, o in objs.items()}

# --- Link topology (for the My Mate importer) ------------------------------
# Dude monitors ONE interface per Link (class 31): 0x55f1 device, 0x55f2 if_index,
# 0x55f3 speed, 0x55f8/0x55f9 tx/rx probe. The link's two device endpoints live on
# the class-74 link map-element: 0x5ddb/0x5ddc = endpoint node elements (each node
# element carries its device in 0x5dc4), 0x5ddd = the Link object. So we can recover
# both device ends; the peer end's interface is unknown (importer makes a stub).
_node_elem_device = {oid: o.get(0x5dc4) for oid, o in objs.items()
                     if o.get('classid') == 74 and isinstance(o.get(0x5dc4), int)
                     and objs.get(o.get(0x5dc4), {}).get('classid') == 15}
link_endpoints = {}                              # linkObjID -> (deviceA, deviceB)
for _oid, _o in objs.items():
    if _o.get('classid') == 74 and _o.get(0x5ddf) == 4 and isinstance(_o.get(0x5ddd), int):
        link_endpoints[_o[0x5ddd]] = (_node_elem_device.get(_o.get(0x5ddb)),
                                      _node_elem_device.get(_o.get(0x5ddc)))
probe_iface = {}                                 # probeID -> (deviceID, if_index, 'tx'|'rx')
for _oid, _o in objs.items():
    if _o.get('classid') == 31:
        _dev, _ifx = _o.get(0x55f1), _o.get(0x55f2)
        if isinstance(_o.get(0x55f8), int): probe_iface[_o[0x55f8]] = (_dev, _ifx, 'tx')
        if isinstance(_o.get(0x55f9), int): probe_iface[_o[0x55f9]] = (_dev, _ifx, 'rx')

# objects.csv
with open(f'{OUT}/objects.csv','w',newline='') as fh:
    w = csv.writer(fh); w.writerow(['id','classid','class','name'])
    for oid,o in sorted(objs.items()):
        c = o.get('classid')
        w.writerow([oid, c, CLASS_NAMES.get(c, f'class{c}'), name[oid]])

# devices.csv
devs = {oid:o for oid,o in objs.items() if o.get('classid')==15}
# The 17 built-in DeviceTypes carry no name in the blob; their labels live in the
# Dude app's Types table (index # 0-16). Built-ins occupy the lowest class-14 object
# ids in that order, so we map by sorted position. (Confirmed: the 5 custom types at
# positions 17+ self-name and match the app's list.)
BUILTIN_TYPES = ['MikroTik Device','Bridge','Router','Switch','RouterOS',
    'Windows Computer','HP Jet Direct','FTP Server','Mail Server','Web Server',
    'DNS Server','POP3 Server','IMAP4 Server','News Server','Time Server','Printer','Some Device']
_type14 = sorted(o for o,x in objs.items() if x.get('classid')==14)
builtin_name = {tid: BUILTIN_TYPES[i] for i,tid in enumerate(_type14) if i < len(BUILTIN_TYPES)}
# Guard the position assumption: the built-ins must be exactly the first contiguous
# run of *unnamed* class-14 objects, with every named (custom) type after them.
# If a Dude version added/removed/reordered built-ins this breaks -> don't mislabel.
_unnamed = [i for i,t in enumerate(_type14) if not objs[t].get('name')]
if _unnamed != list(range(len(_unnamed))):
    print("WARNING: device-type built-in ordering assumption violated; "
          "using builtin#<id> instead of names.", file=sys.stderr)
    builtin_name = {}
def typelabel(tid):                       # 0x1f4c -> DeviceType (class 14)
    if not (isinstance(tid,int) and objs.get(tid,{}).get('classid')==14): return ''
    return name[tid] or builtin_name.get(tid) or f'builtin#{tid}'
def parentlabel(pid):                     # 0x1f4d -> parent Device (class 15)
    return name[pid] if isinstance(pid,int) and pid in devs else ''
with open(f'{OUT}/devices.csv','w',newline='') as fh:
    w = csv.writer(fh)
    w.writerow(['deviceID','name','dns','ip','device_type','parent_device'])
    for oid,o in sorted(devs.items()):
        w.writerow([oid, name[oid], s(o.get(0x1f41)), ip(o.get(0x1f40)),
                    typelabel(o.get(0x1f4c)), parentlabel(o.get(0x1f4d))])
print(f"devices.csv: {len(devs)} devices")

# placements.csv  (class-74 map elements that point at a device)
np = 0
with open(f'{OUT}/placements.csv','w',newline='') as fh:
    w = csv.writer(fh); w.writerow(['deviceID','device','ip','mapID','map','x','y'])
    for oid,o in objs.items():
        if o.get('classid')!=74: continue
        did = o.get(0x5dc4)
        if not isinstance(did,int) or did not in devs: continue
        mid = o.get(0x5dc0)
        w.writerow([did, name.get(did,''), ip(devs[did].get(0x1f40)),
                    mid, name.get(mid,''), o.get(0x5dc5), o.get(0x5dc6)])
        np += 1
print(f"placements.csv: {np} device placements")

# snmp_profiles.csv  (class 58)
SNMP_VER = {0:'v1', 1:'v2c', -1:'disabled'}
profs = {oid:o for oid,o in objs.items() if o.get('classid')==58}
with open(f'{OUT}/snmp_profiles.csv','w',newline='') as fh:
    w = csv.writer(fh); w.writerow(['profileID','name','version','community','port','timeout_ms'])
    for oid,o in sorted(profs.items()):
        w.writerow([oid, name[oid], SNMP_VER.get(o.get(0x3c68), o.get(0x3c68)),
                    s(o.get(0x3c69)), o.get(0x3c6a), o.get(0x3c72)])
print(f"snmp_profiles.csv: {len(profs)} profiles")

# credentials.csv  (device login + effective SNMP, resolving inheritance)
# SNMP cascade: device field 0x1f4e if it names a profile, else the global
# default in ServerConfig (obj 10000) field 0xfa9. No map/agent-level override exists.
default_pid = objs.get(10000, {}).get(0xfa9)
with open(f'{OUT}/credentials.csv','w',newline='') as fh:
    w = csv.writer(fh)
    w.writerow(['deviceID','device','ip','username','password',
                'snmp_source','snmp_profileID','snmp_profile','snmp_version','snmp_community'])
    for oid,o in sorted(devs.items()):
        pid = o.get(0x1f4e)
        if isinstance(pid, int) and pid in profs:
            src = 'device'
        else:
            pid, src = default_pid, 'inherited(global)'
        p = profs.get(pid, {})
        w.writerow([oid, name[oid], ip(o.get(0x1f40)), s(o.get(0x1f46)), s(o.get(0x1f47)),
                    src if p else '', pid if p else '', name.get(pid,'') if p else '',
                    SNMP_VER.get(p.get(0x3c68), p.get(0x3c68)) if p else '',
                    s(p.get(0x3c69)) if p else ''])
print(f"credentials.csv: {len(devs)} devices (global SNMP default: "
      f"{name.get(default_pid,'?')})")

# --- services / probes ---
# Service(17): 0x2ee1 device  0x2ee3 probetype  0x2eec probe-instance  0x2ee0 enabled
# Probe(41):   name "TYPE @ DEVICE [tx|rx]"  0xbf6a unit  0xbf71 poll interval(s)
# ProbeType(13): built-in, no name; identity = value-expr 0x36d0 / unit 0x36d1
def split_probe(nm):                      # -> (type/iface, target-device, direction)
    typ, _, rest = nm.partition(' @ ')
    direction = ''
    for d in (' tx', ' rx'):
        if rest.endswith(d): rest, direction = rest[:-3], d.strip()
    return (typ if rest else nm), (rest if rest else ''), direction

svc_probe = set()
svcs = sorted((oid,o) for oid,o in objs.items() if o.get('classid')==17)
with open(f'{OUT}/services.csv','w',newline='') as fh:
    w = csv.writer(fh)
    w.writerow(['deviceID','device','ip','service_type','enabled','probeID','probe_name','unit','poll_interval_s'])
    for oid,o in svcs:
        did = o.get(0x2ee1); pr = o.get(0x2eec); svc_probe.add(pr)
        pn = name.get(pr,''); stype,_,_ = split_probe(pn)
        w.writerow([did, name.get(did,''), ip(devs.get(did,{}).get(0x1f40)),
                    stype, o.get(0x2ee0), pr, pn,
                    s(objs.get(pr,{}).get(0xbf6a)), objs.get(pr,{}).get(0xbf71)])
print(f"services.csv: {len(svcs)} services")

probes = sorted((oid,o) for oid,o in objs.items() if o.get('classid')==41)
with open(f'{OUT}/probes.csv','w',newline='') as fh:
    w = csv.writer(fh)
    # deviceID/if_index/direction resolve the probe to a concrete monitored interface
    # (via the Link tx/rx refs) so the importer can attribute chart_values series.
    w.writerow(['probeID','name','metric','target','direction','unit','poll_interval_s','kind',
                'deviceID','if_index','iface_direction'])
    for oid,o in probes:
        metric,target,direction = split_probe(name.get(oid,''))
        pdev, pifx, pdir = probe_iface.get(oid, ('', '', ''))
        w.writerow([oid, name.get(oid,''), metric, target, direction,
                    s(o.get(0xbf6a)), o.get(0xbf71),
                    'service' if oid in svc_probe else 'interface',
                    pdev if isinstance(pdev,int) else '', pifx if isinstance(pifx,int) else '', pdir])
print(f"probes.csv: {len(probes)} probe instances ({len(svc_probe)} service, {len(probes)-len(svc_probe)} interface)")

# interfaces.csv  (the monitored interfaces: real if_index + speed + name, one row
# per (device, if_index)). Name is the probe's "<iface> @ <dev> <dir>" minus the
# suffix. This is the interface inventory the importer upserts on (device, if_index).
ifaces = {}
for oid,o in objs.items():
    if o.get('classid') != 31: continue
    dev, ifx, sp = o.get(0x55f1), o.get(0x55f2), o.get(0x55f3)
    if not (isinstance(dev,int) and dev in devs and isinstance(ifx,int)): continue
    pn = name.get(o.get(0x55f8)) or name.get(o.get(0x55f9)) or ''
    ifname = split_probe(pn)[0] if pn else f'if{ifx}'
    ifaces.setdefault((dev,ifx), (ifname, sp))
with open(f'{OUT}/interfaces.csv','w',newline='') as fh:
    w = csv.writer(fh)
    w.writerow(['deviceID','device','ip','if_index','name','speed_bps'])
    for (dev,ifx),(ifname,sp) in sorted(ifaces.items()):
        w.writerow([dev, name[dev], ip(devs[dev].get(0x1f40)), ifx, ifname, sp])
print(f"interfaces.csv: {len(ifaces)} monitored interfaces")

RES_LABEL = {'cpu_usage()':'CPU usage','mem_usage()':'Memory usage',
    'virtual_mem_usage()':'Virtual memory usage','hdd_usage()':'Disk usage','2 + 2':'Availability'}
ptypes = sorted((oid,o) for oid,o in objs.items() if o.get('classid')==13)
with open(f'{OUT}/probe_types.csv','w',newline='') as fh:
    w = csv.writer(fh)
    w.writerow(['probeTypeID','label','value_expr','unit','available_test','down_condition'])
    for oid,o in ptypes:
        expr = s(o.get(0x36d0))
        w.writerow([oid, name[oid] or RES_LABEL.get(expr,''), expr,
                    s(o.get(0x36d1)), s(o.get(0x36ce)), s(o.get(0x36cf))])
print(f"probe_types.csv: {len(ptypes)} probe types")

# --- inventory tables (functions, networks, link types, links, maps, elements, MIBs) ---
def nm(i):  return name.get(i,'') if isinstance(i,int) and i in objs else ''
def ref(i): return i if isinstance(i,int) and i in objs else ''
def dump(fname, classid, cols, rowfn):
    members = sorted((oid,o) for oid,o in objs.items() if o.get('classid')==classid)
    with open(f'{OUT}/{fname}','w',newline='') as fh:
        w = csv.writer(fh); w.writerow(cols)
        for oid,o in members: w.writerow(rowfn(oid,o))
    print(f"{fname}: {len(members)}")
    return len(members)

dump('functions.csv', 4, ['id','name'], lambda i,o:[i,name[i]])
dump('networks.csv', 16, ['id','name'], lambda i,o:[i,name[i]])
dump('link_types.csv', 34, ['id','name'], lambda i,o:[i,name[i]])

elem_count = collections.Counter(o.get(0x5dc0) for o in objs.values() if o.get('classid')==74)
dump('network_maps.csv', 10, ['mapID','name','element_count'],
     lambda i,o:[i,name[i],elem_count.get(i,0)])

dump('map_elements.csv', 74, ['elementID','mapID','map','kind','deviceID','device','x','y','label'],
     lambda i,o:[i, ref(o.get(0x5dc0)), nm(o.get(0x5dc0)),
                 'link' if o.get(0x5ddf)==4 else 'node',
                 ref(o.get(0x5dc4)) if nm(o.get(0x5dc4)) and objs[o[0x5dc4]].get('classid')==15 else '',
                 nm(o.get(0x5dc4)) if isinstance(o.get(0x5dc4),int) and objs.get(o.get(0x5dc4),{}).get('classid')==15 else '',
                 o.get(0x5dc5), o.get(0x5dc6), name[i]])

# links.csv: BOTH device endpoints + the monitored interface. The "a" end is the
# Dude-monitored interface (real if_index + speed); the "b" end is the peer device
# only (Dude tracks one interface per link) -> the importer makes a best-effort stub.
def _link_row(i,o):
    mon = o.get(0x55f1)
    epA, epB = link_endpoints.get(i, (None, None))
    peer = epB if epA == mon else (epA if epB == mon else (epB if isinstance(epB,int) else epA))
    return [i, nm(o.get(0x55f6)),
            ref(mon), nm(mon), ip(devs.get(mon,{}).get(0x1f40)), o.get(0x55f2), o.get(0x55f3),
            ref(peer), nm(peer),
            nm(o.get(0x55f8)), nm(o.get(0x55f9)), nm(o.get(0x55f4))]
dump('links.csv', 31,
     ['linkID','link_type','a_deviceID','a_device','a_ip','a_if_index','speed_bps',
      'b_deviceID','b_device','tx_probe','rx_probe','map'],
     _link_row)

# map_links.csv: EVERY drawn link line (class-74 kind=link, 0x5ddf==4), including ones Dude
# was NOT bandwidth-monitoring - those have no class-31 Link object, so they never reach
# links.csv even though the operator drew them. Both endpoints must resolve to devices
# (0x5ddb / 0x5ddc -> node element -> its device). The importer turns each into a plain
# device<->device link for any pair a monitored link didn't already cover.
with open(f'{OUT}/map_links.csv','w',newline='') as fh:
    w = csv.writer(fh); w.writerow(['elementID','mapID','a_deviceID','a_device','b_deviceID','b_device'])
    _mln = 0
    for _oid, _o in objs.items():
        if _o.get('classid') != 74 or _o.get(0x5ddf) != 4:
            continue
        _a = _node_elem_device.get(_o.get(0x5ddb))
        _b = _node_elem_device.get(_o.get(0x5ddc))
        if isinstance(_a, int) and isinstance(_b, int) and _a != _b:
            w.writerow([_oid, ref(_o.get(0x5dc0)), ref(_a), nm(_a), ref(_b), nm(_b)]); _mln += 1
    print(f"map_links.csv: {_mln}")

# MIB modules are stored as .txt entries in the class-5 Files tree (content not in DB).
mibs = sorted(oid for oid,o in objs.items() if o.get('classid')==5 and name[oid].lower().endswith('.txt'))
with open(f'{OUT}/mib_modules.csv','w',newline='') as fh:
    w = csv.writer(fh); w.writerow(['id','filename'])
    for oid in mibs: w.writerow([oid, name[oid]])
print(f"mib_modules.csv: {len(mibs)}")

# Categories requested but NOT present in this database (no such objects exist):
for cat in ('mib_nodes','discoveries','syslog_rules'):
    print(f"{cat}: 0 (not present in this DB)")

# outages.csv  (the outage log is optional - absent if history was never enabled)
no = 0
with open(f'{OUT}/outages.csv','w',newline='') as fh:
    w = csv.writer(fh)
    w.writerow(['time_utc','status','duration_s','deviceID','device','ip','serviceID','service','mapID','map'])
    if has_table('outages'):
        for sid,did,mid,t,st,dur in db.execute(
                'SELECT serviceID,deviceID,mapID,time,status,duration FROM outages ORDER BY time'):
            w.writerow([ts(t),st,dur,did,name.get(did,''),
                        ip(devs[did].get(0x1f40)) if did in devs else '',
                        sid,name.get(sid,''),mid,name.get(mid,'')])
            no += 1
print(f"outages.csv: {no} rows" + ('' if has_table('outages') else ' (no outages table in this DB)'))

if WANT_CHARTS:
    # Each resolution band is a separate table and each is optional: charting can be
    # off, and the set of bands differs by Dude version. Skip any that don't exist
    # rather than crash - the importer only reads the CSVs that got written.
    for tbl in ('chart_values_raw','chart_values_10min','chart_values_2hour','chart_values_1day'):
        if not has_table(tbl):
            print(f"{tbl}.csv: 0 (no {tbl} table in this DB)")
            continue
        n = 0
        with open(f'{OUT}/{tbl}.csv','w',newline='') as fh:
            w = csv.writer(fh); w.writerow(['sourceID','source','time_utc','value'])
            for key,val in db.execute(f'SELECT sourceIDandTime,value FROM {tbl}'):
                w.writerow([key>>32, name.get(key>>32,''), ts(key & 0xFFFFFFFF), val]); n += 1
        print(f"{tbl}.csv: {n} rows")
