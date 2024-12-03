<?php
// Configurações de agendamento da limpeza
$horaExecucao = $_POST['hora_execucao'];
$intervaloDias = (int)$_POST['intervalo_dias'];

if (!$horaExecucao || !$intervaloDias) {
    echo json_encode(["status" => "error", "message" => "Hora de execução ou intervalo de dias inválido."]);
    exit;
}

list($hora, $minuto) = explode(':', $horaExecucao);

// Comando da limpeza com intervalo de dias como argumento
$cronComando = "/usr/bin/php -q /opt/mk-auth/admin/addons/Recibo_Whatsapp/limpar_tabela.php $intervaloDias > /dev/null 2>&1";

// Lê o conteúdo atual do crontab
$cronAtual = shell_exec("crontab -l 2>/dev/null");

// Garante que o comando atual de limpeza não esteja duplicado
$linhasCron = explode("\n", $cronAtual);
$novoCron = [];

foreach ($linhasCron as $linha) {
    if (strpos($linha, '/opt/mk-auth/admin/addons/Recibo_Whatsapp/limpar_tabela.php') === false) {
        // Adiciona as linhas que não são relacionadas ao comando de limpeza
        $novoCron[] = $linha;
    }
}

// Adiciona o novo agendamento de limpeza
$novoCron[] = "$minuto $hora * * * $cronComando";

// Remove linhas vazias e prepara o conteúdo para salvar no crontab
$novoCronString = implode("\n", array_filter($novoCron));

// Salva o novo conteúdo do crontab
$cronFilePath = '/tmp/cron_limpeza_tabela';
file_put_contents($cronFilePath, $novoCronString . PHP_EOL);
exec("crontab $cronFilePath");

// Resposta em JSON para confirmação
echo json_encode([
    "status" => "success",
    "message" => "Agendamento de limpeza criado com sucesso para as $horaExecucao todos os dias, com intervalo de $intervaloDias dias."
]);
exit;
?>
