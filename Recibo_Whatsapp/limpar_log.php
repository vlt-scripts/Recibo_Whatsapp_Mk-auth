<?php
$logFile = '/opt/mk-auth/dados/Recibo_Whatsapp/log_pagamentos.txt';
if (file_exists($logFile)) {
    file_put_contents($logFile, ''); // Limpa o conteúdo do arquivo de log
    echo "<script>alert('Log limpo com sucesso!');</script>";
} else {
    echo "<script>alert('Arquivo de log não encontrado.');</script>";
}

// Redireciona para a página anterior usando JavaScript
echo "<script>window.location.href = '{$_SERVER['HTTP_REFERER']}';</script>";
exit;
?>
