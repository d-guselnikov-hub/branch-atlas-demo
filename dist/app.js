const menu = document.querySelector('.city-index')
if (menu) {
  const search = menu.querySelector('input')
  const rows = [...menu.querySelectorAll('.city-row')]
  const empty = menu.querySelector('.empty-search')
  search.addEventListener('input', () => {
    const query = search.value.trim().toLocaleLowerCase('ru').replaceAll('ё', 'е')
    for (const row of rows) row.hidden = !row.dataset.search.includes(query)
    empty.hidden = rows.some(row => !row.hidden)
  })
  document.addEventListener('pointerdown', event => {
    if (!menu.contains(event.target)) menu.open = false
  })
  menu.addEventListener('keydown', event => {
    if (event.key === 'Escape') {
      menu.open = false
      menu.querySelector('summary').focus()
    }
  })

  const map = document.querySelector('.map-drawing')
  const viewpoints = document.querySelector('.map-views')
  const tiltInput = document.querySelector('#map-tilt')
  const viewButtons = [...viewpoints.querySelectorAll('[data-map-angle]')]
  const table = map.querySelector('.map-table')
  const depthLayers = [...map.querySelectorAll('.map-depth')]
  const banners = [...map.querySelectorAll('.marker')].map(marker => {
    const {e:x, f:y} = marker.transform.baseVal.getItem(0).matrix
    return {marker,x,y}
  })
  const tiltOrigin = 252
  let tilt = 0
  let tiltAnimation = 0
  function projectTable(angle) {
    tilt = angle
    const radians = angle * Math.PI / 180
    const depth = Math.sin(radians)
    const height = Math.cos(radians)
    const shear = -.22 * depth
    const matrix = 'matrix(1 0 '+shear+' '+height+' '+(-tiltOrigin * shear)+' '+(tiltOrigin * (1 - height))+')'
    table.setAttribute('transform',matrix)
    for (const layer of depthLayers) {
      layer.style.opacity = depth
      layer.setAttribute('transform','translate(0 '+(Number(layer.dataset.depth) * depth)+') '+matrix)
    }
    for (const {marker,x,y} of banners) {
      marker.setAttribute('transform','translate('+(x + (y - tiltOrigin) * shear)+' '+(tiltOrigin + (y - tiltOrigin) * height)+')')
    }
  }
  function changeTilt(angle, animate = false) {
    cancelAnimationFrame(tiltAnimation)
    tiltInput.value = angle
    tiltInput.setAttribute('aria-valuetext',angle+'°')
    for (const button of viewButtons) {
      button.setAttribute('aria-pressed',String(Number(button.dataset.mapAngle) === angle))
    }
    if (!animate || matchMedia('(prefers-reduced-motion: reduce)').matches) {
      projectTable(angle)
      return
    }
    const from = tilt
    const start = performance.now()
    function frame(now) {
      const progress = Math.min(1,(now - start) / 420)
      projectTable(from + (angle - from) * (1 - (1 - progress) ** 3))
      if (progress < 1) tiltAnimation = requestAnimationFrame(frame)
    }
    tiltAnimation = requestAnimationFrame(frame)
  }
  tiltInput.addEventListener('input', () => changeTilt(Number(tiltInput.value)))
  for (const button of viewButtons) {
    button.addEventListener('click', () => changeTilt(Number(button.dataset.mapAngle),true))
  }
  changeTilt(matchMedia('(max-width: 680px)').matches ? 0 : 34)
  viewpoints.hidden = false
  const controls = document.querySelector('.zoom-controls')
  const zoomIn = controls.querySelector('[data-zoom="in"]')
  const zoomOut = controls.querySelector('[data-zoom="out"]')
  const reset = controls.querySelector('[data-zoom="reset"]')
  const original = map.viewBox.baseVal
  const size = { width: original.width, height: original.height }
  let center = { x: size.width / 2, y: size.height / 2 }
  let zoom = 1
  function draw() {
    const width = size.width / zoom
    const height = size.height / zoom
    const x = center.x - width / 2
    const y = center.y - height / 2
    map.setAttribute('viewBox', [x, y, width, height].join(' '))
    zoomIn.disabled = zoom === 4
    zoomOut.disabled = zoom === 1
    reset.disabled = zoom === 1 && x === 0 && y === 0
  }
  function setZoom(value, clientPoint) {
    const next = Math.max(1, Math.min(4, value))
    if (next === zoom) return
    const point = clientPoint
      ? new DOMPoint(clientPoint.x, clientPoint.y).matrixTransform(map.getScreenCTM().inverse())
      : center
    center = {
      x: point.x + (center.x - point.x) * zoom / next,
      y: point.y + (center.y - point.y) * zoom / next,
    }
    zoom = next
    if (zoom === 1) center = { x: size.width / 2, y: size.height / 2 }
    draw()
  }
  zoomIn.addEventListener('click', () => setZoom(zoom * 1.4))
  zoomOut.addEventListener('click', () => setZoom(zoom / 1.4))
  reset.addEventListener('click', () => {
    zoom = 1
    center = { x: size.width / 2, y: size.height / 2 }
    draw()
  })
  map.addEventListener('wheel', event => {
    if (event.ctrlKey || event.metaKey) return
    event.preventDefault()
    setZoom(zoom * (event.deltaY > 0 ? .89 : 1.12), {x:event.clientX, y:event.clientY})
  }, {passive:false})

  const pointers = new Map()
  let dragging = false
  let ignoreClick = false
  const pointAt = event => ({ x: event.clientX, y: event.clientY })
  const distance = (a, b) => Math.hypot(a.x - b.x, a.y - b.y)
  map.addEventListener('pointerdown', event => {
    if (event.button !== 0 || pointers.size >= 2) return
    if (!pointers.size) ignoreClick = false
    pointers.set(event.pointerId, pointAt(event))
  })
  map.addEventListener('pointermove', event => {
    const previous = pointers.get(event.pointerId)
    if (!previous) return
    const point = pointAt(event)
    if (!dragging && pointers.size === 1 && distance(previous, point) < 5) return
    dragging = ignoreClick = true
    map.classList.add('dragging')
    for (const pointer of pointers.keys()) map.setPointerCapture(pointer)

    const other = [...pointers].find(([id]) => id !== event.pointerId)?.[1]
    if (other) {
      const before = distance(previous, other)
      const after = distance(point, other)
      if (before > 0 && after > 0) setZoom(zoom * after / before, {
        x: (point.x + other.x) / 2, y: (point.y + other.y) / 2,
      })
    } else {
      const scale = map.getScreenCTM().a
      center.x = Math.max(0, Math.min(size.width, center.x - (point.x - previous.x) / scale))
      center.y = Math.max(0, Math.min(size.height, center.y - (point.y - previous.y) / scale))
      draw()
    }
    pointers.set(event.pointerId, point)
  })
  function finishGesture(event) {
    pointers.delete(event.pointerId)
    if (pointers.size) return
    dragging = false
    map.classList.remove('dragging')
  }
  window.addEventListener('pointerup', finishGesture)
  window.addEventListener('pointercancel', finishGesture)
  map.addEventListener('click', event => {
    if (ignoreClick && event.detail) event.preventDefault()
    ignoreClick = false
  }, true)

  function highlight(event, active) {
    const id = event.target.closest('[data-city]')?.dataset.city
    if (!id) return
    document.querySelectorAll('[data-city="' + id + '"]').forEach(item => item.classList.toggle('active-city', active))
  }
  for (const event of ['pointerover', 'focusin']) document.addEventListener(event, e => highlight(e, true))
  for (const event of ['pointerout', 'focusout']) document.addEventListener(event, e => highlight(e, false))
}

