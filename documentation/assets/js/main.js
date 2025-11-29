// Minimal interactivity: smooth scrolling, mobile menu, copy button
document.addEventListener('DOMContentLoaded',function(){
  // smooth scroll for anchor links
  document.querySelectorAll('a[href^="#"]').forEach(a=>{
    a.addEventListener('click',function(e){
      const target=document.querySelector(this.getAttribute('href'));
      if(target){e.preventDefault();target.scrollIntoView({behavior:'smooth',block:'start'});}
    });
  });

  // mobile menu toggle
  const menuToggle=document.getElementById('menuToggle');
  const nav=document.querySelector('.main-nav');
  if(menuToggle){menuToggle.addEventListener('click',()=>{nav.style.display = (nav.style.display==='flex')? 'none':'flex';});}

  // copy buttons
  document.querySelectorAll('button[data-copy]').forEach(btn=>{
    btn.addEventListener('click',function(){
      const sel = document.querySelector(this.getAttribute('data-copy'));
      if(!sel) return;
      const text = sel.innerText;
      navigator.clipboard.writeText(text).then(()=>{
        const orig = this.innerText; this.innerText='Copied'; setTimeout(()=>this.innerText=orig,1500);
      });
    });
  });
});