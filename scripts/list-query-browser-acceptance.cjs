const fs = require('fs');
const path = require('path');
const { chromium, request } = require('playwright');
const base = process.env.LIST_TEST_BASE_URL;
const output = process.env.LIST_TEST_REPORT_DIR;
if (!/^http:\/\/127\.0\.0\.1:[0-9]+$/.test(base || '') || !output) throw new Error('isolated loopback test required');
fs.mkdirSync(output, { recursive: true });
const assert = (condition, message) => { if (!condition) throw new Error(message); console.log('PASS ' + message); };
const percentile = (samples, fraction) => [...samples].sort((a,b)=>a-b)[Math.floor((samples.length-1)*fraction)];

async function merchantLogin(context, uid, key) {
  const page = await context.get(base + '/user/login.php');
  const html = await page.text();
  const match = html.match(/name="csrf_token"\s+value="([^"]+)"/);
  if (!match) throw new Error('merchant login CSRF missing');
  const response = await context.post(base + '/user/ajax.php?act=login', {
    form: {type:0,user:String(uid),pass:key,csrf_token:match[1]}, headers:{referer:base+'/user/login.php'}
  });
  assert((await response.json()).code === 0, 'merchant fixture login ' + uid);
}
async function post(context, url, data = {}) {
  const response = await context.post(base+url,{form:data,headers:{referer:base+'/admin/order.php'}});
  if (!response.ok()) throw new Error('HTTP list failed: '+response.status());
  return response.json();
}

