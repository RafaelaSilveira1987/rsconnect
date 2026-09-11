<?php
declare(strict_types=1);
$root=dirname(__DIR__,2);
$controller=(string)file_get_contents($root.'/app/Controllers/ReportController.php');$routes=(string)file_get_contents($root.'/routes/web.php');$tenant=(string)file_get_contents($root.'/app/Views/reports/index.php');$admin=(string)file_get_contents($root.'/app/Views/reports/admin.php');$team=(string)file_get_contents($root.'/app/Views/reports/team.php');$pdf=(string)file_get_contents($root.'/app/Services/ExecutiveReportPdfService.php');$teamPdf=(string)file_get_contents($root.'/app/Services/TeamProfessionalReportPdfService.php');$version=(string)file_get_contents($root.'/app/Services/AppVersionService.php');$manifest=(string)file_get_contents($root.'/manifest.json');
$checks=[
'rota PDF executivo'=>str_contains($routes,"'/reports/pdf'")&&str_contains($controller,'public function pdf(): void'),
'rota PDF equipe'=>str_contains($routes,"'/reports/team/pdf'")&&str_contains($controller,'public function teamPdf(): void'),
'resposta PDF segura'=>str_contains($controller,'Content-Type: application/pdf')&&str_contains($controller,"str_starts_with(\$bytes, '%PDF-')"),
'cliente tem botão PDF'=>str_contains($tenant,'Salvar PDF')&&str_contains($tenant,"'/reports/pdf?"),
'admin tem botão PDF'=>str_contains($admin,'Salvar PDF')&&str_contains($admin,"'/reports/pdf?"),
'equipe tem botão PDF'=>str_contains($team,'Salvar PDF')&&str_contains($team,"'/reports/team/pdf?"),
'CSV permanece'=>str_contains($tenant,'>CSV<')&&str_contains($team,'CSV equipe'),
'PDF cliente comercial'=>str_contains($pdf,"data['crmByStage']")&&str_contains($pdf,"'Comercial'"),
'PDF cliente cobranças'=>str_contains($pdf,"'Cobranças da empresa'")&&str_contains($pdf,"data['recentInvoices']"),
'PDF admin receita'=>str_contains($pdf,"'Receita e cobranças'")&&str_contains($pdf,"data['revenueByPlan']"),
'PDF equipe SLA'=>str_contains($teamPdf,"'SLA'")&&str_contains($teamPdf,'sla_compliance'),
'PDF equipe setores'=>str_contains($teamPdf,"'Carga atual por setor'")&&str_contains($teamPdf,'departmentPerformance'),
'release registrada'=>str_contains($version,"PACKAGE_LABEL = 'RS Connect 36.31.1 — Relatórios PDF e homologação conversacional'"),
'manifesto'=>str_contains($manifest,'"package_version": "36.31.2"')&&str_contains($manifest,'"package_version": "36.31.1"')];
$p=0;foreach($checks as $l=>$ok){echo($ok?'[OK] ':'[FAIL] ').$l.PHP_EOL;$p+=$ok?1:0;}if($p!==count($checks)){fwrite(STDERR,"Falha: {$p}/".count($checks).PHP_EOL);exit(1);}echo"OK - relatórios PDF 36.31.1: {$p}/".count($checks)."\n";
