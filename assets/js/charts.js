(function(){
  var d=window.METEO_DATA;if(!d||!window.Chart)return;
  var l=d.chart_labels||{};
  function mk(id,label,color,data){
    var cv=document.getElementById(id);if(!cv)return;
    var r=cv.getBoundingClientRect();cv.width=Math.max(680,r.width||680);cv.height=260;
    new Chart(cv.getContext('2d'),{type:'line',data:{labels:d.labels,datasets:[{label:label,data:data,borderColor:color}]}});
  }
  mk('chartT',l.T||'Temperature','#d04f2b',d.T);
  mk('chartH',l.H||'Humidity','#0f9d58',d.H);
  mk('chartP',l.P||'Pressure','#0f6cbf',d.P);
  mk('chartR',l.R||'Rain','#1269a8',d.RR.map(function(v,i){var day=d.R[i];return (v||0)+(day||0);}));
  mk('chartW',l.W||'Wind','#7e3fa1',d.W.map(function(v,i){var g=d.G[i]||0;return Math.max(v||0,g);}));
})();
