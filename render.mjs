const drive = '/?nfc=1'
const esc = input => String(input).replace(/[&<>"']/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[char])
const href = city => '/filial/' + city.id + '/'
const icon = (name, size = 20) => {
  const paths = {
    map: '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3zm6-3v15m6-12v15"/>',
    east: '<path d="M4 12h15m-6-6 6 6-6 6"/>',
    west: '<path d="M20 12H5m6-6-6 6 6 6"/>',
    external: '<path d="M7 17 17 7M7 7h10v10"/>',
    search: '<circle cx="10.5" cy="10.5" r="5.5"/><path d="m15 15 4 4"/>',
    copy: '<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 4H6a2 2 0 0 0-2 2v10"/>',
    nfc: '<path d="M9 9a4 4 0 0 1 0 6m3-9a8 8 0 0 1 0 12m3-15a12 12 0 0 1 0 18"/><circle cx="6" cy="12" r="1"/>',
    pin: '<path d="M19 10c0 5-7 10-7 10s-7-5-7-10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/>',
    file: '<path d="M6 3h8l4 4v14H6zM14 3v5h4M9 13h6m-6 4h6"/>',
    person: '<circle cx="12" cy="8" r="3"/><path d="M5 20v-2a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v2"/>',
    lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3"/>',
    folder: '<path d="M3 7a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"/>',
  }
  return '<svg width="'+size+'" height="'+size+'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+paths[name]+'</svg>'
}
const external = 'target="_blank" rel="noopener noreferrer"'
const financeLink = city => '/admin/?next=finance' + (city ? '&amp;city='+esc(city.id) : '')
function financeGate(city, assigned) {
  if (assigned) return '<div class="report finance-gate"><h2>Финансы филиала</h2><p>Ваш логин даёт доступ только к ДДС своего филиала.</p><a class="copy" href="/filial/'+esc(assigned)+'/">Открыть свой филиал</a></div>'
  return '<div class="report finance-gate"><h2>Финансы филиала</h2><p>Отчёты ДДС доступны директорам после входа.</p><a class="copy" href="'+financeLink(city)+'">Войти для просмотра</a></div>'
}
function page(title, main, isMap = false) {
  const navigation = isMap ? '' : '<a class="top-map" href="/">'+icon('map',16)+'<span>Карта филиалов</span></a>'
  const credit = '<a href="https://www.geoboundaries.org/api/current/gbOpen/KAZ/ADM1/" '+external+'>Карта · geoBoundaries / OSM</a>'
  return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><meta name="theme-color" content="#141311"><title>'+esc(title)+' · Branch Atlas</title><link rel="stylesheet" href="/style.css"><script defer src="/app.js"></script></head><body'+(isMap ? ' class="map-page"' : '')+'><header class="top"><div class="top-inner"><a class="brand" href="/" aria-label="Branch Atlas — главная"><img src="/brand.svg" width="122" height="40" alt="Branch Atlas"></a>'+navigation+'<a class="top-admin" href="/admin/">Админка</a><a class="top-drive" href="/admin/?next=finance">'+icon('lock',16)+'<span>Финансы</span></a></div></header>'+main+'<footer class="footer">'+(isMap ? credit : '<span>© 2026 Branch Atlas</span>')+'</footer></body></html>'
}

// Lambert conformal conic projection centered on Kazakhstan.
const rad = v => v * Math.PI / 180
const tangent = v => Math.tan(Math.PI / 4 + v / 2)
const phi1 = rad(43)
const phi2 = rad(52)
const n = Math.log(Math.cos(phi1) / Math.cos(phi2)) / Math.log(tangent(phi2) / tangent(phi1))
const F = Math.cos(phi1) * tangent(phi1) ** n / n
export function project([lon, lat]) {
  const radius = F / tangent(rad(lat)) ** n
  const theta = n * rad(lon - 68)
  return [radius * Math.sin(theta), radius * Math.cos(theta)]
}

export function makeRenderer(bounds, documents, budgets) {
  const scale = Math.min(695 / (bounds[2] - bounds[0]), 395 / (bounds[3] - bounds[1]))
  function xy(point) {
    const [x, y] = project(point)
    return [410 + (x - (bounds[0] + bounds[2])/2) * scale, 252 + (y - (bounds[1] + bounds[3])/2) * scale]
  }

  const country = '<use class="country" href="/kazakhstan.svg#regions"/>'
  const labels = { north:[-14,18,'end'],central:[14,20],south:[12,20] }

  function home(cities) {
  const wall = cities.filter(city => city.wall)
  const pins = wall.map((city, index) => {
    const [x, y] = xy([city.lon,city.lat])
    const [dx,dy,align='start'] = labels[city.id] ?? [0, -13, "middle"]
    const label = city.name
    const width = 10 * label.length
    const left = Math.min(-18, dx - (align === 'end' ? width : align === 'middle' ? width / 2 : 0))
    const right = Math.max(18, dx + (align === 'start' ? width : align === 'middle' ? width / 2 : 0))
    const top = Math.min(-18, dy - 17)
    const bottom = Math.max(18, dy + 5)
    return '<a class="marker" data-city="'+city.id+'" href="'+href(city)+'" aria-label="'+esc(city.name)+'" transform="translate('+x+' '+y+')"><rect fill="transparent" x="'+left+'" y="'+top+'" width="'+(right-left)+'" height="'+(bottom-top)+'"/><rect class="banner-target" fill="transparent" x="-20" y="-51" width="40" height="58"/><circle class="pulse" r="11" style="--delay:'+index*0.24+'s"/><ellipse class="flag-base" rx="8" ry="4"/><use class="city-banner" href="#atlas-banner"/><text x="'+dx+'" y="'+dy+'" text-anchor="'+align+'">'+esc(label)+'</text></a>'
  }).join('')
  const banner = '<defs><linearGradient id="banner-cloth"><stop stop-color="#352a22"/><stop offset=".48" stop-color="#54402c"/><stop offset="1" stop-color="#261e19"/></linearGradient><g id="atlas-banner"><path class="banner-pole" d="M0 0v-40"/><circle class="banner-finial" cy="-47" r="2.4"/><path class="banner-cloth" d="M-12-42h24v26l-12 7-12-7z"/><path class="banner-stitch" d="M-9-39h18v21L0-13l-9-5z"/><svg x="-8" y="-35" width="16" height="16" viewBox=".45 0 10.8 10.96" overflow="hidden"><image href="/brand.svg" width="33.35" height="10.96"/></svg></g></defs>'
  const depth = [10,7,4,2].map(level => '<g class="map-depth" data-depth="'+level+'">'+country+'</g>').join('')
  const map = '<svg class="map-drawing" viewBox="0 0 820 500" role="group" aria-label="Интерактивная карта городов Казахстана">'+banner+depth+'<g class="map-table">'+country+'<text class="water" x="67" y="345">КАСПИЙ</text><text class="map-label" x="400" y="286" text-anchor="middle">ҚАЗАҚСТАН</text></g><g class="city-banners">'+pins+'</g></svg>'
  const cityRows = cities.map(city => {
    const search = (city.name + ' ' + city.region).toLowerCase().replaceAll('ё','е')
    return '<a class="city-row" href="'+href(city)+'" data-city="'+city.id+'" data-search="'+esc(search)+'"><span class="city-icon">'+icon('pin',16)+'</span><span><strong>'+esc(city.name)+'</strong><small>'+(city.wall ? esc(city.region) : 'Дополнительный город')+'</small></span>'+icon('east',17)+'</a>'
  }).join('')
  const views = '<div class="map-views" hidden><div class="view-buttons" role="group" aria-label="Вид карты"><button type="button" data-map-angle="0" aria-pressed="true">Сверху</button><button type="button" data-map-angle="34" aria-pressed="false">Под углом</button></div><input type="range" id="map-tilt" min="0" max="55" value="0" aria-label="Наклон карты" aria-valuetext="0°"></div>'
  return '<main class="map-panel" aria-label="Карта Казахстана"><div class="map-stage">'+map+'</div><section class="intro"><h1>Карта филиалов</h1><p>Казахстан <span aria-hidden="true">·</span> <a href="/?nfc=1">'+wall.length+' NFC-меток</a></p></section><details class="city-index"><summary>'+icon('search',17)+'<span>Найти город</span></summary><div class="city-popover"><label class="search">'+icon('search',17)+'<input id="city-search" type="search" autocomplete="off" placeholder="Название города" aria-label="Поиск города"></label><div class="city-list">'+cityRows+'<p class="empty-search" hidden>Город не найден.</p></div></div></details>'+views+'<div class="zoom-controls"><button type="button" data-zoom="in" aria-label="Приблизить">+</button><button type="button" data-zoom="out" aria-label="Отдалить" disabled>−</button><button type="button" data-zoom="reset" aria-label="Вернуть масштаб" title="Вернуть масштаб" disabled>⌖</button></div><div class="map-legend"><i></i> NFC-метка на стенде</div></main>'


  }

  const money = value => Number.isFinite(value) ? new Intl.NumberFormat('ru-KZ',{maximumFractionDigits:2}).format(value) + ' ₸' : '—'
  const specialNames = {}
  function report(id, index) {
    const doc = documents[id] ?? {url:"/sample-report.html",title:"Таблица ДДС"+(index ? " "+(index+1) : "")}
    const {period, months, receipts, expenses, net} = budgets[id] ?? {period:"",months:["—","—","—"],receipts:[null,null,null],expenses:[null,null,null],net:[null,null,null]}
    const rows = [['Поступления',receipts],['Расходы',expenses],['Чистый денежный поток',net]]
      .map(([label, values]) => '<tr><th scope="row">'+label+'</th>'+values.map(value => '<td>'+money(value)+'</td>').join('')+'</tr>').join('')
    return '<article class="report" data-report="'+esc(id)+'"><div class="report-head"><span class="report-icon">'+icon('file',22)+'</span><div><h3>'+esc(specialNames[id] || doc.title)+'</h3><p data-period>'+esc(period)+' · план в тенге</p></div><a class="source-link" href="'+esc(doc.url)+'" '+external+'><span>Исходник</span>'+icon('external',16)+'</a></div><div class="table-wrap"><table><thead><tr><th scope="col">Показатель</th>'+months.map(month=>'<th scope="col">'+month+'</th>').join('')+'</tr></thead><tbody>'+rows+'</tbody></table></div><p class="footnote" data-budget-status '+(budgets[id] ? 'data-has-values="true" ' : '')+'aria-live="polite">'+(budgets[id] ? 'Демонстрационные данные' : 'Загрузка отчёта…')+'</p></article>'
  }
  function branch(city, finance = false, assigned = '') {
    const [x, y] = xy([city.lon, city.lat])
    const viewBox = [x - 530, y - 133, 780, 460].join(' ')
    const backdrop = '<div class="branch-atlas" aria-hidden="true"><svg viewBox="'+viewBox+'">'+country+'<g class="branch-location" transform="translate('+x+' '+y+')"><circle r="21" class="location-halo"/><circle r="11" class="pulse"/><circle r="7" class="pin-disc"/><circle r="2.5" class="pin-core"/><text y="27" text-anchor="middle">'+esc(city.name)+'</text></g></svg></div>'
    const offices = city.address ? '<div class="office"><strong>Адрес</strong><span class="branch-address">'+esc(city.address)+'</span></div>' : ''
    const tag = city.wall ? '<span class="tag">'+icon('nfc',16)+' NFC-метка</span>' : ''
    const reports = finance && city.files.length ? city.files.map(report).join('') : '<div class="no-data">'+icon('folder',28)+'<p>Для этого города пока нет отчёта.</p><a href="'+drive+'" '+external+'>К карте филиалов '+icon('external',15)+'</a></div>'
    return backdrop+'<main class="frame branch"><a class="back" href="/">'+icon('west',17)+' К карте филиалов</a><section class="branch-hero"><div><p class="branch-kicker">'+icon('pin',17)+' '+esc(city.region)+'</p><h1>'+esc(city.name)+'</h1>'+tag+'</div><div class="branch-actions"><button data-copy class="copy" type="button">'+icon('copy',17)+'<span>Копировать ссылку</span></button><input readonly hidden id="copy-link" aria-label="Ссылка для NFC"><span id="copy-result" class="sr-only" role="status"></span></div></section><div class="branch-content"><section class="financial"'+(finance ? ' data-budget-branch="'+city.id+'"><h2>Движение денежных средств</h2>'+reports : '>'+financeGate(city,assigned))+'</section><aside class="profile" data-bitrix-branch="'+city.id+'"><h2>О филиале</h2><div class="person"><span class="mini" data-manager-placeholder>'+icon('person',21)+'</span><div class="manager-photos" data-manager-photos hidden></div><div aria-live="polite"><strong>Руководитель</strong><p data-manager>Загрузка…</p></div></div><div class="person"><span class="mini">'+icon('person',21)+'</span><div><strong>Активные сотрудники</strong><p data-staff>—</p></div></div><details class="team" data-team open hidden><summary>Команда филиала</summary><ul></ul></details>'+offices+'</aside></div></main>'
  }
  return { xy, home, branch, page, report }
}
