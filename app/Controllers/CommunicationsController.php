<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Core\Csrf; use App\Core\Flash; use App\Core\Router; use App\Core\View; use App\Services\ClientCommunicationService; use Throwable;
final class CommunicationsController {
 public function index():void{View::render('communications.index',['title'=>'Comunicados','data'=>(new ClientCommunicationService())->dashboard(),'prefillTenant'=>(int)($_GET['tenant_id']??0),'prefillIncident'=>(int)($_GET['incident_id']??0),'prefillType'=>(string)($_GET['type']??'')]);}
 public function send():void{if(!Csrf::validate($_POST['_token']??null)){$this->go('error','Sessão expirada.');}try{(new ClientCommunicationService())->send($_POST);$this->go('success','Comunicado enviado pelo RS Connect. Canais externos ficaram registrados conforme a configuração.');}catch(Throwable $e){$this->go('error',$e->getMessage());}}
 private function go(string $t,string $m):never{Flash::set($t,$m);header('Location: '.Router::url('/comunicados'));exit;}
}
