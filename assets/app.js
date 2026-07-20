// Site Manager v0.28 — application script (extracted from the single-file build).
// BOOT carries every piece of server-rendered state this script needs; it is
// injected by inc/views/app.php as the #sm-boot JSON island right above the
// <script src> tag, so it is always present before this file executes.
const BOOT = JSON.parse(document.getElementById('sm-boot').textContent);

// One reusable dropdown component — THE way to build a select-style menu here.
// Every .ddown used to re-declare its own open/close, label lookup, and pick
// logic inline; this factory carries all of those building blocks once, and
// each dropdown just feeds it (1) where its value lives and (2) its options.
//   path : dot-path to the bound value on app state, e.g. 'editForm.room_type'
//   opts : an [{v, n}] array for fixed lists, OR the NAME of an app property
//          (e.g. 'deviceTypes') to read a live/dynamic list from state
//   cfg  : optional { valueKey, labelKey, placeholder } for lists whose items
//          use different field names (deviceTypes uses key/name, not v/n)
// Dropdowns with per-option side effects (the audit filter reloads the log on
// pick; the bulk-actions menu calls a method) stay hand-rolled on purpose —
// folding their custom behavior in here would bloat this into a config monster.
function ddown(path, opts, cfg){
    cfg = cfg || {};
    const vk = cfg.valueKey || 'v', lk = cfg.labelKey || 'n';
    return {
        open:false,
        get items(){ return Array.isArray(opts) ? opts : (this[opts] || []); },
        get val(){ return path.split('.').reduce((o,k)=>o?.[k], this); },
        get label(){
            const hit = this.items.find(o => o[vk] === this.val);
            if(hit) return hit[lk];
            if(cfg.placeholder !== undefined) return cfg.placeholder;
            return this.items[0] ? this.items[0][lk] : '';
        },
        isActive(o){ return o[vk] === this.val; },
        pick(o){
            const ks = path.split('.'); const last = ks.pop();
            const target = ks.reduce((x,k)=>x?.[k], this);
            if(target) target[last] = o[vk];
            this.open = false;
        },
    };
}

// Leaflet objects live OUTSIDE Alpine's reactive data on purpose. Wrapping a
// Leaflet map (which holds circular refs + live DOM nodes) in Alpine's reactive
// Proxy causes performance problems and subtle breakage, so the map, its layers,
// and per-site overlay handles are held in these plain module-level variables and
// only referenced from methods.
let _geoMap = null;              // the Leaflet map instance
let _geoTiles = null;            // the OSM tile layer
let _geoLayers = {};             // siteId -> { marker, overlay }
let _geoEditMarker = null;       // draggable marker shown while placing
let _geoEditOverlay = null;      // live overlay preview shown while placing
let _ocrWorker = null;           // cached Tesseract worker (loaded on first scan)
let _ocrLoading = null;          // in-flight engine load, so double-scans share it
let _pinDragMoved = false;       // did the last pin drag actually move?
let _pinDragStart = null;        // pointer position the drag began at
let _scanStream = null;          // MediaStream while the barcode scanner is open
let _scanDetector = null;        // native BarcodeDetector instance
let _scanRaf = null;             // decode-loop frame handle
let _gpsWatchId = null;          // navigator.geolocation watch handle
let _gpsMarker = null;           // the blue "you are here" dot
let _gpsCircle = null;           // accuracy circle around the dot
let _geoAspect = {};             // siteId -> floor-plan aspect (h/w), cached
const GEO_DEFAULT_METERS = 150;  // overlay width when a site has none stored yet
// Floor-plan overlays are large SVGs and the browser re-rasterizes every one of
// them on every pan/zoom. Below this zoom a whole campus is only a few pixels
// wide, so the overlays cost everything and show nothing — pins carry the map
// at that scale instead.
const GEO_OVERLAY_MIN_ZOOM = 15;
// Del Norte County / Crescent City area — a sensible default view before any
// site has been placed on the map.
const GEO_DEFAULT_CENTER = [41.7558, -124.2026];
const GEO_DEFAULT_ZOOM = 12;

function siteManagerApp(){
    return {
        // ---- App state ----
        sites:        BOOT.sites,
        rooms:        BOOT.rooms,
        devices:      BOOT.devices,
        deviceTypes:  BOOT.deviceTypes,
        siteCounts:   BOOT.siteCounts,
        camerasAdmin: BOOT.camerasAdmin,
        cameras:      BOOT.cameras,
        printers:     BOOT.printers,
        showPrinters: false,
        printersEnabled: BOOT.printersEnabled,
        inheritBuilding: BOOT.inheritBuilding,
        roomTypeColors: BOOT.roomTypeColors || {},
        // Display list for the Settings color grid (kept in step with the room
        // editor's type dropdown).
        roomTypeList: [
            {v:'general',n:'General'},{v:'classroom',n:'Classroom'},{v:'office',n:'Office'},
            {v:'lab',n:'Lab'},{v:'library',n:'Library'},{v:'breakroom',n:'Break Room'},
            {v:'storage',n:'Storage'},{v:'restroom',n:'Restroom'},{v:'utility',n:'Utility'},
            {v:'hallway',n:'Hallway'},{v:'conference',n:'Conference Room'},
            {v:'cafeteria',n:'Cafeteria'},{v:'gym',n:'Gym'},{v:'auditorium',n:'Auditorium'},
        ],
        selectedPrinter: null,
        printerForm: { open:false, printer_id:0, site_number:0, printer_name:'', location:'', web_interface:'', model:'', serial_number:'', mac_address:'', toner_id:'', barcode:'', notes:'', map_level:'level-1' },
        printerImport: { open:false, rows:[], busy:false, error:'', selected:[], filter:'all', bulkSite:0 },
        peopleImport: { open:false, rows:[], busy:false, error:'', selected:[], filter:'all', bulkSite:0, siteFilter:0, sortBySite:false },
        placePanel: { open:false, filter:'' },
        // ---- Data Editor (db_admin only) ----
        dataEditor: { open:false, tables:[], current:'', rows:[], total:0, page:1, per:50, q:'', busy:false, sort:'', dir:'asc', site:'' },
        deForm: { open:false, isNew:false, pkValue:null, values:{} },
        get deCurrentDef(){ return this.dataEditor.tables.find(t => t.table === this.dataEditor.current) || null; },
        get deCols(){ return this.deCurrentDef ? this.deCurrentDef.cols : []; },
        get dePk(){ return this.deCurrentDef ? this.deCurrentDef.pk : 'id'; },
        deCellText(v, type, colName){
            if(v === null || v === undefined || v === '') return '—';
            if(type === 'bool') return (Number(v) ? 'yes' : 'no');
            // Show the friendly site name instead of the raw number.
            if(colName === 'site_number'){
                const s = this.siteById(Number(v));
                if(s) return s.name + ' (#' + v + ')';
            }
            const s = String(v);
            return s.length > 40 ? s.slice(0, 40) + '…' : s;
        },
        async openDataEditor(){
            if(!this.can('data_admin','view')) return;
            this.view = 'data_editor';
            this.writeHash();
            this.dataEditor.open = true;
            if(!this.dataEditor.tables.length){
                try {
                    const res = await fetch('?api=dataedit&action=tables');
                    const data = await res.json();
                    if(data.success){ this.dataEditor.tables = data.tables; if(data.tables.length) this.deSelectTable(data.tables[0].table); }
                    else this.showToast(data.error || 'Could not load tables', 'err');
                } catch(e){ this.showToast('Network error', 'err'); }
            }
        },
        deSelectTable(t){ this.dataEditor.current = t; this.dataEditor.q = ''; this.dataEditor.site = ''; this.dataEditor.sort = ''; this.dataEditor.dir = 'asc'; this.deLoadRows(1); },
        // Click a column header to sort. First click = ascending (low→high);
        // click the same column again to flip to descending.
        deSort(col){
            if(this.dataEditor.sort === col){
                this.dataEditor.dir = this.dataEditor.dir === 'asc' ? 'desc' : 'asc';
            } else {
                this.dataEditor.sort = col;
                this.dataEditor.dir = 'asc';
            }
            this.deLoadRows(1);
        },
        async deLoadRows(page){
            if(!this.dataEditor.current) return;
            this.dataEditor.busy = true;
            try {
                const sortCol = this.dataEditor.sort || this.dePk;
                const url = '?api=dataedit&action=rows&table=' + encodeURIComponent(this.dataEditor.current)
                    + '&page=' + page + '&q=' + encodeURIComponent(this.dataEditor.q||'')
                    + '&sort=' + encodeURIComponent(sortCol) + '&dir=' + encodeURIComponent(this.dataEditor.dir||'asc')
                    + '&site=' + encodeURIComponent(this.dataEditor.site||'');
                const res = await fetch(url);
                const data = await res.json();
                if(data.success){ this.dataEditor.rows = data.rows; this.dataEditor.total = data.total; this.dataEditor.page = data.page; this.dataEditor.per = data.per; }
                else this.showToast(data.error || 'Could not load rows', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.dataEditor.busy = false;
        },
        deNewRow(){
            if(!this.can('data_admin','manage')) return;
            const vals = {};
            this.deCols.forEach(c => { vals[c.name] = (c.type === 'bool') ? false : ''; });
            this.deForm = { open:true, isNew:true, pkValue:null, values:vals };
        },
        deEditRow(row){
            if(!this.can('data_admin','manage')) return;
            const vals = {};
            this.deCols.forEach(c => {
                vals[c.name] = (c.type === 'bool') ? !!Number(row[c.name]) : (row[c.name] === null ? '' : row[c.name]);
            });
            this.deForm = { open:true, isNew:false, pkValue: row[this.dePk], values:vals };
        },
        async deSaveRow(){
            if(!this.can('data_admin','manage')) return;
            const body = { table: this.dataEditor.current, values: this.deForm.values };
            let url = '?api=dataedit&action=insert';
            if(!this.deForm.isNew){ url = '?api=dataedit&action=update'; body.pk_value = this.deForm.pkValue; }
            try {
                const res = await fetch(url, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body) });
                const data = await res.json();
                if(data.success){ this.deForm.open = false; await this.deLoadRows(this.dataEditor.page); this.showToast(this.deForm.isNew ? 'Row created' : 'Row saved', 'ok'); }
                else this.showToast(data.error || 'Save failed', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async deDeleteRow(row){
            if(!this.can('data_admin','manage')) return;
            const id = row[this.dePk];
            if(!confirm('Permanently delete ' + this.dataEditor.current + ' #' + id + '? This cannot be undone.')) return;
            try {
                const res = await fetch('?api=dataedit&action=delete', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ table: this.dataEditor.current, pk_value: id }) });
                const data = await res.json();
                if(data.success){ await this.deLoadRows(this.dataEditor.page); this.showToast('Row deleted', 'ok'); }
                else this.showToast(data.error || 'Delete failed', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // Per-layer collapsed state (persists which sections you fold)
        _placeCollapsed: {},
        togglePlacePanel(){ this.placePanel.open = !this.placePanel.open; },
        // The unified placement model. Each layer contributes its UNPLACED items
        // for the CURRENT SITE. Future layers slot in here as new entries — the
        // panel UI renders whatever this returns, so nothing else needs to change.
        get placeLayers(){
            const q = (this.placePanel.filter||'').toLowerCase().trim();
            const sid = this.currentSiteId;
            const printerIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>';
            const cameraIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 7l-7 5 7 5V7z"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
            const roomIcon = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>';
            const match = (s) => !q || (s||'').toLowerCase().includes(q);

            // Rooms: unplaced = no polygon shape on this site (e.g. created by the
            // people importer). Dropping a pin gives them a starting shape.
            const roomItems = !this.can('base','edit') ? [] : this.rooms
                .filter(r => r.site_number === sid)
                .filter(r => !Array.isArray(r.polygon_points) || r.polygon_points.length < 3)
                .filter(r => match(r.room_name) || match(r.room_number) || match(this.roomNumberLabel(r)))
                .sort((a,b) => (this.roomNumberLabel(a)||a.room_name||'').localeCompare(this.roomNumberLabel(b)||b.room_name||'', undefined, {numeric:true}))
                .map(r => ({ id:r.room_id, name:(this.roomNumberLabel(r) || r.room_name || ('Room '+r.room_id)), meta:(r.room_name || ''), ref:r }));

            // Printers: unplaced = no map_x/map_y on the current map level
            const printerItems = !this.printersEnabled ? [] : this.printers
                .filter(p => p.site_number === sid && (p.map_x === null || p.map_y === null))
                .filter(p => match(p.printer_name) || match(p.location) || match(p.model))
                .sort((a,b) => (a.printer_name||'').localeCompare(b.printer_name||'', undefined, {numeric:true}))
                .map(p => ({ id:p.printer_id, name:p.printer_name, meta:(p.location || p.model || ''), ref:p }));

            // Cameras: unplaced = no map_x/map_y
            const cameraItems = !this.cameraWallEnabled ? [] : this.cameras
                .filter(c => c.site_number === sid && (c.map_x === null || c.map_y === null))
                .filter(c => match(c.camera_name) || match(c.camera_ip))
                .sort((a,b) => (a.camera_name||'').localeCompare(b.camera_name||'', undefined, {numeric:true}))
                .map(c => ({ id:c.camera_number, name:c.camera_name, meta:(c.camera_ip || ''), ref:c }));

            const layers = [
                { key:'room',    label:'Rooms',    color:'#3b82f6', icon:roomIcon,    enabled:true, future:false, items:roomItems },
                { key:'printer', label:'Printers', color:'#0d9488', icon:printerIcon, enabled:this.printersEnabled, future:false, items:printerItems },
                { key:'camera',  label:'Cameras',  color:'#8b5cf6', icon:cameraIcon,  enabled:this.cameraWallEnabled, future:false, items:cameraItems },
            ];
            // Auto-expand layers that have items; collapse empty ones — unless the
            // user has manually toggled that section (then respect their choice).
            layers.forEach(l => {
                l.collapsed = (this._placeCollapsed[l.key] !== undefined)
                    ? this._placeCollapsed[l.key]
                    : (l.items.length === 0);
            });
            return layers;
        },
        get unplacedTotalForSite(){
            const sid = this.currentSiteId; if(!sid) return 0;
            let n = 0;
            if(this.can('base','edit')) n += this.rooms.filter(r => r.site_number===sid && (!Array.isArray(r.polygon_points) || r.polygon_points.length < 3)).length;
            if(this.printersEnabled) n += this.printers.filter(p => p.site_number===sid && (p.map_x===null||p.map_y===null)).length;
            if(this.cameraWallEnabled) n += this.cameras.filter(c => c.site_number===sid && (c.map_x===null||c.map_y===null)).length;
            return n;
        },
        // Unified drag-to-place: works for any layer via its key.
        startPlaceDrag(layerKey, item, ev){
            if(!this.canEdit) return;
            if(ev.button !== undefined && ev.button !== 0) return;
            ev.preventDefault();
            const canvas = this.$refs.canvas; if(!canvas) return;
            const startX = ev.clientX, startY = ev.clientY;
            let dragging = false; const threshold = 5;
            const pct = (e) => {
                const rect = canvas.getBoundingClientRect();
                return { inside: e.clientX>=rect.left && e.clientX<=rect.right && e.clientY>=rect.top && e.clientY<=rect.bottom,
                    x: Math.max(0, Math.min(100, ((e.clientX-rect.left)/rect.width)*100)),
                    y: Math.max(0, Math.min(100, ((e.clientY-rect.top)/rect.height)*100)) };
            };
            const onMove = (e) => {
                if(!dragging){
                    if(Math.abs(e.clientX-startX)<threshold && Math.abs(e.clientY-startY)<threshold) return;
                    dragging = true;
                    this.listDrag = { active:true, dev:null, printer:null, placeLayer:layerKey, placeId:item.id, x:e.clientX, y:e.clientY, over:false };
                }
                const p = pct(e); this.listDrag.x = e.clientX; this.listDrag.y = e.clientY; this.listDrag.over = p.inside;
            };
            const onUp = (e) => {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);
                if(dragging){
                    const p = pct(e);
                    if(p.inside){
                        const x = Math.round(p.x*100)/100, y = Math.round(p.y*100)/100;
                        if(layerKey === 'room'){
                            this.placeRoomAt(item.ref, x, y);
                        } else if(layerKey === 'printer'){
                            const pr = item.ref;
                            pr.map_x = x; pr.map_y = y; pr.map_level = this.selectedLevel || 'level-1';
                            this.savePrinterPosition(pr);
                            this.showPrinters = true;
                        } else if(layerKey === 'camera'){
                            const cam = item.ref;
                            cam.map_x = x; cam.map_y = y; cam.map_level = this.selectedLevel || 'level-1';
                            this.saveCameraPlacement(cam);
                            this.showCameras = true;
                        }
                    }
                    this.listDrag = { active:false, dev:null, printer:null, placeLayer:null, placeId:null, x:0, y:0, over:false };
                }
            };
            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup', onUp);
        },
        async saveCameraPlacement(cam){
            try {
                const res = await fetch('?api=camera&action=set_position', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ camera_number: cam.camera_number, map_x: cam.map_x, map_y: cam.map_y, map_level: cam.map_level })
                });
                const data = await res.json();
                if(data.success) this.showToast('Camera placed', 'ok');
                else this.showToast(data.error || 'Could not place camera', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // Place an unplaced room (e.g. created by the people importer) by dropping
        // a pin: give it a small default rectangle centered at the drop point so it
        // appears on the map, then the user can refine the shape with Edit shape.
        async placeRoomAt(room, cx, cy){
            if(!this.can('base','edit')) return;
            if(!room) return;
            const hw = 8, hh = 7;   // half-width / half-height in % of the map
            const clamp = (v) => Math.max(0, Math.min(100, v));
            const poly = [
                { x: clamp(cx-hw), y: clamp(cy-hh) },
                { x: clamp(cx+hw), y: clamp(cy-hh) },
                { x: clamp(cx+hw), y: clamp(cy+hh) },
                { x: clamp(cx-hw), y: clamp(cy+hh) },
            ];
            const lvl = this.selectedLevel || 'level-1';
            try {
                const res = await fetch('?api=room&action=save', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({
                        room_id: room.room_id,
                        site_number: room.site_number,
                        room_name: room.room_name,
                        room_number: room.room_number,
                        building: room.building || '',
                        map_level: lvl,
                        polygon_points: poly,
                        label_x: clamp(cx), label_y: clamp(cy),
                    })
                });
                const data = await res.json();
                if(data.success){
                    // Update the in-memory room so it renders immediately.
                    const saved = data.room || {};
                    const i = this.rooms.findIndex(r => r.room_id === room.room_id);
                    if(i >= 0){
                        this.rooms[i].polygon_points = poly;
                        this.rooms[i].label_x = clamp(cx);
                        this.rooms[i].label_y = clamp(cy);
                        this.rooms[i].map_level = lvl;
                    }
                    this.showToast('Room placed — use Edit shape to refine it', 'ok');
                } else this.showToast(data.error || 'Could not place room', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        showCameras:  false,
        selectedCamera: null,
        wall: { site:'all', cols:3, visible:[], maxStreams:32, q:'', streaming:new Set() },
        feedModal: { open:false, cam:null },
        camHover: { show:false, cam:null, x:0, y:0 },
        siteTab:'map',             // 'map' | 'cameras' — the in-site module switcher
        canEdit:      BOOT.canEditAny,
        isAdmin:      BOOT.isAdmin,
        dbAdmin:      BOOT.canDataAdmin,
        isGlassbreak: BOOT.isGlassbreak,
        // Per-layer / per-capability permission snapshot for UI gating. The
        // server still enforces everything; this only decides what to SHOW.
        perms: BOOT.perms,
        userRole:     BOOT.userRole,
        myGroups:     BOOT.myGroups,
        currentUser:  BOOT.user,
        mustChangePassword: BOOT.mustChangePassword,
        sessionTimeoutSec: BOOT.sessionTimeoutSecs,
        sessionWarnSec: BOOT.sessionWarnSecs,
        sessionNeverExpire: BOOT.neverExpire,
        cameraWallEnabled: BOOT.cameraWallEnabled,
        siteLogoPath: BOOT.brandLogo,
        siteBrandName: BOOT.brandName,
        brandLogoBusy: false,

        // Navigation
        view:'home',               // 'home' | 'dashboard' | 'cameras' | 'site' | 'room'
        currentSiteId:null,
        currentRoomId:null,
        theme:(typeof localStorage!=='undefined' && localStorage.getItem('sm_theme')) || 'dark',

        // Sidebar "Sites" dropdown (accordion). sitesOpen tracks whether the
        // inline list of every site is expanded. The list is only actually shown
        // when the sidebar itself is expanded — i.e. while hovered on a hover-
        // capable device (sidebarHover), or always on touch where the sidebar is
        // permanently full-width (!sidebarHoverCapable).
        sitesOpen:true,
        sidebarHover:false,
        sidebarHoverCapable:true,
        mobileNavOpen:false,           // slide-over nav on phones (sidebar is hidden there)

        // Map (OpenStreetMap) view. Only plain/serializable UI state lives here;
        // the Leaflet map + layers are module-level (see top of this script).
        geo:{
            libFailed:false,                 // Leaflet didn't load
            base:'satellite',                // 'satellite' (aerial) or 'street' (OSM)
            placing:false,                   // placement panel open
            editSiteId:0,                    // site currently being placed
            hasPoint:false,                  // a location has been chosen for it
            lat:null, lng:null,
            meters:GEO_DEFAULT_METERS,
            showOverlayWhilePlacing:true,
            saving:false,
            searchQuery:'',
            searching:false,
            // Live GPS ("where am I") layer. NOTE: browsers only allow the
            // geolocation API in secure contexts (HTTPS or localhost) — on the
            // current http:// LAN deployment the button explains that instead
            // of failing silently. Fully functional the moment HTTPS exists.
            gps:{ watching:false, follow:true, hasFix:false },
        },

        // Map state
        mapZoom:1.0,
        ZOOM_MAX:20,            // global hard cap (2000%)
        ZOOM_MIN:0.1,           // global floor (10%)
        mapBaseW:1000,
        mapBaseH:640,
        mapSvgMarkup:'',
        mapSvgLoadedForSite:null,
        mapSvgLoading:false,
        showPins:true,
        selectedLevel:'level-1',
        isPanning:false,
        // Search state
        mapSearch:{ q:'', results:[], open:false, choice:null },
        globalSearch:{ q:'', groups:[], open:false, total:0 },
        // Camera barcode scanning. Uses the browser's native BarcodeDetector
        // (Chrome/Edge/Android — no libraries). Camera access, like GPS, is
        // only permitted by browsers on HTTPS, so on the http:// LAN this
        // explains itself rather than failing silently.
        scanner:{ open:false, target:'map', hint:'' },
        blinkRoomId:null,

        // Room edit state
        roomEditMode:false,
        // Room-view interior shape ("trace the room") state
        shapeEdit:{ active:false, points:[], backdrop:false, bgZoom:3, bgX:50, bgY:50, dragIdx:null, grid:true, snap:true, gridSize:20, bgOpacity:0.35, locked:false },
        drawingRoom:false,
        smartRooms:true,           // read the room number off the map label when a pin drops
        pickBox:null,              // live “highlight over the label” rectangle while placing
        draftPoints:[],
        placingRoom:false,
        editingRoomId:null,
        editForm:{},
        siteBuildings:[],          // managed building codes for the current site
        roomSelect:{ on:false, ids:[], box:null },   // map/list multi-select for mass building assign (box = live lasso rect)
        buildingMgr:{ open:false, newCode:'', newLabel:'', busy:false, genCols:8, genRows:6 },
        mapMgr:{ open:false, maps:[], newName:'', busy:false },
        assignPick:'',             // building chosen in the assign bar
        roomListFilter:'',         // filter for the list multi-select panel
        _vtxPointer:null,
        gridSnap:false,
        showGrid:false,
        gridStep:1,
        // Angle-measurement state: when measuringAngle is true, the next two clicks
        // on the map become endpoints of a wall sample, and the building's rotation
        // is computed from their slope.
        measuringAngle:false,
        anglePoints:[],
        snapGuides:[],

        // Map upload / room import
        showSvgUpload:false,
        svgFile:null,
        svgUploading:false,
        svgUploadMsg:'',
        svgUploadErr:false,
        showRoomImport:false,
        importJsonText:'',
        importReplace:false,
        importApplyAngle:false,
        importHasAngle:false,
        importAngleText:'',
        importing:false,
        importMsg:'',
        importErr:false,

        // Room modal
        roomModal:null,

        // Device edit state
        deviceEditMode:false,
        unplaceConfirmId:null,
        selectedDeviceId:null,
        placingDeviceId:null,
        listDrag:{ active:false, dev:null, printer:null, placeLayer:null, placeId:null, x:0, y:0, over:false },
        panelTab:'devices',
        peopleEditor:{ room_extension:'', room_notes:'', occupants:[] },
        // ---- Auth / user management ----
        currentUserId: BOOT.publicId,
        myAvatarPath: BOOT.profileImage,
        avatarBusy: false,
        usersModal:{ open:false, tab:'users', users:[], search:'', roleFilter:'all', statusFilter:'all', sortBy:'name', selected:[], siteSearch:'' },
        userForm:{ open:false, mode:'password', public_id:'', username:'', display_name:'', email:'', role:'viewer', password:'', is_active:true, never_expire:false, sites:[], cameraAccess:{}, group_ids:[], overrides:[] },
        permGroups:[], permCatalog:{ data_layers:[], admin_caps:[], levels:[] },
        groupEdit:{ open:false, group_id:0, name:'', description:'', is_system:0, grants:[], members:[] },
        inviteModal:{ open:false, username:'', email:'', display_name:'', group_ids:[], sending:false },
        pwModal:{ open:false, forced:false, p1:'', p2:'' },
        mfaModal:{ open:false, step:'off', secret:'', uri:'', code:'', codes:[], backupRemaining:0, confirmMode:'' },
        profileModal:{ open:false, tab:'profile', display_name:'', p1:'', p2:'' },
        settingsModal:{ open:false, showGen:false, vals:{ session_timeout_minutes:480, session_warn_minutes:10, audit_retention_days:90, login_max_attempts:5, login_lockout_minutes:15, login_lockout_manual:'0', smtp_enabled:'0', smtp_host:'', smtp_port:587, smtp_user:'', smtp_pass:'', smtp_security:'tls', smtp_from_email:'', smtp_from_name:'Site Manager', email_cap_hourly:50, email_cap_daily:200, layer_cameras_enabled:'1', layer_printers_enabled:'1' } },
        lockoutChoice:'10',
        testEmailTo:'',
        emailTesting:false,
        auditModal:{ open:false, events:[], q:'', actionFilter:'', kinds:[], page:1, pages:1, total:0, loading:false },
        sessionWarn:{ show:false, secondsLeft:0, countdownLabel:'', _poll:null, _tick:null },
        deviceEditor:{open:false, device_id:0, room_id:0, device_name:'', device_type_key:'other', status:'active', asset_tag:'', model:'', serial_number:'', ip_address:'', notes:''},
        // "+ Add Device" now opens a chooser: one by hand, or a CSV batch.
        settingsQuery:'',          // Settings page search box
        deviceAdd:{ open:false },
        deviceImport:{ open:false, rows:[], busy:false, error:'', file:'', done:0 },
        _devPointer:null,

        // UI
        toast:{show:false, msg:'', kind:'ok'},
        _toastTimer:null,

        // ====================================================
        // INIT
        // ====================================================
        // THE way to call the app's JSON API. Owns the whole dance that every
        // call site used to repeat inline: build the request (GET when no body,
        // POST-JSON otherwise), parse the response, toast the API error or the
        // network error, and hand back the data. Returns the parsed response
        // object on success, or null after showing the right toast on any
        // failure — so call sites collapse to:
        //     const data = await this.api(url, body);  if(!data) return;
        // Call sites that need custom failure handling (keep a modal open, set
        // a field-level error, silence errors) should keep using fetch directly.
        async api(url, body, failMsg){
            try {
                const init = body === undefined ? undefined : {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify(body)
                };
                const res = await fetch(url, init);
                // Read as text first: if the body isn't clean JSON (a PHP notice
                // printed before it, an HTML error page), res.json() throws and
                // the old catch reported a meaningless "Network error" — hiding
                // the actual server output. Surface it instead.
                const raw = await res.text();
                let data;
                try { data = JSON.parse(raw); }
                catch(parseErr){
                    console.error('[api] non-JSON response from', url, res.status, raw);
                    this.showToast('Server returned invalid data (' + res.status + '): ' +
                        raw.replace(/<[^>]*>/g, ' ').trim().slice(0, 160), 'err');
                    return null;
                }
                if(!data.success){
                    this.showToast(data.error || failMsg || 'Request failed', 'err');
                    return null;
                }
                return data;
            } catch(e){
                console.error('[api]', url, e);
                this.showToast('Network error: ' + (e && e.message ? e.message : e), 'err');
                return null;
            }
        },

        init(){
            document.documentElement.setAttribute('data-theme', this.theme);
            try { this.sidebarHoverCapable = !window.matchMedia || window.matchMedia('(hover: hover) and (pointer: fine)').matches; } catch(e){ this.sidebarHoverCapable = true; }
            // Phone-sized screens get the map-first "mini" experience. The
            // html.phone class drives view-only mode: everything marked
            // .edit-only in the markup disappears on phones (no map editing
            // on mobile yet — viewing, search, and admin basics only).
            try {
                this._mobileQ = window.matchMedia('(max-width: 768px)');
                const applyPhone = () => document.documentElement.classList.toggle('phone', this._mobileQ.matches);
                applyPhone();
                this._mobileQ.addEventListener ? this._mobileQ.addEventListener('change', applyPhone) : this._mobileQ.addListener(applyPhone);
            } catch(e){ this._mobileQ = null; }
            // GPS shouldn't keep burning battery once you leave the Map view.
            this.$watch('view', v => { if(v !== 'geomap') this._gpsStop(); });
            // Catches the zoom paths that don't go through _applyZoomAt
            // (reset, start view, quick-find). One DOM write, not one per pin.
            this.$watch('mapZoom', () => this._syncPinScale());
            try{ const m = parseInt(localStorage.getItem('sm_wall_max')); if(m) this.wall.maxStreams = Math.max(4, Math.min(128, m)); }catch(e){}
            try{ const c = parseInt(localStorage.getItem('sm_wall_cols')); if(c) this.wall.cols = Math.max(1, Math.min(8, c)); }catch(e){}
            this.$watch('wall.site', () => { this.wall.visible = []; this._recomputeWallStreaming(); });
            this.$watch('wall.q', () => { this.wall.visible = []; this._recomputeWallStreaming(); });
            this.readHash();
            window.addEventListener('hashchange', () => this.readHash());
            window.addEventListener('resize', () => this.refreshMapLayout());
            window.addEventListener('resize', () => { if(this.view==='geomap' && _geoMap){ try { _geoMap.invalidateSize(); } catch(e){} } });
            if(this.view==='site') this.loadSvgForCurrentSite();
            // First login with the default password → force a change.
            if(this.mustChangePassword){
                this.pwModal = { open:true, forced:true, p1:'', p2:'' };
            }
            // Begin idle-timeout tracking (no-op for kiosk/service accounts).
            this.initSessionTimer();
            // Load the district-wide building pool once at startup so the list is
            // ready before any site / room editor / grouping tool opens. (The pool
            // is global, not per-site, so this only needs to happen once.)
            this.loadBuildings();
        },

        // ====================================================
        // NAVIGATION (hash-based routing)
        // ====================================================
        readHash(){
            const h = location.hash.replace('#','');
            if(!h){
                // The phone experience is map-first: no hash on a small screen
                // opens the Map (sites + search + layers) rather than Home.
                if(this._mobileQ && this._mobileQ.matches){
                    this.view='geomap'; this.currentSiteId=null; this.currentRoomId=null;
                    this.$nextTick(() => this._ensureGeoMap());
                    return;
                }
                this.view='home'; this.currentSiteId=null; this.currentRoomId=null; return;
            }
            if(h==='sites'){ this.view='dashboard'; this.currentSiteId=null; this.currentRoomId=null; return; }
            if(h==='cameras'){
                if(this.hasAnyFeedAccess){ this.view='cameras'; this.currentSiteId=null; this.currentRoomId=null; return; }
                this.view='home'; return;
            }
            if(h==='map'){
                this.view='geomap'; this.currentSiteId=null; this.currentRoomId=null;
                this.$nextTick(() => this._ensureGeoMap());
                return;
            }
            // Admin pages: call the same open* functions used elsewhere so their
            // data-loading logic runs consistently whether you clicked the nav
            // item or landed here via a refresh/bookmark. Each is permission-
            // gated the same way its nav button already is — no access falls
            // back to Home rather than showing a page you can't use.
            if(h==='settings'){
                if(this.can('settings','view')) this.openSettings(); else this.view='home';
                return;
            }
            if(h==='audit'){
                if(this.can('audit','view')) this.openAudit(); else this.view='home';
                return;
            }
            if(h==='users'){
                if(this.can('manage_users','view')) this.openUsers(); else this.view='home';
                return;
            }
            if(h==='data-editor'){
                if(this.can('data_admin','view')) this.openDataEditor(); else this.view='home';
                return;
            }
            const parts = h.split('/');
            if(parts[0]==='site' && parts[1]){
                const sid = parseInt(parts[1],10);
                const st = this.siteById(sid);
                if(st){
                    this.currentSiteId = sid;
                    // Pick this site's default map (or its first) so loading a site
                    // URL directly shows the right map — goSite does this, but the
                    // hash-restore path previously left selectedLevel stale, which
                    // could show the wrong map (or none) on a refresh/bookmark.
                    if(st.maps && st.maps.length){
                        const dflt = st.maps.find(m => m.is_default);
                        this.selectedLevel = (dflt || st.maps[0]).key;
                    } else {
                        this.selectedLevel = 'level-1';
                    }
                    if(parts[2]==='room' && parts[3]){
                        const rid = parseInt(parts[3],10);
                        const room = this.rooms.find(r=>r.room_id===rid);
                        if(room){
                            // make sure we're on the room's own map level
                            this.selectedLevel = room.map_level || this.selectedLevel;
                            this.currentRoomId = rid; this.view = 'room';
                            this.loadSvgForCurrentSite();
                            this._syncLevelSelectDom();
                            return;
                        }
                    }
                    this.view='site';
                    this.loadBuildings();
                    this.loadSvgForCurrentSite();
                    this._syncLevelSelectDom();
                    return;
                }
            }
            this.view='dashboard';
            this.currentSiteId=null;
            this.currentRoomId=null;
        },
        writeHash(){
            let h = '';
            if(this.view==='dashboard') h = 'sites';
            if(this.view==='cameras') h = 'cameras';
            if(this.view==='geomap') h = 'map';
            if(this.view==='settings') h = 'settings';
            if(this.view==='audit') h = 'audit';
            if(this.view==='users') h = 'users';
            if(this.view==='data_editor') h = 'data-editor';
            if(this.view==='site'  && this.currentSiteId) h = 'site/'+this.currentSiteId;
            if(this.view==='room'  && this.currentSiteId && this.currentRoomId) h = 'site/'+this.currentSiteId+'/room/'+this.currentRoomId;
            const target = h ? '#'+h : '#';
            if(location.hash !== target) location.hash = target;
        },
        // Navigation invariant: the view switch happens FIRST, cleanup after —
        // and a cleanup failure is logged, never thrown. Previously cleanup ran
        // before the view was set, so one stale observer or half-initialized
        // layer throwing silently ate the whole click ("sometimes it doesn't
        // switch, works when I do it again"). Every go* routes through this.
        _navCleanup(){
            const steps = [
                () => this._clearWallObservers(),
                () => this.closeScanner(),
                () => this.cancelDrawRoom(),
                () => this.cancelRoomEdit(),
            ];
            for(const s of steps){
                try { s(); } catch(e){ console.warn('[nav] cleanup step failed (navigation unaffected):', e); }
            }
        },
        // Opt-in navigation tracing: run localStorage.setItem('sm_debug_nav','1')
        // in the console to log every navigation with its resulting view + hash.
        _navLog(name){
            try { if(localStorage.getItem('sm_debug_nav')) console.log('[nav]', name, '→ view:', this.view, 'hash:', location.hash); } catch(e){}
        },
        goHome(){ this.view='home'; this.currentSiteId=null; this.currentRoomId=null; this.writeHash(); this._navCleanup(); this._navLog('goHome'); },
        goDashboard(){ this.view='dashboard'; this.currentSiteId=null; this.currentRoomId=null; this.writeHash(); this._navCleanup(); this._navLog('goDashboard'); },

        // ====================================================
        //  MAP (OpenStreetMap + floor-plan overlays)
        // ====================================================
        goGeoMap(){
            this.view='geomap';
            this.currentSiteId=null; this.currentRoomId=null;
            this.writeHash();
            this._navCleanup();
            this._navLog('goGeoMap');
            // The container is display:none until this view shows; build/refresh
            // the map on the next tick so Leaflet measures a visible element.
            this.$nextTick(() => { try { this._ensureGeoMap(); } catch(e){ console.warn('[nav] geomap init failed:', e); this.geo.libFailed = true; } });
        },
        // Build the map once, then just refresh + re-measure on later visits.
        _ensureGeoMap(){
            if(typeof L === 'undefined'){ this.geo.libFailed = true; return; }
            this.geo.libFailed = false;
            const el = document.getElementById('geoMap');
            if(!el) return;
            if(!_geoMap){
                _geoMap = L.map(el, { zoomControl:false, attributionControl:true, maxZoom:24 })
                            .setView(GEO_DEFAULT_CENTER, GEO_DEFAULT_ZOOM);
                L.control.zoom({ position:'topright' }).addTo(_geoMap);
                // While placing, a click on the map drops/moves the site point.
                _geoMap.on('click', (e) => this._geoMapClick(e));
                // Manually panning means "stop following me" — standard map-app behavior.
                _geoMap.on('dragstart', () => { this.geo.gps.follow = false; });
                // Add/remove floor-plan overlays as the view changes.
                _geoMap.on('moveend zoomend', () => this._geoSyncOverlays());
                this.geoSetBase(this.geo.base);   // adds the initial base tile layer
            }
            // Leaflet mismeasures if it was initialized while hidden; force a
            // recompute now that the container is on-screen.
            setTimeout(() => { try { _geoMap.invalidateSize(); } catch(e){} }, 60);
            this._renderGeoSites();
            this._geoFitToSites();
        },
        // Swap the base map between aerial imagery and the street map. Both allow
        // over-zoom (maxZoom 22 above maxNativeZoom) so you can get in close; the
        // difference is that satellite has REAL high-resolution imagery — actual
        // rooftops to line a floor plan up against — where over-zoomed street tiles
        // just magnify blurry pixels. The floor-plan overlays sit in Leaflet's
        // overlay pane, always above whichever base layer is active.
        geoSetBase(kind){
            this.geo.base = kind;
            if(!_geoMap) return;
            if(_geoTiles){ try { _geoMap.removeLayer(_geoTiles); } catch(e){} _geoTiles = null; }
            let url, opts;
            if(kind === 'street'){
                url = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';
                opts = { maxZoom:24, maxNativeZoom:19, attribution:'&copy; OpenStreetMap contributors' };
            } else {
                // Esri World Imagery — free aerial/satellite basemap (note z/y/x order).
                url = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
                opts = { maxZoom:24, maxNativeZoom:19, attribution:'Tiles &copy; Esri, Maxar, Earthstar Geographics' };
            }
            _geoTiles = L.tileLayer(url, opts).addTo(_geoMap);
        },
        // Pan/zoom to show all placed sites (only on a fresh map, so we don't
        // yank the view around every time the user comes back).
        _geoFitToSites(){
            if(!_geoMap || this._geoDidFit) return;
            const placed = this.sites.filter(s => s.lat !== null && s.lng !== null);
            if(placed.length === 1){ _geoMap.setView([placed[0].lat, placed[0].lng], 17); this._geoDidFit = true; }
            else if(placed.length > 1){
                try { _geoMap.fitBounds(placed.map(s => [s.lat, s.lng]), { padding:[40,40], maxZoom:17 }); this._geoDidFit = true; } catch(e){}
            }
        },
        // Draw a marker (+ floor-plan overlay) for every placed site.
        _renderGeoSites(){
            if(!_geoMap) return;
            Object.values(_geoLayers).forEach(l => {
                try { if(l.marker) _geoMap.removeLayer(l.marker); } catch(e){}
                try { if(l.overlay) _geoMap.removeLayer(l.overlay); } catch(e){}
            });
            _geoLayers = {};
            this.sites.forEach(s => {
                if(s.lat === null || s.lng === null) return;
                if(this.geo.placing && this.geo.editSiteId === s.id) return; // shown as the live edit preview instead
                const marker = L.marker([s.lat, s.lng], { icon: this._geoIcon() }).addTo(_geoMap);
                const html = document.createElement('div');
                html.innerHTML = '<div class="geo-popup-name"></div><button class="geo-popup-btn">Open site</button>';
                html.querySelector('.geo-popup-name').textContent = s.name;
                html.querySelector('.geo-popup-btn').addEventListener('click', () => { _geoMap.closePopup(); this.goSite(s.id, 'map'); });
                marker.bindPopup(html);
                _geoLayers[s.id] = { marker, overlay:null, pending:false };
            });
            // Overlays are added/removed to match the current view (see below),
            // rather than all ~17 sites being layered on at once forever.
            this._geoSyncOverlays();
        },
        // Bounds an overlay would occupy (aspect defaults to 1 until the plan
        // has been measured once — close enough for a visibility test).
        _geoOverlayBounds(s){
            return this._geoBounds(s.id, s.lat, s.lng, (s.geo_meters || GEO_DEFAULT_METERS), _geoAspect[s.id] || 1);
        },
        // Show a site's floor plan only when it's actually on screen AND zoomed
        // in far enough to read. This is what keeps the Map tab smooth: at
        // county zoom nothing is rendered; zoomed into a campus, typically one
        // or two overlays exist instead of every site's.
        _geoSyncOverlays(){
            if(!_geoMap) return;
            const z = _geoMap.getZoom();
            const view = _geoMap.getBounds();
            this.sites.forEach(s => {
                const rec = _geoLayers[s.id];
                if(!rec || s.lat === null || s.lng === null) return;
                const url = this._siteMapUrl(s);
                const want = !!url && z >= GEO_OVERLAY_MIN_ZOOM &&
                             view.intersects(L.latLngBounds(this._geoOverlayBounds(s)));
                if(want && !rec.overlay && !rec.pending){
                    rec.pending = true;
                    this._overlayFor(s.id, s.lat, s.lng, (s.geo_meters || GEO_DEFAULT_METERS), url, (ov) => {
                        const cur = _geoLayers[s.id];
                        // The view may have moved on while the SVG was loading.
                        if(!cur){ try { _geoMap.removeLayer(ov); } catch(e){} return; }
                        cur.pending = false;
                        cur.overlay = ov;
                    });
                } else if(!want && rec.overlay){
                    try { _geoMap.removeLayer(rec.overlay); } catch(e){}
                    rec.overlay = null;
                }
            });
        },
        _geoIcon(){
            return L.divIcon({ className:'', html:'<div class="geo-site-marker"></div>', iconSize:[14,14], iconAnchor:[7,14], popupAnchor:[0,-14] });
        },
        // Build the serve URL for a site's default floor-plan SVG (or null).
        _siteMapUrl(s){
            // site_map is the single source of truth: no rows means no floor
            // plan (has_map is derived from the same list server-side).
            if(!s.maps || !s.maps.length) return null;
            let m = s.maps.find(x => x.is_default && x.has_svg) || s.maps.find(x => x.has_svg);
            if(!m) return null;
            return '?api=map&action=svg&site=' + s.id + '&map=' + encodeURIComponent(m.key);
        },
        // Compute lat/lng bounds for an overlay centered at (lat,lng) that is
        // `meters` wide, honoring the floor plan's aspect ratio (cached per site).
        _geoBounds(siteId, lat, lng, meters, aspect){
            const widthM = meters;
            const heightM = widthM * (aspect || 1);
            const latHalf = (heightM / 2) / 111320;
            const lngHalf = (widthM / 2) / (111320 * Math.cos(lat * Math.PI / 180));
            return [[lat - latHalf, lng - lngHalf], [lat + latHalf, lng + lngHalf]];
        },
        // Create an image overlay for a site, loading the SVG first (once) to learn
        // its aspect ratio so it isn't stretched. cb receives the overlay.
        _overlayFor(siteId, lat, lng, meters, url, cb){
            const make = (aspect) => {
                const bounds = this._geoBounds(siteId, lat, lng, meters, aspect);
                const ov = L.imageOverlay(url, bounds, { opacity:0.85, interactive:false });
                ov.addTo(_geoMap);
                cb(ov);
            };
            if(_geoAspect[siteId]){ make(_geoAspect[siteId]); return; }
            const img = new Image();
            img.onload = () => {
                const a = (img.naturalWidth > 0) ? (img.naturalHeight / img.naturalWidth) : 1;
                _geoAspect[siteId] = a || 1;
                make(_geoAspect[siteId]);
            };
            img.onerror = () => { _geoAspect[siteId] = 1; make(1); };
            img.src = url;
        },

        // ---- Placement flow ----
        geoStartPlacing(){
            this.geo.placing = true;
            this.geo.editSiteId = 0;
            this.geo.hasPoint = false;
            this.geo.lat = null; this.geo.lng = null;
            this.geo.meters = GEO_DEFAULT_METERS;
        },
        geoCancelPlacing(){
            this.geo.placing = false;
            this._geoClearEditLayers();
            this._renderGeoSites();   // restore the normal marker for the edited site
        },
        _geoClearEditLayers(){
            try { if(_geoEditMarker) _geoMap.removeLayer(_geoEditMarker); } catch(e){}
            try { if(_geoEditOverlay) _geoMap.removeLayer(_geoEditOverlay); } catch(e){}
            _geoEditMarker = null; _geoEditOverlay = null;
        },
        // Switched which site we're placing: seed from its stored spot if any.
        geoSelectEditSite(){
            const s = this.geoEditSite;
            this._geoClearEditLayers();
            if(!s){ this.geo.hasPoint = false; return; }
            if(s.lat !== null && s.lng !== null){
                this.geo.hasPoint = true;
                this.geo.lat = s.lat; this.geo.lng = s.lng;
                this.geo.meters = s.geo_meters || GEO_DEFAULT_METERS;
                _geoMap.setView([s.lat, s.lng], 19);
            } else {
                this.geo.hasPoint = false;
                this.geo.lat = null; this.geo.lng = null;
                this.geo.meters = GEO_DEFAULT_METERS;
            }
            this._renderGeoSites();      // hide the edited site's static marker
            this._geoDrawEdit();
        },
        _geoMapClick(e){
            if(!this.geo.placing || !this.geo.editSiteId) return;
            this.geo.lat = e.latlng.lat;
            this.geo.lng = e.latlng.lng;
            this.geo.hasPoint = true;
            this._geoDrawEdit();
        },
        // Draw/refresh the draggable marker + overlay preview for the site
        // currently being placed.
        _geoDrawEdit(){
            if(!this.geo.hasPoint){ this._geoClearEditLayers(); return; }
            const s = this.geoEditSite;
            const lat = this.geo.lat, lng = this.geo.lng;
            if(!_geoEditMarker){
                _geoEditMarker = L.marker([lat, lng], { icon:this._geoIcon(), draggable:true, zIndexOffset:1000 }).addTo(_geoMap);
                _geoEditMarker.on('drag', (ev) => {
                    const p = ev.target.getLatLng();
                    this.geo.lat = p.lat; this.geo.lng = p.lng;
                    this._geoDrawOverlayPreview();
                });
            } else {
                _geoEditMarker.setLatLng([lat, lng]);
            }
            this._geoDrawOverlayPreview();
        },
        _geoDrawOverlayPreview(){
            try { if(_geoEditOverlay) _geoMap.removeLayer(_geoEditOverlay); } catch(e){}
            _geoEditOverlay = null;
            if(!this.geo.showOverlayWhilePlacing) return;
            const s = this.geoEditSite;
            if(!s) return;
            const url = this._siteMapUrl(s);
            if(!url) return;
            this._overlayFor(s.id, this.geo.lat, this.geo.lng, this.geo.meters, url, (ov) => { _geoEditOverlay = ov; });
        },
        geoUpdatePreview(){ this._geoDrawOverlayPreview(); },
        async geoSavePlacement(){
            if(!this.geo.editSiteId || !this.geo.hasPoint) return;
            this.geo.saving = true;
            const data = await this.api('?api=map&action=set_geo',
                { site_number:this.geo.editSiteId, lat:this.geo.lat, lng:this.geo.lng, meters:this.geo.meters }, 'Save failed');
            if(data){
                const s = this.geoEditSite;
                if(s){ s.lat = data.lat; s.lng = data.lng; s.geo_meters = data.meters; }
                this.showToast('Location saved', 'ok');
                this._geoClearEditLayers();
                this.geo.editSiteId = 0; this.geo.hasPoint = false;
                this._renderGeoSites();
            }
            this.geo.saving = false;
        },
        async geoClearPlacement(){
            if(!this.geo.editSiteId) return;
            this.geo.saving = true;
            const data = await this.api('?api=map&action=set_geo',
                { site_number:this.geo.editSiteId, lat:null, lng:null, meters:null }, 'Could not remove');
            if(data){
                const s = this.geoEditSite;
                if(s){ s.lat = null; s.lng = null; s.geo_meters = null; }
                this.showToast('Location removed', 'ok');
                this._geoClearEditLayers();
                this.geo.hasPoint = false; this.geo.editSiteId = 0;
                this._renderGeoSites();
            }
            this.geo.saving = false;
        },

        // ---- Map search ----
        // Sites whose name or abbreviation matches the query (instant, local).
        get geoSiteMatches(){
            const q = (this.geo.searchQuery || '').trim().toLowerCase();
            if(!q) return [];
            return this.sites.filter(s =>
                (s.name && s.name.toLowerCase().includes(q)) ||
                (s.abbr && s.abbr.toLowerCase().includes(q))
            ).slice(0, 8);
        },
        // Enter key: if exactly one site matches, jump to it; otherwise treat the
        // text as an address to look up.
        geoSearchEnter(){
            const m = this.geoSiteMatches;
            if(m.length === 1){ this.geoFlyToSite(m[0]); return; }
            this.geoSearchAddress();
        },
        geoFlyToSite(s){
            if(!_geoMap) return;
            if(s.lat !== null && s.lng !== null){
                _geoMap.flyTo([s.lat, s.lng], 19);
                this.geo.searchQuery = '';
            } else if(this.can('base','edit')){
                // Not placed yet — drop the user straight into placing it.
                this.geo.searchQuery = '';
                if(!this.geo.placing) this.geoStartPlacing();
                this.geo.editSiteId = s.id;
                this.geoSelectEditSite();
                this.showToast(s.name + ' isn\u2019t placed yet — click the map to set its location', 'ok');
            } else {
                this.showToast(s.name + ' hasn\u2019t been placed on the map yet', 'err');
            }
        },
        // Geocode the query with OpenStreetMap's Nominatim and fly there. Handy
        // for finding a building's spot before placing a site. Light use only.
        async geoSearchAddress(){
            const q = (this.geo.searchQuery || '').trim();
            if(!q || !_geoMap) return;
            this.geo.searching = true;
            try {
                const url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(q);
                const res = await fetch(url, { headers:{ 'Accept':'application/json' } });
                const data = await res.json();
                if(Array.isArray(data) && data.length){
                    const hit = data[0];
                    _geoMap.flyTo([parseFloat(hit.lat), parseFloat(hit.lon)], 18);
                    this.geo.searchQuery = '';
                } else {
                    this.showToast('No place found for that search', 'err');
                }
            } catch(e){
                this.showToast('Address lookup failed', 'err');
            }
            this.geo.searching = false;
        },

        // Pins must stay a constant on-screen size, so they counter-scale against
        // the canvas. That counter-scale used to live in each pin's :style
        // binding — meaning ONE zoom step re-ran a binding for every room,
        // camera, and printer pin (85+ at a big site), each rebuilding a style
        // string and calling labelPosition() twice, then writing inline styles.
        // That reactive storm, not the zoom math, is what made zooming crawl.
        // Now the pins read a single CSS variable and the browser does the rest.
        _syncPinScale(){
            const canvas = this.$refs.canvas;
            if(canvas) canvas.style.setProperty('--pin-scale', String(1 / (this.mapZoom || 1)));
        },
        // A pointer drag finishes with the browser firing a synthetic click on the
        // element you were dragging — which popped that device's menu open the
        // instant you let go of it. These three let the pin click handlers tell a
        // real click from the tail of a drag. Shared by device and printer pins.
        _beginPinDrag(ev){ _pinDragMoved = false; _pinDragStart = { x: ev.clientX, y: ev.clientY }; },
        _notePinDragMove(e){
            if(!_pinDragStart) return;
            if(Math.abs(e.clientX - _pinDragStart.x) > 3 || Math.abs(e.clientY - _pinDragStart.y) > 3) _pinDragMoved = true;
        },
        _consumePinDrag(){          // true = "that click was really a drag ending"
            if(!_pinDragMoved) return false;
            _pinDragMoved = false;
            return true;
        },
        // ---- Barcode scanning into search ----
        async openScanner(target){
            this.scanner.target = target || 'map';
            if(!window.isSecureContext){
                this.showToast('Barcode scanning needs HTTPS \u2014 browsers block camera access on http:// sites', 'err'); return;
            }
            if(!('BarcodeDetector' in window)){
                this.showToast('This browser can\'t scan barcodes \u2014 Chrome or Edge can', 'err'); return;
            }
            let formats = [];
            try { formats = await window.BarcodeDetector.getSupportedFormats(); } catch(e){}
            this.scanner.hint = 'Point the camera at the barcode';
            this.scanner.open = true;
            try {
                _scanStream = await navigator.mediaDevices.getUserMedia({ video:{ facingMode:'environment' } });
            } catch(e){
                this.closeScanner();
                this.showToast((e && e.name === 'NotAllowedError')
                    ? 'Camera permission denied \u2014 allow it to scan barcodes'
                    : 'Could not open the camera', 'err');
                return;
            }
            this.$nextTick(() => {
                const v = this.$refs.scanVideo;
                if(!v){ this.closeScanner(); return; }
                v.srcObject = _scanStream;
                v.play().catch(() => {});
                try { _scanDetector = new window.BarcodeDetector(formats.length ? { formats } : undefined); }
                catch(e){ this.closeScanner(); this.showToast('Could not start the barcode reader', 'err'); return; }
                this._scanTick();
            });
        },
        async _scanTick(){
            if(!this.scanner.open) return;
            const v = this.$refs.scanVideo;
            if(v && v.readyState >= 2 && _scanDetector){
                try {
                    const codes = await _scanDetector.detect(v);
                    if(codes && codes.length && codes[0].rawValue){ this._scanHit(codes[0].rawValue); return; }
                } catch(e){ /* transient per-frame decode failures are normal */ }
            }
            _scanRaf = requestAnimationFrame(() => this._scanTick());
        },
        _scanHit(value){
            const code = String(value).trim();
            const target = this.scanner.target;
            try { if(navigator.vibrate) navigator.vibrate(60); } catch(e){}
            this.closeScanner();
            if(target === 'global'){
                this.globalSearch.q = code;
                this.runGlobalSearch();
            } else {
                this.mapSearch.q = code;
                this.runMapSearch();
            }
            this.showToast('Scanned ' + code, 'ok');
        },
        closeScanner(){
            if(_scanRaf){ cancelAnimationFrame(_scanRaf); _scanRaf = null; }
            if(_scanStream){ try { _scanStream.getTracks().forEach(t => t.stop()); } catch(e){} _scanStream = null; }
            _scanDetector = null;
            this.scanner.open = false;
        },

        // ---- Live GPS ("where am I on the map") ----
        gpsToggle(){
            if(this.geo.gps.watching){ this._gpsStop(); return; }
            if(!('geolocation' in navigator)){
                this.showToast('This device has no location support', 'err'); return;
            }
            // Browsers hard-require a secure context for geolocation. On the
            // http:// LAN this fails by policy, so say WHY instead of letting
            // the browser reject silently. Works immediately once on HTTPS.
            if(!window.isSecureContext){
                this.showToast('Location needs HTTPS \u2014 browsers block GPS on http:// sites', 'err'); return;
            }
            this.geo.gps.watching = true;
            this.geo.gps.follow = true;
            this.geo.gps.hasFix = false;
            _gpsWatchId = navigator.geolocation.watchPosition(
                (pos) => this._gpsFix(pos),
                (err) => this._gpsError(err),
                { enableHighAccuracy:true, maximumAge:2000, timeout:15000 }
            );
        },
        _gpsFix(pos){
            if(!_geoMap || !this.geo.gps.watching) return;
            const lat = pos.coords.latitude, lng = pos.coords.longitude;
            const acc = pos.coords.accuracy || 0;
            if(!_gpsMarker){
                _gpsMarker = L.marker([lat, lng], {
                    icon: L.divIcon({ className:'', html:'<div class="gps-dot"></div>', iconSize:[16,16], iconAnchor:[8,8] }),
                    interactive:false, zIndexOffset:2000
                }).addTo(_geoMap);
                _gpsCircle = L.circle([lat, lng], { radius:acc, weight:1, color:'#3b82f6', fillColor:'#3b82f6', fillOpacity:0.12, interactive:false }).addTo(_geoMap);
            } else {
                _gpsMarker.setLatLng([lat, lng]);
                _gpsCircle.setLatLng([lat, lng]).setRadius(acc);
            }
            // First fix zooms in; later fixes just keep you centered while
            // follow is on (dragging the map turns follow off).
            if(!this.geo.gps.hasFix){
                this.geo.gps.hasFix = true;
                _geoMap.setView([lat, lng], Math.max(_geoMap.getZoom(), 17));
            } else if(this.geo.gps.follow){
                _geoMap.panTo([lat, lng], { animate:true });
            }
        },
        _gpsError(err){
            this._gpsStop();
            if(err && err.code === 1) this.showToast('Location permission denied \u2014 allow it in the browser to see yourself on the map', 'err');
            else if(err && err.code === 3) this.showToast('Timed out getting a location fix', 'err');
            else this.showToast('Could not get a location fix', 'err');
        },
        _gpsStop(){
            if(_gpsWatchId !== null){ try { navigator.geolocation.clearWatch(_gpsWatchId); } catch(e){} _gpsWatchId = null; }
            try { if(_gpsMarker && _geoMap) _geoMap.removeLayer(_gpsMarker); } catch(e){}
            try { if(_gpsCircle && _geoMap) _geoMap.removeLayer(_gpsCircle); } catch(e){}
            _gpsMarker = null; _gpsCircle = null;
            this.geo.gps.watching = false;
            this.geo.gps.hasFix = false;
        },

        // Everything the Home dashboard shows, computed from data already in the
        // payload — no extra API calls. Mapping progress = rooms with a position
        // vs total, per site (sorted least-finished first: that's the to-do
        // list). Type distribution uses the Settings palette colors.
        get homeDash(){
            const rooms = this.rooms || [];
            const placed = r => (r.label_x !== null && r.label_x !== undefined)
                             || (r.polygon_points && r.polygon_points.length);
            const bySite = {};
            (this.sites || []).forEach(s => {
                bySite[s.id] = { id:s.id, name:s.name, color:s.color || '', total:0, placed:0 };
            });
            rooms.forEach(r => {
                const s = bySite[r.site_number];
                if(!s) return;
                s.total++;
                if(placed(r)) s.placed++;
            });
            const sitesP = Object.values(bySite).filter(s => s.total > 0)
                .map(s => Object.assign(s, { pct: Math.round((s.placed / s.total) * 100) }))
                .sort((a, b) => (a.pct - b.pct) || a.name.localeCompare(b.name));
            const typeCount = {};
            rooms.forEach(r => { const t = r.room_type || 'general'; typeCount[t] = (typeCount[t] || 0) + 1; });
            const maxT = Math.max(1, ...Object.values(typeCount).concat([0]));
            const typeRows = (this.roomTypeList || [])
                .filter(t => typeCount[t.v])
                .map(t => ({ v:t.v, n:t.n, count:typeCount[t.v],
                             pct: Math.round((typeCount[t.v] / maxT) * 100),
                             color: (this.roomTypeColors && this.roomTypeColors[t.v]) || 'var(--accent)' }))
                .sort((a, b) => b.count - a.count);
            const totPlaced = sitesP.reduce((n, s) => n + s.placed, 0);
            const totRooms  = sitesP.reduce((n, s) => n + s.total, 0);
            // Camera health: online vs offline, overall + per site (problem
            // sites — most offline — listed first). The donut arc length is
            // precomputed here so the SVG binding stays a plain string.
            const cams = this.cameras || [];
            const camTotal = cams.length;
            const camOnline = cams.filter(c => this.cameraIsOnline(c)).length;
            const CIRC = 213.63;   // 2*pi*r for the r=34 donut
            const camPerSite = {};
            cams.forEach(cm => {
                const s = camPerSite[cm.site_number] || (camPerSite[cm.site_number] = { online:0, total:0 });
                s.total++;
                if(this.cameraIsOnline(cm)) s.online++;
            });
            const camSites = (this.sites || [])
                .filter(s => camPerSite[s.id])
                .map(s => ({ id:s.id, name:s.name, abbr:s.abbr || '',
                             online:camPerSite[s.id].online, total:camPerSite[s.id].total,
                             offline:camPerSite[s.id].total - camPerSite[s.id].online }))
                .sort((a, b) => (b.offline - a.offline) || a.name.localeCompare(b.name));
            // Site inventory columns: rooms + devices per site on one shared
            // scale (heights are % of the largest value on the chart).
            const sc = this.siteCounts || {};
            const invRaw = (this.sites || []).map(s => ({
                id:s.id, name:s.name, abbr:s.abbr || '',
                rooms:(sc[s.id] && sc[s.id].rooms) || 0,
                devices:(sc[s.id] && sc[s.id].devices) || 0,
            })).filter(s => s.rooms || s.devices);
            const invMax = Math.max(1, ...invRaw.map(s => Math.max(s.rooms, s.devices)));
            const inventory = invRaw
                .map(s => Object.assign(s, {
                    roomH: s.rooms ? Math.max(4, Math.round((s.rooms / invMax) * 100)) : 0,
                    devH:  s.devices ? Math.max(4, Math.round((s.devices / invMax) * 100)) : 0,
                }))
                .sort((a, b) => (b.rooms - a.rooms) || a.name.localeCompare(b.name));
            return { sites: sitesP, typeRows,
                     placed: totPlaced,
                     placedPct: totRooms ? Math.round((totPlaced / totRooms) * 100) : 0,
                     cams: { total:camTotal, online:camOnline, offline:camTotal - camOnline,
                             pct: camTotal ? Math.round((camOnline / camTotal) * 100) : 0,
                             dash: camTotal ? ((CIRC * camOnline / camTotal).toFixed(1) + ' ' + CIRC) : ('0 ' + CIRC),
                             perSite: camSites },
                     inventory };
        },
        goSite(id, forceTab){
            this.view='site';
            this.currentSiteId=id;
            this.currentRoomId=null;
            this._navCleanup();
            this.selectedDeviceId=null;
            this.deviceEditMode=false;
            // Open on whichever tab (Map/Cameras) was used last; fall back to Map
            // if this site has no viewable cameras or the cameras layer is off.
            // Callers that mean "always land on the map" (the All-Sites tile grid,
            // the "Back to map" button) pass forceTab='map' to skip that memory.
            let lastTab = 'map';
            if(forceTab){
                lastTab = forceTab;
            } else {
                try { lastTab = localStorage.getItem('sm_site_tab') || 'map'; } catch(e) {}
            }
            const hasCams = this.cameras.some(c => c.can_feed && c.site_number === id);
            this.siteTab = (lastTab === 'cameras' && hasCams && this.cameraWallEnabled !== false) ? 'cameras' : 'map';
            this.closeDeviceEditor();
            this.roomSelect = { on:false, ids:[], box:null };
            this._clearWallObservers();   // tear down camera-wall observers on leave
            // Default to the site's first map (suite/floor), if it defines any.
            const st = this.siteById(id);
            if(st && st.maps && st.maps.length){
                // Prefer the map marked default; otherwise the first by sort order.
                const dflt = st.maps.find(m => m.is_default);
                this.selectedLevel = (dflt || st.maps[0]).key;
            }
            else { this.selectedLevel = 'level-1'; }
            this.loadBuildings();
            this.writeHash();
            this.loadSvgForCurrentSite();
            this._syncLevelSelectDom();
        },
        setSiteTab(t){
            this.siteTab = t;
            try { localStorage.setItem('sm_site_tab', t); } catch(e) {}
        },
        // Picking a site in the All Sites wall drills into that site's Cameras
        // tab — the left column follows, and the wall resets to "all" for next time.
        onWallSiteChange(v){
            // Filter the SAME Camera Wall grid down to one site — feedCameras
            // already filters by wall.site, so this just needs to set it. It
            // previously navigated away entirely (via goSite), landing on that
            // site's own Cameras tab instead of staying on the wall, which meant
            // bouncing back and forth to compare cameras across sites. Now you
            // stay put; picking "All sites" again clears the filter.
            this.wall.site = (v === 'all' || v === '' || v == null) ? 'all' : Number(v);
        },
        goRoom(roomId){
            const room = this.rooms.find(r=>r.room_id===roomId);
            if(!room) return;
            this.view='room';
            this.currentSiteId=room.site_number;
            this.currentRoomId=roomId;
            this.selectedDeviceId=null;
            this.placingDeviceId=null;
            this.panelTab='devices';
            this.writeHash();
        },

        // ====================================================
        // GETTERS
        // ====================================================
        // Canonical entity lookups — THE way to resolve a site from its id.
        // 16 call sites used to inline their own .find() for this; one shared
        // pair keeps null-handling and name-fallbacks identical everywhere.
        siteById(id){ return this.sites.find(s => s.id === id) || null; },
        /** Site display name, with a fallback (default 'site') for unknown ids. */
        siteName(id, fallback = 'site'){ return this.siteById(id)?.name || fallback; },
        get currentSite(){ return this.siteById(this.currentSiteId); },
        // The site currently selected in the Map view's placement panel.
        get geoEditSite(){ return this.siteById(this.geo.editSiteId); },
        get currentRoom(){ return this.rooms.find(r=>r.room_id===this.currentRoomId) || null; },

        // ---- User management computed views ----
        get userStats(){
            const u = this.usersModal.users;
            const inGroup = (x, name) => (x.groups||[]).some(g => g.name === name);
            return {
                total:   u.length,
                admin:   u.filter(x => inGroup(x,'Administrators')).length,
                editor:  u.filter(x => inGroup(x,'Editors')).length,
                viewer:  u.filter(x => inGroup(x,'Viewers')).length,
                disabled:u.filter(x => !x.is_active).length,
            };
        },
        get filteredUsers(){
            const m = this.usersModal;
            const q = m.search.trim().toLowerCase();
            const groupName = { admin:'Administrators', editor:'Editors', viewer:'Viewers' };
            let list = this.usersModal.users.filter(u => {
                if(m.roleFilter !== 'all'){
                    const gn = groupName[m.roleFilter];
                    if(gn && !((u.groups||[]).some(g => g.name === gn))) return false;
                }
                if(m.statusFilter === 'active' && !u.is_active) return false;
                if(m.statusFilter === 'disabled' && u.is_active) return false;
                if(q){
                    const groups = (u.groups||[]).map(g=>g.name).join(' ');
                    const hay = ((u.display_name||'') + ' ' + u.username + ' ' + groups).toLowerCase();
                    if(!hay.includes(q)) return false;
                }
                return true;
            });
            list.sort((a,b) => {
                switch(m.sortBy){
                    case 'role':    return ((b.groups||[]).length)-((a.groups||[]).length) || (a.display_name||a.username).localeCompare(b.display_name||b.username);
                    case 'login':   return (b.last_login||'').localeCompare(a.last_login||'');
                    case 'created': return (b.created_at||'').localeCompare(a.created_at||'') || (a.username||'').localeCompare(b.username||'');
                    default:        return (a.display_name||a.username).toLowerCase().localeCompare((b.display_name||b.username).toLowerCase());
                }
            });
            return list;
        },
        get allVisibleSelected(){
            const vis = this.filteredUsers.filter(u => u.public_id !== this.currentUserId);
            return vis.length > 0 && vis.every(u => this.usersModal.selected.includes(u.public_id));
        },
        // Site list filtered by the form's site-access search box
        get formSitesFiltered(){
            const q = (this.usersModal.siteSearch||'').trim().toLowerCase();
            if(!q) return this.sites;
            return this.sites.filter(s => (s.name||'').toLowerCase().includes(q) || (s.abbr||'').toLowerCase().includes(q));
        },
        get roomsForCurrentSite(){
            if(!this.currentSiteId) return [];
            return this.rooms.filter(r => r.site_number === this.currentSiteId);
        },
        get mapLevels(){
            const set = new Set();
            this.roomsForCurrentSite.forEach(r => set.add(r.map_level || 'level-1'));
            if(set.size===0) set.add('level-1');
            return [...set].sort();
        },
        get roomsVisible(){
            return this.roomsForCurrentSite.filter(r => {
                // Pin model: a room shows if it has any position (label or polygon).
                const hasPos = (r.label_x !== null && r.label_x !== undefined)
                            || (r.polygon_points && r.polygon_points.length);
                if(!hasPos) return false;
                return (r.map_level || 'level-1') === this.selectedLevel;
            });
        },
        // Room pins actually drawn on the map. Mirrors camerasOnMap/printersOnMap:
        // the show/hide toggle is baked into the getter (the reliable pattern),
        // rather than relying on x-show per pin. In edit mode pins always show.
        get roomPinsOnMap(){
            // Don't show pins until the map background has finished loading —
            // otherwise they float over the loading spinner with nothing behind them.
            if(this.mapSvgLoading) return [];
            if(!this.showPins && !this.roomEditMode) return [];
            return this.roomsVisible;
        },
        // Cameras to draw on the current site map: this site, current level, with
        // map coordinates, and only when the camera layer is toggled on. (The list
        // is already permission-filtered server-side — nothing hidden reaches here.)
        get camerasOnMap(){
            if(this.mapSvgLoading) return [];   // wait for the map background
            if(!this.showCameras || !this.currentSiteId) return [];
            return this.cameras.filter(c =>
                c.site_number === this.currentSiteId &&
                c.map_x !== null && c.map_y !== null &&
                (c.map_level || 'level-1') === this.selectedLevel
            );
        },
        get printersOnMap(){
            if(this.mapSvgLoading) return [];   // wait for the map background
            if(!this.showPrinters || !this.currentSiteId) return [];
            return this.printers.filter(p =>
                p.site_number === this.currentSiteId &&
                p.map_x !== null && p.map_y !== null &&
                (p.map_level || 'level-1') === this.selectedLevel
            );
        },
        get printerCountForSite(){
            if(!this.currentSiteId) return 0;
            return this.printers.filter(p => p.site_number === this.currentSiteId).length;
        },
        // Printers placed inside the currently-open room (room diagram pins).
        get printersInCurrentRoom(){
            if(!this.currentRoomId) return [];
            return this.printers.filter(p => p.room_id === this.currentRoomId && p.room_pos_x !== null && p.room_pos_y !== null);
        },
        // All of this SITE's printers — site is the only filter (room doesn't matter).
        // Ones already placed in THIS room are marked so you don't double-add.
        get availablePrintersForRoom(){
            if(!this.currentRoom) return [];
            const sid = this.currentRoom.site_number;
            return this.printers
                .filter(p => p.site_number === sid)
                .slice()
                .sort((a,b) => (a.printer_name||'').localeCompare(b.printer_name||'', undefined, {numeric:true}));
        },
        // Is this printer already placed in the room currently open?
        printerInThisRoom(pr){
            return pr.room_id === this.currentRoomId && pr.room_pos_x !== null && pr.room_pos_y !== null;
        },
        // Drag a printer from the room's list onto the room diagram → assigns it here.
        startPrinterRoomListDrag(pr, ev){
            if(!this.canEdit) return;
            if(ev.button !== undefined && ev.button !== 0) return;
            const stage = this.$refs.stage;
            const canPlace = this.deviceEditMode && !!stage;
            ev.preventDefault();
            const startX = ev.clientX, startY = ev.clientY;
            let dragging = false; const threshold = 5;
            const pct = (e) => {
                const rect = stage.getBoundingClientRect();
                return { inside: e.clientX>=rect.left && e.clientX<=rect.right && e.clientY>=rect.top && e.clientY<=rect.bottom,
                    x: Math.max(0, Math.min(100, ((e.clientX-rect.left)/rect.width)*100)),
                    y: Math.max(0, Math.min(100, ((e.clientY-rect.top)/rect.height)*100)) };
            };
            const onMove = (e) => {
                if(!canPlace) return;
                if(!dragging){
                    if(Math.abs(e.clientX-startX)<threshold && Math.abs(e.clientY-startY)<threshold) return;
                    dragging = true;
                    this.listDrag = { active:true, dev:null, printer:pr, x:e.clientX, y:e.clientY, over:false };
                }
                const p = pct(e); this.listDrag.x = e.clientX; this.listDrag.y = e.clientY; this.listDrag.over = p.inside;
            };
            const onUp = async (e) => {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup', onUp);
                if(dragging){
                    const p = pct(e);
                    if(p.inside){
                        pr.room_id = this.currentRoomId;
                        pr.room_pos_x = Math.round(p.x*100)/100;
                        pr.room_pos_y = Math.round(p.y*100)/100;
                        await this.assignPrinterRoom(pr);
                    }
                    this.listDrag = { active:false, dev:null, printer:null, x:0, y:0, over:false };
                } else {
                    if(!this.deviceEditMode) this.showToast('Turn on “Edit Devices” first, then drag', 'err');
                }
            };
            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup', onUp);
        },
        // Move an already-placed printer within the room diagram.
        startPrinterRoomDrag(pr, ev){
            if(!this.canEdit || !this.deviceEditMode) return;
            ev.stopPropagation(); ev.preventDefault();
            this._beginPinDrag(ev);
            this.selectedPrinter = pr;
            const stage = this.$refs.stage; if(!stage) return;
            const move = (e) => {
                this._notePinDragMove(e);
                const rect = stage.getBoundingClientRect();
                pr.room_pos_x = Math.round(Math.max(0,Math.min(100,((e.clientX-rect.left)/rect.width)*100))*100)/100;
                pr.room_pos_y = Math.round(Math.max(0,Math.min(100,((e.clientY-rect.top)/rect.height)*100))*100)/100;
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                this.savePrinterRoomPosition(pr);
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },
        async assignPrinterRoom(pr){
            try {
                const res = await fetch('?api=printer&action=assign_room', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ printer_id: pr.printer_id, room_id: pr.room_id, room_pos_x: pr.room_pos_x, room_pos_y: pr.room_pos_y })
                });
                const data = await res.json();
                if(data.success) this.showToast('Printer added to room', 'ok');
                else { this.showToast(data.error || 'Could not add printer', 'err'); pr.room_id = null; }
            } catch(e){ this.showToast('Network error', 'err'); pr.room_id = null; }
        },
        async savePrinterRoomPosition(pr){
            try {
                await fetch('?api=printer&action=set_room_position', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ printer_id: pr.printer_id, room_pos_x: pr.room_pos_x, room_pos_y: pr.room_pos_y })
                });
            } catch(e){ /* non-fatal */ }
        },
        async unassignPrinterRoom(pr){
            try {
                const res = await fetch('?api=printer&action=unassign_room', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ printer_id: pr.printer_id })
                });
                const data = await res.json();
                if(data.success){ pr.room_id = null; pr.room_pos_x = null; pr.room_pos_y = null; this.selectedPrinter = null; this.showToast('Printer removed from room', 'ok'); }
                else this.showToast(data.error || 'Could not remove', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // Printers for the placement tray: this site, filtered, unplaced first.
        // Drag a printer in edit mode (editor+), else tap opens its info card.
        onPrinterPinDown(pr, ev){
            ev.stopPropagation();
            const startX = ev.clientX, startY = ev.clientY;
            let moved = false;
            const canDrag = this.roomEditMode && this.canEdit;
            const canvas = this.$refs.canvas;
            const rect = (canDrag && canvas) ? canvas.getBoundingClientRect() : null;
            const move = (e) => {
                if(Math.hypot(e.clientX - startX, e.clientY - startY) >= 4) moved = true;
                if(!moved || !canDrag || !rect) return;
                pr.map_x = Math.round(this._pctX(e.clientX, rect) * 100) / 100;
                pr.map_y = Math.round(this._pctY(e.clientY, rect) * 100) / 100;
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                if(moved && canDrag){ this.savePrinterPosition(pr); return; }
                if(!moved) this.openPrinterInfo(pr);
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },
        async savePrinterPosition(pr){
            try {
                const res = await fetch('?api=printer&action=set_position', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ printer_id: pr.printer_id, map_x: pr.map_x, map_y: pr.map_y, map_level: pr.map_level || this.selectedLevel || 'level-1' })
                });
                const data = await res.json();
                if(data.success) this.showToast('Printer placed', 'ok');
                else this.showToast(data.error || 'Could not move printer', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        openPrinterInfo(pr){ this.selectedPrinter = pr; },
        closePrinterInfo(){ this.selectedPrinter = null; },
        newPrinter(){
            this.printerForm = { open:true, printer_id:0, site_number:this.currentSiteId, printer_name:'', location:'', web_interface:'', model:'', serial_number:'', mac_address:'', toner_id:'', barcode:'', notes:'', map_level:this.selectedLevel||'level-1' };
        },
        editPrinter(pr){
            this.printerForm = { open:true, printer_id:pr.printer_id, site_number:pr.site_number, printer_name:pr.printer_name||'', location:pr.location||'', web_interface:pr.web_interface||'', model:pr.model||'', serial_number:pr.serial_number||'', mac_address:pr.mac_address||'', toner_id:pr.toner_id||'', barcode:pr.barcode||'', notes:pr.notes||'', map_level:pr.map_level||'level-1' };
            this.selectedPrinter = null;
        },
        async savePrinter(){
            const f = this.printerForm;
            if(!f.printer_name.trim()){ this.showToast('Printer name is required', 'err'); return; }
            try {
                const res = await fetch('?api=printer&action=save', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify(f)
                });
                const data = await res.json();
                if(data.success){
                    await this.reloadPrinters();
                    this.printerForm.open = false;
                    this.showToast('Printer saved', 'ok');
                } else this.showToast(data.error || 'Could not save printer', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async deletePrinter(pr){
            if(!confirm('Delete printer "' + (pr.printer_name||'') + '"?')) return;
            try {
                const res = await fetch('?api=printer&action=delete', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ printer_id: pr.printer_id })
                });
                const data = await res.json();
                if(data.success){ this.selectedPrinter=null; this.printerForm.open=false; await this.reloadPrinters(); this.showToast('Printer deleted', 'ok'); }
                else this.showToast(data.error || 'Could not delete', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async reloadPrinters(){
            if(!this.currentSiteId) return;
            const sid = this.currentSiteId;   // capture BEFORE the await
            try {
                const res = await fetch('?api=printer&action=list&site=' + sid);
                const data = await res.json();
                if(this.currentSiteId !== sid) return;   // switched sites mid-fetch
                if(data.success){
                    this.printers = this.printers.filter(p => p.site_number !== sid)
                        .concat((data.printers||[]).map(p => ({
                            printer_id:+p.printer_id, site_number:+p.site_number, printer_name:p.printer_name,
                            location:p.location||'', web_interface:p.web_interface||'', model:p.model||'',
                            serial_number:p.serial_number||'', mac_address:p.mac_address||'', toner_id:p.toner_id||'',
                            barcode:p.barcode||'', notes:p.notes||'',
                            map_x:(p.map_x!==null&&p.map_x!=='')?+p.map_x:null, map_y:(p.map_y!==null&&p.map_y!=='')?+p.map_y:null,
                            map_level:p.map_level||'level-1', rotation:+(p.map_icon_rotation||0)
                        })));
                }
            } catch(e){ /* non-fatal */ }
        },
        // ----- PrinterLogic CSV import -----
        openPrinterImport(){ this.printerImport = { open:true, rows:[], busy:false, error:'', selected:[], filter:'all', bulkSite:0 }; },

        // ============ PEOPLE IMPORTER ============
        openPeopleImport(){ this.peopleImport = { open:true, rows:[], busy:false, error:'', selected:[], filter:'all', bulkSite:0, siteFilter:0, sortBySite:false }; },
        onPeopleCsv(ev){
            const file = ev.target.files && ev.target.files[0];
            ev.target.value = '';
            if(!file) return;
            const reader = new FileReader();
            reader.onload = () => {
                try { this._peopleCsvToRows(String(reader.result)); }
                catch(e){ this.peopleImport.error = 'Could not read that CSV.'; }
            };
            reader.onerror = () => { this.peopleImport.error = 'Could not read the file.'; };
            reader.readAsText(file);
        },
        _peopleCsvToRows(text){
            const rows = this._csvToRows(text);   // reuse the printer importer's RFC-4180 parser
            if(!rows.length){ this.peopleImport.error = 'No rows found in the file.'; return; }
            // Normalize headers for matching.
            const header = rows[0].map(h => String(h||'').toLowerCase().replace(/\s+/g,' ').trim());
            // exact(...) prefers an exact header; contains(...) falls back to substring.
            const exact = (...names) => { for(const n of names){ const i = header.indexOf(n); if(i>=0) return i; } return -1; };
            const contains = (...names) => { for(const n of names){ const i = header.findIndex(h => h.includes(n)); if(i>=0) return i; } return -1; };
            const pick = (exacts, subs) => { const e = exact(...exacts); return e>=0 ? e : contains(...(subs||[])); };

            const idx = {
                // Name: separate first/last, OR a single "staff"/"name"/"full name" column.
                last:  pick(['last name','last'], ['lastname']),
                first: pick(['first name','first'], ['firstname']),
                staff: pick(['staff','employee','full name','name'], ['staff member']),
                pos:   pick(['position','title','role','job title'], ['position','title']),
                ext:   pick(['phone','extension','ext'], ['extension','phone','ext']),
                email: pick(['email','e-mail'], ['email','e-mail','mail']),
                // Room: COMBINED (grouped "A1-100") is best; else explicit "room #".
                // Carefully AVOID "room (old)", "room (current)", "room (type)".
                combined: pick(['combined','room (combined)'], ['combined']),
                roomnum:  pick(['room #','room number','room#'], []),
                building: pick(['building','bldg'], ['building']),
                site:  pick(['site','site name','location','school'], ['site','school']),
            };
            const get = (row, i) => (i >= 0 && i < row.length) ? String(row[i]).trim() : '';

            // Build a lookup of normalized site name/abbr → site id for auto-matching.
            const norm = (s) => String(s||'').toLowerCase().replace(/[^a-z0-9]+/g,' ').replace(/\s+/g,' ').trim();
            const siteByName = {};
            this.sites.forEach(s => {
                if(s.name) siteByName[norm(s.name)] = s.id;
                if(s.abbr) siteByName[norm(s.abbr)] = s.id;
            });
            const matchSite = (raw) => {
                if(!raw) return 0;
                const n = norm(raw);
                if(siteByName[n]) return siteByName[n];
                // loose: a site whose name contains the CSV value or vice-versa.
                for(const s of this.sites){
                    const sn = norm(s.name);
                    if(sn && (sn.includes(n) || n.includes(sn))) return s.id;
                }
                return 0;
            };

            const out = [];
            for(let r = 1; r < rows.length; r++){
                const row = rows[r];
                if(!row || !row.length) continue;
                // Name: first+last, else the single staff/name column.
                let name = '';
                if(idx.first >= 0 || idx.last >= 0){
                    name = (get(row, idx.first) + ' ' + get(row, idx.last)).trim();
                }
                if(!name && idx.staff >= 0) name = get(row, idx.staff);
                if(!name) continue;
                // Room value: prefer COMBINED (grouped), else ROOM #, optionally
                // prefixed with the BUILDING column if COMBINED wasn't present.
                let roomVal = get(row, idx.combined);
                if(!roomVal){
                    const rn = get(row, idx.roomnum);
                    const bld = get(row, idx.building);
                    roomVal = (bld && rn) ? (bld + '-' + rn) : rn;
                }
                const siteRaw = get(row, idx.site);
                const siteId = matchSite(siteRaw);
                out.push({
                    name,
                    role: get(row, idx.pos),
                    extension: get(row, idx.ext),
                    email: get(row, idx.email),
                    room_number: roomVal,
                    site_raw: siteRaw,
                    site_number: siteId,
                    room_action: 'skip',
                    room_id: 0,
                });
            }
            if(!out.length){ this.peopleImport.error = 'No people rows found (need at least a name).'; return; }
            // Auto-match rooms for any rows where we resolved a site.
            out.forEach(r => { if(r.site_number) this.peopleAutoMatch(r); });
            this.peopleImport.error = '';
            this.peopleImport.rows = out;
        },
        // Rooms at a site, for the "pick existing room" dropdown. Uses the grouped
        // label (e.g. "C1-300A") when the room is in a building, so grouped rooms
        // are identifiable; falls back to number/name otherwise.
        roomsForSite(siteId){
            if(!siteId) return [];
            return this.rooms.filter(r => r.site_number === siteId)
                .map(r => {
                    const grouped = this.roomNumberLabel(r);   // building-number, e.g. C1-300A
                    const name = (r.room_name || '').toString().trim();
                    let label = grouped || name || ('Room ' + r.room_id);
                    if(grouped && name && name.toLowerCase() !== grouped.toLowerCase()) label = grouped + ' — ' + name;
                    return { id: r.room_id, label };
                })
                .sort((a,b) => this._natCmp(a.label, b.label));
        },
        // When a site is chosen, try to auto-match the CSV room value to an
        // existing room at that site. Sites are in different states of grouping,
        // so we compare the CSV value against THREE forms of each room and also
        // strip any building prefix off the CSV value:
        //   1. grouped label  (e.g. "A1-100")
        //   2. raw room_number (e.g. "100")
        //   3. room_name       (e.g. "Room 100")
        // If found → match; else, if the value has a number → offer create; else
        // leave it for manual room selection.
        _roomKey(v){ return String(v||'').toLowerCase().replace(/\s+/g,'').trim(); },
        peopleAutoMatch(row){
            if(!row.site_number){ row.room_action = 'skip'; row.room_id = 0; return; }
            const raw = this._roomKey(row.room_number);
            if(!raw){ row.room_action = 'match'; return; }   // no number → pick manually
            // Also consider the CSV value with any "X1-" building prefix stripped,
            // so "A1-100" in the CSV still matches a room stored as plain "100".
            const stripped = this._roomKey(raw.replace(/^[a-z]+[0-9]+-/, ''));
            const wants = [raw]; if(stripped && stripped !== raw) wants.push(stripped);
            const hit = this.rooms.find(r => {
                if(r.site_number !== row.site_number) return false;
                const forms = [
                    this._roomKey(this.roomNumberLabel(r)),   // grouped: A1-100
                    this._roomKey(r.room_number),             // raw: 100
                    this._roomKey(r.room_name),               // name: Room 100
                ].filter(Boolean);
                return wants.some(w => forms.includes(w));
            });
            if(hit){ row.room_action = 'match'; row.room_id = hit.room_id; return; }
            row.room_action = 'create';
        },
        // Selection / filtering (mirrors the printer importer).
        // Sites that actually appear among the import rows (for the filter dropdown).
        get peopleImportSites(){
            const ids = new Set(this.peopleImport.rows.map(r => r.site_number).filter(Boolean));
            return this.sites.filter(s => ids.has(s.id)).map(s => ({ id:s.id, name:s.name }));
        },
        get visiblePeopleRows(){
            const f = this.peopleImport.filter;
            const sf = Number(this.peopleImport.siteFilter) || 0;
            let list = this.peopleImport.rows.map((r,i)=>({r,i})).filter(({r}) => {
                const ready = this._peopleRowReady(r);
                if(f === 'ready' && !ready) return false;
                if(f === 'needroom' && !(r.site_number && r.room_action !== 'skip' && !ready)) return false;
                if(f === 'nosite' && r.site_number) return false;
                if(sf && r.site_number !== sf) return false;
                return true;
            });
            if(this.peopleImport.sortBySite){
                const nameOf = (id) => this.siteName(id, '\uffff'); // unmatched sort last
                list = list.slice().sort((a,b) => {
                    const c = this._natCmp(nameOf(a.r.site_number), nameOf(b.r.site_number));
                    return c !== 0 ? c : this._natCmp(a.r.name, b.r.name);
                });
            }
            return list;
        },
        _peopleRowReady(r){
            if(!r.site_number) return false;
            if(r.room_action === 'create') return !!String(r.room_number||'').trim();
            if(r.room_action === 'match')  return !!r.room_id;
            return false; // skip
        },
        get peopleReadyCount(){ return this.peopleImport.rows.filter(r => this._peopleRowReady(r)).length; },
        get peopleNeedRoomCount(){ return this.peopleImport.rows.filter(r => r.site_number && r.room_action !== 'skip' && !this._peopleRowReady(r)).length; },
        get peopleSkipCount(){ return this.peopleImport.rows.filter(r => !r.site_number || r.room_action === 'skip').length; },
        peopleSelectToggle(i){ const a=this.peopleImport.selected; const at=a.indexOf(i); if(at>=0)a.splice(at,1); else a.push(i); },
        peopleIsSelected(i){ return this.peopleImport.selected.includes(i); },
        get peopleAllVisibleSelected(){ const v=this.visiblePeopleRows.map(x=>x.i); return v.length>0 && v.every(i=>this.peopleImport.selected.includes(i)); },
        peopleToggleSelectAll(){
            const v = this.visiblePeopleRows.map(x=>x.i);
            if(this.peopleAllVisibleSelected){ this.peopleImport.selected = this.peopleImport.selected.filter(i=>!v.includes(i)); }
            else { const s=new Set(this.peopleImport.selected); v.forEach(i=>s.add(i)); this.peopleImport.selected=[...s]; }
        },
        peopleBulkSetSite(){
            const site = Number(this.peopleImport.bulkSite);
            if(!site){ this.showToast('Pick a site first', 'err'); return; }
            let n=0;
            this.peopleImport.selected.forEach(i => { const r=this.peopleImport.rows[i]; if(r){ r.site_number=site; r.room_id=0; this.peopleAutoMatch(r); n++; } });
            const sName = this.siteName(site);
            this.showToast('Set ' + n + ' to ' + sName, 'ok');
        },
        peopleBulkSetAction(act){
            let n=0;
            this.peopleImport.selected.forEach(i => {
                const r=this.peopleImport.rows[i];
                if(!r) return;
                if(act==='create'){ if(r.site_number && String(r.room_number||'').trim()){ r.room_action='create'; n++; } }
                else if(act==='skip'){ r.room_action='skip'; n++; }
            });
            this.showToast('Updated ' + n + ' row' + (n===1?'':'s'), 'ok');
        },
        async runPeopleImport(){
            const rows = this.peopleImport.rows
                .filter(r => this._peopleRowReady(r))
                .map(r => ({
                    name: r.name, role: r.role, extension: r.extension, email: r.email,
                    site_number: r.site_number, room_action: r.room_action,
                    room_id: r.room_id || 0, room_number: r.room_number || ''
                }));
            if(!rows.length){ this.showToast('Nothing ready to import', 'err'); return; }
            this.peopleImport.busy = true;
            try {
                const res = await fetch('?api=occupant&action=import', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ rows }) });
                const data = await res.json();
                if(data.success){
                    let msg = 'Imported ' + data.added + ' people';
                    if(data.rooms_created) msg += ' · ' + data.rooms_created + ' rooms created';
                    if(data.skipped) msg += ' · ' + data.skipped + ' skipped';
                    this.showToast(msg, data.failed ? 'err' : 'ok');
                    this.peopleImport.open = false;
                    // Reload so newly-created rooms and added people appear everywhere.
                    setTimeout(() => location.reload(), 900);
                } else this.showToast(data.error || 'Import failed', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.peopleImport.busy = false;
        },
        onPrinterCsv(ev){
            const file = ev.target.files && ev.target.files[0];
            if(!file) return;
            const reader = new FileReader();
            reader.onload = () => {
                try { this.parsePrinterCsv(String(reader.result)); }
                catch(e){ this.printerImport.error = 'Could not read that CSV. Is it a PrinterLogic export?'; }
            };
            reader.onerror = () => { this.printerImport.error = 'Could not read the file.'; };
            reader.readAsText(file);
        },
        parsePrinterCsv(text){
            const rows = this._csvToRows(text);
            if(!rows.length){ this.printerImport.error = 'The file looks empty.'; return; }
            const header = rows[0].map(h => h.trim());
            const col = (name) => header.findIndex(h => h.toLowerCase() === name.toLowerCase());
            const idx = {
                folder: col('Printer Folder'),
                name:   col('Printer Name'),
                rename: col('Rename To'),
                location: col('Location'),
                comment: col('Comment'),
                ip:     col('Port Address'),
                model:  col('CUSTOM (Text): Model'),
                sn:     col('CUSTOM (Text): SN'),
                mac:    col('CUSTOM (Text): MAC'),
                toner:  col('CUSTOM (Text): Toner ID'),
                notes:  col('CUSTOM (Text): Notes'),
                bc:     col('CUSTOM (Number): BC'),
            };
            if(idx.name < 0){ this.printerImport.error = 'No "Printer Name" column found — is this a PrinterLogic CSV?'; return; }
            // Build a lookup of site abbreviation (upper) → site id.
            const abbrMap = {};
            this.sites.forEach(s => { if(s.abbr) abbrMap[String(s.abbr).toUpperCase().trim()] = s.id; });
            // Duplicate detection. Prefer serial number (strongest identity); but
            // many printers have no serial, so fall back to printer NAME (normalized).
            // Matching only on serial meant serial-less printers could never be
            // detected as already-imported — re-importing made duplicates.
            const norm = v => String(v||'').toLowerCase().replace(/\s+/g,' ').trim();
            const existingSerials = new Set(this.printers.map(p => norm(p.serial_number)).filter(Boolean));
            const existingNames   = new Set(this.printers.map(p => norm(p.printer_name)).filter(Boolean));
            const seenSerials = new Set();
            const seenNames   = new Set();
            const out = [];
            const get = (row, i) => (i >= 0 && i < row.length) ? String(row[i]).trim() : '';
            for(let r = 1; r < rows.length; r++){
                const row = rows[r];
                if(!row || !row.length) continue;
                const name = get(row, idx.rename) || get(row, idx.name);
                if(!name) continue;
                const folder = get(row, idx.folder);
                // Parse the (XX) code at the end of the folder → site abbreviation.
                let siteId = 0;
                const m = folder.match(/\(([^)]+)\)\s*$/);
                if(m){ const code = m[1].toUpperCase().trim(); if(abbrMap[code]) siteId = abbrMap[code]; }
                const ip = get(row, idx.ip);
                const web = ip ? ('http://' + ip.replace(/^https?:\/\//i,'')) : '';
                const sn = get(row, idx.sn);
                const snKey = norm(sn);
                const nameKey = norm(name);
                // Dup if serial matches an existing/seen serial, OR (when no serial)
                // the printer name matches an existing/seen name.
                let dup = false;
                if(snKey){
                    dup = existingSerials.has(snKey) || seenSerials.has(snKey);
                    seenSerials.add(snKey);
                } else if(nameKey){
                    dup = existingNames.has(nameKey) || seenNames.has(nameKey);
                }
                if(nameKey) seenNames.add(nameKey);
                // Fold the PrinterLogic Comment into notes if present.
                let notes = get(row, idx.notes);
                const comment = get(row, idx.comment);
                if(comment && comment !== notes) notes = notes ? (notes + ' — ' + comment) : comment;
                out.push({
                    printer_name: name, folder: folder || '(no folder)', site_number: siteId,
                    location: get(row, idx.location), web_interface: web,
                    model: get(row, idx.model), serial_number: sn, mac_address: get(row, idx.mac),
                    toner_id: get(row, idx.toner), barcode: get(row, idx.bc), notes: notes, dup: dup
                });
            }
            if(!out.length){ this.printerImport.error = 'No printer rows found in the file.'; return; }
            this.printerImport.error = '';
            this.printerImport.rows = out;
        },
        // Minimal RFC-4180-ish CSV parser (handles quotes, commas, newlines in quotes).
        _csvToRows(text){
            const rows = []; let field = ''; let row = []; let inQ = false;
            text = text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
            for(let i = 0; i < text.length; i++){
                const ch = text[i];
                if(inQ){
                    if(ch === '"'){ if(text[i+1] === '"'){ field += '"'; i++; } else inQ = false; }
                    else field += ch;
                } else {
                    if(ch === '"') inQ = true;
                    else if(ch === ',') { row.push(field); field = ''; }
                    else if(ch === '\n'){ row.push(field); rows.push(row); row = []; field = ''; }
                    else field += ch;
                }
            }
            if(field !== '' || row.length){ row.push(field); rows.push(row); }
            return rows.filter(r => r.length && !(r.length === 1 && r[0].trim() === ''));
        },
        // Rows shown in the preview, after the filter toggle. We keep the original
        // index alongside each row so selection/edits map back to the real array.
        get visibleImportRows(){
            const f = this.printerImport.filter;
            return this.printerImport.rows
                .map((r, i) => ({ r, i }))
                .filter(({r}) => {
                    if(f === 'needsite') return !r.dup && !r.site_number;   // only rows still needing a site
                    if(f === 'ready')    return !r.dup && r.site_number;     // only importable rows
                    if(f === 'hidedup')  return !r.dup;                      // everything except duplicates
                    return true;                                            // all
                });
        },
        importSelectToggle(i){
            const arr = this.printerImport.selected;
            const at = arr.indexOf(i);
            if(at >= 0) arr.splice(at, 1); else arr.push(i);
        },
        importIsSelected(i){ return this.printerImport.selected.includes(i); },
        get importAllVisibleSelected(){
            const vis = this.visibleImportRows.map(x => x.i);
            return vis.length > 0 && vis.every(i => this.printerImport.selected.includes(i));
        },
        importToggleSelectAll(){
            const vis = this.visibleImportRows.map(x => x.i);
            if(this.importAllVisibleSelected){
                // deselect all visible
                this.printerImport.selected = this.printerImport.selected.filter(i => !vis.includes(i));
            } else {
                // add all visible (keep any already selected)
                const set = new Set(this.printerImport.selected);
                vis.forEach(i => set.add(i));
                this.printerImport.selected = [...set];
            }
        },
        // Assign the chosen site to every selected row at once.
        importBulkSetSite(){
            const site = Number(this.printerImport.bulkSite);
            if(!site){ this.showToast('Pick a site to assign first', 'err'); return; }
            let n = 0;
            this.printerImport.selected.forEach(i => {
                const r = this.printerImport.rows[i];
                if(r && !r.dup){ r.site_number = site; n++; }
            });
            const sName = this.siteName(site);
            this.showToast('Set ' + n + ' printer' + (n===1?'':'s') + ' to ' + sName, 'ok');
        },
        importClearSelection(){ this.printerImport.selected = []; },
        async runPrinterImport(){
            const rows = this.printerImport.rows.filter(r => r.site_number && !r.dup).map(r => ({
                site_number: r.site_number, printer_name: r.printer_name, location: r.location,
                web_interface: r.web_interface, model: r.model, serial_number: r.serial_number,
                mac_address: r.mac_address, toner_id: r.toner_id, barcode: r.barcode, notes: r.notes
            }));
            if(!rows.length){ this.showToast('Nothing to import — assign sites first', 'err'); return; }
            this.printerImport.busy = true;
            try {
                const res = await fetch('?api=printer&action=import', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ rows })
                });
                const data = await res.json();
                if(data.success){
                    this.printerImport.open = false;
                    await this.reloadPrinters();
                    let msg = 'Imported ' + data.imported + ' printer' + (data.imported===1?'':'s');
                    if(data.skipped_dup) msg += ' · ' + data.skipped_dup + ' duplicate skipped';
                    this.showToast(msg, 'ok');
                } else this.showToast(data.error || 'Import failed', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.printerImport.busy = false;
        },
        // How many cameras exist for this site (any level) — to label the toggle.
        get cameraCountForSite(){
            if(!this.currentSiteId) return 0;
            return this.cameras.filter(c => c.site_number === this.currentSiteId).length;
        },
        // Camera count for an arbitrary site (sidebar badges).
        cameraCountBySite(siteId){
            return this.cameras.filter(c => c.site_number === siteId).length;
        },
        get totalCameraCount(){
            return this.cameras.length;
        },
        // Feed-viewable cameras at the current site (the site Cameras tab).
        get siteFeedCameras(){
            if(!this.currentSiteId) return [];
            return this.cameras.filter(c => c.can_feed && c.site_number === this.currentSiteId)
                .slice().sort((a,b) =>
                    (this.cameraIsOnline(b)?1:0) - (this.cameraIsOnline(a)?1:0) ||
                    this._natCmp(a.camera_name, b.camera_name)
                );
        },
        cameraStatusText(c){
            if(!c) return '';
            if(!c.is_active) return 'Inactive';
            return c.online ? 'Online' : 'Offline';
        },
        cameraIsOnline(c){ return !!(c && c.online); },
        selectCamera(c){ this.selectedCamera = c; },
        closeCameraInfo(){ this.selectedCamera = null; },
        // Tap a camera: feed-access users jump straight to the live feed; object-only
        // users get the persistent info card.
        onCameraTap(cam){
            this.onCameraPinLeave();
            if(cam.can_feed) this.openCameraFeed(cam);
            else this.selectCamera(cam);
        },
        // Camera pins need the same pointerdown treatment as room pins, otherwise the
        // viewport's pan handler captures the pointer and the tap never lands.
        onCameraPinDown(cam, ev){
            ev.stopPropagation();           // don't let the viewport start panning
            const startX = ev.clientX, startY = ev.clientY;
            let moved = false;
            // In edit mode, editors can drag cameras to reposition them — same as rooms.
            const canDrag = this.roomEditMode && this.canEdit;
            const canvas = this.$refs.canvas;
            const rect = (canDrag && canvas) ? canvas.getBoundingClientRect() : null;
            const move = (e) => {
                if(Math.hypot(e.clientX - startX, e.clientY - startY) >= 4) moved = true;
                if(!moved || !canDrag || !rect) return;
                cam.map_x = Math.round(this._pctX(e.clientX, rect) * 100) / 100;
                cam.map_y = Math.round(this._pctY(e.clientY, rect) * 100) / 100;
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                if(moved && canDrag){ this.saveCameraPosition(cam); return; }
                if(!moved) this.onCameraTap(cam);   // a tap (not a drag) acts on the camera
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },
        async saveCameraPosition(cam){
            try {
                const res = await fetch('?api=camera&action=set_position', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ camera_number: cam.camera_number, map_x: cam.map_x, map_y: cam.map_y })
                });
                const data = await res.json();
                if(data.success) this.showToast('Camera moved', 'ok');
                else this.showToast(data.error || 'Could not move camera', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // Hover preview (after a short delay so sweeping the map doesn't spawn iframes).
        onCameraPinEnter(cam, ev){
            clearTimeout(this._camHoverTimer);
            clearTimeout(this._camHoverHideTimer);
            const r = ev.currentTarget.getBoundingClientRect();
            const px = r.left + r.width / 2;
            const py = r.top;   // card sits above the pin (translateY -100% in CSS)
            this._camHoverTimer = setTimeout(() => {
                this.camHover = { show:true, cam, x:px, y:py };
            }, 280);
        },
        onCameraPinLeave(){
            clearTimeout(this._camHoverTimer);
            // small grace period so moving onto the card itself doesn't close it
            this._camHoverHideTimer = setTimeout(() => { this.camHover = { show:false, cam:null, x:0, y:0 }; }, 120);
        },
        cancelCamHoverHide(){ clearTimeout(this._camHoverHideTimer); },
        // ---- Camera Wall (Phase 4) ----
        // Does this user have feed access to ANY camera? (drives the nav item)
        get hasAnyFeedAccess(){ return this.cameras.some(c => c.can_feed); },
        // Cameras the user may watch, honoring the wall's site filter.
        // Ordered by SITE first (in sites-list order), then online-first, then name.
        get feedCameras(){
            let list = this.cameras.filter(c => c.can_feed);
            if(this.wall.site !== 'all'){
                const sid = Number(this.wall.site);   // <select> gives a string; site_number is numeric
                list = list.filter(c => c.site_number === sid);
            }
            // Camera search: name, number, IP, or site name.
            const q = (this.wall.q || '').toLowerCase().trim();
            if(q){
                const siteName = {};
                this.sites.forEach(s => siteName[s.id] = (s.name||'').toLowerCase());
                list = list.filter(c =>
                    (c.camera_name||'').toLowerCase().includes(q) ||
                    String(c.camera_number||'').includes(q) ||
                    (c.camera_ip||'').toLowerCase().includes(q) ||
                    (siteName[c.site_number]||'').includes(q)
                );
            }
            const siteOrder = {};
            this.sites.forEach((s, i) => siteOrder[s.id] = i);
            return list.slice().sort((a,b) =>
                (siteOrder[a.site_number] ?? 999) - (siteOrder[b.site_number] ?? 999) ||
                (this.cameraIsOnline(b)?1:0) - (this.cameraIsOnline(a)?1:0) ||
                this._natCmp(a.camera_name, b.camera_name)
            );
        },
        // The wall rendered as site groups (site order top to bottom).
        get wallGroups(){
            const groups = [];
            let cur = null;
            for(const cam of this.feedCameras){
                if(!cur || cur.site_id !== cam.site_number){
                    const site = this.siteById(cam.site_number);
                    cur = { site_id: cam.site_number, site_name: site ? site.name : ('Site ' + cam.site_number), site_color: site ? site.color : '#888', cams: [] };
                    groups.push(cur);
                }
                cur.cams.push(cam);
            }
            return groups;
        },
        // Sites that actually have feed-viewable cameras (for the filter dropdown).
        get wallSites(){
            const ids = new Set(this.cameras.filter(c => c.can_feed).map(c => c.site_number));
            return this.sites.filter(s => ids.has(s.id));
        },
        goCameraWall(){
            if(!this.hasAnyFeedAccess) return;
            if(this.cameraWallEnabled === false){ this.goHome(); return; }
            this.view = 'cameras';
            this.currentSiteId = null;
            this.currentRoomId = null;
            this.selectedCamera = null;
            this.writeHash();
            this._navLog('goCameraWall');
        },
        // ---- Viewport-based streaming: a tile streams ONLY while on screen ----
        // Enter/leave tracked continuously; scrolling away unloads the stream,
        // scrolling back reloads it. wall.maxStreams caps simultaneous streams.
        wallEnter(camNum){
            camNum = String(camNum);
            if(!this.wall.visible.includes(camNum)){ this.wall.visible.push(camNum); this._scheduleWallStreamingRecompute(); }
        },
        wallLeave(camNum){
            camNum = String(camNum);
            const i = this.wall.visible.indexOf(camNum);
            if(i >= 0){ this.wall.visible.splice(i, 1); this._scheduleWallStreamingRecompute(); }
        },
        // A fast scroll can fire wallEnter/wallLeave for many tiles in a single
        // frame. Rather than recompute once per tile, coalesce them into a single
        // recompute on the next animation frame — same end result, far fewer calls.
        _scheduleWallStreamingRecompute(){
            if(this._wallStreamingRAF) return;
            this._wallStreamingRAF = requestAnimationFrame(() => {
                this._wallStreamingRAF = null;
                this._recomputeWallStreaming();
            });
        },
        // streamActive() is called once per tile on every render, so it must be
        // O(1): it just checks a precomputed Set. The Set (`wall.streaming`) is
        // recomputed only when the visible cameras, the cap, or the wall list
        // change — see _recomputeWallStreaming(), wired to those via watchers.
        streamActive(camNum){
            return this.wall.streaming.has(String(camNum));
        },
        // Recompute which cameras SHOULD stream: the first `maxStreams` VISIBLE
        // cameras in wall (top-to-bottom) order. This only decides the target set;
        // it does not necessarily apply it all at once — see below.
        _recomputeWallStreaming(){
            const cap = this.wall.maxStreams;
            const visible = new Set((this.wall.visible || []).map(String));
            const target = new Set();
            if(visible.size){
                const order = this._wallOrderedNums;   // cached cam numbers in wall order
                for(let i = 0; i < order.length && target.size < cap; i++){
                    if(visible.has(order[i])) target.add(order[i]);
                }
            }
            // Each camera that starts streaming renders a real <iframe> — its own
            // browsing context with a network request, HTML/JS parse, and video
            // decode. Turning on 20-30+ of those in the same instant (e.g. the
            // whole initial batch when the wall first loads) is genuinely heavy
            // work that was freezing the rest of the page — clicking elsewhere,
            // switching sites, etc. — while it happened. So: drop cameras that
            // should STOP streaming immediately (destroying an iframe is cheap),
            // but cameras that should START streaming are added a few at a time
            // with a short gap between batches, so the browser boots them as a
            // trickle instead of a burst.
            const current = this.wall.streaming;
            let removed = false;
            const kept = new Set();
            current.forEach(n => { if(target.has(n)) kept.add(n); else removed = true; });
            if(removed) this.wall.streaming = kept;
            const toAdd = [];
            target.forEach(n => { if(!kept.has(n)) toAdd.push(n); });
            if(this._wallStreamStagger){ clearTimeout(this._wallStreamStagger); this._wallStreamStagger = null; }
            if(!toAdd.length) return;
            let idx = 0;
            const BATCH = 3, GAP_MS = 60;
            const step = () => {
                const batch = toAdd.slice(idx, idx + BATCH);
                if(!batch.length){ this._wallStreamStagger = null; return; }
                const s = new Set(this.wall.streaming);
                batch.forEach(n => s.add(n));
                this.wall.streaming = s;
                idx += BATCH;
                this._wallStreamStagger = setTimeout(step, GAP_MS);
            };
            step();
        },
        // Camera numbers in wall order, cached. IMPORTANT: the cache check below
        // must NOT call feedCameras() to decide whether to recompute — feedCameras
        // does a full filter+sort over every camera, and this getter is read from
        // _recomputeWallStreaming(), which fires on EVERY IntersectionObserver
        // crossing while scrolling (many times per second on a big wall). Calling
        // feedCameras() there regardless of whether anything relevant changed was
        // re-sorting the entire camera list on every scroll tick — that was the
        // actual cause of the wall feeling laggy. Instead we check a few cheap
        // primitive signals (lengths, the current filter/search) that are the only
        // things that can change the order, and only pay for feedCameras' full
        // filter+sort when one of those has actually changed.
        get _wallOrderedNums(){
            const sig = this.cameras.length + '|' + this.wall.site + '|' + (this.wall.q||'') + '|' + this.sites.length;
            if(this._wonCache && this._wonSig === sig) return this._wonCache;
            this._wonCache = this.feedCameras.map(c => String(c.camera_number));
            this._wonSig = sig;
            return this._wonCache;
        },
        setWallMax(v){
            v = Math.max(4, Math.min(128, parseInt(v) || 32));
            this.wall.maxStreams = v;
            this._recomputeWallStreaming();
            try{ localStorage.setItem('sm_wall_max', String(v)); }catch(e){}
        },
        setWallCols(v){
            v = Math.max(1, Math.min(8, parseInt(v) || 3));
            this.wall.cols = v;
            try{ localStorage.setItem('sm_wall_cols', String(v)); }catch(e){}
        },
        // Attach an IntersectionObserver to a tile so it streams only when on
        // screen. Observers are TRACKED and the previous one for the same camera is
        // disconnected first — otherwise every wall re-render (site switch, resize,
        // search) would leak a fresh observer that keeps firing forever.
        _observeWallTile(el, camNum){
            const key = String(camNum);
            if(!this._wallObservers) this._wallObservers = new Map();
            // Disconnect any stale observer for this camera before making a new one.
            const prev = this._wallObservers.get(key);
            if(prev){ try { prev.disconnect(); } catch(e){} }
            const o = new IntersectionObserver(entries => {
                entries.forEach(e => { e.isIntersecting ? this.wallEnter(camNum) : this.wallLeave(camNum); });
            }, { rootMargin:'200px' });
            o.observe(el);
            this._wallObservers.set(key, o);
        },
        // Tear down all wall observers (call when leaving the wall) so none linger.
        _clearWallObservers(){
            if(this._wallObservers){
                this._wallObservers.forEach(o => { try { o.disconnect(); } catch(e){} });
                this._wallObservers.clear();
            }
            if(this._wallStreamingRAF){ cancelAnimationFrame(this._wallStreamingRAF); this._wallStreamingRAF = null; }
            if(this._wallStreamStagger){ clearTimeout(this._wallStreamStagger); this._wallStreamStagger = null; }
            this.wall.visible = [];
            this.wall.streaming = new Set();
        },
        // Convert a client point to a 0-100 map percentage, guarding against a
        // zero-sized rect (which would otherwise yield Infinity/NaN). Realistic
        // pointer handlers never fire on a hidden map, but new code should prefer
        // these helpers over raw division.
        _pctX(clientX, rect){
            if(!rect || !rect.width) return 0;
            return Math.max(0, Math.min(100, ((clientX - rect.left) / rect.width) * 100));
        },
        _pctY(clientY, rect){
            if(!rect || !rect.height) return 0;
            return Math.max(0, Math.min(100, ((clientY - rect.top) / rect.height) * 100));
        },
        // Expanded single-camera video modal (used by wall + map popup).
        openCameraFeed(cam){
            if(!cam || !cam.can_feed) return;
            this.feedModal = { open:true, cam };
        },
        closeCameraFeed(){
            // exit native fullscreen if active
            if(document.fullscreenElement){ document.exitFullscreen?.(); }
            this.feedModal = { open:false, cam:null };
        },
        // Toggle real browser fullscreen on the modal card (matches the NVR app).
        toggleFeedFullscreen(){
            const el = document.getElementById('feedModalCard');
            if(!el) return;
            if(document.fullscreenElement){
                document.exitFullscreen?.();
            } else {
                (el.requestFullscreen || el.webkitRequestFullscreen || el.msRequestFullscreen)?.call(el);
            }
        },
        // Cameras at the modal camera's site, in display order (online first, then name)
        // — the cycle order for the prev/next arrows.
        get feedModalList(){
            const cam = this.feedModal.cam;
            if(!cam) return [];
            return this.cameras.filter(c => c.can_feed && c.site_number === cam.site_number)
                .slice().sort((a,b) =>
                    (this.cameraIsOnline(b)?1:0) - (this.cameraIsOnline(a)?1:0) ||
                    this._natCmp(a.camera_name, b.camera_name)
                );
        },
        get feedModalIndex(){
            const c = this.feedModal.cam;
            if(!c) return -1;
            return this.feedModalList.findIndex(x => x.camera_number === c.camera_number);
        },
        cycleFeedCam(dir){
            const list = this.feedModalList;
            if(list.length < 2) return;
            const i = this.feedModalIndex;
            this.feedModal.cam = list[(i + dir + list.length) % list.length];
        },
        get editingRoom(){ return this.rooms.find(r=>r.room_id===this.editingRoomId) || null; },
        get devicesForCurrentRoom(){
            if(!this.currentRoomId) return [];
            return this.devices.filter(d => d.room_id === this.currentRoomId);
        },
        get placedDevices(){
            return this.devicesForCurrentRoom.filter(d => d.pos_x !== null && d.pos_y !== null);
        },

        devicesForRoom(roomId){
            if(!roomId) return [];
            return this.devices.filter(d => d.room_id === roomId);
        },
        isDevicePlaced(d){ return d && d.pos_x !== null && d.pos_y !== null; },
        // Two-step unplace guard: first tap arms the confirm, second tap (within 3s) unplaces.
        requestUnplace(dev, ev){
            if(ev){ ev.stopPropagation(); ev.preventDefault(); }
            if(this.unplaceConfirmId === dev.device_id){
                this.confirmUnplace(dev);
                return;
            }
            this.unplaceConfirmId = dev.device_id;
            clearTimeout(this._unplaceTimer);
            this._unplaceTimer = setTimeout(() => { this.unplaceConfirmId = null; }, 3000);
        },
        confirmUnplace(dev){
            clearTimeout(this._unplaceTimer);
            this.unplaceConfirmId = null;
            dev.pos_x = null;
            dev.pos_y = null;
            this.savePositionsDebounced([{ device_id: dev.device_id, pos_x: null, pos_y: null }]);
            this.showToast('Unplaced ' + (dev.device_name || 'device') + ' — drag it back to reposition', 'ok');
        },
        cancelUnplace(){ clearTimeout(this._unplaceTimer); this.unplaceConfirmId = null; },

        // ---- Unplace from the SITE map (rooms, cameras, printers) ----
        // Same two-tap guard as device unplace: in edit mode, hover a pin and a
        // small x appears; first tap arms it (red), second tap within 3s clears
        // the pin's position and the item returns to the Place-items column.
        mapUnplaceArm: null,
        requestMapUnplace(kind, id, ev){
            if(ev){ ev.stopPropagation(); ev.preventDefault(); }
            const key = kind + ':' + id;
            if(this.mapUnplaceArm === key){ this.doMapUnplace(kind, id); return; }
            this.mapUnplaceArm = key;
            clearTimeout(this._mapUnplaceTimer);
            this._mapUnplaceTimer = setTimeout(() => { this.mapUnplaceArm = null; }, 3000);
        },
        async doMapUnplace(kind, id){
            clearTimeout(this._mapUnplaceTimer);
            this.mapUnplaceArm = null;
            if(kind === 'room'){
                const r = this.rooms.find(x => x.room_id === id);
                const res = await this.api('?api=room&action=unplace', { room_id:id }, 'Could not unplace room');
                if(!res) return;
                if(r){ r.label_x = null; r.label_y = null; r.polygon_points = []; }
                if(this.editingRoomId === id){ this.editingRoomId = null; this.editForm = {}; }
                this.showToast('Unplaced ' + ((r && (r.room_number || r.room_name)) || 'room') + ' \u2014 it\u2019s back in Place items', 'ok');
            } else if(kind === 'cam'){
                const c = this.cameras.find(x => x.camera_number === id);
                const res = await this.api('?api=camera&action=set_position', { camera_number:id, unplace:true }, 'Could not unplace camera');
                if(!res) return;
                if(c){ c.map_x = null; c.map_y = null; }
                if(this.selectedCamera && this.selectedCamera.camera_number === id) this.selectedCamera = null;
                this.showToast('Unplaced ' + ((c && c.camera_name) || 'camera') + ' \u2014 it\u2019s back in Place items', 'ok');
            } else if(kind === 'printer'){
                const pr = this.printers.find(x => x.printer_id === id);
                const res = await this.api('?api=printer&action=set_position', { printer_id:id, unplace:true }, 'Could not unplace printer');
                if(!res) return;
                if(pr){ pr.map_x = null; pr.map_y = null; }
                if(this.selectedPrinter && this.selectedPrinter.printer_id === id) this.selectedPrinter = null;
                this.showToast('Unplaced ' + ((pr && pr.printer_name) || 'printer') + ' \u2014 it\u2019s back in Place items', 'ok');
            }
        },

        // ---- Glance-card helpers ----
        // The headline person for a room: the one flagged primary, else the first.
        primaryOccupant(room){
            const list = (room && room.occupants) || [];
            if(!list.length) return null;
            return list.find(o => o.is_primary) || list[0];
        },
        primaryInitials(room){
            const p = this.primaryOccupant(room);
            if(!p || !p.name) return '?';
            return p.name.trim().split(/\s+/).slice(0,2).map(s => s[0].toUpperCase()).join('');
        },
        // True if any device in the room is not "active" — drives the amber dot.
        roomNeedsAttention(room){
            if(!room) return false;
            return this.devicesForRoom(room.room_id).some(d => d.status && d.status !== 'active');
        },
        // "2 TVs · 1 printer · 1 phone" — counts by device type, pluralized.
        deviceSummary(roomId){
            const devs = this.devicesForRoom(roomId);
            if(!devs.length) return '';
            const counts = {};
            devs.forEach(d => { counts[d.device_type_key] = (counts[d.device_type_key]||0)+1; });
            return Object.entries(counts).map(([k,n]) => {
                const name = this.typeName(k);
                return n + ' ' + (n>1 ? this._plural(name) : name);
            }).join(' · ');
        },
        _plural(s){
            if(/s$/i.test(s)) return s;
            if(/[^aeiou]y$/i.test(s)) return s.slice(0,-1)+'ies';
            return s + 's';
        },
        // Copy text to the clipboard. Uses the modern API when available (HTTPS or
        // localhost), and falls back to a temporary textarea + execCommand for plain
        // HTTP, where navigator.clipboard is undefined. Returns a Promise<bool>.
        async copyToClipboard(text){
            text = String(text == null ? '' : text);
            // Modern path — only present in secure contexts.
            if(navigator.clipboard && navigator.clipboard.writeText && window.isSecureContext){
                try { await navigator.clipboard.writeText(text); return true; }
                catch(e){ /* fall through to legacy */ }
            }
            // Legacy fallback — works over HTTP.
            try {
                const ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-1000px';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.focus();
                ta.select();
                ta.setSelectionRange(0, text.length);
                const ok = document.execCommand('copy');
                document.body.removeChild(ta);
                return ok;
            } catch(e){ return false; }
        },
        async copyExt(ext){
            if(!ext) return;
            const text = String(ext);
            const ok = await this.copyToClipboard(text);
            this.showToast(ok ? ('Copied ext. ' + text) : ('Ext. ' + text + ' — copy manually'), ok ? 'ok' : 'err');
        },

        // ---- People management (room view tab) ----
        openPeopleTab(){
            this.panelTab = 'people';
            const r = this.currentRoom;
            this.peopleEditor = {
                room_extension: (r && r.room_extension) || '',
                room_notes: (r && r.room_notes) || '',
                occupants: ((r && r.occupants) || []).map(o => ({
                    name:o.name||'', role:o.role||'', extension:o.extension||'', email:o.email||'', is_primary:!!o.is_primary,
                })),
            };
        },
        pmAdd(){
            const first = this.peopleEditor.occupants.length === 0;
            this.peopleEditor.occupants.push({ name:'', role:'', extension:'', email:'', is_primary:first });
        },
        pmRemove(idx){
            const wasPrimary = this.peopleEditor.occupants[idx]?.is_primary;
            this.peopleEditor.occupants.splice(idx,1);
            if(wasPrimary && this.peopleEditor.occupants.length){
                this.peopleEditor.occupants.forEach((o,i) => o.is_primary = (i===0));
            }
        },
        pmSetPrimary(idx){
            this.peopleEditor.occupants.forEach((o,i) => o.is_primary = (i===idx));
        },
        async savePeople(){
            if(!this.can('base','edit')) return;
            const r = this.currentRoom;
            if(!r) return;
            try {
                // save the room's extension + notes via room save (merge with current room)
                const payload = {
                    room_id: r.room_id, site_number: r.site_number,
                    room_name: r.room_name, room_number: r.room_number || '',
                    building: r.building || '',
                    room_type: r.room_type || 'general', department: r.department || '',
                    capacity: r.capacity || null, description: r.description || '',
                    map_level: r.map_level || 'level-1', color: r.color || '',
                    room_extension: this.peopleEditor.room_extension || '',
                    room_notes: this.peopleEditor.room_notes || '',
                    label_x: r.label_x, label_y: r.label_y,
                    polygon_points: r.polygon_points || [],
                };
                const rRes = await fetch('?api=room&action=save', {
                    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload)
                });
                const rData = await rRes.json();
                if(!rData.success){ this.showToast(rData.error || 'Save failed', 'err'); return; }

                const ocRes = await fetch('?api=occupant&action=save_all', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ room_id: r.room_id, occupants: this.peopleEditor.occupants })
                });
                const ocData = await ocRes.json();

                // sync local room
                const saved = rData.room;
                saved.polygon_points = Array.isArray(saved.polygon_points) ? saved.polygon_points : [];
                saved.occupants = (ocData.success ? ocData.occupants : (saved.occupants || []));
                const i = this.rooms.findIndex(x => x.room_id === r.room_id);
                if(i >= 0) this.rooms.splice(i, 1, saved);
                this.showToast('People saved', 'ok');
            } catch(e){ this.showToast('Network error', 'err'); }
        },

        // ====================================================
        // AUTH + USER MANAGEMENT
        // ====================================================
        async logout(){
            try { await fetch('?api=auth&action=logout'); } catch(e){}
            window.location = window.location.pathname;
        },
        // change own password
        async submitPassword(){
            if(this.pwModal.p1.length < 8){ this.showToast('Password must be at least 8 characters', 'err'); return; }
            if(this.pwModal.p1 !== this.pwModal.p2){ this.showToast('Passwords do not match', 'err'); return; }
            try {
                const res = await fetch('?api=auth&action=change_password', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ new_password: this.pwModal.p1 })
                });
                const data = await res.json();
                if(data.success){ this.pwModal.open = false; this.pwModal.forced = false; this.showToast('Password updated', 'ok'); }
                else this.showToast(data.error || 'Could not update password', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },

        // ---- Two-factor (MFA) ----
        // ---- Profile avatar upload/remove ----
        get myAvatarUrl(){
            // Names its subject explicitly, like every other avatar request.
            return this.myAvatarPath ? ('?api=image&action=serve&kind=avatar&id=' + encodeURIComponent(this.currentUserId || '') + '&v=' + encodeURIComponent(this.myAvatarPath)) : '';
        },
        // ---- Admin: manage another user's profile photo ----
        // Same endpoint as the self-serve flow; the server decides whether the
        // caller may touch this target (self, or manage_users:manage).
        adminAvatarTarget:null,
        adminPickAvatar(u){
            this.adminAvatarTarget = u;
            const el = document.getElementById('adminAvatarInput');
            if(el) el.click();
        },
        async adminUploadAvatar(ev){
            const file = ev.target.files && ev.target.files[0];
            ev.target.value = '';
            const u = this.adminAvatarTarget;
            if(!file || !u) return;
            const pub = u.public_id;
            if(!pub){ this.showToast('Could not identify that user \u2014 reopen Manage Users and try again', 'err'); return; }
            if(file.size > 5*1024*1024){ this.showToast('Image too large (max 5MB)', 'err'); return; }
            this.avatarBusy = true;
            try {
                const fd = new FormData();
                fd.append('kind', 'avatar');
                fd.append('public_id', pub);
                fd.append('file', file);
                const res = await fetch('?api=image&action=upload', { method:'POST', body: fd });
                // Read as text first: if PHP emits a warning or an error page,
                // res.json() throws and the old catch reported a meaningless
                // "Network error", hiding the actual cause. Surface it instead.
                const raw = await res.text();
                let data;
                try { data = JSON.parse(raw); }
                catch(e){
                    console.error('[avatar upload] non-JSON response', res.status, raw);
                    this.showToast('Server error ' + res.status + ': ' + raw.replace(/<[^>]*>/g,' ').trim().slice(0,180), 'err');
                    this.avatarBusy = false; this.adminAvatarTarget = null; return;
                }
                if(data.success){
                    // Trust the server's echo of WHO it actually changed rather
                    // than assuming it honoured our target — that assumption is
                    // what made a mis-targeted upload look like it had worked.
                    const hit = (this.usersModal.users || []).find(x => x.public_id === (data.public_id || pub)) || u;
                    hit.profile_image = data.path;
                    if(hit.public_id === this.currentUserId) this.myAvatarPath = data.path;
                    this.showToast('Photo updated for ' + (hit.display_name || hit.username), 'ok');
                } else this.showToast(data.error || 'Upload failed', 'err');
            } catch(e){
                console.error('[avatar upload]', e);
                this.showToast('Upload failed: ' + (e && e.message ? e.message : e), 'err');
            }
            this.avatarBusy = false;
            this.adminAvatarTarget = null;
        },
        async adminRemoveAvatar(u){
            // An empty public_id would be treated as "me" by the server — the
            // caller-default that caused every wrong-target bug in this feature.
            if(!u.public_id){ this.showToast('Could not identify that user \u2014 reopen Manage Users and try again', 'err'); return; }
            this.avatarBusy = true;
            try {
                const fd = new FormData();
                fd.append('kind', 'avatar');
                fd.append('public_id', u.public_id || '');
                const res = await fetch('?api=image&action=remove', { method:'POST', body: fd });
                const data = await res.json();
                if(data.success){
                    u.profile_image = '';
                    if(u.public_id === this.currentUserId) this.myAvatarPath = '';
                    this.showToast('Photo removed for ' + (u.display_name || u.username), 'ok');
                } else this.showToast(data.error || 'Could not remove', 'err');
            } catch(e){
                console.error('[avatar remove]', e);
                this.showToast('Could not remove: ' + (e && e.message ? e.message : e), 'err');
            }
            this.avatarBusy = false;
        },
        async uploadAvatar(ev){
            const file = ev.target.files && ev.target.files[0];
            ev.target.value = '';
            if(!file) return;
            if(file.size > 5*1024*1024){ this.showToast('Image too large (max 5MB)', 'err'); return; }
            this.avatarBusy = true;
            try {
                const fd = new FormData();
                fd.append('kind', 'avatar');
                fd.append('file', file);
                const res = await fetch('?api=image&action=upload', { method:'POST', body: fd });
                const data = await res.json();
                if(data.success){ this.myAvatarPath = data.path; this.showToast('Photo updated', 'ok'); }
                else this.showToast(data.error || 'Upload failed', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.avatarBusy = false;
        },
        async removeAvatar(){
            this.avatarBusy = true;
            try {
                const fd = new FormData(); fd.append('kind', 'avatar');
                const res = await fetch('?api=image&action=remove', { method:'POST', body: fd });
                const data = await res.json();
                if(data.success){ this.myAvatarPath = ''; this.showToast('Photo removed', 'ok'); }
                else this.showToast(data.error || 'Could not remove', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.avatarBusy = false;
        },
        // Unified profile settings (profile · password · security)
        async openProfile(){
            this.profileModal = { open:true, tab:'profile', display_name: this.currentUser.name || '', p1:'', p2:'' };
            // reset MFA sub-state and load current status
            this.mfaModal = { open:false, step:'off', secret:'', uri:'', code:'', codes:[], backupRemaining:0, confirmMode:'' };
            await this.refreshMfaStatus();
        },
        async refreshMfaStatus(){
            try {
                const res = await fetch('?api=auth&action=mfa_status');
                const data = await res.json();
                if(data.success){
                    this.mfaModal.step = data.enabled ? 'on' : 'off';
                    this.mfaModal.backupRemaining = data.backup_remaining || 0;
                    this.mfaModal.confirmMode = '';
                }
            } catch(e){ /* non-fatal */ }
        },
        async saveProfile(){
            const name = (this.profileModal.display_name || '').trim();
            try {
                const res = await fetch('?api=auth&action=update_profile', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ display_name: name })
                });
                const data = await res.json();
                if(data.success){
                    this.currentUser.name = name || this.currentUser.username;
                    this.showToast('Profile saved', 'ok');
                } else this.showToast(data.error || 'Could not save profile', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async saveProfilePassword(){
            if(this.profileModal.p1.length < 8){ this.showToast('Password must be at least 8 characters', 'err'); return; }
            if(this.profileModal.p1 !== this.profileModal.p2){ this.showToast('Passwords do not match', 'err'); return; }
            try {
                const res = await fetch('?api=auth&action=change_password', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ new_password: this.profileModal.p1 })
                });
                const data = await res.json();
                if(data.success){ this.profileModal.p1=''; this.profileModal.p2=''; this.showToast('Password updated', 'ok'); }
                else this.showToast(data.error || 'Could not update password', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async startMfaSetup(){
            try {
                const res = await fetch('?api=auth&action=mfa_setup', { method:'POST', headers:{'Content-Type':'application/json'}, body:'{}' });
                const data = await res.json();
                if(!data.success){ this.showToast(data.error || 'Could not start setup', 'err'); return; }
                this.mfaModal.secret = data.secret;
                this.mfaModal.uri = data.uri;
                this.mfaModal.code = '';
                this.mfaModal.step = 'setup';
                this.$nextTick(() => this.renderQr(data.uri));
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        renderQr(uri){
            const host = this.$refs.mfaQr;
            if(!host) return;
            host.innerHTML = '';
            try {
                // qrcode-generator API: qrcode(typeNumber, errorCorrectionLevel)
                const qr = qrcode(0, 'M');
                qr.addData(uri);
                qr.make();
                host.innerHTML = qr.createSvgTag({ cellSize: 5, margin: 0, scalable: true });
                const svg = host.querySelector('svg');
                if(svg){ svg.style.width = '180px'; svg.style.height = '180px'; }
            } catch(e){
                host.innerHTML = '<div style="font-size:12px;color:var(--text-muted)">Could not draw QR — use the key below.</div>';
            }
        },
        async confirmMfaEnable(){
            const code = (this.mfaModal.code||'').trim();
            if(code.length < 6){ this.showToast('Enter the 6-digit code', 'err'); return; }
            try {
                const res = await fetch('?api=auth&action=mfa_enable', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ code })
                });
                const data = await res.json();
                if(!data.success){ this.showToast(data.error || 'Could not enable', 'err'); return; }
                this.mfaModal.codes = data.backup_codes || [];
                this.mfaModal.step = 'codes';
                this.showToast('Two-factor enabled', 'ok');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async disableMfa(){
            const code = (this.mfaModal.code||'').trim();
            try {
                const res = await fetch('?api=auth&action=mfa_disable', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ code })
                });
                const data = await res.json();
                if(!data.success){ this.showToast(data.error || 'Could not disable', 'err'); return; }
                this.mfaModal.confirmMode = '';
                this.mfaModal.code = '';
                this.mfaModal.step = 'off';
                this.showToast('Two-factor disabled', 'ok');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async regenCodes(){
            const code = (this.mfaModal.code||'').trim();
            try {
                const res = await fetch('?api=auth&action=mfa_regen_codes', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ code })
                });
                const data = await res.json();
                if(!data.success){ this.showToast(data.error || 'Could not regenerate', 'err'); return; }
                this.mfaModal.confirmMode = '';
                this.mfaModal.code = '';
                this.mfaModal.codes = data.backup_codes || [];
                this.mfaModal.backupRemaining = this.mfaModal.codes.length;
                this.mfaModal.step = 'codes';
                this.showToast('New backup codes generated', 'ok');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async copyBackupCodes(){
            const text = (this.mfaModal.codes || []).join('\n');
            const ok = await this.copyToClipboard(text);
            this.showToast(ok ? 'Backup codes copied' : 'Select the codes and copy manually', ok ? 'ok' : 'err');
        },
        // admin: reset another user's MFA
        async adminResetMfa(u){
            if(!confirm('Reset two-factor for ' + (u.display_name || u.username) + '? They will sign in with just their password until they set it up again.')) return;
            try {
                const res = await fetch('?api=user&action=reset_mfa', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ public_id: u.public_id })
                });
                const data = await res.json();
                if(data.success){ await this.loadUsers(); this.showToast('Two-factor reset', 'ok'); }
                else this.showToast(data.error || 'Could not reset', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },

        // ====================================================
        // SYSTEM SETTINGS
        // ====================================================
        minutesLabel(m){
            m = parseInt(m) || 0;
            if(m < 60) return 'min';
            const h = m / 60;
            return (Number.isInteger(h) ? h : h.toFixed(1)) + (h === 1 ? ' hr' : ' hrs');
        },
        daysLabel(d){
            d = parseInt(d) || 0;
            if(d === 0) return 'forever';
            if(d % 30 === 0) return (d/30) + (d===30?' mo':' mos');
            return 'days';
        },
        applyLockoutChoice(){ this.settingsModal.vals.login_lockout_minutes = parseInt(this.lockoutChoice) || 15; },
        async unlockUser(u){
            if(!u || !u.public_id) return;
            try {
                const res = await fetch('?api=user&action=unlock', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ public_id: u.public_id })
                });
                const data = await res.json();
                if(data.success){ this.showToast('Unlocked ' + (u.display_name || u.username), 'ok'); this.loadUsers(); }
                else this.showToast(data.error || 'Could not unlock', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async openSettings(){
            this.view = 'settings';
            this.writeHash();
            this.settingsModal.open = true;
            this.settingsModal.showGen = false;
            this.applyLockoutChoice();
            // Building pool lives here now — make sure it's loaded for the list.
            if(!this.siteBuildings || !this.siteBuildings.length) this.loadBuildings();
            try {
                const res = await fetch('?api=setting&action=list');
                const data = await res.json();
                if(data.success){
                    const s = data.settings;
                    this.settingsModal.vals = {
                        session_timeout_minutes: parseInt(s.session_timeout_minutes) || 480,
                        session_warn_minutes: parseInt(s.session_warn_minutes) || 10,
                        audit_retention_days: parseInt(s.audit_retention_days) || 90,
                        login_max_attempts: (s.login_max_attempts !== undefined ? parseInt(s.login_max_attempts) : 5),
                        login_lockout_minutes: parseInt(s.login_lockout_minutes) || 15,
                        login_lockout_manual: (s.login_lockout_manual === '1') ? '1' : '0',
                        layer_cameras_enabled: (s.layer_cameras_enabled === '0') ? '0' : '1',
                        layer_printers_enabled: (s.layer_printers_enabled === '0') ? '0' : '1',
                        smtp_enabled: (s.smtp_enabled === '1') ? '1' : '0',
                        smtp_host: s.smtp_host || '',
                        smtp_port: parseInt(s.smtp_port) || 587,
                        smtp_user: s.smtp_user || '',
                        smtp_pass: s.smtp_pass || '',
                        smtp_security: s.smtp_security || 'tls',
                        smtp_from_email: s.smtp_from_email || '',
                        smtp_from_name: s.smtp_from_name || 'Site Manager',
                        email_cap_hourly: (s.email_cap_hourly !== undefined ? parseInt(s.email_cap_hourly) : 50),
                        email_cap_daily: (s.email_cap_daily !== undefined ? parseInt(s.email_cap_daily) : 200),
                        site_brand_name: s.site_brand_name || 'Site Manager',
                        room_type_colors: s.room_type_colors || '{}',
                        // Working map for the color grid: stored values over the
                        // built-in defaults, so every type shows a real swatch.
                        _rtc: (() => {
                            let stored = {};
                            try { stored = JSON.parse(s.room_type_colors || '{}') || {}; } catch(e){}
                            return Object.assign({}, this.roomTypeColors, stored);
                        })(),
                    };
                    this.siteLogoPath = s.site_logo_path || '';
                    if(!this.testEmailTo) this.testEmailTo = this.user?.email || '';
                    // map the stored minutes onto the dropdown (fallback to 15)
                    const opts = ['5','10','20','60','240','1440'];
                    this.lockoutChoice = opts.includes(String(this.settingsModal.vals.login_lockout_minutes)) ? String(this.settingsModal.vals.login_lockout_minutes) : '10';
                } else this.showToast(data.error || 'Could not load settings', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // ---- Branding: site logo upload/remove ----
        get brandLogoUrl(){
            // Cache-bust with the stored path so a new upload refreshes the preview.
            return this.siteLogoPath ? ('?api=image&action=serve&kind=logo&v=' + encodeURIComponent(this.siteLogoPath)) : '';
        },
        async uploadLogo(ev){
            const file = ev.target.files && ev.target.files[0];
            ev.target.value = '';
            if(!file) return;
            if(file.size > 5*1024*1024){ this.showToast('Image too large (max 5MB)', 'err'); return; }
            this.brandLogoBusy = true;
            try {
                const fd = new FormData();
                fd.append('kind', 'logo');
                fd.append('file', file);
                const res = await fetch('?api=image&action=upload', { method:'POST', body: fd });
                const data = await res.json();
                if(data.success){ this.siteLogoPath = data.path; this.showToast('Logo updated', 'ok'); }
                else this.showToast(data.error || 'Upload failed', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.brandLogoBusy = false;
        },
        async removeLogo(){
            this.brandLogoBusy = true;
            try {
                const fd = new FormData(); fd.append('kind', 'logo');
                const res = await fetch('?api=image&action=remove', { method:'POST', body: fd });
                const data = await res.json();
                if(data.success){ this.siteLogoPath = ''; this.showToast('Logo removed', 'ok'); }
                else this.showToast(data.error || 'Could not remove', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.brandLogoBusy = false;
        },
        async saveSettings(opts = {}){
            if(!this.can('settings','manage')) return false;
            const v = this.settingsModal.vals;
            if(v.session_warn_minutes >= v.session_timeout_minutes){
                this.showToast('Warning time must be less than the logout time', 'err'); return false;
            }
            try {
                const res = await fetch('?api=setting&action=save', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ settings: v })
                });
                const data = await res.json();
                if(data.success){
                    // adopt new session timing live
                    this.sessionTimeoutSec = (parseInt(data.settings.session_timeout_minutes)||480) * 60;
                    this.sessionWarnSec = (parseInt(data.settings.session_warn_minutes)||10) * 60;
                    // adopt the cameras-layer toggle live (shows/hides the All Sites entry)
                    if(data.settings.layer_cameras_enabled !== undefined){
                        this.cameraWallEnabled = data.settings.layer_cameras_enabled === '1';
                        if(!this.cameraWallEnabled && this.view === 'cameras') this.goHome();
                    }
                    if(data.settings.layer_printers_enabled !== undefined){
                        this.printersEnabled = data.settings.layer_printers_enabled === '1';
                        if(!this.printersEnabled) this.showPrinters = false;
                    }
                    // adopt room-type colors live — pins recolor immediately
                    if(data.settings.room_type_colors !== undefined){
                        try { this.roomTypeColors = JSON.parse(data.settings.room_type_colors) || {}; } catch(e){}
                    }
                    // refresh the masked password field so it shows dots, not the typed value
                    if(data.settings.smtp_pass !== undefined) this.settingsModal.vals.smtp_pass = data.settings.smtp_pass;
                    if(!opts.silent){
                        if(!opts.keepOpen) this.settingsModal.open = false;
                        this.showToast('Settings saved', 'ok');
                    }
                    return true;
                } else { this.showToast(data.error || 'Could not save settings', 'err'); return false; }
            } catch(e){ this.showToast('Network error', 'err'); return false; }
        },
        // For audit-managers without settings-manage: save ONLY the retention value.
        async saveRetentionOnly(){
            if(!this.can('audit','manage')) return;
            try {
                const res = await fetch('?api=setting&action=save', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ settings: { audit_retention_days: this.settingsModal.vals.audit_retention_days } })
                });
                const data = await res.json();
                if(data.success) this.showToast('Retention period saved', 'ok');
                else this.showToast(data.error || 'Could not save', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async sendTestEmail(){
            const to = (this.testEmailTo || '').trim();
            if(!to){ this.showToast('Enter an address to send the test to', 'err'); return; }
            this.emailTesting = true;
            // Save current settings first so the test reflects what's on screen.
            const saved = await this.saveSettings({ silent:true, keepOpen:true });
            if(!saved){ this.emailTesting = false; return; }
            try {
                const res = await fetch('?api=setting&action=test_email', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ to })
                });
                const data = await res.json();
                if(data.success) this.showToast('Test email sent to ' + to, 'ok');
                else this.showToast(data.error || 'Could not send test email', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.emailTesting = false;
        },

        // ====================================================
        // AUDIT LOG
        // ====================================================
        async openAudit(){
            this.view = 'audit';
            this.writeHash();
            this.auditModal.open = true;
            this.auditModal.q = '';
            this.auditModal.actionFilter = '';
            this.auditModal.page = 1;
            try {
                const k = await (await fetch('?api=audit&action=kinds')).json();
                if(k.success) this.auditModal.kinds = k.kinds;
            } catch(e){}
            this.loadAudit();
        },
        async loadAudit(){
            this.auditModal.loading = true;
            try {
                const params = new URLSearchParams({
                    action: 'list', page: this.auditModal.page,
                    q: this.auditModal.q || '', action_filter: this.auditModal.actionFilter || '',
                });
                const res = await fetch('?api=audit&' + params.toString());
                const data = await res.json();
                if(data.success){
                    this.auditModal.events = data.events;
                    this.auditModal.pages = data.pages;
                    this.auditModal.total = data.total;
                } else this.showToast(data.error || 'Could not load log', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.auditModal.loading = false;
        },
        auditLabel(action){
            const map = {
                'login':'Signed in', 'login.failed':'Failed sign-in', 'logout':'Signed out',
                'session.timeout':'Session timed out',
                'user.create':'Created user', 'user.update':'Updated user', 'user.delete':'Deleted user',
                'user.bulk':'Bulk user action', 'user.mfa_reset':'Reset two-factor',
                'settings.update':'Changed settings',
                'room.create':'Created room', 'room.update':'Updated room', 'room.delete':'Deleted room', 'room.import':'Imported rooms',
                'device.create':'Added device', 'device.update':'Updated device', 'device.delete':'Deleted device',
                'people.update':'Updated people',
            };
            return map[action] || action;
        },
        auditIcon(action){
            const k = action.split('.')[0];
            const I = {
                login:'<path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>',
                logout:'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
                session:'<circle cx="12" cy="12" r="9"/><polyline points="12 7 12 12 15 14"/>',
                user:'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                settings:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
                room:'<path d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-3"/>',
                device:'<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
                people:'<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/>',
            };
            const p = I[k] || '<circle cx="12" cy="12" r="9"/>';
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+p+'</svg>';
        },
        auditTime(s){
            if(!s) return '';
            const d = new Date((s||'').replace(' ','T'));
            if(isNaN(d)) return s;
            return d.toLocaleString(undefined, { month:'short', day:'numeric', hour:'numeric', minute:'2-digit' });
        },
        hasDetailKeys(d){ return d && typeof d === 'object' && Object.keys(d).length > 0; },
        formatAuditDetails(d){
            if(!d || typeof d !== 'object') return '';
            const parts = [];
            for(const [k,v] of Object.entries(d)){
                if(v && typeof v === 'object' && ('from' in v || 'to' in v)){
                    parts.push(k + ': ' + (v.from ?? '—') + ' → ' + (v.to ?? '—'));
                } else if(v && typeof v === 'object'){
                    parts.push(k + ': ' + JSON.stringify(v));
                } else {
                    parts.push(k + ': ' + v);
                }
            }
            return parts.join('  ·  ');
        },

        // ====================================================
        // SESSION TIMEOUT (idle warning + auto-logout)
        // ====================================================
        initSessionTimer(){
            if(this.sessionNeverExpire || !this.sessionTimeoutSec) return; // kiosk/service exempt
            // Poll the server periodically for the real remaining time (authoritative),
            // and tick a local countdown each second for a smooth display.
            const poll = async () => {
                try {
                    const res = await fetch('?api=auth&action=session_status');
                    const data = await res.json();
                    if(!data.success) return;
                    if(data.authenticated === false || data.expired){ this.forceLoggedOut(); return; }
                    if(data.never_expire){ this.sessionWarn.show = false; return; }
                    this.sessionWarn.secondsLeft = data.remaining;
                    // Adopt the CURRENT thresholds too, not just the countdown:
                    // an admin changing the timeout/warn settings should reshape
                    // every open session within one poll cycle — the server has
                    // been sending these fields all along; the client ignored
                    // them and kept the values baked in at page load.
                    if(data.warn_at) this.sessionWarnSec = data.warn_at;
                    if(data.timeout) this.sessionTimeoutSec = data.timeout;
                    this.evaluateWarn();
                } catch(e){ /* ignore transient */ }
            };
            // local 1s tick
            this.sessionWarn._tick = setInterval(() => {
                if(this.sessionWarn.secondsLeft > 0){
                    this.sessionWarn.secondsLeft--;
                    this.evaluateWarn();
                    if(this.sessionWarn.secondsLeft <= 0){ this.forceLoggedOut(); }
                }
            }, 1000);
            // server poll every 30s, and once now
            this.sessionWarn._poll = setInterval(poll, 30000);
            poll();
        },
        evaluateWarn(){
            const left = this.sessionWarn.secondsLeft;
            this.sessionWarn.show = (left > 0 && left <= this.sessionWarnSec);
            const m = Math.floor(left/60), s = left % 60;
            this.sessionWarn.countdownLabel = m > 0 ? (m + 'm ' + String(s).padStart(2,'0') + 's') : (s + 's');
        },
        async staySignedIn(){
            try { await fetch('?api=auth&action=session_keepalive', { method:'POST', headers:{'Content-Type':'application/json'}, body:'{}' }); } catch(e){}
            this.sessionWarn.show = false;
            this.sessionWarn.secondsLeft = this.sessionTimeoutSec;
        },
        doLogoutNow(){ this.logout(); },
        async forceLoggedOut(){
            if(this.sessionWarn._tick) clearInterval(this.sessionWarn._tick);
            if(this.sessionWarn._poll) clearInterval(this.sessionWarn._poll);
            // Explicitly end the session before reloading. A bare reload raced
            // the server's clock: client drift meant "zero" could land a moment
            // before the server's own expiry check — and that page load counted
            // as activity, refreshing the session instead of ending it.
            try {
                await fetch('?api=auth&action=logout', { method:'POST',
                    headers:{'Content-Type':'application/json'}, body:'{"reason":"timeout"}' });
            } catch(e){ /* offline — the server will expire it on its own */ }
            window.location = window.location.pathname;
        },

        // admin: manage users
        // ---- Permission admin (groups, grants, overrides) ----
        isAdminCap(layer){ return (this.permCatalog.admin_caps||[]).includes(layer); },
        levelsFor(layer){ return this.isAdminCap(layer) ? ['view','manage'] : ['view','edit','manage','admin']; },
        // Friendly display names so the grant editor reads in plain English.
        layerLabel(key){
            return ({
                base:'Sites & Rooms', cameras:'Cameras', printers:'Printers', devices:'Devices (TVs, carts…)',
                audit:'Audit log', settings:'System settings', manage_users:'Manage users',
                data_admin:'Data editor', notifications:'Email notifications',
            })[key] || key;
        },
        levelLabel(key){
            return ({
                view:'View — read only', edit:'Edit — change items', manage:'Manage — edit + delete', admin:'Admin — full control',
            })[key] || key;
        },
        // One-line plain-English description of a grant, e.g.
        // "Can edit Printers at Crescent Elementary."
        grantSummary(gr){
            const verb = ({view:'View',edit:'Edit',manage:'Manage (incl. delete)',admin:'Fully control'})[gr.level] || gr.level;
            const layer = this.layerLabel(gr.layer);
            if(this.isAdminCap(gr.layer)){
                return (gr.level==='manage'?'Can change ':'Can view ') + layer + '.';
            }
            let where = 'all sites';
            if(gr.scope_type==='site'){
                const s = this.siteById(gr.scope_id);
                where = s ? s.name : 'a specific site';
            } else if(gr.scope_type==='device'){
                const n = (gr.scope_ids||[]).length;
                const siteName = this.siteById(gr._pickSite)?.name;
                if(n === 0) return verb + ' ' + layer + ' — pick cameras…';
                where = n + ' camera' + (n===1?'':'s') + (siteName ? ' at ' + siteName : '');
            }
            return verb + ' ' + layer + ' at ' + where + '.';
        },
        addGrant(arr){ arr.push({ layer:'base', level:'view', scope_type:'all', scope_id:null, _pickSite:null, scope_ids:[] }); },
        // Toggle a camera in a per-camera grant's selection.
        toggleCamScope(gr, camId){
            if(!Array.isArray(gr.scope_ids)) gr.scope_ids = [];
            const i = gr.scope_ids.indexOf(camId);
            if(i >= 0) gr.scope_ids.splice(i, 1); else gr.scope_ids.push(camId);
        },
        // Expand editor grants into storable grants: a per-camera row with
        // scope_ids becomes one device grant per selected camera. Everything
        // else passes through unchanged.
        _expandGrants(rows){
            const out = [];
            (rows||[]).forEach(g => {
                if(g.scope_type === 'device' && g.layer === 'cameras'){
                    (g.scope_ids||[]).forEach(cid => out.push({ layer:'cameras', level:g.level, scope_type:'device', scope_id:cid }));
                } else {
                    out.push({ layer:g.layer, level:g.level, scope_type:g.scope_type, scope_id:g.scope_id });
                }
            });
            return out;
        },
        // Collapse stored grants into editor rows: consecutive per-camera grants
        // for the same site+level fold into one checklist row with scope_ids.
        _collapseGrants(rows){
            const out = []; const camGroups = {};
            (rows||[]).map(x=>({ layer:x.layer, level:x.level, scope_type:x.scope_type, scope_id:x.scope_id!==null?Number(x.scope_id):null }))
              .forEach(g => {
                if(g.scope_type === 'device' && g.layer === 'cameras'){
                    const site = this._siteOfCamera('device', g.scope_id);
                    const key = (site||'?') + '|' + g.level;
                    if(!camGroups[key]){ camGroups[key] = { layer:'cameras', level:g.level, scope_type:'device', scope_id:null, _pickSite:site, scope_ids:[] }; out.push(camGroups[key]); }
                    if(g.scope_id !== null) camGroups[key].scope_ids.push(g.scope_id);
                } else {
                    out.push({ ...g, _pickSite: null, scope_ids: [] });
                }
            });
            return out;
        },
        async loadPermCatalog(){
            if(this.permCatalog.data_layers.length) return;
            try { const r = await fetch('?api=perm&action=catalog'); const d = await r.json(); if(d.success){ this.permCatalog = { data_layers:d.data_layers, admin_caps:d.admin_caps, levels:d.levels }; } } catch(e){}
        },
        async loadGroups(){
            await this.loadPermCatalog();
            try { const r = await fetch('?api=perm&action=groups'); const d = await r.json(); if(d.success) this.permGroups = d.groups; else this.showToast(d.error||'Could not load groups','err'); }
            catch(e){ this.showToast('Network error','err'); }
        },
        newGroup(){ this.groupEdit = { open:true, group_id:0, name:'', description:'', is_system:0, grants:[], members:[] }; },
        async openGroup(g){
            this.groupEdit = { open:true, group_id:g.group_id, name:g.name, description:g.description||'', is_system:g.is_system, grants:[], members:[] };
            try {
                const r = await fetch('?api=perm&action=group_detail&group_id='+g.group_id); const d = await r.json();
                if(d.success){
                    this.groupEdit.grants = this._collapseGrants(d.grants);
                    this.groupEdit.members = d.members||[];
                }
            } catch(e){}
        },
        async saveGroup(){
            if(!this.groupEdit.name.trim()){ this.showToast('Group name required','err'); return; }
            try {
                const r = await fetch('?api=perm&action=group_save', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ group_id:this.groupEdit.group_id, name:this.groupEdit.name, description:this.groupEdit.description }) });
                const d = await r.json();
                if(!d.success){ this.showToast(d.error||'Could not save group','err'); return; }
                const gid = d.group_id;
                const gr = await fetch('?api=perm&action=group_set_grants', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ group_id:gid, grants:this._expandGrants(this.groupEdit.grants) }) });
                const gd = await gr.json();
                if(!gd.success){ this.showToast(gd.error||'Saved group, but grants failed','err'); }
                else this.showToast('Group saved','ok');
                this.groupEdit.group_id = gid;
                await this.loadGroups();
            } catch(e){ this.showToast('Network error','err'); }
        },
        async deleteGroup(){
            if(!this.groupEdit.group_id) return;
            if(!confirm('Delete group "'+this.groupEdit.name+'"? Members will lose its grants.')) return;
            try {
                const r = await fetch('?api=perm&action=group_delete', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ group_id:this.groupEdit.group_id }) });
                const d = await r.json();
                if(d.success){ this.groupEdit.open=false; await this.loadGroups(); this.showToast('Group deleted','ok'); }
                else this.showToast(d.error||'Could not delete','err');
            } catch(e){ this.showToast('Network error','err'); }
        },
        toggleUserGroup(gid){
            const i = this.userForm.group_ids.indexOf(gid);
            if(i>=0) this.userForm.group_ids.splice(i,1); else this.userForm.group_ids.push(gid);
        },
        async openUsers(){
            this.view = 'users';
            this.writeHash();
            this.usersModal.open = true;
            this.usersModal.tab = 'users';
            this.userForm.open = false;
            this.usersModal.selected = [];
            this.usersModal.search = '';
            this.usersModal.roleFilter = 'all';
            this.usersModal.statusFilter = 'all';
            await this.loadUsers();
            await this.loadGroups();
        },
        async loadUsers(){
            try {
                const res = await fetch('?api=user&action=list');
                const data = await res.json();
                if(data.success){
                    this.usersModal.users = data.users;
                    // Diagnostic: the exact identity data every row will render
                    // from, straight from the wire. If this table is right and a
                    // row still displays the wrong face, the divergence is in the
                    // HTTP responses (Network tab) — nowhere else left.
                    try { console.table((data.users||[]).map(u => ({ user: u.username, public_id: (u.public_id||'').slice(0,10), profile_image: u.profile_image||'' }))); } catch(e){}
                    // prune any selected ids that no longer exist
                    const ids = new Set(data.users.map(u => u.public_id));
                    this.usersModal.selected = this.usersModal.selected.filter(id => ids.has(id));
                } else this.showToast(data.error || 'Could not load users', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // Friendly relative time for last login / created.
        relativeTime(s){
            if(!s) return 'Never';
            const d = new Date((s||'').replace(' ','T'));
            if(isNaN(d)) return '—';
            const diff = (Date.now() - d.getTime())/1000;
            if(diff < 60) return 'Just now';
            if(diff < 3600) return Math.floor(diff/60) + 'm ago';
            if(diff < 86400) return Math.floor(diff/3600) + 'h ago';
            if(diff < 2592000) return Math.floor(diff/86400) + 'd ago';
            const mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
            const now = new Date();
            return mon + ' ' + d.getDate() + (d.getFullYear()!==now.getFullYear() ? " '" + String(d.getFullYear()).slice(2) : '');
        },
        // ---- selection (keyed on public_id) ----
        isSelected(id){ return this.usersModal.selected.includes(id); },
        toggleSelect(id){
            if(id === this.currentUserId) return; // can't bulk-act on self
            const i = this.usersModal.selected.indexOf(id);
            if(i >= 0) this.usersModal.selected.splice(i, 1);
            else this.usersModal.selected.push(id);
        },
        toggleSelectAll(){
            if(this.allVisibleSelected){
                this.usersModal.selected = [];
            } else {
                this.usersModal.selected = this.filteredUsers
                    .filter(u => u.public_id !== this.currentUserId)
                    .map(u => u.public_id);
            }
        },
        clearSelection(){ this.usersModal.selected = []; },
        // Add every selected user to a group, keeping their existing memberships.
        async bulkAddToGroup(groupId){
            const ids = [...this.usersModal.selected];
            if(!ids.length) return;
            const grp = this.permGroups.find(g => g.group_id === groupId);
            const grpName = grp ? grp.name : 'group';
            let added = 0, skipped = 0, failed = 0;
            for(const pid of ids){
                const u = this.usersModal.users.find(x => x.public_id === pid);
                if(!u){ failed++; continue; }
                const current = (u.groups || []).map(g => g.group_id);
                if(current.includes(groupId)){ skipped++; continue; }   // already in it
                const next = [...current, groupId];
                try {
                    const res = await fetch('?api=perm&action=user_set_groups', {
                        method:'POST', headers:{'Content-Type':'application/json'},
                        body: JSON.stringify({ public_id: pid, group_ids: next })
                    });
                    const data = await res.json();
                    if(data.success) added++; else failed++;
                } catch(e){ failed++; }
            }
            await this.loadUsers();
            await this.loadGroups();
            let msg = 'Added ' + added + ' to “' + grpName + '”';
            if(skipped) msg += ' · ' + skipped + ' already in it';
            if(failed) msg += ' · ' + failed + ' failed';
            this.showToast(msg, failed ? 'err' : 'ok');
        },
        async bulkAction(op){
            const n = this.usersModal.selected.length;
            if(!n) return;
            const verb = op === 'delete' ? 'Delete' : (op === 'activate' ? 'Activate' : 'Disable');
            if(op === 'delete' && !confirm('Delete ' + n + ' user' + (n>1?'s':'') + '? This cannot be undone.')) return;
            try {
                const res = await fetch('?api=user&action=bulk', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ op, public_ids: this.usersModal.selected })
                });
                const data = await res.json();
                if(data.success){
                    this.usersModal.selected = [];
                    await this.loadUsers();
                    this.showToast(verb + 'd ' + data.affected + ' user' + (data.affected>1?'s':''), 'ok');
                } else this.showToast(data.error || 'Bulk action failed', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // Quick inline enable/disable from a row, without opening the editor.
        async quickToggleActive(u){
            if(u.public_id === this.currentUserId){ this.showToast("You can't disable your own account", 'err'); return; }
            try {
                const res = await fetch('?api=user&action=save', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({
                        public_id: u.public_id, username: u.username, display_name: u.display_name || '',
                        role: u.role, is_active: !u.is_active, password: '',
                        never_expire: !!u.never_expire,
                        sites: u.role === 'admin' ? [] : (u.sites || []),
                    })
                });
                const data = await res.json();
                if(data.success){ await this.loadUsers(); this.showToast(u.is_active ? 'User disabled' : 'User activated', 'ok'); }
                else this.showToast(data.error || 'Could not update user', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        newUser(){
            this.usersModal.siteSearch = '';
            this.userForm = { open:true, mode:'password', public_id:'', username:'', display_name:'', email:'', role:'viewer', password:'', is_active:true, never_expire:false, sites:[], cameraAccess:{}, group_ids:[], overrides:[] };
            if(!this.permGroups || !this.permGroups.length) this.loadGroups();
        },
        openInvite(){
            // Invite is just the new-user form in "invite" mode.
            this.usersModal.siteSearch = '';
            this.userForm = { open:true, mode:'invite', public_id:'', username:'', display_name:'', email:'', role:'viewer', password:'', is_active:true, never_expire:false, sites:[], cameraAccess:{}, group_ids:[], overrides:[] };
            if(!this.permGroups || !this.permGroups.length) this.loadGroups();
        },
        // Back arrow / Cancel — return to the list without closing the whole modal.
        closeUserSubview(){
            this.userForm.open = false;
            this.groupEdit.open = false;
            this.usersModal.tab = 'users';
        },
        // Send an invite using the unified form's fields.
        async sendInviteFromForm(){
            const f = this.userForm;
            if(!f.username.trim()){ this.showToast('Enter a username', 'err'); return; }
            if(!f.email.trim()){ this.showToast('Enter an email address', 'err'); return; }
            this.inviteModal.sending = true;
            try {
                const res = await fetch('?api=user&action=invite', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ username:f.username.trim(), email:f.email.trim(), display_name:f.display_name.trim(), group_ids:f.group_ids })
                });
                const data = await res.json();
                if(data.success){
                    await this.loadUsers();
                    this.closeUserSubview();
                    if(data.email_sent === false) this.showToast('Account created, but the invite email failed: ' + (data.email_error || 'unknown'), 'err');
                    else this.showToast('Invitation sent to ' + f.email.trim(), 'ok');
                } else this.showToast(data.error || 'Could not create invite', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            this.inviteModal.sending = false;
        },
        toggleInviteGroup(gid){
            const i = this.inviteModal.group_ids.indexOf(gid);
            if(i >= 0) this.inviteModal.group_ids.splice(i, 1); else this.inviteModal.group_ids.push(gid);
        },
        async resendInvite(u){
            try {
                const res = await fetch('?api=user&action=resend_invite', {
                    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ public_id:u.public_id })
                });
                const data = await res.json();
                if(data.success) this.showToast('Invite resent to ' + (u.display_name || u.username), 'ok');
                else this.showToast(data.error || 'Could not resend', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async revokeInvite(u){
            if(!confirm('Revoke the invitation for ' + (u.display_name || u.username) + '? This deletes the pending account.')) return;
            try {
                const res = await fetch('?api=user&action=revoke_invite', {
                    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ public_id:u.public_id })
                });
                const data = await res.json();
                if(data.success){ await this.loadUsers(); this.showToast('Invitation revoked', 'ok'); }
                else this.showToast(data.error || 'Could not revoke', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // The live list row for the user being edited — the photo actions
        // target THIS object so the list updates in place after a change.
        get editingUserRow(){
            if(!this.userForm.public_id) return null;
            return (this.usersModal.users || []).find(u => u.public_id === this.userForm.public_id) || null;
        },
        editUser(u){
            this.usersModal.siteSearch = '';
            // deep-copy camera access so edits don't mutate the list copy
            const ca = {};
            const src = u.cameraAccess || {};
            Object.keys(src).forEach(k => {
                const r = src[k] || {};
                ca[k] = {
                    obj:  Array.isArray(r.obj)  ? [...r.obj]  : (r.obj  || 'none'),
                    feed: Array.isArray(r.feed) ? [...r.feed] : (r.feed || 'none'),
                };
            });
            this.userForm = {
                open:true, mode:'password', public_id:u.public_id, username:u.username,
                display_name:u.display_name || '', email:u.email || '', role:u.role, password:'',
                is_active: !!u.is_active, never_expire: !!u.never_expire, sites:[...(u.sites||[])],
                cameraAccess: ca,
                group_ids: (u.groups||[]).map(g => g.group_id),
                overrides: [],
            };
            if(!this.permGroups || !this.permGroups.length) this.loadGroups();
            // Load this user's personal override grants.
            fetch('?api=perm&action=user_grants&public_id='+encodeURIComponent(u.public_id))
                .then(r=>r.json()).then(d=>{
                    if(d.success) this.userForm.overrides = this._collapseGrants(d.grants);
                }).catch(()=>{});
        },
        toggleUserSite(id){
            const i = this.userForm.sites.indexOf(id);
            if(i >= 0) this.userForm.sites.splice(i, 1);
            else this.userForm.sites.push(id);
        },
        selectAllSites(){ this.userForm.sites = this.sites.map(s => s.id); },
        clearAllSites(){ this.userForm.sites = []; },

        // ---- Camera permissions (per-site obj/feed: all | none | [camera_numbers]) ----
        // Cameras available at a given site (from the admin bootstrap list).
        // Natural compare: "B1-2" sorts before "B1-10" (numbers compared as numbers).
        _natCmp(a, b){
            return String(a ?? '').localeCompare(String(b ?? ''), undefined, { numeric:true, sensitivity:'base' });
        },
        camerasForSite(siteId){
            return (this.camerasAdmin || []).filter(c => c.site_number === siteId)
                .slice().sort((a,b) => this._natCmp(a.camera_name, b.camera_name));
        },
        // For a device-scoped (per-camera) grant, find which site that camera is at
        // so the editor's site filter pre-selects correctly.
        _siteOfCamera(scopeType, scopeId){
            if(scopeType !== 'device' || scopeId === null) return null;
            const c = (this.camerasAdmin||[]).find(x => Number(x.camera_number) === Number(scopeId));
            return c ? c.site_number : null;
        },
        // Ensure a site has a rule object; default obj/feed = 'none'.
        _ensureCamRule(siteId){
            if(!this.userForm.cameraAccess[siteId]){
                this.userForm.cameraAccess[siteId] = { obj:'none', feed:'none' };
            }
            return this.userForm.cameraAccess[siteId];
        },
        camObjMode(siteId){
            const r = this.userForm.cameraAccess[siteId];
            if(!r) return 'none';
            return Array.isArray(r.obj) ? 'some' : r.obj;   // 'all' | 'none' | 'some'
        },
        camFeedMode(siteId){
            const r = this.userForm.cameraAccess[siteId];
            if(!r) return 'none';
            return Array.isArray(r.feed) ? 'some' : r.feed;
        },
        // Set the OBJECT mode for a site. Enforces feed ⊆ obj.
        setCamObjMode(siteId, mode){
            const r = this._ensureCamRule(siteId);
            if(mode === 'all') r.obj = 'all';
            else if(mode === 'none') r.obj = 'none';
            else r.obj = Array.isArray(r.obj) ? r.obj : []; // 'some'
            // constrain feed to not exceed obj
            if(r.obj === 'none'){ r.feed = 'none'; }
            else if(Array.isArray(r.obj)){
                if(r.feed === 'all'){ r.feed = [...r.obj]; }
                else if(Array.isArray(r.feed)){ r.feed = r.feed.filter(id => r.obj.includes(id)); }
            }
            this.userForm.cameraAccess[siteId] = { ...r };
        },
        setCamFeedMode(siteId, mode){
            const r = this._ensureCamRule(siteId);
            if(mode === 'all'){
                // "all feeds" can't exceed visible objects
                if(r.obj === 'all') r.feed = 'all';
                else if(Array.isArray(r.obj)) r.feed = [...r.obj];
                else { r.feed = 'none'; this.showToast('Give object access first', 'err'); }
            } else if(mode === 'none'){ r.feed = 'none'; }
            else {
                r.feed = Array.isArray(r.feed) ? r.feed : [];
                // if obj is 'none', granting specific feeds makes no sense — bump obj to match
                if(r.obj === 'none') r.obj = [];
            }
            this.userForm.cameraAccess[siteId] = { ...r };
        },
        // Toggle a single camera in the obj or feed list. Keeps feed ⊆ obj.
        toggleCam(siteId, camNum, which){
            const r = this._ensureCamRule(siteId);
            camNum = String(camNum);
            if(which === 'obj'){
                if(!Array.isArray(r.obj)) r.obj = [];
                const i = r.obj.indexOf(camNum);
                if(i >= 0){
                    r.obj.splice(i, 1);
                    // removing object access also removes feed access for that cam
                    if(Array.isArray(r.feed)){ const j = r.feed.indexOf(camNum); if(j >= 0) r.feed.splice(j, 1); }
                } else r.obj.push(camNum);
            } else { // feed
                if(!Array.isArray(r.feed)) r.feed = [];
                const i = r.feed.indexOf(camNum);
                if(i >= 0) r.feed.splice(i, 1);
                else {
                    r.feed.push(camNum);
                    // granting a feed implies object access
                    if(Array.isArray(r.obj)){ if(!r.obj.includes(camNum)) r.obj.push(camNum); }
                    else if(r.obj === 'none'){ r.obj = [camNum]; }
                }
            }
            this.userForm.cameraAccess[siteId] = { ...r };
        },
        camInList(siteId, camNum, which){
            const r = this.userForm.cameraAccess[siteId];
            if(!r) return false;
            const list = r[which];
            if(list === 'all') return true;
            return Array.isArray(list) && list.includes(String(camNum));
        },
        // Build the camera-access payload, keeping only sites the user actually has.
        _cameraAccessForSites(f){
            const out = {};
            (f.sites || []).forEach(sid => {
                const r = f.cameraAccess[sid];
                if(r && (r.obj !== 'none' || r.feed !== 'none')) out[sid] = r;
            });
            return out;
        },
        // Short summary string for a site's camera rule (for the collapsed view).
        camRuleSummary(siteId){
            const om = this.camObjMode(siteId), fm = this.camFeedMode(siteId);
            const objTxt = om === 'all' ? 'all objects' : om === 'some' ? ((this.userForm.cameraAccess[siteId].obj||[]).length + ' objects') : 'no objects';
            const feedTxt = fm === 'all' ? 'all feeds' : fm === 'some' ? ((this.userForm.cameraAccess[siteId].feed||[]).length + ' feeds') : 'no feeds';
            return objTxt + ' · ' + feedTxt;
        },
        async saveUser(){
            const f = this.userForm;
            if(!f.username.trim()){ this.showToast('Username is required', 'err'); return; }
            if(!f.public_id && f.password.length < 8){ this.showToast('New users need a password (8+ characters)', 'err'); return; }
            try {
                const res = await fetch('?api=user&action=save', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({
                        public_id: f.public_id || undefined, username: f.username.trim(),
                        display_name: f.display_name.trim(), email: (f.email||'').trim(), role: f.role,
                        is_active: f.is_active, password: f.password,
                        never_expire: f.never_expire,
                        sites: f.role === 'admin' ? [] : f.sites,
                        cameraAccess: f.role === 'admin' ? {} : this._cameraAccessForSites(f),
                    })
                });
                const data = await res.json();
                if(data.success){
                    // Persist group memberships + override grants (groups replace roles).
                    const pubId = data.public_id || f.public_id;
                    if(pubId){
                        try {
                            await fetch('?api=perm&action=user_set_groups', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ public_id:pubId, group_ids:f.group_ids }) });
                            await fetch('?api=perm&action=user_set_grants', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({ public_id:pubId, grants:this._expandGrants(f.overrides) }) });
                        } catch(e){ /* non-fatal; user saved */ }
                    }
                    await this.loadUsers(); this.closeUserSubview(); this.showToast('User saved', 'ok');
                }
                else this.showToast(data.error || 'Could not save user', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async deleteUser(){
            if(!this.userForm.public_id) return;
            if(!confirm('Delete this user? This cannot be undone.')) return;
            try {
                const res = await fetch('?api=user&action=delete', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ public_id: this.userForm.public_id })
                });
                const data = await res.json();
                if(data.success){ await this.loadUsers(); this.closeUserSubview(); this.showToast('User deleted', 'ok'); }
                else this.showToast(data.error || 'Could not delete user', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },

        // ====================================================
        // THEME
        // ====================================================
        toggleTheme(){
            this.theme = this.theme === 'dark' ? 'light' : 'dark';
            document.documentElement.setAttribute('data-theme', this.theme);
            try { localStorage.setItem('sm_theme', this.theme); } catch(e) {}
        },
        // UI-side permission check (server still enforces). Mirrors the ladder.
        can(layer, level){
            const rank = { none:0, view:1, edit:2, manage:3, admin:4 };
            const have = (this.perms && this.perms[layer]) ? this.perms[layer] : 'none';
            return (rank[have]||0) >= (rank[level]||0);
        },

        // ====================================================
        // MAP / SVG
        // ====================================================
        loadSvgForCurrentSite(){
            const site = this.currentSite;
            if(!site || !site.has_map){
                this.mapSvgMarkup = '';
                this.mapSvgLoadedForSite = null;
                this.mapSvgLoading = false;
                return;
            }
            // Which map within the site is showing? selectedLevel is the map key.
            const mapKey = this.selectedLevel || 'level-1';
            const token = site.id + '::' + mapKey;
            // Already showing this exact map: clear the loading flag (a fast
            // switch may have set it true) so pins aren't left hidden, and
            // re-apply the map's zoom/start view. That last part was missing —
            // the comment claimed it, the code didn't — so coming back from a
            // room to an already-loaded map kept whatever scroll position was
            // left behind. Whether it worked depended on whether the SVG was
            // still loaded, which is exactly why it looked random.
            if(this.mapSvgLoadedForSite === token){
                this.mapSvgLoading = false;
                this._applyZoomForCurrentMap();
                return;
            }
            // If this map has no SVG of its own, clear the canvas (rooms still list).
            const mapDef = (site.maps || []).find(m => m.key === mapKey);
            if(site.maps && site.maps.length && mapDef && !mapDef.has_svg){
                this.mapSvgMarkup = '';
                this.mapSvgLoadedForSite = token;
                this.mapSvgLoading = false;
                return;
            }
            // PERF (Option A): serve from the in-memory cache if we've already
            // processed this exact map this session — no fetch, no parse, no
            // sanitize. Revisiting a map you saw earlier is then instant.
            if(!this._svgCache) this._svgCache = new Map();
            const cached = this._svgCache.get(token);
            if(cached){
                this.mapBaseW = cached.w; this.mapBaseH = cached.h;
                this.mapSvgMarkup = cached.markup;
                this.mapSvgLoadedForSite = token;
                this.mapSvgLoading = false;
                this._applyZoomForCurrentMap();
                return;
            }
            this.mapSvgMarkup = '';
            this.mapSvgLoading = true;
            // Capture the token this fetch is FOR. If the user switches site/map
            // before it resolves, the token will no longer match and we discard the
            // late result — otherwise a slow fetch for the previous site could
            // overwrite the new site's map (and leave stale pins on screen).
            const fetchToken = token;
            fetch('?api=map&action=svg&site=' + site.id + '&map=' + encodeURIComponent(mapKey))
                .then(r => r.ok ? r.text() : '')
                .then(text => {
                    // Bail if we've navigated away since this fetch started.
                    if(this._currentMapToken() !== fetchToken) return;
                    if(!text){ this.mapSvgLoading = false; this.mapSvgLoadedForSite = fetchToken; return; }
                    const markup = this._processSvgText(text);
                    // Cache the finished markup (cap at 6 maps; drop the oldest).
                    this._svgCache.set(fetchToken, { markup, w: this.mapBaseW, h: this.mapBaseH });
                    if(this._svgCache.size > 6){
                        const oldest = this._svgCache.keys().next().value;
                        this._svgCache.delete(oldest);
                    }
                    // Re-check after the (sync) processing too, for safety.
                    if(this._currentMapToken() !== fetchToken) return;
                    this.mapSvgMarkup = markup;
                    this.mapSvgLoadedForSite = fetchToken;
                    this.mapSvgLoading = false;
                    this._applyZoomForCurrentMap();
                })
                .catch(() => { if(this._currentMapToken() === fetchToken) this.mapSvgLoading = false; });
        },
        // The token (site::map) the app SHOULD currently be showing. Used to discard
        // stale async SVG fetches after a fast site/map switch.
        _currentMapToken(){
            const site = this.currentSite;
            if(!site) return null;
            return site.id + '::' + (this.selectedLevel || 'level-1');
        },
        // Turn raw SVG text into ready-to-inject markup, reading the viewBox for
        // the canvas aspect ratio. Files stamped <!--SM-SANITIZED--> at upload skip
        // the heavy DOMParser walk (Option B) — we just regex out the viewBox and
        // strip width/height, avoiding a full second parse of a multi-MB document.
        _processSvgText(text){
            const trusted = text.indexOf('<!--SM-SANITIZED-->') !== -1;
            // Read viewBox / dimensions cheaply via regex.
            const vbM = text.match(/viewBox\s*=\s*["']\s*([\d.eE+\-]+)\s+([\d.eE+\-]+)\s+([\d.eE+\-]+)\s+([\d.eE+\-]+)\s*["']/i);
            if(vbM){
                const w = parseFloat(vbM[3]), h = parseFloat(vbM[4]);
                if(w>0 && h>0){ this.mapBaseW = w; this.mapBaseH = h; }
            } else {
                const wM = text.match(/\bwidth\s*=\s*["']\s*([\d.]+)/i);
                const hM = text.match(/\bheight\s*=\s*["']\s*([\d.]+)/i);
                if(wM && hM){ const w=parseFloat(wM[1]), h=parseFloat(hM[1]); if(w>0&&h>0){ this.mapBaseW=w; this.mapBaseH=h; } }
            }
            if(trusted){
                // Already clean from the server: just strip the leading marker and
                // any root width/height so CSS controls sizing. No DOM parse needed.
                let s = text.replace('<!--SM-SANITIZED-->', '').trim();
                // Remove width/height only on the FIRST <svg ...> tag.
                s = s.replace(/<svg\b[^>]*>/i, (tag) =>
                    tag.replace(/\swidth\s*=\s*["'][^"']*["']/i, '')
                       .replace(/\sheight\s*=\s*["'][^"']*["']/i, ''));
                return s;
            }
            // Legacy / untrusted file (uploaded before server sanitizing): do the
            // full safe parse + scrub. This path is the slow one, but only old
            // maps hit it — and once re-uploaded they become trusted.
            const parser = new DOMParser();
            const doc = parser.parseFromString(text, 'image/svg+xml');
            const svg = doc.querySelector('svg');
            if(!svg) return '';
            svg.removeAttribute('width');
            svg.removeAttribute('height');
            svg.querySelectorAll('script, foreignObject').forEach(el => el.remove());
            for(const el of svg.querySelectorAll('*')){
                for(const attr of [...el.attributes]){
                    const n = attr.name.toLowerCase(), v = (attr.value || '').trim().toLowerCase();
                    if(n.startsWith('on')) el.removeAttribute(attr.name);
                    else if((n === 'href' || n === 'xlink:href') && (v.startsWith('javascript:') || v.startsWith('data:text'))) el.removeAttribute(attr.name);
                }
            }
            return svg.outerHTML;
        },
        // Apply a zoom synchronously, keeping the viewport-relative point (ox,oy)
        // anchored under itself: size, transform, and scroll all land in one
        // frame. Both the +/- buttons and the wheel route through this — the
        // buttons previously just set state and waited for a full reactive
        // re-render, which is what made stepped zooming feel laggy (and made it
        // resize from the top-left corner instead of what you were looking at).
        _applyZoomAt(newZoom, ox, oy){
            const vp = this.$refs.viewport, sizer = this.$refs.sizer, canvas = this.$refs.canvas;
            newZoom = Math.max(this.ZOOM_MIN, Math.min(this.ZOOM_MAX, newZoom));
            if(!vp || !sizer || !canvas){ this.mapZoom = newZoom; return; }
            const oldZoom = this.mapZoom;
            if(newZoom === oldZoom) return;
            // Anchor in CONTENT coordinates. The workspace margin around the map
            // is constant (it doesn't scale with zoom), so the point under the
            // cursor must be measured from the content's origin — raw
            // scroll-space math would drift by margin x (ratio - 1) every step.
            const mox = sizer.offsetLeft || 0, moy = sizer.offsetTop || 0;
            const px = vp.scrollLeft + ox - mox, py = vp.scrollTop + oy - moy;
            const ratio = newZoom / oldZoom;
            sizer.style.width  = (this.mapBaseW * newZoom) + 'px';
            sizer.style.height = (this.mapBaseH * newZoom) + 'px';
            canvas.style.transform = 'scale(' + newZoom + ')';
            // One variable drives every pin's counter-scale (see _syncPinScale).
            canvas.style.setProperty('--pin-scale', String(1 / newZoom));
            vp.scrollLeft = mox + px * ratio - ox;
            vp.scrollTop  = moy + py * ratio - oy;
            this.mapZoom = newZoom;
        },
        zoom(delta){
            const factor = delta > 0 ? 1.25 : 0.8;
            const vp = this.$refs.viewport;
            this._focusTarget = null;   // the user is driving now
            this._applyZoomAt(this.mapZoom * factor,
                vp ? vp.clientWidth / 2 : 0, vp ? vp.clientHeight / 2 : 0);
        },
        onWheelZoom(e){
            e.preventDefault();
            this._focusTarget = null;   // manual zoom takes over from the saved start view
            const vp = this.$refs.viewport;
            const sizer = this.$refs.sizer;
            const canvas = this.$refs.canvas;
            if(!vp || !sizer || !canvas) return;
            // Capture cursor + accumulate delta synchronously, apply once per frame
            const rect = vp.getBoundingClientRect();
            this._wheelOX = e.clientX - rect.left;
            this._wheelOY = e.clientY - rect.top;
            this._wheelDelta = (this._wheelDelta || 0) + e.deltaY;
            if(this._wheelRAF) return;
            this._wheelRAF = requestAnimationFrame(() => {
                this._wheelRAF = null;
                const factor = Math.exp(-this._wheelDelta * 0.0015);
                this._wheelDelta = 0;
                this._applyZoomAt(this.mapZoom * factor, this._wheelOX, this._wheelOY);
            });
        },
        // Align the viewport with the map's top-left (small breathing inset).
        // With the workspace margin, scroll position 0,0 is blank space — every
        // load path without a saved start view lands here instead.
        _scrollToOrigin(tries){
            const vp = this.$refs.viewport, sizer = this.$refs.sizer;
            if(!vp || !sizer){
                if(tries > 0) requestAnimationFrame(() => this._scrollToOrigin(tries - 1));
                return;
            }
            vp.scrollLeft = Math.max(0, (sizer.offsetLeft || 0) - 16);
            vp.scrollTop  = Math.max(0, (sizer.offsetTop  || 0) - 16);
        },
        // Reset = "put it back how this map opens". If the map has a saved start
        // view, that IS its home position, so Reset restores it rather than
        // dropping you at an unrelated auto-fit zoom (which is what it always
        // did — the function predates start views and never got updated).
        zoomReset(){
            const m = this.currentMapObj;
            if(m && m.focus_x != null && m.focus_y != null){
                this._applyZoomForCurrentMap();
                return;
            }
            this.zoomFit();
        },
        // Plain auto-fit: the whole plan inside the viewport.
        zoomFit(){
            const vp = this.$refs.viewport;
            if(!vp){ this.mapZoom = 1.0; return; }
            const w = vp.clientWidth  - 24;
            const h = vp.clientHeight - 24;
            if(w<=0 || h<=0){ this.mapZoom = 1.0; return; }
            const z = Math.min(w / this.mapBaseW, h / this.mapBaseH, 2);
            this.mapZoom = Math.max(0.4, z);
        },
        refreshMapLayout(){ this.$nextTick(() => this.zoomFit()); },

        // The currently-displayed map object (from currentSite.maps), if any.
        // Zoom below which pins collapse to dots. A fixed number can't fit every
        // map (a tiny plan at 800% never crosses it; on small maps dots hurt
        // more than help), so each map may set its own in Map Manager:
        // null = default 1.6, 0 = never, otherwise the stored multiplier.
        get dotThreshold(){
            const m = this.currentMapObj;
            if(m && m.dot_zoom !== null && m.dot_zoom !== undefined){
                const v = Number(m.dot_zoom);
                if(!isNaN(v)) return v;      // 0 means "never" (mapZoom < 0 is never true)
            }
            return 1.6;
        },
        get currentMapObj(){
            const st = this.currentSite;
            if(!st || !st.maps || !st.maps.length) return null;
            return st.maps.find(m => m.key === this.selectedLevel) || null;
        },
        // Set zoom for the current map: its saved override if present, else auto-fit.
        _applyZoomForCurrentMap(){
            const m = this.currentMapObj;
            const z0 = (m && m.default_zoom) ? Number(m.default_zoom) : 0;
            const fx = (m && m.focus_x != null) ? Number(m.focus_x) : null;
            const fy = (m && m.focus_y != null) ? Number(m.focus_y) : null;
            if(fx === null || fy === null){
                // No start view saved: original behavior (default zoom or reset).
                this._focusTarget = null;
                if(z0 && z0 > 0) this.mapZoom = Math.max(this.ZOOM_MIN, Math.min(this.ZOOM_MAX, z0));
                else this.zoomFit();     // zoomReset() would recurse back into here
                this._scrollToOrigin(8);
                return;
            }
            const zoom = (z0 && z0 > 0)
                ? Math.max(this.ZOOM_MIN, Math.min(this.ZOOM_MAX, z0)) : 1;
            this._focusTarget = { fx, fy, zoom };
            this._applyStartView(8);
        },
        // Applies the saved start view DETERMINISTICALLY. The app itself sets the
        // map's size (mapBaseW × zoom), so the scroll that centres a percentage
        // point is pure arithmetic:  scrollLeft = fx% · width − viewport/2.
        // Size, transform, counter-scale, and scroll all land synchronously in
        // one frame (the wheel-zoom technique) — no layout reads, no rectangle
        // measuring, nothing to race. This replaces the old rect-based centering,
        // which measured rendered geometry across animation-frame retries and
        // lost that race often enough that the feature never worked reliably.
        // The only retry left is for element MOUNT (first paint of the view).
        _applyStartView(tries){
            const t = this._focusTarget;
            if(!t) return;
            const vp = this.$refs.viewport, sizer = this.$refs.sizer, canvas = this.$refs.canvas;
            if(!vp || !sizer || !canvas){
                if(tries > 0) requestAnimationFrame(() => this._applyStartView(tries - 1));
                return;
            }
            // A hidden viewport (entering a site on the Cameras tab keeps the
            // map at display:none) measures 0×0, and centring against zero
            // dimensions computes garbage scroll positions. Don't burn retries
            // either — keep the focus target armed; the tab switch to Map
            // re-invokes the applier when the viewport actually has a size.
            if(vp.clientWidth <= 0 || vp.clientHeight <= 0) return;
            const w = this.mapBaseW * t.zoom, h = this.mapBaseH * t.zoom;
            sizer.style.width  = w + 'px';
            sizer.style.height = h + 'px';
            canvas.style.transform = 'scale(' + t.zoom + ')';
            canvas.style.setProperty('--pin-scale', String(1 / t.zoom));
            this.mapZoom = t.zoom;   // Alpine re-applies these same values
            const ox = sizer.offsetLeft || 0, oy = sizer.offsetTop || 0;
            let sx = Math.max(0, ox + (t.fx / 100) * w - vp.clientWidth  / 2);
            let sy = Math.max(0, oy + (t.fy / 100) * h - vp.clientHeight / 2);
            let dbg = false; try { dbg = !!localStorage.getItem('sm_debug_start'); } catch(e){}
            if(dbg) console.log('[start-view] target', { fx:t.fx, fy:t.fy, zoom:t.zoom, sx, sy, w, h, ox, oy });
            // ENFORCE, don't fire-and-forget: a single set (and a single one-frame
            // re-assert) both proved losable — late DOM work like the SVG markup
            // swap can zero a scroll container whenever it lands. So for up to
            // ~30 frames: any frame where the scroll was yanked off target gets
            // re-asserted; three consecutive stable frames ends it early. Manual
            // panning cancels instantly via _focusTarget.
            let stable = 0, left = 30;
            const setScroll = () => {
                vp.scrollLeft = sx; vp.scrollTop = sy;
                // If the browser clamped us (content smaller than the viewport in
                // an axis), adopt the reachable value as the target.
                if(Math.abs(vp.scrollLeft - sx) > 2) sx = vp.scrollLeft;
                if(Math.abs(vp.scrollTop  - sy) > 2) sy = vp.scrollTop;
            };
            const enforce = () => {
                if(this._focusTarget !== t) return;
                if(Math.abs(vp.scrollLeft - sx) > 2 || Math.abs(vp.scrollTop - sy) > 2){
                    if(dbg) console.log('[start-view] re-assert (found', vp.scrollLeft, vp.scrollTop, ')');
                    setScroll();
                    stable = 0;
                } else stable++;
                if(stable < 3 && --left > 0) requestAnimationFrame(enforce);
                else if(dbg) console.log('[start-view] settled at', vp.scrollLeft, vp.scrollTop);
            };
            setScroll();
            requestAnimationFrame(enforce);
        },
        // "Set start view here": capture the CURRENT viewport center + zoom as
        // this map's opening view. Compose the view by panning/zooming, then
        // one click saves it — no special click-mode needed.
        async saveMapStartView(){
            const m = this.currentMapObj;
            const vp = this.$refs.viewport;
            if(!m || !vp || !this.currentSiteId) return;
            const sz = this.$refs.sizer; if(!sz) return;
            const r = sz.getBoundingClientRect();
            const vr = vp.getBoundingClientRect();
            // Where the viewport's center currently sits, as a % of the map
            // content itself (clamped by the shared pct helpers).
            const fx = this._pctX(vr.left + vr.width/2, r);
            const fy = this._pctY(vr.top  + vr.height/2, r);
            const data = await this.api('?api=map&action=set_focus',
                { site_number:this.currentSiteId, map_key:m.key, x:fx, y:fy, zoom:this.mapZoom }, 'Could not save start view');
            if(data){
                m.focus_x = data.x; m.focus_y = data.y; m.default_zoom = data.zoom;
                this.showToast('Start view saved — this map now opens right here', 'ok');
            }
        },
        async clearMapStartView(){
            const m = this.currentMapObj;
            if(!m || !this.currentSiteId) return;
            const data = await this.api('?api=map&action=set_focus',
                { site_number:this.currentSiteId, map_key:m.key, x:null, y:null, zoom:null }, 'Could not clear start view');
            if(data){
                m.focus_x = null; m.focus_y = null; m.default_zoom = null;
                this.showToast('Start view cleared — this map opens auto-fit again', 'ok');
            }
        },
        // Apply a map's SAVED starting zoom when it loads/switches. If the map has
        // a default_zoom override, open at exactly that; otherwise auto-fit. Called
        // on map load/switch only (not on plain window resize, so a resize won't
        // yank you back to the saved zoom after you've zoomed manually).
        applyMapDefaultZoom(){
            this.$nextTick(() => this._applyZoomForCurrentMap());
        },
        // Force the level <select>'s VISUAL selection to match selectedLevel.
        // Why this exists: the select uses :value="selectedLevel", which only
        // re-applies el.value when selectedLevel itself changes — but on a site
        // switch, the <option> list is ALSO being rebuilt (new site's maps) in
        // that same update. If the :value effect happens to run before the new
        // options exist in the DOM yet, the browser silently ignores setting a
        // value with no matching <option> (native <select> behavior), and the
        // element is left showing a stale/default option — even though
        // selectedLevel itself is correct the whole time (which is why the map
        // content loads correctly; that logic reads selectedLevel directly, not
        // the DOM). $nextTick waits for Alpine's queued DOM updates — including
        // the option list — to finish, so by the time this runs the option we
        // want definitely exists, and setting .value directly is guaranteed to
        // stick, regardless of internal effect ordering.
        _syncLevelSelectDom(){
            this.$nextTick(() => {
                const el = this.$refs.levelSelect;
                if(el && el.value !== this.selectedLevel) el.value = this.selectedLevel;
            });
        },

        // Click-and-drag panning of the map viewport. Engages only when the pointer
        // lands on the map background — not on a room polygon, vertex handle, edge
        // add-point, rotate handle, etc. — so editing interactions still work. Uses
        // middle-click anywhere, or left-click on the background.
        startPan(ev){
            // While placing a room, left-drags belong to the highlight box on the
            // catch layer — the pan bug: without this (and .stop on the layer),
            // BOTH handlers ran, the map scrolled mid-drag, and the labels moved
            // out from under the box before it was read.
            if(this.placingRoom && ev.button !== 1) return;
            this._focusTarget = null;   // manual pan takes over from the saved start view
            // Middle mouse pans from anywhere; left mouse only from the background.
            const fromBackground = !ev.target.closest('.room-poly, .vertex-handle, .move-target, .edge-grab, .add-pt, .rotate-handle, .rotate-stem, .draft-point, .angle-pt, .room-label, .map-tool-group, button, input');
            // In building-select mode, a left-drag from the background is a LASSO box
            // (drag to grab many pins at once) rather than a pan. Middle mouse still pans.
            if(this.roomSelect.on && ev.button !== 1 && fromBackground && !this.drawingRoom){
                this.startSelectBox(ev);
                return;
            }
            if(ev.button !== 1 && !fromBackground) return;
            // also skip when actively drawing or measuring — those use clicks
            if(this.drawingRoom || this.measuringAngle) return;
            const vp = this.$refs.viewport; if(!vp) return;
            ev.preventDefault();
            const startX = ev.clientX, startY = ev.clientY;
            const startSL = vp.scrollLeft, startST = vp.scrollTop;
            this.isPanning = true;
            const move = (e) => {
                vp.scrollLeft = startSL - (e.clientX - startX);
                vp.scrollTop  = startST - (e.clientY - startY);
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                this.isPanning = false;
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        // Lasso/box select for the building grouping tool. Drag a rectangle over
        // the map and every room pin whose point falls inside is selected at once —
        // far faster than clicking 30 pins. Holding Shift ADDS to the current
        // selection; a plain drag replaces it. Coordinates are kept in canvas
        // percent (0–100) so they line up with pin label positions directly.
        startSelectBox(ev){
            const canvas = this.$refs.canvas; if(!canvas) return;
            ev.preventDefault();
            const additive = ev.shiftKey;
            const baseIds = additive ? this.roomSelect.ids.slice() : [];
            const rect = canvas.getBoundingClientRect();
            const toPct = (cx, cy) => ({
                x: Math.max(0, Math.min(100, ((cx - rect.left) / rect.width) * 100)),
                y: Math.max(0, Math.min(100, ((cy - rect.top)  / rect.height) * 100)),
            });
            const start = toPct(ev.clientX, ev.clientY);
            this.roomSelect.box = { x0:start.x, y0:start.y, x1:start.x, y1:start.y };
            let moved = false;
            const move = (e) => {
                const p = toPct(e.clientX, e.clientY);
                const b = this.roomSelect.box;
                b.x1 = p.x; b.y1 = p.y;
                if(Math.abs(b.x1-b.x0) > 0.4 || Math.abs(b.y1-b.y0) > 0.4) moved = true;
                // live-update selection as the box grows
                const lo = { x:Math.min(b.x0,b.x1), y:Math.min(b.y0,b.y1) };
                const hi = { x:Math.max(b.x0,b.x1), y:Math.max(b.y0,b.y1) };
                const inside = this.roomsVisible.filter(r => {
                    const lp = this.labelPosition(r);
                    return lp.x >= lo.x && lp.x <= hi.x && lp.y >= lo.y && lp.y <= hi.y;
                }).map(r => r.room_id);
                // merge with the base selection (additive when Shift held)
                const set = new Set(baseIds);
                inside.forEach(id => set.add(id));
                this.roomSelect.ids = Array.from(set);
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                this.roomSelect.box = null;
                if(moved){
                    const n = this.roomSelect.ids.length;
                    this.showToast(n ? (n + ' room' + (n===1?'':'s') + ' selected') : 'No rooms in selection', n ? 'ok' : 'err');
                }
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        // ====================================================
        // ROOMS
        // ====================================================
        polyToString(pts){
            if(!pts || !pts.length) return '';
            return pts.map(p => p.x + ',' + p.y).join(' ');
        },

        // Build the entire room overlay as one SVG string. Avoids putting Alpine
        // <template>s inside <svg> (which the HTML parser hoists out, breaking scope).

        // Handle radius in viewBox units, scaled so it stays ~constant on screen
        // regardless of map zoom (viewBox is 0-100 stretched over the canvas).
        get handleR(){
            const pxPerUnit = (this.mapBaseW * this.mapZoom) / 100;
            const r = 5 / Math.max(pxPerUnit, 0.001);
            return Math.max(0.18, Math.min(1.4, Math.round(r*100)/100));
        },

        // ---- Overlay event delegation (reads data-* off the hit element) ----

        // Drag the rotate handle: spin the room around its centroid to follow the
        // cursor. Hold Shift to snap to 15° increments. Auto-saves on release.

        labelPosition(room){
            if(room.label_x !== null && room.label_y !== null && room.label_x !== undefined && room.label_y !== undefined){
                return { x: room.label_x, y: room.label_y };
            }
            const pts = room.polygon_points || [];
            if(!pts.length) return { x: 50, y: 50 };
            const sx = pts.reduce((a,p) => a + p.x, 0) / pts.length;
            const sy = pts.reduce((a,p) => a + p.y, 0) / pts.length;
            return { x: sx, y: sy };
        },

        // ====================================================
        // UNIVERSAL ROOM SEARCH (per-site + all-sites)
        // Matches on any scrap of info: room number/name/type/department,
        // room extension/notes, occupant name/role/extension, and any device's
        // asset tag / model / serial / IP / name.
        // ====================================================
        _norm(s){ return (s == null ? '' : String(s)).toLowerCase().trim(); },
        _devicesByRoom(){
            // Build once per search pass for speed.
            if(this._devByRoomCache) return this._devByRoomCache;
            const map = {};
            (this.devices || []).forEach(d => { (map[d.room_id] = map[d.room_id] || []).push(d); });
            this._devByRoomCache = map;
            return map;
        },
        _roomDevices(room){ return this._devicesByRoom()[room.room_id] || []; },
        _printersByRoom(){
            // Same per-pass cache as devices: printers were absent from search
            // entirely, so barcodes/serials on them were unfindable.
            if(this._prtByRoomCache) return this._prtByRoomCache;
            const map = {};
            (this.printers || []).forEach(p => { if(p.room_id != null) (map[p.room_id] = map[p.room_id] || []).push(p); });
            this._prtByRoomCache = map;
            return map;
        },
        _roomPrinters(room){ return this._printersByRoom()[room.room_id] || []; },
        _roomHaystack(room){
            const parts = [room.room_number, room.room_name, this.formatRoomType(room.room_type),
                           room.department, room.room_extension, room.room_notes,
                           room.building, this.roomNumberLabel(room)];
            (room.occupants || []).forEach(o => { parts.push(o.name, o.role, o.extension); });
            this._roomDevices(room).forEach(d => { parts.push(d.asset_tag, d.model, d.serial_number, d.ip_address, d.device_name); });
            // Printers assigned to this room: barcode included so a scanned
            // label resolves straight to the room holding that printer.
            this._roomPrinters(room).forEach(p => { parts.push(p.barcode, p.printer_name, p.model, p.serial_number, p.toner_id, p.location); });
            return parts.filter(Boolean).join(' \u0001 ').toLowerCase();
        },
        // Returns a short human reason describing why this room matched (for the list).
        _matchReason(room, fullQ){
            // If the query led with a site name, reason against the remaining terms
            // so the badge reflects what actually matched (e.g. the extension), not
            // the site-name tokens.
            const scoped = this._parseSiteScopedQuery(fullQ);
            const q = (scoped.siteIds && scoped.rest) ? scoped.rest : this._norm(fullQ);
            if(!q) return null;
            const hit = (v) => { const n = this._norm(v); return n && n.includes(q); };
            if(hit(room.room_number) && this._norm(room.room_number) !== this._norm(room.room_name)) return null; // number shown anyway
            for(const o of (room.occupants || [])){
                if(hit(o.name)) return { icon:'person', text: o.name + (o.role ? ' · ' + o.role : '') };
                if(o.extension && hit(o.extension)) return { icon:'phone', text: 'Ext. ' + o.extension + ' · ' + (o.name||'') };
            }
            if(room.room_extension && hit(room.room_extension)) return { icon:'phone', text: 'Room ext. ' + room.room_extension };
            for(const p of this._roomPrinters(room)){
                if(p.barcode && hit(p.barcode)) return { icon:'device', text: 'Barcode ' + p.barcode + ' · ' + (p.printer_name || 'printer') };
                if(p.serial_number && hit(p.serial_number)) return { icon:'device', text: 'Printer serial ' + p.serial_number };
                if(p.toner_id && hit(p.toner_id)) return { icon:'device', text: 'Toner ' + p.toner_id };
                if(p.printer_name && hit(p.printer_name)) return { icon:'device', text: p.printer_name };
            }
            for(const d of this._roomDevices(room)){
                if(hit(d.asset_tag)) return { icon:'device', text: 'Asset ' + d.asset_tag };
                if(hit(d.serial_number)) return { icon:'device', text: 'Serial ' + d.serial_number };
                if(hit(d.ip_address)) return { icon:'device', text: 'IP ' + d.ip_address };
                if(hit(d.model)) return { icon:'device', text: d.model };
                if(hit(d.device_name)) return { icon:'device', text: d.device_name };
            }
            if(hit(room.department)) return { icon:'tag', text: room.department };
            if(hit(room.room_notes)) return { icon:'note', text: room.room_notes };
            return null;
        },
        _scoreRoom(room, fullQ, tokenGroups){
            const num = this._norm(room.room_number);
            const name = this._norm(room.room_name);
            const ext = this._norm(room.room_extension);
            const bldg = this._norm(room.building);                 // e.g. "b1"
            const blabel = this._norm(this.roomNumberLabel(room));  // e.g. "b1-100"
            let score = 0;
            // direct field hits against the full query
            if(num){ if(num === fullQ) score = Math.max(score, 100); else if(num.startsWith(fullQ)) score = Math.max(score, 86); }
            // building-prefixed number: "b1-100" exact, "b1-1" prefix, and a bare "b1-" / "b1"
            if(blabel){ if(blabel === fullQ) score = Math.max(score, 100); else if(blabel.startsWith(fullQ)) score = Math.max(score, 88); }
            if(bldg){
                const bq = fullQ.replace(/-+$/, '');   // treat "b1-" like "b1"
                if(bldg === bq) score = Math.max(score, 70); else if(bldg.startsWith(bq) && bq) score = Math.max(score, 60);
            }
            if(name){ if(name === fullQ) score = Math.max(score, 96); else if(name.startsWith(fullQ)) score = Math.max(score, 80); }
            if(ext && ext === fullQ) score = Math.max(score, 90);
            for(const o of (room.occupants || [])){
                const on = this._norm(o.name);
                if(on){ if(on === fullQ) score = Math.max(score, 93); else if(on.startsWith(fullQ)) score = Math.max(score, 76); }
                if(o.extension && this._norm(o.extension) === fullQ) score = Math.max(score, 88);
            }
            for(const d of this._roomDevices(room)){
                [d.asset_tag, d.serial_number, d.ip_address].forEach(v => {
                    const nv = this._norm(v);
                    if(nv){ if(nv === fullQ) score = Math.max(score, 89); else if(nv.startsWith(fullQ)) score = Math.max(score, 70); }
                });
                // a prefixed number like "ex1200" → match the extension/number variant exactly too
                for(const g of tokenGroups){ for(const v of g){ if(ext && ext === v) score = Math.max(score, 84); } }
            }
            // also let the extension match a stripped variant directly (covers "ex1200")
            for(const g of tokenGroups){ for(const v of g){
                if(ext && ext === v) score = Math.max(score, 84);
                if(num && num === v) score = Math.max(score, 82);
                for(const o of (room.occupants || [])){ if(o.extension && this._norm(o.extension) === v) score = Math.max(score, 80); }
            }}
            if(score === 0){
                const hay = this._roomHaystack(room);
                if(fullQ.length >= 2 && hay.includes(fullQ)) score = 52;
                else {
                    // every token group must match (each group = a token OR its variants)
                    const allMatch = tokenGroups.length && tokenGroups.every(g => g.some(v => hay.includes(v)));
                    if(allMatch) score = 32;
                }
            }
            return score;
        },
        searchRooms(query, roomPool){
            const q = this._norm(query);
            if(q.length < 1) return [];
            this._devByRoomCache = null; this._prtByRoomCache = null; // rebuild for this pass
            const noise = new Set(['room','rm','ext','extension','ex','x','#','no','number','office','the']);
            let raw = q.split(/[\s,]+/).filter(Boolean);
            // each token becomes a group of acceptable variants (OR within the group)
            const tokenGroups = [];
            raw.forEach(t => {
                if(noise.has(t)) return; // drop standalone noise words
                const variants = [t];
                const m = t.match(/^(ext|ex|x|rm|no|#)(\d+)$/); // "ext1200" → also accept "1200"
                if(m) variants.push(m[2]);
                tokenGroups.push(variants);
            });
            // if everything was noise (e.g. just "room"), fall back to raw tokens
            const groups = tokenGroups.length ? tokenGroups : raw.map(t => [t]);
            const out = [];
            for(const room of roomPool){
                const score = this._scoreRoom(room, q, groups);
                if(score > 0) out.push({ room, score });
            }
            out.sort((a,b) => b.score - a.score
                || this._norm(a.room.room_name || a.room.room_number).localeCompare(this._norm(b.room.room_name || b.room.room_number)));
            this._devByRoomCache = null; this._prtByRoomCache = null;
            return out.slice(0, 40);
        },
        // Score a camera against the query (name, number, IP).
        _scoreCamera(cam, q, groups){
            const name = this._norm(cam.camera_name);
            const num  = this._norm(cam.camera_number);
            const ip   = this._norm(cam.camera_ip);
            if(num && num === q) return 100;                    // exact camera number
            if(ip && ip === q) return 96;                       // exact IP
            if(name === q) return 95;                           // exact name
            if(name.startsWith(q)) return 78;
            if(ip && ip.includes(q)) return 70;
            if(name.includes(q)) return 60;
            if(num && num.includes(q)) return 50;
            // multi-token: every group must match somewhere in the haystack
            const hay = name + ' ' + num + ' ' + ip + ' camera';
            if(groups.length && groups.every(g => g.some(v => hay.includes(v)))) return 34;
            return 0;
        },
        searchCameras(query, camPool){
            const q = this._norm(query);
            if(q.length < 1) return [];
            const noise = new Set(['camera','cam','ip','the']);
            let raw = q.split(/[\s,]+/).filter(Boolean);
            const groups = [];
            raw.forEach(t => { if(!noise.has(t)) groups.push([t]); });
            const useGroups = groups.length ? groups : raw.map(t => [t]);
            const out = [];
            for(const cam of camPool){
                const score = this._scoreCamera(cam, q, useGroups);
                if(score > 0) out.push({ type:'camera', item:cam, score });
            }
            out.sort((a,b) => b.score - a.score
                || this._natCmp(a.item.camera_name, b.item.camera_name));
            return out.slice(0, 20);
        },

        // ---- Per-site map search ----
        runMapSearch(){
            const q = this.mapSearch.q;
            // a new search invalidates any previous highlight
            if(this.blinkRoomId !== null){ clearTimeout(this._blinkTimer); this.blinkRoomId = null; }
            if(this._norm(q).length < 1){ this.mapSearch.results = []; this.mapSearch.open = false; return; }
            // Rooms (existing) — tag each with a type but keep `.room` for the template.
            const roomPool = this.rooms.filter(r => r.site_number === this.currentSiteId);
            const roomResults = this.searchRooms(q, roomPool).map(r => ({ type:'room', room:r.room, item:r.room, score:r.score }));
            // Cameras for this site — already permission-filtered server-side.
            const camPool = this.cameras.filter(c => c.site_number === this.currentSiteId);
            const camResults = this.searchCameras(q, camPool);
            // Merge + sort by score; cap the combined list.
            this.mapSearch.results = [...roomResults, ...camResults]
                .sort((a,b) => b.score - a.score)
                .slice(0, 40);
            this.mapSearch.open = true;
            this.mapSearch.choice = null;
        },
        // Clicking a result shows it on the map. It used to open a two-button
        // menu (go into room / show on map) with a "Quick find" toggle to skip
        // that menu — three ways to express one intent. Now there's one.
        pickSearchResult(room){
            this.mapSearch.open = false;
            this.focusRoomOnMap(room);
        },
        // Typed dispatcher used by the merged dropdown (rooms vs cameras).
        // Enter key: act on the top result, whatever its type.
        pickFirstResult(){
            const r = this.mapSearch.results[0];
            if(!r) return;
            if(r.type === 'camera') this.focusCameraOnMap(r.item);
            else this.pickSearchResult(r.room);
        },
        clearMapSearch(){ this.mapSearch.q=''; this.mapSearch.results=[]; this.mapSearch.open=false; this.mapSearch.choice=null; },

        // "Take me to it on the map": switch to its level, center the viewport on the
        // pin, and blink it a few times so it's obvious.
        focusRoomOnMap(room){
            this.clearMapSearch();
            const sameMap = (this.view === 'site' && this.currentSiteId === room.site_number);
            // ensure we're on the room's site + map view + correct level
            if(!sameMap) this.goSite(room.site_number);
            const lvl = room.map_level || 'level-1';
            if(this.selectedLevel !== lvl){ this.selectedLevel = lvl; this.loadSvgForCurrentSite(); }
            this.showPins = true;   // pin must be visible to highlight it
            const needsZoom = this.mapZoom < 1.6;
            this.$nextTick(() => this._revealRoom(room, 0, !sameMap, needsZoom));
        },
        // Reveal a camera on the map from search: ensure its site/level, turn the
        // camera layer on, scroll to its pin, and pop its info card.
        focusCameraOnMap(cam){
            this.clearMapSearch();
            const sameMap = (this.view === 'site' && this.currentSiteId === cam.site_number);
            if(!sameMap) this.goSite(cam.site_number);
            const lvl = cam.map_level || 'level-1';
            if(this.selectedLevel !== lvl){ this.selectedLevel = lvl; this.loadSvgForCurrentSite(); }
            this.showCameras = true;            // make sure the layer is visible
            const needsZoom = this.mapZoom < 1.6;
            this.$nextTick(() => this._revealCamera(cam, 0, !sameMap, needsZoom));
        },
        _revealCamera(cam, attempt, expectRelayout, doZoom){
            const vp = document.getElementById('mapViewport');
            if(!vp){
                if(attempt < 30){ setTimeout(() => this._revealCamera(cam, attempt + 1, expectRelayout, doZoom), 80); }
                return;
            }
            if(doZoom && this.mapZoom < 1.8){
                this.mapZoom = 1.8;
                setTimeout(() => this._revealCamera(cam, attempt, expectRelayout, false), 100);
                return;
            }
            // If the camera has no map position, just open its info card.
            if(cam.map_x === null || cam.map_y === null){ this.selectCamera(cam); return; }
            const el = vp.querySelector('.camera-pin[data-cam="' + cam.camera_number + '"]');
            if(el){
                this._scrollPinIntoView(vp, el);
                this.selectCamera(cam);
            } else if(attempt < 30){
                setTimeout(() => this._revealCamera(cam, attempt + 1, expectRelayout, false), 80);
            } else {
                this.selectCamera(cam);
            }
            if(expectRelayout){
                setTimeout(() => {
                    const e2 = vp.querySelector('.camera-pin[data-cam="' + cam.camera_number + '"]');
                    if(e2) this._scrollPinIntoView(vp, e2);
                }, 340);
            }
        },
        _revealRoom(room, attempt, expectRelayout, doZoom){
            // Look the viewport up from the document rather than $refs — the ref may not be
            // registered yet right after switching into the map view.
            const vp = document.getElementById('mapViewport');
            if(!vp){
                if(attempt < 30){ setTimeout(() => this._revealRoom(room, attempt + 1, expectRelayout, doZoom), 80); }
                return;
            }
            this._blinkRoom(room.room_id);
            // Raise zoom once (before scrolling) so the pin is legible, then let layout settle.
            if(doZoom && this.mapZoom < 1.8){
                this.mapZoom = 1.8;
                setTimeout(() => this._revealRoom(room, attempt, expectRelayout, false), 100);
                return;
            }
            const el = vp.querySelector('.room-pin[data-room-id="' + room.room_id + '"]');
            if(el){
                this._scrollPinIntoView(vp, el);
            } else {
                // Pin not rendered yet — retry; if it never appears (e.g. an unplaced room),
                // fall back to a coordinate scroll so the map still moves to the area.
                if(attempt < 30){ setTimeout(() => this._revealRoom(room, attempt + 1, expectRelayout, false), 80); return; }
                this._scrollToRoomCoords(vp, room);
            }
            // After a site switch the map does a fit-to-width zoom on a later tick; one more
            // pass once it settles so the pin still lands correctly.
            if(expectRelayout){
                setTimeout(() => {
                    const e2 = vp.querySelector('.room-pin[data-room-id="' + room.room_id + '"]');
                    if(e2) this._scrollPinIntoView(vp, e2);
                    else this._scrollToRoomCoords(vp, room);
                }, 340);
            }
        },
        // Scroll the viewport the MINIMUM needed so the pin sits inside a comfortable
        // margin band — leaves it where it is if already visible, never yanks to center.
        _scrollPinIntoView(vp, el){
            const vpr = vp.getBoundingClientRect();
            const er  = el.getBoundingClientRect();
            const margin = 90; // keep this much breathing room from the edges
            let dx = 0, dy = 0;
            if(er.left   < vpr.left + margin)  dx = er.left   - (vpr.left + margin);
            else if(er.right  > vpr.right - margin)  dx = er.right  - (vpr.right - margin);
            if(er.top    < vpr.top + margin)   dy = er.top    - (vpr.top + margin);
            else if(er.bottom > vpr.bottom - margin) dy = er.bottom - (vpr.bottom - margin);
            if(dx || dy) vp.scrollBy({ left: dx, top: dy, behavior: 'smooth' });
        },
        // Fallback when the pin element isn't available: compute the scroll target from
        // the room's stored position. Same "bring into view, don't center" behavior.
        _scrollToRoomCoords(vp, room){
            const pos = this.labelPosition(room);              // 0..100 percentages
            const px = (pos.x / 100) * (this.mapBaseW * this.mapZoom);
            const py = (pos.y / 100) * (this.mapBaseH * this.mapZoom);
            const margin = 90;
            let left = vp.scrollLeft, top = vp.scrollTop;
            if(px < vp.scrollLeft + margin) left = px - margin;
            else if(px > vp.scrollLeft + vp.clientWidth - margin) left = px - vp.clientWidth + margin;
            if(py < vp.scrollTop + margin) top = py - margin;
            else if(py > vp.scrollTop + vp.clientHeight - margin) top = py - vp.clientHeight + margin;
            vp.scrollTo({ left: Math.max(0, left), top: Math.max(0, top), behavior: 'smooth' });
        },
        _blinkRoom(roomId){
            // Always clear the previous highlight first so only one room is ever green,
            // and so re-triggering the same room restarts the animation cleanly.
            clearTimeout(this._blinkTimer);
            this.blinkRoomId = null;
            this.$nextTick(() => {
                this.blinkRoomId = roomId;
                // auto-clear after the animation finishes (4 pulses × 0.8s)
                this._blinkTimer = setTimeout(() => { this.blinkRoomId = null; }, 3400);
            });
        },
        // "Go into that room"
        goIntoRoom(room){
            this.clearMapSearch();
            this.goRoom(room.room_id);
        },

        // ---- All-Sites global search (grouped by site) ----
        // Parse a query that may contain a SITE NAME *anywhere* (start, middle, or
        // end) so you can combine a site with any other search term in any order:
        // "Westview Room 200" or "Room 200 Westview", "WVE Gym" or "Gym WVE", etc.
        // Returns { siteIds:[...]|null, rest:'...' }: siteIds is the set of sites
        // whose name/abbr matched a run of tokens, and rest is the remaining query
        // (the matched site tokens removed). If no site name is found, siteIds is
        // null and rest is the whole query (normal all-sites search).
        _parseSiteScopedQuery(query){
            const norm = this._norm(query);
            if(!norm) return { siteIds:null, rest:'' };
            const tokens = norm.split(/[\s,]+/).filter(Boolean);
            const sitesNorm = (this.sites || []).map(s => ({
                id: s.id,
                name: this._norm(s.name),
                abbr: this._norm(s.abbr),
            }));
            const matchRun = (phrase, take) => sitesNorm.filter(s => {
                if(!s.name && !s.abbr) return false;
                if(s.abbr && s.abbr === phrase) return true;            // abbr exact
                if(s.name === phrase) return true;                      // name exact
                if(s.name && s.name.startsWith(phrase)) return true;    // name prefix
                if(take >= 2 && s.name && s.name.includes(phrase)) return true; // multi-word contained
                return false;
            });
            // Try the LONGEST contiguous run first (so "Del Norte High" beats "Del"),
            // and at each length slide a window across EVERY start position so the
            // site name can sit anywhere in the query.
            const maxTake = Math.min(tokens.length, 6);
            for(let take = maxTake; take >= 1; take--){
                for(let start = 0; start + take <= tokens.length; start++){
                    const phrase = tokens.slice(start, start + take).join(' ');
                    if(phrase.length < 2) continue;
                    const hits = matchRun(phrase, take);
                    if(!hits.length) continue;
                    // remaining query = all tokens EXCEPT the matched run
                    const rest = tokens.slice(0, start).concat(tokens.slice(start + take)).join(' ');
                    const confident = hits.some(s => s.name === phrase || s.abbr === phrase || (s.name && s.name.startsWith(phrase)));
                    // Scope only if there's leftover query to search, or this is a
                    // confident whole/prefix site match (so a bare site name lists it).
                    // This avoids hijacking a plain search that merely shares one word
                    // with a site name when there's nothing else to anchor on.
                    if(rest || confident){
                        return { siteIds: hits.map(h => h.id), rest };
                    }
                }
            }
            return { siteIds:null, rest:norm };
        },
        runGlobalSearch(){
            const q = this.globalSearch.q;
            if(this._norm(q).length < 1){ this.globalSearch.groups = []; this.globalSearch.open = false; this.globalSearch.total = 0; return; }

            // Detect an optional site name anywhere so "<Site> <terms>" and
            // "<terms> <Site>" both work.
            const scoped = this._parseSiteScopedQuery(q);
            const siteIds = scoped.siteIds;          // null = all sites
            const rest = scoped.rest;                // remaining terms to match

            let matches, camMatches;
            if(siteIds && !rest){
                // Just a site name with no other terms → list that site's rooms.
                const pool = this.rooms.filter(r => siteIds.includes(r.site_number));
                matches = pool.map(room => ({ room, score: 60 }))
                    .sort((a,b) => this._natCmp(
                        a.room.room_number || a.room.room_name,
                        b.room.room_number || b.room.room_name));
                const camPool = this.cameras.filter(c => siteIds.includes(c.site_number));
                camMatches = camPool.map(item => ({ type:'camera', item, score: 55 }));
            } else {
                // Normal search on the remaining terms, optionally scoped to the site.
                const roomPool = siteIds ? this.rooms.filter(r => siteIds.includes(r.site_number)) : this.rooms;
                const camPool  = siteIds ? this.cameras.filter(c => siteIds.includes(c.site_number)) : this.cameras;
                matches = this.searchRooms(rest, roomPool);
                camMatches = this.searchCameras(rest, camPool);
            }

            // group by site, preserving score order within each
            const bySite = {};
            matches.forEach(m => {
                const sid = m.room.site_number;
                (bySite[sid] = bySite[sid] || { rooms:[], cameras:[] }).rooms.push(m);
            });
            camMatches.forEach(m => {
                const sid = m.item.site_number;
                (bySite[sid] = bySite[sid] || { rooms:[], cameras:[] }).cameras.push(m);
            });
            const groups = Object.keys(bySite).map(sid => {
                const site = this.siteById(+sid);
                const g = bySite[sid];
                const bestScore = Math.max(
                    g.rooms[0]?.score || 0,
                    g.cameras[0]?.score || 0
                );
                return {
                    site_id: +sid,
                    site_name: site ? site.name : ('Site ' + sid),
                    site_color: site ? site.color : '#888',
                    rooms: g.rooms,
                    cameras: g.cameras,
                    _best: bestScore,
                };
            });
            // sort groups by their best match score (desc)
            groups.sort((a,b) => b._best - a._best || a.site_name.localeCompare(b.site_name));
            this.globalSearch.groups = groups;
            this.globalSearch.total = matches.length + camMatches.length;
            this.globalSearch.open = true;
        },
        clearGlobalSearch(){ this.globalSearch.q=''; this.globalSearch.groups=[]; this.globalSearch.open=false; this.globalSearch.total=0; },
        // From a global result: go into the site map and highlight the room.
        globalGoToMap(room){
            this.clearGlobalSearch();
            this.focusRoomOnMap(room);
        },
        globalGoIntoRoom(room){
            this.clearGlobalSearch();
            this.goRoom(room.room_id);
        },
        // From a global result: reveal a camera on its site map.
        globalGoToCamera(cam){
            this.clearGlobalSearch();
            this.focusCameraOnMap(cam);
        },
        // Small helper: an icon for a match-reason kind
        reasonIcon(kind){
            const I = {
                person:'<path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>',
                phone:'<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/>',
                device:'<rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/>',
                tag:'<path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><line x1="7" y1="7" x2="7.01" y2="7"/>',
                note:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
            };
            const p = I[kind] || I.tag;
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'+p+'</svg>';
        },

        // Grid overlay (rotated to the building) shown when Show grid is on in edit mode.
        get gridSvg(){
            if(!(this.roomEditMode && this.showGrid)) return '';
            const step = this.gridStep || 1;
            const ang = this.buildingAngle;
            let s = '<svg viewBox="0 0 100 100" preserveAspectRatio="none" style="position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none">';
            s += '<g transform="rotate('+ang+' 50 50)">';
            for(let v=-50; v<=150.0001; v+=step){
                const a=Math.round(v*1000)/1000;
                s += '<line class="grid-line" x1="'+a+'" y1="-50" x2="'+a+'" y2="150"></line>';
                s += '<line class="grid-line" x1="-50" y1="'+a+'" x2="150" y2="'+a+'"></line>';
            }
            s += '</g></svg>';
            return s;
        },
        formatLevel(lvl){
            if(!lvl) return 'Level 1';
            const m = String(lvl).match(/level-?(\d+)/i);
            if(m) return 'Level ' + m[1];
            return lvl;
        },
        // Human-friendly "last seen" from a SQL timestamp (e.g. "3 min ago").
        formatLastSeen(ts){
            if(!ts) return '—';
            const d = new Date(String(ts).replace(' ', 'T'));
            if(isNaN(d.getTime())) return String(ts);
            const secs = Math.floor((Date.now() - d.getTime()) / 1000);
            if(secs < 0) return d.toLocaleString();
            if(secs < 60) return 'just now';
            const mins = Math.floor(secs/60);
            if(mins < 60) return mins + (mins===1?' min ago':' mins ago');
            const hrs = Math.floor(mins/60);
            if(hrs < 24) return hrs + (hrs===1?' hour ago':' hours ago');
            const days = Math.floor(hrs/24);
            if(days < 30) return days + (days===1?' day ago':' days ago');
            return d.toLocaleDateString();
        },
        formatRoomType(t){
            const map = {
                general:'General', classroom:'Classroom', office:'Office', lab:'Lab',
                library:'Library', breakroom:'Break Room', storage:'Storage',
                restroom:'Restroom', utility:'Utility', hallway:'Hallway',
                conference:'Conference Room', cafeteria:'Cafeteria', gym:'Gym',
                auditorium:'Auditorium'
            };
            return map[t] || (t ? t.charAt(0).toUpperCase()+t.slice(1) : 'General');
        },
        // The room's identifier with building prefix: "A1-100". Falls back to just
        // the number, then the name, when pieces are missing.
        roomNumberLabel(room){
            if(!room) return '';
            const num = (room.room_number || '').toString().trim();
            const bld = (room.building || '').toString().trim();
            if(bld && num) return bld + '-' + num;
            if(bld) return bld;
            return num;
        },
        // Full display label for a room (building+number, then name).
        roomLabel(room){
            if(!room) return '';
            const id = this.roomNumberLabel(room);
            const name = (room.room_name || '').toString().trim();
            if(id && name) return name;     // name shown as primary; id shown separately
            return name || id || 'Room';
        },
        roomCountForBuilding(code){
            // The building pool is GLOBAL (shared across every site), so the count
            // shown next to each code in Settings must be too — how many rooms,
            // district-wide, use this code. (This used to be scoped to whatever
            // site happened to be "current," which silently showed 0 whenever
            // Settings was opened outside a site, and undercounted otherwise.)
            return this.rooms.filter(r => (r.building||'') === code).length;
        },
        // Name of the map (suite/floor) a room sits on, for search results etc.
        mapNameForRoom(room){
            if(!room) return '';
            const site = this.siteById(room.site_number);
            if(!site || !site.maps || site.maps.length < 2) return '';  // single-map site: no need to label
            const key = room.map_level || 'level-1';
            const m = site.maps.find(mm => mm.key === key);
            return m ? m.name : '';
        },
        // A color per room type — used to tint the popup header icon badge.
        roomTypeColor(t){
            const c = {
                classroom:'#3b82f6', office:'#8b5cf6', lab:'#06b6d4', library:'#10b981',
                breakroom:'#f59e0b', storage:'#64748b', restroom:'#0ea5e9', utility:'#64748b',
                hallway:'#94a3b8', conference:'#6366f1', cafeteria:'#f97316', gym:'#ec4899',
                auditorium:'#a855f7', general:'#3b82f6'
            };
            return c[t] || '#3b82f6';
        },
        // A simple outline icon per room type for the header badge.
        roomTypeIcon(t){
            const I = {
                classroom:'<path d="M4 19V5a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v14"/><path d="M2 19h20"/><path d="M8 8h8M8 12h5"/>',
                office:'<rect x="3" y="7" width="18" height="13" rx="1"/><path d="M8 7V5a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
                lab:'<path d="M9 3h6M10 3v6l-5 9a2 2 0 0 0 2 3h10a2 2 0 0 0 2-3l-5-9V3"/>',
                library:'<path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/>',
                breakroom:'<path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4z"/><path d="M6 2v2M10 2v2M14 2v2"/>',
                storage:'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/>',
                restroom:'<circle cx="12" cy="5" r="2"/><path d="M9 22V12l-2-3h10l-2 3v10"/>',
                utility:'<path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18v3h3l6.3-6.3a4 4 0 0 0 5.4-5.4l-2.8 2.8-2-2z"/>',
                hallway:'<path d="M5 3v18M19 3v18"/><path d="M9 12h6M13 9l3 3-3 3"/>',
                conference:'<circle cx="9" cy="9" r="2"/><circle cx="15" cy="9" r="2"/><path d="M5 20v-1a4 4 0 0 1 4-4M15 15a4 4 0 0 1 4 4v1"/>',
                cafeteria:'<path d="M3 2v7a3 3 0 0 0 6 0V2M6 2v20M16 2c-2 0-3 2-3 5s1 5 3 5 3-2 3-5-1-5-3-5zM16 12v10"/>',
                gym:'<path d="M6.5 6.5l11 11M3 9l3-3M9 3L6 6M18 15l3-3M15 21l3-3M2 14l8 8M14 2l8 8"/>',
                auditorium:'<path d="M3 10h18M5 10V7a7 7 0 0 1 14 0v3M4 10l-1 8h18l-1-8"/>',
                general:'<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6v6H9z"/>'
            };
            const p = I[t] || I.general;
            return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round">'+p+'</svg>';
        },
        glanceLevel(lvl){
            const m = String(lvl||'').match(/level-?(\d+)/i);
            return m ? 'L' + m[1] : (lvl ? this.formatLevel(lvl) : 'L1');
        },
        // Compact, human date: "May 29" this year, "May 29 '24" otherwise. Falls back to —.
        glanceDate(s){
            if(!s) return '—';
            const d = new Date((s||'').replace(' ','T'));
            if(isNaN(d)) return (s||'').slice(0,10) || '—';
            const mon = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][d.getMonth()];
            const now = new Date();
            return mon + ' ' + d.getDate() + (d.getFullYear()!==now.getFullYear() ? " '" + String(d.getFullYear()).slice(2) : '');
        },

        onRoomClick(room, ev){
            if(this.roomEditMode){
                // Safety net so the Building dropdown is never empty when editing.
                if(!this.siteBuildings || !this.siteBuildings.length) this.loadBuildings();
                this.editingRoomId = room.room_id;
                this.editForm = {
                    room_id: room.room_id,
                    room_name: room.room_name,
                    room_number: room.room_number || '',
                    building: room.building || '',
                    room_type: room.room_type || 'general',
                    department: room.department || '',
                    capacity: room.capacity || '',
                    description: room.description || '',
                    map_level: room.map_level || 'level-1',
                    color: room.color || '',
                    room_extension: room.room_extension || '',
                    room_notes: room.room_notes || '',
                    // Off by default; only true if the room explicitly has it on.
                    show_primary_contact: !!room.show_primary_contact,
                    label_x: room.label_x, label_y: room.label_y,
                    polygon_points: (room.polygon_points || []).map(p => ({x:p.x, y:p.y})),
                    // deep-copy occupants so edits don't mutate the live room until save
                    occupants: (room.occupants || []).map(o => ({
                        name: o.name || '', role: o.role || '',
                        extension: o.extension || '', email: o.email || '', is_primary: !!o.is_primary,
                    })),
                };
                return;
            }
            this.roomModal = room;
        },

        // ---- People editor helpers (operate on editForm.occupants) ----
        addOccupant(){
            if(!this.editForm.occupants) this.editForm.occupants = [];
            const first = this.editForm.occupants.length === 0;
            this.editForm.occupants.push({ name:'', role:'', extension:'', email:'', is_primary: first });
        },
        removeOccupant(idx){
            if(!this.editForm.occupants) return;
            const wasPrimary = this.editForm.occupants[idx]?.is_primary;
            this.editForm.occupants.splice(idx, 1);
            // if we removed the primary, promote the first remaining person
            if(wasPrimary && this.editForm.occupants.length){
                this.editForm.occupants.forEach((o,i) => o.is_primary = (i===0));
            }
        },
        setPrimaryOccupant(idx){
            (this.editForm.occupants || []).forEach((o,i) => o.is_primary = (i===idx));
        },

        // Pin pointer-down: a click selects/opens the room; in edit mode a drag
        // repositions the pin (updates label_x/label_y). We tell them apart by how
        // far the pointer moves before release.
        onPinPointerDown(room, ev){
            ev.stopPropagation();          // don't let the viewport start panning
            // In group-select mode, a tap just toggles selection — no drag, no editor.
            if(this.roomSelect.on){
                ev.preventDefault();
                this.toggleRoomSelected(room.room_id);
                return;
            }
            const canvas = this.$refs.canvas; if(!canvas) return;
            const rect = canvas.getBoundingClientRect();
            const startX = ev.clientX, startY = ev.clientY;
            let dragged = false;
            const editMode = this.roomEditMode;
            const move = (e) => {
                const dist = Math.hypot(e.clientX - startX, e.clientY - startY);
                if(!dragged && dist < 4) return;     // tiny movement = still a click
                if(!editMode) return;                // only reposition in edit mode
                dragged = true;
                const x = this._pctX(e.clientX, rect);
                const y = this._pctY(e.clientY, rect);
                const snapped = this._snapPin(x, y, room, e);
                room.label_x = Math.round(snapped.x*100)/100;
                room.label_y = Math.round(snapped.y*100)/100;
                this._paintGuides();
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                this.snapGuides = [];
                this._paintGuides();
                if(dragged){
                    this.savePinPosition(room);
                } else {
                    this.onRoomClick(room, ev);     // treat as a click
                }
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        // Snap a dragged pin to line up with other pins (same X or Y) so rows/columns
        // of rooms align perfectly, plus the optional grid. Hold Alt to bypass.
        // Records guide lines for the overlay. Works in the building-rotated frame.
        _snapPin(x, y, room, ev){
            if(ev && (ev.altKey || ev.metaKey)){ this.snapGuides=[]; return {x,y}; }
            const pxPerUnit = (this.mapBaseW * this.mapZoom) / 100;
            const tol = 6 / Math.max(pxPerUnit, 0.001);
            const loc = this.worldToLocal(x, y);
            // candidate alignment lines from every OTHER pin (in local frame)
            let blx=null, bly=null, bdx=tol, bdy=tol;
            for(const r of this.roomsVisible){
                if(r.room_id === room.room_id) continue;
                const lp = this.labelPosition(r);
                const rl = this.worldToLocal(lp.x, lp.y);
                const ax=Math.abs(rl.x-loc.x); if(ax<bdx){ bdx=ax; blx=rl.x; }
                const ay=Math.abs(rl.y-loc.y); if(ay<bdy){ bdy=ay; bly=rl.y; }
            }
            let lfx = blx!==null ? blx : loc.x;
            let lfy = bly!==null ? bly : loc.y;
            if(this.gridSnap){
                const g = this.gridStep || 1;
                if(blx===null){ const n=Math.round(loc.x/g)*g; if(Math.abs(n-loc.x)<=tol) lfx=n; }
                if(bly===null){ const n=Math.round(loc.y/g)*g; if(Math.abs(n-loc.y)<=tol) lfy=n; }
            }
            const ang = this.buildingAngle;
            this.snapGuides = [];
            if(blx!==null) this.snapGuides.push({type:'v', at:lfx, ang});
            if(bly!==null) this.snapGuides.push({type:'h', at:lfy, ang});
            const w = this.localToWorld(lfx, lfy);
            return { x: w.x, y: w.y };
        },

        // Paint snap-guide lines into a dedicated overlay layer during pin drags.
        _paintGuides(){
            const host = this.$refs.guideHost;
            if(!host) return;
            if(!this.snapGuides.length){ host.innerHTML=''; return; }
            const ang = this.snapGuides[0].ang || 0;
            let s = '<svg viewBox="0 0 100 100" preserveAspectRatio="none" style="position:absolute;inset:0;width:100%;height:100%;overflow:visible;pointer-events:none">';
            s += '<g transform="rotate('+ang+' 50 50)">';
            for(const g of this.snapGuides){
                if(g.type==='v') s += '<line class="snap-guide" x1="'+g.at+'" y1="-50" x2="'+g.at+'" y2="150"></line>';
                else s += '<line class="snap-guide" x1="-50" y1="'+g.at+'" x2="150" y2="'+g.at+'"></line>';
            }
            s += '</g></svg>';
            host.innerHTML = s;
        },

        // Persist a pin's new position. Mirrors the proven saveRoom payload exactly
        // (same site id source, same field types) so it can't drift out of sync.
        async savePinPosition(room){
            try {
                // recenter the stored polygon on the new pin point so shape + pin agree
                let poly = room.polygon_points || [];
                if(poly.length){
                    const cx = poly.reduce((a,p)=>a+p.x,0)/poly.length;
                    const cy = poly.reduce((a,p)=>a+p.y,0)/poly.length;
                    const dx = room.label_x - cx, dy = room.label_y - cy;
                    poly = poly.map(p=>({x:Math.round((p.x+dx)*100)/100, y:Math.round((p.y+dy)*100)/100}));
                } else {
                    const d=1.5, px=room.label_x, py=room.label_y;
                    poly = [{x:px-d,y:py-d},{x:px+d,y:py-d},{x:px+d,y:py+d},{x:px-d,y:py+d}];
                }
                room.polygon_points = poly;
                const res = await fetch('?api=room&action=save', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({
                        room_id: room.room_id || 0,
                        site_number: this.currentSiteId,
                        room_name: room.room_name,
                        room_number: room.room_number || '',
                        building: room.building || '',
                        room_type: room.room_type || 'general',
                        department: room.department || '',
                        capacity: room.capacity || null,
                        description: room.description || '',
                        room_extension: room.room_extension || '',
                        room_notes: room.room_notes || '',
                        map_level: room.map_level || 'level-1',
                        color: room.color || '',
                        label_x: room.label_x, label_y: room.label_y,
                        polygon_points: poly,
                    })
                });
                const data = await res.json();
                if(data.success){
                    // sync the saved row back so room_id and stored values stay correct
                    if(data.room){
                        const s = data.room;
                        s.polygon_points = Array.isArray(s.polygon_points) ? s.polygon_points : [];
                        const i = this.rooms.findIndex(r => r.room_id === s.room_id);
                        if(i >= 0) this.rooms.splice(i, 1, s);
                    }
                    this.showToast('Pin moved', 'ok');
                } else {
                    this.showToast(data.error || 'Could not save position', 'err');
                }
            } catch(e){ this.showToast('Could not save position', 'err'); }
        },

        // Click on the canvas while drawing — drop a polygon point
        onCanvasClick(ev){
            const canvas = this.$refs.canvas;
            if(!canvas) return;
            const rect = canvas.getBoundingClientRect();
            const x = this._pctX(ev.clientX, rect);
            const y = this._pctY(ev.clientY, rect);
            // Angle measurement consumes clicks first
            if(this.measuringAngle){
                this.anglePoints.push({ x: Math.round(x*100)/100, y: Math.round(y*100)/100 });
                if(this.anglePoints.length >= 2) this.finishAngleMeasure();
                return;
            }
            // (While placing a room, the .place-catch layer above the canvas owns
            // every pointer event — clicks can't reach here in that mode.)
        },
        // One place where a new room pin is created, whether it came from a
        // click or from a highlight box.
        _dropRoomPin(px, py, label, dragged, quiet){
            const d = 1.5;
            const neighbour = this._buildingFromNeighbour(px, py);
            this.editForm = {
                room_id: 0, room_name: label ? ('Room ' + label) : '', room_number: label || '',
                building: neighbour ? neighbour.building : '', room_type: 'general',
                department: '', capacity: '', description: '',
                map_level: this.selectedLevel, color: '',
                show_primary_contact: false,
                label_x: px, label_y: py,
                polygon_points: [
                    {x:px-d,y:py-d},{x:px+d,y:py-d},{x:px+d,y:py+d},{x:px-d,y:py+d}
                ],
            };
            this.editingRoomId = -1;     // sentinel: new room
            this.placingRoom = false;
            if(quiet){
                // The OCR flow narrates itself; just mention the inherited building.
                if(neighbour) this.showToast('Building ' + neighbour.building + ' inherited from '
                    + (neighbour.room_number || neighbour.room_name || 'the room next to it'), 'ok');
                return;
            }
            const bits = [];
            if(label) bits.push('read \u201c' + label + '\u201d off the map');
            if(neighbour) bits.push('building ' + neighbour.building + ' from '
                + (neighbour.room_number || neighbour.room_name || 'the room next to it'));
            if(bits.length){
                this.showToast('Pin dropped \u2014 ' + bits.join(', ') + '. Check it and Save.', 'ok');
            } else if(this.smartRooms && dragged){
                this.showToast('Nothing readable inside that box \u2014 this map\'s labels may be shapes rather than text. Type the number in.', 'err');
            } else if(this.smartRooms){
                this.showToast('Pin dropped \u2014 drag a box over the room number to read it, or type it in.', 'ok');
            } else {
                this.showToast('Pin dropped \u2014 fill in the details and Save', 'ok');
            }
        },
        onCanvasMove(ev){ /* reserved */ },

        toggleRoomEdit(){
            // Edit mode is the gateway to per-layer tools (rooms, printers, etc.).
            // Anyone who can edit ANY layer may enter it; each tool checks its own layer.
            if(!(this.can('base','edit') || this.can('printers','edit') || this.can('cameras','edit') || this.can('devices','edit'))) return;
            this.roomEditMode = !this.roomEditMode;
            if(!this.roomEditMode){
                this.placingRoom = false;
                this.editingRoomId = null;
            }
        },
        // "+ New Room" → enter placing mode; next map click drops the pin.
        // Buildings are spatial: a room dropped right beside Room 211 is almost
        // certainly in the same building as Room 211. When a new pin lands, look
        // for the nearest already-placed room ON THIS LEVEL that has a building
        // and borrow it. Distance is in map percent, and the cutoff matters: past
        // it we'd be guessing rather than reading the obvious, so we say nothing
        // and leave the field blank. Never overrides anything — this only ever
        // pre-fills a new room's empty building, and it's editable like any other
        // field before you Save.
        // Pin color resolution, one place: the room's own color wins; otherwise
        // its type's default from Settings; otherwise empty (the CSS default).
        roomColor(room){
            if(!room) return '';
            if(room.color) return room.color;
            return (this.roomTypeColors && this.roomTypeColors[room.room_type]) || '';
        },
        _buildingFromNeighbour(px, py){
            if(!this.inheritBuilding) return null;   // switched off in Settings
            const NEAR = 9;   // % of the map; beyond this, "next to" isn't true any more
            let best = null, bestD = Infinity;
            (this.roomsVisible || []).forEach(r => {
                if(!r.building) return;
                const p = this.labelPosition(r);   // handles label pins and polygon centroids
                const d = Math.hypot(p.x - px, p.y - py);
                if(d < bestD){ bestD = d; best = r; }
            });
            return (best && bestD <= NEAR) ? best : null;
        },
        // Smart Rooms: the floor plan is inlined as real SVG, so the room labels
        // printed on it are actual <text> nodes — we can read the exact characters
        // instead of OCR'ing pixels. Returns the label nearest the dropped pin,
        // preferring one whose box the click actually landed inside.
        // Caveat: if a plan's text was converted to outlines/paths when it was
        // exported, there are no <text> nodes to find and this returns null (the
        // form is simply left blank, as before).
        // Reads the room label printed on the plan. Takes the pointer's real
        // screen coordinates straight from the click event: previously the click
        // was converted to map percent and then back to pixels, and those two
        // conversions could disagree — which is how clicking 121 landed on 122A.
        // Preference: the smallest label box the click actually falls inside (an
        // exact hit), else the closest label within a small radius.
        _labelTextAt(clientX, clientY){
            const svg = document.querySelector('.map-bg svg');
            const sizer = this.$refs.sizer;
            if(!svg || !sizer) return null;
            const mapW = sizer.getBoundingClientRect().width;
            if(mapW < 10) return null;
            const NEAR = mapW * 0.02;      // 2% of the map, in on-screen pixels
            let hit = null, hitArea = Infinity, near = null, nearD = Infinity;
            svg.querySelectorAll('text, tspan').forEach(t => {
                const s = (t.textContent || '').replace(/\s+/g, ' ').trim();
                if(!s || s.length > 24) return;                          // paragraphs aren't room labels
                if(t.querySelector && t.querySelector('tspan')) return;  // prefer the leaf holding the text
                let r; try { r = t.getBoundingClientRect(); } catch(e){ return; }
                if(!r || (!r.width && !r.height)) return;
                if(clientX >= r.left && clientX <= r.right && clientY >= r.top && clientY <= r.bottom){
                    const area = r.width * r.height;    // smallest containing box = most specific label
                    if(area < hitArea){ hitArea = area; hit = s; }
                }
                const d = Math.hypot((r.left + r.width/2) - clientX, (r.top + r.height/2) - clientY);
                if(d < nearD){ nearD = d; near = s; }
            });
            if(hit) return hit;
            return (near && nearD <= NEAR) ? near : null;
        },
        // Everything the highlight box covers, read left-to-right, top-to-bottom.
        // Working in the pointer's own screen coordinates (no map-percent round
        // trip) and using an explicit box means there is no proximity guessing
        // left: you highlight it, you get it.
        _labelTextInRect(a, b){
            const svg = document.querySelector('.map-bg svg');
            if(!svg) return null;
            const L = Math.min(a.x, b.x), R = Math.max(a.x, b.x);
            const T = Math.min(a.y, b.y), B = Math.max(a.y, b.y);
            const found = [];
            svg.querySelectorAll('text, tspan').forEach(t => {
                const s = (t.textContent || '').replace(/\s+/g, ' ').trim();
                if(!s || s.length > 24) return;
                if(t.querySelector && t.querySelector('tspan')) return;   // read the leaf, not its container
                let r; try { r = t.getBoundingClientRect(); } catch(e){ return; }
                if(!r || (!r.width && !r.height)) return;
                const cx = r.left + r.width / 2, cy = r.top + r.height / 2;
                if(cx >= L && cx <= R && cy >= T && cy <= B) found.push({ s, x:cx, y:cy });
            });
            if(!found.length) return null;
            found.sort((p, q) => (p.y - q.y) || (p.x - q.x));
            return found.map(f => f.s).join(' ');
        },
        // Highlight-to-place: press on the map and drag a box over the room's
        // number. The pin lands in the middle of the box and the label is read
        // from whatever the box covered. A plain click (no drag) still works and
        // falls back to the nearest label.
        // Diagnostic: after a highlight read, flash an outline over every text
        // object the SVG actually contains near the box. If those outlines sit
        // BESIDE the printed numbers instead of on them, the map's text layer is
        // offset from its visuals — which would explain wrong reads that get
        // worse the further in you zoom (zoom multiplies the offset on screen).
        _flashTextNodes(a, b){
            const svg = document.querySelector('.map-bg svg');
            if(!svg) return;
            const pad = 140;   // show the neighbourhood, not just the box
            const L = Math.min(a.x, b.x) - pad, R = Math.max(a.x, b.x) + pad;
            const T = Math.min(a.y, b.y) - pad, B = Math.max(a.y, b.y) + pad;
            const marks = [];
            svg.querySelectorAll('text, tspan').forEach(t => {
                const s = (t.textContent || '').replace(/\s+/g, ' ').trim();
                if(!s || s.length > 24) return;
                if(t.querySelector && t.querySelector('tspan')) return;
                let r; try { r = t.getBoundingClientRect(); } catch(e){ return; }
                if(!r || (!r.width && !r.height)) return;
                const cx = r.left + r.width/2, cy = r.top + r.height/2;
                if(cx < L || cx > R || cy < T || cy > B) return;
                const m = document.createElement('div');
                m.className = 'smart-flash';
                m.style.left = r.left + 'px'; m.style.top = r.top + 'px';
                m.style.width = Math.max(6, r.width) + 'px'; m.style.height = Math.max(6, r.height) + 'px';
                m.dataset.txt = s;
                document.body.appendChild(m);
                marks.push(m);
            });
            setTimeout(() => marks.forEach(m => m.remove()), 2600);
        },
        // ---- Smart Rooms via OCR ----
        // The SVG text layer proved unreliable (offset/stale on most maps), so
        // the highlight box is now read the honest way: rasterize exactly what
        // is on screen and recognize the pixels. Because the source is vector,
        // the crop renders razor-sharp at any scale — ideal OCR input.
        async _ensureOcr(){
            if(_ocrWorker) return _ocrWorker;
            if(_ocrLoading) return _ocrLoading;
            _ocrLoading = (async () => {
                if(!window.Tesseract){
                    await new Promise((res, rej) => {
                        const s = document.createElement('script');
                        s.src = 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/tesseract.min.js';
                        s.onload = res;
                        s.onerror = () => rej(new Error('could not load the OCR engine from the CDN'));
                        document.head.appendChild(s);
                    });
                }
                const worker = await window.Tesseract.createWorker('eng', 1, {
                    workerPath: 'https://cdn.jsdelivr.net/npm/tesseract.js@5.1.1/dist/worker.min.js',
                    corePath:   'https://cdn.jsdelivr.net/npm/tesseract.js-core@5.1.0',
                    langPath:   'https://cdn.jsdelivr.net/npm/@tesseract.js-data/eng/4.0.0_best_int',
                });
                // Room labels are short alphanumerics on one line — constrain the
                // engine to exactly that and accuracy jumps.
                await worker.setParameters({
                    tessedit_char_whitelist: '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ-',
                    tessedit_pageseg_mode: '7',
                });
                _ocrWorker = worker;
                return worker;
            })();
            try { return await _ocrLoading; } finally { _ocrLoading = null; }
        },
        // Draw the highlighted region of the floor plan onto a canvas, scaled so
        // the glyphs come out large (~170px box height) and perfectly crisp.
        // Rasterize exactly the highlighted region. The previous version drew
        // the whole SVG into an image and cropped by its "natural size" — but a
        // viewBox-only SVG (typical CAD export) reports a junk natural size, so
        // the crop landed on a DIFFERENT part of the plan (hence OCR returning
        // random letters). Now the box's screen corners are mapped into the
        // SVG's own coordinate space via its live screen-transform matrix
        // (getScreenCTM — exact under any zoom or transform), and a clone of the
        // SVG is re-viewBoxed to precisely that region at a known pixel size.
        async _rasterizeBox(c0, c1){
            const svgEl = document.querySelector('.map-bg svg');
            if(!svgEl) return null;
            const clientW = Math.abs(c1.x - c0.x), clientH = Math.abs(c1.y - c0.y);
            if(clientW < 8 || clientH < 8) return null;
            let inv;
            try { inv = svgEl.getScreenCTM().inverse(); } catch(e){ return null; }
            const map = (x, y) => new DOMPoint(x, y).matrixTransform(inv);
            const p0 = map(Math.min(c0.x, c1.x), Math.min(c0.y, c1.y));
            const p1 = map(Math.max(c0.x, c1.x), Math.max(c0.y, c1.y));
            const uw = p1.x - p0.x, uh = p1.y - p0.y;
            if(!(uw > 0) || !(uh > 0)) return null;
            const scale = Math.max(1, Math.min(8, 170 / clientH));
            const cw = Math.min(2200, Math.round(clientW * scale));
            const ch = Math.min(2200, Math.round(clientH * scale));
            const clone = svgEl.cloneNode(true);
            clone.setAttribute('viewBox', p0.x + ' ' + p0.y + ' ' + uw + ' ' + uh);
            clone.setAttribute('width', String(cw));
            clone.setAttribute('height', String(ch));
            clone.removeAttribute('style');
            const xml = new XMLSerializer().serializeToString(clone);
            const url = URL.createObjectURL(new Blob([xml], { type:'image/svg+xml' }));
            try {
                const img = await new Promise((res, rej) => {
                    const i = new Image();
                    i.onload = () => res(i);
                    i.onerror = () => rej(new Error('could not rasterize the map'));
                    i.src = url;
                });
                const cv = document.createElement('canvas');
                cv.width = cw; cv.height = ch;
                const ctx = cv.getContext('2d');
                ctx.fillStyle = '#ffffff';
                ctx.fillRect(0, 0, cw, ch);
                ctx.drawImage(img, 0, 0, cw, ch);
                this._ocrPreprocess(ctx, cw, ch);
                // Debug: run localStorage.setItem('sm_debug_ocr','1') to see the
                // exact image the OCR engine receives, flashed on screen.
                try {
                    if(localStorage.getItem('sm_debug_ocr')){
                        const pv = document.createElement('img');
                        pv.src = cv.toDataURL();
                        pv.className = 'ocr-preview';
                        document.body.appendChild(pv);
                        setTimeout(() => pv.remove(), 4000);
                    }
                } catch(e){}
                return cv;
            } finally { URL.revokeObjectURL(url); }
        },
        // Contrast pass: room numbers sit on coloured fills; normalize to clean
        // black-on-white (inverting first if the region is dark-on-light-less),
        // which is the input Tesseract is happiest with.
        _ocrPreprocess(ctx, w, h){
            const im = ctx.getImageData(0, 0, w, h), d = im.data;
            let min = 255, max = 0, sum = 0;
            const n = d.length / 4;
            for(let i = 0; i < d.length; i += 4){
                const lum = 0.299*d[i] + 0.587*d[i+1] + 0.114*d[i+2];
                d[i] = lum;                       // stash lum in R
                if(lum < min) min = lum;
                if(lum > max) max = lum;
                sum += lum;
            }
            if(max - min < 24) return;            // flat region: leave it alone
            const mean = sum / n;
            const invert = mean < 128;            // light text on dark fill
            const t = min + (max - min) * 0.55;   // between the two populations
            for(let i = 0; i < d.length; i += 4){
                let v = d[i] <= t ? 0 : 255;
                if(invert) v = 255 - v;
                d[i] = d[i+1] = d[i+2] = v; d[i+3] = 255;
            }
            ctx.putImageData(im, 0, 0);
        },
        async _ocrLabel(c0, c1){
            const cv = await this._rasterizeBox(c0, c1);
            if(!cv) return null;
            const worker = await this._ensureOcr();
            const { data } = await worker.recognize(cv);
            return (data && data.text ? data.text : '').replace(/\s+/g, ' ').trim() || null;
        },
        startRoomPickBox(ev){
            const canvas = this.$refs.canvas;
            if(!canvas) return;
            ev.preventDefault();
            const rect = canvas.getBoundingClientRect();
            const toPct = (cx, cy) => ({ x: this._pctX(cx, rect), y: this._pctY(cy, rect) });
            const start = toPct(ev.clientX, ev.clientY);
            this.pickBox = { x0:start.x, y0:start.y, x1:start.x, y1:start.y };
            const c0 = { x:ev.clientX, y:ev.clientY };
            let c1 = { x:ev.clientX, y:ev.clientY };
            const move = (e) => {
                const p = toPct(e.clientX, e.clientY);
                this.pickBox.x1 = p.x; this.pickBox.y1 = p.y;
                c1 = { x:e.clientX, y:e.clientY };
            };
            const up = () => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                const box = this.pickBox; this.pickBox = null;
                if(!box) return;
                const dragged = Math.abs(c1.x - c0.x) > 4 || Math.abs(c1.y - c0.y) > 4;
                const px = Math.round(((box.x0 + box.x1) / 2) * 100) / 100;
                const py = Math.round(((box.y0 + box.y1) / 2) * 100) / 100;
                if(this.smartRooms && dragged){
                    // Pin and form appear immediately; the number arrives when the
                    // read finishes (first ever scan also loads the engine).
                    this._dropRoomPin(px, py, '', true, true);
                    this.showToast('Reading the number\u2026', 'ok');
                    this._ocrLabel(c0, c1).then(raw => {
                        const label = this._parseRoomLabel(raw);
                        if(!label){
                            this.showToast('Couldn\u2019t read a number there \u2014 try a tighter box around just the number, or type it in', 'err');
                            return;
                        }
                        // Only fill fields the user hasn't already typed in.
                        if(this.editingRoomId === -1 && this.editForm && !this.editForm.room_number){
                            this.editForm.room_number = label;
                            if(!this.editForm.room_name) this.editForm.room_name = 'Room ' + label;
                            this.showToast('Read \u201c' + label + '\u201d off the map', 'ok');
                        }
                    }).catch(e => {
                        console.warn('[smart-rooms ocr]', e);
                        this.showToast('Couldn\u2019t read it \u2014 ' + (e && e.message ? e.message : 'OCR engine error'), 'err');
                    });
                } else {
                    this._dropRoomPin(px, py, '', false);
                }
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },
        // "200" / "200A" / "A1-100C" are all room numbers; a label that already
        // reads "Room 200" shouldn't become "Room Room 200".
        _parseRoomLabel(raw){
            const s = String(raw || '').replace(/\s+/g, ' ').trim().replace(/^(room|rm)\.?\s+/i, '');
            return s;
        },
        startDrawRoom(){
            this.editingRoomId = null;
            this.editForm = {};
            this.placingRoom = true;
            this.showToast(this.smartRooms
                ? 'Drag a box over the room number on the map \u2014 or just click to place'
                : 'Click on the map to place the room', 'ok');
        },
        cancelDrawRoom(){ this.placingRoom = false; },
        redrawPolygon(){
            // "move pin" — re-enter placing mode for the room being edited
            this.placingRoom = true;
            this.showToast('Click the new spot for this room', 'ok');
        },
        cancelRoomEdit(){
            this.editingRoomId = null;
            this.editForm = {};
            this.placingRoom = false;
        },

        // The room currently being shaped — its polygon comes from editForm so live
        // handle/tool edits render immediately. Returns null unless editing a saved room.
        get selectedRoomObj(){
            if(!this.editForm || !this.editForm.polygon_points || this.editForm.polygon_points.length < 3) return null;
            return { room_id: this.editingRoomId, polygon_points: this.editForm.polygon_points };
        },

        // ---- Vertex dragging on the map ----
        // Repaint the overlay immediately during a drag. Alpine's x-html re-renders
        // on its own reactive cycle, which can lag behind rapid pointermove events —
        // so we paint the fresh markup straight into the host, throttled to one paint
        // per animation frame. Alpine re-syncs harmlessly on the next tick.


        // ---- Move the whole room by dragging its body ----

        // Persist just the shape after a drag, if this is a saved room. Silent (no
        // toast spam) and debounced so rapid drags don't fire many requests.
        autoSaveShape(){
            if(!this.editingRoomId || this.editingRoomId <= 0) return; // unsaved new room: wait for Save
            clearTimeout(this._shapeSaveTimer);
            this._shapeSaveTimer = setTimeout(() => { this.saveRoom(true); }, 400);
        },

        // ---- Building-rotation helpers ----
        // The building's wall angle (degrees) is stored per-site. All snap/grid/
        // straighten tools operate in this rotated "local" frame so they line up
        // with the walls, not the screen.
        get buildingAngle(){
            return (this.currentSite && this.currentSite.building_angle) ? +this.currentSite.building_angle : 0;
        },
        _rotatePoint(x, y, deg, cx, cy){
            cx = cx==null ? 50 : cx; cy = cy==null ? 50 : cy;
            const r = deg * Math.PI/180, c = Math.cos(r), s = Math.sin(r);
            const dx = x-cx, dy = y-cy;
            return { x: cx + dx*c - dy*s, y: cy + dx*s + dy*c };
        },
        // World (map %) -> local (building-aligned %): rotate by -angle around (50,50)
        worldToLocal(x, y){ return this._rotatePoint(x, y, -this.buildingAngle); },
        // Local -> world: rotate by +angle
        localToWorld(x, y){ return this._rotatePoint(x, y,  this.buildingAngle); },

        // ---- Angle measurement: click two points along a wall ----
        startAngleMeasure(){
            this.measuringAngle = true;
            this.anglePoints = [];
            this.showToast('Click two points along any straight wall', 'ok');
        },
        cancelAngleMeasure(){
            this.measuringAngle = false;
            this.anglePoints = [];
        },
        async finishAngleMeasure(){
            if(!this.currentSite || this.anglePoints.length < 2) return;
            const [a, b] = this.anglePoints;
            // angle of the wall in degrees, normalized to (-90, 90] so a flat wall reads 0
            let deg = Math.atan2(b.y - a.y, b.x - a.x) * 180 / Math.PI;
            while(deg >  90) deg -= 180;
            while(deg <= -90) deg += 180;
            try {
                const res = await fetch('?api=map&action=set_angle', {
                    method: 'POST', headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ site_number: this.currentSite.id, angle: deg })
                });
                const data = await res.json();
                if(data.success){
                    this.currentSite.building_angle = data.angle;
                    this.showToast('Building angle set to ' + data.angle.toFixed(1) + '°', 'ok');
                } else {
                    this.showToast(data.error || 'Save failed', 'err');
                }
            } catch(e){
                this.showToast('Network error', 'err');
            }
            this.measuringAngle = false;
            this.anglePoints = [];
        },

        // ---- Shape transforms (operate around the polygon centroid) ----
        _centroid(pts){
            const n = pts.length;
            return { cx: pts.reduce((s,p)=>s+p.x,0)/n, cy: pts.reduce((s,p)=>s+p.y,0)/n };
        },

        // Current angle of the selected room, estimated from its longest edge,
        // normalized to (-90, 90] so a flat room reads 0. Used by the angle field.
        get roomAngle(){
            const pts = this.editForm && this.editForm.polygon_points;
            if(!pts || pts.length<2) return 0;
            // find longest edge
            let best=-1, bang=0;
            for(let i=0;i<pts.length;i++){
                const a=pts[i], b=pts[(i+1)%pts.length];
                const dx=b.x-a.x, dy=b.y-a.y, len=dx*dx+dy*dy;
                if(len>best){ best=len; bang=Math.atan2(dy,dx)*180/Math.PI; }
            }
            while(bang> 90) bang-=180;
            while(bang<=-90) bang+=180;
            return Math.round(bang*10)/10;
        },
        // Rotate the room so its longest edge sits at the given absolute angle.
        setRoomAngle(target){
            target = parseFloat(target);
            if(isNaN(target)) return;
            const delta = target - this.roomAngle;
            if(Math.abs(delta) < 0.01) return;
            this.rotateRoom(delta);
        },

        // Snap a dragged corner in the BUILDING'S frame: alignment to nearby corners
        // and grid checks happen along the wall directions, so edges stay parallel to
        // the building no matter how tilted the map is.

        setGrid(v){ this.gridStep = Math.max(0.1, Math.min(10, Math.round(v*100)/100)); },

        // Square the room up to a rectangle aligned to the BUILDING (not the screen),
        // so straightened rooms run parallel to the walls regardless of map rotation.
        straightenRoom(){
            const pts = this.editForm.polygon_points; if(!pts || pts.length<3) return;
            // map every corner into the building's local frame
            const local = pts.map(p => this.worldToLocal(p.x, p.y));
            const xs = local.map(p=>p.x), ys = local.map(p=>p.y);
            const minx=Math.min(...xs), maxx=Math.max(...xs);
            const miny=Math.min(...ys), maxy=Math.max(...ys);
            // build axis-aligned rectangle there, then rotate back to world space
            const r2 = v => Math.round(v*100)/100;
            const corners = [
                {x:minx,y:miny},{x:maxx,y:miny},{x:maxx,y:maxy},{x:minx,y:maxy}
            ].map(p => {
                const w = this.localToWorld(p.x, p.y);
                return { x: Math.max(0, Math.min(100, r2(w.x))),
                         y: Math.max(0, Math.min(100, r2(w.y))) };
            });
            this.editForm.polygon_points = corners;
            this.autoSaveShape();
        },
        rotateRoomNoSave(deg){
            const pts = this.editForm.polygon_points; if(!pts || pts.length<3) return;
            const {cx, cy} = this._centroid(pts);
            const r = deg * Math.PI/180, cos = Math.cos(r), sin = Math.sin(r);
            this.editForm.polygon_points = pts.map(p => {
                const dx = p.x-cx, dy = p.y-cy;
                return {
                    x: Math.max(0, Math.min(100, Math.round((cx + dx*cos - dy*sin)*100)/100)),
                    y: Math.max(0, Math.min(100, Math.round((cy + dx*sin + dy*cos)*100)/100)),
                };
            });
        },
        rotateRoom(deg){
            this.rotateRoomNoSave(deg);
            this.autoSaveShape();
        },
        scaleRoom(f){
            const pts = this.editForm.polygon_points; if(!pts || pts.length<3) return;
            const {cx, cy} = this._centroid(pts);
            this.editForm.polygon_points = pts.map(p => ({
                x: Math.max(0, Math.min(100, Math.round((cx + (p.x-cx)*f)*100)/100)),
                y: Math.max(0, Math.min(100, Math.round((cy + (p.y-cy)*f)*100)/100)),
            }));
            this.autoSaveShape();
        },
        nudgeRoom(dx, dy){
            const pts = this.editForm.polygon_points; if(!pts || pts.length<3) return;
            this.editForm.polygon_points = pts.map(p => ({
                x: Math.max(0, Math.min(100, Math.round((p.x+dx)*100)/100)),
                y: Math.max(0, Math.min(100, Math.round((p.y+dy)*100)/100)),
            }));
            this.autoSaveShape();
        },

        // ---- SVG upload ----
        openSvgUpload(){
            this.showSvgUpload = true; this.svgFile = null; this.svgUploadMsg = ''; this.svgUploadErr = false;
            // Defensively clear the native file input too, so it never shows a
            // stale filename left over from a previous upload.
            this.$nextTick(() => { if(this.$refs.svgFileInput) this.$refs.svgFileInput.value = ''; });
        },
        onSvgFilePicked(ev){
            this.svgFile = ev.target.files && ev.target.files[0] ? ev.target.files[0] : null;
            // Clear the native input's value right after reading the file. Browsers
            // do NOT fire another 'change' event if you pick the exact same file
            // (same name/path) twice in a row — which silently left svgFile stuck
            // at null on a re-pick (so the Upload button stayed disabled until a
            // full page reload). Clearing the value here means the input has "no
            // file" again, so selecting that same file next time is a genuine value
            // change and fires change normally.
            ev.target.value = '';
        },
        async doSvgUpload(){
            if(!this.svgFile || !this.currentSite){ return; }
            this.svgUploading = true; this.svgUploadMsg = ''; this.svgUploadErr = false;
            try {
                const fd = new FormData();
                fd.append('file', this.svgFile);
                fd.append('site', this.currentSite.id);
                fd.append('map', this.selectedLevel || 'level-1');  // upload to the active map
                const res = await fetch('?api=map&action=upload_svg', { method:'POST', body: fd });
                const data = await res.json();
                if(data.success){
                    this.svgUploadMsg = 'Map uploaded. Reloading…';
                    this.currentSite.has_map = true;
                    // mark this map as having an SVG now
                    const mk = this.selectedLevel || 'level-1';
                    const md = (this.currentSite.maps||[]).find(m => m.key === mk);
                    if(md) md.has_svg = true;
                    // Bust the in-memory cache for this map so the new upload shows.
                    const tok = this.currentSite.id + '::' + mk;
                    if(this._svgCache) this._svgCache.delete(tok);
                    this.mapSvgLoadedForSite = null;
                    this.loadSvgForCurrentSite();
                    setTimeout(() => { this.showSvgUpload = false; }, 700);
                } else {
                    this.svgUploadErr = true; this.svgUploadMsg = data.error || 'Upload failed';
                }
            } catch(e){
                this.svgUploadErr = true; this.svgUploadMsg = 'Upload failed';
            } finally {
                this.svgUploading = false;
            }
        },

        // ---- Room import ----
        openRoomImport(){ this.showRoomImport = true; this.importJsonText=''; this.importReplace=false; this.importApplyAngle=false; this.importHasAngle=false; this.importAngleText=''; this.importMsg=''; this.importErr=false; },
        onImportFilePicked(ev){
            const f = ev.target.files && ev.target.files[0];
            if(!f) return;
            const reader = new FileReader();
            reader.onload = () => { this.importJsonText = reader.result; this._detectImportAngle(); };
            reader.readAsText(f);
        },
        // peek at the pasted/loaded JSON for a building_angle so we can offer the toggle
        _detectImportAngle(){
            this.importHasAngle = false; this.importAngleText = '';
            try {
                const p = JSON.parse(this.importJsonText);
                if(!Array.isArray(p) && p.building_angle != null && Math.abs(+p.building_angle) >= 0.5){
                    this.importHasAngle = true;
                    this.importAngleText = (+p.building_angle).toFixed(1) + '°';
                }
            } catch(e){ /* not valid yet */ }
        },
        async doRoomImport(){
            if(!this.currentSite){ return; }
            let parsed;
            try { parsed = JSON.parse(this.importJsonText); }
            catch(e){ this.importErr = true; this.importMsg = 'Invalid JSON'; return; }
            const rooms = Array.isArray(parsed) ? parsed : (parsed.rooms || []);
            if(!rooms.length){ this.importErr = true; this.importMsg = 'No rooms found in JSON'; return; }
            const levels = [...new Set(rooms.map(r => r.map_level || 'level-1'))];
            const importAngle = (this.importApplyAngle && !Array.isArray(parsed) && parsed.building_angle != null) ? +parsed.building_angle : null;
            this.importing = true; this.importMsg=''; this.importErr=false;
            try {
                const body = { site_number: this.currentSite.id, rooms };
                if(this.importReplace) body.replace_levels = levels;
                if(importAngle !== null) body.building_angle = importAngle;
                const res = await fetch('?api=room&action=import', {
                    method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)
                });
                const data = await res.json();
                if(data.success){
                    // apply detected building angle locally so wall-aligned tools match
                    if(importAngle !== null) this.currentSite.building_angle = importAngle;
                    this.importMsg = 'Imported ' + data.inserted + ' rooms' + (data.skipped ? (', skipped ' + data.skipped) : '')
                        + (importAngle !== null ? (' · angle ' + importAngle.toFixed(1) + '°') : '') + '. Reloading…';
                    await this.reloadRooms();
                    setTimeout(() => { this.showRoomImport = false; }, 900);
                } else {
                    this.importErr = true; this.importMsg = data.error || 'Import failed';
                }
            } catch(e){
                this.importErr = true; this.importMsg = 'Import failed';
            } finally {
                this.importing = false;
            }
        },
        async reloadRooms(){
            if(!this.currentSite) return;
            // Capture the site this reload is FOR. If the user switches sites before
            // the fetch resolves, we must not merge this site's rooms against the
            // new site's id (that would mix the wrong rooms / leave stale ones).
            const sid = this.currentSite.id;
            try {
                const res = await fetch('?api=room&action=list&site=' + sid);
                const data = await res.json();
                // Bail if we've navigated to a different site since the fetch started.
                if(this.currentSiteId !== sid) return;
                if(data.success){
                    // replace this site's rooms in the local array
                    this.rooms = this.rooms.filter(r => r.site_number !== sid).concat(data.rooms);
                    this.recomputeSiteCount(sid);
                }
            } catch(e){ /* non-fatal */ }
            this.loadBuildings();
        },
        async loadBuildings(){
            try {
                const res = await fetch('?api=building&action=list');
                const data = await res.json();
                if(data.success && Array.isArray(data.buildings)){
                    this.siteBuildings = data.buildings;
                } else if(data.success){
                    // API says ok but no array — treat as genuinely empty pool
                    this.siteBuildings = [];
                }
                // on an explicit failure, keep whatever we already had (don't blank)
                else if(!this.siteBuildings) this.siteBuildings = [];
            } catch(e){
                // network hiccup: preserve any existing list rather than clearing it
                if(!this.siteBuildings) this.siteBuildings = [];
            }
        },
        // ----- Room multi-select (for mass building assignment) -----
        toggleRoomSelectMode(){
            this.roomSelect.on = !this.roomSelect.on;
            this.roomSelect.ids = [];
            this.roomSelect.box = null;
            if(this.roomSelect.on){
                // selection needs pins visible
                this.showPins = true;
                // Safety net: if the pool list didn't load earlier, fetch it now so
                // the "Set building" dropdown is never mysteriously empty.
                if(!this.siteBuildings || !this.siteBuildings.length) this.loadBuildings();
                this.showToast('Drag a box over the map to grab rooms (or tap them), then pick a building', 'ok');
            }
        },
        toggleRoomSelected(roomId){
            const i = this.roomSelect.ids.indexOf(roomId);
            if(i >= 0) this.roomSelect.ids.splice(i, 1); else this.roomSelect.ids.push(roomId);
        },
        isRoomSelected(roomId){ return this.roomSelect.ids.includes(roomId); },
        selectAllVisibleRooms(){
            this.roomSelect.ids = this.roomsVisible.map(r => r.room_id);
        },
        clearRoomSelection(){ this.roomSelect.ids = []; },
        // Instantly persist ONLY the building for the room currently open in the
        // editor — lets the building dropdown save on change without needing a full
        // "Save room" click. Skips brand-new (unsaved) rooms, which have id -1 and
        // get written when the whole form is saved.
        async onEditBuildingChange(){
            const id = this.editForm.room_id;
            if(!id || id <= 0) return;                  // new room: defer to full save
            const bldg = this.editForm.building || null;
            try {
                const res = await fetch('?api=room&action=set_building', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ room_ids: [id], building: bldg })
                });
                const data = await res.json();
                if(data.success){
                    // reflect on the live room so the pin label updates immediately
                    const r = this.rooms.find(x => x.room_id === id);
                    if(r) r.building = bldg;
                    this.showToast(bldg ? ('Building set to ' + bldg) : 'Building cleared', 'ok');
                } else {
                    this.showToast(data.error || 'Could not update building', 'err');
                }
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async assignBuildingToSelection(building){
            if(!this.roomSelect.ids.length){ this.showToast('No rooms selected', 'err'); return; }
            const count = this.roomSelect.ids.length;
            try {
                const res = await fetch('?api=room&action=set_building', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ room_ids: this.roomSelect.ids, building: building || null })
                });
                const data = await res.json();
                if(data.success){
                    await this.reloadRooms();
                    const label = building ? ('building ' + building) : 'no building';
                    this.showToast('Set ' + count + ' room' + (count===1?'':'s') + ' to ' + label + ' — select more or Close', 'ok');
                    // Stay in the tool so several batches can be grouped in a row;
                    // just clear the current selection (don't turn the mode off).
                    this.roomSelect.ids = [];
                    this.roomSelect.box = null;
                } else this.showToast(data.error || 'Could not update rooms', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // ----- Building management (the managed list) -----
        async openBuildingManager(){
            this.buildingMgr = { open:true, newCode:'', newLabel:'', busy:true, genCols:8, genRows:6 };
            // Always (re)load the pool when opening so the list can't be empty due to
            // the fire-and-forget load at site-load not having resolved yet.
            await this.loadBuildings();
            this.buildingMgr.busy = false;
        },
        async generateGrid(){
            const m = this.buildingMgr;
            m.busy = true;
            try {
                const res = await fetch('?api=building&action=generate', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ col_count: Number(m.genCols), rows: Number(m.genRows) })
                });
                const data = await res.json();
                if(data.success){
                    await this.loadBuildings();
                    if(data.added > 0) this.showToast('Added ' + data.added + ' building code' + (data.added===1?'':'s'), 'ok');
                    else this.showToast('Those codes already exist — list is up to date (' + (data.total||this.siteBuildings.length) + ' total)', 'ok');
                }
                else this.showToast(data.error || 'Could not generate', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            m.busy = false;
        },
        // ----- Maps (suites / floors) management -----
        roomCountForMap(key){
            if(!this.currentSite) return 0;
            return this.rooms.filter(r => r.site_number === this.currentSite.id && (r.map_level || 'level-1') === key).length;
        },
        async openMapManager(){
            this.mapMgr = { open:true, maps:[], newName:'', busy:false };
            await this.refreshMapList();
        },
        async refreshMapList(){
            if(!this.currentSite) return;
            try {
                const res = await fetch('?api=sitemap&action=list&site=' + this.currentSite.id);
                const data = await res.json();
                let maps = (data.success && data.maps) ? data.maps : [];
                // Defensive: only ONE map may be default. If stale data ever has
                // more than one (e.g. a tied-sort seed), keep the first and clear
                // the rest in the display so the UI never shows two "Default" pills.
                let seenDefault = false;
                maps = maps.map(m => {
                    if(m.is_default){
                        if(seenDefault) m = { ...m, is_default:false };
                        else seenDefault = true;
                    }
                    return m;
                });
                this.mapMgr.maps = maps;
                // keep the site object's maps in sync (preserve is_default so the
                // switcher + next open reflect the right default without a reload).
                if(data.success) this.currentSite.maps = maps.map(m => ({ key:m.key, name:m.name, has_svg:m.has_svg, is_default:!!m.is_default, default_zoom: m.default_zoom || null, focus_x: (m.focus_x ?? null), focus_y: (m.focus_y ?? null), dot_zoom: (m.dot_zoom ?? null) }));
            } catch(e){ this.mapMgr.maps = []; }
        },
        async addMap(){
            const m = this.mapMgr;
            if(!m.newName.trim()){ this.showToast('Enter a map name', 'err'); return; }
            m.busy = true;
            try {
                const res = await fetch('?api=sitemap&action=add', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ site_number: this.currentSite.id, name: m.newName.trim() })
                });
                const data = await res.json();
                if(data.success){ m.newName=''; await this.refreshMapList(); this.showToast('Map added', 'ok'); }
                else this.showToast(data.error || 'Could not add map', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            m.busy = false;
        },
        async renameMap(mp, name){
            name = (name||'').trim();
            if(!name || name === mp.name) return;
            try {
                const res = await fetch('?api=sitemap&action=rename', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ id: mp.id, name })
                });
                const data = await res.json();
                if(data.success){ await this.refreshMapList(); this.showToast('Renamed', 'ok'); }
                else this.showToast(data.error || 'Could not rename', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async deleteMap(mp){
            const rooms = this.roomCountForMap(mp.key);
            if(rooms > 0){ this.showToast('This map has ' + rooms + ' room(s). Move or delete them first.', 'err'); return; }
            if(!confirm('Delete the map "' + mp.name + '"?')) return;
            try {
                const res = await fetch('?api=sitemap&action=delete', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ id: mp.id })
                });
                const data = await res.json();
                if(data.success){ await this.refreshMapList(); this.showToast('Map deleted', 'ok'); }
                else this.showToast(data.error || 'Could not delete map', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        // Mark a map as the site's default (shown first on open).
        // Site colour (Settings -> Site colours). '' resets to the auto palette.
        async setSiteColor(site, raw){
            const val = (raw || '').trim();
            const res = await this.api('?api=site&action=set_color',
                { site_number: site.id, color: val }, 'Could not save the site colour');
            if(!res) return;
            // Reload so the payload's palette fallback decides the colour when
            // cleared — rather than this client guessing which palette slot it was.
            if(!val){ location.reload(); return; }
            site.color = val;
            this.showToast('Colour saved for ' + site.name, 'ok');
        },
        // Settings search. Walks the rendered DOM instead of needing an x-show
        // binding on all 22 rows. Filters by SECTION, hiding every element
        // between one header and the next — not just .set-row blocks. The
        // first version only knew about .set-row, so the Buildings section
        // (a building list + grid-code generator, built from different markup)
        // was invisible to the filter and sat there through every search.
        filterSettings(){
            const q = (this.settingsQuery || '').trim().toLowerCase();
            const root = document.querySelector('#settingsBody');
            if(!root) return;
            const heads = Array.from(root.querySelectorAll('.glance-section'));
            heads.forEach(head => {
                // Everything from this header up to the next one is its content,
                // whatever markup it happens to use.
                const content = [];
                let el = head.nextElementSibling;
                while(el && !el.classList.contains('glance-section')){
                    content.push(el);
                    el = el.nextElementSibling;
                }
                if(!q){
                    head.style.display = '';
                    content.forEach(n => { n.style.display = ''; });
                    return;
                }
                const headHit = (head.textContent || '').toLowerCase().includes(q);
                let shown = 0;
                content.forEach(n => {
                    // Rows filter individually; anything that isn't a row (lists,
                    // generators, pickers) follows its section as one unit.
                    const isRow = n.classList.contains('set-row');
                    const hit = headHit || (isRow
                        ? (n.textContent || '').toLowerCase().includes(q)
                        : (head.textContent + ' ' + n.textContent).toLowerCase().includes(q));
                    n.style.display = hit ? '' : 'none';
                    if(hit) shown++;
                });
                head.style.display = (headHit || shown) ? '' : 'none';
            });
        },
        // Mini-pin threshold per map, typed as a percent: blank = default,
        // 0 = never dots on this map, anything else = that threshold.
        async setMapDotZoom(mp, raw){
            const pct = parseFloat(raw);
            const val = (raw === '' || raw === null || raw === undefined) ? null
                      : (!pct || pct <= 0 ? 0 : Math.max(0.1, Math.min(20, pct / 100)));
            const res = await this.api('?api=sitemap&action=set_dot_zoom',
                { id: mp.id, dot_zoom: val }, 'Could not save mini-pin setting');
            if(!res) return;
            const saved = (res.dot_zoom === null || res.dot_zoom === undefined) ? null : Number(res.dot_zoom);
            mp.dot_zoom = saved;
            // reflect on the live site maps so pins react immediately
            const site = this.currentSite;
            if(site && site.maps){
                const sm = site.maps.find(m => m.key === mp.key);
                if(sm) sm.dot_zoom = saved;
            }
            this.showToast(saved === null ? 'Mini pins: back to the default for this map'
                : (saved === 0 ? 'Mini pins turned off for this map'
                : ('Mini pins below ' + Math.round(saved * 100) + '% on this map')), 'ok');
        },
        async setMapZoom(mp, pctValue){
            // pctValue is a percent string/number (e.g. 500). Blank/0 clears the
            // override (back to auto-fit). We store the zoom as a multiplier.
            const pct = (pctValue === '' || pctValue === null || pctValue === undefined) ? 0 : parseFloat(pctValue);
            const zoom = (pct && pct > 0) ? Math.max(0.1, Math.min(20, pct/100)) : null;
            try {
                const res = await fetch('?api=sitemap&action=set_zoom', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ id: mp.id, zoom: zoom })
                });
                const data = await res.json();
                if(data.success){
                    const newZoom = data.default_zoom || null;
                    // reflect on the manager row + the live site maps so it sticks
                    mp.default_zoom = newZoom;
                    const site = this.currentSite;
                    if(site && site.maps){
                        const sm = site.maps.find(m => m.key === mp.key);
                        if(sm) sm.default_zoom = newZoom;
                    }
                    // if this is the map currently on screen, jump to the new zoom now
                    if(mp.key === this.selectedLevel){
                        if(newZoom) this.mapZoom = Math.max(this.ZOOM_MIN, Math.min(this.ZOOM_MAX, newZoom));
                        else this.zoomReset();
                    }
                    this.showToast(newZoom ? ('“' + mp.name + '” opens at ' + Math.round(newZoom*100) + '%') : ('“' + mp.name + '” reset to auto-fit'), 'ok');
                } else this.showToast(data.error || 'Could not save zoom', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async setDefaultMap(mp){
            if(mp.is_default) return;
            try {
                const res = await fetch('?api=sitemap&action=set_default', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ id: mp.id })
                });
                const data = await res.json();
                if(data.success){
                    // Reflect locally: clear others, set this one (manager list + the
                    // site's maps in the sidebar so it sticks without a reload).
                    this.mapMgr.maps.forEach(m => m.is_default = (m.id === mp.id));
                    const site = this.currentSite;
                    if(site && site.maps){
                        site.maps.forEach(m => m.is_default = (m.key === mp.key));
                    }
                    this.showToast('“' + mp.name + '” is now the default level', 'ok');
                } else this.showToast(data.error || 'Could not set default', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async addBuilding(){
            const m = this.buildingMgr;
            if(!m.newCode.trim()){ this.showToast('Enter a building code', 'err'); return; }
            m.busy = true;
            try {
                const res = await fetch('?api=building&action=add', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ site_number: this.currentSite.id, code: m.newCode.trim(), label: m.newLabel.trim() })
                });
                const data = await res.json();
                if(data.success){ m.newCode=''; m.newLabel=''; await this.loadBuildings(); this.showToast('Building added', 'ok'); }
                else this.showToast(data.error || 'Could not add building', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
            m.busy = false;
        },
        async deleteBuilding(b){
            const clear = confirm('Delete building "' + b.code + '"?\n\nOK = also clear it from rooms using it.\nCancel = keep this dialog (press Cancel then use the keep option).');
            try {
                const res = await fetch('?api=building&action=delete', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ id: b.id, clear_rooms: clear })
                });
                const data = await res.json();
                if(data.success){ await this.loadBuildings(); await this.reloadRooms(); this.showToast('Building removed', 'ok'); }
                else this.showToast(data.error || 'Could not delete', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },

        async saveRoom(silent){
            if(!this.editForm.room_name || !this.editForm.room_name.trim()){
                if(!silent) this.showToast('Room name is required', 'err');
                return;
            }
            const room = this.editingRoom;
            const polygon = (this.editForm.polygon_points && this.editForm.polygon_points.length>=3)
                ? this.editForm.polygon_points
                : (room && room.polygon_points && room.polygon_points.length>=3 ? room.polygon_points : []);
            const payload = {
                room_id: this.editForm.room_id || 0,
                site_number: this.currentSiteId,
                room_name: this.editForm.room_name.trim(),
                room_number: this.editForm.room_number || '',
                building: this.editForm.building || '',
                room_type: this.editForm.room_type || 'general',
                department: this.editForm.department || '',
                capacity: this.editForm.capacity || null,
                description: this.editForm.description || '',
                map_level: this.editForm.map_level || 'level-1',
                color: this.editForm.color || '',
                room_extension: this.editForm.room_extension || '',
                room_notes: this.editForm.room_notes || '',
                show_primary_contact: this.editForm.show_primary_contact ? 1 : 0,
                label_x: (this.editForm.label_x !== undefined && this.editForm.label_x !== null)
                    ? this.editForm.label_x
                    : (polygon.length ? Math.round((polygon.reduce((a,p)=>a+p.x,0)/polygon.length)*100)/100 : null),
                label_y: (this.editForm.label_y !== undefined && this.editForm.label_y !== null)
                    ? this.editForm.label_y
                    : (polygon.length ? Math.round((polygon.reduce((a,p)=>a+p.y,0)/polygon.length)*100)/100 : null),
                polygon_points: polygon,
            };
            try {
                const resp = await fetch('?api=room&action=save', {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body: JSON.stringify(payload),
                });
                const data = await resp.json();
                if(!data.success){ this.showToast(data.error || 'Save failed', 'err'); return; }
                // upsert into local rooms
                const saved = data.room;
                saved.polygon_points = Array.isArray(saved.polygon_points) ? saved.polygon_points : (saved.polygon_points ? saved.polygon_points : []);
                saved.occupants = Array.isArray(saved.occupants) ? saved.occupants : [];
                this.editingRoomId = saved.room_id;
                this.editForm.room_id = saved.room_id;

                // Save the people list too — but only on an explicit save (not the
                // silent shape auto-save), and only when editing through the panel.
                if(!silent && this.editForm.occupants !== undefined){
                    try {
                        const ocRes = await fetch('?api=occupant&action=save_all', {
                            method:'POST', headers:{'Content-Type':'application/json'},
                            body: JSON.stringify({ room_id: saved.room_id, occupants: this.editForm.occupants || [] })
                        });
                        const ocData = await ocRes.json();
                        if(ocData.success) saved.occupants = ocData.occupants;
                    } catch(e){ /* non-fatal; room itself saved */ }
                } else if(silent && room) {
                    // preserve existing occupants on the saved copy during shape saves
                    saved.occupants = room.occupants || [];
                }

                const existing = this.rooms.findIndex(r => r.room_id === saved.room_id);
                if(existing >= 0) this.rooms.splice(existing, 1, saved);
                else this.rooms.push(saved);
                this.recomputeSiteCount(saved.site_number);
                this.showToast(silent ? 'Shape saved' : 'Room saved', 'ok');
            } catch (e) {
                this.showToast('Network error', 'err');
            }
        },
        async deleteRoom(){
            const room = this.editingRoom;
            if(!room || room.room_id<=0) return;
            if(!confirm('Delete room "' + room.room_name + '"? Its devices will also be removed.')) return;
            try {
                const resp = await fetch('?api=room&action=delete', {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ room_id: room.room_id }),
                });
                const data = await resp.json();
                if(!data.success){ this.showToast(data.error || 'Delete failed', 'err'); return; }
                this.rooms = this.rooms.filter(r => r.room_id !== room.room_id);
                this.devices = this.devices.filter(d => d.room_id !== room.room_id);
                // Printers placed INSIDE this room reference it via room_id; clear
                // that reference locally so none point at a room that no longer
                // exists (and re-sync from the server to be safe).
                (this.printers || []).forEach(p => {
                    if(p.room_id === room.room_id){ p.room_id = null; p.room_pos_x = null; p.room_pos_y = null; }
                });
                this.recomputeSiteCount(room.site_number);
                this.editingRoomId = null;
                this.editForm = {};
                this.showToast('Room deleted', 'ok');
                this.reloadPrinters();
            } catch (e) {
                this.showToast('Network error', 'err');
            }
        },

        // ====================================================
        // ROOM MODAL
        // ====================================================
        closeRoomModal(){ this.roomModal = null; },
        enterRoomFromModal(){
            if(!this.roomModal) return;
            const id = this.roomModal.room_id;
            this.roomModal = null;
            this.goRoom(id);
        },

        // ====================================================
        // DEVICES
        // ====================================================
        typeMeta(key){
            return this.deviceTypes.find(t => t.key === key) || { key:key, name:key, color:'#94a3b8', icon:'box', category:'Misc' };
        },
        typeColor(key){ return this.typeMeta(key).color; },
        typeName(key){  return this.typeMeta(key).name;  },
        typeIconSvg(key){
            const ic = this.typeMeta(key).icon || 'box';
            const icons = {
                tv:        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
                projector: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="8" width="20" height="10" rx="2"/><circle cx="16" cy="13" r="2.5"/><line x1="6" y1="12" x2="6" y2="14"/></svg>',
                printer:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>',
                cart:      '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.7 13.4a2 2 0 0 0 2 1.6h9.7a2 2 0 0 0 2-1.6L23 6H6"/></svg>',
                laptop:    '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"/><line x1="2" y1="20" x2="22" y2="20"/></svg>',
                desktop:   '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>',
                phone:     '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="2" width="14" height="20" rx="2"/><line x1="12" y1="18" x2="12" y2="18"/></svg>',
                box:       '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>',
            };
            return icons[ic] || icons.box;
        },

        // One mode. "Edit Devices" and "Move Devices" used to be mutually
        // exclusive toggles, so repositioning something then correcting its
        // details meant flipping modes back and forth. Edit mode now does both:
        // drag to reposition, click to open the editor.
        toggleDeviceEdit(){
            if(!this.can('devices','edit')) return;
            this.deviceEditMode = !this.deviceEditMode;
            this.cancelUnplace();
            if(!this.deviceEditMode){
                this.placingDeviceId = null;
                this.selectedDeviceId = null;
                this.closeDeviceEditor();
            }
        },
        // Click behaviour shared by pins and list rows: select always; open the editor
        // only when in data-edit mode (never in move mode).
        focusDevice(dev){
            this.selectedDeviceId = dev.device_id;
            if(this.deviceEditMode) this.openDeviceEditor(dev);
        },
        openDeviceAdd(){
            if(!this.can('devices','edit')) return;
            this.deviceAdd.open = true;
        },
        deviceAddSingle(){ this.deviceAdd.open = false; this.openDeviceEditor(null); },
        openDeviceImport(){
            this.deviceAdd.open = false;
            this.deviceImport = { open:true, rows:[], busy:false, error:'', file:'', done:0 };
        },
        // The template carries the exact headers the parser reads, plus one
        // filled-in example row so the expected shape is obvious.
        downloadDeviceTemplate(){
            const cols = ['device_name','device_type','status','asset_tag','model','serial_number','ip_address','notes'];
            const ex = ['Room 200 TV','tv','active','BC075235','Newline Q Ultra','6942067','10.0.136.100','Mounted on the north wall'];
            const esc = (v) => /[",\n]/.test(v) ? '"' + v.replace(/"/g,'""') + '"' : v;
            const csv = cols.join(',') + '\n' + ex.map(esc).join(',') + '\n';
            const url = URL.createObjectURL(new Blob([csv], { type:'text/csv' }));
            const a = document.createElement('a');
            a.href = url; a.download = 'site-manager-devices-template.csv';
            document.body.appendChild(a); a.click(); a.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        },
        onDeviceCsv(ev){
            const file = ev.target.files && ev.target.files[0];
            ev.target.value = '';        // so the same file can be re-picked after a fix
            if(!file) return;
            this.deviceImport.file = file.name;
            const reader = new FileReader();
            reader.onload  = () => { try { this.parseDeviceCsv(String(reader.result)); }
                                     catch(e){ this.deviceImport.error = 'Could not read that CSV.'; } };
            reader.onerror = () => { this.deviceImport.error = 'Could not read the file.'; };
            reader.readAsText(file);
        },
        // Accepts either the type key ('tv') or its display name ('Classroom TV').
        _resolveDeviceType(v){
            const n = String(v || '').trim().toLowerCase();
            if(!n) return '';
            const t = (this.deviceTypes || []).find(t =>
                String(t.key).toLowerCase() === n || String(t.name).toLowerCase() === n);
            return t ? t.key : '';
        },
        parseDeviceCsv(text){
            const rows = this._csvToRows(text);   // the printer importer's RFC-4180 parser
            this.deviceImport.rows = []; this.deviceImport.error = '';
            if(!rows.length){ this.deviceImport.error = 'That file looks empty.'; return; }
            const header = rows[0].map(h => String(h || '').trim().toLowerCase());
            const col = (n) => header.indexOf(n);
            const idx = { name:col('device_name'), type:col('device_type'), status:col('status'),
                          asset:col('asset_tag'), model:col('model'), sn:col('serial_number'),
                          ip:col('ip_address'), notes:col('notes') };
            if(idx.name < 0){
                this.deviceImport.error = 'No “device_name” column found — download the template to see the expected headers.';
                return;
            }
            const get = (r, i) => (i >= 0 && r[i] != null) ? String(r[i]).trim() : '';
            const out = [];
            for(let i = 1; i < rows.length; i++){
                const r = rows[i];
                if(!r || r.every(v => !String(v || '').trim())) continue;   // blank line
                const name = get(r, idx.name);
                const typeRaw = get(r, idx.type);
                const typeKey = this._resolveDeviceType(typeRaw);
                out.push({
                    device_name:name, device_type_key: typeKey || 'other', typeRaw,
                    typeUnknown: !!typeRaw && !typeKey,
                    status:(get(r, idx.status) || 'active').toLowerCase(),
                    asset_tag:get(r, idx.asset), model:get(r, idx.model),
                    serial_number:get(r, idx.sn), ip_address:get(r, idx.ip), notes:get(r, idx.notes),
                    error: name ? '' : 'Needs a device_name',
                });
            }
            if(!out.length){ this.deviceImport.error = 'No rows found under the header row.'; return; }
            this.deviceImport.rows = out;
        },
        get deviceImportReady(){ return this.deviceImport.rows.filter(r => !r.error).length; },
        async runDeviceImport(){
            if(!this.currentRoomId){ this.showToast('Open a room first', 'err'); return; }
            const rows = this.deviceImport.rows.filter(r => !r.error);
            if(!rows.length) return;
            this.deviceImport.busy = true; this.deviceImport.done = 0;
            let ok = 0, failed = 0;
            for(const r of rows){
                // Deliberately reuses the same ?api=device&action=save endpoint as
                // the single-device editor rather than adding a bulk path whose
                // validation could drift out of sync. Raw fetch (not the api()
                // helper) so a bad row doesn't fire a toast per row — one summary
                // at the end instead.
                try {
                    const resp = await fetch('?api=device&action=save', {
                        method:'POST', headers:{'Content-Type':'application/json'},
                        body: JSON.stringify({ device_id:0, room_id:this.currentRoomId,
                            device_name:r.device_name, device_type_key:r.device_type_key,
                            status:r.status || 'active', asset_tag:r.asset_tag, model:r.model,
                            serial_number:r.serial_number, ip_address:r.ip_address, notes:r.notes })
                    });
                    const data = await resp.json();
                    if(data.success && data.device){
                        const s = data.device;
                        s.device_id = parseInt(s.device_id, 10);
                        s.room_id   = parseInt(s.room_id, 10);
                        s.pos_x = s.pos_x !== null ? parseFloat(s.pos_x) : null;
                        s.pos_y = s.pos_y !== null ? parseFloat(s.pos_y) : null;
                        this.devices.push(s); ok++;
                    } else { failed++; r.error = data.error || 'Rejected by the server'; }
                } catch(e){ failed++; r.error = 'Network error'; }
                this.deviceImport.done++;
            }
            this.deviceImport.busy = false;
            if(!failed){
                this.deviceImport.open = false;
                this.showToast(ok + ' device' + (ok === 1 ? '' : 's') + ' imported', 'ok');
            } else {
                // Keep the dialog open so the failed rows stay visible.
                this.deviceImport.rows = this.deviceImport.rows.filter(r => r.error);
                this.showToast(ok + ' imported, ' + failed + ' failed — see the rows still listed', 'err');
            }
        },
        openDeviceEditor(d){
            if(d){
                this.deviceEditor = {
                    open:true,
                    device_id: d.device_id,
                    room_id: d.room_id,
                    device_name: d.device_name,
                    device_type_key: d.device_type_key,
                    status: d.status || 'active',
                    asset_tag: d.asset_tag || '',
                    model: d.model || '',
                    serial_number: d.serial_number || '',
                    ip_address: d.ip_address || '',
                    notes: d.notes || '',
                };
            } else {
                this.deviceEditor = {
                    open:true,
                    device_id:0,
                    room_id: this.currentRoomId,
                    device_name:'',
                    device_type_key: this.deviceTypes[0]?.key || 'other',
                    status:'active',
                    asset_tag:'', model:'', serial_number:'', ip_address:'', notes:'',
                };
            }
        },
        closeDeviceEditor(){
            this.deviceEditor = {open:false, device_id:0, room_id:0, device_name:'', device_type_key:'other', status:'active', asset_tag:'', model:'', serial_number:'', ip_address:'', notes:''};
        },
        async saveDevice(){
            if(!this.deviceEditor.device_name.trim()){
                this.showToast('Device name is required', 'err');
                return;
            }
            const payload = { ...this.deviceEditor, room_id: this.currentRoomId };
            delete payload.open;
            try {
                const resp = await fetch('?api=device&action=save', {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body: JSON.stringify(payload),
                });
                const data = await resp.json();
                if(!data.success){ this.showToast(data.error || 'Save failed', 'err'); return; }
                const saved = data.device;
                saved.device_id = parseInt(saved.device_id, 10);
                saved.room_id   = parseInt(saved.room_id, 10);
                saved.pos_x = saved.pos_x !== null ? parseFloat(saved.pos_x) : null;
                saved.pos_y = saved.pos_y !== null ? parseFloat(saved.pos_y) : null;
                const idx = this.devices.findIndex(d => d.device_id === saved.device_id);
                if(idx >= 0) this.devices.splice(idx, 1, saved);
                else this.devices.push(saved);
                this.recomputeSiteCount(this.currentSite?.id);
                // If new and not placed, enter placing mode
                if(saved.pos_x === null || saved.pos_y === null){
                    this.placingDeviceId = saved.device_id;
                    this.showToast('Device saved — click on the canvas to place it', 'ok');
                } else {
                    this.showToast('Device saved', 'ok');
                }
                this.closeDeviceEditor();
            } catch (e) {
                this.showToast('Network error', 'err');
            }
        },
        async deleteDevice(){
            if(!this.deviceEditor.device_id) return;
            if(!confirm('Delete device "' + this.deviceEditor.device_name + '"?')) return;
            try {
                const resp = await fetch('?api=device&action=delete', {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ device_id: this.deviceEditor.device_id }),
                });
                const data = await resp.json();
                if(!data.success){ this.showToast(data.error || 'Delete failed', 'err'); return; }
                this.devices = this.devices.filter(d => d.device_id !== this.deviceEditor.device_id);
                this.recomputeSiteCount(this.currentSite?.id);
                this.closeDeviceEditor();
                this.showToast('Device deleted', 'ok');
            } catch (e) {
                this.showToast('Network error', 'err');
            }
        },

        // ---- Device placement on the stage ----
        onStageClick(ev){
            // In shape-trace mode, a bare canvas click does NOT add corners (that was
            // too easy to trigger while moving pins). Corners are added only via the
            // "+" handles between points. Clicking empty canvas just deselects/no-ops.
            if(this.shapeEdit.active){
                return;
            }
            if(!this.deviceEditMode) return;
            if(!this.placingDeviceId) return;
            const stage = this.$refs.stage;
            if(!stage) return;
            const rect = stage.getBoundingClientRect();
            const x = this._pctX(ev.clientX, rect);
            const y = this._pctY(ev.clientY, rect);
            const dev = this.devices.find(d => d.device_id === this.placingDeviceId);
            if(!dev) { this.placingDeviceId = null; return; }
            dev.pos_x = Math.round(x*100)/100;
            dev.pos_y = Math.round(y*100)/100;
            this.savePositionsDebounced([{device_id: dev.device_id, pos_x: dev.pos_x, pos_y: dev.pos_y}]);
            this.placingDeviceId = null;
            this.selectedDeviceId = dev.device_id;
        },
        // ---- Room interior shape ("trace the room") ----
        _stagePct(ev){
            const stage = this.$refs.stage;
            if(!stage) return null;
            const rect = stage.getBoundingClientRect();
            return {
                x: Math.round(this._pctX(ev.clientX, rect) * 10) / 10,
                y: Math.round(this._pctY(ev.clientY, rect) * 10) / 10,
            };
        },
        // Snap a point to the nearest grid intersection (when snap is on).
        _snapToGrid(p){
            if(!this.shapeEdit.snap) return p;
            const step = 100 / this.shapeEdit.gridSize;          // grid cell size in %
            return {
                x: Math.round(Math.round(p.x / step) * step * 10) / 10,
                y: Math.round(Math.round(p.y / step) * step * 10) / 10,
            };
        },
        // Apply snap to grid, then angle-lock to the previous point so near-straight
        // walls become exactly horizontal/vertical. Returns a new point.
        // CSS background for the visible grid overlay.
        get shapeGridStyle(){
            const step = 100 / this.shapeEdit.gridSize;
            return 'background-size:' + step + '% ' + step + '%;';
        },
        toggleShapeGrid(){ this.shapeEdit.grid = !this.shapeEdit.grid; },
        toggleShapeSnap(){ this.shapeEdit.snap = !this.shapeEdit.snap; },
        setGridSize(v){ this.shapeEdit.gridSize = Math.max(6, Math.min(50, Math.round(parseFloat(v) || 20))); },
        // The shape to render: the saved polygon, or a sensible default rectangle.
        get currentRoomShape(){
            const r = this.currentRoom;
            if(r && Array.isArray(r.room_shape) && r.room_shape.length >= 3) return r.room_shape;
            return this._defaultRoomRect();
        },
        _defaultRoomRect(){ return [{x:8,y:10},{x:92,y:10},{x:92,y:90},{x:8,y:90}]; },
        // points → "x%,y% x%,y% ..." for an SVG <polygon points>
        shapePointsAttr(pts){ return (pts||[]).map(p => p.x + ',' + p.y).join(' '); },
        startShapeEdit(){
            if(!this.can('base','edit')) return;
            const existing = (this.currentRoom && Array.isArray(this.currentRoom.room_shape) && this.currentRoom.room_shape.length >= 3)
                ? this.currentRoom.room_shape : this._defaultRoomRect();
            this.shapeEdit.active = true;
            this.shapeEdit.points = existing.map(p => ({ x:p.x, y:p.y }));
            this.shapeEdit.dragIdx = null;
            // turn off device placement/edit while tracing
            this.placingDeviceId = null;
            this.deviceEditMode = false;
            // Load the site floor plan as a backdrop and center it on this room's pin.
            this.loadSvgForCurrentSite();
            const room = this.currentRoom;
            const pin = room ? this.labelPosition(room) : { x:50, y:50 };
            this.shapeEdit.backdrop = !!(this.currentSite && this.currentSite.has_map);
            this.shapeEdit.bgZoom = 5;          // start zoomed in on the room
            this.shapeEdit.bgX = pin.x;         // map % point to keep centered
            this.shapeEdit.bgY = pin.y;
        },
        cancelShapeEdit(){ this.shapeEdit.active = false; this.shapeEdit.points = []; this.shapeEdit.dragIdx = null; },
        undoShapePoint(){ this.shapeEdit.points.pop(); },
        clearShapePoints(){ this.shapeEdit.points = []; },
        resetShapeToRect(){ this.shapeEdit.points = this._defaultRoomRect().map(p => ({x:p.x,y:p.y})); },
        // Bounding box of the current trace points.
        _shapeBounds(){
            const pts = this.shapeEdit.points;
            if(!pts.length) return null;
            const xs = pts.map(p => p.x), ys = pts.map(p => p.y);
            const minX = Math.min(...xs), maxX = Math.max(...xs);
            const minY = Math.min(...ys), maxY = Math.max(...ys);
            return { minX, maxX, minY, maxY, w: maxX - minX, h: maxY - minY, cx: (minX+maxX)/2, cy: (minY+maxY)/2 };
        },
        // Move the shape (unchanged size) so its centre sits at the canvas centre.
        centerShape(){
            const b = this._shapeBounds();
            if(!b) return;
            const dx = 50 - b.cx, dy = 50 - b.cy;
            this.shapeEdit.points = this.shapeEdit.points.map(p => ({
                x: Math.round(Math.max(0, Math.min(100, p.x + dx)) * 10) / 10,
                y: Math.round(Math.max(0, Math.min(100, p.y + dy)) * 10) / 10,
            }));
        },
        // Center AND scale the shape to fill ~84% of the canvas (keeps aspect ratio).
        fitShape(){
            const b = this._shapeBounds();
            if(!b || b.w === 0 || b.h === 0) return;
            const target = 84; // % of canvas to fill on the larger dimension
            const scale = Math.min(target / b.w, target / b.h);
            this.shapeEdit.points = this.shapeEdit.points.map(p => ({
                x: Math.round(Math.max(0, Math.min(100, 50 + (p.x - b.cx) * scale)) * 10) / 10,
                y: Math.round(Math.max(0, Math.min(100, 50 + (p.y - b.cy) * scale)) * 10) / 10,
            }));
        },
        removeShapePoint(i){ if(this.shapeEdit.points.length > 3) this.shapeEdit.points.splice(i, 1); },
        // Midpoints of every edge (including the closing edge) — the "+" insert handles.
        get shapeMidpoints(){
            const pts = this.shapeEdit.points;
            if(pts.length < 3) return [];
            const mids = [];
            for(let i = 0; i < pts.length; i++){
                const a = pts[i], b = pts[(i + 1) % pts.length];
                mids.push({ x: (a.x + b.x) / 2, y: (a.y + b.y) / 2, after: i });
            }
            return mids;
        },
        // Insert a new corner on the edge after `afterIndex` (splits that edge in two).
        insertShapePoint(afterIndex){
            const pts = this.shapeEdit.points;
            const a = pts[afterIndex], b = pts[(afterIndex + 1) % pts.length];
            if(!a || !b) return;
            pts.splice(afterIndex + 1, 0, {
                x: Math.round(((a.x + b.x) / 2) * 10) / 10,
                y: Math.round(((a.y + b.y) / 2) * 10) / 10,
            });
        },
        // Grab an edge "+" handle: insert a corner there and immediately drag it.
        startMidDrag(afterIndex, ev){
            ev.preventDefault(); ev.stopPropagation();
            this.insertShapePoint(afterIndex);
            this._suppressNextStageClick = true; // don't also append on the trailing click
            this.startVtxDrag(afterIndex + 1, ev);
        },
        startVtxDrag(i, ev){
            ev.preventDefault(); ev.stopPropagation();
            this.shapeEdit.dragIdx = i;
            const move = (e) => {
                let p = this._stagePct(e);
                if(p && this.shapeEdit.points[i]){
                    // angle-lock relative to the neighbours on each side of this corner
                    const pts = this.shapeEdit.points;
                    const prev = pts[(i - 1 + pts.length) % pts.length];
                    const next = pts[(i + 1) % pts.length];
                    p = this._snapToGrid(p);
                    if(this.shapeEdit.snap){
                        const tol = 4;
                        // prefer aligning to whichever neighbour is closest to straight
                        const cand = [];
                        if(prev){ cand.push({axis:'x', ref:prev.x, d:Math.abs(p.x-prev.x)}); cand.push({axis:'y', ref:prev.y, d:Math.abs(p.y-prev.y)}); }
                        if(next){ cand.push({axis:'x', ref:next.x, d:Math.abs(p.x-next.x)}); cand.push({axis:'y', ref:next.y, d:Math.abs(p.y-next.y)}); }
                        cand.sort((a,b)=>a.d-b.d);
                        for(const c of cand){ if(c.d <= tol){ if(c.axis==='x') p.x = c.ref; else p.y = c.ref; break; } }
                    }
                    this.shapeEdit.points[i].x = Math.max(0, Math.min(100, p.x));
                    this.shapeEdit.points[i].y = Math.max(0, Math.min(100, p.y));
                }
            };
            const up = () => { this.shapeEdit.dragIdx = null; document.removeEventListener('pointermove', move); document.removeEventListener('pointerup', up); };
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', up);
        },
        // ---- Trace backdrop (faint floor plan you frame yourself) ----
        // Positions the floor-plan layer so the (bgX,bgY) map-% point sits at the
        // canvas centre, scaled by bgZoom. The layer is bgZoom× the canvas size.
        get shapeBgStyle(){
            const z = this.shapeEdit.bgZoom;
            // layer is z× canvas; shift so the focus point lands at 50% of the canvas
            const tx = 50 - this.shapeEdit.bgX * z;
            const ty = 50 - this.shapeEdit.bgY * z;
            return 'width:' + (z*100) + '%;height:' + (z*100) + '%;left:' + tx + '%;top:' + ty + '%;'
                 + 'opacity:' + this.shapeEdit.bgOpacity + ';';
        },
        setShapeZoom(v){ this.shapeEdit.bgZoom = Math.max(1, Math.min(14, parseFloat(v) || 1)); },
        setBgOpacity(v){ this.shapeEdit.bgOpacity = Math.max(0.1, Math.min(1, parseFloat(v) || 0.35)); },
        toggleShapeBackdrop(){ this.shapeEdit.backdrop = !this.shapeEdit.backdrop; },
        toggleShapeLock(){ this.shapeEdit.locked = !this.shapeEdit.locked; },
        // drag to pan the backdrop (move the focus point opposite the drag)
        startBgPan(ev){
            // only pan when the click is on empty canvas/backdrop, not a corner handle
            if(ev.target.closest('.shape-vtx')) return;
            if(!this.shapeEdit.backdrop) return;
            if(this.shapeEdit.locked) return; // map is locked in place
            const stage = this.$refs.stage;
            if(!stage) return;
            const rect = stage.getBoundingClientRect();
            const z = this.shapeEdit.bgZoom;
            const startX = ev.clientX, startY = ev.clientY;
            const ox = this.shapeEdit.bgX, oy = this.shapeEdit.bgY;
            let moved = false;
            const move = (e) => {
                const dxPct = ((e.clientX - startX) / rect.width)  * 100;
                const dyPct = ((e.clientY - startY) / rect.height) * 100;
                if(Math.abs(e.clientX-startX) > 3 || Math.abs(e.clientY-startY) > 3) moved = true;
                // dividing by z converts canvas-% movement into map-% movement
                this.shapeEdit.bgX = Math.max(0, Math.min(100, ox - dxPct / z));
                this.shapeEdit.bgY = Math.max(0, Math.min(100, oy - dyPct / z));
            };
            const up = () => {
                document.removeEventListener('pointermove', move);
                document.removeEventListener('pointerup', up);
                // suppress the click-to-add-point that would fire after a real drag
                if(moved) this._suppressNextStageClick = true;
            };
            document.addEventListener('pointermove', move);
            document.addEventListener('pointerup', up);
        },
        async saveShape(){
            if(this.shapeEdit.points.length < 3){ this.showToast('Add at least 3 corners (or use a rectangle)', 'err'); return; }
            const room = this.currentRoom;
            if(!room) return;
            try {
                const res = await fetch('?api=room&action=save_shape', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ room_id: room.room_id, room_shape: this.shapeEdit.points })
                });
                const data = await res.json();
                if(data.success){
                    room.room_shape = data.room_shape;       // update in-memory
                    this.shapeEdit.active = false;
                    this.showToast('Room shape saved', 'ok');
                } else this.showToast(data.error || 'Could not save shape', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        async clearSavedShape(){
            const room = this.currentRoom;
            if(!room) return;
            if(!confirm('Remove this room shape and go back to the default rectangle?')) return;
            try {
                const res = await fetch('?api=room&action=save_shape', {
                    method:'POST', headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ room_id: room.room_id, room_shape: null })
                });
                const data = await res.json();
                if(data.success){ room.room_shape = null; this.cancelShapeEdit(); this.showToast('Shape cleared', 'ok'); }
                else this.showToast(data.error || 'Could not clear', 'err');
            } catch(e){ this.showToast('Network error', 'err'); }
        },
        onDevicePinClick(dev){
            if(this._consumePinDrag()) return;   // finished a drag, not a click
            this.focusDevice(dev);
        },
        onPrinterPinClick(pr){
            if(this._consumePinDrag()) return;   // finished a drag, not a click
            this.openPrinterInfo(pr);
        },
        startDevicePointerDrag(dev, ev){
            if(!this.deviceEditMode) return;   // dragging lives in edit mode
            ev.preventDefault();
            this._beginPinDrag(ev);
            this.selectedDeviceId = dev.device_id;
            const stage = this.$refs.stage;
            if(!stage) return;
            const rect = stage.getBoundingClientRect();
            const onMove = (e) => {
                this._notePinDragMove(e);
                const x = this._pctX(e.clientX, rect);
                const y = this._pctY(e.clientY, rect);
                dev.pos_x = Math.round(x*100)/100;
                dev.pos_y = Math.round(y*100)/100;
            };
            const onUp = () => {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup',   onUp);
                this.savePositionsDebounced([{device_id: dev.device_id, pos_x: dev.pos_x, pos_y: dev.pos_y}]);
            };
            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup',   onUp);
        },
        // Drag a device straight from the right-column list onto the canvas to place it.
        startDeviceListDrag(dev, ev){
            if(!this.canEdit) return;
            // left button / touch only
            if(ev.button !== undefined && ev.button !== 0) return;
            const stage = this.$refs.stage;
            const canPlace = this.deviceEditMode && !!stage;  // drag-to-place lives in edit mode
            const startX = ev.clientX, startY = ev.clientY;
            let dragging = false;
            const threshold = 5; // px before it counts as a drag (so plain clicks still work)

            const pctFromEvent = (e) => {
                const rect = stage.getBoundingClientRect();
                return {
                    inside: e.clientX >= rect.left && e.clientX <= rect.right && e.clientY >= rect.top && e.clientY <= rect.bottom,
                    x: this._pctX(e.clientX, rect),
                    y: this._pctY(e.clientY, rect),
                };
            };

            const onMove = (e) => {
                if(!canPlace) return;   // no drag-to-place unless in Move mode
                if(!dragging){
                    if(Math.abs(e.clientX - startX) < threshold && Math.abs(e.clientY - startY) < threshold) return;
                    // begin the drag
                    dragging = true;
                    this.listDrag = { active:true, dev, x:e.clientX, y:e.clientY, over:false };
                    this.selectedDeviceId = dev.device_id;
                }
                const pos = pctFromEvent(e);
                this.listDrag.x = e.clientX;
                this.listDrag.y = e.clientY;
                this.listDrag.over = pos.inside;
            };
            const onUp = (e) => {
                document.removeEventListener('pointermove', onMove);
                document.removeEventListener('pointerup',   onUp);
                if(dragging){
                    const pos = pctFromEvent(e);
                    if(pos.inside){
                        dev.pos_x = Math.round(pos.x * 100) / 100;
                        dev.pos_y = Math.round(pos.y * 100) / 100;
                        this.savePositionsDebounced([{ device_id: dev.device_id, pos_x: dev.pos_x, pos_y: dev.pos_y }]);
                        this.showToast('Placed ' + (dev.device_name || 'device'), 'ok');
                    }
                    this.listDrag = { active:false, dev:null, x:0, y:0, over:false };
                } else {
                    // no real drag → click: open editor in data mode, otherwise just select
                    this.focusDevice(dev);
                }
            };
            document.addEventListener('pointermove', onMove);
            document.addEventListener('pointerup',   onUp);
        },

        _savePositionsTimer:null,
        _pendingPositions:[],
        savePositionsDebounced(batch){
            // Merge batches; only keep latest pos per device
            batch.forEach(p => {
                this._pendingPositions = this._pendingPositions.filter(x => x.device_id !== p.device_id);
                this._pendingPositions.push(p);
            });
            if(this._savePositionsTimer) clearTimeout(this._savePositionsTimer);
            this._savePositionsTimer = setTimeout(() => this.flushPositionSaves(), 350);
        },
        async flushPositionSaves(){
            const positions = this._pendingPositions.slice();
            this._pendingPositions = [];
            if(!positions.length) return;
            try {
                const resp = await fetch('?api=device&action=save_positions', {
                    method:'POST',
                    headers:{'Content-Type':'application/json'},
                    body: JSON.stringify({ positions }),
                });
                const data = await resp.json();
                if(!data.success) this.showToast('Position save failed', 'err');
            } catch (e) {
                this.showToast('Network error saving positions', 'err');
            }
        },

        // ====================================================
        // MISC
        // ====================================================
        recomputeSiteCount(siteId){
            if(!siteId) return;
            const siteRooms = this.rooms.filter(r => r.site_number === siteId);
            const ids = siteRooms.map(r => r.room_id);
            const devs = this.devices.filter(d => ids.includes(d.room_id));
            this.siteCounts[siteId] = { rooms: siteRooms.length, devices: devs.length };
        },

        showToast(msg, kind){
            this.toast = { show:true, msg, kind: kind || 'ok' };
            if(this._toastTimer) clearTimeout(this._toastTimer);
            this._toastTimer = setTimeout(() => { this.toast.show = false; }, 2800);
        },

        onEscape(){
            if(this.drawingRoom){ this.cancelDrawRoom(); return; }
            if(this.placingDeviceId){ this.placingDeviceId = null; return; }
            if(this.roomModal){ this.closeRoomModal(); return; }
            if(this.deviceEditor.open){ this.closeDeviceEditor(); return; }
            if(this.editingRoomId){ this.cancelRoomEdit(); return; }
        },
    };
}
