<?php

declare(strict_types=1);
namespace App\Controllers;
use App\Core\Auth; use App\Core\Csrf; use App\Core\Flash; use App\Core\Router; use App\Core\View; use App\Services\OperationalAlertService; use Throwable;
final class OperationalAlertsController {
 public function index():void{View::render('operations.alerts',['title'=>'Alertas operacionais','data'=>(new OperationalAlertService())->dashboard()]);}
 public function save():void{if(!Csrf::validate($_POST['_token']??null)){$this->go('error','Sessão expirada.');}try{(new OperationalAlertService())->savePreferences((int)Auth::id(),$_POST);$this->go('success','Preferências de alertas salvas.');}catch(Throwable $e){$this->go('error',$e->getMessage());}}
 public function readAll():void{if(Csrf::validate($_POST['_token']??null))(new OperationalAlertService())->markAllRead((int)Auth::id());$this->go('success','Alertas marcados como lidos.');}
 public function count():void{$d=(new OperationalAlertService())->dashboard((int)Auth::id());header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true,'count'=>(int)($d['unread']??0),'latest'=>($d['notifications'][0]??null)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
 private function go(string $t,string $m):never{Flash::set($t,$m);header('Location: '.Router::url('/operacao-alertas'));exit;}
}