(async()=>{
  const admin = await request.newContext();
  const merchant = await request.newContext();
  const other = await request.newContext();
  const anonymous = await request.newContext();
  const browser = await chromium.launch({headless:true});
  try {
    const login = await post(admin,'/admin/login.php?act=login',{username:'admin',password:'123456',code:''});
    assert(login.code === 0,'admin fixture login');
    await merchantLogin(merchant,1000,'fixture-only-a');
    await merchantLogin(other,1001,'fixture-only-b');
    const denied = await post(anonymous,'/user/ajax2.php?act=orderList',{offset:0,limit:20});
    assert(denied.code === -3 && !denied.rows,'anonymous cannot read warm cache');
    const a = await post(merchant,'/user/ajax2.php?act=orderList',{uid:1001,offset:0,limit:20});
    const b = await post(other,'/user/ajax2.php?act=orderList',{uid:1000,offset:0,limit:20});
    assert(a.rows.every(r=>Number(r.uid)===1000) && b.rows.every(r=>Number(r.uid)===1001),'HTTP merchant isolation');
    await other.get(base+'/user/login.php?logout=1',{headers:{referer:base+'/user/order.php'}});
    const loggedOut=await post(other,'/user/ajax2.php?act=orderList',{limit:20});
    assert(loggedOut.code===-3 && !loggedOut.rows,'logout cannot read previously warmed merchant cache');
    const bad = await post(admin,'/admin/ajax_order.php?act=orderList',{dstatus:99,limit:20});
    assert(bad.code === -1 && !bad.rows,'invalid filter is an explicit error');
    const endpoints = [
      ['/admin/ajax_order.php?act=orderList',{offset:0,limit:30}],
      ['/admin/ajax_shop.php?act=orderList',{offset:0,limit:20}],
      ['/admin/ajax_shop.php?act=orderList',{offset:0,limit:20,search_field:'pay_trade_no',keyword:'LP0000000000000000004'}]
    ];
    const report = {samples:100,concurrency:1,endpoints:[]};
    for (const [url,data] of endpoints) {
      await post(admin,url,data);
      const times=[];
      for(let i=0;i<100;i++){
        const start=performance.now();
        const result=await post(admin,url,data);
        times.push(performance.now()-start);
        if(!Array.isArray(result.rows) || !result.meta.rows_live) throw new Error('invalid list response');
      }
      const p95=percentile(times,.95);
      report.endpoints.push({url,exact:!!data.keyword,p50_ms:percentile(times,.5),p95_ms:p95});
      assert(p95<300,'100k HTTP warm P95 below 300 ms '+url+(data.keyword?' exact':''));
    }
    const start=performance.now();
    const concurrent=await Promise.all(Array.from({length:20},()=>post(admin,endpoints[0][0],endpoints[0][1])));
    report.concurrency20_batch_ms=performance.now()-start;
    assert(concurrent.every(r=>r.rows.length===30),'20 concurrent requests succeed');
    const fresh=await post(admin,endpoints[0][0],{...endpoints[0][1],fresh:1});
    assert(!fresh.meta.total_cached,'HTTP force refresh bypasses cache');
    const paidBefore=await post(admin,endpoints[0][0],{dstatus:1,limit:20});
    await post(admin,'/admin/ajax_order.php?act=setStatus&trade_no=0000000000000000011&status=3');
    const paidAfter=await post(admin,endpoints[0][0],{dstatus:1,limit:20});
    assert(paidAfter.total===paidBefore.total-1,'admin status mutation invalidates across HTTP workers');
    await post(admin,'/admin/ajax_order.php?act=setStatus&trade_no=0000000000000000011&status=1');
    await post(admin,'/admin/ajax_pay.php?act=savePayType',{action:'edit',id:1,name:'alipay',showname:'Fixture renamed',device:0});
    const renamed=await post(merchant,'/user/ajax2.php?act=orderList',{limit:20});
    assert(renamed.rows.every(r=>r.typeshowname==='Fixture renamed'),'type rename invalidates merchant display dictionary across requests');
    const errors=[];
    const adminStorage=await admin.storageState();
    const merchantStorage=await merchant.storageState();
    for(const width of [1366,768,390]){
      for(const [url,storage,table] of [
        ['/admin/order.php',adminStorage,'#listTable'],
        ['/admin/shop_orders.php',adminStorage,'#ordersTable'],
        ['/admin/shop_goods.php',adminStorage,'#goodsTable'],
        ['/user/order.php',merchantStorage,'#listTable'],
        ['/user/record.php',merchantStorage,'#listTable']
      ]){
        const context=await browser.newContext({storageState:storage,viewport:{width,height:900}});
        const page=await context.newPage();
        page.on('pageerror',error=>errors.push(error.message));
        await page.goto(base+url,{waitUntil:'networkidle'});
        await page.locator('.list-read-status').filter({hasText:'列表实时查询'}).waitFor();
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=document.documentElement.clientWidth+1),'no horizontal page overflow '+url+' '+width);
        await page.screenshot({path:path.join(output,url.replace(/\W/g,'_')+'-'+width+'.png'),fullPage:true});
        await page.locator('.list-read-status button').click();
        await page.locator('.list-read-status').filter({hasText:'列表实时查询'}).waitFor();
        if(width===390 || width===1366) await page.screenshot({path:path.join(output,url.replace(/\W/g,'_')+'-'+width+'.png'),fullPage:true});
        if(url==='/admin/shop_orders.php' && width===1366){
          let summaries=0;
          page.on('request',r=>{if(r.url().includes('act=summary')) summaries++;});
          await page.locator('#shop-search-keyword').fill('LP0000000000000000004');
          await page.locator('#toolbar button[type=submit]').click();
          await page.waitForFunction(()=>$('#ordersTable').bootstrapTable('getData').length===1);
          assert(summaries===0,'search does not refetch global shop summary');
          await page.locator('#shop-search-keyword').fill('LP0000000000000000008');
          await page.locator('#toolbar button[type=submit]').click();
          await page.waitForFunction(()=>$('#ordersTable').bootstrapTable('getData')[0]?.pay_trade_no==='LP0000000000000000008');
          let releaseOld;
          const oldStarted=new Promise(resolve=>releaseOld=resolve);
          await page.route('**/ajax_shop.php?act=orderList',async route=>{
            if((route.request().postData()||'').includes('0000000000000000004')) {
              const response=await route.fetch(); releaseOld();
              await new Promise(resolve=>setTimeout(resolve,400));
              await route.fulfill({response}).catch(()=>{});
            } else await route.continue();
          });
          await page.locator('#shop-search-keyword').fill('LP0000000000000000004');
          await page.locator('#toolbar button[type=submit]').click();
          await oldStarted;
          await page.locator('#shop-search-keyword').fill('LP0000000000000000008');
          await page.locator('#toolbar button[type=submit]').click();
          await page.waitForTimeout(650);
          assert(await page.evaluate(()=>$('#ordersTable').bootstrapTable('getData')[0]?.pay_trade_no==='LP0000000000000000008'),'slow old search never overwrites new filter');
          await page.unroute('**/ajax_shop.php?act=orderList');
          await page.route('**/ajax_shop.php?act=orderList',route=>route.fulfill({status:500,contentType:'application/json',body:'{}'}));
          await page.locator('.list-read-status button').click();
          await page.locator('.list-read-status.text-danger').waitFor();
          await page.unroute('**/ajax_shop.php?act=orderList');
          await page.locator('.list-read-status button').click();
          await page.locator('.list-read-status').filter({hasText:'列表实时查询'}).waitFor();
          assert(true,'shop failure and retry visible');
        }
        if(url.endsWith('/order.php') && width===390){
          await page.getByText('统计概况',{exact:true}).click();
          await page.locator('.order-summary-note').filter({hasText:'统计于'}).waitFor();
          await page.getByRole('button',{name:'刷新统计',exact:true}).click();
          await page.locator('.order-summary-note').filter({hasText:'统计于'}).waitFor();
          assert(true,'mobile summary and refresh '+url);
        }
        await context.close();
      }
    }
    assert(errors.length===0,'no browser JavaScript errors: '+errors.join(';'));
    fs.writeFileSync(path.join(output,'http-report.json'),JSON.stringify(report,null,2));
    console.log('LIST_QUERY_BROWSER_OK '+JSON.stringify(report));
  } finally {
    await browser.close();
    await Promise.all([admin.dispose(),merchant.dispose(),other.dispose(),anonymous.dispose()]);
  }
})().catch(error=>{console.error(error);process.exit(1);});
