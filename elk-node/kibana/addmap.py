import json, sys
# addmap.py <dashboard saved object json> <map id> <x> <y> <w> <h> -> the saved object's update body (the map added by reference)
d = json.load(open(sys.argv[1])); mid = sys.argv[2]; x, y, w, h = map(int, sys.argv[3:7])
a = d['attributes']; panels = json.loads(a['panelsJSON'])
panels = [p for p in panels if p.get('panelIndex') != 'sentora-map']
panels.append({'type': 'map', 'panelIndex': 'sentora-map', 'gridData': {'x': x, 'y': y, 'w': w, 'h': h, 'i': 'sentora-map'},
               'embeddableConfig': {'hidePanelTitles': True, 'isLayerTOCOpen': False, 'mapCenter': {'lat': 25, 'lon': 10, 'zoom': 1.4}}, 'panelRefName': 'panel_sentora-map'})
refs = [r for r in d.get('references', []) if not r['name'].startswith('sentora-map:')]
refs.append({'name': 'sentora-map:panel_sentora-map', 'type': 'map', 'id': mid})
a['panelsJSON'] = json.dumps(panels)
print(json.dumps({'attributes': a, 'references': refs}))
