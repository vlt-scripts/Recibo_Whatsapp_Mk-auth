<?php
// Configurações de conexão com o banco de dados usando variáveis de ambiente para evitar exposição direta das credenciais
$host = getenv('DB_HOST') ?: 'localhost';
$usuario = getenv('DB_USER') ?: 'root';
$senha = getenv('DB_PASS') ?: 'vertrigo';
$db = getenv('DB_NAME') ?: 'mkradius';

// Caminho do arquivo de log
$logFile = '/opt/mk-auth/dados/Recibo_Whatsapp/log_pagamentos.txt';

// Função para registrar mensagens no log
function registrarLog($mensagem) {
    global $logFile;
    $dataHora = date("Y-m-d H:i:s");
    error_log("[$dataHora] $mensagem\n", 3, $logFile);
}

// Recebe o intervalo de dias como argumento, usando um valor padrão de 30 dias se não for passado
$intervaloDias = isset($argv[1]) ? (int)$argv[1] : 30;

// Criação de conexão segura usando try-catch para lidar com exceções
try {
    $conn = new mysqli($host, $usuario, $senha, $db);
    if ($conn->connect_error) {
        throw new Exception("Falha na conexão: " . $conn->connect_error);
    }

    // SQL preparada para excluir registros com base no intervalo configurado
    $sql = "DELETE FROM brl_pago WHERE data < DATE_SUB(NOW(), INTERVAL ? DAY)";
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Erro na preparação da consulta: " . $conn->error);
    }

    // Vincula o intervalo de dias como parâmetro e executa a consulta
    $stmt->bind_param("i", $intervaloDias);

    if ($stmt->execute()) {
        $mensagem = "Registros com mais de $intervaloDias dias foram excluídos com sucesso!";
        echo $mensagem;
        registrarLog($mensagem);  // Registro de sucesso no log
    } else {
        throw new Exception("Erro ao executar a consulta: " . $stmt->error);
    }

    // Fecha a declaração
    $stmt->close();

} catch (Exception $e) {
    // Registra o erro no log e exibe uma mensagem genérica
    registrarLog("Erro: " . $e->getMessage());
    echo "Ocorreu um erro ao processar sua solicitação. Tente novamente mais tarde.";
} finally {
    // Fecha a conexão, se estiver aberta
    if ($conn) {
        $conn->close();
    }
}
?>
