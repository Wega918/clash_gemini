(function(){
  const RaidApi = {
    csrf(){
      return window.APP_CONFIG?.csrfToken || document.querySelector('meta[name="csrf_token"],meta[name="csrf-token"]')?.content || '';
    },
    async post(action, payload={}){
      const body = new URLSearchParams({action, csrf_token:this.csrf(), ...payload});
      const res = await fetch('/app/battle_api.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest','X-CSRF-Token':this.csrf(),'Accept':'application/json'},
        credentials:'same-origin',
        body:body.toString()
      });
      const data = await res.json().catch(()=>({ok:false,error:'bad_json'}));
      if (data.csrf_token && window.APP_CONFIG) window.APP_CONFIG.csrfToken = data.csrf_token;
      if (!res.ok || !data.ok) throw new Error(data.error || ('HTTP '+res.status));
      return data;
    },
    bootstrap(){ return this.post('bootstrap'); },
    searchOpponent(reroll=false){ return this.post('search_opponent', {reroll: reroll ? 1 : 0}); },
    startRaid(defenderId){ return this.post('start_raid', {defender_id: defenderId}); },
    resolveRaid(raidId, result){ return this.post('resolve_raid', {raid_id: raidId, result_json: JSON.stringify(result)}); }
  };
  window.RaidApi = RaidApi;
})();
