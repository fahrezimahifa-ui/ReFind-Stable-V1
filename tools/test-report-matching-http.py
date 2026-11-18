import json
from report_test_client import Client,data
from verification_receipt import complete_receipt
checks=[]
def check(label,ok):
 if not ok:raise AssertionError('MATCHING HTTP GATE FAILED: '+label)
 checks.append(label)
primary_category = '1'
comparison_category = '3'
category_status, references, _, _ = Client().call('/api/references.php')
check('matching fixture references load', category_status == 200)
active_categories = {str(category['id']) for category in references['categories']}
check('fixture categories are distinct and active', primary_category != comparison_category and {primary_category, comparison_category} <= active_categories)
check('category 2 remains inactive', '2' not in active_categories)
a=Client();a.login('student_a');b=Client();b.login('student_b');bm=Client();bm.login('bm')
bm_before=bm.call('/api/notifications.php?count=1')[1]['unread_count']
payload=data(a,category_id=primary_category,title='Quartz jade cobalt amber copper',description='Matching HTTP public vocabulary.',date='2026-01-15')
s,created,_,_=a.call('/api/items.php',payload);check('Lost create contract',s==201);lost=created['id']
before_found=b.call('/api/notifications.php?count=1')[1]['unread_count']
s,created,_,_=b.call('/api/items.php',payload|dict(type='FOUND'));check('Found create contract',s==201);found=created['id']
item=a.call('/api/items.php?id='+lost)[1]['item'];matches=item['matches'];pair=[x for x in matches if x['item_id']==found]
check('new suggestion visible at score 100',len(pair)==1 and float(pair[0]['match_score'])==100)
check('idle report transitioned to possible match',item['status_code']=='POTENTIAL_MATCH')
check('matching public projection excludes private clue','verification_clue' not in json.dumps(matches) and payload['clue'] not in json.dumps(matches))
check('new Found alerts scoped to Found owner',b.call('/api/notifications.php?count=1')[1]['unread_count']==before_found+2)
check('BM receives no Student matching alerts',bm.call('/api/notifications.php?count=1')[1]['unread_count']==bm_before)
count=a.call('/api/notifications.php?count=1')[1]['unread_count']
s,_,_,_=a.call('/api/items.php',payload|dict(action='update',id=lost));check('unchanged report update works',s==200)
check('unchanged pair sends no duplicate alert',a.call('/api/notifications.php?count=1')[1]['unread_count']==count)
check('other owner still cannot edit',b.call('/api/items.php',payload|dict(action='update',id=lost))[0]==403)
inactive_status, inactive_error, _, _ = b.call('/api/items.php', payload | dict(action='update', id=found, type='FOUND', category_id='2'))
check('inactive category update is rejected', inactive_status == 422 and inactive_error['code'] == 'validation_error' and 'category_id' in inactive_error['fields'])
s,_,_,_=b.call('/api/items.php',payload|dict(action='update',id=found,type='FOUND',category_id=comparison_category));check('category edit works',s==200)
check('category change removes stale suggestion',not any(x['item_id']==found for x in a.call('/api/items.php?id='+lost)[1]['item']['matches']))
check('losing suggestion produces no alert for Lost owner',a.call('/api/notifications.php?count=1')[1]['unread_count']==count)
check('Found eligible delete works',b.call('/api/items.php',dict(action='delete',id=found))[0]==200)
check('Lost eligible delete works',a.call('/api/items.php',dict(action='delete',id=lost))[0]==200)
check('owner deletion notification count increases once',a.call('/api/notifications.php?count=1')[1]['unread_count']==count+1)
check('notification marking stays unavailable',a.call('/api/notifications.php',dict(action='read'))[0]==501)
complete_receipt(len(checks),dict(checks=checks,native_traffic=False));print(json.dumps(dict(matching_http_checks_passed=len(checks))))
