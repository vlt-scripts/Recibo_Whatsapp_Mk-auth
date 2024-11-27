<?php
function obterLimpeza() {
    // Caminho para o arquivo temporário onde o cron foi salvo
    $cronFilePath = '/tmp/cron_limpeza_tabela';
    
    // Lê o conteúdo do cron para verificar o agendamento de limpeza
    $cronAtual = shell_exec("crontab -l");
    $agendamentoLimpeza = "";

    // Verifica se o comando específico de limpeza está no cron
    if (strpos($cronAtual, '/opt/mk-auth/admin/addons/Recibo_Whatsapp/limpar_tabela.php') !== false) {
        // Extrai a linha de agendamento específica
        $linhas = explode("\n", $cronAtual);
        foreach ($linhas as $linha) {
            if (strpos($linha, '/opt/mk-auth/admin/addons/Recibo_Whatsapp/limpar_tabela.php') !== false) {
                // Pega a linha de agendamento e separa os parâmetros
                $partes = preg_split('/\s+/', $linha);
                $minuto = $partes[0];
                $hora = $partes[1];
                $intervaloDias = intval(end($partes));  // Intervalo de dias, passado como argumento

                // Monta a descrição do agendamento
                $agendamentoLimpeza = "Agendamento: Todos os dias às $hora:$minuto, excluindo registros com mais de $intervaloDias dias.";
                break;
            }
        }
    } else {
        $agendamentoLimpeza = "Nenhum agendamento de limpeza configurado.";
    }

    return htmlspecialchars($agendamentoLimpeza);
}
?>
