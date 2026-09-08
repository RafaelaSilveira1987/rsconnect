<?php

declare(strict_types=1);

use App\Core\Database;

require_once dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['tenant::', 'apply', 'limit::']);
$tenantId = isset($options['tenant']) ? max(0, (int) $options['tenant']) : 0;
$apply = array_key_exists('apply', $options);
$limit = max(1, min(5000, (int) ($options['limit'] ?? 1000)));
$pdo = Database::connection();

function digits(string $value): string { return preg_replace('/\D+/', '', $value) ?: ''; }
function validBrPhone(string $value): bool { $d = digits($value); return str_starts_with($d, '55') && in_array(strlen($d), [12,13], true); }
function phoneFromCandidate(string $value): string {
    $value = trim($value);
    if ($value === '' || str_ends_with(strtolower($value), '@lid') || str_ends_with(strtolower($value), '@g.us')) return '';
    $d = digits(strstr($value, '@', true) ?: $value);
    if (in_array(strlen($d), [10,11], true)) $d = '55'.$d;
    return validBrPhone($d) ? $d : '';
}
function walkCandidates(mixed $value, array &$phones, array &$names, int $depth=0): void {
    if ($depth > 8 || !is_array($value)) return;
    foreach ($value as $key=>$item) {
        $k = strtolower((string)$key);
        if (is_scalar($item)) {
            $v = trim((string)$item);
            if (in_array($k,['remotejidalt','senderpn','participantpn','senderjid','participantjid','remotejid','sender','participant','number','phone'],true)) {
                $p = phoneFromCandidate($v); if ($p !== '') $phones[]=$p;
            }
            if (in_array($k,['pushname','verifiedname','sendername','notify','profilename','name'],true) && $v !== '' && !preg_match('/^\d+$/',$v)) $names[]=$v;
        } elseif (is_array($item)) walkCandidates($item,$phones,$names,$depth+1);
    }
}

$sql='SELECT c.id,c.tenant_id,c.phone,c.remote_jid,c.name,c.name_source,c.whatsapp_name_candidate
      FROM contacts c WHERE (c.phone NOT LIKE "55%" OR CHAR_LENGTH(c.phone) NOT IN (12,13) OR c.remote_jid LIKE "%@lid")';
$params=[];
if ($tenantId>0) { $sql.=' AND c.tenant_id=:tenant_id'; $params['tenant_id']=$tenantId; }
$sql.=' ORDER BY c.id ASC LIMIT '.$limit;
$stmt=$pdo->prepare($sql); $stmt->execute($params); $contacts=$stmt->fetchAll(PDO::FETCH_ASSOC);
$summary=['mode'=>$apply?'apply':'dry-run','checked'=>count($contacts),'repairable'=>0,'updated'=>0,'collisions'=>0,'unresolved'=>0,'items'=>[]];
foreach ($contacts as $c) {
    $m=$pdo->prepare('SELECT cm.raw_payload_json FROM conversation_messages cm INNER JOIN conversations cv ON cv.id=cm.conversation_id WHERE cv.tenant_id=:tenant AND cv.contact_id=:contact AND cm.direction="incoming" AND cm.raw_payload_json IS NOT NULL ORDER BY cm.id DESC LIMIT 30');
    $m->execute(['tenant'=>(int)$c['tenant_id'],'contact'=>(int)$c['id']]);
    $phones=[];$names=[];
    foreach ($m->fetchAll(PDO::FETCH_COLUMN) as $raw) { $json=json_decode((string)$raw,true); if(is_array($json)) walkCandidates($json,$phones,$names); }
    $phones=array_values(array_unique(array_filter($phones,'validBrPhone')));
    $newPhone=$phones[0]??'';
    $newName='';
    foreach (array_values(array_unique($names)) as $candidate) {
        $n=mb_strtolower(trim($candidate));
        if (!in_array($n,['unknown','desconhecido','sem nome','whatsapp','meta ai'],true) && !preg_match('/^\d+$/',$candidate)) { $newName=trim($candidate); break; }
    }
    if ($newPhone==='') { $summary['unresolved']++; $summary['items'][]=['id'=>(int)$c['id'],'old_phone'=>$c['phone'],'status'=>'unresolved']; continue; }
    $collision=$pdo->prepare('SELECT id FROM contacts WHERE tenant_id=:tenant AND phone=:phone AND id<>:id LIMIT 1');
    $collision->execute(['tenant'=>(int)$c['tenant_id'],'phone'=>$newPhone,'id'=>(int)$c['id']]);
    if ($collision->fetchColumn()) { $summary['collisions']++; $summary['items'][]=['id'=>(int)$c['id'],'old_phone'=>$c['phone'],'new_phone'=>$newPhone,'status'=>'collision']; continue; }
    $summary['repairable']++;
    if ($apply) {
        $set=['phone=:phone','remote_jid=:jid']; $up=['phone'=>$newPhone,'jid'=>$newPhone.'@s.whatsapp.net','id'=>(int)$c['id'],'tenant'=>(int)$c['tenant_id']];
        $source=(string)($c['name_source']??'');
        if ($newName!=='' && in_array($source,['','legacy','unknown','whatsapp'],true)) { $set[]='name=:name';$set[]='name_source="whatsapp"';$set[]='whatsapp_name_candidate=:name';$set[]='whatsapp_name_seen_count=GREATEST(whatsapp_name_seen_count,1)';$up['name']=$newName; }
        $u=$pdo->prepare('UPDATE contacts SET '.implode(',',$set).' WHERE id=:id AND tenant_id=:tenant'); $u->execute($up); $summary['updated']++;
    }
    $summary['items'][]=['id'=>(int)$c['id'],'old_phone'=>$c['phone'],'new_phone'=>$newPhone,'name'=>$newName?:$c['name'],'status'=>$apply?'updated':'repairable'];
}
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
