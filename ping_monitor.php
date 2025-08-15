<?php
error_reporting(0);
ini_set('display_errors', 0);

$cacheFile = sys_get_temp_dir().'/ping_monitor_cache.json';
$cacheTTL = 2;

if(isset($_GET['action']) && $_GET['action']==='ping'){
    header('Content-Type: application/json');

    $servers=[
        ['name'=>'Example','url'=>'http://example.com'],
        ['name'=>'HTTPBin','url'=>'http://httpbin.org/get'],
        ['name'=>'Google','url'=>'http://google.com'],
        ['name'=>'Cloudflare','url'=>'http://cloudflare.com'],
        ['name'=>'FPT VN','url'=>'http://fpt.vn'],
        ['name'=>'Your IP: ' . $_SERVER['REMOTE_ADDR'],'ip'=>$_SERVER['REMOTE_ADDR']]
    ];

    $res=[];
    foreach($servers as $s){
        $ms=null;
        if(isset($s['url'])){
            $start=microtime(true);
            if(function_exists('curl_init')){
                $ch=@curl_init($s['url']);
                @curl_setopt($ch,CURLOPT_NOBODY,true);
                @curl_setopt($ch,CURLOPT_TIMEOUT,2);
                @curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
                @curl_exec($ch);
                if(@curl_errno($ch)===0) $ms=round((microtime(true)-$start)*1000);
                @curl_close($ch);
            } else {
                $ctx=@stream_context_create(['http'=>['method'=>'HEAD','timeout'=>2]]);
                if(@file_get_contents($s['url'],false,$ctx)!==false)
                    $ms=round((microtime(true)-$start)*1000);
            }
        }
        $res[]=['name'=>$s['name'],'ms'=>$ms];
    }

    // Cache CPU/RAM 2s
    $cpu=['1min'=>null,'5min'=>null,'15min'=>null];
    $ram=null;
    if(file_exists($cacheFile) && (time()-filemtime($cacheFile))<$cacheTTL){
        $cached=json_decode(file_get_contents($cacheFile),true);
        $cpu=$cached['cpu'];
        $ram=$cached['ram'];
    } else {
        // CPU Windows
        $cpuOut = trim(@shell_exec('powershell -Command "(Get-CimInstance Win32_Processor | Measure-Object -Property LoadPercentage -Average).Average"'));
        if(is_numeric($cpuOut)){
            $cpu=['1min'=>$cpuOut,'5min'=>$cpuOut,'15min'=>$cpuOut];
        }

        // RAM Windows
        $ramOut = trim(@shell_exec('powershell -Command "$m = Get-CimInstance Win32_OperatingSystem; [math]::Round((($m.TotalVisibleMemorySize - $m.FreePhysicalMemory)/$m.TotalVisibleMemorySize)*100)"'));
        if(is_numeric($ramOut)) $ram = $ramOut;

        file_put_contents($cacheFile,json_encode(['cpu'=>$cpu,'ram'=>$ram]));
    }

    echo json_encode(['servers'=>$res,'cpu'=>$cpu,'ram'=>$ram]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<title>Ping & Server Monitor</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js" defer></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/gsap/3.14.0/gsap.min.js" defer></script>
<script defer>
document.addEventListener('DOMContentLoaded', function(){

const cpuCtx = document.getElementById('cpuChart').getContext('2d');
const ramCtx = document.getElementById('ramChart').getContext('2d');

const cpuChart = new Chart(cpuCtx, {
    type: 'line',
    data: { labels: [], datasets:[{label:'CPU %', data:[], borderColor:'#3b82f6', backgroundColor:'rgba(59,130,246,0.2)', fill:true}] },
    options:{responsive:true, animation:{duration:500,easing:'easeOutQuad'}, scales:{y:{min:0,max:100}}}
});

const ramChart = new Chart(ramCtx, {
    type: 'line',
    data: { labels: [], datasets:[{label:'RAM %', data:[], borderColor:'#10b981', backgroundColor:'rgba(16,185,129,0.2)', fill:true}] },
    options:{responsive:true, animation:{duration:500,easing:'easeOutQuad'}, scales:{y:{min:0,max:100}}}
});

async function pingClient(){
    var start=performance.now();
    return new Promise(resolve=>{
        var xhr=new XMLHttpRequest();
        xhr.open('GET','',true);
        xhr.onload=function(){resolve(Math.round(performance.now()-start))};
        xhr.onerror=function(){resolve(null)};
        xhr.send();
    });
}

function colorClass(ms){
    if(ms===null) return 'text-red-500';
    if(ms<200) return 'text-green-500';
    if(ms<500) return 'text-yellow-500';
    return 'text-red-600 font-bold';
}

let tick = 0;
async function update(){
    var xhr=new XMLHttpRequest();
    xhr.open('GET','?action=ping',true);
    xhr.onload=async function(){
        if(xhr.status===200){
            try { var data=JSON.parse(xhr.responseText); } 
            catch(e){ console.error("Invalid JSON:", xhr.responseText); return; }

            // client latency
            for(var i=0;i<data.servers.length;i++)
                if(data.servers[i].name.startsWith('Your IP')) 
                    data.servers[i].ms=await pingClient();

            // update table
            const tbody=document.getElementById('server-list'); tbody.innerHTML='';
            data.servers.forEach(s=>{
                const cls=colorClass(s.ms);
                const tr=document.createElement('tr');
                tr.innerHTML='<td class="border px-4 py-2">'+s.name+'</td><td class="border px-4 py-2 '+cls+'">'+(s.ms===null?'OFF':s.ms)+'</td>';
                tbody.appendChild(tr);
            });

            // update CPU/RAM text with GSAP
            if(window.gsap){
                gsap.to("#cpu-load",{textContent:data.cpu['1min'], duration:0.5, roundProps:"textContent"});
                gsap.to("#ram-used",{textContent:data.ram, duration:0.5, roundProps:"textContent"});
            }

            // update charts
            tick++;
            if(cpuChart.data.labels.length>=20){cpuChart.data.labels.shift(); cpuChart.data.datasets[0].data.shift();}
            cpuChart.data.labels.push(tick); cpuChart.data.datasets[0].data.push(data.cpu['1min']);
            cpuChart.update();

            if(ramChart.data.labels.length>=20){ramChart.data.labels.shift(); ramChart.data.datasets[0].data.shift();}
            ramChart.data.labels.push(tick); ramChart.data.datasets[0].data.push(data.ram);
            ramChart.update();
        }
    };
    xhr.send();
}

update();
setInterval(update,1000);

});
</script>
</head>
<body class="bg-gray-100 text-gray-900">
<div class="container mx-auto p-4">
    <h1 class="text-3xl font-bold mb-4 text-center">Server Ping & System Monitor</h1>
    
    <div class="overflow-x-auto mb-4">
        <table class="min-w-full bg-white shadow rounded-lg">
            <thead class="bg-gray-200 text-lg">
                <tr>
                    <th class="px-4 py-2">Server</th>
                    <th class="px-4 py-2">Ping (ms)</th>
                </tr>
            </thead>
            <tbody id="server-list" class="text-xl"></tbody>
        </table>
    </div>

    <div class="bg-white p-4 shadow rounded-lg text-xl mb-4">
        <p class="mb-2">CPU Load: <span id="cpu-load">–</span>%</p>
        <canvas id="cpuChart" class="mb-4" height="100"></canvas>
        <p class="mb-2">RAM Used: <span id="ram-used">–</span>%</p>
        <canvas id="ramChart" height="100"></canvas>
    </div>
</div>
</body>
</html>
