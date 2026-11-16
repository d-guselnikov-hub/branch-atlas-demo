import fs from 'node:fs'
import path from 'node:path'
import {fileURLToPath} from 'node:url'
import {buildSync} from 'esbuild'
import {makeRenderer,project} from './render.mjs'
import './build.mjs'

const root = path.dirname(fileURLToPath(import.meta.url))
const read = file => fs.readFileSync(path.join(root,file),'utf8')
const data = name => JSON.parse(read(name+'.json'))
const cities = data('branches')
const documents = Object.fromEntries(data('documents').map(doc=>[doc.id,doc]))
const budgets = data('budget')
const geometry = data('geo')
const points = geometry.features.flatMap(feature => feature.geometry.type === 'Polygon'
  ? feature.geometry.coordinates.flat() : feature.geometry.coordinates.flat(2)).map(project)
const bounds = [Math.min(...points.map(p=>p[0])),Math.min(...points.map(p=>p[1])),
  Math.max(...points.map(p=>p[0])),Math.max(...points.map(p=>p[1]))]
const renderer = makeRenderer(bounds,documents,budgets)
const sample = {id:'atlas-template-id',name:'ATLAS_NAME',region:'ATLAS_REGION',
  address:'ATLAS_ADDRESS',wall:true,files:[],lat:50,lon:72}
const [x,y] = renderer.xy([sample.lon,sample.lat])
const skeleton = renderer.page('ATLAS_NAME',renderer.branch(sample,true))
  .replace('viewBox="'+[x-530,y-133,780,460].join(' ')+'"','viewBox="ATLAS_VIEWBOX"')
  .replace('translate('+x+' '+y+')','translate(ATLAS_COORDS)')
  .replace(/<span class="tag">.*?<\/span>/,'ATLAS_TAG')
  .replace(/<section class="financial".*?<\/section>/,'ATLAS_FINANCE')
  .replace(/<div class="office">.*?<\/div>/,'ATLAS_OFFICE')
const one = renderer.home([sample])
const home = renderer.page('Карта филиалов',one,true)
  .replace(/<a class="marker".*?<\/a>/,'ATLAS_PINS')
  .replace(/<a class="city-row".*?<\/a>/,'ATLAS_ROWS')
  .replace('1 NFC-меток','ATLAS_COUNT NFC-меток')
const templates = {
  home, skeleton, page:renderer.page('ATLAS_NAME','ATLAS_MAIN'),
  pin:one.match(/<a class="marker".*?<\/a>/)?.[0]
    .replace('translate('+x+' '+y+')','translate(ATLAS_COORDS)'),
  pinOffsets:Object.fromEntries(cities.filter(city=>city.wall).map(city=>{
    const pin=renderer.home([city]).match(/<a class="marker".*?<\/a>/)?.[0]
    return [city.id,[pin?.match(/<rect[^>]+>/)?.[0],pin?.match(/<text [^>]+>/)?.[0]]]
  })),
  row:one.match(/<a class="city-row".*?<\/a>/)?.[0]
    .replace('atlas_name atlas_region','ATLAS_SEARCH'),
  nfc:renderer.branch(sample,true).match(/<span class="tag">.*?<\/span>/)?.[0],
  empty:renderer.branch(sample,true).match(/<div class="no-data">.*?<\/div>/)?.[0],
  report:renderer.report('ATLAS_FILE_PLACEHOLDER',0),
  reports:Object.fromEntries(Object.keys(documents).map(id=>[id,renderer.report(id,0)])),
}
const assets = Object.fromEntries(['style.css','app.js','brand.svg','kazakhstan.svg','sample-report.html'].map(file=>['/'+file,read('dist/'+file)]))
assets['/app.js']+='\n;'+buildSync({entryPoints:[path.join(root,'passkey-client.mjs')],
  bundle:true,write:false,platform:'browser',format:'iife',target:'es2020',minify:true}).outputFiles[0].text
const target = path.resolve(process.argv[2] ?? path.join(root,'public'))
fs.mkdirSync(target,{recursive:true})
const code = ['local-data.php','local-api.php','local-finance.php','local-passkeys.php','local-audit.php','local-site.php'].map(file=>read('plesk/'+file).replace(/^<\?php\s*/,''))
const embedded = Buffer.from(JSON.stringify({cities,documents,budgets,bounds,templates,assets})).toString('base64')
const setupHash = '0'.repeat(64)
if (!/^[a-f0-9]{64}$/.test(setupHash)) throw new Error('SETUP_HASH is required')
fs.writeFileSync(path.join(target,'index.php'),'<?php\n'+code.join('\n')
  .replaceAll('@@SITE_BUNDLE@@',embedded).replaceAll('@@SETUP_HASH@@',setupHash))