const nfcLinks = document.querySelector('.nfc-links')
if (nfcLinks) {
  const status = nfcLinks.querySelector('#nfc-copy-message')
  nfcLinks.addEventListener('click', async event => {
    const button = event.target.closest('[data-nfc-url]')
    if (!button) return
    try {
      await navigator.clipboard.writeText(button.dataset.nfcUrl)
      for (const previous of nfcLinks.querySelectorAll('[data-nfc-url]')) previous.textContent = 'Копировать'
      button.textContent = 'Скопировано'
      status.textContent = 'Ссылка скопирована'
    } catch {
      const link = button.closest('tr').querySelector('a')
      const range = document.createRange()
      range.selectNodeContents(link)
      const selection = window.getSelection()
      selection.removeAllRanges()
      selection.addRange(range)
      status.textContent = 'Ссылка выделена. Скопируйте её из меню браузера.'
    }
  })
}

const copy = document.querySelector('[data-copy]')
if (copy) {
  const output = document.querySelector('#copy-result')
  copy.addEventListener('click', async () => {
    const link = new URL(location.pathname, location.origin).href
    try {
      await navigator.clipboard.writeText(link)
      copy.querySelector('span').textContent = 'Ссылка скопирована'
      output.textContent = 'Ссылка скопирована'
    } catch {
      const field = document.querySelector('#copy-link')
      field.hidden = false
      field.value = link
      field.focus()
      field.select()
    }
  })
}

