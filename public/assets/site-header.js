(() => {
 const nav=document.querySelector('.mcv-nav'), marker=document.querySelector('.mcv-nav-marker'), burger=document.querySelector('.mcv-burger'), drawer=document.querySelector('.mcv-drawer'), consult=document.querySelector('.mcv-consult-dialog'), header=document.querySelector('.mcv-header');
 if(!nav||!marker||!burger||!drawer||!consult||!header)return;
 let frame=0;
 const layout=()=>{frame=0;nav.classList.toggle('is-stuck',marker.getBoundingClientRect().top<0);const r=header.getBoundingClientRect();burger.style.top=Math.max(16,r.top+(r.height-burger.offsetHeight)/2)+'px'};
 const update=()=>{if(!frame)frame=requestAnimationFrame(layout)};
 addEventListener('scroll',update,{passive:true});addEventListener('resize',update);layout();
 const open=(dialog)=>{dialog.showModal();document.body.classList.add('mcv-modal-open');if(dialog===drawer)burger.setAttribute('aria-expanded','true')};
 burger.addEventListener('click',()=>open(drawer));
 document.querySelector('[data-consult]').addEventListener('click',()=>open(consult));
 [drawer,consult].forEach(dialog=>{
  dialog.querySelector('[data-close]').addEventListener('click',()=>dialog.close());
  dialog.addEventListener('click',e=>{const r=dialog.getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)dialog.close()});
  dialog.addEventListener('close',()=>{document.body.classList.remove('mcv-modal-open');burger.setAttribute('aria-expanded','false')});
 });
 const toggle=document.querySelector('.mcv-sub-toggle'), parent=toggle.closest('li');
 toggle.addEventListener('click',()=>{const state=toggle.getAttribute('aria-expanded')!=='true';toggle.setAttribute('aria-expanded',String(state));parent.classList.toggle('is-open',state)});
 document.addEventListener('click',e=>{if(!parent.contains(e.target)){parent.classList.remove('is-open');toggle.setAttribute('aria-expanded','false')}});
 document.addEventListener('keydown',e=>{if(e.key==='Escape'){parent.classList.remove('is-open');toggle.setAttribute('aria-expanded','false');if(parent.contains(document.activeElement))document.activeElement.blur()}});
 matchMedia('(min-width:1025px)').addEventListener('change',e=>{if(e.matches&&drawer.open)drawer.close()});
})();
