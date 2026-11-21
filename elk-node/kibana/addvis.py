import json, sys
# addvis.py <dashboard saved object json> <type:id:x:y:w:h>... -> the saved object's update body (each one added by reference)
# (Kibana's dashboards API takes no Vega or map panel: the installer saves them first, then puts them on their board with this)
d = json.load(open(sys.argv[1]))
a = d['attributes']
panels = [p for p in json.loads(a['panelsJSON']) if not str(p.get('panelIndex', '')).startswith('sentora-vis-')]
refs = [r for r in d.get('references', []) if not r['name'].startswith('sentora-vis-')]
for n, spec in enumerate(sys.argv[2:]):
    kind, oid, x, y, w, h = spec.split(':')
    idx = 'sentora-vis-%d' % n
    panels.append({'type': kind, 'panelIndex': idx, 'gridData': {'x': int(x), 'y': int(y), 'w': int(w), 'h': int(h), 'i': idx},
                   'embeddableConfig': {}, 'panelRefName': 'panel_' + idx})
    refs.append({'name': idx + ':panel_' + idx, 'type': kind, 'id': oid})
a['panelsJSON'] = json.dumps(panels)
print(json.dumps({'attributes': a, 'references': refs}))
