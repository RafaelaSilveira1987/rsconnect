<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class OperationalAlertService
{
    private const DEFAULTS = ['critical_enabled'=>1,'warning_enabled'=>1,'evolution_enabled'=>1,'ai_enabled'=>1,'n8n_enabled'=>1,'backup_enabled'=>1,'routines_enabled'=>1,'platform_enabled'=>1,'whatsapp_enabled'=>0,'email_enabled'=>0,'whatsapp_recipient'=>'','email_recipient'=>'','reminder_hours'=>3];

    public function dashboard(?int $userId = null): array
    {
        $userId = $userId ?: (int) Auth::id();
        return ['preferences'=>$this->preferences($userId),'notifications'=>$this->notifications($userId),'unread'=>$this->unreadCount($userId),'deliveries'=>$this->deliveries($userId)];
    }

    public function preferences(int $userId): array
    {
        if ($userId < 1) return self::DEFAULTS;
        try {
            $st=Database::connection()->prepare('SELECT * FROM operational_alert_preferences WHERE user_id=:id LIMIT 1'); $st->execute(['id'=>$userId]); $row=$st->fetch(PDO::FETCH_ASSOC)?:[];
            return array_merge(self::DEFAULTS,$row);
        } catch(Throwable){ return self::DEFAULTS; }
    }

    public function savePreferences(int $userId, array $data): void
    {
        $email=trim((string)($data['email_recipient']??'')); if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('E-mail de alerta inválido.');
        $phone=trim((string)($data['whatsapp_recipient']??'')); $hours=max(1,min(72,(int)($data['reminder_hours']??3)));
        $flags=['critical_enabled','warning_enabled','evolution_enabled','ai_enabled','n8n_enabled','backup_enabled','routines_enabled','platform_enabled','whatsapp_enabled','email_enabled'];
        $v=[]; foreach($flags as $f)$v[$f]=!empty($data[$f])?1:0;
        $sql='INSERT INTO operational_alert_preferences (user_id,'.implode(',',$flags).',whatsapp_recipient,email_recipient,reminder_hours) VALUES (:user_id,'.implode(',',array_map(fn($f)=>':'.$f,$flags)).',:phone,:email,:hours) ON DUPLICATE KEY UPDATE '.implode(',',array_map(fn($f)=>$f.'=VALUES('.$f.')',$flags)).',whatsapp_recipient=VALUES(whatsapp_recipient),email_recipient=VALUES(email_recipient),reminder_hours=VALUES(reminder_hours)';
        Database::connection()->prepare($sql)->execute(array_merge(['user_id'=>$userId,'phone'=>$phone?:null,'email'=>$email?:null,'hours'=>$hours],$v));
    }

    public function dispatchOpened(int $incidentId): void { $this->dispatch($incidentId,'opened'); }
    public function dispatchRecovered(int $incidentId): void { $this->dispatch($incidentId,'recovered'); }

    public function dispatchReminderIfDue(int $incidentId): void
    {
        foreach($this->admins() as $admin){
            $uid=(int)$admin['id'];
            $p=$this->preferences($uid);
            $h=max(1,(int)($p['reminder_hours']??3));
            try{
                $st=Database::connection()->prepare("SELECT MAX(created_at) FROM operational_alert_deliveries WHERE incident_id=:i AND user_id=:u AND notification_kind IN ('opened','reminder')");
                $st->execute(['i'=>$incidentId,'u'=>$uid]);
                $last=(string)$st->fetchColumn();
                if($last==='') { $this->dispatch($incidentId,'opened',$uid); continue; }
                if(strtotime($last)>time()-$h*3600) continue;
            }catch(Throwable){}
            $this->dispatch($incidentId,'reminder',$uid);
        }
    }

    private function dispatch(int $incidentId,string $kind,?int $onlyUser=null): void
    {
        $incident=$this->incident($incidentId); if(!$incident)return;
        foreach($this->admins() as $admin){$uid=(int)$admin['id']; if($onlyUser&&$uid!==$onlyUser)continue; $p=$this->preferences($uid); if(!$this->enabled($incident,$p,$kind))continue;
            $diag=$this->diagnosticKey((string)$incident['event']); $url=(new OperationalPlaybookService())->centralUrl($diag,(int)($incident['tenant_id']??0));
            $title=$kind==='recovered'?'Resolvido — '.$this->label($diag):($kind==='reminder'?'Continua ativo — '.$this->label($diag):'Alerta — '.$this->label($diag));
            $message=$kind==='recovered'?'O monitoramento confirmou a recuperação. '.(string)$incident['message']:(string)$incident['message'];
            $severity=$kind==='recovered'?'success':(((string)$incident['severity']==='critical'||(string)$incident['severity']==='error')?'danger':'warning');
            if(!empty($p['platform_enabled']) && !$this->hasDelivery($incidentId,$uid,$kind,'platform')){$this->platform($uid,$incidentId,$kind,$severity,$title,$message,$url);$this->delivery($incidentId,$uid,$kind,'platform','sent',null,null);}
            if(!empty($p['whatsapp_enabled'])){$dest=trim((string)($p['whatsapp_recipient']??''));$this->delivery($incidentId,$uid,$kind,'whatsapp','pending_configuration',$dest?:null,'Canal externo preparado; configure o provedor administrativo da RS para envio.');}
            if(!empty($p['email_enabled'])){$dest=trim((string)($p['email_recipient']??''));$this->delivery($incidentId,$uid,$kind,'email','pending_configuration',$dest?:null,'Canal externo preparado; configure o transportador de e-mail da RS para envio.');}
        }
    }

    private function enabled(array $i,array $p,string $kind): bool { if($kind==='recovered') return true; $sev=(string)$i['severity']; if(in_array($sev,['critical','error'],true)&&empty($p['critical_enabled']))return false; if($sev==='warning'&&empty($p['warning_enabled']))return false; $k=$this->diagnosticKey((string)$i['event']); $map=['evolution'=>'evolution_enabled','openai'=>'ai_enabled','ai_reprocess'=>'ai_enabled','after_hours_recovery'=>'ai_enabled','n8n'=>'n8n_enabled','backup'=>'backup_enabled','billing_cron'=>'routines_enabled','reporting'=>'routines_enabled']; return empty($map[$k])||!empty($p[$map[$k]]); }
    private function diagnosticKey(string $event): string { $key=str_starts_with($event,'operations.alert.')?substr($event,17):(str_starts_with($event,'backup.')?'backup':'generic'); if(str_starts_with($key,'evolution.'))return 'evolution'; return $key; }
    private function label(string $k): string { return ['evolution'=>'WhatsApp / Evolution','openai'=>'OpenAI / IA','n8n'=>'n8n','backup'=>'Backup','billing_cron'=>'Cron de cobrança','ai_reprocess'=>'Fila da IA','after_hours_recovery'=>'Recuperação pós-horário','reporting'=>'Relatórios','database'=>'Banco de dados','migrations'=>'Migrations','calendar'=>'Google Agenda','payments'=>'Pagamentos','webhooks'=>'Webhooks'][$k]??'Operação RS'; }

    private function hasDelivery(int $i,int $u,string $k,string $c):bool{try{$s=Database::connection()->prepare('SELECT id FROM operational_alert_deliveries WHERE incident_id=:i AND user_id=:u AND notification_kind=:k AND channel=:c LIMIT 1');$s->execute(compact('i','u','k','c'));return(bool)$s->fetchColumn();}catch(Throwable){return false;}}
    private function platform(int $u,int $i,string $k,string $s,string $t,string $m,string $url): void { try{Database::connection()->prepare('INSERT IGNORE INTO admin_operational_notifications (user_id,incident_id,notification_kind,severity,title,message,action_url) VALUES (:u,:i,:k,:s,:t,:m,:url)')->execute(compact('u','i','k','s','t','m','url'));}catch(Throwable){} }
    private function delivery(int $i,int $u,string $k,string $c,string $s,?string $d,?string $e): void { try{Database::connection()->prepare('INSERT IGNORE INTO operational_alert_deliveries (incident_id,user_id,notification_kind,channel,status,destination,error_message) VALUES (:i,:u,:k,:c,:s,:d,:e)')->execute(compact('i','u','k','c','s','d','e'));}catch(Throwable){} }
    private function incident(int $id):?array{try{$s=Database::connection()->prepare('SELECT * FROM system_incidents WHERE id=:id LIMIT 1');$s->execute(['id'=>$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}catch(Throwable){return null;}}
    private function admins():array{try{return Database::connection()->query("SELECT id,name,email FROM users WHERE role='super_admin' AND status='active'")->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return [];}}
    private function notifications(int $u):array{try{$s=Database::connection()->prepare('SELECT * FROM admin_operational_notifications WHERE user_id=:u ORDER BY id DESC LIMIT 80');$s->execute(['u'=>$u]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return [];}}
    private function deliveries(int $u):array{try{$s=Database::connection()->prepare('SELECT * FROM operational_alert_deliveries WHERE user_id=:u ORDER BY id DESC LIMIT 40');$s->execute(['u'=>$u]);return $s->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return [];}}
    public function unreadCount(int $u):int{try{$s=Database::connection()->prepare("SELECT COUNT(*) FROM admin_operational_notifications WHERE user_id=:u AND status='unread'");$s->execute(['u'=>$u]);return(int)$s->fetchColumn();}catch(Throwable){return 0;}}
    public function markAllRead(int $u):void{try{Database::connection()->prepare("UPDATE admin_operational_notifications SET status='read',read_at=NOW() WHERE user_id=:u AND status='unread'")->execute(['u'=>$u]);}catch(Throwable){}}
}
