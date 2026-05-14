/**
 * Pulsetic Speed Widget v1.3.0 — psw-widget.js
 * Handles [pulsetic_speed] and [pulsetic_uptime] shortcodes.
 * NOTE: This file is intentionally excluded from cache plugin JS optimisation
 *       by class-psw-cache.php. Do not rename without updating the exclusion filter.
 */
(function () {
    'use strict';
    const cfg = window.pswConfig || { speedUrl:'/wp-json/psw/v1/speed', uptimeUrl:'/wp-json/psw/v1/uptime', historyUrl:'/wp-json/psw/v1/speed-history', refreshInterval:60, nonce:'' };
    const BAR_MAX = 2000;
    function fmt(ms){ return ms<1000 ? ms+'ms' : (ms/1000).toFixed(2)+'s'; }
    function barW(ms){ return Math.min(100,Math.max(8,100-Math.round((ms/BAR_MAX)*100)+15)); }
    function isSlow(ms){ return ms>800; }
    function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    function fmtTime(iso){ try{ return new Date(iso).toLocaleTimeString([],{hour:'2-digit',minute:'2-digit',second:'2-digit'}); }catch(e){return '';} }
    function fetchJson(url,params){
        const qs=new URLSearchParams(params).toString();
        const h={}; if(cfg.nonce) h['X-WP-Nonce']=cfg.nonce;
        return fetch(url+(qs?'?'+qs:''),{headers:h}).then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); });
    }

    /* ── SPEED ── */
    function renderSpeedPreview(widget,nodes,preview){
        const c=widget.querySelector('.psw-regions'); if(!c) return;
        const shown=nodes.slice(0,preview);
        if(!shown.length){ c.innerHTML='<div class="psw-error">No check data for this window.</div>'; return; }
        c.innerHTML=shown.map(function(n){
            const w=barW(n.avg_ms),sl=isSlow(n.avg_ms)?' psw-bar-slow':'';
            return '<div class="psw-region"><span class="psw-region-flag">'+(n.flag?'<img src="https://flagcdn.com/w20/'+n.flag+'.png" alt="'+esc(n.node)+'" width="24" height="18" class="psw-flag-img">':'<span class="psw-flag-globe"></span>')+'</span><span class="psw-region-name">'+esc(n.node)+'</span><div class="psw-bar-track"><div class="psw-bar-fill'+sl+'" style="width:'+w+'%"></div></div><span class="psw-time">'+fmt(n.avg_ms)+'</span></div>';
        }).join('');
    }
    function renderSpeedStats(widget,data){
        var el;
        el=widget.querySelector('[data-stat="nodes"]'); if(el) el.textContent=data.total_nodes||(data.nodes?data.nodes.length:'—');
        el=widget.querySelector('[data-stat="uptime"]'); if(el) el.textContent=(data.status||'').toLowerCase()==='up'?'99.9%':'—';
        el=widget.querySelector('[data-stat="status"]');
        if(el){ const s=(data.status||'unknown').toLowerCase(); el.textContent=s==='up'?'Online':s==='down'?'Offline':'—'; el.className='psw-stat-num psw-status-'+(s==='up'?'up':s==='down'?'down':'unknown'); }
    }
    function enableViewAll(widget,data){
        const btn=widget.querySelector('.psw-all-btn'); if(!btn) return;
        const total=data.total_nodes||(data.nodes?data.nodes.length:0);
        btn.disabled=false;
        btn.querySelector('.psw-all-btn-label').textContent='View all '+total+' location'+(total!==1?'s':'');
        btn.dataset.pswData=JSON.stringify(data);
    }
    function fetchSpeed(widget){
        const mid=widget.dataset.monitor||'',min=parseInt(widget.dataset.minutes,10)||30,prev=parseInt(widget.dataset.preview,10)||4,mid2=widget.dataset.modalId||'';
        const p={minutes:min}; if(mid) p.monitor_id=mid;
        fetchJson(cfg.speedUrl,p).then(function(data){
            if(data.error){ showErr(widget,data.error); return; }
            renderSpeedPreview(widget,data.nodes||[],prev);
            renderSpeedStats(widget,data);
            enableViewAll(widget,data);
            const u=widget.querySelector('.psw-updated'); if(u) u.textContent=data.fetched_at?'Updated '+fmtTime(data.fetched_at):'';
            if(mid2){ const ov=document.getElementById(mid2); if(ov&&ov.classList.contains('psw-modal-open')) renderModal(ov,data); }
            // Fetch real uptime % for the UPTIME SLA stat in the stats row
            const up={days:30}; if(mid) up.monitor_id=mid;
            fetchJson(cfg.uptimeUrl,up).then(function(ud){
                const el=widget.querySelector('[data-stat="uptime"]');
                if(el&&ud.uptime_display) el.textContent=ud.uptime_display+'%';
            }).catch(function(){});
        }).catch(function(e){ showErr(widget,'Could not load data. Retrying…'); console.warn('[PSW speed]',e); });
    }
    function showErr(widget,msg){ const c=widget.querySelector('.psw-regions'); if(c) c.innerHTML='<div class="psw-error">'+esc(msg)+'</div>'; }

    /* ── MODAL ── */
    function groupByRegion(nodes){
        const order=['North America','Europe','Asia Pacific','Middle East','South America','Africa','Other'],groups={};
        order.forEach(function(r){groups[r]=[];});
        nodes.forEach(function(n){ const r=n.region||'Other'; if(!groups[r]) groups[r]=[]; groups[r].push(n); });
        return order.map(function(r){return{region:r,nodes:groups[r]||[]};}).filter(function(g){return g.nodes.length>0;});
    }
    function renderModal(overlay,data){
        const re=overlay.querySelector('[data-modal-regions]'),se=overlay.querySelector('[data-modal-sub]'),ue=overlay.querySelector('[data-modal-updated]');
        const total=data.total_nodes||(data.nodes?data.nodes.length:0);
        if(se) se.textContent=total+' location'+(total!==1?'s':'')+' monitored';
        if(ue) ue.textContent=data.fetched_at?'Updated '+fmtTime(data.fetched_at):'';
        if(!re||!data.nodes||!data.nodes.length) return;
        let html='';
        groupByRegion(data.nodes).forEach(function(g){
            html+='<div class="psw-modal-group-label">'+esc(g.region)+'</div>';
            g.nodes.forEach(function(n){
                const w=barW(n.avg_ms),sl=isSlow(n.avg_ms)?' psw-bar-slow':'',ck=n.check_count?n.check_count+' check'+(n.check_count!==1?'s':''):'';
                html+='<div class="psw-modal-region"><span class="psw-modal-region-flag">'+(n.flag?'<img src="https://flagcdn.com/w20/'+n.flag+'.png" alt="'+esc(n.node)+'" width="24" height="18" class="psw-flag-img">':'<span class="psw-flag-globe"></span>')+'</span><span class="psw-modal-region-name">'+esc(n.node)+'</span><div class="psw-modal-bar-track"><div class="psw-modal-bar-fill'+sl+'" style="width:'+w+'%"></div></div><span class="psw-modal-region-time">'+fmt(n.avg_ms)+'</span><span class="psw-modal-region-count">'+esc(ck)+'</span></div>';
            });
        });
        re.innerHTML=html;
    }
    function openModal(overlay,data){ renderModal(overlay,data); document.body.style.overflow='hidden'; overlay.setAttribute('aria-hidden','false'); overlay.classList.add('psw-modal-open'); const cb=overlay.querySelector('.psw-modal-close'); if(cb) setTimeout(function(){cb.focus();},50); }
    function closeModal(overlay){ overlay.classList.remove('psw-modal-open'); overlay.setAttribute('aria-hidden','true'); document.body.style.overflow=''; }
    function bindModal(overlay){
        const cb=overlay.querySelector('.psw-modal-close'); if(cb) cb.addEventListener('click',function(){closeModal(overlay);});
        overlay.addEventListener('click',function(e){if(e.target===overlay) closeModal(overlay);});
        document.addEventListener('keydown',function(e){if(e.key==='Escape'&&overlay.classList.contains('psw-modal-open')) closeModal(overlay);});

        // Monthly average toggle
        overlay.querySelectorAll('.psw-toggle-btn').forEach(function(btn){
            btn.addEventListener('click',function(){
                const view=btn.dataset.view;
                overlay.querySelectorAll('.psw-toggle-btn').forEach(function(b){ b.classList.remove('psw-toggle-active'); });
                btn.classList.add('psw-toggle-active');

                if(view==='monthly'){
                    const re=overlay.querySelector('[data-modal-regions]');
                    if(re) re.innerHTML='<div class="psw-modal-loading">Loading 7-day averages…</div>';
                    const mid=overlay.dataset.monitor||'',p={days:7};
                    if(mid) p.monitor_id=mid;
                    fetchJson(cfg.historyUrl||cfg.speedUrl.replace('/speed','/speed-history'),p).then(function(data){
                        const se=overlay.querySelector('[data-modal-sub]');
                        if(se) se.textContent='7-day averages across '+(data.total_nodes||0)+' location'+(data.total_nodes!==1?'s':'')+(data.row_count?' ('+data.row_count+' snapshots)':'');
                        renderModal(overlay,data);
                    }).catch(function(){ const re=overlay.querySelector('[data-modal-regions]'); if(re) re.innerHTML='<div class="psw-error">Could not load historical data.</div>'; });
                } else {
                    // Back to recent — reuse cached data on the trigger button
                    const triggerBtn=document.querySelector('[data-modal="'+overlay.id+'"]');
                    let data={}; try{ data=JSON.parse(triggerBtn&&triggerBtn.dataset.pswData||'{}'); }catch(e){}
                    const se=overlay.querySelector('[data-modal-sub]');
                    if(se) se.textContent=(data.total_nodes||0)+' location'+(data.total_nodes!==1?'s':'')+' monitored';
                    renderModal(overlay,data);
                }
            });
        });
    }

    /* ── UPTIME ── */
    function fetchUptime(widget){
        const mid=widget.dataset.monitor||'',days=parseInt(widget.dataset.days,10)||30,inline=widget.classList.contains('psw-uptime-inline');
        const p={days}; if(mid) p.monitor_id=mid;
        fetchJson(cfg.uptimeUrl,p).then(function(data){
            if(data.error){return;}
            if(inline){ renderUptimeInline(widget,data); } else { renderUptimeCard(widget,data); }
        }).catch(function(e){console.warn('[PSW uptime]',e);});
    }
    function renderUptimeCard(widget,data){
        var el;
        el=widget.querySelector('[data-uptime-pct]'); if(el) el.textContent=(data.uptime_display||'—')+'%';
        el=widget.querySelector('[data-uptime-bar]'); if(el) el.style.width=(data.uptime_pct||0)+'%';
        el=widget.querySelector('[data-uptime-status-badge]');
        if(el){ const s=(data.status||'').toLowerCase(),up=s==='up'; el.textContent=up?'Online':s==='down'?'Offline':'Checking…'; el.className='psw-uptime-status-badge '+(up?'up':s==='down'?'down':'other'); }
        el=widget.querySelector('[data-uptime-detail]');
        if(el) el.textContent=data.downtime_human&&data.downtime_human!=='No downtime'?data.downtime_human+' in last '+data.window_days+' days':'No downtime in last '+data.window_days+' days';
        el=widget.querySelector('[data-uptime-updated]'); if(el) el.textContent=data.fetched_at?'Updated '+fmtTime(data.fetched_at):'';
    }
    function renderUptimeInline(widget,data){
        var el;
        el=widget.querySelector('[data-uptime-pct]'); if(el) el.textContent=(data.uptime_display||'—')+'%';
        el=widget.querySelector('[data-uptime-status]'); if(el){ const s=(data.status||'').toLowerCase(); el.className='psw-uptime-dot '+(s==='up'?'up':s==='down'?'down':''); }
    }

    /* ── INIT ── */
    function init(){
        document.querySelectorAll('.psw-modal-overlay').forEach(function(o){ document.body.appendChild(o); bindModal(o); });
        const interval=Math.max(15,cfg.refreshInterval||60)*1000;
        document.querySelectorAll('.psw-speed').forEach(function(widget){
            const btn=widget.querySelector('.psw-all-btn');
            if(btn) btn.addEventListener('click',function(){
                const ov=document.getElementById(btn.dataset.modal); if(!ov) return;
                let data={}; try{data=JSON.parse(btn.dataset.pswData||'{}');}catch(e){}
                openModal(ov,data);
            });
            fetchSpeed(widget);
            setInterval(function(){fetchSpeed(widget);},interval);
        });
        document.querySelectorAll('.psw-uptime-card,.psw-uptime-inline').forEach(function(widget){
            fetchUptime(widget);
            setInterval(function(){fetchUptime(widget);},interval);
        });
    }

    if(document.readyState==='loading'){ document.addEventListener('DOMContentLoaded',init); } else { init(); }
})();
