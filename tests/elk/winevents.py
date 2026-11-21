"""Test events shaped as Winlogbeat sends them from a domain controller (one JSON object per line), for the Elk node test.

python3 winevents.py [DC-NAME] > events.ndjson
"""
import datetime, json, sys

dc = sys.argv[1] if len(sys.argv) > 1 else "DC01.office.example"
now = datetime.datetime.now(datetime.timezone.utc).replace(microsecond=0)
# the last Sunday at 03:15 (outside working hours, the office at UTC+0)
sunday = (now - datetime.timedelta(days=(now.weekday() + 1) % 7 or 7)).replace(hour=3, minute=15, second=0)


def ev(eid, data, when=None, user_data=None, tz="+00:00"):
    t = when or now
    w = {"event_id": str(eid), "channel": "Security", "computer_name": dc, "provider_name": "Microsoft-Windows-Security-Auditing",
         "event_data": data}
    if user_data:
        w["user_data"] = user_data
    return {"@timestamp": t.strftime("%Y-%m-%dT%H:%M:%S.000Z"), "event": {"code": str(eid), "provider": "Microsoft-Windows-Security-Auditing", "timezone": tz},
            "winlog": w, "message": "Security event %s" % eid}


E = [
    ev(4624, {"TargetUserName": "jsmith", "TargetDomainName": "OFFICE", "LogonType": "10", "IpAddress": "10.0.1.25", "WorkstationName": "PC-25"}, sunday),
    ev(4624, {"TargetUserName": "akhan", "TargetDomainName": "OFFICE", "LogonType": "2", "IpAddress": "-", "WorkstationName": "DC01"}),
] + [
    ev(4625, {"TargetUserName": "mlee", "TargetDomainName": "OFFICE", "LogonType": "3", "IpAddress": "203.0.113.9", "WorkstationName": "-",
              "Status": "0xC000006D", "SubStatus": "0xC000006A"}) for _ in range(3)
] + [
    ev(4740, {"TargetUserName": "mlee", "TargetDomainName": "PC-31", "SubjectUserName": "DC01$"}),
    ev(4728, {"TargetUserName": "Domain Admins", "MemberName": "CN=Eve Example,OU=Staff,DC=office,DC=example", "SubjectUserName": "admin.rb"}),
    ev(4720, {"TargetUserName": "newhire", "SubjectUserName": "admin.rb"}),
    ev(4724, {"TargetUserName": "mlee", "SubjectUserName": "helpdesk"}),
    ev(4771, {"TargetUserName": "tgarcia", "IpAddress": "::ffff:10.0.1.40", "FailureCode": "0x18"}),
    ev(1102, {}, user_data={"SubjectUserName": "admin.rb", "SubjectDomainName": "OFFICE"}),
    ev(5136, {"SubjectUserName": "admin.rb", "ObjectDN": "CN={31B2F340-016D-11D2-945F-00C04FB984F9},CN=Policies,CN=System,DC=office,DC=example",
              "ObjectClass": "groupPolicyContainer", "AttributeLDAPDisplayName": "versionNumber"}),
    ev(4768, {"TargetUserName": "akhan", "TargetDomainName": "OFFICE", "IpAddress": "::ffff:10.0.1.12", "Status": "0x0"}),
]
for e in E:
    print(json.dumps(e))
