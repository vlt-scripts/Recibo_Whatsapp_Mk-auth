<?php
// Configurações de agendamento da limpeza
$horaExecucao = $_POST['hora_execucao'];
$intervaloDias = (int)$_POST['intervalo_dias'];

if (!$horaExecucao || !$intervaloDias) {
    echo json_encode(["status" => "error", "message" => "Hora de execução ou intervalo de dias inválido."]);
    exit;
}

list($hora, $minuto) = explode(':', $horaExecucao);

$cronComando = "/usr/bin/php -q /opt/mk-auth/admin/addons/Recibo_Whatsapp/limpar_tabela.php $intervaloDias > /dev/null 2>&1";
$cronAgendamento = "$minuto $hora * * * $cronComando" . PHP_EOL;

$cronFilePath = '/tmp/cron_limpeza_tabela';
file_put_contents($cronFilePath, $cronAgendamento);
exec("crontab $cronFilePath");

// Resposta em JSON para confirmação
echo json_encode([
    "status" => "success",
    "message" => "Agendamento de limpeza criado com sucesso para as $horaExecucao todos os dias, com intervalo de $intervaloDias dias."
]);
exit;
?>
