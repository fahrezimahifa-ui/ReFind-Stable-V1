from verification_receipt import complete_receipt
from pathlib import Path
import json,hashlib,datetime
from report_test_client import Client,png,data,ROOT
checks=[]
def check(label,ok):
 if not ok:raise AssertionError('CONSISTENCY GATE FAILED: '+label)
 checks.append(label)
def media():return {str(p.relative_to(ROOT/'state/academic-test/app/private/refind')):hashlib.sha256(p.read_bytes()).hexdigest() for folder in ['items','evidence','claims','avatars'] for p in (ROOT/'state/academic-test/app/private/refind'/folder).iterdir() if p.is_file()}
a=Client();a.login('student_a');b=Client();b.login('student_b');bm=Client();bm.login('bm');guest=Client();payload=data(a);image=png();upload=[('photos[]','photo.png','image/png',image),('ownershipPhotos[]','proof.png','image/png',image)]
check('guest cannot create',guest.call('/api/items.php',payload)[0]==401)
check('BM cannot create',bm.call('/api/items.php',payload)[0]==403)
check('CSRF mandatory for report creation',a.call('/api/items.php',payload,token=False)[0]==403)
for field,value in [('title','xy'),('clue','short'),('category_id','999999'),('location_id','999999'),('date','2026-02-30'),('time','25:61')]:
 code,obj,_,_=a.call('/api/items.php',payload|{field:value});check('report validation '+field,code==422 and obj['ok'] is False)
future=(datetime.date.fromisoformat(payload['date'])+datetime.timedelta(days=1)).isoformat();check('future campus date rejected',a.call('/api/items.php',payload|{'date':future})[0]==422)
check('actor cannot be supplied',a.call('/api/items.php',payload|{'reporter_user_id':'2'})[0]==400)
check('AI opt in stays disabled',a.call('/api/items.php',payload|{'visual_opt_in':'1'})[0]==422)
for label,files in [('invalid signature',[('photos[]','bad.png','image/png',b'<?php echo 1;')]),('oversized prepared image',[('photos[]','big.png','image/png',image+b'x'*250001)]),('dimensions',[('photos[]','wide.png','image/png',png(1201,1))]),('too many public photos',[('photos[]',str(i)+'.png','image/png',image) for i in range(5)]),('too many private photos',[('ownershipPhotos[]',str(i)+'.png','image/png',image) for i in range(3)]),('malformed collection',[('photos[name]','image.png','image/png',image)])]:
 before=media();code,obj,_,_=a.call('/api/items.php',payload,files);check('upload rejected '+label,code==422);check('rejected upload leaves authoritative files unchanged '+label,media()==before)
check('Found cannot upload Lost ownership evidence',a.call('/api/items.php',payload|{'type':'FOUND'},[upload[1]])[0]==422)
code,obj,_,_=a.call('/api/items.php',payload,upload);check('actual multipart create 201',code==201 and obj['action']=='create' and isinstance(obj['id'],str));id=obj['id']
code,detail,_,_=a.call('/api/items.php?id='+id+'&editable=1');item=detail['item'];check('owned editable metadata',code==200 and item['private_clue']==payload['clue'] and len(item['ownership_photo_ids'])==1)
public=item['photo_ids'][0];proof=item['ownership_photo_ids'][0]
check('other Student cannot get editable data',b.call('/api/items.php?id='+id+'&editable=1')[0]==403)
publicView=a.call('/api/items.php?id='+id)[1]['item'];check('public projection hides ownership secrets','private_clue' not in publicView and 'verification_clue' not in publicView)
check('Student cannot read own private evidence',a.call('/api/photos.php?kind=item&id='+str(proof))[0]==403)
code,_,headers,_=bm.call('/api/photos.php?kind=item&id='+str(proof));check('BM reads evidence privately',code==200 and 'private' in headers['Cache-Control'])
check('public report photo available to other Student',b.call('/api/photos.php?kind=item&id='+str(public))[0]==200)
check('other Student cannot update',b.call('/api/items.php',payload|{'action':'update','id':id})[0]==403)
check('other Student cannot delete',b.call('/api/items.php',{'action':'delete','id':id})[0]==403)
for label,extra,status in [('foreign public retained ID',{'keep_photo_ids':'[1]'},403),('private ID as public',{'keep_photo_ids':json.dumps([proof])},403),('duplicate retained ID',{'keep_photo_ids':json.dumps([public,public])},422),('invalid retained JSON',{'keep_photo_ids':'broken'},422),('retained-plus-new overflow',{'keep_photo_ids':json.dumps([public])},422)]:
 before=media();files=[('photos[]','new.png','image/png',image)]*(4 if label=='retained-plus-new overflow' else 1)
 code,_,_,_=a.call('/api/items.php',payload|{'action':'update','id':id}|extra,files);check(label,code==status);check(label+' rollback media',media()==before)
check('report type immutable',a.call('/api/items.php',payload|{'action':'update','id':id,'type':'FOUND'})[0]==409)
check('active claim locks report',b.call('/api/items.php',{'action':'delete','id':'5'})[0]==409)
check('terminal report locked',b.call('/api/items.php',{'action':'delete','id':'3'})[0]==409)
check('confirmed pair locks report',a.call('/api/items.php',{'action':'delete','id':'6'})[0]==409)
code,_,_,_=a.call('/api/items.php',payload|{'action':'update','id':id,'title':'Updated synthetic multipart report','keep_photo_ids':'[]','keep_ownership_ids':json.dumps([proof])},[('photos[]','replacement.png','image/png',image)]);check('replace photo update 200',code==200)
check('replaced photo ID no longer served',a.call('/api/photos.php?kind=item&id='+str(public))[0]==404)
check('retained evidence still private and present',bm.call('/api/photos.php?kind=item&id='+str(proof))[0]==200)
check('updated report appears in My Reports',any(x['item_id']==id for x in a.call('/api/items.php?mine=1&active=1&limit=48')[1]['items']))
check('report view enabled',a.call('/report.html')[0]==200);check('My Reports view enabled',a.call('/my-reports.html')[0]==200);check('claims tab read view enabled',a.call('/my-reports.html?tab=claims')[0]==200);check('claim form remains pending',a.call('/claim.html')[0]==501)
code,obj,_,_=a.call('/api/items.php',{'action':'delete','id':id});check('eligible delete contract',code==200 and obj=={'ok':True,'id':id,'action':'delete'})
check('deleted report read is 404',a.call('/api/items.php?id='+id)[0]==404);check('deleted private photo gone',bm.call('/api/photos.php?kind=item&id='+str(proof))[0]==404)
fixture=json.loads((ROOT/'state/academic-test/phase5-eligibility.json').read_text());check('closed claim report can delete',a.call('/api/items.php',{'action':'delete','id':'4'})[0]==200);check('cascaded closed claim media cleaned',not (ROOT/'state/academic-test/app/private/refind'/fixture['claim_photo']).exists())
for path in ['claims.php','staff.php','handovers.php','account.php','notifications.php']:
 actor=bm if path in ['staff.php','handovers.php'] else a
 check('unsupported operation '+path,actor.call('/api/'+path,{'action':'submit'})[0]==501)
(ROOT/'evidence/phase5/report-http-results.json').write_text(json.dumps({'passed':len(checks),'checks':checks,'native_traffic':False},indent=2),encoding='utf-8');print(json.dumps({'report_http_checks_passed':len(checks)}))
complete_receipt(len(checks), {'checks': checks, 'native_traffic': False})
