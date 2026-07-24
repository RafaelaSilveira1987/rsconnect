<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use PDO;
use Throwable;

final class ClientCommunicationService
{
    public function dashboard(): array
    {
        return ['tenants'=>$this->tenants(),'history'=>$this->history(),'summary'=>$this->summary()];
    }

    public function send(array $data): int
    {
        $title=mb_substr(trim((string)($data['title']??'')),0,180); $message=trim((string)($data['message']??'')); if($title===''||$message==='')throw new \RuntimeException('Informe título e mensagem.');
        $type=(string)($data['communication_type']??'information'); if(!in_array($type,['information','maintenance','attention','incident','resolved'],true))$type='information';
        $aud=(string)($data['audience_type']??'selected'); if(!in_array($aud,['selected','all','incident'],true))$aud='selected';
        $channels=['in_app'=>1,'whatsapp'=>!empty($data['channel_whatsapp'])?1:0,'email'=>!empty($data['channel_email'])?1:0];
        $tenantIds=$aud==='all'?array_column($this->tenants(),'id'):array_values(array_unique(array_filter(array_map('intval',(array)($data['tenant_ids']??[]))))); if(!$tenantIds)throw new \RuntimeException('Selecione pelo menos uma empresa.');
        $pdo=Database::connection(); $pdo->beginTransaction(); try{
            $st=$pdo->prepare('INSERT INTO client_communications (communication_type,title,message,audience_type,incident_id,channels_json,created_by,sent_at) VALUES (:type,:title,:message,:aud,:incident,:channels,:user,NOW())');
            $st->execute(['type'=>$type,'title'=>$title,'message'=>$message,'aud'=>$aud,'incident'=>(int)($data['incident_id']??0)?:null,'channels'=>json_encode($channels,JSON_UNESCAPED_UNICODE),'user'=>Auth::id()]); $id=(int)$pdo->lastInsertId();
            foreach($tenantIds as $tenantId){$notificationId=$this->notification($pdo,$tenantId,$type,$title,$message,$id); $wa=$channels['whatsapp']?'pending_configuration':'not_requested';$em=$channels['email']?'pending_configuration':'not_requested';
                $pdo->prepare('INSERT INTO client_communication_recipients (communication_id,tenant_id,notification_id,in_app_status,whatsapp_status,email_status) VALUES (:c,:t,:n,"sent",:w,:e)')->execute(['c'=>$id,'t'=>$tenantId,'n'=>$notificationId?:null,'w'=>$wa,'e'=>$em]);}
            $pdo->commit(); return $id;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack(); throw $e;}
    }

    private function notification(PDO $pdo,int $tenantId,string $type,string $title,string $message,int $commId):int
    { $severity=['information'=>'info','maintenance'=>'warning','attention'=>'warning','incident'=>'danger','resolved'=>'success'][$type]??'info'; $st=$pdo->prepare('INSERT INTO client_notifications (tenant_id,type,severity,title,message,action_url,source_event,reference_type,reference_id,metadata_json) VALUES (:t,"communication",:s,:title,:message,"/notifications","rs.communication","communication",:id,:meta)');$st->execute(['t'=>$tenantId,'s'=>$severity,'title'=>mb_substr($title,0,160),'message'=>$message,'id'=>$commId,'meta'=>json_encode(['communication_type'=>$type],JSON_UNESCAPED_UNICODE)]);return(int)$pdo->lastInsertId(); }
    private function tenants():array{
        try{return Database::connection()->query("SELECT id,name,email,COALESCE(NULLIF(commercial_whatsapp,''),phone) AS admin_phone,plan,status FROM tenants WHERE status IN ('active','suspended') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)?:[];}
        catch(Throwable){try{return Database::connection()->query("SELECT id,name,email,phone AS admin_phone,plan,status FROM tenants WHERE status IN ('active','suspended') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return [];}}
    }
    private function history():array{try{return Database::connection()->query("SELECT c.*,COUNT(r.id) recipients,SUM(CASE WHEN n.status='read' THEN 1 ELSE 0 END) read_count,SUM(CASE WHEN r.whatsapp_status='pending_configuration' THEN 1 ELSE 0 END) whatsapp_pending,SUM(CASE WHEN r.email_status='pending_configuration' THEN 1 ELSE 0 END) email_pending FROM client_communications c LEFT JOIN client_communication_recipients r ON r.communication_id=c.id LEFT JOIN client_notifications n ON n.id=r.notification_id GROUP BY c.id ORDER BY c.id DESC LIMIT 60")->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable){return [];}}
    private function summary():array{$h=$this->history();return ['sent'=>count($h),'recipients'=>array_sum(array_map(fn($r)=>(int)($r['recipients']??0),$h)),'read'=>array_sum(array_map(fn($r)=>(int)($r['read_count']??0),$h))];}
}
