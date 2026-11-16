import {browserSupportsWebAuthn,startAuthentication,startRegistration} from '@simplewebauthn/browser'

if (window.isSecureContext && browserSupportsWebAuthn()) {
  for (const button of document.querySelectorAll('button[data-passkey]')) {
    const mode=button.dataset.passkey
    const status=button.parentElement.querySelector('[data-passkey-status]')
    const describe=(text)=>{ status.hidden=!text; status.textContent=text }
    button.hidden=false
    const device=button.closest('.finance-device')
    if (device) device.hidden=false
    const post=async(action,fields={})=>{
      const response=await fetch('/admin/',{
        method:'POST',credentials:'same-origin',cache:'no-store',
        body:new URLSearchParams({action:'passkey-'+mode+'-'+action,...fields})
      })
      const answer=await response.json()
      if (!response.ok) throw new Error(answer.status ?? 'failed')
      return answer
    }
    function warm() {
      const started=Date.now()
      const promise=post('start').catch(error=>({error}))
      return {started,promise}
    }
    let cached=warm()
    button.addEventListener('click',async()=>{
      button.disabled=true
      describe('')
      try {
        if (Date.now()-cached.started>45000) cached=warm()
        const {options,ticket,error}=await cached.promise
        if (error) throw error
        const auth=mode==='auth'
        const credential=auth ? await startAuthentication({optionsJSON:options}) : await startRegistration({optionsJSON:options})
        const result=await post('finish',{
          ticket,challenge:options.challenge,credential:JSON.stringify(credential),
          entry:button.dataset.passkeyEntry ?? '',city:button.dataset.passkeyCity ?? ''
        })
        const next = auth ? result.next : result.status==='registered' ? button.dataset.passkeyReturn : ''
        if (/^\/(?:filial\/[a-z0-9-]+\/|admin\/(?:\?next=finance)?)$/.test(next ?? '')) location.assign(next)
        else if (result.status==='registered') describe('Ключ входа сохранён. На этом устройстве теперь можно войти без пароля.')
      } catch(error) {
        if (error.message==='limit') describe('Достигнут лимит устройств. Обратитесь к администратору.')
        else if (mode==='auth') describe('Вход с ключом не завершился. Попробуйте снова или войдите с паролем.')
        else describe('Ключ не сохранён. Проверьте, настроена ли блокировка экрана, и попробуйте снова.')
      } finally {
        button.disabled=false
        cached=warm()
      }
    })
  }
}
