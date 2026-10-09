<!doctype html>
<html lang="de">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Beleg-Check · Werkbank</title>
<style>
  :root {
    --bg: #f6f5f2; --panel: #ffffff; --ink: #1d1d1b; --muted: #6b6a66; --line: #e3e1db;
    --accent: #1f4e79; --gruen: #2e7d4f; --gelb: #b7791f; --rot: #b3261e; --grau: #8a8984;
    --gruen-bg: #e6f2ea; --gelb-bg: #fbf1de; --rot-bg: #fbe5e3; --grau-bg: #efeeea;
  }
  * { box-sizing: border-box; }
  body { margin: 0; background: var(--bg); color: var(--ink); font: 14px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; }
  header { padding: 20px 28px 8px; display: flex; align-items: baseline; gap: 14px; }
  header h1 { margin: 0; font-size: 20px; letter-spacing: -.01em; }
  header span { color: var(--muted); }
  main { padding: 8px 28px 40px; max-width: 1400px; }

  .drop { border: 2px dashed var(--line); border-radius: 12px; background: var(--panel); padding: 28px; text-align: center; transition: border-color .15s, background .15s; }
  .drop.over { border-color: var(--accent); background: #eef3f8; }
  .drop p { margin: 0 0 14px; color: var(--muted); }
  .drop strong { color: var(--ink); }
  button, .btn { font: inherit; border: 1px solid var(--line); background: var(--panel); color: var(--ink); padding: 7px 14px; border-radius: 8px; cursor: pointer; }
  button:hover, .btn:hover { border-color: var(--accent); }
  button.primary { background: var(--accent); color: #fff; border-color: var(--accent); }
  button:disabled { opacity: .5; cursor: default; }
  input[type=file] { display: none; }

  .leiste { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 18px 0 10px; }
  .zahl { padding: 4px 10px; border-radius: 999px; font-weight: 600; font-size: 13px; }
  .leiste .platz { flex: 1; }
  .fortschritt { color: var(--muted); }

  table { width: 100%; border-collapse: collapse; background: var(--panel); border: 1px solid var(--line); border-radius: 10px; overflow: hidden; }
  th, td { padding: 8px 10px; text-align: left; border-bottom: 1px solid var(--line); vertical-align: top; }
  th[data-sort] { cursor: pointer; user-select: none; }
  th[data-sort]:hover { color: var(--ink); }
  th[data-richtung=auf]::after { content: ' ▲'; font-size: 9px; }
  th[data-richtung=ab]::after { content: ' ▼'; font-size: 9px; }
  th { font-size: 12px; font-weight: 600; color: var(--muted); background: #faf9f6; white-space: nowrap; }
  tr.beleg { cursor: pointer; }
  tr.beleg:hover td { background: #fbfaf8; }
  td.num { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  td.datei { max-width: 260px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  td.mono { white-space: nowrap; }
  .mono { font-family: ui-monospace, "Cascadia Mono", Consolas, monospace; font-size: 12.5px; }

  .ampel { display: inline-block; min-width: 64px; text-align: center; padding: 2px 8px; border-radius: 999px; font-weight: 600; font-size: 12px; }
  .gruen { background: var(--gruen-bg); color: var(--gruen); }
  .gelb  { background: var(--gelb-bg);  color: var(--gelb); }
  .rot   { background: var(--rot-bg);   color: var(--rot); }
  .grau  { background: var(--grau-bg);  color: var(--grau); }
  .dublette { color: var(--rot); font-weight: 600; font-size: 12px; }

  tr.detail td { background: #fbfaf8; padding: 14px 18px 18px; }
  .ergebnis { display: grid; grid-template-columns: 90px 110px 1fr; gap: 4px 12px; margin: 0 0 14px; }
  .ergebnis div { padding: 2px 0; }
  .stufe-ok { color: var(--gruen); } .stufe-hinweis { color: var(--gelb); } .stufe-auffaellig { color: var(--gelb); font-weight: 600; }
  .stufe-widerspruch { color: var(--rot); font-weight: 600; } .stufe-nicht_pruefbar { color: var(--grau); }
  .vergleich { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; padding-top: 10px; border-top: 1px solid var(--line); }
  .vergleich label { display: flex; flex-direction: column; font-size: 12px; color: var(--muted); gap: 3px; }
  .vergleich input { font: inherit; padding: 6px 8px; border: 1px solid var(--line); border-radius: 6px; width: 150px; }
  .qrroh { margin-top: 10px; word-break: break-all; color: var(--muted); }
  .leer { color: var(--muted); text-align: center; padding: 28px; }
  .fehler { color: var(--rot); }
  .quelle { font-size: 10.5px; color: var(--muted); border: 1px solid var(--line); border-radius: 4px; padding: 0 4px; margin-left: 4px; }
  .quelle.unsicher { color: var(--gelb); border-color: var(--gelb); }
  details.text { margin-top: 12px; }
  details.text summary { cursor: pointer; color: var(--muted); }
  details.text pre { background: var(--panel); border: 1px solid var(--line); border-radius: 6px; padding: 10px; max-height: 320px; overflow: auto; font-size: 12px; }
  .detailgitter { display: grid; grid-template-columns: 340px 1fr; gap: 22px; align-items: start; }
  .original { position: sticky; top: 12px; }
  .original img { width: 100%; border: 1px solid var(--line); border-radius: 6px; background: #fff; cursor: zoom-in; display: block; margin-bottom: 8px; }
  #lupe { position: fixed; inset: 0; background: rgba(20,20,18,.82); z-index: 20; overflow: auto; display: none; padding: 24px 0 60px; }
  #lupe.offen { display: block; }
  #lupe img { display: block; margin: 0 auto; width: min(760px, 94vw); background: #fff; border-radius: 4px; cursor: zoom-in; }
  #lupe img.breit { width: min(1400px, 98vw); cursor: zoom-out; }
  #lupe .zu { position: fixed; top: 14px; right: 20px; background: #fff; border: 0; border-radius: 999px; width: 36px; height: 36px; font-size: 18px; }
  #lupe .tipp { position: fixed; bottom: 12px; left: 50%; transform: translateX(-50%); color: #fff; background: rgba(0,0,0,.7); padding: 4px 12px; border-radius: 999px; font-size: 12px; }
  .gemerkt { color: var(--gruen); font-size: 13px; align-self: center; }
  .meta { font-size: 12px; color: var(--muted); }
  @media (max-width: 900px) { .detailgitter { grid-template-columns: 1fr; } .original { position: static; } }
  footer { color: var(--muted); font-size: 12px; margin-top: 16px; }
</style>
</head>
<body>
<header>
  <h1>Beleg-Check</h1><span>Werkbank · Prototyp · Sprint 2 · Bilder werden nicht gespeichert, nur Kassendaten grüner/bestätigter Belege</span>
</header>
<main>
  <div class="drop" id="drop">
    <p><strong>Belege oder ganze Ordner hierher ziehen</strong><br>JPG, PNG, HEIC, PDF · mehrere auf einmal</p>
    <button class="primary" id="btnDateien">Dateien wählen</button>
    <button id="btnOrdner">Ordner wählen</button>
    <input type="file" id="inDateien" multiple accept=".jpg,.jpeg,.png,.heic,.heif,.webp,.pdf">
    <input type="file" id="inOrdner" webkitdirectory multiple>
  </div>

  <div class="leiste">
    <span class="zahl gruen" id="nGruen">0 grün</span>
    <span class="zahl gelb" id="nGelb">0 gelb</span>
    <span class="zahl rot" id="nRot">0 rot</span>
    <span class="zahl grau" id="nOhne">0 ohne QR</span>
    <span class="fortschritt" id="fortschritt"></span>
    <span class="platz"></span>
    <button id="btnCsv" disabled>Als CSV speichern</button>
    <button id="btnLeeren" disabled>Liste leeren</button>
  </div>

  <table>
    <thead>
      <tr id="kopf">
        <th data-sort="datei">Datei</th><th data-sort="ampel">Ampel</th><th data-sort="aussteller">Aussteller</th>
        <th data-sort="datum">Datum/Uhrzeit</th><th data-sort="kasse">Kassen-ID</th><th data-sort="belegnr">Belegnummer</th>
        <th data-sort="summe" class="num">Summe QR</th><th data-sort="gedruckt" class="num">Gedruckt</th><th data-sort="hinweis">Hinweis</th>
      </tr>
    </thead>
    <tbody id="liste"><tr><td colspan="9" class="leer">Noch keine Belege geprüft.</td></tr></tbody>
  </table>
  <footer>Spaltenkopf anklicken zum Sortieren (Ampel: Rot zuerst). Zeile anklicken für Details mit dem Original (Bild anklicken vergrößert). Dort kannst du die gedruckten Werte eintragen und den Beleg erneut gegen den QR-Code prüfen.</footer>
</main>

<div id="lupe" onclick="if (event.target === this) lupeZu()">
  <button class="zu" onclick="lupeZu()" title="Schließen (Esc)">✕</button>
  <img id="lupeBild" alt="Original" onclick="this.classList.toggle('breit')">
  <div class="tipp">Scrollen für den ganzen Beleg · Bild anklicken für noch größer · Esc schließt</div>
</div>

<script>
function lupe(src) { const b = document.getElementById('lupeBild'); b.src = src; b.classList.remove('breit'); const l = document.getElementById('lupe'); l.classList.add('offen'); l.scrollTop = 0; document.body.style.overflow = 'hidden'; }
function lupeZu() { document.getElementById('lupe').classList.remove('offen'); document.body.style.overflow = ''; }
document.addEventListener('keydown', e => { if (e.key === 'Escape') lupeZu(); });
const CSRF = document.querySelector('meta[name=csrf-token]').content;
const ERLAUBT = /\.(jpe?g|png|heic|heif|webp|pdf)$/i;
const PARALLEL = 2;
const belege = [];          // {id, name, status, daten, gedruckt}
const warteschlange = [];
let laufend = 0;

const $ = id => document.getElementById(id);
const eur = c => c == null ? '' : (c / 100).toLocaleString('de-AT', { minimumFractionDigits: 2 }) + ' €';
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
const AMPEL_TEXT = { gruen: 'grün', gelb: 'gelb', rot: 'rot' };

// ---------- Dateien sammeln ----------
$('btnDateien').onclick = () => $('inDateien').click();
$('btnOrdner').onclick = () => $('inOrdner').click();
$('inDateien').onchange = e => { hinzufuegen([...e.target.files]); e.target.value = ''; };
$('inOrdner').onchange = e => { hinzufuegen([...e.target.files]); e.target.value = ''; };

const drop = $('drop');
['dragenter', 'dragover'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.add('over'); }));
['dragleave', 'drop'].forEach(t => drop.addEventListener(t, e => { e.preventDefault(); drop.classList.remove('over'); }));
drop.addEventListener('drop', async e => {
  const eintraege = [...e.dataTransfer.items].map(i => i.webkitGetAsEntry && i.webkitGetAsEntry()).filter(Boolean);
  const dateien = [];
  for (const eintrag of eintraege) await einsammeln(eintrag, dateien);
  hinzufuegen(dateien);
});

// Ordner rekursiv durchlaufen
async function einsammeln(eintrag, ziel) {
  if (eintrag.isFile) {
    ziel.push(await new Promise(r => eintrag.file(r)));
  } else if (eintrag.isDirectory) {
    const leser = eintrag.createReader();
    let teil;
    do {
      teil = await new Promise(r => leser.readEntries(r));
      for (const kind of teil) await einsammeln(kind, ziel);
    } while (teil.length);
  }
}

function hinzufuegen(dateien) {
  const passend = dateien.filter(d => ERLAUBT.test(d.name));
  for (const datei of passend) {
    const b = { id: belege.length, name: datei.webkitRelativePath || datei.name, status: 'wartet', daten: null, gedruckt: {} };
    belege.push(b);
    warteschlange.push({ b, datei });
  }
  zeichnen();
  weiter();
}

// ---------- Prüfen ----------
function weiter() {
  while (laufend < PARALLEL && warteschlange.length) {
    const { b, datei } = warteschlange.shift();
    laufend++;
    b.status = 'prüft';
    zeichnen();
    hochladen(b, datei).finally(() => { laufend--; zeichnen(); weiter(); });
  }
}

async function hochladen(b, datei) {
  const fd = new FormData();
  fd.append('datei', datei);
  try {
    const r = await fetch('/pruefen', { method: 'POST', body: fd, headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' } });
    const j = await r.json();
    if (!r.ok) throw new Error(j.fehler || j.message || ('HTTP ' + r.status));
    b.daten = j;
    b.status = 'fertig';
  } catch (e) {
    b.status = 'fehler';
    b.fehler = e.message;
  }
}

async function nachpruefen(b) {
  const r = await fetch('/nachpruefen', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ qr_text: b.daten.qr_text, forensik: b.daten.forensik, uid: b.daten.uid, aussteller: b.daten.aussteller, datei_sha256: b.daten.datei_sha256, ...b.gedruckt }),
  });
  const j = await r.json();
  if (r.ok) { b.daten = { ...b.daten, ...j }; zeichnen(); }
}

// ---------- Dubletten innerhalb der Liste ----------
function dubletten() {
  const nachDatei = {}, nachFiskal = {};
  for (const b of belege) {
    if (!b.daten) continue;
    (nachDatei[b.daten.datei_sha256] ??= []).push(b);
    if (b.daten.fiskal_schluessel) (nachFiskal[b.daten.fiskal_schluessel] ??= []).push(b);
  }
  const hinweise = {};
  for (const gruppe of Object.values(nachDatei)) if (gruppe.length > 1)
    for (const b of gruppe) hinweise[b.id] = 'Gleiche Datei wie ' + gruppe.filter(x => x !== b).map(x => x.name).join(', ');
  for (const gruppe of Object.values(nachFiskal)) if (gruppe.length > 1)
    for (const b of gruppe) hinweise[b.id] ??= 'Gleicher Kassenbeleg wie ' + gruppe.filter(x => x !== b).map(x => x.name).join(', ');
  const nachInhalt = {};
  for (const b of belege) if (b.daten?.inhalt_hash) (nachInhalt[b.daten.inhalt_hash] ??= []).push(b);
  for (const gruppe of Object.values(nachInhalt)) if (gruppe.length > 1)
    for (const b of gruppe) hinweise[b.id] ??= 'Gleiche Rechnung (Inhalt) wie ' + gruppe.filter(x => x !== b).map(x => x.name).join(', ');
  const mitText = belege.filter(b => b.daten?.text_hash);
  for (const a of mitText) for (const c of mitText)
    if (a !== c && bitAbstand(a.daten.text_hash, c.daten.text_hash) <= 6)
      hinweise[a.id] ??= 'Sehr ähnlicher Text wie ' + c.name + ' (zweites Foto?)';
  return hinweise;
}

// ---------- Anzeige ----------
let offen = null;

// ---------- Sortierung ----------
let sortSpalte = null, sortRichtung = 1;
// Ampel: rot zuerst (bei absteigend), dann nach Risikowert
const AMPEL_RANG = b => b.status === 'fehler' ? 5 : !b.daten ? -2 : !b.daten.qr ? 0 : ({ gruen: 1, gelb: 2, rot: 3 }[b.daten.ampel] ?? 0);
const SORT_WERT = {
  datei: b => b.name.toLowerCase(),
  ampel: b => AMPEL_RANG(b) * 1000 + (b.daten?.risikowert ?? 0),
  aussteller: b => (b.daten?.aussteller ?? '').toLowerCase(),
  datum: b => b.daten?.qr?.datum_uhrzeit?.replace('T', ' ') ?? b.daten?.gedruckt?.datum_uhrzeit ?? '',
  kasse: b => b.daten?.qr?.kassen_id ?? '',
  belegnr: b => b.daten?.qr?.belegnummer ?? '',
  summe: b => b.daten?.qr?.summe_cent ?? -1,
  gedruckt: b => b.daten?.gedruckt?.gesamt_cent ?? -1,
  hinweis: (b, dub) => (dub[b.id] ? 'zz' : '') + (b.daten?.ergebnisse ?? []).filter(e => !['ok', 'nicht_pruefbar'].includes(e.stufe)).length,
};

function sortiert(dub) {
  if (!sortSpalte) return belege;
  const f = SORT_WERT[sortSpalte];
  return [...belege].sort((a, b) => {
    const x = f(a, dub), y = f(b, dub);
    return (x < y ? -1 : x > y ? 1 : a.id - b.id) * sortRichtung;
  });
}

document.querySelectorAll('#kopf th[data-sort]').forEach(th => th.onclick = () => {
  const spalte = th.dataset.sort;
  if (sortSpalte === spalte) sortRichtung = -sortRichtung;
  else { sortSpalte = spalte; sortRichtung = ['ampel', 'summe', 'gedruckt', 'hinweis'].includes(spalte) ? -1 : 1; }
  document.querySelectorAll('#kopf th').forEach(t => t.dataset.richtung = '');
  th.dataset.richtung = sortRichtung === 1 ? 'auf' : 'ab';
  zeichnen();
});

function zeichnen() {
  const tb = $('liste');
  if (!belege.length) {
    tb.innerHTML = '<tr><td colspan="9" class="leer">Noch keine Belege geprüft.</td></tr>';
  } else {
    const dub = dubletten();
    tb.innerHTML = sortiert(dub).map(b => zeile(b, dub[b.id]) + (offen === b.id && b.daten ? detail(b) : '')).join('');
  }

  const fertig = belege.filter(b => b.daten);
  const zaehle = f => fertig.filter(f).length;
  $('nGruen').textContent = zaehle(b => b.daten.qr && b.daten.ampel === 'gruen') + ' grün';
  $('nGelb').textContent = zaehle(b => b.daten.qr && b.daten.ampel === 'gelb') + ' gelb';
  $('nRot').textContent = zaehle(b => b.daten.qr && b.daten.ampel === 'rot') + ' rot';
  $('nOhne').textContent = zaehle(b => !b.daten.qr) + ' ohne QR';
  const offenZahl = belege.filter(b => b.status === 'wartet' || b.status === 'prüft').length;
  $('fortschritt').textContent = offenZahl ? `prüfe … noch ${offenZahl}` : (belege.length ? `${belege.length} Belege` : '');
  $('btnCsv').disabled = !fertig.length;
  $('btnLeeren').disabled = !belege.length || offenZahl > 0;
}

function zeile(b, dublette) {
  const d = b.daten, q = d?.qr;
  let ampel;
  if (b.status === 'fehler') ampel = '<span class="ampel rot">Fehler</span>';
  else if (!d) ampel = `<span class="ampel grau">${b.status}</span>`;
  else if (!q) ampel = '<span class="ampel grau">ohne QR</span>';
  else ampel = `<span class="ampel ${d.ampel}">${AMPEL_TEXT[d.ampel]} · ${d.risikowert}</span>`;

  const auffaellig = d?.ergebnisse.filter(e => !['ok', 'nicht_pruefbar'].includes(e.stufe)) ?? [];
  const hinweis = b.status === 'fehler' ? `<span class="fehler">${esc(b.fehler)}</span>`
    : dublette ? `<span class="dublette">${esc(dublette)}</span>`
    : esc(auffaellig.map(e => e.code).join(', '));

  return `<tr class="beleg" onclick="umschalten(${b.id})">
    <td class="datei" title="${esc(b.name)}">${esc(b.name)}</td>
    <td>${ampel}</td>
    <td class="datei" title="${esc(d?.aussteller)}">${esc(d?.aussteller)}</td>
    <td class="mono">${esc(q?.datum_uhrzeit?.replace('T', ' ') ?? d?.gedruckt?.datum_uhrzeit)}</td>
    <td class="mono">${esc(q?.kassen_id)}</td>
    <td class="mono">${esc(q?.belegnummer)}</td>
    <td class="num">${eur(q?.summe_cent)}</td>
    <td class="num">${gedrucktZelle(b)}</td>
    <td>${hinweis}</td>
  </tr>`;
}

function gedrucktZelle(b) {
  if (b.gedruckt.gesamt) return esc(b.gedruckt.gesamt) + ' <span class="quelle">Hand</span>';
  const g = b.daten?.gedruckt;
  if (g?.gesamt_cent == null) return '';
  const unsicher = (g.lesesicherheit?.gesamt ?? 1) < 0.8;
  return eur(g.gesamt_cent) + ` <span class="quelle${unsicher ? ' unsicher' : ''}">${b.daten.text_quelle === 'pdf-text' ? 'PDF' : 'OCR'}</span>`;
}

function bitAbstand(a, b) {
  let x = BigInt('0x' + a) ^ BigInt('0x' + b), n = 0;
  while (x) { n += Number(x & 1n); x >>= 1n; }
  return n;
}

function detail(b) {
  const d = b.daten;
  const RANG = { widerspruch: 0, auffaellig: 1, hinweis: 2, nicht_pruefbar: 3, ok: 4 };
  const sortiert = [...d.ergebnisse].sort((a, b) => RANG[a.stufe] - RANG[b.stufe]);
  const ergebnisse = sortiert.map(e => `
    <div class="mono">${esc(e.code)}</div>
    <div class="stufe-${e.stufe}">${esc(e.stufe.replace('_', ' '))}</div>
    <div>${esc(e.begruendung)}</div>`).join('');
  const auto = d.gedruckt || {};
  const g = {
    gesamt: b.gedruckt.gesamt ?? (auto.gesamt_cent != null ? (auto.gesamt_cent / 100).toFixed(2).replace('.', ',') : ''),
    datum_uhrzeit: b.gedruckt.datum_uhrzeit ?? (auto.datum_uhrzeit ? auto.datum_uhrzeit.replace(/^(\d{4})-(\d{2})-(\d{2})/, '$3.$2.$1') : ''),
    kassen_id: b.gedruckt.kassen_id ?? auto.kassen_id ?? '',
  };
  const textBlock = d.text ? `<details class="text" onclick="event.stopPropagation()"><summary>Erkannter Text (${d.text_quelle === 'pdf-text' ? 'aus PDF' : 'Texterkennung, Sicherheit ' + Math.round((d.text_sicherheit ?? 0) * 100) + ' %'}${d.uid ? ', UID ' + esc(d.uid) : ''})</summary><pre>${esc(d.text)}</pre></details>` : '';
  const vergleich = d.qr ? `
    <div class="vergleich" onclick="event.stopPropagation()">
      <label>Gedruckter Gesamtbetrag (aus Text vorbefüllt)<input id="g_gesamt" placeholder="92,60" value="${esc(g.gesamt ?? '')}"></label>
      <label>Gedrucktes Datum/Uhrzeit<input id="g_datum" placeholder="05.10.2026 22:37" value="${esc(g.datum_uhrzeit ?? '')}"></label>
      <label>Gedruckte Kassen-ID<input id="g_kasse" placeholder="Pos10918" value="${esc(g.kassen_id ?? '')}"></label>
      <button class="primary" onclick="gedrucktPruefen(${b.id})">Mit Gedrucktem vergleichen</button>
      ${d.im_gedaechtnis ? '<span class="gemerkt">✓ im Kassen-Gedächtnis</span>' : `<button onclick="bestaetigen(${b.id})" title="Beleg ist in Ordnung – Kasse und Belegnummer ins Gedächtnis übernehmen">Als in Ordnung bestätigen</button>`}
    </div>
    <div class="qrroh mono">QR-Inhalt: ${esc(d.qr_text)}</div>` : '';
  const bilder = (d.vorschau || []).map((v, i) =>
    `<img src="data:image/jpeg;base64,${v}" alt="Seite ${i + 1}" onclick="event.stopPropagation(); lupe(this.src)" title="Klicken zum Vergrößern">`).join('');
  const f = d.forensik?.metadaten || {};
  const meta = d.forensik ? `<div class="meta">Datei: ${esc([f.hersteller, f.modell].filter(Boolean).join(' ') || 'keine Kameraangaben')}${f.software ? ' · Software: ' + esc(f.software) : ''}${f.aufnahme ? ' · Aufnahme: ' + esc(f.aufnahme) : ''}</div>` : '';
  return `<tr class="detail"><td colspan="9"><div class="detailgitter">
    <div class="original">${bilder || '<div class="leer">keine Vorschau</div>'}${meta}</div>
    <div><div class="ergebnis">${ergebnisse}</div>${vergleich}${textBlock}</div>
  </div></td></tr>`;
}

window.umschalten = id => { offen = offen === id ? null : id; zeichnen(); };
window.gedrucktPruefen = id => {
  const b = belege[id];
  b.gedruckt = { gesamt: $('g_gesamt').value.trim(), datum_uhrzeit: $('g_datum').value.trim(), kassen_id: $('g_kasse').value.trim() };
  nachpruefen(b);
};

window.bestaetigen = async id => {
  const b = belege[id], d = b.daten;
  const r = await fetch('/bestaetigen', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ qr_text: d.qr_text, uid: d.uid, aussteller: d.aussteller, datei_sha256: d.datei_sha256 }),
  });
  if (r.ok) { d.im_gedaechtnis = (await r.json()).im_gedaechtnis; zeichnen(); }
};

$('btnLeeren').onclick = () => { belege.length = 0; offen = null; zeichnen(); };

// ---------- CSV (Testsatz) ----------
$('btnCsv').onclick = () => {
  const kopf = ['datei', 'ampel', 'risikowert', 'aussteller', 'uid', 'datum_uhrzeit', 'kassen_id', 'belegnummer', 'summe_qr', 'gedruckt_gesamt', 'auffaellig', 'qr_text'];
  const zeilen = belege.filter(b => b.daten).map(b => {
    const d = b.daten, q = d.qr || {};
    return [b.name, d.qr ? d.ampel : 'ohne_qr', d.risikowert, d.aussteller, d.uid, q.datum_uhrzeit ?? d.gedruckt?.datum_uhrzeit, q.kassen_id, q.belegnummer,
      q.summe_cent != null ? (q.summe_cent / 100).toFixed(2).replace('.', ',') : '', b.gedruckt.gesamt ?? (d.gedruckt?.gesamt_cent != null ? (d.gedruckt.gesamt_cent / 100).toFixed(2).replace('.', ',') : ''),
      d.ergebnisse.filter(e => !['ok', 'nicht_pruefbar'].includes(e.stufe)).map(e => e.code).join(' '), d.qr_text ?? ''];
  });
  const csv = [kopf, ...zeilen].map(z => z.map(f => '"' + String(f ?? '').replace(/"/g, '""') + '"').join(';')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8' }));
  a.download = 'belegcheck-' + new Date().toISOString().slice(0, 10) + '.csv';
  a.click();
};
</script>
</body>
</html>
