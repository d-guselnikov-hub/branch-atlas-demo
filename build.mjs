import fs from 'node:fs'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import { buildSync } from 'esbuild'
import { makeRenderer, project } from './render.mjs'

const source = path.dirname(fileURLToPath(import.meta.url))
const read = name => JSON.parse(fs.readFileSync(path.join(source, name + '.json'), 'utf8'))
const cities = read('branches').sort((a, b) => a.name.localeCompare(b.name, 'ru'))
const documents = Object.fromEntries(read('documents').map(doc => [doc.id, doc]))
const budgets = read('budget')
const geometry = read('geo')

const polygons = geometry.features.map(feature => feature.geometry.type === 'Polygon'
  ? [feature.geometry.coordinates] : feature.geometry.coordinates)
const rawPoints = polygons.flat(3).map(project)
const bounds = [
  Math.min(...rawPoints.map(p => p[0])), Math.min(...rawPoints.map(p => p[1])),
  Math.max(...rawPoints.map(p => p[0])), Math.max(...rawPoints.map(p => p[1]))
]
const {xy, home, branch, page} = makeRenderer(bounds, documents, budgets)
const color = ['#342f27','#2e2b25','#3b3429','#353127','#302b24','#39342b']
const regions = polygons.map((area, index) => {
  const d = area.flatMap(poly => poly.map(ring => 'M' + ring.map(point => xy(point).map(v=>v.toFixed(1)).join(',')).join('L') + 'Z')).join('')
  return '<path fill="'+color[index % color.length]+'" d="'+d+'"/>'
}).join('')

function write(file, html) {
  const target = path.join(source, 'dist', file)
  fs.mkdirSync(path.dirname(target), {recursive:true})
  fs.writeFileSync(target, html)
}
write('index.html', page('Карта филиалов', home(cities), true))
write('kazakhstan.svg', '<svg xmlns="http://www.w3.org/2000/svg"><g id="regions">'+regions+'</g></svg>')
for (const city of cities) write('filial/' + city.id + '/index.html', page(city.name, branch(city)))
write('404.html', page('Страница не найдена','<main class="frame branch"><h1>Страница не найдена</h1><a class="back" href="/">К карте филиалов</a></main>'))