const profile = document.querySelector('[data-bitrix-branch]')
const checkVisible = task => {
  let pending = false
  async function update() {
    if (pending || document.visibilityState !== 'visible') return
    pending = true
    try { await task() } finally { pending = false }
  }
  update()
  setInterval(update, 120000)
  document.addEventListener('visibilitychange', update)
}
if (profile) {
  const manager = profile.querySelector('[data-manager]')
  const staff = profile.querySelector('[data-staff]')
  const photos = profile.querySelector('[data-manager-photos]')
  const placeholder = profile.querySelector('[data-manager-placeholder]')
  const team = profile.querySelector('[data-team]')
  const teamList = team.querySelector('ul')
  let shownTeam = []
  let shownPhotos = []
  function showTeam(members) {
    if (JSON.stringify(members) === JSON.stringify(shownTeam)) return
    shownTeam = members
    teamList.replaceChildren(...members.map(({name,photo}) => {
      const row = document.createElement('li')
      const avatar = document.createElement('span')
      avatar.className = 'team-avatar'
      avatar.setAttribute('aria-hidden', 'true')
      avatar.textContent = name.split(/\s+/).slice(0, 2).map(word => word[0].toUpperCase()).join('')
      if (photo) {
        const image = document.createElement('img')
        image.alt = ''
        image.loading = 'lazy'
        image.referrerPolicy = 'no-referrer'
        image.addEventListener('load', () => { image.dataset.loaded = 'true' })
        image.src = photo
        avatar.append(image)
      }
      const label = document.createElement('span')
      label.textContent = name
      row.append(avatar,label)
      return row
    }))
    team.hidden = !members.length
  }
  const updatePhotoVisibility = () => {
    const hasPhoto = [...photos.children].some(image => !image.hidden)
    photos.hidden = !hasPhoto
    placeholder.hidden = hasPhoto
  }
  function showPhotos(urls) {
    if (JSON.stringify(urls) === JSON.stringify(shownPhotos)) return
    shownPhotos = urls
    photos.replaceChildren(...urls.map(url => {
      const image = document.createElement('img')
      image.alt = ''
      image.referrerPolicy = 'no-referrer'
      image.hidden = true
      image.addEventListener('load', () => { image.hidden = false; updatePhotoVisibility() })
      image.src = url
      return image
    }))
    updatePhotoVisibility()
  }
  checkVisible(async () => {
    try {
      const response = await fetch('/api/branch/' + encodeURIComponent(profile.dataset.bitrixBranch))
      if (!response.ok) throw new Error('Profile request failed')
      const data = await response.json()
      manager.textContent = data.status === 'ready'
        ? data.managers.join(', ') || 'В оргструктуре не указан'
        : ({ 'not-configured':'каталог сотрудников ещё не подключён', 'unmapped':'Подразделение не найдено в каталог сотрудников' })[data.status] || 'Данные временно недоступны'
      staff.textContent = Number.isInteger(data.staff) ? new Intl.NumberFormat('ru-RU').format(data.staff) : '—'
      showPhotos(data.photos ?? [])
      showTeam(data.team ?? [])
    } catch {
      manager.textContent = 'Данные каталог сотрудников временно недоступны'
      staff.textContent = '—'
      showPhotos([])
      showTeam([])
    }
  })
}

const financial = document.querySelector('[data-budget-branch]')
const reportCards = financial ? [...financial.querySelectorAll('[data-report]')] : []
if (reportCards.length) {
  const currency = new Intl.NumberFormat('ru-KZ',{maximumFractionDigits:2})
  checkVisible(async () => {
    let result
    try {
      const response = await fetch('/api/budget/' + encodeURIComponent(financial.dataset.budgetBranch))
      if (response.ok) result = await response.json()
    } catch {}
    for (const card of reportCards) {
      const status = card.querySelector('[data-budget-status]')
      const data = result?.files?.[card.dataset.report]?.data
      if (!data) {
        status.textContent = status.dataset.hasValues
          ? 'Не удалось обновить таблицу. Показаны последние доступные значения.'
          : 'Не удалось загрузить таблицу. Проверьте доступ по ссылке и наличие листа бюджета.'
        continue
      }
      card.querySelector('[data-period]').textContent = data.period + ' · план в тенге'
      const headers = [...card.querySelectorAll('thead th')].slice(1)
      data.months.forEach((month,index) => { headers[index].textContent = month })
      const cells = card.querySelectorAll('tbody td')
      const values = [...data.receipts,...data.expenses,...data.net]
      values.forEach((value,index) => {
        cells[index].textContent = Number.isFinite(value) ? currency.format(value) + ' ₸' : '—'
      })
      const time = new Intl.DateTimeFormat('ru-RU', {
        timeStyle:'short', timeZone:'Asia/Yekaterinburg'
      }).format(new Date(result.checkedAt))
      status.dataset.hasValues = 'true'
      status.textContent = 'Проверено на демо-данные в ' + time +
        (data.calculated ? ' · Итоги пересчитаны по заполненным строкам бюджета.' : '')
    }
  })
}
