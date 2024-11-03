<?php
$logFile = '/opt/mk-auth/dados/Recibo_Whatsapp/log_pagamentos.txt';
if (file_exists($logFile)) {
    file_put_contents($logFile, ''); // Limpa o conteúdo do arquivo de log
    echo "Log limpo com sucesso!";
} else {
    echo "Arquivo de log não encontrado.";
}
header("Location: {$_SERVER['HTTP_REFERER']}"); // Redireciona de volta para a página anterior
exit;
?>
